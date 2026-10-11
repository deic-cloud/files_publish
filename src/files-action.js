/**
 * "Publish…" file action, registered via the official @nextcloud/files API
 * (this file is bundled — registerFileAction is not exposed to plain JS).
 * The metadata dialog itself stays vanilla (window.FilesPublishDialog, loaded
 * separately) so the bundle only carries what needs the package.
 */
import { registerFileAction } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { showError, showInfo } from './toast'
import { openDialog } from './dialog'
import { openDoiDialog } from './doi-dialog'
import { loadState } from '@nextcloud/initial-state'
import { getCurrentUser } from '@nextcloud/auth'

const OCS = (window.OC?.webroot || '') + '/ocs/v2.php/apps/files_publish/api/v1'

const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M19.35 10.04A7.49 7.49 0 0 0 12 4C9.11 4 6.6 5.64 5.35 8.04A5.994 5.994 0 0 0 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/></svg>'

function ocsGet(path) {
	return fetch(OCS + path + (path.includes('?') ? '&' : '?') + 'format=json', {
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
		showInfo(t('files_publish', 'No publishing targets are configured. Ask an administrator to set up Zenodo or Figshare.'))
		return
	}
	const fileids = (nodes || []).map((n) => n.fileid).filter(Boolean)
	if (!fileids.length) {
		showError(t('files_publish', 'No file selected to publish.'))
		return
	}
	openDialog(targets, fileids, { ocsGet, ocsPost })
}

async function runPublish(nodes) {
	try {
		await startPublish(nodes)
	} catch (e) {
		// Surface the real error instead of an opaque console TypeError.
		showError(t('files_publish', 'Publish failed') + ': ' + (e && e.message ? e.message : e))
		console.error('[files_publish]', e)
	}
}

registerFileAction({
	id: 'files-publish',
	displayName: () => t('files_publish', 'Publish…'),
	title: () => t('files_publish', 'Publish to a research data repository'),
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => Array.isArray(nodes) && nodes.length > 0,
	exec: async ({ nodes }) => { await runPublish(nodes); return null },
	execBatch: async ({ nodes }) => { await runPublish(nodes); return nodes.map(() => null) },
	order: 25,
})

// "Assign DOI…" (DataCite) — offered only where an administrator configured it,
// on a single item the user owns (the DOI's landing page lists the owner's data).
const DOI_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M5.5 7A1.5 1.5 0 0 1 4 5.5A1.5 1.5 0 0 1 5.5 4A1.5 1.5 0 0 1 7 5.5A1.5 1.5 0 0 1 5.5 7m15.91 4.58l-9-9C12.05 2.22 11.55 2 11 2H4c-1.11 0-2 .89-2 2v7c0 .55.22 1.05.59 1.41l8.99 9c.37.36.87.59 1.42.59s1.05-.23 1.41-.59l7-7c.37-.36.59-.86.59-1.41c0-.56-.23-1.06-.59-1.42Z"/></svg>'

let doiEnabled = false
try { doiEnabled = !!loadState('files_publish', 'doi', false) } catch (e) { /* not provided */ }

if (doiEnabled) {
	registerFileAction({
		id: 'files-publish-doi',
		displayName: () => t('files_publish', 'Assign DOI…'),
		title: () => t('files_publish', 'Give this file or folder a citable DOI; the data stays here'),
		iconSvgInline: () => DOI_ICON,
		enabled: ({ nodes }) => Array.isArray(nodes) && nodes.length === 1 && !!nodes[0].fileid
			&& (!nodes[0].owner || nodes[0].owner === getCurrentUser()?.uid),
		exec: async ({ nodes }) => {
			try {
				openDoiDialog(nodes[0].fileid, { ocsGet, ocsPost })
			} catch (e) {
				showError(t('files_publish', 'Assign DOI failed') + ': ' + (e && e.message ? e.message : e))
			}
			return null
		},
		order: 26,
	})
}
