<?php
// index.php - forside for wordlex.dk: de to spil og den brugerstyrede ordbog.

require_once __DIR__ . '/lib/config.php';

const STATUS_LABELS = ['common' => 'Almindelig', 'rare' => 'Sjælden', 'deleted' => 'Slettet'];

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function badge(?string $status): string {
    return $status === null
        ? '<span class="badge badge-new">Nyt ord</span>'
        : '<span class="badge badge-' . h($status) . '">' . h(STATUS_LABELS[$status]) . '</span>';
}

function tiles(string $word, string $states): string {
    $classes = ['g' => 'tile-correct', 'y' => 'tile-present', 'a' => 'tile-absent'];
    $html = '';
    foreach (mb_str_split($word, 1, 'UTF-8') as $i => $letter) {
        $html .= '<span class="tile ' . $classes[$states[$i]] . '">' . h($letter) . '</span>';
    }
    return '<div class="tiles" aria-hidden="true">' . $html . '</div>';
}

// Tal og seneste ændringer. Siden skal virke selv hvis databasen driller.
$stats = null;
$recent = [];
try {
    $shared = connectDb(SHARED_DB);
    $stats = ['common' => [], 'edits' => (int)$shared->query("SELECT COUNT(*) FROM word_edits")->fetchColumn()];
    foreach (VARIANT_DBS as $length => $dbName) {
        $stats['common'][$length] = (int)connectDb($dbName)->query("SELECT COUNT(*) FROM words WHERE status = 'common'")->fetchColumn();
    }
    $recent = $shared->query("
        SELECT e.id, e.word, e.from_status, e.to_status, e.created_at, u.username
        FROM word_edits e LEFT JOIN users u ON u.id = e.user_id
        ORDER BY e.id DESC LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {
    error_log('Forside: ' . $e->getMessage());
}

$games = [
    5 => ['word' => 'TRANE', 'states' => 'yagag', 'rows' => VARIANT_ROWS[5]],
    6 => ['word' => 'PROCES', 'states' => 'ggyagg', 'rows' => VARIANT_ROWS[6]],
];
?>
<!DOCTYPE html>
<html lang="da">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WordleX – Dansk Wordle med point og en ordliste styret af spillerne</title>
	<meta name="description" content="Spil dansk Wordle med 5 eller 6 bogstaver, optjen point og vær med til at bestemme, hvilke ord der er med i spillet.">
	<link rel="canonical" href="<?= h(SITE_URL) ?>">
	<meta property="og:type" content="website">
	<meta property="og:site_name" content="WordleX">
	<meta property="og:locale" content="da_DK">
	<meta property="og:url" content="<?= h(SITE_URL) ?>">
	<meta property="og:title" content="WordleX – Dansk Wordle">
	<meta property="og:description" content="Dansk Wordle med point og en ordliste, som spillerne selv er med til at bestemme over.">
	<meta property="og:image" content="<?= h(SITE_URL) ?>wordlex.png">
	<meta property="og:image:width" content="896">
	<meta property="og:image:height" content="420">
	<meta property="og:image:alt" content="WordleX – dansk Wordle med 5 eller 6 bogstaver">
	<meta name="twitter:card" content="summary_large_image">
	<meta name="twitter:title" content="WordleX – Dansk Wordle">
	<meta name="twitter:description" content="Dansk Wordle med point og en ordliste, som spillerne selv er med til at bestemme over.">
	<meta name="twitter:image" content="<?= h(SITE_URL) ?>wordlex.png">
	<link rel="icon" href="favicon.ico">
	<link rel="apple-touch-icon" href="favicon.ico">
	<script type="application/ld+json">
	{
		"@context": "https://schema.org",
		"@type": "WebSite",
		"name": "WordleX",
		"url": "<?= h(SITE_URL) ?>",
		"inLanguage": "da"
	}
	</script>
	<link rel="stylesheet" href="ord/ord.css">
	<style>
		.help h4 { margin: 14px 0 4px; font-size: 0.95rem; }
		.help p, .help li { font-size: 0.9rem; }
		.help ul { padding-left: 18px; }

		.hero { text-align: center; padding: 36px 16px 8px; }
		.hero .tiles { justify-content: center; margin-bottom: 18px; }
		.hero .tile { width: 44px; height: 44px; font-size: 1.5rem; }
		.hero p { font-size: 1.1rem; max-width: 520px; margin: 0 auto; }

		.tiles { display: flex; gap: 5px; }
		.tile { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 5px; color: #fff; font-weight: 800; font-size: 1.2rem; }
		.tile-correct { background: var(--color-correct); }
		.tile-present { background: var(--color-present); }
		.tile-absent { background: var(--color-absent); }

		.games { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
		.game { display: flex; flex-direction: column; align-items: center; gap: 12px; text-align: center; padding: 22px 16px; }
		.game h2 { margin: 0; font-size: 1.3rem; }
		.game .btn { padding: 10px 22px; font-size: 1rem; }
		.game .btn:hover { text-decoration: none; }

		.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 14px 0; }
		.stat { text-align: center; padding: 12px 8px; }
		.stat b { display: block; font-size: 1.4rem; }
		.stat span { font-size: 0.8rem; color: var(--color-muted); }

		.statuses { list-style: none; margin: 10px 0; }
		.statuses li { margin-bottom: 6px; }

		.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

		footer { text-align: center; padding: 24px 16px 40px; font-size: 0.85rem; color: var(--color-muted); }

		@media (max-width: 560px) {
			.games { grid-template-columns: 1fr; }
			.stats { gap: 8px; }
			.stat b { font-size: 1.15rem; }
			.hero .tile { width: 36px; height: 36px; font-size: 1.2rem; }
		}
	</style>
</head>
<body data-page="forside">
	<header class="toolbar">
		<div class="toolbar-left">
			<h1><a href="<?= h(SITE_URL) ?>">WordleX</a></h1>
		</div>
		<div class="toolbar-right" id="nav"></div>
	</header>

	<div class="hero">
		<?= tiles('WORDLEX', 'gyagaga') ?>
		<h1 class="visually-hidden">WordleX</h1>
		<p>Dansk Wordle med point – og en ordliste, som spillerne selv er med til at bestemme over.</p>
	</div>

	<main>
		<section class="games">
			<?php foreach ($games as $length => $game): ?>
				<div class="card game">
					<h2>WordleX <?= $length ?></h2>
					<?= tiles($game['word'], $game['states']) ?>
					<p class="muted"><?= $length ?> bogstaver · <?= $game['rows'] ?> forsøg</p>
					<a class="btn" href="<?= h(GAME_URLS[$length]) ?>">Spil WordleX <?= $length ?></a>
				</div>
			<?php endforeach; ?>
		</section>

		<section>
			<h2>Point</h2>
			<p>Du får point for hver ny grøn og gul brik – jo tidligere i spillet, jo flere. Gætter du ordet i første forsøg, får du 1.000 point. Det gælder i begge udgaver, så du kan se, hvor meget sværere 6 bogstaver er.</p>
			<p>Går du i stå, kan du købe en liste over de ord, der stadig er mulige. Den bliver dyrere, jo færre ord der er tilbage. Opret en konto for at komme på Top-100.</p>
		</section>

		<section>
			<h2>En ordliste styret af spillerne</h2>
			<p>Hvilke ord der er med i WordleX, bestemmer spillerne i fællesskab i <a href="<?= h(WIKI_URL) ?>">ordbogen</a>. Hvert ord har en af tre statusser:</p>
			<ul class="statuses">
				<li><?= badge('common') ?> kan blive løsningsord</li>
				<li><?= badge('rare') ?> accepteres som gæt, men er aldrig løsningsord</li>
				<li><?= badge('deleted') ?> accepteres ikke som gæt</li>
			</ul>
			<p><?= MIN_GAMES_TO_EDIT > 0 ? 'Har du en konto og har spillet mindst ' . MIN_GAMES_TO_EDIT . ' spil' : 'Har du en konto' ?>, kan du ændre et ords status eller foreslå nye ord. Du skal altid skrive, hvorfor. Alle ændringer bliver gemt, så alle kan se, hvem der har ændret hvad og hvorfor – og ændre det igen. Er du uenig i en ændring eller i en brugers adfærd, kan du anmelde den til admin.</p>

			<?php if ($stats): ?>
				<div class="stats">
					<?php foreach ($stats['common'] as $length => $count): ?>
						<div class="card stat"><b><?= number_format($count, 0, ',', '.') ?></b><span>løsningsord med <?= $length ?> bogstaver</span></div>
					<?php endforeach; ?>
					<div class="card stat"><b><?= number_format($stats['edits'], 0, ',', '.') ?></b><span>ændringer fra spillerne</span></div>
				</div>
				<p class="muted"><a href="<?= h(SITE_URL) ?>statistik.php">Se hvor meget der bliver spillet</a></p>
			<?php endif; ?>

			<?php if ($recent): ?>
				<h3 style="font-size:0.95rem; margin:16px 0 6px;">Seneste ændringer</h3>
				<div class="card">
					<ul class="word-list">
						<?php foreach ($recent as $e): ?>
							<li>
								<div class="word-row">
									<a class="word" href="<?= h(WIKI_URL . 'ord.html?ord=' . rawurlencode($e['word']) . '#e' . $e['id']) ?>"><?= h($e['word']) ?></a>
									<?= $e['from_status'] === null && $e['to_status'] !== 'deleted' ? 'tilføjet som ' . badge($e['to_status']) : badge($e['from_status']) . ' → ' . badge($e['to_status']) ?>
								</div>
								<div class="edit-meta">af <?= $e['username'] !== null ? h($e['username']) : '<i>slettet bruger</i>' ?>, <?= h(date('j/n Y', strtotime($e['created_at']))) ?></div>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<p style="margin-top:16px;"><a class="btn" href="<?= h(WIKI_URL) ?>">Gå til ordbogen</a></p>
		</section>
	</main>

	<template id="help-text">
		<h3>Hjælp &amp; regler</h3>
		<div class="help">
			<p>WordleX er dansk Wordle i to udgaver: <b>WordleX 5</b>, hvor du skal gætte et ord på 5 bogstaver på højst <?= VARIANT_ROWS[5] ?> forsøg, og <b>WordleX 6</b> med 6 bogstaver og <?= VARIANT_ROWS[6] ?> forsøg.</p>

			<h4>Sådan spiller du</h4>
			<ul>
				<li><b style="color: var(--color-correct);">Grøn:</b> bogstavet er i ordet og står på den rigtige plads.</li>
				<li><b style="color: #c79100;">Gul:</b> bogstavet er i ordet, men på en anden plads.</li>
				<li><b style="color: var(--color-absent);">Grå:</b> bogstavet er ikke i ordet.</li>
			</ul>

			<h4>Point</h4>
			<p>Du får point for hver ny grøn og gul brik – jo tidligere i spillet, jo flere. Gætter du ordet i første forsøg, får du 1.000 point i begge udgaver. En gul er halvt så meget værd som en grøn, og bliver en gul senere grøn, får du forskellen. Kun vundne spil giver point på Top-100.</p>

			<h4>Hints</h4>
			<p>I første række kan du gratis få et forslag til et startord. Derefter kan du købe en liste over de ord, der stadig er mulige. Prisen er 25–200 point og stiger, jo færre ord der er tilbage.</p>

			<h4>Konto og Top-100</h4>
			<p>Med en konto bliver din statistik gemt, og du kommer på Top-100. Den samme konto gælder begge spil og ordbogen.</p>

			<h4>Ordbogen</h4>
			<p>Spillerne bestemmer i fællesskab, hvilke ord der er med. Et ord kan være <?= badge('common') ?>, <?= badge('rare') ?> eller <?= badge('deleted') ?>. Kun almindelige ord kan blive løsningsord, og slettede ord accepteres ikke som gæt. <?= MIN_GAMES_TO_EDIT > 0 ? 'Har du spillet mindst ' . MIN_GAMES_TO_EDIT . ' spil' : 'Har du en konto' ?>, kan du ændre et ords status eller foreslå nye ord – altid med en begrundelse.</p>
		</div>
		<div class="modal-buttons"><button class="btn" onclick="closeModal()">Luk</button></div>
	</template>

	<template id="about-text">
		<h3>Om WordleX</h3>
		<p>WordleX er dansk Wordle med point, Top-100 og en ordliste, som spillerne selv er med til at styre.</p>
		<p>Idé og vibe-kode: Thomas Jensen</p>
		<p>Kontakt: <a href="mailto:wordlex@rpix.dk">wordlex@rpix.dk</a></p>
		<div class="modal-buttons"><button class="btn" onclick="closeModal()">Luk</button></div>
	</template>

	<script>window.WORDLEX_API = 'ord/api.php';</script>
	<script src="ord/ord.js"></script>
	<script>
		function showTemplate(id, extraClass = '') {
			showModal(document.getElementById(id).innerHTML, extraClass);
		}
		window.EXTRA_MENU_ITEMS = [
			{ label: 'Hjælp & regler', action: () => showTemplate('help-text', 'modal-wide') },
			{ label: 'Om WordleX', action: () => showTemplate('about-text') }
		];
		loadMe();
	</script>

	<footer>
		Idé og vibe-kode: Thomas Jensen · Kontakt: <a href="mailto:wordlex@rpix.dk">wordlex@rpix.dk</a>
	</footer>
</body>
</html>
