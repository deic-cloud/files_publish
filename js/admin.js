/* global OC, t */
(function () {
	'use strict';
	const OCS = (OC.webroot || '') + '/ocs/v2.php/apps/files_publish/api/v1';

	async function post(path, body) {
		const res = await fetch(OCS + path + '?format=json', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'OCS-APIREQUEST': 'true',
				'requesttoken': OC.requestToken,
			},
			body,
		});
		return res.json();
	}

	document.addEventListener('DOMContentLoaded', () => {
		// Affiliation is a global (target '') key, saved with whichever target button — fold into each save.
		document.querySelectorAll('#filesPublishAdmin .fp-save').forEach((btn) => {
			btn.addEventListener('click', async () => {
				const target = btn.dataset.target;
				const fs = btn.closest('.fp-target');
				const msg = fs.querySelector('.fp-msg');
				msg.textContent = t('files_publish', 'Saving…');

				const params = new URLSearchParams();
				params.append('target', target);
				params.append('format', 'json');
				fs.querySelectorAll('input[data-key]').forEach((inp) => {
					params.append('values[' + inp.dataset.key + ']', inp.value);
				});
				const data = await post('/config', params);
				msg.textContent = data?.ocs?.meta?.status === 'ok'
					? t('files_publish', 'Saved') : t('files_publish', 'Save failed');
				setTimeout(() => { msg.textContent = ''; }, 3000);
			});
		});

		// Global default affiliation: store under the empty-target namespace via a tiny dedicated call.
		const aff = document.getElementById('fpAffiliation');
		if (aff) {
			aff.addEventListener('change', () => {
				const params = new URLSearchParams();
				params.append('target', '');
				params.append('values[defaultAffiliation]', aff.value);
				params.append('format', 'json');
				post('/config', params);
			});
		}
	});
})();
