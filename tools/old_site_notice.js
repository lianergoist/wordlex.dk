// old_site_notice.js - besked på den GAMLE side (w5./w6.wordlex.dk) om at den
// nye version overtager 1. november 2026.
//
// Kopieres til /var/www/wordlex.dk/w5/nyhed.js og /w6/nyhed.js og indlæses med
// <script src="nyhed.js"></script> lige før </body>. Filen er selvstændig og
// bruger ikke noget fra den gamle sides egen kode.
(function () {
	const NEW_SITE = 'https://test.wordlex.dk/';
	const STORAGE_KEY = 'wordlex_nov2026_notice';
	const SHOW_AGAIN_AFTER_DAYS = 3;

	const variant = location.hostname.startsWith('w6') ? 6 : 5;
	const newGame = NEW_SITE + 'w' + variant + '/';

	// "Senere" skjuler beskeden et par dage
	try {
		const dismissed = Number(localStorage.getItem(STORAGE_KEY) || 0);
		if (Date.now() - dismissed < SHOW_AGAIN_AFTER_DAYS * 24 * 60 * 60 * 1000) return;
	} catch (e) { /* uden localStorage vises beskeden hver gang */ }

	const style = document.createElement('style');
	style.textContent = `
		#nov-notice { position: fixed; inset: 0; z-index: 10000; display: flex; align-items: center; justify-content: center;
			padding: 16px; background: rgba(0,0,0,0.55); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		#nov-notice .box { background: #fff; color: #1a1a1a; border-radius: 12px; padding: 20px; width: 100%; max-width: 440px;
			max-height: 90vh; overflow-y: auto; line-height: 1.5; font-size: 0.92rem; }
		#nov-notice h3 { margin: 0 0 10px; font-size: 1.25rem; }
		#nov-notice p { margin: 0 0 10px; }
		#nov-notice a { color: #2196f3; }
		#nov-notice .buttons { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; margin-top: 14px; }
		#nov-notice .btn { border: none; border-radius: 6px; padding: 9px 14px; font: inherit; font-weight: 700; cursor: pointer; text-decoration: none; }
		#nov-notice .btn-primary { background: #2196f3; color: #fff; }
		#nov-notice .btn-secondary { background: #e2e4e9; color: #1a1a1a; }
	`;
	document.head.appendChild(style);

	const overlay = document.createElement('div');
	overlay.id = 'nov-notice';
	overlay.innerHTML = `
		<div class="box" role="dialog" aria-modal="true" aria-labelledby="nov-notice-title">
			<h3 id="nov-notice-title">WordleX får nyt liv 1. november</h3>
			<p>Denne udgave af WordleX lukker ved udgangen af oktober. 1. november vender spillet tilbage i en ny udgave, hvor det er spillerne selv, der bestemmer, hvilke ord der er med. I den nye <a href="${NEW_SITE}ord/">ordbog</a> kan du foreslå nye ord, gøre ord sjældne og slette ord, der ikke hører hjemme i spillet.</p>
			<p>Den nye udgave har også et forbedret pointsystem, hvor du bliver belønnet for at finde ordet hurtigt. Pointene kan ikke sammenlignes med de gamle, så <b>highscore-listen og din statistik starter forfra</b>. Din konto flytter med, med samme brugernavn og kodeord.</p>
			<p>Du kan allerede nu prøve den nye udgave på <a href="${NEW_SITE}">test.wordlex.dk</a>. Det, du laver der, bliver også flyttet med 1. november.</p>
			<div class="buttons">
				<button class="btn btn-secondary" id="nov-notice-later">Senere</button>
				<a class="btn btn-primary" href="${newGame}">Prøv den nye udgave</a>
			</div>
		</div>`;

	function close() {
		try { localStorage.setItem(STORAGE_KEY, String(Date.now())); } catch (e) {}
		overlay.remove();
	}
	overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
	document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

	function show() {
		document.body.appendChild(overlay);
		overlay.querySelector('#nov-notice-later').addEventListener('click', close);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', show);
	else show();
})();
