<?php
// lib/config.php - fælles konfiguration for spil, ordbog og admin.
//
// Miljøet afgøres af hvor koden ligger: dev-kopien ligger under
// /var/www/rpix.dk/, den rigtige side under /var/www/wordlex.dk/.

if (strpos(__DIR__, '/var/www/rpix.dk/') === 0) {
    define('WORDLEX_ENV', 'dev');
    define('SHARED_DB', 'wordlex_dev');
    define('VARIANT_DBS', [5 => 'wordlex5_dev', 6 => 'wordlex6_dev']);
    define('COOKIE_DOMAIN', '');           // samme host for spil og ordbog
    define('SITE_URL', 'https://rpix.dk/wordlex.dk/');
    define('WIKI_URL', SITE_URL . 'ord/');
    define('GAME_URLS', [5 => SITE_URL . 'w5/', 6 => SITE_URL . 'w6/']);
} else {
    define('WORDLEX_ENV', 'live');
    define('SHARED_DB', 'wordlex');
    define('VARIANT_DBS', [5 => 'wordlex5', 6 => 'wordlex6']);
    define('COOKIE_DOMAIN', '.wordlex.dk'); // login deles mellem w5., w6. og wordlex.dk
    define('SITE_URL', 'https://wordlex.dk/');
    define('WIKI_URL', SITE_URL . 'ord/');
    define('GAME_URLS', [5 => 'https://w5.wordlex.dk/', 6 => 'https://w6.wordlex.dk/']);
}

// Antal forsøg pr. ordlængde
const VARIANT_ROWS = [5 => 6, 6 => 7];

// Bruges hvis ordlisten mod forventning er tom
const VARIANT_FALLBACK_WORDS = [5 => 'TORSK', 6 => 'FLASKE'];

// --- VÆRN MOD HÆRVÆRK I ORDBOGEN (gælder ikke admin) ---
const MIN_GAMES_TO_EDIT = 10;   // afsluttede spil (begge udgaver tilsammen)
const MAX_EDITS_PER_DAY = 20;   // ændringer + nye ord pr. døgn
const MIN_COMMENT_LENGTH = 5;
const MAX_COMMENT_LENGTH = 500;

const WORD_STATUSES = ['common', 'rare', 'deleted'];

function connectDb(string $dbName): PDO {
    return new PDO("mysql:host=localhost;dbname=$dbName;charset=utf8mb4", getenv('WORDLEX_DB_USER'), getenv('WORDLEX_DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}

function startWordlexSession(): void {
    $lifetime = 30 * 24 * 60 * 60; // 30 dage

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'domain'   => COOKIE_DOMAIN,
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    ini_set('session.gc_maxlifetime', $lifetime);
    session_name('WORDLEXSESSID');
    session_start();
}

function jsonInput(): array {
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : [];
}
