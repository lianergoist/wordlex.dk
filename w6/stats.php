<?php
// 1. Databaseforbindelse
$host = 'localhost';
$db   = 'wordlex6_dev';
$user = getenv('WORDLEX_DB_USER');
$pass = getenv('WORDLEX_DB_PASS');
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// 2. Seneste 24 timer (spillere = loggede-ind brugere med mindst ét gæt)
$stmtGames24h = $pdo->query("SELECT COUNT(*) AS total_games FROM games WHERE created_at > NOW() - INTERVAL 1 DAY AND attempts > 0");
$games24h = $stmtGames24h->fetch()['total_games'];

$stmtUsers24h = $pdo->query("SELECT COUNT(DISTINCT user_id) AS total_users FROM games WHERE user_id IS NOT NULL AND attempts > 0 AND created_at > NOW() - INTERVAL 1 DAY");
$users24h = $stmtUsers24h->fetch()['total_users'];

// 3. Seneste 7 dage samlet
$stmtGames7d = $pdo->query("SELECT COUNT(*) AS total_games FROM games WHERE created_at > NOW() - INTERVAL 7 DAY AND attempts > 0");
$games7d = $stmtGames7d->fetch()['total_games'];

$stmtUsers7d = $pdo->query("SELECT COUNT(DISTINCT user_id) AS total_users FROM games WHERE user_id IS NOT NULL AND attempts > 0 AND created_at > NOW() - INTERVAL 7 DAY");
$users7d = $stmtUsers7d->fetch()['total_users'];

// 4. Hent daglig aktivitet for de seneste 7 dage til grafen
// Hent spil pr. dag
$stmtDailyGames = $pdo->query("
    SELECT DATE(created_at) AS game_date, COUNT(*) AS games_count
    FROM games
    WHERE created_at >= CURDATE() - INTERVAL 6 DAY AND attempts > 0
    GROUP BY DATE(created_at)
");
$dailyGamesRaw = $stmtDailyGames->fetchAll(PDO::FETCH_KEY_PAIR);

// Hent aktive spillere pr. dag
$stmtDailyUsers = $pdo->query("
    SELECT DATE(created_at) AS user_date, COUNT(DISTINCT user_id) AS users_count
    FROM games
    WHERE user_id IS NOT NULL AND attempts > 0 AND created_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY DATE(created_at)
");
$dailyUsersRaw = $stmtDailyUsers->fetchAll(PDO::FETCH_KEY_PAIR);

// Byg en komplet dataserie for de seneste 7 dage (inkl. i dag)
$dates = [];
$chartGames = [];
$chartUsers = [];

for ($i = 6; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-$i days"));
    // Formater dato til pænt dansk format (f.eks. "04/08")
    $dates[] = date('d/m', strtotime("-$i days"));
    $chartGames[] = isset($dailyGamesRaw[$dateKey]) ? (int)$dailyGamesRaw[$dateKey] : 0;
    $chartUsers[] = isset($dailyUsersRaw[$dateKey]) ? (int)$dailyUsersRaw[$dateKey] : 0;
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aktivitets-Dashboard - WordleX</title>
    <!-- Chart.js til grafer -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f6f9;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .dashboard-container {
            max-width: 900px;
            width: 100%;
        }

        header {
            margin-bottom: 24px;
        }

        header h1 {
            font-size: 1.8rem;
            color: #1e293b;
        }

        header p {
            color: #64748b;
            font-size: 0.95rem;
        }

        .section-title {
            font-size: 1.2rem;
            color: #334155;
            margin: 24px 0 12px 0;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 6px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }

        .card {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid #e2e8f0;
        }

        .card-header {
            font-size: 0.875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 8px;
        }

        .card-value {
            font-size: 2.25rem;
            font-weight: 700;
            color: #0f172a;
        }

        .card-footer {
            margin-top: 8px;
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .chart-card {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            border: 1px solid #e2e8f0;
            margin-top: 16px;
        }

        .chart-container {
            position: relative;
            height: 320px;
            width: 100%;
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <header>
        <h1>Aktivitets-Dashboard</h1>
        <p>Overblik over aktive spillere og gennemførte spil på WordleX.</p>
    </header>

    <h2 class="section-title">Seneste 24 timer</h2>
    <div class="grid">
        <div class="card">
            <div class="card-header">Antal Spillere</div>
            <div class="card-value"><?= number_format($users24h, 0, ',', '.') ?></div>
            <div class="card-footer">Spillere inden for seneste 24 timer</div>
        </div>
        <div class="card">
            <div class="card-header">Spillede Spil</div>
            <div class="card-value"><?= number_format($games24h, 0, ',', '.') ?></div>
            <div class="card-footer">Gennemførte spil inden for seneste 24 timer</div>
        </div>
    </div>

    <h2 class="section-title">Seneste 7 dage</h2>
    <div class="grid">
        <div class="card">
            <div class="card-header">Antal Spillere (Totalt)</div>
            <div class="card-value"><?= number_format($users7d, 0, ',', '.') ?></div>
            <div class="card-footer">Aktive de seneste 7 dage</div>
        </div>
        <div class="card">
            <div class="card-header">Spillede Spil (Totalt)</div>
            <div class="card-value"><?= number_format($games7d, 0, ',', '.') ?></div>
            <div class="card-footer">Gennemførte spil de seneste 7 dage</div>
        </div>
    </div>

    <!-- Graf over daglig udvikling -->
    <div class="chart-card">
        <div class="card-header" style="margin-bottom: 16px;">Daglig Udvikling (Seneste 7 Dage)</div>
        <div class="chart-container">
            <canvas id="activityChart"></canvas>
        </div>
    </div>
</div>

<script>
    const ctx = document.getElementById('activityChart').getContext('2d');
    
    const labels = <?= json_encode($dates) ?>;
    const gamesData = <?= json_encode($chartGames) ?>;
    const usersData = <?= json_encode($chartUsers) ?>;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Spillede Spil',
                    data: gamesData,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                    pointHoverRadius: 6
                },
                {
                    label: 'Aktive Spillere',
                    data: usersData,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: {
                            family: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto',
                            size: 13
                        },
                        usePointStyle: true,
                        boxWidth: 8
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    },
                    grid: {
                        color: '#f1f5f9'
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
</script>

</body>
</html>
