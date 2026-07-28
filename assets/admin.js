/*
 * Copyright (C) 2026 Eli Gold
 *
 * This file is part of Resource Access Gate.
 *
 * Resource Access Gate is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * Resource Access Gate is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General
 * Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Resource Access Gate. If not, see <https://www.gnu.org/licenses/>.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

(function () {
	'use strict';

	var config = window.ResourceAccessGateAdmin || {};

	function shortcodeFromRow(row) {
		var idInput = row ? row.querySelector('.resoacga-resource-id-input') : null;
		var id = idInput ? idInput.value.trim().replace(/"/g, '') : '';
		var template = config.shortcodeTemplate || '[resource_access_gate id="%s"]';

		return id ? template.replace('%s', id) : '';
	}

	function updateShortcode(row) {
		var preview = row ? row.querySelector('.resoacga-shortcode-preview') : null;
		if (preview) {
			preview.value = shortcodeFromRow(row);
		}
	}

	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}

		return new Promise(function (resolve, reject) {
			var textarea = document.createElement('textarea');
			textarea.value = text;
			textarea.setAttribute('readonly', 'readonly');
			textarea.style.position = 'fixed';
			textarea.style.left = '-9999px';
			document.body.appendChild(textarea);
			textarea.select();

			try {
				if (document.execCommand('copy')) {
					resolve();
				} else {
					reject(new Error('copy-failed'));
				}
			} catch (error) {
				reject(error);
			} finally {
				document.body.removeChild(textarea);
			}
		});
	}

	document.addEventListener('input', function (event) {
		if (event.target.classList.contains('resoacga-resource-id-input')) {
			updateShortcode(event.target.closest('tr'));
		}
	});

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.resoacga-copy-shortcode');
		if (!button) {
			return;
		}

		var row = button.closest('tr');
		var feedback = row ? row.querySelector('.resoacga-copy-feedback') : null;
		var shortcode = shortcodeFromRow(row);
		updateShortcode(row);

		if (!shortcode) {
			if (feedback) {
				feedback.textContent = config.missingId || 'Ajoutez un ID.';
			}
			return;
		}

		copyText(shortcode).then(function () {
			if (feedback) {
				feedback.textContent = config.copied || 'Copié.';
				window.setTimeout(function () {
					feedback.textContent = '';
				}, 1800);
			}
		}).catch(function () {
			if (feedback) {
				feedback.textContent = config.copyFailed || 'Copie impossible.';
			}
		});
	});
}());
