<?php
// ord/api.php - API for ordbogen: brugerstyret status for ord (almindelig,
// sjælden, slettet), forslag til nye ord, historik, anmeldelser og admin.
//
// Ordene ligger i hver udgaves database (words.status) - ordets længde
// afgør hvilken. Historik, låse og anmeldelser ligger i den fælles database.
// Alle ændringer går gennem 'edit', så word_edits er en komplet historik.

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/auth.php';

startWordlexSession();
header('Content-Type: application/json; charset=utf-8');

try {
    $shared = connectDb(SHARED_DB);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Databaseforbindelse fejlede']);
    exit;
}

$action = $_GET['action'] ?? '';

handleAuthAction($action, $shared);

$user = currentUser($shared);

function fail(string $message): void {
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function variantDb(int $length): PDO {
    static $connections = [];
    return $connections[$length] ??= connectDb(VARIANT_DBS[$length]);
}

/** Store bogstaver, uden mellemrum. */
function normalizeWord(string $word): string {
    return mb_strtoupper(trim($word), 'UTF-8');
}

/** Ordlængde hvis ordet hører til en udgave og kun består af bogstaver, ellers null. */
function wordVariant(string $word): ?int {
    if (!preg_match('/^[A-ZÆØÅ]+$/u', $word)) {
        return null;
    }
    $length = mb_strlen($word, 'UTF-8');
    return isset(VARIANT_DBS[$length]) ? $length : null;
}

/**
 * Ordets nuværende status, eller null hvis det ikke findes. Sammenligningen
 * er binær, fordi words bruger utf8mb4_danish_ci, hvor "AA" = "Å".
 */
function wordStatus(string $word, int $length): ?string {
    $stmt = variantDb($length)->prepare("SELECT status FROM words WHERE word COLLATE utf8mb4_bin = :w");
    $stmt->execute([':w' => $word]);
    $status = $stmt->fetchColumn();
    return $status === false ? null : $status;
}

function wordLock(PDO $shared, string $word): ?array {
    $stmt = $shared->prepare("
        SELECT l.reason, l.locked_at, u.username AS locked_by
        FROM word_locks l LEFT JOIN users u ON u.id = l.locked_by
        WHERE l.word = :w
    ");
    $stmt->execute([':w' => $word]);
    return $stmt->fetch() ?: null;
}

function finishedGames(int $userId): int {
    $total = 0;
    foreach (array_keys(VARIANT_DBS) as $length) {
        $stmt = variantDb($length)->prepare("SELECT COUNT(*) FROM games WHERE user_id = :u AND is_finished = 1 AND attempts > 0");
        $stmt->execute([':u' => $userId]);
        $total += (int)$stmt->fetchColumn();
    }
    return $total;
}

function editsLastDay(PDO $shared, int $userId): int {
    $stmt = $shared->prepare("SELECT COUNT(*) FROM word_edits WHERE user_id = :u AND created_at > NOW() - INTERVAL 1 DAY");
    $stmt->execute([':u' => $userId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Om brugeren må redigere ord lige nu (låste ord tjekkes separat).
 * @return array{can_edit: bool, reason: ?string}
 */
function editPermission(PDO $shared, ?array $user): array {
    if (!$user) {
        return ['can_edit' => false, 'reason' => 'Log ind for at redigere ord.'];
    }
    if (isAdmin($user)) {
        return ['can_edit' => true, 'reason' => null];
    }
    if ($user['edit_blocked']) {
        return ['can_edit' => false, 'reason' => 'Din konto er blokeret fra at redigere ord.'];
    }
    $games = finishedGames((int)$user['id']);
    if ($games < MIN_GAMES_TO_EDIT) {
        return ['can_edit' => false, 'reason' => 'Du skal have spillet ' . MIN_GAMES_TO_EDIT . " spil som logget ind for at redigere ord (du har spillet $games)."];
    }
    if (editsLastDay($shared, (int)$user['id']) >= MAX_EDITS_PER_DAY) {
        return ['can_edit' => false, 'reason' => 'Du har nået grænsen på ' . MAX_EDITS_PER_DAY . ' ændringer i døgnet.'];
    }
    return ['can_edit' => true, 'reason' => null];
}

function requireAdmin(?array $user): void {
    if (!isAdmin($user)) {
        http_response_code(403);
        fail('Kun for admin.');
    }
}

function cleanText(string $text, int $min, int $max, string $what): string {
    $text = trim($text);
    $length = mb_strlen($text, 'UTF-8');
    if ($length < $min) {
        fail("$what skal være mindst $min tegn.");
    }
    if ($length > $max) {
        fail("$what må højst være $max tegn.");
    }
    return $text;
}

// --- LÆSNING ---

if ($action === 'my_status') {
    $permission = editPermission($shared, $user);
    echo json_encode([
        'logged_in' => $user !== null,
        'username' => $user['username'] ?? null,
        'is_admin' => isAdmin($user),
        'can_edit' => $permission['can_edit'],
        'reason' => $permission['reason'],
        'game_urls' => GAME_URLS,
        'site_url' => SITE_URL,
        'wiki_url' => WIKI_URL,
        'min_comment_length' => MIN_COMMENT_LENGTH,
        'min_games' => MIN_GAMES_TO_EDIT,
    ]);
    exit;
}

if ($action === 'list') {
    // Ord som brugere har ændret eller tilføjet, nyeste ændring først. Den
    // seneste ændring bestemmer ordets status (alle ændringer går gennem 'edit').
    $where = [];
    $params = [];
    if (isset(VARIANT_DBS[(int)($_GET['length'] ?? 0)])) {
        $where[] = 'e.word_length = :len';
        $params[':len'] = (int)$_GET['length'];
    }
    if (in_array($_GET['status'] ?? '', WORD_STATUSES, true)) {
        $where[] = 'e.to_status = :status';
        $params[':status'] = $_GET['status'];
    }
    if (($_GET['user'] ?? '') !== '') {
        $where[] = 'e.word IN (SELECT e2.word FROM word_edits e2 JOIN users u2 ON u2.id = e2.user_id WHERE u2.username = :user)';
        $params[':user'] = $_GET['user'];
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $page = max(0, (int)($_GET['page'] ?? 0));
    $perPage = 50;

    $stmt = $shared->prepare("
        SELECT e.id, e.word, e.word_length, e.from_status, e.to_status, e.comment, e.created_at,
               u.username, g.edit_count, (l.word IS NOT NULL) AS locked
        FROM word_edits e
        JOIN (SELECT MAX(id) AS last_id, COUNT(*) AS edit_count FROM word_edits GROUP BY word) g ON g.last_id = e.id
        LEFT JOIN users u ON u.id = e.user_id
        LEFT JOIN word_locks l ON l.word = e.word
        $whereSql
        ORDER BY e.id DESC
        LIMIT " . ($perPage + 1) . " OFFSET " . ($page * $perPage)
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $perPage;

    $words = array_map(fn($r) => [
        'word' => $r['word'],
        'length' => (int)$r['word_length'],
        'status' => $r['to_status'],
        'edit_count' => (int)$r['edit_count'],
        'locked' => (bool)$r['locked'],
        'last_edit' => [
            'id' => (int)$r['id'],
            'from_status' => $r['from_status'],
            'to_status' => $r['to_status'],
            'comment' => $r['comment'],
            'created_at' => $r['created_at'],
            'username' => $r['username'],
        ],
    ], array_slice($rows, 0, $perPage));

    echo json_encode(['words' => $words, 'page' => $page, 'has_more' => $hasMore]);
    exit;
}

if ($action === 'word') {
    $word = normalizeWord($_GET['word'] ?? '');
    $length = wordVariant($word);
    if ($length === null) {
        fail('Ordbogen har kun ord på ' . implode(' eller ', array_keys(VARIANT_DBS)) . ' bogstaver (A-Å).');
    }

    $stmt = $shared->prepare("
        SELECT e.id, e.from_status, e.to_status, e.comment, e.created_at, u.username
        FROM word_edits e LEFT JOIN users u ON u.id = e.user_id
        WHERE e.word = :w
        ORDER BY e.id DESC
    ");
    $stmt->execute([':w' => $word]);
    $history = $stmt->fetchAll();
    foreach ($history as &$h) {
        $h['id'] = (int)$h['id'];
    }
    unset($h);

    $stmt = variantDb($length)->prepare("SELECT count FROM userwords WHERE word = :w");
    $stmt->execute([':w' => $word]);
    $missingCount = (int)$stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'word' => $word,
        'length' => $length,
        'status' => wordStatus($word, $length),
        'lock' => wordLock($shared, $word),
        'history' => $history,
        'missing_count' => $missingCount,
        'permission' => editPermission($shared, $user),
        'is_admin' => isAdmin($user),
    ]);
    exit;
}

if ($action === 'missing') {
    // Ord spillerne har gættet, men som ikke findes - kandidater til nye ord.
    $result = [];
    foreach (array_keys(VARIANT_DBS) as $length) {
        $rows = variantDb($length)->query("
            SELECT u.word, u.count FROM userwords u
            WHERE NOT EXISTS (SELECT 1 FROM words w WHERE w.word = u.word COLLATE utf8mb4_danish_ci)
            ORDER BY u.count DESC, u.word
            LIMIT 200
        ")->fetchAll();
        $result[$length] = array_slice(array_values(array_filter($rows, fn($r) => wordVariant($r['word']) === $length)), 0, 30);
    }
    echo json_encode(['missing' => $result]);
    exit;
}

// Admin-oversigten er også en læsning (GET)
if ($action === 'admin_overview') {
    requireAdmin($user);

    $reports = $shared->query("
        SELECT r.id, r.edit_id, r.reason, r.status, r.created_at, r.resolution, r.resolved_at,
               rep.username AS reporter, ru.username AS reported_user, ru.edit_blocked AS reported_user_blocked,
               e.word, e.from_status, e.to_status, e.comment AS edit_comment,
               res.username AS resolved_by
        FROM reports r
        LEFT JOIN users rep ON rep.id = r.reporter_id
        LEFT JOIN users ru ON ru.id = r.reported_user_id
        LEFT JOIN word_edits e ON e.id = r.edit_id
        LEFT JOIN users res ON res.id = r.resolved_by
        WHERE r.status = 'open' OR r.resolved_at > NOW() - INTERVAL 30 DAY
        ORDER BY r.status = 'open' DESC, r.id DESC
        LIMIT 200
    ")->fetchAll();

    $locks = $shared->query("
        SELECT l.word, l.reason, l.locked_at, u.username AS locked_by
        FROM word_locks l LEFT JOIN users u ON u.id = l.locked_by
        ORDER BY l.locked_at DESC
    ")->fetchAll();

    $blocked = $shared->query("SELECT username FROM users WHERE edit_blocked = 1 ORDER BY username")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['reports' => $reports, 'locks' => $locks, 'blocked_users' => $blocked]);
    exit;
}

// --- ÆNDRINGER (kræver login) ---

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Ukendt handling.');
}
if (!$user) {
    fail('Du skal være logget ind.');
}
$input = jsonInput();

if ($action === 'edit') {
    $word = normalizeWord($input['word'] ?? '');
    $length = wordVariant($word);
    $newStatus = $input['status'] ?? '';
    if ($length === null) {
        fail('Ordet skal være på ' . implode(' eller ', array_keys(VARIANT_DBS)) . ' bogstaver (A-Å).');
    }
    if (!in_array($newStatus, WORD_STATUSES, true)) {
        fail('Ugyldig status.');
    }
    $comment = cleanText($input['comment'] ?? '', MIN_COMMENT_LENGTH, MAX_COMMENT_LENGTH, 'Kommentaren');

    $permission = editPermission($shared, $user);
    if (!$permission['can_edit']) {
        fail($permission['reason']);
    }
    if (!isAdmin($user) && wordLock($shared, $word)) {
        fail('Ordet er låst af admin og kan ikke redigeres.');
    }

    $oldStatus = wordStatus($word, $length);
    if ($oldStatus === $newStatus) {
        fail('Ordet har allerede den status.');
    }

    $db = variantDb($length);
    try {
        if ($oldStatus === null) {
            // Et ord, der ikke findes, kan også markeres som slettet. Så står det
            // i ordlisten som afvist og forsvinder fra listen over savnede ord.
            $stmt = $db->prepare("INSERT INTO words (word, status) VALUES (:w, :s)");
            $stmt->execute([':w' => $word, ':s' => $newStatus]);
            $stmt = $db->prepare("DELETE FROM userwords WHERE word = :w");
            $stmt->execute([':w' => $word]);
        } else {
            $stmt = $db->prepare("UPDATE words SET status = :s WHERE word COLLATE utf8mb4_bin = :w");
            $stmt->execute([':w' => $word, ':s' => $newStatus]);
        }
    } catch (PDOException $e) {
        // words.word er unik under utf8mb4_danish_ci, hvor fx "AA" og "Å" er ens
        fail('Ordet findes allerede i ordlisten i en anden stavemåde (fx med AA i stedet for Å).');
    }

    $stmt = $shared->prepare("
        INSERT INTO word_edits (word, word_length, user_id, from_status, to_status, comment)
        VALUES (:w, :len, :u, :from, :to, :c)
    ");
    $stmt->execute([
        ':w' => $word, ':len' => $length, ':u' => $user['id'],
        ':from' => $oldStatus, ':to' => $newStatus, ':c' => $comment,
    ]);

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

if ($action === 'report') {
    $reason = cleanText($input['reason'] ?? '', MIN_COMMENT_LENGTH, MAX_COMMENT_LENGTH, 'Begrundelsen');
    $editId = isset($input['edit_id']) ? (int)$input['edit_id'] : null;
    $reportedUserId = null;

    if ($editId !== null) {
        $stmt = $shared->prepare("SELECT user_id FROM word_edits WHERE id = :id");
        $stmt->execute([':id' => $editId]);
        $edit = $stmt->fetch();
        if (!$edit) {
            fail('Ændringen findes ikke.');
        }
        $reportedUserId = $edit['user_id'];
    } elseif (($input['username'] ?? '') !== '') {
        $stmt = $shared->prepare("SELECT id FROM users WHERE username = :u");
        $stmt->execute([':u' => $input['username']]);
        $reportedUserId = $stmt->fetchColumn() ?: null;
        if ($reportedUserId === null) {
            fail('Brugeren findes ikke.');
        }
    } else {
        fail('Angiv en ændring eller en bruger.');
    }

    $stmt = $shared->prepare("
        SELECT COUNT(*) FROM reports
        WHERE reporter_id = :r AND status = 'open' AND edit_id <=> :e AND reported_user_id <=> :u
    ");
    $stmt->execute([':r' => $user['id'], ':e' => $editId, ':u' => $reportedUserId]);
    if ($stmt->fetchColumn() > 0) {
        fail('Du har allerede anmeldt dette.');
    }

    $stmt = $shared->prepare("INSERT INTO reports (reporter_id, edit_id, reported_user_id, reason) VALUES (:r, :e, :u, :reason)");
    $stmt->execute([':r' => $user['id'], ':e' => $editId, ':u' => $reportedUserId, ':reason' => $reason]);

    echo json_encode(['success' => true]);
    exit;
}

// --- ADMIN ---

if ($action === 'admin_resolve') {
    requireAdmin($user);
    $stmt = $shared->prepare("
        UPDATE reports SET status = 'closed', resolved_by = :u, resolved_at = NOW(), resolution = :res
        WHERE id = :id AND status = 'open'
    ");
    $stmt->execute([':u' => $user['id'], ':res' => trim($input['resolution'] ?? ''), ':id' => (int)($input['report_id'] ?? 0)]);
    echo json_encode(['success' => $stmt->rowCount() > 0]);
    exit;
}

if ($action === 'admin_lock') {
    requireAdmin($user);
    $word = normalizeWord($input['word'] ?? '');
    if (wordVariant($word) === null) {
        fail('Ugyldigt ord.');
    }
    if (!empty($input['locked'])) {
        $stmt = $shared->prepare("
            INSERT INTO word_locks (word, locked_by, reason) VALUES (:w, :u, :r)
            ON DUPLICATE KEY UPDATE locked_by = VALUES(locked_by), reason = VALUES(reason), locked_at = NOW()
        ");
        $stmt->execute([':w' => $word, ':u' => $user['id'], ':r' => mb_substr(trim($input['reason'] ?? ''), 0, MAX_COMMENT_LENGTH, 'UTF-8')]);
    } else {
        $stmt = $shared->prepare("DELETE FROM word_locks WHERE word = :w");
        $stmt->execute([':w' => $word]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'admin_block') {
    requireAdmin($user);
    $stmt = $shared->prepare("UPDATE users SET edit_blocked = :b WHERE username = :u AND role <> 'admin'");
    $stmt->execute([':b' => empty($input['blocked']) ? 0 : 1, ':u' => $input['username'] ?? '']);
    echo json_encode(['success' => $stmt->rowCount() > 0]);
    exit;
}

fail('Ukendt handling.');
