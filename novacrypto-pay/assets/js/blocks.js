/* NovaCrypto Pay – Gutenberg blocks */
(function (wp) {
	if (!wp || !wp.blocks) { return; }
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody, TextControl = wp.components.TextControl, SelectControl = wp.components.SelectControl;
	var ServerSideRender = wp.serverSideRender;
	var coins = (window.ncpBlocks && window.ncpBlocks.coins) || [];
	var coinOptions = [{ label: __('All enabled coins', 'novacrypto-pay'), value: '' }].concat(coins.map(function (c) { return { label: c.name, value: c.id }; }));

	function register(name, title, icon, fields) {
		wp.blocks.registerBlockType('novacrypto/' + name, {
			title: title,
			icon: icon,
			category: 'novacrypto',
			edit: function (props) {
				var controls = fields.map(function (f) {
					if (f.type === 'coin') {
						return el(SelectControl, { key: f.key, label: f.label, value: props.attributes[f.key], options: coinOptions, onChange: function (v) { var o = {}; o[f.key] = v; props.setAttributes(o); } });
					}
					return el(TextControl, { key: f.key, label: f.label, value: props.attributes[f.key], onChange: function (v) { var o = {}; o[f.key] = v; props.setAttributes(o); } });
				});
				return el(wp.element.Fragment, {},
					el(InspectorControls, {}, el(PanelBody, { title: __('Settings', 'novacrypto-pay'), initialOpen: true }, controls)),
					el('div', { className: 'ncp-block-preview' }, el(ServerSideRender, { block: 'novacrypto/' + name, attributes: props.attributes }))
				);
			},
			save: function () { return null; }
		});
	}

	register('pay-button', __('Crypto Pay Button', 'novacrypto-pay'), 'money-alt', [
		{ key: 'amount', label: __('Amount', 'novacrypto-pay') }, { key: 'currency', label: __('Currency', 'novacrypto-pay') }, { key: 'title', label: __('Title', 'novacrypto-pay') },
		{ key: 'button', label: __('Button label', 'novacrypto-pay') }, { key: 'coins', label: __('Restrict to coin', 'novacrypto-pay'), type: 'coin' }, { key: 'ref', label: __('Reference', 'novacrypto-pay') }
	]);
	register('donation', __('Crypto Donation Box', 'novacrypto-pay'), 'heart', [
		{ key: 'title', label: __('Title', 'novacrypto-pay') }, { key: 'presets', label: __('Preset amounts (comma separated)', 'novacrypto-pay') }, { key: 'min', label: __('Minimum', 'novacrypto-pay') },
		{ key: 'currency', label: __('Currency', 'novacrypto-pay') }, { key: 'coins', label: __('Restrict to coin', 'novacrypto-pay'), type: 'coin' }, { key: 'button', label: __('Button label', 'novacrypto-pay') }
	]);
	register('wallets', __('Crypto Wallet Addresses', 'novacrypto-pay'), 'admin-site', [
		{ key: 'title', label: __('Title', 'novacrypto-pay') }, { key: 'coins', label: __('Restrict to coin', 'novacrypto-pay'), type: 'coin' }, { key: 'show_qr', label: __('Show QR (1/0)', 'novacrypto-pay') }
	]);
	register('price', __('Live Crypto Price', 'novacrypto-pay'), 'chart-line', [
		{ key: 'amount', label: __('Fiat amount', 'novacrypto-pay') }, { key: 'currency', label: __('Currency', 'novacrypto-pay') }, { key: 'coin', label: __('Coin', 'novacrypto-pay'), type: 'coin' }
	]);
	register('link', __('Crypto Payment Link', 'novacrypto-pay'), 'admin-links', [
		{ key: 'slug', label: __('Link slug', 'novacrypto-pay') }
	]);
})(window.wp);
