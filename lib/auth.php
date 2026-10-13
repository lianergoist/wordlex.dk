<?php
// lib/auth.php - fælles brugerkonti (spil og ordbog bruger samme konto).
//
// Brugerne ligger i den fælles database (SHARED_DB). Konti der fandtes i
// både 5- og 6-bogstavsudgaven er slået sammen under 5-udgavens id;
// alt_password_hash er 6-udgavens gamle kodeord, så begge virker.

require_once __DIR__ . '/config.php';

/**
 * Den loggede-ind bruger (id, username, role, edit_blocked), eller null.
 */
function currentUser(PDO $shared): ?array {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    $stmt = $shared->prepare("SELECT id, username, role, edit_blocked FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function isAdmin(?array $user): bool {
    return $user !== null && $user['role'] === 'admin';
}

/**
 * Håndterer register/login/logout/me/delete_account. Afslutter scriptet hvis
 * $action er en af dem. $variant er spillets ordlængde (null i ordbogen) -
 * bruges når kun statistikken for én udgave skal nulstilles.
 */
function handleAuthAction(string $action, PDO $shared, ?int $variant = null): void {
    if ($action === 'register') {
        $input = jsonInput();
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
        $stmt = $shared->prepare("SELECT id FROM users WHERE username = :u");
        $stmt->execute([':u' => $username]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Brugernavnet er optaget.']);
            exit;
        }

        $stmt = $shared->prepare("INSERT INTO users (username, password_hash) VALUES (:u, :p)");
        $stmt->execute([':u' => $username, ':p' => password_hash($password, PASSWORD_BCRYPT)]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$shared->lastInsertId();
        $_SESSION['username'] = $username;

        echo json_encode(['success' => true, 'username' => $username]);
        exit;
    }

    if ($action === 'login') {
        $input = jsonInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';

        $stmt = $shared->prepare("SELECT * FROM users WHERE username = :u");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        $ok = $user && (password_verify($password, $user['password_hash'])
            || ($user['alt_password_hash'] !== null && password_verify($password, $user['alt_password_hash'])));

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
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

    if ($action === 'me') {
        $user = currentUser($shared);
        echo json_encode($user
            ? ['logged_in' => true, 'username' => $user['username'], 'is_admin' => isAdmin($user), 'wiki_url' => WIKI_URL, 'site_url' => SITE_URL]
            : ['logged_in' => false, 'wiki_url' => WIKI_URL, 'site_url' => SITE_URL]);
        exit;
    }

    if ($action === 'delete_account') {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Ikke logget ind']);
            exit;
        }
        $userId = $_SESSION['user_id'];
        $type = jsonInput()['type'] ?? 'stats_only'; // 'stats_only' eller 'full_delete'

        if ($type === 'full_delete') {
            // Statistik i alle udgaver forsvinder. Ændringer i ordbogen bliver
            // stående, men vises som "slettet bruger".
            foreach (VARIANT_DBS as $dbName) {
                $stmt = connectDb($dbName)->prepare("DELETE FROM leaderboard WHERE id = :uid");
                $stmt->execute([':uid' => $userId]);
            }
            $stmt = $shared->prepare("DELETE FROM users WHERE id = :uid");
            $stmt->execute([':uid' => $userId]);

            session_destroy();
            echo json_encode(['success' => true, 'action' => 'deleted_user', 'message' => 'Brugerkonto og statistik er slettet.']);
        } else {
            $variants = $variant !== null ? [$variant] : array_keys(VARIANT_DBS);
            foreach ($variants as $v) {
                $stmt = connectDb(VARIANT_DBS[$v])->prepare("
                    UPDATE leaderboard
                    SET games_played = 0, total_score = 0, avg_score = 0, high_score = 0, hints_used = 0
                    WHERE id = :uid
                ");
                $stmt->execute([':uid' => $userId]);
            }
            echo json_encode(['success' => true, 'action' => 'reset_stats', 'message' => 'Din statistik i databasen er blevet nulstillet.']);
        }
        exit;
    }
}
