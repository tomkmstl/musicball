<?php
// gameplay/standings.php
// Standings, podium, and round result helpers.

function mlCompareRoundStandingEntries(array $a, array $b): int {
    $pointsA = (int)($a['points'] ?? 0);
    $pointsB = (int)($b['points'] ?? 0);
    if ($pointsA !== $pointsB) {
        return ($pointsA > $pointsB) ? -1 : 1;
    }

    $votersA = (int)($a['voter_count'] ?? 0);
    $votersB = (int)($b['voter_count'] ?? 0);
    if ($votersA !== $votersB) {
        return ($votersA > $votersB) ? -1 : 1;
    }

    $userIdA = (int)($a['user_id'] ?? 0);
    $userIdB = (int)($b['user_id'] ?? 0);
    if ($userIdA !== $userIdB) {
        return ($userIdA > $userIdB) ? -1 : 1;
    }

    return 0;
}
function mlCompareOverallStandingsEntries(array $a, array $b): int {
    $pointsA = (int)($a['points'] ?? 0);
    $pointsB = (int)($b['points'] ?? 0);
    if ($pointsA !== $pointsB) {
        return ($pointsA > $pointsB) ? -1 : 1;
    }

    $votersA = (int)($a['positive_voter_total'] ?? 0);
    $votersB = (int)($b['positive_voter_total'] ?? 0);
    if ($votersA !== $votersB) {
        return ($votersA > $votersB) ? -1 : 1;
    }

    $userIdA = (int)($a['user_id'] ?? 0);
    $userIdB = (int)($b['user_id'] ?? 0);
    if ($userIdA !== $userIdB) {
        return ($userIdA > $userIdB) ? -1 : 1;
    }

    return 0;
}
function mlBuildStandingsDataFromClosedRounds(PDO $pdo, array $closedRounds, int $currentUserId, bool $includeRoundBreakdown = true): array {
    $users = mlLoadAllUsers($pdo);
    $playerStats = [];

    foreach ($users as $user) {
        $userId = (int)$user['UserID'];
        $playerStats[$userId] = [
            'user_id' => $userId,
            'user_name' => (string)$user['UserName'],
            'profile_image_path' => (string)($user['profile_image_path'] ?? mlGetUserProfilePath($userId, $user['ProfileImageFilename'] ?? null)),
            'points' => 0,
            'round_wins' => 0,
            'total_voters' => 0,
            'podiums' => 0,
            'best_round_score' => 0,
            'holdouts' => 0,
            'positive_voter_total' => 0,
            'is_current_user' => ($userId === $currentUserId),
        ];
    }

    $result = [
        'standings' => [],
        'round_breakdown' => [],
        'closed_round_count' => count($closedRounds),
    ];

    if (empty($closedRounds) || !mlTableExists($pdo, 'ML_RoundSongs') || !mlTableExists($pdo, 'ML_RoundVotes')) {
        foreach ($playerStats as $row) {
            $result['standings'][] = $row;
        }
        usort($result['standings'], 'mlCompareOverallStandingsEntries');
        $rank = 1;
        foreach ($result['standings'] as &$row) {
            $row['rank'] = $rank;
            $rank++;
        }
        unset($row);
        return $result;
    }

    $closedRoundIds = array_keys($closedRounds);
    $placeholders = implode(',', array_fill(0, count($closedRoundIds), '?'));

    $roundSongStats = [];
    try {
        $sql = "
            SELECT rs.SeasonRoundID,
                   rs.RoundSongID,
                   rs.UserID,
                   COALESCE(SUM(rv.Score), 0) AS TotalPoints,
                   COUNT(DISTINCT CASE WHEN rv.Score > 0 THEN rv.VoterUserID END) AS PositiveVoterCount
            FROM ML_RoundSongs rs
            LEFT JOIN ML_RoundVotes rv
              ON rv.RoundSongID = rs.RoundSongID
             AND rv.SeasonRoundID = rs.SeasonRoundID
            WHERE rs.SeasonRoundID IN ($placeholders)
            GROUP BY rs.SeasonRoundID, rs.RoundSongID, rs.UserID
            ORDER BY rs.SeasonRoundID ASC, rs.RoundSongID ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($closedRoundIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $seasonRoundId = (int)$row['SeasonRoundID'];
            $ownerUserId = (int)$row['UserID'];
            $entry = [
                'season_round_id' => $seasonRoundId,
                'round_song_id' => (int)$row['RoundSongID'],
                'user_id' => $ownerUserId,
                'points' => (int)$row['TotalPoints'],
                'voter_count' => (int)$row['PositiveVoterCount'],
                'voter_ids' => [],
            ];
            $roundSongStats[$seasonRoundId][$ownerUserId] = $entry;

            if (isset($playerStats[$ownerUserId])) {
                $playerStats[$ownerUserId]['points'] += $entry['points'];
                $playerStats[$ownerUserId]['positive_voter_total'] += $entry['voter_count'];
                $playerStats[$ownerUserId]['total_voters'] += $entry['voter_count'];
                if ($entry['points'] > $playerStats[$ownerUserId]['best_round_score']) {
                    $playerStats[$ownerUserId]['best_round_score'] = $entry['points'];
                }
            }
        }
    } catch (Throwable $e) {
        foreach ($playerStats as $row) {
            $result['standings'][] = $row;
        }
        usort($result['standings'], 'mlCompareOverallStandingsEntries');
        $rank = 1;
        foreach ($result['standings'] as &$row) {
            $row['rank'] = $rank;
            $rank++;
        }
        unset($row);
        return $result;
    }

    try {
        $voterSql = "
            SELECT rv.SeasonRoundID, rv.RoundSongID, rv.VoterUserID
            FROM ML_RoundVotes rv
            WHERE rv.SeasonRoundID IN ($placeholders)
              AND rv.Score > 0
            ORDER BY rv.SeasonRoundID ASC, rv.RoundSongID ASC, rv.VoterUserID ASC
        ";
        $stmt = $pdo->prepare($voterSql);
        $stmt->execute($closedRoundIds);
        $voterRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($voterRows as $row) {
            $seasonRoundId = (int)$row['SeasonRoundID'];
            $roundSongId = (int)$row['RoundSongID'];
            $voterUserId = (int)$row['VoterUserID'];

            if (empty($roundSongStats[$seasonRoundId])) {
                continue;
            }

            foreach ($roundSongStats[$seasonRoundId] as $ownerUserId => &$songEntry) {
                if ((int)$songEntry['round_song_id'] !== $roundSongId) {
                    continue;
                }
                $songEntry['voter_ids'][$voterUserId] = true;
                break;
            }
            unset($songEntry);
        }
    } catch (Throwable $e) {
        // Leave voter_ids empty if this query fails.
    }

    $roundBreakdown = [];
    foreach ($closedRounds as $seasonRoundId => $round) {
        $submittedEntries = [];
        $playerCells = [];

        foreach ($playerStats as $userId => $_unused) {
            $entry = $roundSongStats[$seasonRoundId][$userId] ?? null;
            $points = $entry ? (int)$entry['points'] : null;
            $voterCount = $entry ? (int)$entry['voter_count'] : 0;
            $playerCells[$userId] = [
                'user_id' => $userId,
                'points' => $points,
                'voter_count' => $voterCount,
                'is_winner' => false,
            ];

            if ($entry !== null) {
                $submittedEntries[] = [
                    'user_id' => $userId,
                    'points' => (int)$entry['points'],
                    'voter_count' => (int)$entry['voter_count'],
                ];
            }
        }

        if (!empty($submittedEntries)) {
            usort($submittedEntries, 'mlCompareRoundStandingEntries');

            $winnerUserId = (int)$submittedEntries[0]['user_id'];
            if (isset($playerStats[$winnerUserId])) {
                $playerStats[$winnerUserId]['round_wins'] += 1;
            }
            if (isset($playerCells[$winnerUserId])) {
                $playerCells[$winnerUserId]['is_winner'] = true;
            }

            $podiumCount = min(3, count($submittedEntries));
            for ($i = 0; $i < $podiumCount; $i++) {
                $podiumUserId = (int)$submittedEntries[$i]['user_id'];
                if (isset($playerStats[$podiumUserId])) {
                    $playerStats[$podiumUserId]['podiums'] += 1;
                }
            }

            foreach ($submittedEntries as $entry) {
                $ownerUserId = (int)$entry['user_id'];
                $songEntry = $roundSongStats[$seasonRoundId][$ownerUserId] ?? null;
                if ($songEntry === null) {
                    continue;
                }

                if ((int)$songEntry['voter_count'] < 9) {
                    continue;
                }

                $voterIds = $songEntry['voter_ids'] ?? [];
                foreach ($playerStats as $candidateUserId => &$candidateStats) {
                    if ((int)$candidateUserId === $ownerUserId) {
                        continue;
                    }
                    if (isset($voterIds[$candidateUserId])) {
                        continue;
                    }
                    $candidateStats['holdouts'] += 1;
                }
                unset($candidateStats);
            }
        }

        if ($includeRoundBreakdown) {
            $roundBreakdown[] = [
                'season_round_id' => $seasonRoundId,
                'round_number' => (int)($round['RoundNumber'] ?? 0),
                'title' => (string)($round['Title'] ?? ('Round ' . (int)($round['RoundNumber'] ?? 0))),
                'players' => $playerCells,
            ];
        }
    }

    $standings = array_values($playerStats);
    usort($standings, 'mlCompareOverallStandingsEntries');

    $rank = 1;
    foreach ($standings as &$row) {
        $row['rank'] = $rank;
        $rank++;
    }
    unset($row);

    return [
        'standings' => $standings,
        'round_breakdown' => $roundBreakdown,
        'closed_round_count' => count($closedRounds),
    ];
}
function mlBuildStandingsData(PDO $pdo, int $seasonId, int $currentUserId): array {
    static $cache = [];

    $cacheKey = 'season:' . $seasonId . ':' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $rounds = mlLoadSeasonRoundsForGameplay($pdo, $seasonId);
    $presentedRounds = mlComputeRoundPresentation($pdo, $rounds, $currentUserId);
    $closedRounds = [];
    foreach ($presentedRounds as $round) {
        if (($round['round_state'] ?? '') === 'closed') {
            $closedRounds[(int)$round['SeasonRoundID']] = $round;
        }
    }

    $cache[$cacheKey] = mlBuildStandingsDataFromClosedRounds($pdo, $closedRounds, $currentUserId, true);
    return $cache[$cacheKey];
}
function mlBuildAllTimeStandingsData(PDO $pdo, int $currentUserId): array {
    static $cache = [];

    $cacheKey = 'all:' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $seasonList = mlLoadSeasonSummaries($pdo);
    $closedRounds = [];

    foreach ($seasonList as $seasonRow) {
        $seasonId = (int)($seasonRow['SeasonID'] ?? 0);
        if ($seasonId <= 0 || (int)($seasonRow['RoundCount'] ?? 0) <= 0) {
            continue;
        }

        $rounds = mlLoadSeasonRoundsForGameplay($pdo, $seasonId);
        $presentedRounds = mlComputeRoundPresentation($pdo, $rounds, $currentUserId);
        foreach ($presentedRounds as $round) {
            if (($round['round_state'] ?? '') === 'closed') {
                $closedRounds[(int)$round['SeasonRoundID']] = $round;
            }
        }
    }

    if (!empty($closedRounds)) {
        uasort($closedRounds, static function (array $a, array $b): int {
            $seasonComparison = ((int)($a['SeasonID'] ?? 0)) <=> ((int)($b['SeasonID'] ?? 0));
            if ($seasonComparison !== 0) {
                return $seasonComparison;
            }

            $roundComparison = ((int)($a['RoundNumber'] ?? 0)) <=> ((int)($b['RoundNumber'] ?? 0));
            if ($roundComparison !== 0) {
                return $roundComparison;
            }

            return ((int)($a['SeasonRoundID'] ?? 0)) <=> ((int)($b['SeasonRoundID'] ?? 0));
        });
    }

    $cache[$cacheKey] = mlBuildStandingsDataFromClosedRounds($pdo, $closedRounds, $currentUserId, false);
    return $cache[$cacheKey];
}
function mlBuildStandingsPreview(PDO $pdo, int $seasonId, int $currentUserId): array {
    $data = mlBuildStandingsData($pdo, $seasonId, $currentUserId);
    return $data['standings'] ?? [];
}
function mlBuildStandingsBreakdown(PDO $pdo, int $seasonId, int $currentUserId): array {
    $data = mlBuildStandingsData($pdo, $seasonId, $currentUserId);
    return $data['round_breakdown'] ?? [];
}
function mlRoundIsFinishedForDisplay(array $round): bool {
    if (($round['status_key'] ?? '') === 'closed') {
        return true;
    }

    $expectedPlayers = (int)($round['expected_players'] ?? 0);
    $voteSubmissionCount = (int)($round['vote_submission_count'] ?? 0);

    return (($round['round_state'] ?? '') === 'voting')
        && $expectedPlayers > 0
        && $voteSubmissionCount >= $expectedPlayers;
}
function mlBuildRoundPodium(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId): array {
    static $cache = [];

    $cacheKey = $seasonId . ':' . $seasonRoundId . ':' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $results = mlBuildRoundResultsPreview($pdo, $seasonId, $seasonRoundId, $currentUserId);
    if (empty($results)) {
        $cache[$cacheKey] = [];
        return [];
    }

    $podium = [];
    $places = ['1st', '2nd', '3rd'];

    foreach ($results as $result) {
        $entry = $result['entry'] ?? [];
        $userId = (int)($entry['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        if (isset($podium[$userId])) {
            continue;
        }

        $podium[$userId] = [
            'user_id' => $userId,
            'user_name' => (string)($entry['user_name'] ?? ''),
            'profile_image_path' => (string)($entry['profile_image_path'] ?? mlGetUserProfilePath($userId)),
            'place_label' => $places[count($podium)] ?? '',
            'total_score' => (int)($result['total_score'] ?? 0),
        ];

        if (count($podium) >= 3) {
            break;
        }
    }

    $cache[$cacheKey] = array_values($podium);
    return $cache[$cacheKey];
}
function mlBuildRoundResultsPreview(PDO $pdo, int $seasonId, int $seasonRoundId, int $currentUserId): array {
    static $cache = [];

    $cacheKey = $seasonId . ':' . $seasonRoundId . ':' . $currentUserId;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $playlistEntries = mlBuildPlaylistPreview($pdo, $seasonId, $seasonRoundId, $currentUserId);
    if (empty($playlistEntries)) {
        $cache[$cacheKey] = [];
        return [];
    }

    $voteDraftMap = [];
    if (mlTableExists($pdo, 'ML_RoundVotes')) {
        try {
            $stmt = $pdo->prepare("
				SELECT rv.RoundSongID, rv.VoterUserID, rv.Score, rv.Comment, u.UserName, u.ProfileImageFilename
				FROM ML_RoundVotes rv
				INNER JOIN ML_RoundVoteSubmissions rvs
				  ON rvs.SeasonRoundID = rv.SeasonRoundID
				 AND rvs.UserID = rv.VoterUserID
				LEFT JOIN ML_Users u ON rv.VoterUserID = u.UserID
				WHERE rv.SeasonRoundID = ?
				ORDER BY rv.RoundSongID ASC, rv.VoterUserID ASC
			");
            $stmt->execute([$seasonRoundId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $entryId = 'entry_' . (int)$row['RoundSongID'];
                if (!isset($voteDraftMap[$entryId])) {
                    $voteDraftMap[$entryId] = [];
                }
                $voteDraftMap[$entryId][] = [
                    'voter_user_id' => (int)$row['VoterUserID'],
                    'voter_name' => (string)($row['UserName'] ?? ('User ' . $row['VoterUserID'])),
                    'score' => (int)$row['Score'],
                    'comment' => trim((string)($row['Comment'] ?? '')),
                    'profile_image_path' => mlGetUserProfilePath((int)$row['VoterUserID'], $row['ProfileImageFilename'] ?? null),
                ];
            }
        } catch (Throwable $e) {
            $voteDraftMap = [];
        }
    }

    $results = [];
    foreach ($playlistEntries as $entry) {
        $votes = $voteDraftMap[$entry['entry_id']] ?? [];
        usort($votes, function ($a, $b) {
            $scoreA = (int)($a['score'] ?? 0);
            $scoreB = (int)($b['score'] ?? 0);
            if ($scoreA !== $scoreB) {
                return ($scoreA > $scoreB) ? -1 : 1;
            }

            $hasCommentA = trim((string)($a['comment'] ?? '')) !== '';
            $hasCommentB = trim((string)($b['comment'] ?? '')) !== '';
            if ($hasCommentA !== $hasCommentB) {
                return $hasCommentA ? -1 : 1;
            }

            return strcasecmp((string)($a['voter_name'] ?? ''), (string)($b['voter_name'] ?? ''));
        });

        $totalScore = 0;
        $positiveVoterCount = 0;
        foreach ($votes as $vote) {
            $voteScore = (int)($vote['score'] ?? 0);
            $totalScore += $voteScore;
            if ($voteScore > 0) {
                $positiveVoterCount++;
            }
        }
        $voteCount = count($votes);

        $results[] = [
            'entry' => $entry,
            'total_score' => $totalScore,
            'positive_voter_count' => $positiveVoterCount,
            'average_score' => $voteCount > 0 ? ($totalScore / $voteCount) : 0,
            'vote_breakdown' => $votes,
        ];
    }

    usort($results, function ($a, $b) {
        $scoreA = (int)($a['total_score'] ?? 0);
        $scoreB = (int)($b['total_score'] ?? 0);
        if ($scoreA !== $scoreB) {
            return ($scoreA > $scoreB) ? -1 : 1;
        }

        $votersA = (int)($a['positive_voter_count'] ?? 0);
        $votersB = (int)($b['positive_voter_count'] ?? 0);
        if ($votersA !== $votersB) {
            return ($votersA > $votersB) ? -1 : 1;
        }

        $userIdA = (int)($a['entry']['user_id'] ?? 0);
        $userIdB = (int)($b['entry']['user_id'] ?? 0);
        if ($userIdA !== $userIdB) {
            return ($userIdA > $userIdB) ? -1 : 1;
        }

        return 0;
    });

    $cache[$cacheKey] = $results;
    return $results;
}
