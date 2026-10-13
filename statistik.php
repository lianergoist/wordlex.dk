<?php
// statistik.php - offentlig aktivitetsstatistik for begge udgaver af WordleX.
// Erstatter de tidligere w5/stats.php og w6/stats.php (som nu sender hertil).
//
// "Spil" = spil med mindst ét gæt. "Aktive spillere" = loggede-ind brugere med
// mindst ét gæt; kontiene er fælles, så en spiller tælles én gang, selv om
// vedkommende har spillet begge udgaver.

require_once __DIR__ . '/lib/config.php';

const DAYS = 14;

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function n(int $value): string {
    return number_format($value, 0, ',', '.');
}

$error = false;
$games = [];          // [længde][dato] => antal spil
$players = [];        // [dato] => [user_id => true], på tværs af udgaver
$totals = ['24h' => ['games' => [], 'players' => []], '7d' => ['games' => [], 'players' => []]];
$edits7d = 0;

try {
    foreach (VARIANT_DBS as $length => $dbName) {
        $pdo = connectDb($dbName);

        $stmt = $pdo->query("
            SELECT DATE(created_at) AS day, COUNT(*) AS n
            FROM games
            WHERE attempts > 0 AND created_at >= CURDATE() - INTERVAL " . (DAYS - 1) . " DAY
            GROUP BY DATE(created_at)
        ");
        $games[$length] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmt = $pdo->query("
            SELECT DISTINCT DATE(created_at) AS day, user_id
            FROM games
            WHERE attempts > 0 AND user_id IS NOT NULL AND created_at >= CURDATE() - INTERVAL " . (DAYS - 1) . " DAY
        ");
        foreach ($stmt as $row) {
            $players[$row['day']][$row['user_id']] = true;
        }

        foreach (['24h' => '1 DAY', '7d' => '7 DAY'] as $period => $interval) {
            $totals[$period]['games'][$length] = (int)$pdo->query(
                "SELECT COUNT(*) FROM games WHERE attempts > 0 AND created_at > NOW() - INTERVAL $interval"
            )->fetchColumn();
            $ids = $pdo->query(
                "SELECT DISTINCT user_id FROM games WHERE attempts > 0 AND user_id IS NOT NULL AND created_at > NOW() - INTERVAL $interval"
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $totals[$period]['players'][$id] = true;
            }
        }
    }
    $edits7d = (int)connectDb(SHARED_DB)->query(
        "SELECT COUNT(*) FROM word_edits WHERE created_at > NOW() - INTERVAL 7 DAY"
    )->fetchColumn();
} catch (PDOException $e) {
    error_log('Statistik: ' . $e->getMessage());
    $error = true;
}

// Komplet dataserie for de seneste DAYS dage (inkl. i dag), også dage uden spil
$series = ['days' => [], 'games5' => [], 'games6' => [], 'players' => []];
$weekdays = ['søn', 'man', 'tir', 'ons', 'tor', 'fre', 'lør'];
for ($i = DAYS - 1; $i >= 0; $i--) {
    $ts = strtotime("-$i days");
    $key = date('Y-m-d', $ts);
    $series['days'][] = ['short' => date('j/n', $ts), 'long' => $weekdays[date('w', $ts)] . ' ' . date('j/n', $ts)];
    $series['games5'][] = (int)($games[5][$key] ?? 0);
    $series['games6'][] = (int)($games[6][$key] ?? 0);
    $series['players'][] = count($players[$key] ?? []);
}

$tiles = [];
foreach (['24h' => 'Seneste 24 timer', '7d' => 'Seneste 7 dage'] as $period => $title) {
    $tiles[] = [
        'title' => $title,
        'players' => count($totals[$period]['players']),
        'games' => array_sum($totals[$period]['games']),
        'split' => $totals[$period]['games'],
    ];
}
?>
<!DOCTYPE html>
<html lang="da">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Statistik – WordleX</title>
	<meta name="description" content="Hvor mange spiller WordleX? Spil og aktive spillere de seneste to uger.">
	<link rel="icon" href="favicon.ico">
	<link rel="stylesheet" href="ord/ord.css">
	<style>
		.viz-root {
			--series-5: #2a78d6;      /* WordleX 5 - kategorisk plads 1 */
			--series-6: #eb6834;      /* WordleX 6 - kategorisk plads 2 */
			--series-players: #6250d6;
			--grid: #ececea;
			--axis-text: #6b6a66;
		}
		.tiles { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 8px; }
		.tile h3 { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--color-muted); margin-bottom: 8px; }
		.tile-values { display: flex; gap: 20px; flex-wrap: wrap; }
		.tile-values b { display: block; font-size: 1.6rem; line-height: 1.2; }
		.tile-values span { font-size: 0.8rem; color: var(--color-muted); }

		.chart-card { margin-top: 12px; }
		.chart-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 8px; }
		.chart-head h2 { margin: 0; font-size: 1rem; }
		.legend { display: flex; gap: 14px; font-size: 0.8rem; color: var(--color-muted); }
		.legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 5px; vertical-align: -1px; }
		.chart { position: relative; width: 100%; height: 220px; }
		.chart svg { display: block; width: 100%; height: 100%; overflow: visible; }
		.chart text { font-size: 11px; fill: var(--axis-text); font-family: inherit; }
		.tooltip { position: absolute; pointer-events: none; background: #fff; border: 1px solid var(--color-line); border-radius: 6px;
			box-shadow: 0 2px 8px rgba(0,0,0,0.12); padding: 6px 9px; font-size: 0.8rem; white-space: nowrap; display: none; z-index: 2; }
		.tooltip b { display: block; margin-bottom: 2px; }
		.tooltip i { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 5px; }

		details { margin-top: 10px; font-size: 0.85rem; }
		summary { cursor: pointer; color: var(--color-focus); }
		table { border-collapse: collapse; width: 100%; margin-top: 8px; font-variant-numeric: tabular-nums; }
		th, td { padding: 4px 6px; text-align: right; border-bottom: 1px solid var(--color-line); }
		th:first-child, td:first-child { text-align: left; }

		@media (max-width: 560px) {
			.tile-values { gap: 14px; }
			.tile-values b { font-size: 1.3rem; }
		}
	</style>
</head>
<body>
	<header>
		<h1><a href="<?= h(SITE_URL) ?>">WordleX</a></h1>
		<nav>
			<a href="<?= h(SITE_URL) ?>">Forside</a>
			<a href="<?= h(GAME_URLS[5]) ?>">Spil 5</a>
			<a href="<?= h(GAME_URLS[6]) ?>">Spil 6</a>
			<a href="<?= h(WIKI_URL) ?>">Ordbog</a>
		</nav>
	</header>

	<main class="viz-root">
		<section>
			<h2 style="font-size:1.25rem;">Statistik</h2>
			<p class="muted">Hvor meget bliver der spillet? Et spil tæller, når der er gættet mindst én gang. Aktive spillere er spillere med en konto – en spiller tæller én gang, selv om vedkommende spiller begge udgaver.</p>
		</section>

		<?php if ($error): ?>
			<p class="error">Statistikken kan ikke hentes lige nu.</p>
		<?php else: ?>
			<section class="tiles">
				<?php foreach ($tiles as $tile): ?>
					<div class="card tile">
						<h3><?= h($tile['title']) ?></h3>
						<div class="tile-values">
							<div><b><?= n($tile['players']) ?></b><span>aktive spillere</span></div>
							<div><b><?= n($tile['games']) ?></b><span>spil (<?= n($tile['split'][5]) ?> + <?= n($tile['split'][6]) ?>)</span></div>
						</div>
					</div>
				<?php endforeach; ?>
			</section>
			<p class="muted">Tallene i parentes er WordleX 5 + WordleX 6. Spillerne har lavet <?= n($edits7d) ?> ændring<?= $edits7d === 1 ? '' : 'er' ?> i <a href="<?= h(WIKI_URL) ?>">ordbogen</a> de seneste 7 dage.</p>

			<section class="card chart-card">
				<div class="chart-head">
					<h2>Spil pr. dag</h2>
					<div class="legend"><span><i style="background:var(--series-5)"></i>WordleX 5</span><span><i style="background:var(--series-6)"></i>WordleX 6</span></div>
				</div>
				<div class="chart" id="chart-games" role="img" aria-label="Søjlediagram over spil pr. dag de seneste <?= DAYS ?> dage, fordelt på WordleX 5 og WordleX 6"></div>
				<details>
					<summary>Vis som tabel</summary>
					<table>
						<thead><tr><th>Dag</th><th>WordleX 5</th><th>WordleX 6</th><th>I alt</th></tr></thead>
						<tbody>
							<?php foreach ($series['days'] as $i => $day): ?>
								<tr><td><?= h($day['long']) ?></td><td><?= n($series['games5'][$i]) ?></td><td><?= n($series['games6'][$i]) ?></td><td><?= n($series['games5'][$i] + $series['games6'][$i]) ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			</section>

			<section class="card chart-card">
				<div class="chart-head"><h2>Aktive spillere pr. dag</h2></div>
				<div class="chart" id="chart-players" role="img" aria-label="Søjlediagram over aktive spillere pr. dag de seneste <?= DAYS ?> dage"></div>
				<details>
					<summary>Vis som tabel</summary>
					<table>
						<thead><tr><th>Dag</th><th>Aktive spillere</th></tr></thead>
						<tbody>
							<?php foreach ($series['days'] as $i => $day): ?>
								<tr><td><?= h($day['long']) ?></td><td><?= n($series['players'][$i]) ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			</section>
		<?php endif; ?>
	</main>

	<?php if (!$error): ?>
	<script>
		const DATA = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;

		// Pæne akse-trin: 0 og op til et rundt maksimum i 4 trin
		function niceMax(max) {
			if (max <= 4) return 4;
			const step = Math.pow(10, Math.floor(Math.log10(max / 4)));
			for (const m of [1, 2, 2.5, 5, 10]) {
				if (m * step * 4 >= max) return m * step * 4;
			}
			return max;
		}

		// Søjle med 4px afrundet top og firkantet bund (kun toppen af stakken afrundes)
		function columnPath(x, y, w, h, rounded) {
			if (h <= 0) return '';
			const r = rounded ? Math.min(4, h, w / 2) : 0;
			return `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + w - r} Q${x + w},${y} ${x + w},${y + r} V${y + h} Z`;
		}

		const fmt = n => n.toLocaleString('da-DK');

		/**
		 * Stablede søjler. series: [{label, color, values}], nederste serie først.
		 */
		function renderColumns(el, series) {
			const W = el.clientWidth, H = el.clientHeight;
			const m = { top: 12, right: 4, bottom: 22, left: 34 };
			const plotW = W - m.left - m.right, plotH = H - m.top - m.bottom;
			const n = DATA.days.length;
			const totals = DATA.days.map((_, i) => series.reduce((s, se) => s + se.values[i], 0));
			const max = niceMax(Math.max(...totals));
			const slot = plotW / n;
			const barW = Math.min(24, slot * 0.62);
			const y = v => m.top + plotH - (v / max) * plotH;
			const gap = 2; // overfladefarvet mellemrum mellem stablede segmenter

			let svg = '';
			for (let t = 0; t <= 4; t++) {
				const v = max * t / 4, yy = Math.round(y(v)) + 0.5;
				svg += `<line x1="${m.left}" x2="${W - m.right}" y1="${yy}" y2="${yy}" stroke="var(--grid)" stroke-width="1"/>`;
				svg += `<text x="${m.left - 6}" y="${yy + 4}" text-anchor="end">${fmt(v)}</text>`;
			}
			const labelEvery = slot < 34 ? 2 : 1;
			DATA.days.forEach((day, i) => {
				const cx = m.left + slot * i + slot / 2;
				const x = cx - barW / 2;
				let base = 0;
				series.forEach((se, s) => {
					const v = se.values[i];
					if (v <= 0) return;
					const isTop = series.slice(s + 1).every(o => o.values[i] <= 0);
					const y0 = y(base), y1 = y(base + v);
					const h = y0 - y1 - (base > 0 ? gap : 0);
					svg += `<path d="${columnPath(x, y1, barW, h, isTop)}" fill="${se.color}"/>`;
					base += v;
				});
				if ((n - 1 - i) % labelEvery === 0) {
					svg += `<text x="${cx}" y="${H - 6}" text-anchor="middle">${day.short}</text>`;
				}
				// Usynligt felt for hele dagen - større end søjlen, så tooltip er let at ramme
				svg += `<rect class="hit" data-i="${i}" x="${m.left + slot * i}" y="${m.top}" width="${slot}" height="${plotH}" fill="transparent"/>`;
			});
			el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">${svg}</svg><div class="tooltip"></div>`;

			const tip = el.querySelector('.tooltip');
			el.querySelectorAll('.hit').forEach(rect => {
				const show = () => {
					const i = Number(rect.dataset.i);
					const rows = series.map(se => `<div><i style="background:${se.color}"></i>${se.label}: ${fmt(se.values[i])}</div>`).join('');
					const total = series.length > 1 ? `<div>I alt: ${fmt(totals[i])}</div>` : '';
					tip.innerHTML = `<b>${DATA.days[i].long}</b>${rows}${total}`;
					tip.style.display = 'block';
					const cx = m.left + slot * i + slot / 2;
					const left = Math.min(Math.max(cx - tip.offsetWidth / 2, 0), W - tip.offsetWidth);
					tip.style.left = left + 'px';
					tip.style.top = Math.max(y(totals[i]) - tip.offsetHeight - 8, 0) + 'px';
					el.querySelectorAll('.hit').forEach(r => r.setAttribute('fill', r === rect ? 'rgba(0,0,0,0.04)' : 'transparent'));
				};
				rect.addEventListener('mouseenter', show);
				rect.addEventListener('click', show);
			});
			el.addEventListener('mouseleave', () => {
				tip.style.display = 'none';
				el.querySelectorAll('.hit').forEach(r => r.setAttribute('fill', 'transparent'));
			});
		}

		function renderAll() {
			const css = getComputedStyle(document.querySelector('.viz-root'));
			renderColumns(document.getElementById('chart-games'), [
				{ label: 'WordleX 5', color: css.getPropertyValue('--series-5').trim(), values: DATA.games5 },
				{ label: 'WordleX 6', color: css.getPropertyValue('--series-6').trim(), values: DATA.games6 }
			]);
			renderColumns(document.getElementById('chart-players'), [
				{ label: 'Aktive spillere', color: css.getPropertyValue('--series-players').trim(), values: DATA.players }
			]);
		}
		// Tegn igen når graferne skifter bredde (også første gang, når layoutet er på plads)
		let lastWidth = 0;
		new ResizeObserver(entries => {
			const width = Math.round(entries[0].contentRect.width);
			if (width > 0 && width !== lastWidth) {
				lastWidth = width;
				renderAll();
			}
		}).observe(document.getElementById('chart-games'));
	</script>
	<?php endif; ?>
</body>
</html>
