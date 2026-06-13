/* global OC, OCA, t */
/**
 * Registers a "Publish" file action in the Files app. No build step: uses the
 * global OCA.Files.registerFileAction API exposed by the Files app at runtime.
 * Single-file and multi-select; opens a metadata dialog, then the target's
 * publish flow in a popup (OAuth where needed).
 */
(function () {
	'use strict';

	const OCS = (OC.webroot || '') + '/ocs/v2.php/apps/files_publish/api/v1';
	let TARGETS = [];

	// Upload-to-cloud glyph, themed via currentColor.
	window.FilesPublishIcon = window.FilesPublishIcon ||
		'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>';

	function ocsGet(path) {
		return fetch(OCS + path + '?format=json', {
			headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': OC.requestToken },
		}).then((r) => r.json());
	}
	function ocsPost(path, params) {
		return fetch(OCS + path + '?format=json', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				'OCS-APIREQUEST': 'true', 'requesttoken': OC.requestToken,
			},
			body: params,
		}).then((r) => r.json());
	}

	async function loadTargets() {
		if (TARGETS.length) return TARGETS;
		const data = await ocsGet('/targets');
		TARGETS = (data?.ocs?.data) || [];
		return TARGETS;
	}

	function fileIdsFrom(nodes) {
		return (Array.isArray(nodes) ? nodes : [nodes]).map((n) => n.fileid || n.id).filter(Boolean);
	}

	async function startPublish(nodes) {
		const targets = await loadTargets();
		if (!targets.length) {
			OC.dialogs.info(t('files_publish', 'No publishing targets are configured. Ask an administrator to set up Zenodo or Figshare.'), t('files_publish', 'Data publishing'));
			return;
		}
		openDialog(targets, fileIdsFrom(nodes));
	}

	function openDialog(targets, fileids) {
		if (!window.FilesPublishDialog) {
			OC.dialogs.info(t('files_publish', 'The publish dialog failed to load.'), t('files_publish', 'Data publishing'));
			return;
		}
		window.FilesPublishDialog.open(targets, fileids, { ocsGet, ocsPost });
	}

	function register() {
		if (!window.OCA || !OCA.Files || !OCA.Files.registerFileAction || !window.OCA.Files.FileAction) {
			return false;
		}
		const { registerFileAction, FileAction } = OCA.Files;
		registerFileAction(new FileAction({
			id: 'files-publish',
			displayName: () => t('files_publish', 'Publish…'),
			iconSvgInline: () => window.FilesPublishIcon || '',
			// Files only (folders are zipped server-side, but allow them too)
			enabled: (nodes) => nodes.length > 0,
			exec: async (node) => { await startPublish(node); return null; },
			execBatch: async (nodes) => { await startPublish(nodes); return nodes.map(() => null); },
			order: 25,
		}));
		return true;
	}

	// The Files app may load after us; retry briefly.
	if (!register()) {
		let tries = 0;
		const iv = setInterval(() => { if (register() || ++tries > 50) clearInterval(iv); }, 100);
	}
})();
