/**
 * Open World — Auto-Translate Controller
 *
 * Fires sequential AJAX batch calls to translate untranslated strings.
 * Supports both DeepL and Google Translate (Free) providers.
 * Shows real-time progress bar, char count, and supports stop/resume.
 */
(function () {
	'use strict';

	const cfg     = window.owDeepL || {};
	const i18n    = cfg.i18n || {};
	const ajaxurl = cfg.ajaxurl;
	const nonce   = cfg.nonce;
	const saveNonce = cfg.saveNonce || nonce;

	const elLang       = document.getElementById('ow-at-lang');
	const elDomain     = document.getElementById('ow-at-domain');
	const elSourceType = document.getElementById('ow-at-source-type');
	const elSource     = document.getElementById('ow-at-source');
	const elPreviewBtn = document.getElementById('ow-at-preview');
	const elPreviewInfo = document.getElementById('ow-at-preview-info');
	const elStartBtn   = document.getElementById('ow-at-start');
	const elStopBtn    = document.getElementById('ow-at-stop');
	const elProgressBar = document.getElementById('ow-at-progress-bar');
	const elStatus     = document.getElementById('ow-at-status');
	const elResult     = document.getElementById('ow-at-result');
	const elResultText = document.getElementById('ow-at-result-text');
	const elRejectedPanel = document.getElementById('ow-rejected-panel');
	const elRejectedCount = document.getElementById('ow-rejected-count');
	const elRejectedTableBody = document.querySelector('#ow-rejected-table tbody');
	const elRetryAllWarnings = document.getElementById('ow-retranslate-all-warnings');

	if (!elLang || !elStartBtn) return;

	let running      = false;
	let totalStrings  = 0;
	let doneStrings   = 0;
	let totalChars    = 0;

	function getFilters() {
		return {
			lang:        elLang.value,
			domain:      elDomain.value,
			source_type: elSourceType.value,
			source:      elSource.value,
		};
	}

	function updateProgress() {
		const pct = totalStrings > 0 ? Math.round(doneStrings / totalStrings * 100) : 0;
		elProgressBar.style.width = pct + '%';
		elProgressBar.textContent = pct + '%';
		elStatus.textContent =
			doneStrings.toLocaleString() + ' ' + i18n.of + ' ' + totalStrings.toLocaleString() +
			' ' + i18n.strings + ' — ' + totalChars.toLocaleString() + ' ' + i18n.chars_used;
	}

	function showResult(msg, isError) {
		elResult.style.display = 'block';
		elResult.className = 'notice ' + (isError ? 'notice-error' : 'notice-success');
		elResultText.textContent = msg;
	}

	function updateRejectedCount() {
		if (!elRejectedPanel || !elRejectedCount || !elRejectedTableBody) return;

		const count = elRejectedTableBody.querySelectorAll('tr').length;
		elRejectedCount.textContent = count.toLocaleString();
		elRejectedPanel.style.display = count > 0 ? 'block' : 'none';
	}

	function clearRejectedRows() {
		if (!elRejectedTableBody) return;
		elRejectedTableBody.innerHTML = '';
		updateRejectedCount();
	}

	function addRejectedRows(warnings) {
		if (!elRejectedTableBody || !Array.isArray(warnings)) return;

		warnings.forEach(function (warning) {
			if (!warning || !warning.id) return;

			const existing = elRejectedTableBody.querySelector('tr[data-id="' + warning.id + '"]');
			if (existing) {
				existing.querySelector('.ow-rejected-translation').textContent = warning.translation || '';
				existing.querySelector('.ow-rejected-reason').textContent = warning.reason || '';
				return;
			}

			const tr = document.createElement('tr');
			tr.dataset.id = String(warning.id);
			tr.innerHTML =
				'<td class="ow-rejected-original"></td>' +
				'<td class="ow-rejected-translation"></td>' +
				'<td class="ow-rejected-reason"></td>' +
				'<td><button type="button" class="button button-small ow-rejected-translate-now">' + (i18n.translate_now || 'Translate now') + '</button></td>';
			tr.querySelector('.ow-rejected-original').textContent = warning.original || '';
			tr.querySelector('.ow-rejected-translation').textContent = warning.translation || '';
			tr.querySelector('.ow-rejected-reason').textContent = warning.reason || '';
			elRejectedTableBody.appendChild(tr);
		});

		updateRejectedCount();
	}

	async function retranslateSingle(id) {
		const body = new URLSearchParams({
			action: 'ow_retranslate_single',
			id: id,
			_ajax_nonce: saveNonce
		});

		const response = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
		const data = await response.json();

		if (!data.success) {
			throw new Error(data.data || 'translate_failed');
		}

		const warning = data.data.warning || {};
		const hasWarning = warning && !warning.ignored && (warning.placeholder || warning.html);
		const row = elRejectedTableBody ? elRejectedTableBody.querySelector('tr[data-id="' + id + '"]') : null;

		if (row) {
			if (hasWarning) {
				row.querySelector('.ow-rejected-translation').textContent = data.data.msgstr || '';
				row.querySelector('.ow-rejected-reason').textContent = (data.data.warning_messages || []).join(' | ');
			} else {
				row.remove();
			}
			updateRejectedCount();
		}

		return data;
	}

	async function preview() {
		elPreviewInfo.textContent = '...';

		const body = new URLSearchParams({
			action: 'ow_deepl_preview',
			_ajax_nonce: nonce,
			...getFilters()
		});

		try {
			const resp = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
			const data = await resp.json();
			if (data.success) {
				const count = data.data.count;
				totalStrings = count;
				doneStrings = 0;
				totalChars = 0;
				updateProgress();
				elPreviewInfo.textContent = count > 0
					? count.toLocaleString() + ' ' + i18n.strings
					: i18n.no_untranslated;
			} else {
				elPreviewInfo.textContent = '⚠ ' + (data.data || 'Error');
			}
		} catch (e) {
			elPreviewInfo.textContent = '⚠ Network error';
		}
	}

	async function translateLoop() {
		running = true;
		elStartBtn.disabled = true;
		elStopBtn.disabled  = false;
		elResult.style.display = 'none';
		clearRejectedRows();

		if (totalStrings === 0) {
			await preview();
			if (totalStrings === 0) {
				showResult(i18n.no_untranslated, false);
				running = false;
				elStartBtn.disabled = false;
				elStopBtn.disabled  = true;
				return;
			}
		}

		let consecutiveErrors = 0;

		while (running) {
			elStatus.textContent = i18n.translating;

			const activeProvider = (window.owDeepL && window.owDeepL.provider) ? window.owDeepL.provider : 'deepl';
			const translateAction = activeProvider === 'google_free' ? 'ow_google_free_translate' : 'ow_deepl_translate';

			const body = new URLSearchParams({
				action: translateAction,
				_ajax_nonce: nonce,
				...getFilters()
			});

			try {
				const resp = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
				const data = await resp.json();

				if (!data.success) {
					consecutiveErrors++;
					if (consecutiveErrors > 5) {
						showResult(i18n.error + ' ' + (data.data || '?'), true);
						break;
					}
					const waitSecs = 5 * consecutiveErrors;
					elStatus.textContent = i18n.error + ' ' + (data.data || '?') + ` (Retrying in ${waitSecs}s...)`;
					await new Promise(resolve => setTimeout(resolve, waitSecs * 1000));
					continue;
				}

				const r = data.data;

				if (!r.ok) {
					if (r.error_type === 'network' || r.error_type === 'rate_limit') {
						consecutiveErrors++;
						if (consecutiveErrors > 5) {
							showResult(i18n.error + ' ' + (r.error || '?'), true);
							break;
						}
						const waitSecs = r.error_type === 'rate_limit' ? 10 * consecutiveErrors : 5 * consecutiveErrors;
						elStatus.textContent = i18n.error + ' ' + (r.error || '?') + ` (Retrying in ${waitSecs}s...)`;
						await new Promise(resolve => setTimeout(resolve, waitSecs * 1000));
						continue;
					} else {
						showResult(i18n.error + ' ' + (r.error || '?'), true);
						break;
					}
				}

				consecutiveErrors = 0;

				doneStrings += r.translated;
				totalChars  += r.chars_used;
				addRejectedRows(r.placeholder_warnings || []);
				updateProgress();

				if (r.remaining <= 0 || r.translated === 0) {
					showResult(
						i18n.done + ' ' + doneStrings.toLocaleString() + ' ' + i18n.strings +
						' (' + totalChars.toLocaleString() + ' ' + i18n.chars_used + ')',
						false
					);
					break;
				}
			} catch (e) {
				consecutiveErrors++;
				if (consecutiveErrors > 5) {
					showResult(i18n.error + ' Network error', true);
					break;
				}
				const waitSecs = 5 * consecutiveErrors;
				elStatus.textContent = i18n.error + ' Network fetch error (Retrying in ' + waitSecs + 's...)';
				await new Promise(resolve => setTimeout(resolve, waitSecs * 1000));
			}
		}

		if (!running) {
			showResult(i18n.stopped + ' ' + doneStrings.toLocaleString() + ' ' + i18n.strings, false);
		}

		running = false;
		elStartBtn.disabled = false;
		elStopBtn.disabled  = true;
	}

	// ── Event listeners ───────────────────────────────────────────────────────
	elPreviewBtn.addEventListener('click', preview);

	elStartBtn.addEventListener('click', function () {
		if (!running) translateLoop();
	});

	elStopBtn.addEventListener('click', function () {
		running = false;
		elStatus.textContent = i18n.stopped;
	});

	if (elRejectedTableBody) {
		elRejectedTableBody.addEventListener('click', async function (event) {
			const button = event.target.closest('.ow-rejected-translate-now');
			if (!button) return;

			const row = button.closest('tr');
			if (!row) return;

			button.disabled = true;
			try {
				await retranslateSingle(row.dataset.id);
			} catch (e) {
				button.disabled = false;
			}
		});
	}

	if (elRetryAllWarnings) {
		elRetryAllWarnings.addEventListener('click', async function () {
			const buttons = Array.from(document.querySelectorAll('.ow-rejected-translate-now'));
			elRetryAllWarnings.disabled = true;
			for (const button of buttons) {
				const row = button.closest('tr');
				if (!row) continue;
				button.disabled = true;
				try {
					await retranslateSingle(row.dataset.id);
				} catch (e) {
					button.disabled = false;
				}
			}
			elRetryAllWarnings.disabled = false;
		});
	}

})();
