/* Gift Card Redeem Desk — vanilla JS, no dependencies. All text from the server goes in via textContent. */
(function () {
	'use strict';
	var C = window.GCRD || {};
	var T = C.i18n || {};
	var state = { card: null, history: [], mode: 'redeem', busy: false };

	/* ---------- helpers ---------- */
	function $(id) { return document.getElementById(id); }
	function el(tag, attrs, children) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v === null || v === undefined || v === false) { return; }
				if (k === 'class') { n.className = v; }
				else if (k === 'text') { n.textContent = v; }
				else if (k.indexOf('on') === 0) { n.addEventListener(k.slice(2), v); }
				else { n.setAttribute(k, v === true ? '' : v); }
			});
		}
		(children || []).forEach(function (c) {
			if (c === null || c === undefined || c === false) { return; }
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}
	function clear(n) { while (n.firstChild) { n.removeChild(n.firstChild); } return n; }
	function store(k, v) { try { if (v === undefined) { return localStorage.getItem('gcrd_' + k); } localStorage.setItem('gcrd_' + k, v); } catch (e) { return null; } }
	function money(n) {
		var d = typeof C.decimals === 'number' ? C.decimals : 2;
		var s;
		try { s = new Intl.NumberFormat(undefined, { minimumFractionDigits: d, maximumFractionDigits: d }).format(n); } catch (e) { s = Number(n).toFixed(d); }
		return (C.currency || '') + ' ' + s;
	}
	function parseAmount(v) {
		v = String(v || '').replace(/[,\s]/g, '');
		if (!/^\d*\.?\d*$/.test(v) || v === '' || v === '.') { return NaN; }
		return Math.round(parseFloat(v) * Math.pow(10, C.decimals || 0)) / Math.pow(10, C.decimals || 0);
	}
	function outlet() { return $('gcrd-outlet') ? $('gcrd-outlet').value : ''; }

	function api(method, path, body) {
		var url = C.rest.replace(/\/$/, '') + path;
		var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce, 'Accept': 'application/json' } };
		if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
		return fetch(url, opts).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (r.ok) { return data; }
				if (data && (data.code === 'rest_cookie_invalid_nonce' || r.status === 401)) {
					window.alert(T.sessionExpired);
					window.location.reload();
				}
				var err = new Error((data && data.message) || T.error);
				err.code = data && data.code;
				throw err;
			});
		}, function () { throw new Error(T.error); });
	}

	function showMsg(node, text, ok) {
		if (!text) { node.hidden = true; return; }
		node.textContent = text;
		node.className = 'gcrd-msg' + (ok ? ' gcrd-msg--ok' : '');
		node.hidden = false;
	}

	/* ---------- modal ---------- */
	var lastFocus = null;
	function openModal(nodes, onClose) {
		lastFocus = document.activeElement;
		var body = clear($('gcrd-modal-body'));
		nodes.forEach(function (n) { body.appendChild(n); });
		$('gcrd-modal').hidden = false;
		$('gcrd-modal')._onClose = onClose || null;
		var f = body.querySelector('[autofocus], input, textarea, button');
		if (f) { f.focus(); }
	}
	function closeModal() {
		var m = $('gcrd-modal');
		if (m.hidden) { return; }
		m.hidden = true;
		if (typeof m._onClose === 'function') { m._onClose(); }
		clear($('gcrd-modal-body'));
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });
	document.addEventListener('click', function (e) { if (e.target === $('gcrd-modal')) { closeModal(); } });

	/* ---------- outlets ---------- */
	function initOutlets() {
		var sel = $('gcrd-outlet');
		if (!sel) { return; }
		var list = C.outlets || [];
		if (!list.length) { sel.parentNode.hidden = true; return; }
		sel.appendChild(el('option', { value: '', text: T.chooseOutlet }));
		list.forEach(function (o) { sel.appendChild(el('option', { value: o, text: o })); });
		var saved = store('outlet');
		if (saved && list.indexOf(saved) !== -1) { sel.value = saved; }
		sel.addEventListener('change', function () { store('outlet', sel.value); loadToday(); });
	}

	/* ---------- lookup ---------- */
	function lookup(code) {
		code = String(code || '').trim();
		if (!code) { $('gcrd-code').focus(); return; }
		var btn = $('gcrd-lookup');
		btn.disabled = true;
		showMsg($('gcrd-search-msg'), '');
		return api('GET', '/card?code=' + encodeURIComponent(code)).then(function (data) {
			state.card = data.card;
			state.history = data.history || [];
			$('gcrd-code').value = data.card.code;
			renderCard();
		}).catch(function (e) {
			state.card = null;
			$('gcrd-card').hidden = true;
			showMsg($('gcrd-search-msg'), e.message);
			$('gcrd-code').select();
		}).then(function () { btn.disabled = false; });
	}

	function detail(label, value, cls) {
		if (!value) { return null; }
		return el('div', { class: cls || '' }, [el('dt', { text: label }), el('dd', { text: value, class: cls === 'gcrd-wide gcrd-quote-wrap' ? 'gcrd-quote' : '' })]);
	}

	function renderCard() {
		var c = state.card;
		var box = clear($('gcrd-card'));
		box.hidden = false;

		box.appendChild(el('div', { class: 'gcrd-card-visual' }, [
			el('div', { class: 'gcrd-card-code', text: c.code }),
			el('span', { class: 'gcrd-badge gcrd-badge--' + c.status, text: T['status_' + c.status] || c.status }),
			el('div', { class: 'gcrd-card-balance-label', text: T.balance }),
			el('div', { class: 'gcrd-card-balance', text: money(c.balance) })
		]));

		var dl = el('dl', { class: 'gcrd-details' }, [
			detail(T.originalValue, c.original !== null ? money(c.original) : ''),
			detail(T.expires, c.expires_html),
			detail(T.issued, c.issued_html),
			detail(T.recipient, [c.to, c.recipient_email].filter(Boolean).join(' · ')),
			detail(T.from, c.from),
			detail(T.buyer, c.buyer),
			detail(T.message, c.message ? '“' + c.message + '”' : '', 'gcrd-wide gcrd-quote-wrap')
		]);
		if (c.order_id) {
			var orderDd = el('dd', {}, [c.order_url ? el('a', { href: c.order_url, target: '_blank', rel: 'noopener', class: 'gcrd-link', text: '#' + c.order_id }) : '#' + c.order_id]);
			dl.appendChild(el('div', {}, [el('dt', { text: T.order }), orderDd]));
		}
		box.appendChild(dl);

		box.appendChild(renderActions());
		box.appendChild(el('h3', { text: T.history }));
		box.appendChild(renderHistory());
	}

	function renderActions() {
		var c = state.card;
		var wrap = el('div', { class: 'gcrd-actions' });
		var canRedeem = c.status === 'active';
		var canRecharge = C.canRecharge && (c.status === 'active' || c.status === 'empty');
		if (!canRedeem && !canRecharge) {
			wrap.appendChild(el('p', { class: 'gcrd-inactive-note', text: T['status_' + c.status] || '' }));
			return wrap;
		}
		if (!canRedeem) { state.mode = 'recharge'; }
		if (!canRecharge) { state.mode = 'redeem'; }

		if (canRedeem && canRecharge) {
			var tabs = el('div', { class: 'gcrd-tabs', role: 'tablist' });
			['redeem', 'recharge'].forEach(function (m) {
				tabs.appendChild(el('button', {
					type: 'button', class: 'gcrd-tab', role: 'tab', 'aria-selected': state.mode === m ? 'true' : 'false', text: T[m],
					onclick: function () { state.mode = m; renderCard(); }
				}));
			});
			wrap.appendChild(tabs);
		}

		var amount = el('input', { id: 'gcrd-amount', class: 'gcrd-input', type: 'text', inputmode: 'decimal', autocomplete: 'off' });
		var amountWrap = el('div', { class: 'gcrd-wide' }, [
			el('label', { class: 'gcrd-label', for: 'gcrd-amount', text: T.amount }),
			el('div', { class: 'gcrd-amount' }, [el('span', { class: 'gcrd-cur', text: C.currency }), amount])
		]);
		if (state.mode === 'redeem') {
			amountWrap.appendChild(el('div', { class: 'gcrd-chips' }, [
				el('button', { type: 'button', class: 'gcrd-chip', text: T.fullBalance + ' · ' + money(c.balance), onclick: function () { amount.value = c.balance; amount.focus(); } })
			]));
		}
		var ref = el('input', { id: 'gcrd-ref', class: 'gcrd-input', type: 'text', maxlength: '191', autocomplete: 'off' });
		var note = el('textarea', { id: 'gcrd-note', class: 'gcrd-textarea', maxlength: '1000' });

		var form = el('form', { class: 'gcrd-form-grid', novalidate: true }, [
			amountWrap,
			el('div', { class: 'gcrd-wide' }, [el('label', { class: 'gcrd-label', for: 'gcrd-ref', text: C.refLabel + (C.requireRef ? ' *' : '') }), ref]),
			el('div', { class: 'gcrd-wide' }, [el('label', { class: 'gcrd-label', for: 'gcrd-note', text: T.note }), note]),
			el('p', { class: 'gcrd-msg gcrd-wide', id: 'gcrd-form-msg', role: 'alert', hidden: true }),
			el('div', { class: 'gcrd-wide gcrd-submit' }, [el('button', { type: 'submit', class: 'gcrd-btn gcrd-btn--primary gcrd-btn--block', text: T[state.mode] })])
		]);
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var a = parseAmount(amount.value);
			var msg = $('gcrd-form-msg');
			if (!(a > 0)) { return showMsg(msg, T.enterAmount); }
			if (state.mode === 'redeem' && a > c.balance + 0.000001) { return showMsg(msg, T.overBalance); }
			if ((C.outlets || []).length && !outlet()) { $('gcrd-outlet').focus(); return showMsg(msg, T.needOutlet); }
			if (C.requireRef && !ref.value.trim()) { ref.focus(); return showMsg(msg, T.needRef); }
			showMsg(msg, '');
			confirmChange(a, ref.value.trim(), note.value.trim());
		});
		wrap.appendChild(form);
		// On phones, don't pop the keyboard and jump the page; on tablets/desktops go straight to the amount.
		if (window.matchMedia && window.matchMedia('(min-width: 601px)').matches) {
			setTimeout(function () { amount.focus({ preventScroll: true }); }, 0);
		}
		return wrap;
	}

	function summaryRow(label, value, big) {
		return el('div', { class: big ? 'gcrd-big' : '' }, [el('dt', { text: label }), el('dd', { text: value })]);
	}

	function confirmChange(amount, reference, note) {
		var c = state.card;
		var after = state.mode === 'redeem' ? c.balance - amount : c.balance + amount;
		var okBtn = el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--primary', text: state.mode === 'redeem' ? T.confirmRedeem : T.confirmRecharge, autofocus: true });
		var msg = el('p', { class: 'gcrd-msg', role: 'alert', hidden: true });
		okBtn.addEventListener('click', function () {
			if (state.busy) { return; }
			state.busy = true;
			okBtn.disabled = true;
			api('POST', '/card/' + state.mode, { code: c.code, amount: amount, outlet: outlet(), reference: reference, note: note })
				.then(function (res) { state.busy = false; showResult(res); })
				.catch(function (e) { state.busy = false; okBtn.disabled = false; showMsg(msg, e.message); });
		});
		openModal([
			el('h2', { id: 'gcrd-modal-title', text: state.mode === 'redeem' ? T.confirmRedeem : T.confirmRecharge }),
			el('dl', { class: 'gcrd-summary' }, [
				summaryRow(T.cardCode, c.code),
				summaryRow(T.amount, money(amount), true),
				summaryRow(T.balance, money(c.balance)),
				summaryRow(T.newBalance, money(after)),
				outlet() ? summaryRow(T.outlet, outlet()) : null,
				reference ? summaryRow(T.ref, reference) : null
			]),
			msg,
			el('div', { class: 'gcrd-modal-actions' }, [el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--ghost', text: T.cancel, onclick: closeModal }), okBtn])
		]);
	}

	function showResult(res) {
		var tx = res.transaction;
		state.card = res.card;
		state.history.unshift(tx);
		renderCard();
		loadToday();
		openModal([
			el('div', { class: 'gcrd-success-icon', 'aria-hidden': 'true' }, [svgCheck()]),
			el('h2', { id: 'gcrd-modal-title', class: 'gcrd-center', text: T.success }),
			el('dl', { class: 'gcrd-summary' }, [
				summaryRow(T.cardCode, tx.code),
				summaryRow(T['type_' + tx.type], money(tx.amount)),
				summaryRow(T.newBalance, money(tx.balance_after), true),
				tx.outlet ? summaryRow(T.outlet, tx.outlet) : null,
				tx.reference ? summaryRow(T.ref, tx.reference) : null,
				summaryRow(T.dateTime, tx.created_html)
			]),
			el('div', { class: 'gcrd-modal-actions' }, [
				el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--ghost', text: T.print, onclick: function () { printSlip(tx); } }),
				el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--primary', text: T.newCard, autofocus: true, onclick: function () { closeModal(); resetDesk(); } })
			])
		]);
	}

	function svgCheck() {
		var ns = 'http://www.w3.org/2000/svg';
		var s = document.createElementNS(ns, 'svg');
		s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', '30'); s.setAttribute('height', '30');
		var p = document.createElementNS(ns, 'path');
		p.setAttribute('d', 'M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z'); p.setAttribute('fill', 'currentColor');
		s.appendChild(p);
		return s;
	}

	function resetDesk() {
		state.card = null; state.history = []; state.mode = 'redeem';
		$('gcrd-card').hidden = true;
		$('gcrd-code').value = '';
		$('gcrd-code').focus();
	}

	/* ---------- history & void ---------- */
	function txItem(tx, withVoid) {
		var sign = tx.type === 'redeem' ? '−' : (tx.type === 'recharge' ? '+' : '↺');
		var li = el('li', { class: 'gcrd-tx gcrd-tx--' + tx.type + (tx.voided_by ? ' gcrd-tx-voided' : '') }, [
			el('span', { class: 'gcrd-tx-main', text: (T['type_' + tx.type] || tx.type) + (withVoid ? '' : ' · ' + tx.code) }),
			el('span', { class: 'gcrd-tx-amt', text: sign + ' ' + money(tx.amount) }),
			el('span', { class: 'gcrd-tx-meta', text: [tx.created_html, tx.outlet, tx.reference ? T.ref + ' ' + tx.reference : '', tx.user, tx.voided_by ? T.voided : '', tx.type === 'void' && tx.note ? '“' + tx.note + '”' : ''].filter(Boolean).join(' · ') })
		]);
		if (withVoid && C.canVoid && tx.type !== 'void' && !tx.voided_by) {
			li.appendChild(el('div', { class: 'gcrd-tx-actions' }, [
				el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--danger gcrd-btn--sm', text: T.void, onclick: function () { confirmVoid(tx); } })
			]));
		}
		return li;
	}

	function renderHistory() {
		var ul = el('ul', { class: 'gcrd-tx-list' });
		if (!state.history.length) { return el('p', { class: 'gcrd-empty', text: T.noHistory }); }
		state.history.forEach(function (tx) { ul.appendChild(txItem(tx, true)); });
		return ul;
	}

	function confirmVoid(tx) {
		var reason = el('input', { class: 'gcrd-input', type: 'text', maxlength: '200', id: 'gcrd-void-reason', autofocus: true });
		var msg = el('p', { class: 'gcrd-msg', role: 'alert', hidden: true });
		var ok = el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--danger', text: T.confirmVoid });
		ok.addEventListener('click', function () {
			if (!reason.value.trim()) { return showMsg(msg, T.voidReason); }
			ok.disabled = true;
			api('POST', '/transactions/' + tx.id + '/void', { reason: reason.value.trim() }).then(function () {
				closeModal();
				lookup(state.card.code);
				loadToday();
			}).catch(function (e) { ok.disabled = false; showMsg(msg, e.message); });
		});
		openModal([
			el('h2', { id: 'gcrd-modal-title', text: T.void + ' · ' + (T['type_' + tx.type] || tx.type) + ' ' + money(tx.amount) }),
			el('dl', { class: 'gcrd-summary' }, [summaryRow(T.cardCode, tx.code), summaryRow(T.dateTime, tx.created_html), tx.outlet ? summaryRow(T.outlet, tx.outlet) : null]),
			el('label', { class: 'gcrd-label', for: 'gcrd-void-reason', text: T.voidReason }),
			reason, msg,
			el('div', { class: 'gcrd-modal-actions', style: 'margin-top:16px' }, [el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--ghost', text: T.cancel, onclick: closeModal }), ok])
		]);
	}

	/* ---------- today ---------- */
	function loadToday() {
		var list = $('gcrd-today');
		if (!list) { return; }
		var q = outlet() ? '?outlet=' + encodeURIComponent(outlet()) : '';
		api('GET', '/transactions' + q).then(function (data) {
			clear(list);
			$('gcrd-today-total').textContent = data.rows.length ? T.todayTotal + ' ' + money(data.redeemed_total) : '';
			if (!data.rows.length) { list.appendChild(el('li', { class: 'gcrd-empty', text: T.nothingToday })); return; }
			data.rows.forEach(function (tx) { list.appendChild(txItem(tx, false)); });
		}).catch(function () {});
	}

	/* ---------- print ---------- */
	function printSlip(tx) {
		var slip = clear($('gcrd-slip'));
		var logo = document.querySelector('.gcrd-logo');
		if (logo) { slip.appendChild(el('img', { src: logo.src, alt: '' })); }
		slip.appendChild(el('h1', { text: C.brand }));
		slip.appendChild(el('div', { class: 'gcrd-center', text: T.slipTitle }));
		slip.appendChild(el('dl', {}, [
			summaryRow(T.dateTime, tx.created_html),
			summaryRow(T.cardCode, tx.code),
			summaryRow(T['type_' + tx.type], money(tx.amount)),
			summaryRow(T.newBalance, money(tx.balance_after)),
			tx.outlet ? summaryRow(T.outlet, tx.outlet) : null,
			tx.reference ? summaryRow(T.ref, tx.reference) : null,
			summaryRow(T.staff, tx.user)
		]));
		slip.appendChild(el('div', { class: 'gcrd-sign', text: '' }));
		window.print();
	}

	/* ---------- scanner ---------- */
	var scanStream = null, scanTimer = null;
	function stopScan() {
		if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
		if (scanStream) { scanStream.getTracks().forEach(function (t) { t.stop(); }); scanStream = null; }
	}
	function startScan() {
		var supported = 'BarcodeDetector' in window && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
		if (!supported) {
			openModal([el('h2', { id: 'gcrd-modal-title', text: T.scan }), el('p', { text: T.scanUnsupported }), el('div', { class: 'gcrd-modal-actions' }, [el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--primary', text: T.close, onclick: closeModal })])]);
			return;
		}
		var video = el('video', { playsinline: true, muted: true, autoplay: true });
		var msg = el('p', { class: 'gcrd-msg', role: 'alert', hidden: true });
		openModal([
			el('h2', { id: 'gcrd-modal-title', text: T.scan }),
			el('div', { class: 'gcrd-video-wrap' }, [video]),
			el('p', { class: 'gcrd-empty', text: T.scanHint }),
			msg,
			el('div', { class: 'gcrd-modal-actions', style: 'margin-top:12px' }, [el('button', { type: 'button', class: 'gcrd-btn gcrd-btn--ghost', text: T.close, onclick: closeModal })])
		], stopScan);
		var detector;
		Promise.resolve().then(function () {
			return window.BarcodeDetector.getSupportedFormats ? window.BarcodeDetector.getSupportedFormats() : ['qr_code'];
		}).then(function (formats) {
			if (formats.indexOf('qr_code') === -1) { throw new Error('noqr'); }
			detector = new window.BarcodeDetector({ formats: ['qr_code'] });
			return navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
		}).then(function (stream) {
			scanStream = stream;
			video.srcObject = stream;
			video.muted = true;
			return video.play();
		}).then(function () {
			var tick = function () {
				if (!scanStream) { return; }
				detector.detect(video).then(function (codes) {
					if (codes && codes.length) {
						var raw = codes[0].rawValue || '';
						var code = raw;
						try { var u = new URL(raw); code = u.searchParams.get('code') || raw; } catch (e) { /* not a URL */ }
						closeModal();
						$('gcrd-code').value = code;
						lookup(code);
						return;
					}
					scanTimer = setTimeout(tick, 250);
				}).catch(function () { scanTimer = setTimeout(tick, 400); });
			};
			tick();
		}).catch(function (e) {
			stopScan();
			showMsg(msg, e && e.message === 'noqr' ? T.scanUnsupported : T.cameraDenied);
		});
	}

	/* ---------- boot ---------- */
	function boot() {
		if (!$('gcrd-search-form')) { return; }
		$('gcrd-code').placeholder = T.codePlaceholder;
		$('gcrd-lookup').textContent = T.lookup;
		$('gcrd-scan').setAttribute('aria-label', T.scan);
		document.querySelector('#gcrd-scan .gcrd-btn-text').textContent = T.scan;
		$('gcrd-today-title').textContent = T.today;
		initOutlets();
		$('gcrd-search-form').addEventListener('submit', function (e) { e.preventDefault(); lookup($('gcrd-code').value); });
		$('gcrd-scan').addEventListener('click', startScan);
		loadToday();

		var params = new URLSearchParams(window.location.search);
		var code = params.get('code');
		if (code) {
			$('gcrd-code').value = code;
			lookup(code);
			params.delete('code');
			var qs = params.toString();
			try { history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '')); } catch (e) { /* ignore */ }
		} else {
			$('gcrd-code').focus();
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
