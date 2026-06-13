/**
 * "Publish…" file action, registered via the official @nextcloud/files API
 * (this file is bundled — registerFileAction is not exposed to plain JS).
 * The metadata dialog itself stays vanilla (window.FilesPublishDialog, loaded
 * separately) so the bundle only carries what needs the package.
 */
import { registerFileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'

const OCS = (window.OC?.webroot || '') + '/ocs/v2.php/apps/files_publish/api/v1'

const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>'

function ocsGet(path) {
	return fetch(OCS + path + '?format=json', {
		headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': window.OC.requestToken },
	}).then((r) => r.json())
}
function ocsPost(path, params) {
	return fetch(OCS + path + '?format=json', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
			'OCS-APIREQUEST': 'true', 'requesttoken': window.OC.requestToken,
		},
		body: params,
	}).then((r) => r.json())
}

async function startPublish(nodes) {
	const data = await ocsGet('/targets')
	const targets = data?.ocs?.data || []
	if (!targets.length) {
		window.OC.dialogs.info(
			t('files_publish', 'No publishing targets are configured. Ask an administrator to set up Zenodo or Figshare.'),
			t('files_publish', 'Data publishing'))
		return
	}
	const fileids = nodes.map((n) => n.fileid).filter(Boolean)
	if (!window.FilesPublishDialog) {
		window.OC.dialogs.info(t('files_publish', 'The publish dialog failed to load.'), t('files_publish', 'Data publishing'))
		return
	}
	window.FilesPublishDialog.open(targets, fileids, { ocsGet, ocsPost })
}

registerFileAction({
	id: 'files-publish',
	displayName: () => t('files_publish', 'Publish…'),
	title: () => t('files_publish', 'Publish to a research data repository'),
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => Array.isArray(nodes) && nodes.length > 0,
	exec: async ({ nodes }) => { await startPublish([nodes]); return null },
	execBatch: async ({ nodes }) => { await startPublish(nodes); return nodes.map(() => null) },
	order: 25,
})
