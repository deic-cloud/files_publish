/** "Assign DOI…" dialog: a DataCite DOI for one file or folder that stays
 *  here. Register a draft (or update an existing DOI's metadata), then — as a
 *  separate, warned step — make it findable. */
import { translate as t } from '@nextcloud/l10n'
import { el, field, authorsToString, authorsFromString } from './dialog'
import { showSuccess } from './toast'

export function openDoiDialog(fileid, api) {
	const overlay = el('div', { class: 'fp-overlay' })
	const box = el('div', { class: 'fp-dialog fp-doi' })
	overlay.appendChild(box)
	const close = () => overlay.remove()

	box.appendChild(el('div', { class: 'fp-dialog-head' }, [
		el('h2', { text: t('files_publish', 'Assign DOI') }),
		el('button', { class: 'fp-x', text: '×', 'aria-label': t('files_publish', 'Close') }),
	]))
	box.querySelector('.fp-x').addEventListener('click', close)

	const status = el('div', { class: 'fp-prior fp-doi-status' })
	const formArea = el('div', { class: 'fp-form' })
	const warn = el('div', { class: 'fp-doi-warn' })
	warn.style.display = 'none'
	const msg = el('span', { class: 'fp-msg' })
	const cancel = el('button', { class: 'fp-cancel', text: t('files_publish', 'Close') })
	const findableBtn = el('button', { class: 'fp-findable', text: t('files_publish', 'Make findable…') })
	const go = el('button', { class: 'primary fp-go' })
	const footer = el('div', { class: 'fp-dialog-foot' }, [msg, cancel, findableBtn, go])
	box.append(status, formArea, warn, footer)
	cancel.addEventListener('click', close)
	document.body.appendChild(overlay)

	let data = null

	function setMsg(text, error) {
		msg.textContent = text || ''
		msg.classList.toggle('fp-msg-error', !!error)
	}

	function renderStatus() {
		status.innerHTML = ''
		if (data.test) {
			status.appendChild(el('p', { text: t('files_publish', 'Test mode: DOIs are registered on DataCite\'s test service and do not resolve via doi.org.') }))
		}
		if (data.doi) {
			const findable = data.state === 'findable'
			const line = el('p')
			line.appendChild(el('strong', { text: 'DOI ' + data.doi }))
			line.appendChild(el('span', {
				class: 'fp-doi-state ' + (findable ? 'fp-doi-findable' : 'fp-doi-draft'),
				text: findable ? t('files_publish', 'Findable') : (data.state === 'draft' ? t('files_publish', 'Draft') : (data.state || t('files_publish', 'unknown state'))),
			}))
			status.appendChild(line)
			status.appendChild(el('p', {
				text: findable
					? t('files_publish', 'The DOI is permanent. Changes to the metadata below are sent to DataCite.')
					: t('files_publish', 'A draft DOI is not yet citable. Check the landing page, then make it findable.'),
			}))
			if (data.landingUrl) {
				status.appendChild(el('p', {}, [el('a', { href: data.landingUrl, target: '_blank', rel: 'noopener', text: t('files_publish', 'Open the landing page') })]))
			}
		} else {
			status.appendChild(el('p', { text: t('files_publish', 'Registers a draft DOI that points at this item\'s page in the public catalogue. The item gets a public link and is listed in the catalogue. Nothing is permanent until you make the DOI findable.') }))
		}
		go.textContent = data.doi ? t('files_publish', 'Update DOI metadata') : t('files_publish', 'Register draft DOI')
		findableBtn.style.display = (data.doi && data.state === 'draft') ? '' : 'none'
	}

	function renderForm() {
		formArea.innerHTML = ''
		const values = data.values || {}
		data.schema.forEach((f) => {
			let val = values[f.key]
			if (f.type === 'authors') val = authorsToString(val && val.length ? val : data.creators)
			formArea.appendChild(field(f, val))
		})
	}

	function collect() {
		const metadata = {}
		let missing = false
		data.schema.forEach((f) => {
			const inp = formArea.querySelector('[data-key="' + f.key + '"]')
			if (!inp) return
			if (f.type === 'authors') {
				metadata[f.key] = authorsFromString(inp.value, data.creators)
				if (f.required && !metadata[f.key].length) missing = true
			} else {
				metadata[f.key] = inp.value
				if (f.required && !inp.value.trim()) missing = true
			}
			inp.classList.toggle('fp-missing', !!(f.required && !(f.type === 'authors' ? metadata[f.key].length : inp.value.trim())))
		})
		return missing ? null : metadata
	}

	function body(extra) {
		const p = new URLSearchParams()
		p.append('fileid', fileid)
		const add = (prefix, val) => {
			if (Array.isArray(val)) val.forEach((v, i) => add(prefix + '[' + i + ']', v))
			else if (val && typeof val === 'object') { for (const k in val) add(prefix + '[' + k + ']', val[k]) }
			else p.append(prefix, val == null ? '' : val)
		}
		for (const k in (extra || {})) add('metadata[' + k + ']', extra[k])
		return p
	}

	go.addEventListener('click', async () => {
		const metadata = collect()
		if (!metadata) { setMsg(t('files_publish', 'Please fill the required fields.'), true); return }
		go.disabled = true
		setMsg(data.doi ? t('files_publish', 'Updating…') : t('files_publish', 'Registering…'))
		const res = await api.ocsPost('/doi', body(metadata))
		go.disabled = false
		const d = res?.ocs?.data || {}
		if (res?.ocs?.meta?.status !== 'ok') {
			setMsg(d.message || t('files_publish', 'The DOI could not be registered.'), true)
			if (d.doi) { data.doi = d.doi; data.state = 'draft'; renderStatus() }
			return
		}
		const wasNew = !data.doi
		Object.assign(data, { doi: d.doi, state: d.state, landingUrl: d.landingUrl })
		renderStatus()
		setMsg('')
		showSuccess(wasNew
			? t('files_publish', 'Draft DOI {doi} registered.', { doi: d.doi })
			: t('files_publish', 'DOI metadata updated.'))
	})

	// Making a DOI findable cannot be undone: explain, then ask again.
	findableBtn.addEventListener('click', () => {
		warn.innerHTML = ''
		warn.appendChild(el('p', { text: t('files_publish', 'A findable DOI is permanent: it can never be deleted, and DataCite and search services list it publicly.') }))
		warn.appendChild(el('p', { text: t('files_publish', 'From then on its landing page must stay available. Keep the public link and the catalogue listing, and do not delete, move or rename the item. Check the title, authors and description first.') }))
		const yes = el('button', { class: 'fp-doi-confirm', text: t('files_publish', 'Make findable') })
		const no = el('button', { text: t('files_publish', 'Not now') })
		warn.appendChild(el('div', { class: 'fp-doi-warn-actions' }, [no, yes]))
		warn.style.display = ''
		warn.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
		no.addEventListener('click', () => { warn.style.display = 'none' })
		yes.addEventListener('click', async () => {
			yes.disabled = true
			setMsg(t('files_publish', 'Making the DOI findable…'))
			const res = await api.ocsPost('/doi/findable', body())
			yes.disabled = false
			const d = res?.ocs?.data || {}
			if (res?.ocs?.meta?.status !== 'ok') { setMsg(d.message || t('files_publish', 'The DOI could not be made findable.'), true); return }
			warn.style.display = 'none'
			data.state = d.state
			renderStatus()
			setMsg('')
			showSuccess(t('files_publish', 'DOI {doi} is now findable.', { doi: d.doi }))
		})
	})

	;(async () => {
		setMsg(t('files_publish', 'Loading…'))
		go.style.display = 'none'
		findableBtn.style.display = 'none'
		const res = await api.ocsGet('/doi?fileid=' + encodeURIComponent(fileid))
		data = res?.ocs?.data || {}
		if (res?.ocs?.meta?.status !== 'ok') { setMsg(data.message || t('files_publish', 'Could not load the form.'), true); return }
		setMsg('')
		renderStatus()
		if (!data.ready) {
			setMsg(t('files_publish', 'This server has no "DataCite" metadata schema; ask an administrator.'), true)
			return
		}
		renderForm()
		go.style.display = ''
	})()
}
