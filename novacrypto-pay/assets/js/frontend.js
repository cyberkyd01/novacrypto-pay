/* NovaCrypto Pay – frontend */
(function () {
	'use strict';
	var D = window.ncpData || {};
	var i18n = D.i18n || {};

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	function post(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', D.nonce);
		Object.keys(data || {}).forEach(function (k) {
			if (Array.isArray(data[k])) { data[k].forEach(function (v) { fd.append(k + '[]', v); }); }
			else { fd.append(k, data[k]); }
		});
		return fetch(D.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
	}

	function rest(path, method, body) {
		return fetch(D.rest + path, {
			method: method || 'GET',
			headers: { 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (r) { return r.json().then(function (j) { j.__ok = r.ok; return j; }); });
	}

	/* ---------- QR ---------- */
	function renderQR(el, text, size) {
		if (!el || !window.qrcode || !text) { return; }
		el.innerHTML = '';
		try {
			var q = window.qrcode(0, 'M');
			q.addData(text);
			q.make();
			var count = q.getModuleCount();
			var px = size || 184;
			var cell = px / count;
			var c = document.createElement('canvas');
			c.width = c.height = px;
			var ctx = c.getContext('2d');
			ctx.fillStyle = '#fff';
			ctx.fillRect(0, 0, px, px);
			ctx.fillStyle = '#000';
			for (var r = 0; r < count; r++) {
				for (var col = 0; col < count; col++) {
					if (q.isDark(r, col)) { ctx.fillRect(Math.round(col * cell), Math.round(r * cell), Math.ceil(cell), Math.ceil(cell)); }
				}
			}
			el.appendChild(c);
		} catch (e) { el.textContent = text; }
	}

	/* ---------- copy ---------- */
	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.ncp-copy');
		if (!btn) { return; }
		var text = btn.getAttribute('data-copy');
		var done = function () {
			btn.classList.add('is-copied');
			var old = btn.textContent;
			btn.textContent = i18n.copied || 'Copied!';
			setTimeout(function () { btn.classList.remove('is-copied'); btn.textContent = old; }, 1600);
		};
		if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); }); }
		else { fallbackCopy(text); done(); }
	});
	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
		document.body.appendChild(ta); ta.select();
		try { document.execCommand('copy'); } catch (e) {}
		document.body.removeChild(ta);
	}

	/* ---------- payment box ---------- */
	function fmt(sec) {
		if (sec <= 0) { return i18n.expired || 'Expired'; }
		var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
		return (h ? h + ':' : '') + (m < 10 && h ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}

	function initBox(box) {
		if (box.__ncp) { return; }
		box.__ncp = true;
		var uuid = box.getAttribute('data-uuid');
		var status = box.getAttribute('data-status');
		var expires = parseInt(box.getAttribute('data-expires'), 10) || 0;
		var uri = box.getAttribute('data-uri');
		var wallet = {};
		try { wallet = JSON.parse(box.getAttribute('data-wallet') || '{}'); } catch (e) {}
		var active = ['pending', 'detected', 'confirming', 'underpaid'].indexOf(status) !== -1;

		renderQR($('[data-ncp="qr"]', box), uri || $('[data-ncp="address"]', box) && $('[data-ncp="address"]', box).textContent);

		// countdown
		var cd = $('[data-ncp="countdown"]', box);
		var deadline = Date.now() + expires * 1000;
		var timer = null;
		if (cd && active) {
			var tick = function () {
				var left = Math.round((deadline - Date.now()) / 1000);
				cd.textContent = fmt(left);
				if (left <= 0) { clearInterval(timer); poll(true); }
			};
			tick();
			timer = setInterval(tick, 1000);
		}

		// polling
		var pollTimer = null;
		function apply(p) {
			if (!p) { return; }
			var badge = $('[data-ncp="status"]', box);
			if (badge) { badge.textContent = p.status_label; badge.className = 'ncp-badge ncp-badge-' + p.status; }
			var conf = $('[data-ncp="conf"]', box);
			if (conf) { conf.textContent = p.confirmations; }
			var txs = $('[data-ncp="txs"]', box);
			if (txs && p.txs) {
				txs.innerHTML = p.txs.map(function (t) {
					var id = t.txid.substring(0, 18) + '…';
					return '<div class="ncp-tx">' + (t.url ? '<a target="_blank" rel="noopener" href="' + t.url + '">' + id + '</a>' : id) + ' · ' + t.amount + ' ' + p.symbol + ' · ' + t.confirmations + ' conf</div>';
				}).join('');
			}
			var txt = $('[data-ncp="progress-text"]', box);
			if (txt) {
				if (p.status === 'detected' || p.status === 'confirming') { txt.textContent = 'Transaction seen. Waiting for ' + p.required_confirmations + ' confirmation(s)…'; }
				else if (p.status === 'underpaid') { txt.textContent = 'Underpaid: please send the remaining ' + p.remaining_crypto + ' ' + p.symbol + ' to the same address.'; }
			}
			if (p.status === 'underpaid' && parseFloat(p.remaining_crypto) > 0) {
				var amt = $('[data-ncp="amount"]', box);
				if (amt) { amt.textContent = p.remaining_crypto + ' ' + p.symbol; amt.nextElementSibling && amt.nextElementSibling.setAttribute('data-copy', p.remaining_crypto); }
			}
			box.className = box.className.replace(/ncp-status-\S+/, 'ncp-status-' + p.status);
			if (['paid', 'expired', 'cancelled', 'refunded'].indexOf(p.status) !== -1) {
				clearInterval(pollTimer); clearInterval(timer);
				if (p.status === 'paid' && p.return_url) {
					setTimeout(function () { window.location.href = p.return_url; }, 2500);
				}
				// Re-render the whole box from the server for the final view.
				setTimeout(function () { window.location.reload(); }, p.status === 'paid' && p.return_url ? 2400 : 800);
			}
		}
		function poll(force) {
			post('ncp_status', { uuid: uuid }).then(function (r) { if (r && r.success) { apply(r.data); } }).catch(function () {});
		}
		if (active) {
			pollTimer = setInterval(poll, D.pollInterval || 10000);
			setTimeout(poll, 2500);
		}

		// browser wallet (EIP-1193)
		var mm = $('[data-ncp="metamask"]', box);
		if (mm) {
			mm.addEventListener('click', function () {
				var eth = window.ethereum;
				if (!eth) { alert(i18n.noWallet || 'No wallet found'); return; }
				mm.disabled = true;
				eth.request({ method: 'eth_requestAccounts' }).then(function (accounts) {
					return eth.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: wallet.chainId }] }).catch(function (err) {
						if (err && (err.code === 4902 || (err.data && err.data.originalError && err.data.originalError.code === 4902))) {
							return eth.request({ method: 'wallet_addEthereumChain', params: [{ chainId: wallet.chainId, chainName: wallet.chainName, rpcUrls: [wallet.rpc], nativeCurrency: { name: wallet.symbol, symbol: wallet.symbol, decimals: 18 }, blockExplorerUrls: wallet.explorer ? [wallet.explorer] : undefined }] });
						}
						throw err;
					}).then(function () { return accounts; });
				}).then(function (accounts) {
					var from = accounts[0];
					var tx;
					if (wallet.contract) {
						var to = wallet.to.replace(/^0x/, '').toLowerCase().padStart(64, '0');
						var val = wallet.valueHex.replace(/^0x/, '').padStart(64, '0');
						tx = { from: from, to: wallet.contract, value: '0x0', data: '0xa9059cbb' + to + val };
					} else {
						tx = { from: from, to: wallet.to, value: wallet.valueHex };
					}
					return eth.request({ method: 'eth_sendTransaction', params: [tx] });
				}).then(function (hash) {
					var msg = $('[data-ncp="progress-text"]', box);
					if (msg) { msg.textContent = 'Transaction sent: ' + hash + ' — waiting for confirmation…'; }
					rest('payments/' + uuid + '/txid', 'POST', { txid: hash }).catch(function () {});
					setTimeout(poll, 4000);
				}).catch(function (err) {
					console.warn(err);
					alert((err && err.message) || i18n.walletError || 'Wallet error');
				}).then(function () { mm.disabled = false; });
			});
		}

		// tx hash submit
		var tf = $('[data-ncp="txid-form"]', box);
		if (tf) {
			tf.addEventListener('submit', function (e) {
				e.preventDefault();
				var msg = $('[data-ncp="txid-msg"]', box);
				msg.className = 'ncp-form-msg'; msg.textContent = i18n.loading || '…';
				rest('payments/' + uuid + '/txid', 'POST', { txid: tf.txid.value.trim() }).then(function (r) {
					if (r.__ok) { msg.className = 'ncp-form-msg is-ok'; msg.textContent = 'Thanks! We are checking the network for your transaction.'; apply(r.payment); }
					else { msg.className = 'ncp-form-msg is-error'; msg.textContent = r.message || (i18n.error || 'Error'); }
				});
			});
		}
		var rf = $('[data-ncp="refund-form"]', box);
		if (rf) {
			rf.addEventListener('submit', function (e) {
				e.preventDefault();
				var msg = $('[data-ncp="refund-msg"]', box);
				msg.className = 'ncp-form-msg';
				rest('payments/' + uuid + '/refund-address', 'POST', { address: rf.address.value.trim() }).then(function (r) {
					msg.className = 'ncp-form-msg ' + (r.__ok ? 'is-ok' : 'is-error');
					msg.textContent = r.__ok ? 'Saved.' : (r.message || (i18n.error || 'Error'));
				});
			});
		}
	}

	/* ---------- coin picker + quotes ---------- */
	function initPicker(picker) {
		if (picker.__ncp) { return; }
		picker.__ncp = true;
		var amountSrc = picker.closest('form') ? $('[data-ncp="custom-amount"]', picker.closest('form')) : null;
		var debounce = null;
		function quote() {
			var amount = amountSrc ? parseFloat(amountSrc.value) : parseFloat(picker.getAttribute('data-amount'));
			if (!amount || amount <= 0) { return; }
			var coins = $$('input[type=radio]', picker).map(function (i) { return i.value; });
			post('ncp_quote', { amount: amount, currency: picker.getAttribute('data-currency'), coins: coins }).then(function (r) {
				if (!r || !r.success) { return; }
				Object.keys(r.data).forEach(function (id) {
					var el = $('.ncp-coin-quote[data-coin="' + id + '"]', picker);
					var q = r.data[id];
					if (el) { el.textContent = q.amount ? ('≈ ' + q.amount + ' ' + q.symbol + (q.fee ? ' · fee ≈ ' + q.fee : '')) : '—'; }
				});
			});
		}
		quote();
		if (amountSrc) { amountSrc.addEventListener('input', function () { clearTimeout(debounce); debounce = setTimeout(quote, 500); }); }
		picker.addEventListener('change', function () {
			$$('.ncp-coin-option', picker).forEach(function (o) { o.classList.toggle('is-selected', o.querySelector('input').checked); });
		});
	}

	/* ---------- start forms (shortcode / donation / links) ---------- */
	function initStartForm(form) {
		if (form.__ncp) { return; }
		form.__ncp = true;
		var wrap = form.parentNode;
		$$('.ncp-preset', form).forEach(function (b) {
			b.addEventListener('click', function () {
				$$('.ncp-preset', form).forEach(function (x) { x.classList.remove('is-selected'); });
				b.classList.add('is-selected');
				var inp = $('[data-ncp="custom-amount"]', form);
				if (inp) { inp.value = b.getAttribute('data-amount'); inp.dispatchEvent(new Event('input')); }
			});
		});
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var msg = $('[data-ncp="form-msg"]', form);
			var btn = $('button[type=submit]', form);
			var data = {};
			new FormData(form).forEach(function (v, k) { data[k] = v; });
			if (!data.coin) { msg.className = 'ncp-form-msg is-error'; msg.textContent = 'Please choose a coin.'; return; }
			btn.disabled = true; msg.className = 'ncp-form-msg'; msg.textContent = i18n.loading || '…';
			post('ncp_start', data).then(function (r) {
				btn.disabled = false;
				if (!r || !r.success) { msg.className = 'ncp-form-msg is-error'; msg.textContent = (r && r.data && r.data.message) || (i18n.error || 'Error'); return; }
				msg.textContent = '';
				if (form.getAttribute('data-inline') === '1') {
					var target = $('[data-ncp="payment-target"]', wrap) || wrap;
					target.innerHTML = r.data.html;
					form.style.display = 'none';
					init(target);
					target.scrollIntoView({ behavior: 'smooth', block: 'start' });
					try { history.replaceState(null, '', r.data.url); } catch (e) {}
				} else {
					window.location.href = r.data.url;
				}
			}).catch(function () { btn.disabled = false; msg.className = 'ncp-form-msg is-error'; msg.textContent = i18n.error || 'Error'; });
		});
	}

	function init(root) {
		$$('.ncp-box[data-uuid]', root).forEach(initBox);
		$$('[data-ncp="coin-picker"]', root).forEach(initPicker);
		$$('[data-ncp="start-form"]', root).forEach(initStartForm);
		$$('.ncp-qr[data-qr]', root).forEach(function (el) { if (!el.__ncp) { el.__ncp = true; renderQR(el, el.getAttribute('data-qr'), 128); } });
	}

	window.ncpInit = init;
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { init(document); }); }
	else { init(document); }
	// WooCommerce classic checkout re-renders payment methods via AJAX.
	if (window.jQuery) { window.jQuery(document.body).on('updated_checkout payment_method_selected', function () { init(document); }); }
})();
