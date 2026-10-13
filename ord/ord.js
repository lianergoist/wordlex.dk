// ord.js - fælles hjælpefunktioner for ordbogens sider (bruges også af forsiden,
// som sætter window.WORDLEX_API = 'ord/api.php' før filen indlæses).

const STATUS_LABELS = { common: 'Almindelig', rare: 'Sjælden', deleted: 'Slettet' };
const STATUS_HELP = {
	common: 'kan blive løsningsord',
	rare: 'accepteres som gæt, men er aldrig løsningsord',
	deleted: 'accepteres ikke som gæt'
};

let me = { logged_in: false, can_edit: false, is_admin: false, game_urls: {} };

async function api(action, body = null, params = {}) {
	const query = new URLSearchParams({ action, ...params });
	const options = body === null ? {} : {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify(body)
	};
	const res = await fetch((window.WORDLEX_API || 'api.php') + '?' + query, { credentials: 'same-origin', ...options });
	return res.json();
}

function esc(value) {
	return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function statusBadge(status) {
	if (!status) return '<span class="badge badge-new">Nyt ord</span>';
	return `<span class="badge badge-${esc(status)}">${esc(STATUS_LABELS[status] || status)}</span>`;
}

// "fra → til" for en ændring. from_status = null betyder at ordet blev tilføjet.
function changeText(edit) {
	if (edit.from_status === null) return `Tilføjet som ${statusBadge(edit.to_status)}`;
	return `${statusBadge(edit.from_status)} → ${statusBadge(edit.to_status)}`;
}

function formatDate(sqlDate) {
	const d = new Date(sqlDate.replace(' ', 'T'));
	return d.toLocaleDateString('da-DK', { day: 'numeric', month: 'short', year: 'numeric' }) +
		' ' + d.toLocaleTimeString('da-DK', { hour: '2-digit', minute: '2-digit' });
}

function userLink(username) {
	if (!username) return '<i>slettet bruger</i>';
	return `<a href="index.html?bruger=${encodeURIComponent(username)}">${esc(username)}</a>`;
}

function wordUrl(word, editId = null) {
	return 'ord.html?ord=' + encodeURIComponent(word) + (editId ? '#e' + editId : '');
}

/* --- Header med login --- */

async function loadMe() {
	me = await api('my_status');
	renderHeader();
	return me;
}

const MENU_ICON = '<svg xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 -960 960 960" width="24" fill="#1f1f1f" aria-hidden="true"><path d="M480-160q-33 0-56.5-23.5T400-240q0-33 23.5-56.5T480-320q33 0 56.5 23.5T560-240q0 33-23.5 56.5T480-160Zm0-240q-33 0-56.5-23.5T400-480q0-33 23.5-56.5T480-560q33 0 56.5 23.5T560-480q0 33-23.5 56.5T480-400Zm0-240q-33 0-56.5-23.5T400-720q0-33 23.5-56.5T480-800q33 0 56.5 23.5T560-720q0 33-23.5 56.5T480-640Z"/></svg>';

/**
 * Bygger højre side af toolbaren: brugernavn og en menu. <body data-page="...">
 * angiver den aktuelle side, som udelades fra menuen. En side kan tilføje egne
 * punkter nederst via window.EXTRA_MENU_ITEMS = [{ label, action }].
 */
function renderHeader() {
	const right = document.getElementById('nav');
	if (!right) return;
	const home = document.getElementById('home-link');
	if (home && me.site_url) home.href = me.site_url;

	const page = document.body.dataset.page;
	const links = [
		['forside', 'Forside', me.site_url],
		['ordbog', 'Ordbog', me.wiki_url],
		['spil5', 'Spil WordleX 5', me.game_urls?.[5]],
		['spil6', 'Spil WordleX 6', me.game_urls?.[6]],
		['statistik', 'Statistik', me.site_url && me.site_url + 'statistik.php'],
	];
	if (me.is_admin) links.push(['admin', 'Admin', me.wiki_url + 'admin.html']);

	const items = [
		{ label: me.logged_in ? 'Log ud' : 'Log ind / Opret', action: me.logged_in ? logout : openLogin },
		...links.filter(([key, , url]) => key !== page && url).map(([, label, url]) => ({ label, url })),
		...(window.EXTRA_MENU_ITEMS || [])
	];

	right.innerHTML = `
		<button class="toolbar-user" id="toolbar-user" title="${esc(me.username || 'Log ind')}">${esc(me.logged_in ? me.username : 'Gæst')}</button>
		<button class="menu-btn" id="menu-btn" title="Menu" aria-haspopup="true" aria-expanded="false">${MENU_ICON}</button>
		<nav class="dropdown" id="menu"></nav>`;
	const menu = right.querySelector('#menu');
	const menuBtn = right.querySelector('#menu-btn');
	const toggle = open => { menu.classList.toggle('active', open); menuBtn.setAttribute('aria-expanded', open); };

	items.forEach(item => {
		const el = document.createElement(item.url ? 'a' : 'button');
		el.textContent = item.label;
		if (item.url) el.href = item.url;
		else el.addEventListener('click', () => { toggle(false); item.action(); });
		menu.appendChild(el);
	});

	menuBtn.addEventListener('click', e => { e.stopPropagation(); toggle(!menu.classList.contains('active')); });
	right.querySelector('#toolbar-user').addEventListener('click', e => {
		e.stopPropagation();
		if (me.logged_in) toggle(true); else openLogin();
	});
	closeMenuOnOutsideClick = e => { if (!menu.contains(e.target)) toggle(false); };
}

let closeMenuOnOutsideClick = () => {};
document.addEventListener('click', e => closeMenuOnOutsideClick(e));

function showModal(html, extraClass = '') {
	closeModal();
	const overlay = document.createElement('div');
	overlay.className = 'modal-overlay';
	overlay.id = 'modal';
	overlay.innerHTML = `<div class="modal ${extraClass}">${html}</div>`;
	overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
	document.body.appendChild(overlay);
	const first = overlay.querySelector('input, textarea');
	if (first) first.focus();
}

function closeModal() {
	document.getElementById('modal')?.remove();
}

function openLogin() {
	showModal(`
		<h3>Log ind</h3>
		<p class="muted" style="margin-bottom:10px;">Samme konto som i spillet.</p>
		<input type="text" id="login-user" placeholder="Brugernavn" autocomplete="username">
		<input type="password" id="login-pass" placeholder="Kodeord" autocomplete="current-password">
		<div id="login-error" class="error"></div>
		<div class="modal-buttons">
			<button class="btn btn-secondary" onclick="submitLogin('register')">Opret ny</button>
			<button class="btn" onclick="submitLogin('login')">Log ind</button>
		</div>`);
	document.getElementById('login-pass').addEventListener('keydown', e => { if (e.key === 'Enter') submitLogin('login'); });
}

async function submitLogin(action) {
	const data = await api(action, {
		username: document.getElementById('login-user').value,
		password: document.getElementById('login-pass').value
	});
	if (data.success) {
		closeModal();
		location.reload();
	} else {
		document.getElementById('login-error').textContent = data.message || 'Det lykkedes ikke.';
	}
}

async function logout() {
	await api('logout', {});
	location.reload();
}

/* --- Anmeld en ændring eller en bruger --- */

function openReport(target) {
	if (!me.logged_in) return openLogin();
	const what = target.edit_id ? 'ændringen' : `brugeren ${esc(target.username)}`;
	showModal(`
		<h3>Anmeld ${what}</h3>
		<p class="muted" style="margin-bottom:10px;">Anmeldelsen sendes til admin.</p>
		<textarea id="report-reason" placeholder="Hvorfor anmelder du?"></textarea>
		<div id="report-error" class="error"></div>
		<div class="modal-buttons">
			<button class="btn btn-secondary" onclick="closeModal()">Annuller</button>
			<button class="btn" id="report-submit">Send</button>
		</div>`);
	document.getElementById('report-submit').onclick = async () => {
		const data = await api('report', { ...target, reason: document.getElementById('report-reason').value });
		if (data.success) {
			showModal('<h3>Tak</h3><p>Din anmeldelse er sendt til admin.</p><div class="modal-buttons"><button class="btn" onclick="closeModal()">Luk</button></div>');
		} else {
			document.getElementById('report-error').textContent = data.message;
		}
	};
}
