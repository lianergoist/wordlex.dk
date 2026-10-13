<?php
// Migration 001: fælles brugere, ordstatus og ordbogens tabeller.
//
// Skriver SQL til stdout - kør den med en MySQL-konto der må ændre tabeller:
//   php tools/migrate_001_shared_users_and_word_status.php dev  | mysql
//   php tools/migrate_001_shared_users_and_word_status.php live | mysql
//
// Stopper den undervejs, kan den fortsætte fra et trin (se "-- TRIN:" i
// outputtet), fx:
//   php tools/migrate_001_shared_users_and_word_status.php dev remap | mysql
//
// Den fælles database (dev: wordlex_dev, live: wordlex) skal være oprettet,
// og spillets MySQL-bruger skal have SELECT/INSERT/UPDATE/DELETE på den.
// Migrationen kan kun køres én gang pr. miljø.
//
// Hvad den gør:
//   1. Opretter users, word_edits, word_locks og reports i den fælles database.
//   2. Flytter brugerne: 5-udgavens brugere beholder deres id. 6-udgavens
//      brugere får nye id'er, undtagen dem hvis navn findes i 5-udgaven - de
//      slås sammen, og 6-kodeordet gemmes som alt_password_hash.
//   3. Omnummererer user_id i 6-udgavens leaderboard og games.
//   4. Flytter last_game_at fra users til leaderboard i hver udgave.
//   5. Omdøber de gamle users-tabeller til users_old (backup).
//   6. Erstatter words.is_common med words.status (common/rare/deleted).
//   7. Gør lianergoist (id 3) til admin.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$env = $argv[1] ?? '';
$names = [
    'dev'  => ['wordlex_dev', 'wordlex5_dev', 'wordlex6_dev'],
    'live' => ['wordlex', 'wordlex5', 'wordlex6'],
];
if (!isset($names[$env])) {
    fwrite(STDERR, "Brug: php " . basename(__FILE__) . " dev|live [starttrin]\n");
    exit(1);
}
[$S, $D5, $D6] = $names[$env];
$fromStep = $argv[2] ?? null;

$sql = <<<SQL
-- TRIN: tables

CREATE TABLE `$S`.users (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  alt_password_hash VARCHAR(255) NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  edit_blocked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

CREATE TABLE `$S`.word_edits (
  id INT NOT NULL AUTO_INCREMENT,
  word VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  word_length TINYINT NOT NULL,
  user_id INT NULL,
  from_status ENUM('common','rare','deleted') NULL COMMENT 'NULL = nyt ord',
  to_status ENUM('common','rare','deleted') NOT NULL,
  comment TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY word (word),
  KEY user_id (user_id),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

CREATE TABLE `$S`.word_locks (
  word VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  locked_by INT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  locked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

CREATE TABLE `$S`.reports (
  id INT NOT NULL AUTO_INCREMENT,
  reporter_id INT NULL,
  edit_id INT NULL,
  reported_user_id INT NULL,
  reason TEXT NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_by INT NULL,
  resolved_at TIMESTAMP NULL,
  resolution TEXT NULL,
  PRIMARY KEY (id),
  KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_danish_ci;

-- TRIN: users
-- 2. Brugere
INSERT INTO `$S`.users (id, username, password_hash, created_at)
  SELECT id, username, password_hash, created_at FROM `$D5`.users;

INSERT INTO `$S`.users (username, password_hash, created_at)
  SELECT u6.username, u6.password_hash, u6.created_at
  FROM `$D6`.users u6
  WHERE NOT EXISTS (SELECT 1 FROM `$S`.users s WHERE s.username = u6.username COLLATE utf8mb4_danish_ci)
  ORDER BY u6.id;

UPDATE `$S`.users s
  JOIN `$D6`.users u6 ON s.username = u6.username COLLATE utf8mb4_danish_ci
  SET s.alt_password_hash = u6.password_hash
  WHERE s.password_hash <> u6.password_hash COLLATE utf8mb4_bin;

UPDATE `$S`.users SET role = 'admin' WHERE id = 3 AND username = 'lianergoist';

-- TRIN: last_game_at
-- 4. last_game_at flyttes til leaderboard (før omnummereringen)
ALTER TABLE `$D5`.leaderboard ADD COLUMN last_game_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE `$D6`.leaderboard ADD COLUMN last_game_at TIMESTAMP NULL DEFAULT NULL;
UPDATE `$D5`.leaderboard l JOIN `$D5`.users u ON l.id = u.id SET l.last_game_at = u.last_game_at;
UPDATE `$D6`.leaderboard l JOIN `$D6`.users u ON l.id = u.id SET l.last_game_at = u.last_game_at;

-- TRIN: user_id_map
-- 3. Omnummerering af 6-udgavens brugere
CREATE TABLE `$D6`.user_id_map (old_id INT NOT NULL PRIMARY KEY, new_id INT NOT NULL);
INSERT INTO `$D6`.user_id_map (old_id, new_id)
  SELECT u6.id, s.id FROM `$D6`.users u6
  JOIN `$S`.users s ON s.username = u6.username COLLATE utf8mb4_danish_ci;

-- TRIN: remap
-- leaderboard.id har en fremmednøgle til den gamle users-tabel, som skal væk
ALTER TABLE `$D5`.leaderboard DROP FOREIGN KEY IF EXISTS leaderboard_ibfk_1;
ALTER TABLE `$D6`.leaderboard DROP FOREIGN KEY IF EXISTS leaderboard_ibfk_1;

-- Leaderboard-id er primærnøgle: flyt først alle ud af vejen
UPDATE `$D6`.leaderboard SET id = id + 1000000;
UPDATE `$D6`.leaderboard l JOIN `$D6`.user_id_map m ON l.id = m.old_id + 1000000 SET l.id = m.new_id;
DELETE FROM `$D6`.leaderboard WHERE id >= 1000000;
UPDATE `$D6`.games g JOIN `$D6`.user_id_map m ON g.user_id = m.old_id SET g.user_id = m.new_id;
DROP TABLE `$D6`.user_id_map;

-- TRIN: rename_users
-- 5. Gamle brugertabeller gemmes som backup
RENAME TABLE `$D5`.users TO `$D5`.users_old;
RENAME TABLE `$D6`.users TO `$D6`.users_old;

-- TRIN: word_status
-- 6. Ordstatus
ALTER TABLE `$D5`.words ADD COLUMN status ENUM('common','rare','deleted') NOT NULL DEFAULT 'rare' AFTER word, ADD KEY status (status);
UPDATE `$D5`.words SET status = IF(is_common = 1, 'common', 'rare');
ALTER TABLE `$D5`.words DROP COLUMN is_common;

ALTER TABLE `$D6`.words ADD COLUMN status ENUM('common','rare','deleted') NOT NULL DEFAULT 'rare' AFTER word, ADD KEY status (status);
UPDATE `$D6`.words SET status = IF(is_common = 1, 'common', 'rare');
ALTER TABLE `$D6`.words DROP COLUMN is_common;

SQL;

if ($fromStep !== null) {
    $pos = strpos($sql, "-- TRIN: $fromStep\n");
    if ($pos === false) {
        fwrite(STDERR, "Ukendt trin: $fromStep\n");
        exit(1);
    }
    $sql = substr($sql, $pos);
}

echo "-- Migration 001 ($env)\n\n" . $sql;
