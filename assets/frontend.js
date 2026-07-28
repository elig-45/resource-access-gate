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
	var activeModal = null;
	var activeTrigger = null;

	function closestGate(element) {
		return element ? element.closest('.rag-resource-gate') : null;
	}

	function focusableElements(modal) {
		if (!modal) {
			return [];
		}

		return Array.prototype.slice.call(modal.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		)).filter(function (element) {
			return !element.hidden && element.offsetParent !== null;
		});
	}

	function openModal(modal, trigger) {
		if (!modal) {
			return;
		}

		if (activeModal && activeModal !== modal) {
			closeModal(activeModal);
		}

		activeModal = modal;
		activeTrigger = trigger || null;
		modal.hidden = false;
		document.body.classList.add('rag-modal-is-open');

		if (activeTrigger) {
			activeTrigger.setAttribute('aria-expanded', 'true');
		}

		window.requestAnimationFrame(function () {
			modal.classList.add('is-open');
			var emailInput = modal.querySelector('.rag-resource-form:not([hidden]) input[type="email"]');
			var resultLink = modal.querySelector('.rag-resource-result:not([hidden]) a');
			var dialog = modal.querySelector('.rag-resource-dialog');
			(emailInput || resultLink || dialog || modal).focus();
		});
	}

	function closeModal(modal) {
		if (!modal) {
			return;
		}

		modal.classList.remove('is-open');
		modal.hidden = true;
		document.body.classList.remove('rag-modal-is-open');

		if (activeTrigger) {
			activeTrigger.setAttribute('aria-expanded', 'false');
			activeTrigger.focus();
		}

		activeModal = null;
		activeTrigger = null;
	}

	function setMessage(form, message, isError) {
		var messageNode = form.querySelector('.rag-resource-message');
		if (!messageNode) {
			return;
		}

		messageNode.textContent = message;
		messageNode.hidden = false;
		messageNode.classList.toggle('is-error', !!isError);
	}

	document.addEventListener('click', function (event) {
		var opener = event.target.closest('[data-rag-open]');
		if (opener) {
			event.preventDefault();
			openModal(document.getElementById(opener.getAttribute('data-rag-open')), opener);
			return;
		}

		var closer = event.target.closest('[data-rag-close]');
		if (closer) {
			event.preventDefault();
			closeModal(closer.closest('.rag-resource-modal'));
		}
	});

	document.addEventListener('keydown', function (event) {
		if (!activeModal) {
			return;
		}

		if ('Escape' === event.key) {
			event.preventDefault();
			closeModal(activeModal);
			return;
		}

		if ('Tab' !== event.key) {
			return;
		}

		var focusable = focusableElements(activeModal);
		if (!focusable.length) {
			event.preventDefault();
			return;
		}

		var first = focusable[0];
		var last = focusable[focusable.length - 1];
		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	});

	document.addEventListener('submit', function (event) {
		var form = event.target.closest('.rag-resource-form');
		if (!form) {
			return;
		}

		event.preventDefault();

		var gate = closestGate(form);
		var emailInput = form.querySelector('input[type="email"]');
		var button = form.querySelector('button[type="submit"]');
		var result = gate ? gate.querySelector('.rag-resource-result') : null;
		var resultLink = result ? result.querySelector('a') : null;
		var resultMessage = result ? result.querySelector('.rag-resource-result-message') : null;
		var email = emailInput ? emailInput.value.trim() : '';
		var honeypotInput = form.querySelector('input[name="website"]');
		var languageInput = form.querySelector('input[name="resource_language"]');
		var resourceId = gate ? gate.getAttribute('data-resource-id') : '';
		var config = window.ResourceAccessGate || {};

		if (!emailInput || !emailInput.validity.valid || email.indexOf('@') === -1) {
			setMessage(form, config.invalidEmail || 'Adresse email invalide.', true);
			if (emailInput) {
				emailInput.focus();
			}
			return;
		}

		if (button) {
			button.disabled = true;
			button.dataset.originalText = button.textContent;
			button.textContent = config.loading || 'Vérification...';
		}

		if (result) {
			result.hidden = true;
		}

		var data = new FormData();
		data.append('action', 'rag_resource_access');
		data.append('nonce', config.nonce || '');
		data.append('email', email);
		data.append('resource_id', resourceId);
		data.append('resource_language', languageInput ? languageInput.value : '');
		data.append('website', honeypotInput ? honeypotInput.value : '');

		fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data || !payload.data.downloadUrl) {
				var serverMessage = payload && payload.data && payload.data.message ? payload.data.message : '';
				throw new Error(serverMessage || 'invalid-response');
			}

			if (resultMessage) {
				resultMessage.textContent = payload.data.message || '';
				resultMessage.classList.toggle('is-error', !payload.data.mailSent);
			}

			if (resultLink) {
				resultLink.href = payload.data.downloadUrl;
				resultLink.textContent = payload.data.downloadLabel || 'Télécharger le document';
			}

			form.hidden = true;
			if (result) {
				result.hidden = false;
				result.focus();
			}
		}).catch(function (error) {
			var message = error && error.message && 'invalid-response' !== error.message
				? error.message
				: (config.genericError || 'Erreur. Réessayez plus tard.');
			setMessage(form, message, true);
		}).finally(function () {
			if (button) {
				button.disabled = false;
				button.textContent = button.dataset.originalText || 'Recevoir le lien';
			}
		});
	});
})();

