/* global OC, t */
/**
 * The publish dialog: pick a target, fill its metadata schema (authors
 * prefilled from profile + ORCID), then begin the publish — opening the
 * OAuth/upload flow in a popup and reporting the result.
 */
(function () {
	'use strict';

	function el(tag, attrs, children) {
		const e = document.createElement(tag);
		for (const k in (attrs || {})) {
			if (k === 'text') e.textContent = attrs[k];
			else if (k === 'html') e.innerHTML = attrs[k];
			else e.setAttribute(k, attrs[k]);
		}
		(children || []).forEach((c) => e.appendChild(c));
		return e;
	}

	function field(f, value) {
		const wrap = el('div', { class: 'fp-field' });
		wrap.appendChild(el('label', { text: f.label + (f.required ? ' *' : '') }));
		let input;
		if (f.type === 'textarea') {
			input = el('textarea', { rows: '4', 'data-key': f.key });
			input.value = value || '';
		} else if (f.type === 'select') {
			input = el('select', { 'data-key': f.key });
			for (const v in (f.options || {})) {
				const o = el('option', { value: v, text: f.options[v] });
				if ((value || f.default) === v) o.selected = true;
				input.appendChild(o);
			}
		} else if (f.type === 'authors') {
			input = el('input', { type: 'text', 'data-key': f.key, 'data-authors': '1' });
			input.value = value || '';
		} else {
			input = el('input', { type: 'text', 'data-key': f.key });
			input.value = value || '';
		}
		wrap.appendChild(input);
		if (f.hint) wrap.appendChild(el('div', { class: 'fp-hint', text: f.hint }));
		return wrap;
	}

	function authorsToString(creators) {
		return (creators || []).map((c) => c.name + (c.orcid ? ' <' + c.orcid + '>' : '')).join('; ');
	}
	function authorsFromString(s, creators) {
		// Keep it simple: names separated by ';', preserve the prefilled orcid/affiliation for the first.
		const names = (s || '').split(';').map((x) => x.trim()).filter(Boolean);
		return names.map((n, i) => {
			const m = n.match(/^(.*?)\s*<(\d{4}-\d{4}-\d{4}-\d{3}[\dX])>\s*$/);
			const base = (creators && creators[i]) || {};
			return {
				name: m ? m[1].trim() : n,
				orcid: m ? m[2] : (i === 0 ? (base.orcid || '') : ''),
				affiliation: i === 0 ? (base.affiliation || '') : '',
			};
		});
	}

	const Dialog = {
		open(targets, fileids, api) {
			const overlay = el('div', { class: 'fp-overlay' });
			const box = el('div', { class: 'fp-dialog' });
			overlay.appendChild(box);

			const close = () => overlay.remove();
			box.appendChild(el('div', { class: 'fp-dialog-head' }, [
				el('h2', { text: t('files_publish', 'Publish') }),
				el('button', { class: 'fp-x', text: '×' }),
			]));
			box.querySelector('.fp-x').addEventListener('click', close);

			// Target picker
			const picker = el('div', { class: 'fp-targets' });
			targets.forEach((tg, i) => {
				const b = el('label', { class: 'fp-target-opt' });
				const r = el('input', { type: 'radio', name: 'fp-target', value: tg.id });
				if (i === 0) r.checked = true;
				b.appendChild(r);
				if (tg.icon) b.appendChild(el('img', { src: tg.icon, alt: '' }));
				b.appendChild(el('span', { text: tg.label }));
				picker.appendChild(b);
			});
			box.appendChild(picker);

			const formArea = el('div', { class: 'fp-form' });
			box.appendChild(formArea);

			const footer = el('div', { class: 'fp-dialog-foot' }, [
				el('span', { class: 'fp-msg' }),
				el('button', { class: 'primary fp-go', text: t('files_publish', 'Publish') }),
			]);
			box.appendChild(footer);

			let current = null; // {schema, creators}
			const msg = footer.querySelector('.fp-msg');

			async function loadSchema(targetId) {
				formArea.innerHTML = '';
				msg.textContent = t('files_publish', 'Loading…');
				const data = await api.ocsGet('/targets/' + encodeURIComponent(targetId) + '/schema');
				msg.textContent = '';
				if (data?.ocs?.meta?.status !== 'ok') {
					formArea.appendChild(el('p', { text: t('files_publish', 'Could not load the form.') }));
					return;
				}
				current = data.ocs.data;
				current.schema.forEach((f) => {
					const val = f.type === 'authors' ? authorsToString(current.creators) : '';
					formArea.appendChild(field(f, val));
				});
			}

			picker.addEventListener('change', (e) => loadSchema(e.target.value));
			loadSchema(targets[0].id);

			footer.querySelector('.fp-go').addEventListener('click', async () => {
				const targetId = box.querySelector('input[name="fp-target"]:checked').value;
				const metadata = {};
				let missing = false;
				current.schema.forEach((f) => {
					const inp = formArea.querySelector('[data-key="' + f.key + '"]');
					if (!inp) return;
					if (f.type === 'authors') {
						metadata[f.key] = authorsFromString(inp.value, current.creators);
						if (f.required && !metadata[f.key].length) missing = true;
					} else {
						metadata[f.key] = inp.value;
						if (f.required && !inp.value.trim()) missing = true;
					}
				});
				if (missing) { msg.textContent = t('files_publish', 'Please fill the required fields.'); return; }

				msg.textContent = t('files_publish', 'Starting…');
				const data = await api.ocsPost('/publish', buildBody(targetId, fileids, metadata));
				if (data?.ocs?.meta?.status !== 'ok') {
					msg.textContent = t('files_publish', 'Could not start publishing.');
					return;
				}
				const step = data.ocs.data;
				const popup = window.open(step.url, '_blank', 'width=680,height=780');
				window.addEventListener('message', function onMsg(ev) {
					if (ev.data && ev.data.filesPublish) {
						window.removeEventListener('message', onMsg);
						close();
						if (ev.data.filesPublish === 'ok') {
							OC.Notification.showTemporary(t('files_publish', 'Draft created on the repository — review and submit it there.'));
						}
					}
				});
				if (!popup) msg.textContent = t('files_publish', 'Please allow popups and try again.');
			});

			document.body.appendChild(overlay);
		},
	};

	function buildBody(targetId, fileids, metadata) {
		const p = new URLSearchParams();
		p.append('target', targetId);
		fileids.forEach((id) => p.append('fileids[]', id));
		// Expand into PHP-friendly nested params so metadata arrives as a
		// nested array: authors become metadata[creators][0][name] etc.
		const add = (prefix, val) => {
			if (Array.isArray(val)) {
				val.forEach((v, i) => add(prefix + '[' + i + ']', v));
			} else if (val && typeof val === 'object') {
				for (const k in val) add(prefix + '[' + k + ']', val[k]);
			} else {
				p.append(prefix, val == null ? '' : val);
			}
		};
		for (const k in metadata) add('metadata[' + k + ']', metadata[k]);
		p.append('format', 'json');
		return p;
	}

	window.FilesPublishDialog = Dialog;
})();
