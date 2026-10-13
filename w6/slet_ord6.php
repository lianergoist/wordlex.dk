<?php
// Databasekonfiguration
$db_host = 'localhost';
$db_name = 'wordlex6_dev';
$db_user = getenv('WORDLEX_DB_USER');
$db_pass = getenv('WORDLEX_DB_PASS');

$message = '';
$message_type = '';

try {
    // Opret forbindelse til databasen med PDO
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Databaseforbindelse mislykkedes: " . $e->getMessage());
}

// Håndter formularen, når den sendes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $word = isset($_POST['word']) ? trim($_POST['word']) : '';
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // Validering: Tjek om ordet er præcis 6 bogstaver
    if (mb_strlen($word, 'UTF-8') !== 6) {
        $message = "Ordet skal være på præcis 6 bogstaver!";
        $message_type = "error";
    } else {
        if ($action === 'add') {
            // Konverter til store bogstaver (inkl. Æ, Ø, Å)
            $word_upper = mb_strtoupper($word, 'UTF-8');

            // Tjek om ordet allerede findes
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM words WHERE word = :word");
            $stmt->execute([':word' => $word_upper]);
            
            if ($stmt->fetchColumn() > 0) {
                $message = "Ordet '$word_upper' findes allerede i databasen.";
                $message_type = "error";
            } else {
                // Indsæt ordet
                $stmt = $pdo->prepare("INSERT INTO words (word) VALUES (:word)");
                $stmt->execute([':word' => $word_upper]);
                $message = "Ordet '$word_upper' blev tilføjet!";
                $message_type = "success";
            }

        } elseif ($action === 'delete') {
            // Ved sletning søger vi uafhængigt af store/små bogstaver
            $stmt = $pdo->prepare("SELECT word FROM words WHERE word = :word");
            $stmt->execute([':word' => $word]);
            $existing_word = $stmt->fetchColumn();

            if (!$existing_word) {
                $message = "Ordet '$word' blev ikke fundet i databasen.";
                $message_type = "error";
            } else {
                // Slet ordet
                $stmt = $pdo->prepare("DELETE FROM words WHERE word = :word");
                $stmt->execute([':word' => $existing_word]);
                $message = "Ordet '$existing_word' blev slettet fra databasen!";
                $message_type = "success";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wordlex - Administrer ord</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background: #ffffff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        h2 {
            margin-top: 0;
            color: #333;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            font-size: 18px;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            text-align: center;
        }
        .button-group {
            display: flex;
            gap: 10px;
        }
        button {
            flex: 1;
            padding: 12px;
            font-size: 16px;
            font-weight: bold;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
            transition: background 0.2s;
        }
        .btn-add {
            background-color: #28a745;
        }
        .btn-add:hover {
            background-color: #218838;
        }
        .btn-delete {
            background-color: #dc3545;
        }
        .btn-delete:hover {
            background-color: #c82333;
        }
        .message {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
        }
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Wordlex Ord-styring</h2>

    <?php if (!empty($message)): ?>
        <div class="message <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <form action="" method="POST">
        <div class="form-group">
            <label for="word">Indtast ord (6 bogstaver):</label>
            <input type="text" id="word" name="word" maxlength="6" required autocomplete="off" autofocus>
        </div>
        <div class="button-group">
            <button type="submit" name="action" value="add" class="btn-add">Tilføj</button>
            <button type="submit" name="action" value="delete" class="btn-delete">Slet</button>
        </div>
    </form>
</div>

</body>
</html>
