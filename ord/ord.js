// ord.js - fælles hjælpefunktioner for ordbogens sider.

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
	const res = await fetch('api.php?' + query, { credentials: 'same-origin', ...options });
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

function renderHeader() {
	const nav = document.getElementById('nav');
	const games = Object.entries(me.game_urls || {})
		.map(([len, url]) => `<a href="${esc(url)}">Spil ${esc(len)}</a>`).join('');
	const admin = me.is_admin ? '<a href="admin.html">Admin</a>' : '';
	const account = me.logged_in
		? `<span class="user-name">${esc(me.username)}</span><button class="link-btn" onclick="logout()">Log ud</button>`
		: '<button class="link-btn" onclick="openLogin()">Log ind / Opret</button>';
	nav.innerHTML = `<a href="index.html">Ordbog</a>${games}${admin}${account}`;
}

function showModal(html) {
	closeModal();
	const overlay = document.createElement('div');
	overlay.className = 'modal-overlay';
	overlay.id = 'modal';
	overlay.innerHTML = `<div class="modal">${html}</div>`;
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
