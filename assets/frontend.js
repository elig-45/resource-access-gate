(function () {
	function closestGate(element) {
		return element ? element.closest('.rag-resource-gate') : null;
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

	document.addEventListener('submit', function (event) {
		var form = event.target.closest('.rag-resource-form');
		if (!form) {
			return;
		}

		event.preventDefault();

		var gate = closestGate(form);
		var emailInput = form.querySelector('input[type="email"]');
		var button = form.querySelector('button[type="submit"]');
		var result = form.querySelector('.rag-resource-result');
		var resultLink = result ? result.querySelector('a') : null;
		var email = emailInput ? emailInput.value.trim() : '';
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

		fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data || !payload.data.downloadUrl) {
				throw new Error('invalid-response');
			}

			setMessage(form, payload.data.message || '', !payload.data.mailSent);

			if (resultLink) {
				resultLink.href = payload.data.downloadUrl;
				resultLink.textContent = payload.data.downloadLabel || 'Télécharger le document';
			}

			if (result) {
				result.hidden = false;
			}
		}).catch(function () {
			setMessage(form, config.genericError || 'Erreur. Réessayez plus tard.', true);
		}).finally(function () {
			if (button) {
				button.disabled = false;
				button.textContent = button.dataset.originalText || 'Recevoir le lien';
			}
		});
	});
})();

