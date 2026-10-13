<?php
// api6.php - Version 2.0 (6-bogstaver / 7-rækker)
//
// KRÆVER DATABASE-MIGRATION FØR DENNE VERSION KØRER (samme migration som
// 5-bogstavs-udgaven, blot på wordlex6-databasen):
//   ALTER TABLE games ADD COLUMN guess_history TEXT NULL;
//   ALTER TABLE games ADD COLUMN total_score INT NOT NULL DEFAULT 0;
//   ALTER TABLE games ADD COLUMN won TINYINT(1) NOT NULL DEFAULT 0;
//   ALTER TABLE games ADD COLUMN hints_used INT NOT NULL DEFAULT 0;
//   ALTER TABLE games ADD COLUMN score_saved TINYINT(1) NOT NULL DEFAULT 0;
//   ALTER TABLE games ADD COLUMN user_id INT NULL;
//
// guess_history bruges til at genopbygge kandidat-ord-puljen (entropi-scoring).
// total_score/won/hints_used/score_saved/user_id gør 'games' til den ENESTE
// autoritative kilde til point - save_score stoler ikke på klienten.

$lifetime = 30 * 24 * 60 * 60;

session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

ini_set('session.gc_maxlifetime', $lifetime);

session_start();
header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';
$db   = 'wordlex6_dev';
$user = getenv('WORDLEX_DB_USER');
$pass = getenv('WORDLEX_DB_PASS');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Databaseforbindelse fejlede']);
    exit;
}

$action = $_GET['action'] ?? '';

// --- POINT-KONFIGURATION (entropi-baseret scoring - samme princip som 5-bogstavs-udgaven) ---
//
// Point for et gæt = (bits information vundet ved dette gæt) * POINTS_PER_BIT.
// "Bits information" = log2(antal mulige hemmelige ord FØR gættet / antal mulige
// hemmelige ord EFTER gættet), hvor "mulige ord" er alle words.is_common=1 ord
// (6 bogstaver) der stadig er forenelige med alt hvad spilleren ved fra sine gæt
// indtil videre. Se api.php (5-bogstavs-udgaven) for den fulde matematiske
// begrundelse - samme princip, blot tilpasset 6 bogstaver / 7 rækker.
const POINTS_PER_BIT = 40;
const EFFICIENCY_BONUS_PER_UNUSED_ATTEMPT = 15; // Lille, adskilt bonus pr. ubrugt forsøg (op til 6), kun ved sejr.

// --- HINT-PRISSÆTNING (flyttet server-side, så $score kan valideres fuldt ud) ---
//
// Uændret formel ift. den oprindelige klient-side udgave (ROW_VALUES i index.html):
// prisen kombinerer rækkens "grønne værdi" (falder med rækken) med hvor afslørende
// ordlisten er lige nu (falder når flere ord matcher).
const HINT_ROW_BASE_VALUES = [200, 160, 120, 90, 60, 40, 25];
const HINT_PRICE_FLOOR = 15;

function hintMatchFactor(?int $matchCount): float {
    if ($matchCount === null || $matchCount < 1) {
        return 2.5;
    }
    $factor = 2.0 / sqrt($matchCount);
    return min(2.5, max(0.25, $factor));
}

function hintPrice(int $row, ?int $matchCount): int {
    $rowValues = HINT_ROW_BASE_VALUES;
    $greenValue = $rowValues[$row] ?? end($rowValues);
    return max(HINT_PRICE_FLOOR, (int)round($greenValue * hintMatchFactor($matchCount)));
}

/**
 * Beregner Wordle-feedback (correct/present/absent) for et 6-bogstavs gæt mod
 * et hemmeligt ord. Udtrukket til egen funktion så både selve gættet og
 * entropi-beregningen bruger nøjagtig samme logik (inkl. dobbelt-bogstaver).
 */
function computeFeedback(string $secret, string $guess): array {
    $secretArr = preg_split('//u', $secret, -1, PREG_SPLIT_NO_EMPTY);
    $guessArr = preg_split('//u', $guess, -1, PREG_SPLIT_NO_EMPTY);
    $result = array_fill(0, 6, 'absent');
    $letterCounts = array_count_values($secretArr);

    for ($i = 0; $i < 6; $i++) {
        if ($guessArr[$i] === $secretArr[$i]) {
            $result[$i] = 'correct';
            $letterCounts[$guessArr[$i]]--;
        }
    }
    for ($i = 0; $i < 6; $i++) {
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
 * Genopbygger de kumulative constraints (pattern/absent/present/not_at_pos) ud
 * fra en liste af tidligere gæt mod det kendte hemmelige ord. Bruges til at
 * tælle hvor mange kandidatord der stadig er mulige FØR og EFTER et givent gæt.
 * Formatet matcher det getMatchingWords() (længere nede) allerede forventer.
 */
function buildConstraintsFromGuesses(string $secret, array $guesses): array {
    $pattern = ['_', '_', '_', '_', '_', '_'];
    $absent = [];
    $present = [];
    $notAtPos = [[], [], [], [], [], []];

    foreach ($guesses as $g) {
        $feedback = computeFeedback($secret, $g);
        $letters = preg_split('//u', $g, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($letters as $i => $ch) {
            if ($feedback[$i] === 'correct') {
                $pattern[$i] = $ch;
            } elseif ($feedback[$i] === 'present') {
                $present[] = $ch;
                $notAtPos[$i][] = $ch;
            } else {
                $absent[] = $ch;
            }
        }
    }

    return [
        'pattern'     => implode('', $pattern),
        'absent'      => array_values(array_unique($absent)),
        'present'     => array_values(array_unique($present)),
        'not_at_pos'  => $notAtPos,
    ];
}

/**
 * Tæller hvor mange ord fra den faktiske "hemmeligt-ord-pulje" (is_common = 1,
 * samme pulje som 'start' trækker fra) der stadig matcher et givent sæt
 * constraints. Genbruger getMatchingWords() (defineret længere nede) med
 * $onlyCommon=true, så entropi-beregningen bruger nøjagtig samme
 * kollations-sikre bogstav-for-bogstav-filtrering som hint-listen.
 */
function countCandidateWords(PDO $pdo, array $constraints): int {
    return count(getMatchingWords($pdo, $constraints, true));
}

// --- BRUGER HÅNDTERING ---

if ($action === 'register') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (mb_strlen($username, 'UTF-8') < 3 || mb_strlen($password, 'UTF-8') < 4 || mb_strlen($username, 'UTF-8') > 20) {
        echo json_encode(['success' => false, 'message' => 'Brugernavn skal være min. 3 tegn og kodeord min. 4 tegn.']);
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9æøåÆØÅ_\-]+$/u', $username)) {
        echo json_encode(['success' => false, 'message' => 'Brugernavnet indeholder ugyldige tegn. Brug kun bogstaver, tal, _ eller - .']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Brugernavnet er optaget.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :p)");
    $stmt->execute([':u' => $username, ':p' => $hash]);
    
    $userId = $pdo->lastInsertId();
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;

    $stmt = $pdo->prepare("INSERT INTO leaderboard (id, username) VALUES (:id, :u)");
    $stmt->execute([':id' => $userId, ':u' => $username]);

    echo json_encode(['success' => true, 'username' => $username]);
    exit;
}

if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        echo json_encode(['success' => true, 'username' => $user['username']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Forkert brugernavn eller kodeord.']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_account') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Ikke logget ind']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? 'stats_only';
    $userId = $_SESSION['user_id'];

    if ($type === 'full_delete') {
        $stmt1 = $pdo->prepare("DELETE FROM leaderboard WHERE id = :uid");
        $stmt1->execute([':uid' => $userId]);

        $stmt2 = $pdo->prepare("DELETE FROM users WHERE id = :uid");
        $stmt2->execute([':uid' => $userId]);

        session_destroy();
        echo json_encode(['success' => true, 'action' => 'deleted_user', 'message' => 'Brugerkonto og statistik er slettet.']);
    } else {
        $stmt = $pdo->prepare("
            UPDATE leaderboard 
            SET games_played = 0, total_score = 0, avg_score = 0, high_score = 0, hints_used = 0 
            WHERE id = :uid
        ");
        $stmt->execute([':uid' => $userId]);

        echo json_encode(['success' => true, 'action' => 'reset_stats', 'message' => 'Din statistik i databasen er blevet nulstillet.']);
    }
    exit;
}

if ($action === 'me') {
    if (isset($_SESSION['user_id'])) {
        echo json_encode(['logged_in' => true, 'username' => $_SESSION['username']]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

// --- SPIL HÅNDTERING ---

function logMissingWord(PDO $pdo, string $word): bool {
	$cleanWord = mb_strtoupper(trim($word), 'UTF-8');

	if (empty($cleanWord) || mb_strlen($cleanWord, 'UTF-8') !== 6) {
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
    $stmt = $pdo->query("SELECT word FROM words WHERE is_common = 1 ORDER BY RAND() LIMIT 1");
    $row = $stmt->fetch();
    $secret = $row ? $row['word'] : 'FLASKE';

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

    if (isset($_SESSION['user_id'])) {
        $stmtUser = $pdo->prepare("UPDATE users SET last_game_at = NOW() WHERE id = :uid");
        $stmtUser->execute([':uid' => $_SESSION['user_id']]);
    }

    echo json_encode(['game_id' => $gameId]);
    exit;
}

if ($action === 'guess') {
    $input = json_decode(file_get_contents('php://input'), true);
    $gameId = $input['game_id'] ?? '';
    $guess = mb_strtoupper(trim($input['guess'] ?? ''), 'UTF-8');

    if (mb_strlen($guess, 'UTF-8') !== 6) {
        echo json_encode(['valid' => false, 'message' => 'Ordet skal være på 6 bogstaver']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM games WHERE game_id = :id AND is_finished = 0");
    $stmt->execute([':id' => $gameId]);
    $game = $stmt->fetch();

    if (!$game) {
        echo json_encode(['error' => 'Ugyldigt spil']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM words WHERE word = :word");
    $stmt->execute([':word' => $guess]);
    if ($stmt->fetchColumn() == 0) {
        logMissingWord($pdo, $guess);
        echo json_encode(['valid' => false, 'message' => 'Ordet findes ikke i ordlisten']);
        exit;
    }

	$secret = $game['secret_word'];
    $result = computeFeedback($secret, $guess);

    // Tidligere gæt i dette spil (bruges til at genopbygge kandidat-puljen)
    $guessHistory = json_decode($game['guess_history'] ?? '', true);
    if (!is_array($guessHistory)) {
        $guessHistory = [];
    }

    // Kandidatord der var mulige FØR dette gæt (kun ud fra tidligere gæt)
    $constraintsBefore = buildConstraintsFromGuesses($secret, $guessHistory);
    $candidatesBefore = countCandidateWords($pdo, $constraintsBefore);

    $guessHistory[] = $guess;

    // Kandidatord der er mulige EFTER dette gæt (tidligere gæt + dette)
    $constraintsAfter = buildConstraintsFromGuesses($secret, $guessHistory);
    $candidatesAfter = countCandidateWords($pdo, $constraintsAfter);

    // Sikkerhedsnet mod division/log af 0 eller inkonsistente tællinger
    $candidatesAfter = max(1, $candidatesAfter);
    $candidatesBefore = max($candidatesBefore, $candidatesAfter);

    $informationBits = ($candidatesBefore > $candidatesAfter)
        ? log($candidatesBefore / $candidatesAfter, 2)
        : 0.0;
    $informationScore = (int)round($informationBits * POINTS_PER_BIT);

    $attempts = $game['attempts'] + 1;
    $isCorrect = ($guess === $secret);
    // Maksimalt 7 forsøg i 6-bogstavs-versionen
    $isFinished = ($isCorrect || $attempts >= 7) ? 1 : 0;

    // Lille, adskilt bonus for ubrugte forsøg - kun ved sejr
    $efficiencyBonus = 0;
    if ($isCorrect) {
        $unusedAttempts = 7 - $attempts;
        $efficiencyBonus = $unusedAttempts * EFFICIENCY_BONUS_PER_UNUSED_ATTEMPT;
    }

    $guessScore = $informationScore + $efficiencyBonus;
    $runningTotalScore = (int)($game['total_score'] ?? 0) + $guessScore;

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
        ':score' => $guessScore,
        ':won'   => $isCorrect ? 1 : 0,
        ':id'    => $gameId
    ]);

    echo json_encode([
        'valid' => true,
        'result' => $result,
        'is_correct' => $isCorrect,
        'is_finished' => (bool)$isFinished,
        'secret_word' => $isFinished ? $secret : null,
        'guess_score' => $guessScore,
        'total_score' => $runningTotalScore,
        'information_score' => $informationScore,
        'efficiency_bonus' => $efficiencyBonus,
        'information_bits' => round($informationBits, 2),
        'candidates_before' => $candidatesBefore,
        'candidates_after' => $candidatesAfter
    ]);
    exit;
}

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
    // games-tabellen, som kun api6.php selv har skrevet til (i 'guess'/
    // 'get_matching_words'/'get_random_word'). Umuligt for en klient at poste
    // en vilkårlig score.
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
        // Allerede gemt engang - forhindrer at samme game_id genindsendes for at score point flere gange.
        echo json_encode(['success' => false, 'message' => 'Denne runde er allerede gemt']);
        exit;
    }
    if ($game['user_id'] !== null && (int)$game['user_id'] !== (int)$userId) {
        echo json_encode(['success' => false, 'message' => 'Dette spil tilhører en anden bruger']);
        exit;
    }

    // Kun point hvis spillet reelt blev vundet (samme regel som før, nu håndhævet server-side)
    $score = $game['won'] ? (int)$game['total_score'] : 0;
    $hints = (int)$game['hints_used'];

    $stmt = $pdo->prepare("
        INSERT INTO leaderboard (id, username, games_played, total_score, avg_score, high_score, hints_used)
        VALUES (:uid, :u, 1, :score, :score, :score, :hints)
        ON DUPLICATE KEY UPDATE 
            username = VALUES(username),
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

    if ($stats) {
        echo json_encode([
            'logged_in' => true,
            'stats' => [
                'games_played' => (int)$stats['games_played'],
                'total_score' => (int)$stats['total_score'],
                'avg_score'   => round((float)$stats['avg_score'], 1),
                'high_score'  => (int)$stats['high_score'],
                'hints_used'  => (int)$stats['hints_used']
            ]
        ]);
    } else {
        echo json_encode([
            'logged_in' => true,
            'stats' => [
                'games_played' => 0,
                'total_score' => 0,
                'avg_score'   => 0,
                'high_score'  => 0,
                'hints_used'  => 0
            ]
        ]);
    }
    exit;
}

if ($action === 'leaderboard') {
    $stmtHigh = $pdo->query("
        SELECT u.username, l.games_played, l.high_score, l.avg_score, l.total_score 
        FROM leaderboard l 
        JOIN users u ON l.id = u.id 
        WHERE l.games_played > 0 AND u.last_game_at > NOW() - INTERVAL 2 WEEK
        ORDER BY l.high_score DESC, l.total_score DESC 
        LIMIT 100
    ");
    $highScores = $stmtHigh->fetchAll();

    $stmtAvg = $pdo->query("
        SELECT u.username, l.games_played, l.high_score, l.avg_score, l.total_score 
        FROM leaderboard l 
        JOIN users u ON l.id = u.id 
        WHERE l.games_played > 2 AND u.last_game_at > NOW() - INTERVAL 2 WEEK
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

    $stmt = $pdo->query("SELECT word FROM words WHERE is_common = 1 ORDER BY RAND() LIMIT 100");
    $words = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $selectedWord = null;

    foreach ($words as $w) {
        $wUpper = mb_strtoupper($w, 'UTF-8');
        $chars = preg_split('//u', $wUpper, -1, PREG_SPLIT_NO_EMPTY);
        
        if (count($chars) === 6 && count(array_unique($chars)) === 6) {
            $selectedWord = $wUpper;
            break;
        }
    }

    if (!$selectedWord) {
        $selectedWord = 'FLASKE';
    }

    // Gratis hint (Række 1), men tælles stadig med i hints_used for statistikkens skyld.
    $totalScore = null;
    if ($gameId !== '') {
        $stmt = $pdo->prepare("SELECT total_score FROM games WHERE game_id = :id AND is_finished = 0");
        $stmt->execute([':id' => $gameId]);
        $game = $stmt->fetch();
        if ($game) {
            $stmtUpdate = $pdo->prepare("UPDATE games SET hints_used = hints_used + 1 WHERE game_id = :id");
            $stmtUpdate->execute([':id' => $gameId]);
            $totalScore = (int)$game['total_score'];
        }
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

/**
 * Henter og filtrerer ord til brug for get_matching_words (selve listen) og
 * count_matching_words (kun antallet, brugt til at prissætte hintet).
 *
 * OBS: Filtreringen sker bevidst i PHP med mb_-funktioner og IKKE via
 * CHAR_LENGTH()/LIKE i SQL. CHAR_LENGTH() og LIKE afhænger af "words"-
 * tabellens kolonne-collation - er den ikke konsekvent utf8mb4, kan ord med
 * Æ/Ø/Å (fx "HØLOFT") blive talt til en forkert længde eller matchet forkert
 * og forsvinde stille fra resultatet, selvom de reelt opfylder alle
 * betingelser. mb_-funktionerne arbejder derimod direkte på UTF-8-strengen,
 * som PDO allerede har afkodet korrekt via forbindelsens charset, og er
 * derfor uafhængige af kolonnens collation.
 *
 * @param bool $onlyCommon Når true, begrænses kandidatpuljen til is_common = 1
 *        (den faktiske hemmeligt-ord-pulje - bruges af entropi-scoringen via
 *        countCandidateWords()). Når false (standard), bruges hele ordlisten,
 *        som hint-listen altid har vist.
 * @return string[] Matchende ord (store bogstaver, trimmet, unikke)
 */
function getMatchingWords(PDO $pdo, array $input, bool $onlyCommon = false): array {
    $pattern = $input['pattern'] ?? '______';
    $absent = array_map(fn($c) => mb_strtoupper($c, 'UTF-8'), $input['absent'] ?? []);
    $present = array_map(fn($c) => mb_strtoupper($c, 'UTF-8'), $input['present'] ?? []);
    $notAtPosInput = $input['not_at_pos'] ?? [[], [], [], [], [], []];

    $patternChars = [];
    for ($i = 0; $i < 6; $i++) {
        $c = mb_substr($pattern, $i, 1, 'UTF-8');
        $patternChars[$i] = ($c !== '' && $c !== '_') ? mb_strtoupper($c, 'UTF-8') : null;
    }

    $notAtPos = [];
    for ($i = 0; $i < 6; $i++) {
        $notAtPos[$i] = array_map(fn($c) => mb_strtoupper($c, 'UTF-8'), $notAtPosInput[$i] ?? []);
    }

    $knownChars = array_unique(array_merge($present, array_values(array_filter($patternChars))));
    $strictlyAbsent = array_diff($absent, $knownChars);

    // Grov, byte-baseret forudfiltrering i SQL (sikker: selv Æ/Ø/Å fylder
    // højst 4 byte pr. tegn i utf8mb4). Den endelige afgørelse om match
    // sker udelukkende i PHP herunder, så kolonnens collation er uden
    // betydning for resultatet.
    $sql = "SELECT DISTINCT TRIM(word) AS word FROM words WHERE LENGTH(TRIM(word)) BETWEEN 6 AND 24";
    if ($onlyCommon) {
        $sql .= " AND is_common = 1";
    }
    $stmt = $pdo->query($sql);
    $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $matches = [];
    foreach ($candidates as $word) {
        $word = trim($word);
        if (mb_strlen($word, 'UTF-8') !== 6) {
            continue;
        }

        $wordUpper = mb_strtoupper($word, 'UTF-8');
        $letters = mb_str_split($wordUpper, 1, 'UTF-8');

        $ok = true;
        for ($i = 0; $i < 6; $i++) {
            if ($patternChars[$i] !== null && $letters[$i] !== $patternChars[$i]) {
                $ok = false;
                break;
            }
            if (!empty($notAtPos[$i]) && in_array($letters[$i], $notAtPos[$i], true)) {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            continue;
        }

        foreach ($strictlyAbsent as $aChar) {
            if (in_array($aChar, $letters, true)) {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            continue;
        }

        foreach ($present as $pChar) {
            if (!in_array($pChar, $letters, true)) {
                $ok = false;
                break;
            }
        }
        if (!$ok) {
            continue;
        }

        $matches[] = $wordUpper;
    }

    return array_values(array_unique($matches));
}

if ($action === 'get_matching_words') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $gameId = $input['game_id'] ?? '';
    $words = getMatchingWords($pdo, $input);

    // Opkræv hint-prisen HER (når listen faktisk udleveres) - server-side, så den
    // ikke kan omgås. count_matching_words nedenfor viser kun en forhåndsvisning
    // af prisen og trækker intet.
    $totalScore = null;
    $priceCharged = null;

    if ($gameId !== '') {
        $stmt = $pdo->prepare("SELECT attempts, total_score FROM games WHERE game_id = :id AND is_finished = 0");
        $stmt->execute([':id' => $gameId]);
        $game = $stmt->fetch();
        if ($game) {
            $row = (int)$game['attempts'];
            $priceCharged = hintPrice($row, count($words));
            $totalScore = (int)$game['total_score'] - $priceCharged;

            $stmtUpdate = $pdo->prepare("UPDATE games SET total_score = total_score - :price, hints_used = hints_used + 1 WHERE game_id = :id");
            $stmtUpdate->execute([':price' => $priceCharged, ':id' => $gameId]);
        }
    }

    echo json_encode([
        'words' => $words,
        'price_charged' => $priceCharged,
        'total_score' => $totalScore
    ]);
    exit;
}

if ($action === 'count_matching_words') {
    // Bruges til at FORHÅNDSVISE hint-prisen inden brugeren bekræfter - trækker
    // ikke point (det sker i get_matching_words). Samme filtrering som get_matching_words.
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $gameId = $input['game_id'] ?? '';
    $words = getMatchingWords($pdo, $input);
    $count = count($words);

    $row = 0;
    if ($gameId !== '') {
        $stmt = $pdo->prepare("SELECT attempts FROM games WHERE game_id = :id");
        $stmt->execute([':id' => $gameId]);
        $game = $stmt->fetch();
        if ($game) {
            $row = (int)$game['attempts'];
        }
    }

    echo json_encode(['count' => $count, 'price' => hintPrice($row, $count)]);
    exit;
}
