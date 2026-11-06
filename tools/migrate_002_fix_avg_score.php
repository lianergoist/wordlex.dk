<?php
// Migration 002: retter leaderboard.avg_score.
//
// Skriver SQL til stdout - kør den med en MySQL-konto der må ændre tabellerne:
//   php tools/migrate_002_fix_avg_score.php live | mysql
//
// save_score beregnede gennemsnittet som (T + 2s) / (G + 2), fordi MySQL
// tildeler fra venstre mod højre og total_score/games_played allerede var
// talt op. Gennemsnittet genberegnes her ud fra de korrekte totaler.
// Migrationen kan køres flere gange.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (($argv[1] ?? '') !== 'live') {
    fwrite(STDERR, "Brug: php " . basename(__FILE__) . " live\n");
    exit(1);
}

foreach (['wordlex5_v2', 'wordlex6_v2'] as $db) {
    echo "UPDATE `$db`.leaderboard SET avg_score = IF(games_played > 0, total_score / games_played, 0);\n";
}
