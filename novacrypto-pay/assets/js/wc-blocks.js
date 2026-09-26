/* NovaCrypto Pay – WooCommerce Blocks checkout integration */
(function () {
	if (!window.wc || !window.wc.wcBlocksRegistry) { return; }
	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState, useEffect = window.wp.element.useEffect;
	var decode = window.wp.htmlEntities.decodeEntities;
	var data = getSetting('novacrypto_data', {});
	var label = decode(data.title || 'Cryptocurrency');

	function Content(props) {
		var eventRegistration = props.eventRegistration, emitResponse = props.emitResponse;
		var state = useState(data.coins && data.coins.length ? data.coins[0].id : '');
		var coin = state[0], setCoin = state[1];
		var quotes = useState({});
		var q = quotes[0], setQ = quotes[1];
		var total = props.billing && props.billing.cartTotal ? props.billing.cartTotal.value / Math.pow(10, props.billing.currency.minorUnit) : 0;
		var currency = props.billing && props.billing.currency ? props.billing.currency.code : '';

		useEffect(function () {
			if (!total) { return; }
			var fd = new FormData();
			fd.append('action', 'ncp_quote'); fd.append('nonce', data.nonce); fd.append('amount', total * (1 - (data.discount || 0) / 100)); fd.append('currency', currency);
			(data.coins || []).forEach(function (c) { fd.append('coins[]', c.id); });
			fetch(data.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); }).then(function (r) { if (r && r.success) { setQ(r.data); } }).catch(function () {});
		}, [total, currency]);

		useEffect(function () {
			var unsub = eventRegistration.onPaymentSetup(function () {
				if (!coin) { return { type: emitResponse.responseTypes.ERROR, message: 'Please choose a cryptocurrency.' }; }
				return { type: emitResponse.responseTypes.SUCCESS, meta: { paymentMethodData: { ncp_coin: coin } } };
			});
			return unsub;
		}, [eventRegistration, emitResponse, coin]);

		return el('div', { className: 'ncp-blocks-fields' },
			data.description ? el('p', {}, decode(data.description)) : null,
			data.discount > 0 ? el('p', { className: 'ncp-discount-note' }, 'Pay with crypto and get ' + data.discount + '% off.') : null,
			el('div', { className: 'ncp-coin-picker' }, (data.coins || []).map(function (c) {
				var quote = q[c.id];
				return el('label', { key: c.id, className: 'ncp-coin-option' + (coin === c.id ? ' is-selected' : ''), onClick: function () { setCoin(c.id); } },
					el('input', { type: 'radio', name: 'ncp_coin', value: c.id, checked: coin === c.id, onChange: function () { setCoin(c.id); } }),
					el('img', { src: c.icon, alt: '', className: 'ncp-coin-icon', onError: function (e) { e.target.style.visibility = 'hidden'; } }),
					el('span', { className: 'ncp-coin-name' }, c.name),
					el('span', { className: 'ncp-coin-net' }, c.network),
					el('span', { className: 'ncp-coin-quote' }, quote && quote.amount ? '≈ ' + quote.amount + ' ' + quote.symbol : '…')
				);
			}))
		);
	}

	registerPaymentMethod({
		name: 'novacrypto',
		label: el('span', {}, label),
		content: el(Content, {}),
		edit: el(Content, {}),
		canMakePayment: function () { return true; },
		ariaLabel: label,
		supports: { features: data.supports || ['products'] }
	});
})();
