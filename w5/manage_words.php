<?php
// --- DATABASE KONFIGURATION ---
$db_host = 'localhost';
$db_name = 'wordlex_dev';
$db_user = getenv('WORDLEX_DB_USER');
$db_pass = getenv('WORDLEX_DB_PASS');

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Databasefejl: " . $e->getMessage());
}

// --- AJAX API ENDPOINTS ---
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // 1. Hent næste ord og samlet antal tilbage
    if ($_GET['action'] === 'get_next') {
        // Tæl hvor mange ord der har count > 1
        $stmtCount = $pdo->query("SELECT COUNT(*) AS total FROM userwords WHERE count > 1");
        $total = $stmtCount->fetch()['total'];

        // Hent det første ord med count > 1
        $stmt = $pdo->query("SELECT word, count FROM userwords WHERE count > 1 ORDER BY count DESC LIMIT 1");
        $row = $stmt->fetch();

        echo json_encode([
            'word' => $row ? $row['word'] : null,
            'remaining' => (int)$total
        ]);
        exit;
    }

    // 2. Håndter valg (Flyt til words eller Slet)
    if ($_GET['action'] === 'process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $word = trim($data['word'] ?? '');
        $choice = $data['choice'] ?? ''; // 'common', 'rare', 'delete'

        if (!$word) {
            echo json_encode(['success' => false, 'error' => 'Intet ord angivet']);
            exit;
        }

        try {
            // Brug transaktion så indsæt + slet sker samlet
            $pdo->beginTransaction();

            if ($choice === 'common' || $choice === 'rare') {
                $is_common = ($choice === 'common') ? 1 : 0;

                $stmtInsert = $pdo->prepare("
                    INSERT INTO words (word, is_common) 
                    VALUES (:word, :is_common)
                    ON DUPLICATE KEY UPDATE is_common = :is_common
                ");
                $stmtInsert->execute([
                    'word' => $word,
                    'is_common' => $is_common
                ]);
                $stmtDelete = $pdo->prepare("DELETE FROM userwords WHERE word = :word");
				$stmtDelete->execute(['word' => $word]);
            } else {
				$stmtUpdate = $pdo->prepare("UPDATE userwords SET count = 0 WHERE word = :word");
				$stmtUpdate->execute(['word' => $word]);
			}

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WordleX - Moderer Ord</title>
    <style>
        * { box-sizing: border-box; font-family: system-ui, -apple-system, sans-serif; }
        body {
            background-color: #121213;
            color: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .card {
            background: #1a1a1b;
            border: 1px solid #3a3a3c;
            border-radius: 12px;
            padding: 2rem;
            width: 100%;
            max-width: 480px;
            text-align: center;
            box-shadow: 0 8px 24px rgba(0,0,0,0.5);
        }
        .badge {
            font-size: 0.85rem;
            color: #818384;
            margin-bottom: 1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .word-display {
            font-size: 2.5rem;
            font-weight: bold;
            letter-spacing: 4px;
            margin: 1.5rem 0;
            color: #ffffff;
            text-transform: uppercase;
            min-height: 60px;
        }
        .actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 1.5rem;
        }
        .btn {
            padding: 12px 16px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            font-size: 0.95rem;
            cursor: pointer;
            transition: opacity 0.15s ease, transform 0.1s ease;
        }
        .btn:active { transform: scale(0.98); }
        .btn-common { background-color: #538d4e; color: white; grid-column: span 1; }
        .btn-rare { background-color: #b59f3b; color: white; grid-column: span 1; }
        .btn-delete { background-color: #3a3a3c; color: #f8f8f8; grid-column: span 2; margin-top: 5px; }
        .btn-delete:hover { background-color: #a12c2c; }
        
        .shortcuts {
            margin-top: 1.5rem;
            font-size: 0.75rem;
            color: #818384;
            border-top: 1px solid #272729;
            padding-top: 1rem;
        }
        kbd {
            background: #272729;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #3a3a3c;
        }
        .empty-state {
            color: #818384;
            font-size: 1.2rem;
            padding: 2rem 0;
        }
    </style>
</head>
<body>

<div class="card">
    <div class="badge" id="counter">Henter ord...</div>
    
    <div id="word-container">
        <div class="word-display" id="current-word">-</div>
        
        <div class="actions">
            <button class="btn btn-common" onclick="processWord('common')">Almindeligt (1)</button>
            <button class="btn btn-rare" onclick="processWord('rare')">Sjældent (0)</button>
            <button class="btn btn-delete" onclick="processWord('delete')">Slet ord</button>
        </div>

        <div class="shortcuts">
            Tastatur-genveje: <kbd>←</kbd> Almindeligt | <kbd>→</kbd> Sjældent | <kbd>Slet</kbd> / <kbd>Backspace</kbd> Slet
        </div>
    </div>
</div>

<script>
let currentWord = null;

async function loadNextWord() {
    try {
        const res = await fetch('?action=get_next');
        const data = await res.json();
        
        const counterEl = document.getElementById('counter');
        const wordEl = document.getElementById('current-word');
        
        if (!data.word) {
            document.getElementById('word-container').innerHTML = `
                <div class="empty-state">
                    🎉 Der er ikke flere ord at gennemgå!
                </div>`;
            counterEl.innerText = "0 ORD TILBAGE";
            return;
        }

        currentWord = data.word;
        wordEl.innerText = data.word;
        counterEl.innerText = `${data.remaining} ORD TILBAGE I USERWORDS`;
    } catch (err) {
        console.error("Fejl ved hentning af ord:", err);
    }
}

async function processWord(choice) {
    if (!currentWord) return;

    const wordToProcess = currentWord;
    currentWord = null;

    try {
        const res = await fetch('?action=process', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ word: wordToProcess, choice: choice })
        });
        
        const result = await res.json();
        if (!result.success) {
            alert('Fejl fra server: ' + result.error);
            return;
        }
        
        loadNextWord();
    } catch (err) {
        console.error("Fejl ved gemning:", err);
    }
}

document.addEventListener('keydown', (e) => {
    if (!currentWord) return;
    
    if (e.key === 'ArrowLeft') {
        processWord('common');
    } else if (e.key === 'ArrowRight') {
        processWord('rare');
    } else if (e.key === 'Backspace' || e.key === 'Delete') {
        processWord('delete');
    }
});

loadNextWord();
</script>

</body>
</html>
