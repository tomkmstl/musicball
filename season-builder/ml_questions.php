<?php
// ml_questions.php
require_once __DIR__ . '/ml_season_builder.php';

$mlQuestionConfig = mlLoadSeasonQuestionConfig($pdo, $seasonId);
$mlHeadings = $mlQuestionConfig['headings'];
$q2Options = $mlQuestionConfig['q2Options'];
$q3Options = $mlQuestionConfig['q3Options'];
