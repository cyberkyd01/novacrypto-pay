/* NovaCrypto Pay – admin */
(function ($) {
	'use strict';
	// Tabs
	$('.ncp-tabs a').on('click', function (e) {
		e.preventDefault();
		var t = $(this).attr('href');
		$('.ncp-tabs a').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.ncp-tab-panel').hide();
		$(t).show();
		$('#ncp_active_tab').val(t.replace('#', ''));
		try { localStorage.setItem('ncp_tab', t); } catch (err) {}
	});
	var saved = $('#ncp_active_tab').val() ? '#' + $('#ncp_active_tab').val() : (function () { try { return localStorage.getItem('ncp_tab'); } catch (e) { return null; } })();
	if (saved && $(saved).length) { $('.ncp-tabs a[href="' + saved + '"]').trigger('click'); } else { $('.ncp-tabs a').first().trigger('click'); }

	// Repeaters (custom tokens / networks / webhook urls / manual rates)
	$(document).on('click', '.ncp-repeater-add', function () {
		var $rep = $(this).closest('.ncp-repeater');
		var tpl = $rep.find('template').html();
		var idx = $rep.find('tbody tr').length;
		$rep.find('tbody').append(tpl.replace(/__i__/g, idx));
	});
	$(document).on('click', '.ncp-repeater-remove', function () { $(this).closest('tr').remove(); });

	// HD preview
	$(document).on('click', '.ncp-hd-preview-btn', function () {
		var $btn = $(this), coin = $btn.data('coin'), $out = $btn.siblings('.ncp-hd-preview');
		var xpub = $btn.closest('.ncp-coin-fields').find('input[name$="[xpub]"]').val();
		var script = $btn.closest('.ncp-coin-fields').find('select[name$="[xpub_script]"]').val();
		$out.text('…').show();
		$.post(ajaxurl, { action: 'ncp_admin', task: 'hd_preview', nonce: ncpAdmin.nonce, coin: coin, xpub: xpub, script: script }, function (r) {
			$out.text(r.success ? r.data.join('\n') : (r.data && r.data.message ? r.data.message : 'Error'));
		});
	});
	// Validate address inline
	$(document).on('blur', 'textarea[name$="[addresses]"]', function () {
		var $ta = $(this), coin = $ta.data('coin');
		$.post(ajaxurl, { action: 'ncp_admin', task: 'validate', nonce: ncpAdmin.nonce, coin: coin, addresses: $ta.val() }, function (r) {
			var $m = $ta.siblings('.ncp-validate-msg');
			if (!$m.length) { $m = $('<p class="description ncp-validate-msg"></p>').insertAfter($ta); }
			$m.text(r.success ? r.data.message : (r.data && r.data.message)).css('color', r.success ? '#00761d' : '#b32d2e');
		});
	});
	// Test buttons
	$(document).on('click', '[data-ncp-task]', function (e) {
		e.preventDefault();
		var $b = $(this), task = $b.data('ncp-task'), $out = $('#' + $b.data('out'));
		$b.prop('disabled', true); $out.text('…');
		$.post(ajaxurl, { action: 'ncp_admin', task: task, nonce: ncpAdmin.nonce, coin: $b.data('coin') || '' }, function (r) {
			$out.html(r.success ? (typeof r.data === 'string' ? r.data : '<pre>' + JSON.stringify(r.data, null, 2) + '</pre>') : '<span style="color:#b32d2e">' + (r.data && r.data.message || 'Error') + '</span>');
			$b.prop('disabled', false);
		});
	});
	// Copy
	$(document).on('click', '.ncp-copy-btn', function () {
		var t = $(this).data('copy');
		if (navigator.clipboard) { navigator.clipboard.writeText(t); }
		$(this).text('Copied');
	});
	// Colour pickers
	if ($.fn.wpColorPicker) { $('.ncp-color').wpColorPicker(); }
	// Invoice: toggle custom amount
	$(document).on('change', '#ncp_link_type', function () {
		$('.ncp-invoice-only').toggle($(this).val() === 'invoice');
	}).trigger('change');
})(jQuery);
