<?php
// lib/api.php - fælles API for alle WordleX-udgaver (5 og 6 bogstaver).
//
// Filen kaldes ikke direkte. Hver udgave har sin egen tynde api.php (fx
// w5/api.php), der kun definerer WORD_LENGTH og derefter inkluderer denne
// fil. Database og antal forsøg slås op i lib/config.php.
//
// games-tabellen er den eneste autoritative kilde til point: guess_history
// gemmer alle gæt, så serveren selv kan genberegne hvad spilleren ved, og
// save_score stoler aldrig på et tal fra klienten.

if (!defined('WORD_LENGTH')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

define('MAX_ROWS', VARIANT_ROWS[WORD_LENGTH]);
define('FALLBACK_WORD', VARIANT_FALLBACK_WORDS[WORD_LENGTH]);

startWordlexSession();
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = connectDb(VARIANT_DBS[WORD_LENGTH]);
    $shared = connectDb(SHARED_DB);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Databaseforbindelse fejlede']);
    exit;
}

$action = $_GET['action'] ?? '';

// --- POINT-KONFIGURATION ---
//
// Værdien af et helt ord (alle brikker grønne) i hver række. Fem (eller seks)
// grønne i første række giver altså 1.000 point i begge udgaver, så de kan
// sammenlignes direkte. En grøn brik er rækkens værdi delt med ordets længde,
// en gul er halvdelen af en grøn.
//
// Der gives kun point for NY viden:
//   - en grøn på en plads, der ikke var grøn før, giver en grøn,
//   - et bogstav, der ikke var fundet før, og som står gult, giver en gul,
//   - et bogstav, der var gult og nu bliver grønt, giver forskellen (grøn - gul).
// Når ordet gættes, bliver de resterende pladser grønne og tæller med som
// almindelige nye grønne - jo tidligere, jo flere point.
const ROW_VALUES = [1000, 750, 550, 400, 275, 175, 100];
const YELLOW_FRACTION = 0.5;

// --- HINT-PRIS ---
//
// Prisen afhænger af hvor mange mulige løsningsord (status = 'common') der er
// tilbage ud fra ALT hvad spilleren ved: grønne, gule og udelukkede bogstaver.
// Serveren regner det selv ud fra guess_history - klientens egne oplysninger
// bruges ikke, så prisen ikke kan snydes ned. Første tærskel der er
// opfyldt (antal > tærskel) bestemmer prisen.
const HINT_PRICE_TIERS = [
    200 => 25,  // over 200 mulige ord
    50  => 50,  // 51-200
    10  => 100, // 11-50
    2   => 150, // 3-10
    0   => 200, // 1-2
];

function rowValue(int $row): float {
    return ROW_VALUES[$row] ?? end(ROW_VALUES);
}

function hintPrice(int $candidateCount): int {
    foreach (HINT_PRICE_TIERS as $threshold => $price) {
        if ($candidateCount > $threshold) {
            return $price;
        }
    }
    return end(HINT_PRICE_TIERS);
}

function splitWord(string $word): array {
    return mb_str_split($word, 1, 'UTF-8');
}

/**
 * Beregner Wordle-feedback (correct/present/absent) for et gæt mod et
 * hemmeligt ord, inkl. korrekt håndtering af dobbelt-bogstaver.
 */
function computeFeedback(string $secret, string $guess): array {
    $secretArr = splitWord($secret);
    $guessArr = splitWord($guess);
    $result = array_fill(0, WORD_LENGTH, 'absent');
    $letterCounts = array_count_values($secretArr);

    for ($i = 0; $i < WORD_LENGTH; $i++) {
        if ($guessArr[$i] === $secretArr[$i]) {
            $result[$i] = 'correct';
            $letterCounts[$guessArr[$i]]--;
        }
    }
    for ($i = 0; $i < WORD_LENGTH; $i++) {
        if ($result[$i] !== 'correct') {
            $char = $guessArr[$i];
            if (isset($letterCounts[$char]) && $letterCounts[$char] > 0) {
                $result[$i] = 'present';
                $letterCounts[$char]--;
            }
        }
    }
    return $result;
}

/**
 * Hvad spilleren ved efter en række gæt: hvilke pladser der er grønne, og
 * hvor mange forekomster af hvert bogstav der er fundet (grønne + gule).
 *
 * @return array{green: array<int,string>, known: array<string,int>}
 */
function buildKnowledge(string $secret, array $guesses): array {
    $green = [];
    $known = [];

    foreach ($guesses as $g) {
        $feedback = computeFeedback($secret, $g);
        $found = [];
        foreach (splitWord($g) as $i => $ch) {
            if ($feedback[$i] === 'correct') {
                $green[$i] = $ch;
            }
            if ($feedback[$i] !== 'absent') {
                $found[$ch] = ($found[$ch] ?? 0) + 1;
            }
        }
        foreach ($found as $ch => $n) {
            $known[$ch] = max($known[$ch] ?? 0, $n);
        }
    }

    // Grønne pladser fra forskellige gæt kan tilsammen afsløre flere
    // forekomster af et bogstav end noget enkelt gæt gjorde.
    foreach (array_count_values($green) as $ch => $n) {
        $known[$ch] = max($known[$ch] ?? 0, $n);
    }

    return ['green' => $green, 'known' => $known];
}

/**
 * Point for et gæt ud fra den nye viden det giver (se POINT-KONFIGURATION).
 *
 * @return array{score:int, new_greens:int, new_yellows:int, upgrades:int}
 */
function scoreGuess(string $secret, array $previousGuesses, string $guess): array {
    $before = buildKnowledge($secret, $previousGuesses);
    $after = buildKnowledge($secret, array_merge($previousGuesses, [$guess]));

    $newGreensByLetter = [];
    foreach ($after['green'] as $i => $ch) {
        if (!isset($before['green'][$i])) {
            $newGreensByLetter[$ch] = ($newGreensByLetter[$ch] ?? 0) + 1;
        }
    }

    $newGreens = 0;
    $newYellows = 0;
    $upgrades = 0;

    foreach ($after['known'] as $ch => $knownAfter) {
        $newInstances = $knownAfter - ($before['known'][$ch] ?? 0);
        $greens = $newGreensByLetter[$ch] ?? 0;
        $fresh = min($greens, $newInstances);

        $newGreens += $fresh;
        $upgrades += $greens - $fresh;
        $newYellows += $newInstances - $fresh;
    }

    $greenValue = rowValue(count($previousGuesses)) / WORD_LENGTH;
    $yellowValue = $greenValue * YELLOW_FRACTION;
    $score = $newGreens * $greenValue
           + $newYellows * $yellowValue
           + $upgrades * ($greenValue - $yellowValue);

    return [
        'score'       => (int)round($score),
        'new_greens'  => $newGreens,
        'new_yellows' => $newYellows,
        'upgrades'    => $upgrades,
    ];
}

/**
 * Genopbygger de kumulative constraints (pattern/absent/present/not_at_pos),
 * som getMatchingWords() forventer, ud fra spillets tidligere gæt.
 */
function buildConstraintsFromGuesses(string $secret, array $guesses): array {
    $pattern = array_fill(0, WORD_LENGTH, '_');
    $absent = [];
    $present = [];
    $notAtPos = array_fill(0, WORD_LENGTH, []);

    foreach ($guesses as $g) {
        $feedback = computeFeedback($secret, $g);
        foreach (splitWord($g) as $i => $ch) {
            if ($feedback[$i] === 'correct') {
                $pattern[$i] = $ch;
            } elseif ($feedback[$i] === 'present') {
                $present[] = $ch;
                $notAtPos[$i][] = $ch;
            } else {
                $absent[] = $ch;
                // Et "overskydende" dobbeltbogstav er gråt, men bogstavet kan
                // stadig findes andre steder - det står i hvert fald ikke her.
                $notAtPos[$i][] = $ch;
            }
        }
    }

    return [
        'pattern'    => implode('', $pattern),
        'absent'     => array_values(array_unique($absent)),
        'present'    => array_values(array_unique($present)),
        'not_at_pos' => $notAtPos,
    ];
}

/**
 * Henter de ord der matcher et sæt constraints.
 *
 * OBS: Filtreringen sker bevidst i PHP med mb_-funktioner og IKKE via LIKE i
 * SQL. words-tabellen bruger utf8mb4_danish_ci, hvor fx "AA" og "Å" regnes
 * for ens, så LIKE kan matche forkert. mb_-funktionerne arbejder direkte på
 * UTF-8-strengen og er uafhængige af kolonnens collation.
 *
 * @param bool $onlyCommon Når true, kun status = 'common' (puljen af løsningsord, som hint-listen bruger).
 * @return string[] Matchende ord (store bogstaver, unikke)
 */
function getMatchingWords(PDO $pdo, array $constraints, bool $onlyCommon = false): array {
    $pattern = $constraints['pattern'] ?? str_repeat('_', WORD_LENGTH);
    $absent = $constraints['absent'] ?? [];
    $present = $constraints['present'] ?? [];
    $notAtPos = $constraints['not_at_pos'] ?? array_fill(0, WORD_LENGTH, []);

    $patternChars = [];
    foreach (splitWord($pattern) as $i => $c) {
        $patternChars[$i] = ($c !== '_') ? $c : null;
    }

    $knownChars = array_unique(array_merge($present, array_filter($patternChars)));
    $strictlyAbsent = array_diff($absent, $knownChars);

    // Grov, byte-baseret forudfiltrering i SQL; den endelige afgørelse sker i PHP.
    $sql = "SELECT DISTINCT TRIM(word) AS word FROM words WHERE status <> 'deleted' AND LENGTH(TRIM(word)) BETWEEN "
         . WORD_LENGTH . " AND " . (WORD_LENGTH * 4);
    if ($onlyCommon) {
        $sql .= " AND status = 'common'";
    }
    $candidates = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    $matches = [];
    foreach ($candidates as $word) {
        $wordUpper = mb_strtoupper(trim($word), 'UTF-8');
        if (mb_strlen($wordUpper, 'UTF-8') !== WORD_LENGTH) {
            continue;
        }
        $letters = splitWord($wordUpper);

        for ($i = 0; $i < WORD_LENGTH; $i++) {
            if ($patternChars[$i] !== null && $letters[$i] !== $patternChars[$i]) {
                continue 2;
            }
            if (!empty($notAtPos[$i]) && in_array($letters[$i], $notAtPos[$i], true)) {
                continue 2;
            }
        }
        foreach ($strictlyAbsent as $aChar) {
            if (in_array($aChar, $letters, true)) {
                continue 2;
            }
        }
        foreach ($present as $pChar) {
            if (!in_array($pChar, $letters, true)) {
                continue 2;
            }
        }

        $matches[] = $wordUpper;
    }

    return array_values(array_unique($matches));
}

/**
 * Henter et igangværende spil og dets gæt-historik, eller null.
 */
function loadActiveGame(PDO $pdo, string $gameId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = :id AND is_finished = 0");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        return null;
    }
    $history = json_decode($game['guess_history'] ?? '', true);
    $game['history'] = is_array($history) ? $history : [];
    return $game;
}

// --- BRUGER HÅNDTERING (fælles konti, se lib/auth.php) ---

handleAuthAction($action, $shared, WORD_LENGTH);

// --- SPIL HÅNDTERING ---

function logMissingWord(PDO $pdo, string $word): bool {
    $cleanWord = mb_strtoupper(trim($word), 'UTF-8');

    if (empty($cleanWord) || mb_strlen($cleanWord, 'UTF-8') !== WORD_LENGTH) {
        return false;
    }

    $sql = "INSERT INTO userwords (word)
            VALUES (:word)
            ON DUPLICATE KEY UPDATE count = count + 1";

    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([':word' => $cleanWord]);
    } catch (PDOException $e) {
        error_log("Fejl ved gemning af brugerord: " . $e->getMessage());
        return false;
    }
}

if ($action === 'start') {
    $stmt = $pdo->query("SELECT word FROM words WHERE status = 'common' ORDER BY RAND() LIMIT 1");
    $row = $stmt->fetch();
    $secret = $row ? mb_strtoupper(trim($row['word']), 'UTF-8') : FALLBACK_WORD;

    $gameId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    // Knyt spillet til den loggede-ind bruger (NULL hvis gæst), så vi senere
    // kan validere at det er DEN bruger, der forsøger at gemme scoren for det.
    $userId = $_SESSION['user_id'] ?? null;

    $stmt = $pdo->prepare("INSERT INTO games (game_id, secret_word, user_id) VALUES (:game_id, :secret, :uid)");
    $stmt->execute([':game_id' => $gameId, ':secret' => $secret, ':uid' => $userId]);

    echo json_encode(['game_id' => $gameId]);
    exit;
}

if ($action === 'guess') {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = $input['game_id'] ?? '';
    $guess = mb_strtoupper(trim($input['guess'] ?? ''), 'UTF-8');

    if (mb_strlen($guess, 'UTF-8') !== WORD_LENGTH) {
        echo json_encode(['valid' => false, 'message' => 'Ordet skal være på ' . WORD_LENGTH . ' bogstaver']);
        exit;
    }

    $game = loadActiveGame($pdo, $gameId);
    if (!$game) {
        echo json_encode(['error' => 'Ugyldigt spil']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM words WHERE word = :word AND status <> 'deleted'");
    $stmt->execute([':word' => $guess]);
    if ($stmt->fetchColumn() == 0) {
        logMissingWord($pdo, $guess);
        echo json_encode(['valid' => false, 'message' => 'Ordet findes ikke i ordlisten', 'unknown_word' => $guess]);
        exit;
    }

    $secret = mb_strtoupper($game['secret_word'], 'UTF-8');
    $result = computeFeedback($secret, $guess);
    $scoring = scoreGuess($secret, $game['history'], $guess);
    $guessHistory = array_merge($game['history'], [$guess]);

    $attempts = $game['attempts'] + 1;
    $isCorrect = ($guess === $secret);
    $isFinished = ($isCorrect || $attempts >= MAX_ROWS) ? 1 : 0;
    $runningTotalScore = (int)$game['total_score'] + $scoring['score'];

    $stmt = $pdo->prepare("
        UPDATE games
        SET attempts = :att,
            is_finished = :fin,
            guess_history = :hist,
            total_score = total_score + :score,
            won = :won
        WHERE game_id = :id
    ");
    $stmt->execute([
        ':att'   => $attempts,
        ':fin'   => $isFinished,
        ':hist'  => json_encode($guessHistory, JSON_UNESCAPED_UNICODE),
        ':score' => $scoring['score'],
        ':won'   => $isCorrect ? 1 : 0,
        ':id'    => $gameId
    ]);

    echo json_encode([
        'valid' => true,
        'result' => $result,
        'is_correct' => $isCorrect,
        'is_finished' => (bool)$isFinished,
        'secret_word' => $isFinished ? $secret : null,
        'guess_score' => $scoring['score'],
        'total_score' => $runningTotalScore,
        'new_greens' => $scoring['new_greens'],
        'new_yellows' => $scoring['new_yellows'],
        'upgrades' => $scoring['upgrades']
    ]);
    exit;
}

// --- GEM POINT TIL LEADERBOARD ---

if ($action === 'save_score') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Ikke logget ind']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = $input['game_id'] ?? '';
    $userId = $_SESSION['user_id'];
    $username = $_SESSION['username'] ?? 'Anonym';

    // VIGTIGT: $score kommer IKKE fra klienten. Den hentes udelukkende fra
    // games-tabellen, som kun serveren selv skriver til.
    $stmt = $pdo->prepare("
        SELECT total_score, won, is_finished, score_saved, user_id, hints_used
        FROM games
        WHERE game_id = :id
    ");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        echo json_encode(['success' => false, 'message' => 'Ugyldigt spil']);
        exit;
    }
    if (!$game['is_finished']) {
        echo json_encode(['success' => false, 'message' => 'Spillet er ikke afsluttet endnu']);
        exit;
    }
    if ($game['score_saved']) {
        // Forhindrer at samme game_id genindsendes for at score point flere gange.
        echo json_encode(['success' => false, 'message' => 'Denne runde er allerede gemt']);
        exit;
    }
    if ($game['user_id'] !== null && (int)$game['user_id'] !== (int)$userId) {
        echo json_encode(['success' => false, 'message' => 'Dette spil tilhører en anden bruger']);
        exit;
    }

    // Kun point hvis spillet blev vundet - og aldrig under 0, selv hvis hints
    // har kostet mere end gættene gav.
    $score = $game['won'] ? max(0, (int)$game['total_score']) : 0;
    $hints = (int)$game['hints_used'];

    $stmt = $pdo->prepare("
        INSERT INTO leaderboard (id, username, games_played, total_score, avg_score, high_score, hints_used, last_game_at)
        VALUES (:uid, :u, 1, :score, :score, :score, :hints, NOW())
        ON DUPLICATE KEY UPDATE
            username = VALUES(username),
            last_game_at = NOW(),
            games_played = games_played + 1,
            total_score = total_score + VALUES(total_score),
            hints_used = hints_used + VALUES(hints_used),
            avg_score = (total_score + VALUES(total_score)) / (games_played + 1),
            high_score = GREATEST(high_score, VALUES(total_score))
    ");
    $stmt->execute([':uid' => $userId, ':u' => $username, ':score' => $score, ':hints' => $hints]);

    $stmtMark = $pdo->prepare("UPDATE games SET score_saved = 1 WHERE game_id = :id");
    $stmtMark->execute([':id' => $gameId]);

    echo json_encode(['success' => true, 'score' => $score]);
    exit;
}

if ($action === 'user_stats') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['logged_in' => false]);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("
        SELECT games_played, total_score, avg_score, high_score, hints_used
        FROM leaderboard
        WHERE id = :uid
    ");
    $stmt->execute([':uid' => $userId]);
    $stats = $stmt->fetch();

    echo json_encode([
        'logged_in' => true,
        'stats' => [
            'games_played' => (int)($stats['games_played'] ?? 0),
            'total_score'  => (int)($stats['total_score'] ?? 0),
            'avg_score'    => round((float)($stats['avg_score'] ?? 0), 1),
            'high_score'   => (int)($stats['high_score'] ?? 0),
            'hints_used'   => (int)($stats['hints_used'] ?? 0)
        ]
    ]);
    exit;
}

if ($action === 'leaderboard') {
    $stmtHigh = $pdo->query("
        SELECT l.username, l.games_played, l.high_score, l.avg_score, l.total_score
        FROM leaderboard l
        WHERE l.games_played > 0 AND l.last_game_at > NOW() - INTERVAL 2 WEEK
        ORDER BY l.high_score DESC, l.total_score DESC
        LIMIT 100
    ");
    $highScores = $stmtHigh->fetchAll();

    $stmtAvg = $pdo->query("
        SELECT l.username, l.games_played, l.high_score, l.avg_score, l.total_score
        FROM leaderboard l
        WHERE l.games_played > 2 AND l.last_game_at > NOW() - INTERVAL 2 WEEK
        ORDER BY l.avg_score DESC, l.games_played DESC
        LIMIT 100
    ");
    $avgScores = $stmtAvg->fetchAll();

    echo json_encode([
        'high_score' => $highScores,
        'avg_score'  => $avgScores
    ]);
    exit;
}

if ($action === 'get_random_word') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $gameId = $input['game_id'] ?? '';

    $stmt = $pdo->query("SELECT word FROM words WHERE status = 'common' ORDER BY RAND() LIMIT 100");
    $words = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Start-ord uden gentagne bogstaver
    $selectedWord = FALLBACK_WORD;
    foreach ($words as $w) {
        $chars = splitWord(mb_strtoupper(trim($w), 'UTF-8'));
        if (count($chars) === WORD_LENGTH && count(array_unique($chars)) === WORD_LENGTH) {
            $selectedWord = implode('', $chars);
            break;
        }
    }

    // Gratis hint (række 1), men tælles stadig med i hints_used for statistikkens skyld.
    $totalScore = null;
    $game = $gameId !== '' ? loadActiveGame($pdo, $gameId) : null;
    if ($game) {
        $stmtUpdate = $pdo->prepare("UPDATE games SET hints_used = hints_used + 1 WHERE game_id = :id");
        $stmtUpdate->execute([':id' => $gameId]);
        $totalScore = (int)$game['total_score'];
    }

    echo json_encode(['word' => $selectedWord, 'total_score' => $totalScore]);
    exit;
}

if ($action === 'abandon') {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = $input['game_id'] ?? '';

    $stmt = $pdo->prepare("SELECT secret_word FROM games WHERE game_id = :id");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if ($game) {
        $stmtUpdate = $pdo->prepare("UPDATE games SET is_finished = 1 WHERE game_id = :id");
        $stmtUpdate->execute([':id' => $gameId]);

        echo json_encode(['success' => true, 'secret_word' => $game['secret_word']]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// --- HINT: LISTE OVER MULIGE ORD ---
//
// Både forhåndsvisning (count_matching_words) og selve listen
// (get_matching_words) bygger på serverens egen viden om spillet. Klientens
// pattern/absent/present ignoreres.

if ($action === 'get_matching_words' || $action === 'count_matching_words') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $game = loadActiveGame($pdo, $input['game_id'] ?? '');
    if (!$game) {
        echo json_encode(['error' => 'Ugyldigt spil']);
        exit;
    }

    // Kun løsningsord (status = 'common') - samme ord som prisen er beregnet ud fra.
    $constraints = buildConstraintsFromGuesses(mb_strtoupper($game['secret_word'], 'UTF-8'), $game['history']);
    $words = getMatchingWords($pdo, $constraints, true);
    $price = hintPrice(count($words));

    if ($action === 'count_matching_words') {
        // Kun forhåndsvisning - trækker ikke point.
        echo json_encode(['count' => count($words), 'price' => $price]);
        exit;
    }

    $stmtUpdate = $pdo->prepare("UPDATE games SET total_score = total_score - :price, hints_used = hints_used + 1 WHERE game_id = :id");
    $stmtUpdate->execute([':price' => $price, ':id' => $game['game_id']]);

    echo json_encode([
        'words' => $words,
        'price_charged' => $price,
        'total_score' => (int)$game['total_score'] - $price
    ]);
    exit;
}
