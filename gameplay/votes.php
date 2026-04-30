<?php
// gameplay/votes.php
// Vote draft, vote submission, and ballot helpers.

function mlFetchVoteSubmissionCount(PDO $pdo, int $seasonRoundId): int {
    static $cache = [];

    if (array_key_exists($seasonRoundId, $cache)) {
        return $cache[$seasonRoundId];
    }

    if (!mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
        $cache[$seasonRoundId] = 0;
        return 0;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ?');
        $stmt->execute([$seasonRoundId]);
        $cache[$seasonRoundId] = (int)$stmt->fetchColumn();
        return $cache[$seasonRoundId];
    } catch (Throwable $e) {
        $cache[$seasonRoundId] = 0;
        return 0;
    }
}
function mlFetchCurrentUserVoteSubmission(PDO $pdo, int $seasonRoundId, int $userId): bool {
    static $cache = [];

    $cacheKey = $seasonRoundId . ':' . $userId;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (!mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
        $cache[$cacheKey] = false;
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? AND UserID = ? LIMIT 1');
        $stmt->execute([$seasonRoundId, $userId]);
        $cache[$cacheKey] = (bool)$stmt->fetchColumn();
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
        return false;
    }
}
function mlFetchRoundVoteDraftFromDatabase(PDO $pdo, int $userId, int $seasonRoundId): array {
    if (!mlTableExists($pdo, 'ML_RoundVotes')) {
        return [];
    }

    try {
        $submittedAt = null;
        if (mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
            $submittedStmt = $pdo->prepare('SELECT SubmittedAt FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? AND UserID = ? LIMIT 1');
            $submittedStmt->execute([$seasonRoundId, $userId]);
            $submittedAt = $submittedStmt->fetchColumn();
        }

        $stmt = $pdo->prepare("\n            SELECT rv.RoundSongID, rv.Score, rv.Comment, rv.UpdatedAt\n            FROM ML_RoundVotes rv\n            WHERE rv.SeasonRoundID = ? AND rv.VoterUserID = ?\n            ORDER BY rv.RoundSongID ASC\n        ");
        $stmt->execute([$seasonRoundId, $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            return $submittedAt ? ['entries' => [], 'submitted_at' => (string)$submittedAt] : [];
        }

        $entries = [];
        $savedAt = '';
        foreach ($rows as $row) {
            $entryId = 'entry_' . (int)$row['RoundSongID'];
            $entries[$entryId] = [
                'score' => (int)$row['Score'],
                'comment' => (string)($row['Comment'] ?? ''),
            ];
            if ($savedAt === '' && !empty($row['UpdatedAt'])) {
                $savedAt = (string)$row['UpdatedAt'];
            }
        }

        $result = [
            'entries' => $entries,
        ];
        if ($savedAt !== '') {
            $result['saved_at'] = $savedAt;
        }
        if ($submittedAt) {
            $result['submitted_at'] = (string)$submittedAt;
        }

        return $result;
    } catch (Throwable $e) {
        return [];
    }
}
function mlGetRoundVoteDraft(int $userId, int $seasonId, int $seasonRoundId): array {
    $pdo = mlGameplayPdo();
    if ($pdo) {
        $dbDraft = mlFetchRoundVoteDraftFromDatabase($pdo, $userId, $seasonRoundId);
        if (!empty($dbDraft)) {
            return $dbDraft;
        }
    }

    if (!isset($_SESSION['ml_round_votes'][$userId][$seasonId][$seasonRoundId])) {
        return [];
    }

    $draft = $_SESSION['ml_round_votes'][$userId][$seasonId][$seasonRoundId];
    return is_array($draft) ? $draft : [];
}
function mlSaveRoundVoteDraft(int $userId, int $seasonId, int $seasonRoundId, array $votePayload, bool $markSubmitted): void {
    $pdo = mlGameplayPdo();

    if ($pdo && mlTableExists($pdo, 'ML_RoundVotes')) {
        require_once __DIR__ . '/../integrations/discord/discord.php';
        try {
            $entries = isset($votePayload['entries']) && is_array($votePayload['entries']) ? $votePayload['entries'] : [];
            $pdo->beginTransaction();

            $deleteStmt = $pdo->prepare('DELETE FROM ML_RoundVotes WHERE SeasonRoundID = ? AND VoterUserID = ?');
            $deleteStmt->execute([$seasonRoundId, $userId]);

            $insertStmt = $pdo->prepare("\n                INSERT INTO ML_RoundVotes\n                    (SeasonRoundID, VoterUserID, RoundSongID, Score, Comment)\n                VALUES\n                    (?, ?, ?, ?, ?)\n            ");

            foreach ($entries as $entryId => $entry) {
                if (!preg_match('/^entry_(\d+)$/', (string)$entryId, $matches)) {
                    continue;
                }
                $roundSongId = (int)$matches[1];
                if ($roundSongId <= 0) {
                    continue;
                }

                $score = isset($entry['score']) ? max(0, min(10, (int)$entry['score'])) : 0;
                $comment = isset($entry['comment']) ? trim((string)$entry['comment']) : '';
                $insertStmt->execute([$seasonRoundId, $userId, $roundSongId, $score, $comment]);
            }

            if (mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
                if ($markSubmitted) {
                    $submitStmt = $pdo->prepare("\n                        INSERT INTO ML_RoundVoteSubmissions (SeasonRoundID, UserID)\n                        VALUES (?, ?)\n                        ON DUPLICATE KEY UPDATE SubmittedAt = CURRENT_TIMESTAMP\n                    ");
                    $submitStmt->execute([$seasonRoundId, $userId]);
                } else {
                    $clearStmt = $pdo->prepare('DELETE FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? AND UserID = ?');
                    $clearStmt->execute([$seasonRoundId, $userId]);
                }
            }

            $pdo->commit();

            if ($markSubmitted) {
                try {
                    mlDiscordMaybeSendVotesSubmittedForRound($pdo, $seasonRoundId, $userId);
                    mlDiscordMaybeSendAllVotesInForRound($pdo, $seasonRoundId);
                } catch (Throwable $e) {
                    // Never interrupt gameplay for Discord failures.
                }
            }

            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Fall back to session below.
        }
    }

    if (!isset($_SESSION['ml_round_votes'])) {
        $_SESSION['ml_round_votes'] = [];
    }
    if (!isset($_SESSION['ml_round_votes'][$userId])) {
        $_SESSION['ml_round_votes'][$userId] = [];
    }
    if (!isset($_SESSION['ml_round_votes'][$userId][$seasonId])) {
        $_SESSION['ml_round_votes'][$userId][$seasonId] = [];
    }

    $votePayload['saved_at'] = gmdate('Y-m-d H:i:s');
    if ($markSubmitted) {
        $votePayload['submitted_at'] = gmdate('Y-m-d H:i:s');
    } else {
        unset($votePayload['submitted_at']);
    }
    $_SESSION['ml_round_votes'][$userId][$seasonId][$seasonRoundId] = $votePayload;
}
function mlBuildVotingBallot(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId): array {
    $playlistEntries = mlBuildPlaylistPreview($pdo, $seasonId, $seasonRoundId, $currentUserId);
    $ballot = [];

    foreach ($playlistEntries as $entry) {
        $entry['can_score'] = empty($entry['is_current_user_song']);
        $ballot[] = $entry;
    }

    return $ballot;
}
