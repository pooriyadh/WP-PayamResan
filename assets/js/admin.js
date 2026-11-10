/* WP PayamResan Admin JS */
(function ($) {
	'use strict';

	var cfg = window.wpPayamResanAdmin || {};
	var i18n = cfg.i18n || {};

	function escHtml(str) {
		return String(str === null || str === undefined ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function renderAlert(type, title, details) {
		var html = '<div class="wppr-alert wppr-alert-' + escHtml(type) + '">';
		html += '<strong>' + escHtml(title) + '</strong>';
		if (Array.isArray(details) && details.length > 0) {
			html += '<ul>';
			details.forEach(function (item) {
				html += '<li>' + escHtml(item) + '</li>';
			});
			html += '</ul>';
		}
		html += '</div>';
		return html;
	}

	function setBtnLoading($btn, loading) {
		if (loading) {
			$btn.prop('disabled', true).addClass('is-loading');
		} else {
			$btn.prop('disabled', false).removeClass('is-loading');
		}
	}

	function insertAtCursor(textarea, text) {
		if (!textarea) {
			return;
		}
		var start = textarea.selectionStart;
		var end = textarea.selectionEnd;
		var val = textarea.value || '';

		if (typeof start === 'number' && typeof end === 'number') {
			textarea.value = val.substring(0, start) + text + val.substring(end);
			textarea.selectionStart = textarea.selectionEnd = start + text.length;
		} else {
			textarea.value = val + text;
		}
		textarea.focus();
	}

	function renderPreviewHtml(data) {
		var html = '';
		if (data.image_url) {
			html += '<div class="wppr-alert wppr-alert-info"><strong>تصویر انتخاب‌شده:</strong> <a href="' +
				escHtml(data.image_url) + '" target="_blank" rel="noopener noreferrer">' +
				escHtml(data.image_url) + '</a></div>';
		} else {
			html += '<div class="wppr-alert wppr-alert-info">تصویری برای این مطلب تنظیم نشده است (ارسال به صورت متنی).</div>';
		}

		html += '<div class="wppr-preview-grid">';
		html += '<div class="wppr-preview-pane is-telegram"><div class="wppr-preview-pane-head"><span class="wppr-dot"></span><h4>خروجی تلگرام (HTML)</h4></div><pre>' + escHtml(data.telegram_text || '') + '</pre></div>';
		html += '<div class="wppr-preview-pane is-bale"><div class="wppr-preview-pane-head"><span class="wppr-dot"></span><h4>خروجی بله (Markdown)</h4></div><pre>' + escHtml(data.bale_text || '') + '</pre></div>';
		html += '</div>';

		if (Array.isArray(data.meta_samples) && data.meta_samples.length > 0) {
			html += '<div class="wppr-table-scroll"><table class="wppr-table wppr-preview-meta-table"><thead><tr><th>کلید متا</th><th>برچسب</th><th>منبع</th><th>مقدار خوانده‌شده</th></tr></thead><tbody>';
			data.meta_samples.forEach(function (m) {
				html += '<tr><td><code>{meta:' + escHtml(m.key) + '}</code></td><td>' +
					escHtml(m.label) + '</td><td>' + escHtml(m.source) + '</td><td>' + escHtml(m.value) + '</td></tr>';
			});
			html += '</tbody></table></div>';
		}
		return html;
	}

	function formatDispatchReport(res) {
		var payload = res && res.data ? res.data : {};
		var lines = [];
		if (payload.results && typeof payload.results === 'object') {
			Object.keys(payload.results).forEach(function (messenger) {
				var label = messenger === 'telegram' ? 'تلگرام' : 'بله';
				var items = payload.results[messenger] || [];
				items.forEach(function (item) {
					var ch = item.channel_id || '-';
					if (item.success) {
						lines.push(label + ' (' + ch + '): ارسال موفق — شناسه پیام ' + (item.message_id || '-'));
						if (item.warning) {
							lines.push('هشدار: ' + item.warning);
						}
					} else {
						lines.push(label + ' (' + ch + '): خطا — ' + (item.error || 'نامشخص'));
					}
				});
			});
		}
		return renderAlert(res && res.success ? 'success' : 'error', payload.message || 'عملیات انجام شد.', lines);
	}

	function applyCptFilters() {
		var query = ($('#wppr-cpt-search').val() || '').trim().toLowerCase();
		var mode = $('.wppr-filter-pill.is-active').data('cpt-filter') || 'all';

		$('.wppr-pt-card').each(function () {
			var $card = $(this);
			var haystack = String($card.attr('data-cpt-search') || '').toLowerCase();
			var isEnabled = $card.attr('data-cpt-enabled') === '1';

			var matchesQuery = !query || haystack.indexOf(query) !== -1;
			var matchesMode = mode === 'all' || (mode === 'enabled' && isEnabled);

			if (matchesQuery && matchesMode) {
				$card.show();
			} else {
				$card.hide();
			}
		});
	}

	$(document).ready(function () {
		// Toggle token visibility.
		$(document).on('click', '.wppr-toggle-secret', function () {
			var $btn = $(this);
			var $input = $($btn.data('target'));
			if (!$input.length) {
				return;
			}
			if ($input.attr('type') === 'password') {
				$input.attr('type', 'text');
				$btn.text('مخفی');
			} else {
				$input.attr('type', 'password');
				$btn.text('نمایش');
			}
		});

		// Test connection for Telegram / Bale.
		$(document).on('click', '.wppr-test-connection-btn', function () {
			var $btn = $(this);
			var $output = $($btn.data('output'));
			setBtnLoading($btn, true);
			$output.html(renderAlert('info', i18n.testing || 'در حال بررسی...'));

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_test_connection',
				nonce: cfg.nonce,
				messenger: $btn.data('messenger'),
				bot_token: $($btn.data('token-input')).val(),
				channel_ids: $($btn.data('channels-input')).val(),
				api_base_url: $($btn.data('base-input')).val()
			})
			.done(function (res) {
				var data = res && res.data ? res.data : {};
				var details = [];
				if (data.bot && data.bot.first_name) {
					details.push('نام ربات: ' + data.bot.first_name + (data.bot.username ? ' (' + data.bot.username + ')' : ''));
				}
				if (Array.isArray(data.channels)) {
					data.channels.forEach(function (ch) {
						details.push((ch.success ? '✓ ' : '✕ ') + ch.channel_id + ': ' + ch.message);
					});
				}
				$output.html(renderAlert(res.success ? 'success' : 'error', data.message || '', details));
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: (i18n.networkError || 'خطا در ارتباط.');
				$output.html(renderAlert('error', msg));
			})
			.always(function () {
				setBtnLoading($btn, false);
			});
		});

		// Send live test message for Telegram / Bale.
		$(document).on('click', '.wppr-send-test-btn', function () {
			var $btn = $(this);
			var $output = $($btn.data('output'));
			setBtnLoading($btn, true);
			$output.html(renderAlert('info', i18n.sendingTest || 'در حال ارسال...'));

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_send_test_message',
				nonce: cfg.nonce,
				messenger: $btn.data('messenger'),
				bot_token: $($btn.data('token-input')).val(),
				channel_ids: $($btn.data('channels-input')).val(),
				api_base_url: $($btn.data('base-input')).val(),
				test_message: $($btn.data('message-input')).val()
			})
			.done(function (res) {
				var data = res && res.data ? res.data : {};
				var details = [];
				if (Array.isArray(data.results)) {
					data.results.forEach(function (r) {
						if (r.success) {
							details.push('✓ ' + r.channel_id + ' — شناسه پیام: ' + (r.message_id || '-'));
						} else {
							details.push('✕ ' + r.channel_id + ' — ' + (r.error || 'خطا'));
						}
					});
				}
				$output.html(renderAlert(res.success ? 'success' : 'error', data.message || '', details));
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
					? xhr.responseJSON.data.message
					: (i18n.networkError || 'خطا در ارتباط.');
				$output.html(renderAlert('error', msg));
			})
			.always(function () {
				setBtnLoading($btn, false);
			});
		});

		// Accordion toggle for Post Type cards (click button or title group).
		$(document).on('click', '.wppr-pt-toggle-body, .wppr-pt-title-group', function (e) {
			if ($(e.target).closest('.wppr-pt-toggle-body').length && $(this).hasClass('wppr-pt-title-group')) {
				return;
			}
			var $card = $(this).closest('.wppr-pt-card');
			$card.toggleClass('is-open');
			$card.find('.wppr-pt-toggle-body').attr('aria-expanded', $card.hasClass('is-open') ? 'true' : 'false');
		});

		// Sync enabled state styling & filter attribute on CPT toggle change.
		$(document).on('change', '.wppr-cpt-enable-toggle', function () {
			var $card = $(this).closest('.wppr-pt-card');
			var enabled = $(this).is(':checked');
			$card.toggleClass('is-enabled-cpt', enabled);
			$card.attr('data-cpt-enabled', enabled ? '1' : '0');
		});

		// CPT live search and filter pills.
		$(document).on('input', '#wppr-cpt-search', applyCptFilters);
		$(document).on('click', '.wppr-filter-pill', function () {
			$('.wppr-filter-pill').removeClass('is-active');
			$(this).addClass('is-active');
			applyCptFilters();
		});

		// Toggle custom image meta key input.
		$(document).on('change', '.wppr-img-source-select', function () {
			var $row = $(this).closest('.wppr-pt-options').find('.wppr-img-meta-row');
			if ($(this).val() === 'meta') {
				$row.slideDown(150);
			} else {
				$row.slideUp(150);
			}
		});

		// Insert placeholder chip at cursor position.
		$(document).on('click', '.wppr-insert-tag', function () {
			var target = $(this).data('target');
			var tag = $(this).data('tag');
			var el = document.querySelector(target);
			insertAtCursor(el, tag);
		});

		// Load hidden underscore meta fields via AJAX.
		$(document).on('click', '.wppr-load-hidden-meta', function () {
			var $btn = $(this);
			var postType = $btn.data('post-type');
			var target = $btn.data('target');
			var $container = $('#wppr-meta-chips-' + postType);

			$btn.prop('disabled', true).text(i18n.loadingMeta || 'در حال دریافت...');

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_get_post_type_meta',
				nonce: cfg.nonce,
				post_type: postType,
				include_hidden: 1
			})
			.done(function (res) {
				if (!res || !res.success || !res.data || !Array.isArray(res.data.meta_fields)) {
					return;
				}
				var fields = res.data.meta_fields;
				if (fields.length === 0) {
					$container.html('<span class="wppr-help-text">متادیتایی یافت نشد.</span>');
					return;
				}
				var html = '';
				fields.forEach(function (f) {
					html += '<button type="button" class="wppr-chip wppr-insert-tag wppr-meta-chip" ' +
						'data-target="' + escHtml(target) + '" ' +
						'data-tag="' + escHtml('{meta:' + f.key + '}') + '" ' +
						'title="' + escHtml((f.label || f.key) + ' (' + (f.source || '') + ')') + '">' +
						'<span class="wppr-chip-key">' + escHtml(f.key) + '</span>' +
						'<span class="wppr-chip-src">' + escHtml(f.source || 'Meta') + '</span>' +
						'</button>';
				});
				$container.html(html);
				$btn.hide();
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
		});

		// Per-CPT Live Template Preview.
		$(document).on('click', '.wppr-preview-btn', function () {
			var $btn = $(this);
			var postId = $($btn.data('post-select')).val();
			var template = $($btn.data('template')).val();
			var $out = $($btn.data('output'));

			if (!postId || postId === '0') {
				$out.html(renderAlert('error', 'مطلبی برای پیش‌نمایش در این پست تایپ وجود ندارد.'));
				return;
			}

			setBtnLoading($btn, true);
			$out.html(renderAlert('info', i18n.loadingPreview || 'در حال بارگذاری...'));

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_preview_message',
				nonce: cfg.nonce,
				post_id: postId,
				template: template
			})
			.done(function (res) {
				if (res && res.success && res.data) {
					$out.html(renderPreviewHtml(res.data));
				} else {
					$out.html(renderAlert('error', (res && res.data && res.data.message) || 'خطا در پیش‌نمایش.'));
				}
			})
			.fail(function () {
				$out.html(renderAlert('error', i18n.networkError || 'خطا در ارتباط.'));
			})
			.always(function () {
				setBtnLoading($btn, false);
			});
		});

		// Manual Send Tab: reload posts when Post Type changes.
		$(document).on('change', '#wppr-manual-pt', function () {
			var postType = $(this).val();
			var $select = $('#wppr-manual-post');
			$select.prop('disabled', true).html('<option value="0">در حال بارگذاری...</option>');

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_get_posts',
				nonce: cfg.nonce,
				post_type: postType
			})
			.done(function (res) {
				if (res && res.success && res.data && Array.isArray(res.data.posts)) {
					var posts = res.data.posts;
					if (posts.length === 0) {
						$select.html('<option value="0">مطلبی یافت نشد</option>');
					} else {
						var opts = '';
						posts.forEach(function (p) {
							opts += '<option value="' + escHtml(p.id) + '">#' + escHtml(p.id) + ' — ' + escHtml(p.title) + ' (' + escHtml(p.status) + ')</option>';
						});
						$select.html(opts);
					}
				}
			})
			.always(function () {
				$select.prop('disabled', false);
			});
		});

		function getManualSelectedPostId() {
			var customId = parseInt($('#wppr-manual-post-id').val(), 10);
			if (customId > 0) {
				return customId;
			}
			return parseInt($('#wppr-manual-post').val(), 10) || 0;
		}

		// Manual Send Tab: Preview button.
		$(document).on('click', '#wppr-manual-preview-btn', function () {
			var $btn = $(this);
			var postId = getManualSelectedPostId();
			var $out = $('#wppr-manual-preview-output');

			if (!postId) {
				$out.html(renderAlert('error', 'لطفاً یک نوشته انتخاب کنید یا شناسه عددی وارد نمایید.'));
				return;
			}

			setBtnLoading($btn, true);
			$out.html(renderAlert('info', i18n.loadingPreview || 'در حال بارگذاری...'));

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_preview_message',
				nonce: cfg.nonce,
				post_id: postId,
				template: $('#wppr-manual-tpl').val()
			})
			.done(function (res) {
				if (res && res.success && res.data) {
					$out.html(renderPreviewHtml(res.data));
				} else {
					$out.html(renderAlert('error', (res && res.data && res.data.message) || 'خطا در پیش‌نمایش.'));
				}
			})
			.fail(function () {
				$out.html(renderAlert('error', i18n.networkError || 'خطا در ارتباط.'));
			})
			.always(function () {
				setBtnLoading($btn, false);
			});
		});

		// Manual Send Tab: Send button.
		$(document).on('click', '#wppr-manual-send-btn', function () {
			var $btn = $(this);
			var postId = getManualSelectedPostId();
			var $out = $('#wppr-manual-result');
			var messengers = [];

			$('.wppr-manual-messenger:checked').each(function () {
				messengers.push($(this).val());
			});

			if (!postId) {
				$out.html(renderAlert('error', 'لطفاً یک نوشته انتخاب کنید.'));
				return;
			}

			if (messengers.length === 0) {
				$out.html(renderAlert('error', 'حداقل یک پیام‌رسان فعال را انتخاب کنید.'));
				return;
			}

			setBtnLoading($btn, true);
			$out.html(renderAlert('info', i18n.sendingPost || 'در حال ارسال...'));

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_manual_send',
				nonce: cfg.nonce,
				post_id: postId,
				messengers: messengers,
				custom_template: $('#wppr-manual-tpl').val(),
				skip_image: $('#wppr-manual-skip-img').is(':checked') ? 1 : 0,
				force: 1
			})
			.done(function (res) {
				$out.html(formatDispatchReport(res));
			})
			.fail(function (xhr) {
				if (xhr.responseJSON) {
					$out.html(formatDispatchReport(xhr.responseJSON));
				} else {
					$out.html(renderAlert('error', i18n.networkError || 'خطا در ارتباط.'));
				}
			})
			.always(function () {
				setBtnLoading($btn, false);
			});
		});

		// Post Editor Metabox: Manual Send button.
		$(document).on('click', '.wppr-mb-send-btn', function () {
			var $btn = $(this);
			var $box = $btn.closest('.wppr-metabox');
			var $out = $box.find('.wppr-mb-feedback');
			var postId = $btn.data('post-id');
			var messengers = [];

			$box.find('.wppr-mb-messenger:checked').each(function () {
				messengers.push($(this).val());
			});

			if (messengers.length === 0) {
				$out.html(renderAlert('error', 'حداقل یک پیام‌رسان را انتخاب کنید.'));
				return;
			}

			setBtnLoading($btn, true);
			$out.html(renderAlert('info', i18n.sendingPost || 'در حال ارسال...'));

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_manual_send',
				nonce: cfg.nonce,
				post_id: postId,
				messengers: messengers,
				custom_template: $('#wppr-custom-message').val(),
				force: 1
			})
			.done(function (res) {
				$out.html(formatDispatchReport(res));
			})
			.fail(function (xhr) {
				if (xhr.responseJSON) {
					$out.html(formatDispatchReport(xhr.responseJSON));
				} else {
					$out.html(renderAlert('error', i18n.networkError || 'خطا در ارتباط.'));
				}
			})
			.always(function () {
				setBtnLoading($btn, false);
			});
		});

		// Clear Logs button.
		$(document).on('click', '#wppr-clear-logs-btn', function () {
			if (!window.confirm(i18n.confirmClearLog || 'آیا مطمئن هستید؟')) {
				return;
			}
			var $btn = $(this);
			setBtnLoading($btn, true);

			$.post(cfg.ajaxUrl, {
				action: 'wp_payamresan_clear_logs',
				nonce: cfg.nonce
			})
			.done(function () {
				window.location.reload();
			})
			.always(function () {
				setBtnLoading($btn, false);
			});
		});
	});
})(jQuery);
