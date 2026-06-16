/** Publish dialog (DOM-built modal). Bundled with the action; uses
 *  @nextcloud/dialogs toasts for feedback. */
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from './toast'

function el(tag, attrs, children) {
	const e = document.createElement(tag)
	for (const k in (attrs || {})) {
		if (k === 'text') e.textContent = attrs[k]
		else if (k === 'html') e.innerHTML = attrs[k]
		else e.setAttribute(k, attrs[k])
	}
	;(children || []).forEach((c) => e.appendChild(c))
	return e
}

function field(f, value) {
	const wrap = el('div', { class: 'fp-field' })
	wrap.appendChild(el('label', { text: f.label + (f.required ? ' *' : '') }))
	let input
	if (f.type === 'textarea') {
		input = el('textarea', { rows: '4', 'data-key': f.key }); input.value = value || ''
	} else if (f.type === 'select') {
		input = el('select', { 'data-key': f.key })
		for (const v in (f.options || {})) {
			const o = el('option', { value: v, text: f.options[v] })
			if ((value || f.default) === v) o.selected = true
			input.appendChild(o)
		}
	} else {
		input = el('input', { type: 'text', 'data-key': f.key }); input.value = value || ''
	}
	wrap.appendChild(input)
	if (f.hint) wrap.appendChild(el('div', { class: 'fp-hint', text: f.hint }))
	return wrap
}

const ORCID_RE = /^(.*?)\s*<(\d{4}-\d{4}-\d{4}-\d{3}[\dX])>\s*$/
function authorsToString(creators) {
	return (creators || []).map((c) => c.name + (c.orcid ? ' <' + c.orcid + '>' : '')).join('; ')
}
function authorsFromString(s, creators) {
	const names = (s || '').split(';').map((x) => x.trim()).filter(Boolean)
	return names.map((n, i) => {
		const m = n.match(ORCID_RE)
		const base = (creators && creators[i]) || {}
		return {
			name: m ? m[1].trim() : n,
			orcid: m ? m[2] : (i === 0 ? (base.orcid || '') : ''),
			affiliation: i === 0 ? (base.affiliation || '') : '',
		}
	})
}

function buildBody(targetId, fileids, metadata) {
	const p = new URLSearchParams()
	p.append('target', targetId)
	fileids.forEach((id) => p.append('fileids[]', id))
	const add = (prefix, val) => {
		if (Array.isArray(val)) val.forEach((v, i) => add(prefix + '[' + i + ']', v))
		else if (val && typeof val === 'object') { for (const k in val) add(prefix + '[' + k + ']', val[k]) }
		else p.append(prefix, val == null ? '' : val)
	}
	for (const k in metadata) add('metadata[' + k + ']', metadata[k])
	p.append('format', 'json')
	return p
}

export function openDialog(targets, fileids, api) {
	const overlay = el('div', { class: 'fp-overlay' })
	const box = el('div', { class: 'fp-dialog' })
	overlay.appendChild(box)
	const close = () => overlay.remove()

	box.appendChild(el('div', { class: 'fp-dialog-head' }, [
		el('h2', { text: t('files_publish', 'Publish') }),
		el('button', { class: 'fp-x', text: '×' }),
	]))
	box.querySelector('.fp-x').addEventListener('click', close)

	const picker = el('div', { class: 'fp-targets' })
	const multi = targets.length > 1
	targets.forEach((tg, i) => {
		const b = el('label', { class: 'fp-target-opt' })
		if (multi) {
			const r = el('input', { type: 'radio', name: 'fp-target', value: tg.id })
			if (i === 0) r.checked = true
			b.appendChild(r)
		}
		if (tg.icon) b.appendChild(el('img', { src: tg.icon, alt: '' }))
		b.appendChild(el('span', { text: tg.label }))
		picker.appendChild(b)
	})
	box.appendChild(picker)

	const formArea = el('div', { class: 'fp-form' })
	box.appendChild(formArea)

	// Always-available "publish a link instead of uploading" option (shown for
	// targets that support it). Keeps the data on ScienceData and deposits a
	// citable record that links to a public share — preferred for large data.
	const aslinkBox = el('input', { type: 'checkbox', class: 'fp-aslink' })
	const aslinkWrap = el('div', { class: 'fp-aslink-wrap' }, [
		el('label', {
			class: 'fp-aslink-label',
			title: t('files_publish', 'Keeps the data on ScienceData and deposits a citable record that links to a public share. Recommended for large datasets.'),
		}, [
			aslinkBox,
			el('span', { text: t('files_publish', 'Publish a link to the data instead of uploading it') }),
		]),
	])
	box.appendChild(aslinkWrap)

	const footer = el('div', { class: 'fp-dialog-foot' }, [
		el('span', { class: 'fp-msg' }),
		el('button', { class: 'fp-cancel', text: t('files_publish', 'Cancel') }),
		el('button', { class: 'primary fp-go', title: t('files_publish', 'Uploads your files as a draft on the selected repository. Nothing is made public and no DOI is minted — you review the metadata and submit it there to finish.'), text: t('files_publish', 'Submit') }),
	])
	box.appendChild(footer)
	footer.querySelector('.fp-cancel').addEventListener('click', close)

	let current = null
	const msg = footer.querySelector('.fp-msg')

	async function loadSchema(targetId) {
		const tgt = targets.find((x) => x.id === targetId)
		aslinkWrap.style.display = (tgt && tgt.supportsLink) ? '' : 'none'
		// Preserve anything already entered (fields shared across targets use
		// the same key) so switching target doesn't wipe the form.
		const saved = {}
		formArea.querySelectorAll('[data-key]').forEach((inp) => { saved[inp.dataset.key] = inp.value })
		msg.textContent = t('files_publish', 'Loading…')
		const data = await api.ocsGet('/targets/' + encodeURIComponent(targetId) + '/schema')
		msg.textContent = ''
		formArea.innerHTML = ''
		if (data?.ocs?.meta?.status !== 'ok') {
			formArea.appendChild(el('p', { text: t('files_publish', 'Could not load the form.') })); return
		}
		current = data.ocs.data
		current.schema.forEach((f) => {
			const val = (f.key in saved)
				? saved[f.key]
				: (f.type === 'authors' ? authorsToString(current.creators) : '')
			formArea.appendChild(field(f, val))
		})
	}
	picker.addEventListener('change', (e) => loadSchema(e.target.value))
	loadSchema(targets[0].id)

	function currentTargetId() {
		const checked = box.querySelector('input[name="fp-target"]:checked')
		return checked ? checked.value : targets[0].id
	}

	function collectMetadata() {
		const metadata = {}
		let missing = false
		current.schema.forEach((f) => {
			const inp = formArea.querySelector('[data-key="' + f.key + '"]')
			if (!inp) return
			if (f.type === 'authors') {
				metadata[f.key] = authorsFromString(inp.value, current.creators)
				if (f.required && !metadata[f.key].length) missing = true
			} else {
				metadata[f.key] = inp.value
				if (f.required && !inp.value.trim()) missing = true
			}
		})
		return missing ? null : metadata
	}

	function openPopup(step) {
		const popup = window.open(step.url, '_blank', 'width=680,height=780')
		window.addEventListener('message', function onMsg(ev) {
			if (ev.data && ev.data.filesPublish) {
				window.removeEventListener('message', onMsg)
				close()
				if (ev.data.filesPublish === 'ok') showSuccess(t('files_publish', 'Draft created on the repository — review and submit it there.'))
			}
		})
		if (!popup) { msg.textContent = ''; showError(t('files_publish', 'Please allow popups and try again.')) }
	}

	async function proceed(targetId, metadata, link) {
		msg.classList.remove('fp-msg-error')
		const oldLink = footer.querySelector('.fp-link'); if (oldLink) oldLink.remove()
		msg.textContent = t('files_publish', 'Starting…')
		const body = buildBody(targetId, fileids, metadata)
		if (link) body.append('link', '1')
		const data = await api.ocsPost('/publish', body)
		if (data?.ocs?.meta?.status !== 'ok') { msg.textContent = t('files_publish', 'Could not start publishing.'); return }
		const step = data.ocs.data
		// Pre-flight size block: too big to upload. Offer the link-deposit path
		// (keep the data on ScienceData; deposit a record that links to it).
		if (step.step === 'too-large') {
			msg.textContent = step.message || t('files_publish', 'The selection is too large to publish.')
			msg.classList.add('fp-msg-error')
			if (step.canLink) {
				const b = el('button', {
					class: 'fp-link',
					title: t('files_publish', 'Keep the data on ScienceData and publish a citable record that links to a public share of it.'),
					text: t('files_publish', 'Publish a link instead'),
				})
				b.addEventListener('click', () => proceed(targetId, metadata, true))
				footer.insertBefore(b, footer.querySelector('.fp-go'))
			}
			return
		}
		openPopup(step)
	}

	footer.querySelector('.fp-go').addEventListener('click', () => {
		const metadata = collectMetadata()
		if (!metadata) { msg.textContent = t('files_publish', 'Please fill the required fields.'); return }
		const tid = currentTargetId()
		const tgt = targets.find((x) => x.id === tid)
		const asLink = !!(tgt && tgt.supportsLink && aslinkBox.checked)
		proceed(tid, metadata, asLink)
	})

	document.body.appendChild(overlay)
}
