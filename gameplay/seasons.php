<?php
// gameplay/seasons.php
// Season and round lookup/date helpers.

function mlLoadSeasonSummaries(PDO $pdo): array {
    $sql = "
        SELECT s.SeasonID,
               s.SeasonName,
               s.IsActive,
               (SELECT COUNT(*) FROM ML_SeasonRounds sr WHERE sr.SeasonID = s.SeasonID) AS RoundCount,
               (SELECT COUNT(DISTINCT sub.UserID) FROM ML_Submissions sub WHERE sub.SeasonID = s.SeasonID) AS SubmissionCount
        FROM ML_Seasons s
        ORDER BY s.SeasonID DESC
    ";

    $stmt = $pdo->query($sql);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $result;
}
function mlResolveGameplaySeasonId(PDO $pdo, int $requestedSeasonId, int $activeSeasonId): int {
    $seasonList = mlLoadSeasonSummaries($pdo);
    $hasRequested = false;
    $firstSeasonWithRounds = 0;
    $firstAnySeason = 0;

    foreach ($seasonList as $seasonRow) {
        $rowSeasonId = (int)$seasonRow['SeasonID'];
        if ($firstAnySeason === 0) {
            $firstAnySeason = $rowSeasonId;
        }
        if ($firstSeasonWithRounds === 0 && (int)$seasonRow['RoundCount'] > 0) {
            $firstSeasonWithRounds = $rowSeasonId;
        }
        if ($requestedSeasonId > 0 && $rowSeasonId === $requestedSeasonId) {
            $hasRequested = true;
        }
    }

    if ($requestedSeasonId > 0 && $hasRequested) {
        return $requestedSeasonId;
    }

    if ($activeSeasonId > 0) {
        foreach ($seasonList as $seasonRow) {
            if ((int)$seasonRow['SeasonID'] === $activeSeasonId && (int)$seasonRow['RoundCount'] > 0) {
                return $activeSeasonId;
            }
        }
    }

    if ($firstSeasonWithRounds > 0) {
        return $firstSeasonWithRounds;
    }

    if ($activeSeasonId > 0) {
        return $activeSeasonId;
    }

    return $firstAnySeason;
}
function mlLoadSeasonById(PDO $pdo, ?int $seasonId): ?array {
    if ($seasonId === null || $seasonId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT SeasonID, SeasonName, IsActive FROM ML_Seasons WHERE SeasonID = ? LIMIT 1');
    $stmt->execute([$seasonId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $result = $row ?: null;
    return $result;
}
function mlSeasonIsActiveForGameplay(PDO $pdo, int $seasonId): bool {
    static $cache = [];

    if ($seasonId <= 0) {
        return false;
    }
    if (array_key_exists($seasonId, $cache)) {
        return $cache[$seasonId];
    }

    $stmt = $pdo->prepare('SELECT IsActive FROM ML_Seasons WHERE SeasonID = ? LIMIT 1');
    $stmt->execute([$seasonId]);
    $cache[$seasonId] = ((int)$stmt->fetchColumn() === 1);
    return $cache[$seasonId];
}
function mlLoadSeasonRoundsForGameplay(PDO $pdo, int $seasonId): array {
    $select = 'SeasonRoundID, SeasonID, RoundNumber, Title, Tagline, SongsDue, VotesDue';
    if (mlSeasonRoundsHasStateColumns($pdo)) {
        $select .= ', RoundState, StateMode, HoldForAllSongs, HoldForAllVotes';
    }

    $stmt = $pdo->prepare("\n        SELECT {$select}\n        FROM ML_SeasonRounds\n        WHERE SeasonID = ?\n        ORDER BY RoundNumber ASC\n    ");
    $stmt->execute([$seasonId]);
    $rounds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = $rounds ?: [];
    return $result;
}
function mlFindRoundById(PDO $pdo, int $seasonRoundId): ?array {
    $select = 'SeasonRoundID, SeasonID, RoundNumber, Title, Tagline, SongsDue, VotesDue';
    if (mlSeasonRoundsHasStateColumns($pdo)) {
        $select .= ', RoundState, StateMode, HoldForAllSongs, HoldForAllVotes';
    }

    $stmt = $pdo->prepare("\n        SELECT {$select}\n        FROM ML_SeasonRounds\n        WHERE SeasonRoundID = ?\n        LIMIT 1\n    ");
    $stmt->execute([$seasonRoundId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
function mlCreateUtcDate(?string $value): ?DateTimeImmutable {
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return null;
    }
}
function mlFormatRoundDate(?string $value): string {
    $dt = mlCreateUtcDate($value);
    if (!$dt) {
        return 'TBD';
    }

    return $dt->setTimezone(new DateTimeZone('UTC'))->format('M j, Y g:i A') . ' UTC';
}
function mlGetExpectedPlayerCount(PDO $pdo): int {
    static $count = null;
    global $totalPlayers;

    if ($count !== null) {
        return $count;
    }

    if (isset($totalPlayers) && (int)$totalPlayers > 0) {
        $count = (int)$totalPlayers;
        return $count;
    }

    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM ML_Users')->fetchColumn();
    } catch (Throwable $e) {
        $count = 0;
    }

    return $count;
}
