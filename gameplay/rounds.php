<?php
// gameplay/rounds.php
// Round state, presentation, and progress helpers.

function mlRoundReadyForPlaylistGeneration(array $round, array $playlistRecord, DateTimeImmutable $now, int $songSubmissionCount, int $expectedPlayers, string $playlistBuildMode): bool {
    if (!empty($playlistRecord)) {
        return false;
    }

    if ($songSubmissionCount <= 0) {
        return false;
    }

    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    if (!$songsDue instanceof DateTimeImmutable) {
        return false;
    }

    if ($now <= $songsDue) {
        return false;
    }

    if ($playlistBuildMode === 'wait') {
        return $expectedPlayers > 0 && $songSubmissionCount >= $expectedPlayers;
    }

    return true;
}
function mlCanChooseSongForRound(array $round, array $playlistRecord, DateTimeImmutable $now, string $playlistBuildMode, int $songSubmissionCount = 0, int $expectedPlayers = 0): bool {
    if (!empty($playlistRecord)) {
        return false;
    }

    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    if (!$songsDue instanceof DateTimeImmutable) {
        return true;
    }

    if ($now <= $songsDue) {
        return true;
    }

    if ($playlistBuildMode === 'wait') {
        return $expectedPlayers > 0 && $songSubmissionCount < $expectedPlayers;
    }

    return false;
}
function mlCanManuallyGeneratePlaylist(array $round, array $playlistRecord, DateTimeImmutable $now, int $songSubmissionCount = 0, int $expectedPlayers = 0, string $playlistBuildMode = 'due'): bool {
    if (!empty($playlistRecord)) {
        return false;
    }

    if ($songSubmissionCount <= 0) {
        return false;
    }

    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    $allSubmitted = ($expectedPlayers > 0 && $songSubmissionCount >= $expectedPlayers);

    if ($allSubmitted) {
        return true;
    }

    if (!$songsDue instanceof DateTimeImmutable) {
        return false;
    }

    return $now > $songsDue;
}
function mlRoundEligibleForAutomaticPlaylistBuild(array $round, array $playlistRecord, int $songSubmissionCount, int $expectedPlayers, DateTimeImmutable $now, string $playlistBuildMode): bool {
    return mlRoundReadyForPlaylistGeneration($round, $playlistRecord, $now, $songSubmissionCount, $expectedPlayers, $playlistBuildMode);
}
function mlRoundIsExplicitlyClosed(array $round): bool {
    return strtolower(trim((string)($round['RoundState'] ?? ''))) === 'closed';
}
function mlRoundHasAllExpectedVotes(int $voteSubmissionCount, int $expectedPlayers): bool {
    return $expectedPlayers > 0 && $voteSubmissionCount >= $expectedPlayers;
}
function mlRoundVotingShouldClose(array $round, DateTimeImmutable $now, int $voteSubmissionCount, int $expectedPlayers, string $playlistBuildMode): bool {
    if (mlRoundIsExplicitlyClosed($round)) {
        return true;
    }

    $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? (string)$round['VotesDue'] : null);
    if (!$votesDue instanceof DateTimeImmutable || $now <= $votesDue) {
        return false;
    }

    if ($playlistBuildMode !== 'wait') {
        return true;
    }

    return mlRoundHasAllExpectedVotes($voteSubmissionCount, $expectedPlayers);
}
function mlGetVotingWaitFallbackAt(array $round, array $nextRound = []): ?DateTimeImmutable {
    $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? (string)$round['VotesDue'] : null);
    $nextSongsDue = mlCreateUtcDate(isset($nextRound['SongsDue']) ? (string)$nextRound['SongsDue'] : null);
    if (!$votesDue instanceof DateTimeImmutable || !$nextSongsDue instanceof DateTimeImmutable) {
        return null;
    }

    $fallbackAt = $nextSongsDue->modify('-12 hours');
    return $fallbackAt < $votesDue ? $votesDue : $fallbackAt;
}
function mlCanManuallyFinalizeRound(array $round, DateTimeImmutable $now, int $voteSubmissionCount): bool {
    if (mlRoundIsExplicitlyClosed($round) || empty($round['has_playlist']) || $voteSubmissionCount <= 0) {
        return false;
    }

    $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? (string)$round['VotesDue'] : null);
    return $votesDue instanceof DateTimeImmutable && $now > $votesDue;
}
function mlMarkRoundClosed(PDO $pdo, int $seasonRoundId): bool {
    if ($seasonRoundId <= 0 || !mlSeasonRoundsHasStateColumns($pdo)) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE ML_SeasonRounds SET RoundState = 'closed' WHERE SeasonRoundID = ?");
    $stmt->execute([$seasonRoundId]);
    return true;
}
function mlNotifyVotingPhaseClosedBestEffort(PDO $pdo, array $round): void {
    try {
        require_once __DIR__ . '/../integrations/push/push.php';
        mlPushSendIncompletePhaseClosed($pdo, $round, 'vote');
    } catch (Throwable $e) {
        // Never interrupt round finalization or the admin response for push failures.
    }
}
function mlHandleManualRoundFinalization(PDO $pdo, int $seasonRoundId, int $seasonId): array {
    $round = mlFindRoundById($pdo, $seasonRoundId);
    if (!$round || (int)($round['SeasonID'] ?? 0) !== $seasonId) {
        throw new RuntimeException('The selected round could not be found.');
    }

    if (!mlSeasonRoundsHasStateColumns($pdo)) {
        throw new RuntimeException('Round finalization storage is not available.');
    }

    $playlistRecord = mlGetRoundPlaylistRecord($pdo, $seasonRoundId, true);
    $round['has_playlist'] = !empty($playlistRecord);
    $voteSubmissionCount = mlFetchVoteSubmissionCount($pdo, $seasonRoundId);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    if (mlRoundIsExplicitlyClosed($round)) {
        return ['already_finalized' => true, 'round' => $round];
    }

    if (!mlCanManuallyFinalizeRound($round, $now, $voteSubmissionCount)) {
        if ($voteSubmissionCount <= 0) {
            throw new RuntimeException('The round cannot be finalized until at least one player has voted.');
        }
        throw new RuntimeException('The round cannot be finalized before Votes Due.');
    }

    if (!mlMarkRoundClosed($pdo, $seasonRoundId)) {
        throw new RuntimeException('The round could not be finalized.');
    }
    mlNotifyVotingPhaseClosedBestEffort($pdo, $round);

    return ['already_finalized' => false, 'round' => $round];
}
function mlResolveRoundState(array $round, DateTimeImmutable $now, ?DateTimeImmutable $previousVotesDue, int $expectedPlayers, int $songSubmissionCount, int $voteSubmissionCount, array $playlistRecord = [], string $playlistBuildMode = 'due'): array {
    $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
    $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? $round['VotesDue'] : null);
    $hasPlaylist = !empty($playlistRecord) && trim((string)($playlistRecord['SpotifyPlaylistURL'] ?? $playlistRecord['SpotifyPlaylistID'] ?? '')) !== '';

    if (mlRoundIsExplicitlyClosed($round)) {
        $roundState = 'closed';
    } elseif ($previousVotesDue instanceof DateTimeImmutable && $now <= $previousVotesDue) {
        $roundState = 'upcoming';
    } elseif ($hasPlaylist) {
        if (mlRoundVotingShouldClose($round, $now, $voteSubmissionCount, $expectedPlayers, $playlistBuildMode)) {
            $roundState = 'closed';
        } else {
            $roundState = 'voting';
        }
    } elseif ($votesDue instanceof DateTimeImmutable && $now > $votesDue) {
        $roundState = 'closed';
    } else {
        $roundState = 'submission';
    }

    $statusMap = [
        'upcoming' => ['Upcoming', 'pill-neutral'],
        'submission' => ['Choose a Song Stage', 'pill-open'],
        'voting' => ['Voting Stage', 'pill-open'],
        'closed' => ['Round Closed', 'pill-complete'],
    ];

    $status = $statusMap[$roundState] ?? $statusMap['upcoming'];

    return [
        'round_state' => $roundState,
        'status_key' => $roundState,
        'status_label' => $status[0],
        'status_class' => $status[1],
        'can_choose_song' => $roundState === 'submission' && mlCanChooseSongForRound($round, $playlistRecord, $now, $playlistBuildMode, $songSubmissionCount, $expectedPlayers),
        'can_vote' => $roundState === 'voting',
        'can_view_playlist' => in_array($roundState, ['voting', 'closed'], true) && $hasPlaylist,
        'can_manual_generate_playlist' => false,
        'can_manual_finalize_round' => $roundState === 'voting' && mlCanManuallyFinalizeRound(array_merge($round, ['has_playlist' => $hasPlaylist]), $now, $voteSubmissionCount),
        'has_playlist' => $hasPlaylist,
        'songs_due_utc' => isset($round['SongsDue']) ? (string)$round['SongsDue'] : '',
        'votes_due_utc' => isset($round['VotesDue']) ? (string)$round['VotesDue'] : '',
        'songs_due_label' => mlFormatRoundDate(isset($round['SongsDue']) ? $round['SongsDue'] : null),
        'votes_due_label' => mlFormatRoundDate(isset($round['VotesDue']) ? $round['VotesDue'] : null),
        'songs_due_passed' => $songsDue instanceof DateTimeImmutable && $now > $songsDue,
        'votes_due_passed' => $votesDue instanceof DateTimeImmutable && $now > $votesDue,
        'submission_closed' => ($roundState === 'submission' && !mlCanChooseSongForRound($round, $playlistRecord, $now, $playlistBuildMode, $songSubmissionCount, $expectedPlayers)),
    ];
}
function mlComputeRoundPresentation(PDO $pdo, array $rounds, int $currentUserId): array {
    static $cache = [];

    $cacheRoundParts = [];
    foreach ($rounds as $round) {
        $cacheRoundParts[] = (int)($round['SeasonRoundID'] ?? 0)
            . ':'
            . (string)($round['SongsDue'] ?? '')
            . ':'
            . (string)($round['VotesDue'] ?? '');
    }

    $cacheKey = $currentUserId . '|' . implode('|', $cacheRoundParts);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $qaCurrentSeasonRoundId = (function_exists('mlGetQaCurrentSeasonRoundId') ? mlGetQaCurrentSeasonRoundId($pdo) : 0);
    $resolved = [];
    $previousVotesDue = null;
    $expectedPlayers = mlGetExpectedPlayerCount($pdo);
    $playlistBuildMode = mlGetPlaylistBuildMode($pdo);
    $allUsers = null;

    $roundIds = array_map(static function ($round) {
        return (int)$round['SeasonRoundID'];
    }, $rounds);
    $playlistRecords = mlFetchPlaylistRecordsForRounds($pdo, $roundIds);

    $currentRoundIndex = null;

    foreach ($rounds as $index => $round) {
        $seasonRoundId = (int)$round['SeasonRoundID'];
        $seasonIsActive = mlSeasonIsActiveForGameplay($pdo, (int)($round['SeasonID'] ?? 0));
        $playlistRecord = $playlistRecords[$seasonRoundId] ?? [];
        $songsDue = mlCreateUtcDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
        $votesDue = mlCreateUtcDate(isset($round['VotesDue']) ? $round['VotesDue'] : null);
        $hasPlaylist = !empty($playlistRecord) && trim((string)($playlistRecord['SpotifyPlaylistURL'] ?? $playlistRecord['SpotifyPlaylistID'] ?? '')) !== '';
        $voteSubmissionCount = $hasPlaylist ? mlFetchVoteSubmissionCount($pdo, $seasonRoundId) : 0;

        if (mlRoundIsExplicitlyClosed($round)) {
            $roundState = 'closed';
        } elseif (!$seasonIsActive) {
            $roundState = ($votesDue instanceof DateTimeImmutable && $now > $votesDue)
                ? 'closed'
                : 'upcoming';
        } elseif ($previousVotesDue instanceof DateTimeImmutable && $now <= $previousVotesDue) {
            $roundState = 'upcoming';
        } elseif (!$hasPlaylist && $votesDue instanceof DateTimeImmutable && $now > $votesDue) {
            $roundState = 'closed';
        } elseif ($hasPlaylist && mlRoundVotingShouldClose($round, $now, $voteSubmissionCount, $expectedPlayers, $playlistBuildMode)) {
            $roundState = 'closed';
        } else {
            $roundState = $hasPlaylist ? 'voting' : 'submission';
            if ($qaCurrentSeasonRoundId > 0) {
                if ($seasonRoundId === $qaCurrentSeasonRoundId) {
                    $currentRoundIndex = $index;
                }
            } elseif ($currentRoundIndex === null) {
                $currentRoundIndex = $index;
            }
        }

        $statusMap = [
            'upcoming' => ['Upcoming', 'pill-neutral'],
            'submission' => ['Choose a Song Stage', 'pill-open'],
            'voting' => ['Voting Stage', 'pill-open'],
            'closed' => ['Round Closed', 'pill-complete'],
        ];
        $status = $statusMap[$roundState] ?? $statusMap['upcoming'];

        $round['expected_players'] = $expectedPlayers;
        $round['song_submission_count'] = 0;
        $round['vote_submission_count'] = $voteSubmissionCount;
        $round['playlist_record'] = $playlistRecord;
        $round['playlist_url'] = (string)($playlistRecord['SpotifyPlaylistURL'] ?? '');
        $round['has_playlist'] = $hasPlaylist;
        $round['season_is_active'] = $seasonIsActive;
        $round['song_draft'] = [];
        $round['vote_draft'] = [];
        $round['song_saved'] = false;
        $round['vote_saved'] = false;
        $round['vote_submitted'] = false;
        $round['progress_completed_users'] = [];
        $round['progress_pending_users'] = [];
        $round['progress_completed_names'] = 'None';
        $round['progress_pending_names'] = 'None';
        $round['round_state'] = $roundState;
        $round['status_key'] = $roundState;
        $round['status_label'] = $status[0];
        $round['status_class'] = $status[1];
        $round['can_choose_song'] = false;
        $round['can_vote'] = false;
        $round['can_view_playlist'] = in_array($roundState, ['voting', 'closed'], true) && $hasPlaylist;
        $round['can_manual_generate_playlist'] = false;
        $round['can_manual_finalize_round'] = false;
        $round['songs_due_utc'] = isset($round['SongsDue']) ? (string)$round['SongsDue'] : '';
        $round['votes_due_utc'] = isset($round['VotesDue']) ? (string)$round['VotesDue'] : '';
        $round['songs_due_label'] = mlFormatRoundDate(isset($round['SongsDue']) ? $round['SongsDue'] : null);
        $round['votes_due_label'] = mlFormatRoundDate(isset($round['VotesDue']) ? $round['VotesDue'] : null);
        $round['songs_due_passed'] = $songsDue instanceof DateTimeImmutable && $now > $songsDue;
        $round['votes_due_passed'] = $votesDue instanceof DateTimeImmutable && $now > $votesDue;
        $round['submission_closed'] = false;

        $resolved[] = $round;

        if ($votesDue instanceof DateTimeImmutable) {
            $previousVotesDue = $votesDue;
        }
    }

    foreach ($resolved as $index => $resolvedRound) {
        $seasonRoundId = (int)$resolvedRound['SeasonRoundID'];
        $seasonId = (int)$resolvedRound['SeasonID'];
        $seasonIsActive = !empty($resolvedRound['season_is_active']);
        $playlistRecord = $resolvedRound['playlist_record'] ?? [];
        $roundState = (string)($resolvedRound['round_state'] ?? '');

        if ($roundState === 'submission') {
            $songSubmissionCount = mlFetchSongSubmissionCount($pdo, $seasonRoundId);
            $resolvedRound['song_submission_count'] = $songSubmissionCount;
            $resolvedRound['can_choose_song'] = $seasonIsActive
                && mlCanChooseSongForRound($resolvedRound, $playlistRecord, $now, $playlistBuildMode, $songSubmissionCount, $expectedPlayers);
            $resolvedRound['submission_closed'] = !$resolvedRound['can_choose_song'];

            $songDraft = mlGetRoundSongDraft($pdo, $currentUserId, $seasonId, $seasonRoundId);
            $resolvedRound['song_draft'] = $songDraft;
            $resolvedRound['song_saved'] = !empty($songDraft);

            if ($seasonIsActive && $index === $currentRoundIndex) {
                $resolvedRound['can_manual_generate_playlist'] = mlCanManuallyGeneratePlaylist($resolvedRound, $playlistRecord, $now, $songSubmissionCount, $expectedPlayers, $playlistBuildMode);

                $allUsers = $allUsers ?? mlLoadAllUsers($pdo);
                $progress = mlBuildRoundProgressUsers($pdo, $seasonRoundId, 'submission', $allUsers);
                $resolvedRound['progress_completed_users'] = $progress['completed'];
                $resolvedRound['progress_pending_users'] = $progress['pending'];
                $resolvedRound['progress_completed_names'] = $progress['completed_names'];
                $resolvedRound['progress_pending_names'] = $progress['pending_names'];
            }
        } elseif ($roundState === 'upcoming') {
            $resolvedRound['can_choose_song'] = $seasonIsActive;
            $songDraft = mlGetRoundSongDraft($pdo, $currentUserId, $seasonId, $seasonRoundId);
            $resolvedRound['song_draft'] = $songDraft;
            $resolvedRound['song_saved'] = !empty($songDraft);
        } elseif ($index === $currentRoundIndex && $roundState === 'voting') {
            $voteSubmissionCount = (int)$resolvedRound['vote_submission_count'];
            $resolvedRound['vote_submission_count'] = $voteSubmissionCount;
            $resolvedRound['can_vote'] = $seasonIsActive;
            $resolvedRound['can_manual_finalize_round'] = $seasonIsActive
                && mlCanManuallyFinalizeRound($resolvedRound, $now, $voteSubmissionCount);
            $voteDraft = mlGetRoundVoteDraft($currentUserId, $seasonId, $seasonRoundId);
            $resolvedRound['vote_draft'] = $voteDraft;
            $resolvedRound['vote_saved'] = !empty($voteDraft);
            $resolvedRound['vote_submitted'] = mlFetchCurrentUserVoteSubmission($pdo, $seasonRoundId, $currentUserId) || !empty($voteDraft['submitted_at']);

            $allUsers = $allUsers ?? mlLoadAllUsers($pdo);
            $progress = mlBuildRoundProgressUsers($pdo, $seasonRoundId, 'voting', $allUsers);
            $resolvedRound['progress_completed_users'] = $progress['completed'];
            $resolvedRound['progress_pending_users'] = $progress['pending'];
            $resolvedRound['progress_completed_names'] = $progress['completed_names'];
            $resolvedRound['progress_pending_names'] = $progress['pending_names'];
        }

        $resolved[$index] = $resolvedRound;
    }

    $cache[$cacheKey] = $resolved;
    return $resolved;
}
function mlLoadAllUsers(PDO $pdo): array {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $select = 'SELECT UserID, UserName';
    if (mlUsersHasShortDisplayNameColumn($pdo)) {
        $select .= ', ShortDisplayName';
    }
    if (mlUsersHasProfileImageColumn($pdo)) {
        $select .= ', ProfileImageFilename';
    }
    $select .= ' FROM ML_Users ORDER BY UserID ASC';

    $stmt = $pdo->query($select);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($users as &$user) {
        $user['profile_image_path'] = mlGetUserProfilePath((int)$user['UserID'], $user['ProfileImageFilename'] ?? null);
    }
    unset($user);

    $cache = $users;
    return $cache;
}
function mlFetchRoundCompletedUserIds(PDO $pdo, int $seasonRoundId, string $mode): array {
    static $cache = [];
    $cacheKey = $mode . ':' . $seasonRoundId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $ids = [];
    try {
        if ($mode === 'submission' && mlTableExists($pdo, 'ML_RoundSongs')) {
            $stmt = $pdo->prepare('SELECT DISTINCT UserID FROM ML_RoundSongs WHERE SeasonRoundID = ? ORDER BY UserID ASC');
            $stmt->execute([$seasonRoundId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($mode === 'voting' && mlTableExists($pdo, 'ML_RoundVoteSubmissions')) {
            $stmt = $pdo->prepare('SELECT DISTINCT UserID FROM ML_RoundVoteSubmissions WHERE SeasonRoundID = ? ORDER BY UserID ASC');
            $stmt->execute([$seasonRoundId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (Throwable $e) {
        $ids = [];
    }

    $cache[$cacheKey] = $ids;
    return $ids;
}
function mlBuildRoundProgressUsers(PDO $pdo, int $seasonRoundId, string $mode, array $allUsers): array {
    $completedIds = mlFetchRoundCompletedUserIds($pdo, $seasonRoundId, $mode);
    $completedLookup = array_fill_keys($completedIds, true);
    $completedUsers = [];
    $pendingUsers = [];

    foreach ($allUsers as $user) {
        $row = [
            'user_id' => (int)$user['UserID'],
            'user_name' => (string)$user['UserName'],
            'profile_image_path' => (string)($user['profile_image_path'] ?? mlGetUserProfilePath((int)$user['UserID'], $user['ProfileImageFilename'] ?? null)),
        ];

        if (isset($completedLookup[$row['user_id']])) {
            $completedUsers[] = $row;
        } else {
            $pendingUsers[] = $row;
        }
    }

    return [
        'completed' => $completedUsers,
        'pending' => $pendingUsers,
        'completed_names' => !empty($completedUsers) ? implode(', ', array_map(static fn($u) => $u['user_name'], $completedUsers)) : 'None',
        'pending_names' => !empty($pendingUsers) ? implode(', ', array_map(static fn($u) => $u['user_name'], $pendingUsers)) : 'None',
    ];
}
