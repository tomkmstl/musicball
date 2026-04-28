<?php
// ml_season_builder.php
// Helper functions for database-driven season setup / round rendering.

function mlTableExists(PDO $pdo, $tableName) {
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        " );
        $stmt->execute([$tableName]);
        $cache[$tableName] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $cache[$tableName] = false;
    }

    return $cache[$tableName];
}

function mlSeasonBuilderAvailable(PDO $pdo) {
    return mlTableExists($pdo, 'ML_FixedRounds')
        && mlTableExists($pdo, 'ML_SeasonRoundSlots')
        && mlTableExists($pdo, 'ML_SeasonQ2Options')
        && mlTableExists($pdo, 'ML_SeasonQ3Options');
}

function mlOrdinalLabel($number) {
    $number = (int)$number;
    $abs = abs($number);
    $mod100 = $abs % 100;

    if ($mod100 >= 11 && $mod100 <= 13) {
        return $number . 'th';
    }

    switch ($abs % 10) {
        case 1: return $number . 'st';
        case 2: return $number . 'nd';
        case 3: return $number . 'rd';
        default: return $number . 'th';
    }
}

function mlDefaultQuestionConfig() {
    return [
        'headings' => [
            'q1' => [
                'wizard' => 'MLP Category Pool',
                'choice' => 'MLP Category Pool',
            ],
            'q2' => [
                'wizard' => [
                    1 => 'Main Character',
                    2 => 'Doing a Thing',
                ],
                'choice' => 'Someone\'s Walkman',
            ],
            'q3' => [
                'wizard' => 'Era Pick',
                'choice' => 'Era Pick',
            ],
        ],
        'q2Options' => [
            1 => [
                1 => 'Willie Nelson',
                2 => 'Bigfoot',
                3 => 'The Roadrunner',
                4 => 'Patti Smith',
                5 => 'Nick Hexum',
                6 => 'Regina George',
            ],
            2 => [
                1 => 'driving across the country',
                2 => 'cleaning the house',
                3 => 'getting ready to go clubbing',
                4 => 'running late for a flight',
                5 => 'power-walking in the rain',
                6 => 'staring at the ceiling at 2am',
            ],
        ],
        'q3Options' => [
            1 => '1967-1970',
            2 => '1978-1982',
            3 => '2012',
            4 => '1983-1986',
            5 => '1975-1978',
            6 => '2004',
        ],
    ];
}

function mlLoadSeasonQuestionConfig(PDO $pdo, $seasonId) {
    $config = mlDefaultQuestionConfig();

    if (!mlSeasonBuilderAvailable($pdo)) {
        return $config;
    }

    $q2Stmt = $pdo->prepare('SELECT PartNumber, OptionIndex, Label FROM ML_SeasonQ2Options WHERE SeasonID = ? ORDER BY PartNumber, OptionIndex');
    $q2Stmt->execute([(int)$seasonId]);
    $q2Rows = $q2Stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($q2Rows)) {
        $config['q2Options'] = [1 => [], 2 => []];
        foreach ($q2Rows as $row) {
            $part = (int)$row['PartNumber'];
            $idx = (int)$row['OptionIndex'];
            if (!isset($config['q2Options'][$part])) {
                $config['q2Options'][$part] = [];
            }
            $config['q2Options'][$part][$idx] = (string)$row['Label'];
        }
    }

    $q3Stmt = $pdo->prepare('SELECT OptionIndex, Label FROM ML_SeasonQ3Options WHERE SeasonID = ? ORDER BY OptionIndex');
    $q3Stmt->execute([(int)$seasonId]);
    $q3Rows = $q3Stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($q3Rows)) {
        $config['q3Options'] = [];
        foreach ($q3Rows as $row) {
            $config['q3Options'][(int)$row['OptionIndex']] = (string)$row['Label'];
        }
    }

    return $config;
}

function mlLoadFixedRoundLibrary(PDO $pdo) {
    if (!mlTableExists($pdo, 'ML_FixedRounds')) {
        return [];
    }

    $stmt = $pdo->query('SELECT FixedRoundID, Title, Tagline, IsActive, CreatedSeasonID, CreatedAt FROM ML_FixedRounds ORDER BY Title ASC, FixedRoundID ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mlLoadSeasonRoundSlots(PDO $pdo, $seasonId, $slotCount = 12) {
    $slots = [];
    for ($i = 1; $i <= $slotCount; $i++) {
        $slots[$i] = [
            'round_number' => $i,
            'round_type' => '',
            'fixed_round_id' => '',
            'q1_rank' => '',
            'title_override' => '',
            'tag_override' => '',
            'schedule_left' => '',
            'schedule_right' => '',
        ];
    }

    if (!mlTableExists($pdo, 'ML_SeasonRoundSlots')) {
        return $slots;
    }

    $stmt = $pdo->prepare('SELECT RoundNumber, RoundType, FixedRoundID, Q1Rank, TitleOverride, TagOverride, SongsDue, VotesDue FROM ML_SeasonRoundSlots WHERE SeasonID = ? ORDER BY RoundNumber');
    $stmt->execute([(int)$seasonId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roundNumber = (int)$row['RoundNumber'];
        if (!isset($slots[$roundNumber])) {
            continue;
        }

        $slots[$roundNumber] = [
            'round_number' => $roundNumber,
            'round_type' => (string)$row['RoundType'],
            'fixed_round_id' => $row['FixedRoundID'] !== null ? (string)(int)$row['FixedRoundID'] : '',
            'q1_rank' => $row['Q1Rank'] !== null ? (string)(int)$row['Q1Rank'] : '',
            'title_override' => (string)($row['TitleOverride'] ?? ''),
            'tag_override' => (string)($row['TagOverride'] ?? ''),
            'schedule_left' => (string)($row['SongsDue'] ?? ''),
            'schedule_right' => (string)($row['VotesDue'] ?? ''),
        ];
    }

    return $slots;
}

function mlComputeTopQ1ByRank(PDO $pdo, $seasonId, $limit = 12) {
    $stmt = $pdo->prepare("\n        SELECT c.CategoryIndex, c.Title, COALESCE(SUM(v.Points), 0) AS TotalPoints\n        FROM ML_Q1Categories c\n        LEFT JOIN ML_Q1Votes v\n          ON v.SeasonID = c.SeasonID\n         AND v.CategoryIndex = c.CategoryIndex\n        WHERE c.SeasonID = ?\n        GROUP BY c.CategoryIndex, c.Title\n        ORDER BY TotalPoints DESC, c.CategoryIndex ASC\n        LIMIT " . (int)$limit);
    $stmt->execute([(int)$seasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mlComputeWinningEraLabel(PDO $pdo, $seasonId, array $q3Options) {
    $q3Counts = [];

    $stmt = $pdo->prepare('SELECT Choice1Index, Choice2Index FROM ML_Q3Answers WHERE SeasonID = ?');
    $stmt->execute([(int)$seasonId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $c1 = (int)$row['Choice1Index'];
        $c2 = (int)$row['Choice2Index'];

        if (!isset($q3Counts[$c1])) { $q3Counts[$c1] = 0; }
        if (!isset($q3Counts[$c2])) { $q3Counts[$c2] = 0; }

        $q3Counts[$c1]++;
        $q3Counts[$c2]++;
    }

    $label = 'TBD Era';
    $bestIndex = null;
    $bestCount = -1;

    foreach ($q3Counts as $idx => $cnt) {
        if ($cnt > $bestCount || ($cnt === $bestCount && ($bestIndex === null || $idx < $bestIndex))) {
            $bestIndex = $idx;
            $bestCount = $cnt;
        }
    }

    if ($bestIndex !== null && isset($q3Options[$bestIndex])) {
        $label = $q3Options[$bestIndex];
    }

    return $label;
}

function mlComputeWinningQ2MadlibLabel(PDO $pdo, $seasonId, array $q2Options) {
    $q2Counts = [1 => [], 2 => []];

    $stmt = $pdo->prepare('SELECT QuestionNumber, Choice1Index, Choice2Index FROM ML_Q2Answers WHERE SeasonID = ? AND QuestionNumber IN (1, 2)');
    $stmt->execute([(int)$seasonId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $part = (int)$row['QuestionNumber'];
        $c1 = (int)$row['Choice1Index'];
        $c2 = (int)$row['Choice2Index'];

        if (!isset($q2Counts[$part][$c1])) { $q2Counts[$part][$c1] = 0; }
        if (!isset($q2Counts[$part][$c2])) { $q2Counts[$part][$c2] = 0; }

        $q2Counts[$part][$c1]++;
        $q2Counts[$part][$c2]++;
    }

    $labels = [];
    foreach ([1, 2] as $part) {
        $bestIndex = null;
        $bestCount = -1;

        foreach ($q2Counts[$part] as $idx => $cnt) {
            if ($cnt > $bestCount || ($cnt === $bestCount && ($bestIndex === null || $idx < $bestIndex))) {
                $bestIndex = $idx;
                $bestCount = $cnt;
            }
        }

        if ($bestIndex !== null && isset($q2Options[$part][$bestIndex])) {
            $labels[] = $q2Options[$part][$bestIndex];
        } else {
            $labels[] = 'TBD';
        }
    }

    return trim(implode(' ', $labels));
}

function mlComputeWalkmanDisplays(PDO $pdo, $seasonId, $count = 1) {
    $count = max(1, (int)$count);
    $defaultDisplay = "A League Member's Walkman";
    $displays = array_fill(0, $count, $defaultDisplay);

    try {
        $candidateStmt = $pdo->prepare("
            SELECT u.UserID, u.UserName
            FROM ML_Users u
            LEFT JOIN ML_WalkmanExcluded e
              ON u.UserID = e.UserID
             AND e.SeasonID = ?
            WHERE e.UserID IS NULL
            ORDER BY u.UserID ASC
        ");
        $candidateStmt->execute([(int)$seasonId]);
        $candidateRows = $candidateStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($candidateRows)) {
            return $displays;
        }

        $candidateById = [];
        foreach ($candidateRows as $candidateRow) {
            $candidateById[(int)$candidateRow['UserID']] = $candidateRow;
        }

        $configKeys = [];
        for ($position = 1; $position <= $count; $position++) {
            $configKeys[$position] = ($position === 1) ? 'walkman_user_id' : 'walkman_user_id_' . $position;
        }

        $savedConfigByKey = [];
        $configStmt = $pdo->prepare('SELECT ConfigKey, ConfigValue FROM ML_Config WHERE SeasonID = ? AND ConfigKey LIKE ?');
        $configStmt->execute([(int)$seasonId, 'walkman_user_id%']);
        foreach ($configStmt->fetchAll(PDO::FETCH_ASSOC) as $cfgRow) {
            $savedConfigByKey[(string)$cfgRow['ConfigKey']] = (int)$cfgRow['ConfigValue'];
        }

        $selectedUsersByPosition = [];
        $usedUserIds = [];

        for ($position = 1; $position <= $count; $position++) {
            $configKey = $configKeys[$position];
            $savedUserId = isset($savedConfigByKey[$configKey]) ? (int)$savedConfigByKey[$configKey] : 0;

            if ($savedUserId > 0 && isset($candidateById[$savedUserId]) && !isset($usedUserIds[$savedUserId])) {
                $selectedUsersByPosition[$position] = $candidateById[$savedUserId];
                $usedUserIds[$savedUserId] = true;
            }
        }

        for ($position = 1; $position <= $count; $position++) {
            if (isset($selectedUsersByPosition[$position])) {
                continue;
            }

            $remainingCandidates = [];
            foreach ($candidateRows as $candidateRow) {
                $candidateUserId = (int)$candidateRow['UserID'];
                if (!isset($usedUserIds[$candidateUserId])) {
                    $remainingCandidates[] = $candidateRow;
                }
            }

            if (empty($remainingCandidates)) {
                break;
            }

            $randomIndex = random_int(0, count($remainingCandidates) - 1);
            $selectedUser = $remainingCandidates[$randomIndex];
            $selectedUserId = (int)$selectedUser['UserID'];

            $selectedUsersByPosition[$position] = $selectedUser;
            $usedUserIds[$selectedUserId] = true;
        }

        $saveStmt = $pdo->prepare("
            INSERT INTO ML_Config (SeasonID, ConfigKey, ConfigValue)
            VALUES (:season_id, :config_key, :config_value)
            ON DUPLICATE KEY UPDATE ConfigValue = VALUES(ConfigValue)
        ");

        for ($position = 1; $position <= $count; $position++) {
            if (!isset($selectedUsersByPosition[$position])) {
                continue;
            }

            $selectedUser = $selectedUsersByPosition[$position];
            $selectedUserId = (int)$selectedUser['UserID'];
            $configKey = $configKeys[$position];

            if (!isset($savedConfigByKey[$configKey]) || (int)$savedConfigByKey[$configKey] !== $selectedUserId) {
                $saveStmt->execute([
                    ':season_id' => (int)$seasonId,
                    ':config_key' => $configKey,
                    ':config_value' => (string)$selectedUserId,
                ]);
            }

            if (!empty($selectedUser['UserName'])) {
                $displays[$position - 1] = $selectedUser['UserName'] . "'s Walkman";
            }
        }
    } catch (Throwable $e) {
        return $displays;
    }

    return $displays;
}

function mlComputeWalkmanDisplay(PDO $pdo, $seasonId) {
    $displays = mlComputeWalkmanDisplays($pdo, $seasonId, 1);
    return isset($displays[0]) ? $displays[0] : "A League Member's Walkman";
}

function mlSeasonRoundsAvailable(PDO $pdo) {
    return mlTableExists($pdo, 'ML_SeasonRounds');
}

function mlLoadCommittedSeasonRounds(PDO $pdo, $seasonId, $slotCount = 12) {
    $rounds = [];

    if (!mlSeasonRoundsAvailable($pdo)) {
        return $rounds;
    }

    $stmt = $pdo->prepare('SELECT RoundNumber, Title, Tagline, SongsDue, VotesDue FROM ML_SeasonRounds WHERE SeasonID = ? ORDER BY RoundNumber');
    $stmt->execute([(int)$seasonId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roundNumber = (int)$row['RoundNumber'];
        if ($roundNumber < 1 || $roundNumber > (int)$slotCount) {
            continue;
        }

        $rounds[$roundNumber] = [
            'round_number' => $roundNumber,
            'title' => (string)($row['Title'] ?? ''),
            'tag' => (string)($row['Tagline'] ?? ''),
            'schedule_left' => (string)($row['SongsDue'] ?? ''),
            'schedule_right' => (string)($row['VotesDue'] ?? ''),
            'schedule_is_utc' => true,
        ];
    }

    ksort($rounds);
    return $rounds;
}

function mlSeasonHasCommittedRounds(PDO $pdo, $seasonId, $slotCount = 12) {
    return count(mlLoadCommittedSeasonRounds($pdo, $seasonId, $slotCount)) > 0;
}

function mlResolveSeasonRounds(PDO $pdo, $seasonId, $seasonName, array $q2Options, array $q3Options, $slotCount = 12) {
    $committedRounds = mlLoadCommittedSeasonRounds($pdo, $seasonId, $slotCount);
    if (!empty($committedRounds)) {
        return array_values($committedRounds);
    }

    $rounds = [];
    $useBuilderRounds = false;

    if (mlSeasonBuilderAvailable($pdo)) {
        $builderSlots = mlLoadSeasonRoundSlots($pdo, $seasonId, $slotCount);
        foreach ($builderSlots as $slot) {
            if ($slot['round_type'] !== '') {
                $useBuilderRounds = true;
                break;
            }
        }

        if ($useBuilderRounds) {
            $maxRank = 0;
            foreach ($builderSlots as $slot) {
                if ($slot['round_type'] === 'q1_ranked_category') {
                    $maxRank = max($maxRank, (int)$slot['q1_rank']);
                }
            }
            if ($maxRank <= 0) {
                $maxRank = $slotCount;
            }

            $topQ1Rows = mlComputeTopQ1ByRank($pdo, $seasonId, $maxRank);
            $q1ByRank = [];
            foreach ($topQ1Rows as $index => $row) {
                $q1ByRank[$index + 1] = $row;
            }

            $eraLabel = mlComputeWinningEraLabel($pdo, $seasonId, $q3Options);
            $q2CombinedLabel = mlComputeWinningQ2MadlibLabel($pdo, $seasonId, $q2Options);
            $walkmanSlotCount = 0;
            foreach ($builderSlots as $walkmanSlot) {
                if ($walkmanSlot['round_type'] === 'walkman') {
                    $walkmanSlotCount++;
                }
            }
            $walkmanDisplays = mlComputeWalkmanDisplays($pdo, $seasonId, max(1, $walkmanSlotCount));

            $fixedRoundLibrary = [];
            foreach (mlLoadFixedRoundLibrary($pdo) as $fixedRound) {
                $fixedRoundLibrary[(int)$fixedRound['FixedRoundID']] = $fixedRound;
            }

            foreach ($builderSlots as $roundNumber => $slot) {
                $title = 'TBD Round';
                $tag = '';

                switch ($slot['round_type']) {
                    case 'fixed':
                        $fixedRoundId = (int)$slot['fixed_round_id'];
                        if ($fixedRoundId > 0 && isset($fixedRoundLibrary[$fixedRoundId])) {
                            $title = (string)$fixedRoundLibrary[$fixedRoundId]['Title'];
                            $tag = (string)($fixedRoundLibrary[$fixedRoundId]['Tagline'] ?? '');
                        }
                        break;

                    case 'q1_ranked_category':
                        $rank = (int)$slot['q1_rank'];
                        if ($rank > 0 && isset($q1ByRank[$rank]['Title'])) {
                            $title = (string)$q1ByRank[$rank]['Title'];
                        }
                        if ($rank > 0) {
                            $tag = mlOrdinalLabel($rank) . ' most-voted category';
                        }
                        break;

                    case 'q2_madlib':
                        $title = $q2CombinedLabel;
                        $tag = 'Madlib Playlist Creator';
                        break;

                    case 'q3_era':
                        $title = $eraLabel;
                        $tag = 'Era';
                        break;

                    case 'walkman':
                        $title = !empty($walkmanDisplays) ? array_shift($walkmanDisplays) : "A League Member's Walkman";
                        $tag = 'Something that would fit right into a playlist of this randomly-selected MLP';
                        break;
                }

                if ($slot['title_override'] !== '') {
                    $title = $slot['title_override'];
                }
                if ($slot['tag_override'] !== '') {
                    $tag = $slot['tag_override'];
                }

                $rounds[] = [
                    'round_number' => (int)$roundNumber,
                    'title' => $title,
                    'tag' => $tag,
                    'schedule_left' => $slot['schedule_left'],
                    'schedule_right' => $slot['schedule_right'],
                    'schedule_is_utc' => true,
                ];
            }
        }
    }

    if (!empty($rounds)) {
        return $rounds;
    }

    $q1TopStmt = $pdo->prepare("
        SELECT c.CategoryIndex,
               c.Title,
               SUM(v.Points) AS TotalPoints
        FROM ML_Q1Votes v
        JOIN ML_Q1Categories c
          ON v.SeasonID = c.SeasonID
         AND v.CategoryIndex = c.CategoryIndex
        WHERE v.SeasonID = ?
        GROUP BY c.CategoryIndex, c.Title
        ORDER BY TotalPoints DESC, c.CategoryIndex ASC
        LIMIT 6
    ");
    $q1TopStmt->execute([(int)$seasonId]);
    $topQ1 = $q1TopStmt->fetchAll(PDO::FETCH_ASSOC);

    $defaultCategory = [
        'CategoryIndex' => null,
        'Title' => 'TBD Round',
    ];
    for ($i = 0; $i < 6; $i++) {
        if (!isset($topQ1[$i])) {
            $topQ1[$i] = $defaultCategory;
        }
    }

    $eraLabel = mlComputeWinningEraLabel($pdo, $seasonId, $q3Options);
    $q2CombinedLabel = mlComputeWinningQ2MadlibLabel($pdo, $seasonId, $q2Options);
    $walkmanDisplay = mlComputeWalkmanDisplay($pdo, $seasonId);

    return [
        [
            'round_number' => 1,
            'title' => 'My Current Jam ' . $seasonName,
            'tag' => '',
            'schedule_left' => 'submit on 1/9',
            'schedule_right' => 'vote by 1/14',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 2,
            'title' => $topQ1[3]['Title'],
            'tag' => '4th most-voted category',
            'schedule_left' => 'submit on 1/16',
            'schedule_right' => 'vote by 1/21',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 3,
            'title' => $walkmanDisplay,
            'tag' => 'Something that would fit right into a playlist of this randomly-selected MLP',
            'schedule_left' => 'submit on 1/23',
            'schedule_right' => 'vote by 1/28',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 4,
            'title' => 'Songs in the Queue s4e1',
            'tag' => '',
            'schedule_left' => 'submit on 1/30',
            'schedule_right' => 'vote by 2/4',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 5,
            'title' => $topQ1[0]['Title'],
            'tag' => '1st most-voted category',
            'schedule_left' => 'submit on 2/6',
            'schedule_right' => 'vote by 2/11',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 6,
            'title' => $topQ1[4]['Title'],
            'tag' => '5th most-voted category',
            'schedule_left' => 'submit on 2/13',
            'schedule_right' => 'vote by 2/18',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 7,
            'title' => $eraLabel,
            'tag' => 'Era',
            'schedule_left' => 'submit on 2/20',
            'schedule_right' => 'vote by 2/25',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 8,
            'title' => $topQ1[5]['Title'],
            'tag' => '6th most-voted category',
            'schedule_left' => 'submit on 2/27',
            'schedule_right' => 'vote by 3/4',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 9,
            'title' => $topQ1[2]['Title'],
            'tag' => '3rd most-voted category',
            'schedule_left' => 'submit on 3/6',
            'schedule_right' => 'vote by 3/11',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 10,
            'title' => $q2CombinedLabel,
            'tag' => 'Madlib Playlist Creator',
            'schedule_left' => 'submit on 3/13',
            'schedule_right' => 'vote by 3/18',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 11,
            'title' => 'Songs in the Queue s4e2',
            'tag' => '',
            'schedule_left' => 'submit on 3/20',
            'schedule_right' => 'vote by 3/25',
            'schedule_is_utc' => false,
        ],
        [
            'round_number' => 12,
            'title' => $topQ1[1]['Title'],
            'tag' => '2nd most-voted category',
            'schedule_left' => 'submit on 3/27',
            'schedule_right' => 'vote by 4/1',
            'schedule_is_utc' => false,
        ],
    ];
}
