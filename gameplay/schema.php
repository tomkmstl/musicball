<?php
// gameplay/schema.php
// Database capability and schema detection helpers.

if (!function_exists('mlTableExists')) {
    function mlTableExists(PDO $pdo, string $tableName): bool {
        static $tableLookup = null;

        if ($tableLookup === null) {
            $tableLookup = [];

            try {
                $stmt = $pdo->query('SHOW TABLES');
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_NUM) : [];

                foreach ($rows as $row) {
                    if (!isset($row[0])) {
                        continue;
                    }

                    $tableLookup[(string)$row[0]] = true;
                }
            } catch (Throwable $e) {
                $tableLookup = [];
            }
        }

        return isset($tableLookup[$tableName]);
    }
}
function mlSeasonRoundsHasStateColumns(PDO $pdo): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_SeasonRounds LIKE 'RoundState'");
        $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $checked = false;
    }

    return $checked;
}
function mlRoundSongsHasSongCommentColumn(PDO $pdo): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    if (!mlTableExists($pdo, 'ML_RoundSongs')) {
        $checked = false;
        return false;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM ML_RoundSongs LIKE 'SongComment'");
        $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $checked = false;
    }

    return $checked;
}
