/* global SiteMigrator */
(function () {
	'use strict';

	const $ = (selector) => document.querySelector(selector);
	const form = $('#sm-form');
	const t = SiteMigrator.i18n;

	let running = false;
	let stopRequested = false;
	let rows = [];

	/* ---------- Utilities ---------- */

	/** Fills %s (in order) and %1$s, %2$s… (by position) placeholders. */
	function fmt(str, ...args) {
		let i = 0;
		return str.replace(/%(?:(\d+)\$)?s/g, (m, pos) => String(pos ? args[pos - 1] : args[i++]));
	}

	function getOptions(includeSource) {
		const options = {};
		form.querySelectorAll('[name]').forEach((el) => {
			if (!includeSource && (el.name === 'sitemap' || el.name === 'urls')) {
				return;
			}
			if (el.type === 'radio' && !el.checked) {
				return;
			}
			options[el.name] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
		});
		return options;
	}

	async function call(action, data, options) {
		const fd = new FormData();
		fd.append('action', 'sm_' + action);
		fd.append('nonce', SiteMigrator.nonce);
		Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
		Object.entries(options || {}).forEach(([k, v]) => fd.append('options[' + k + ']', v));

		let res;
		try {
			res = await fetch(SiteMigrator.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
		} catch (e) {
			throw new Error(fmt(t.noResponse, e.message));
		}

		const text = await res.text();
		let json;
		try {
			json = JSON.parse(text);
		} catch (e) {
			throw new Error(fmt(t.invalidResponse, res.status));
		}
		if (!json || !json.success) {
			throw new Error((json && json.data && json.data.message) || t.unknownError);
		}
		return json.data;
	}

	function setBusy(button, busy) {
		button.disabled = busy;
		const spinner = button.parentElement.querySelector('.spinner');
		if (spinner) {
			spinner.classList.toggle('is-active', busy);
		}
	}

	function notice(container, type, message) {
		const div = document.createElement('div');
		div.className = 'notice notice-' + type + ' inline';
		const p = document.createElement('p');
		p.textContent = message;
		div.appendChild(p);
		container.appendChild(div);
	}

	function queueUrls() {
		return $('#sm-queue').value.split(/\r?\n/).map((u) => u.trim()).filter(Boolean);
	}

	function refreshQueue() {
		const urls = queueUrls();
		$('#sm-url-count').textContent = urls.length === 1 ? t.urlCountOne : fmt(t.urlCountMany, urls.length);

		const select = $('#sm-preview-url');
		const current = select.value;
		select.innerHTML = '';
		urls.forEach((url) => {
			const opt = document.createElement('option');
			opt.value = url;
			opt.textContent = url;
			select.appendChild(opt);
		});
		if (urls.includes(current)) {
			select.value = current;
		}
		$('#sm-start').disabled = running || urls.length === 0;
	}

	function lockUI(locked) {
		form.querySelectorAll('input, select, textarea').forEach((el) => { el.disabled = locked; });
		['#sm-collect', '#sm-preview-btn', '#sm-queue', '#sm-preview-url', '#sm-start'].forEach((s) => { $(s).disabled = locked; });
		$('#sm-stop').disabled = !locked;
	}

	/* ---------- Mode ---------- */

	function currentMode() {
		const radio = form.querySelector('input[name="mode"]:checked');
		return radio ? radio.value : 'all';
	}

	/** Shows only the fields that apply to the selected mode (elements with data-modes="..."). */
	function applyMode() {
		const mode = currentMode();
		document.querySelectorAll('[data-modes]').forEach((el) => {
			el.hidden = !el.dataset.modes.split(' ').includes(mode);
		});
	}

	form.querySelectorAll('input[name="mode"]').forEach((radio) => radio.addEventListener('change', applyMode));
	applyMode();

	/* ---------- Get URLs ---------- */

	$('#sm-collect').addEventListener('click', async () => {
		const button = $('#sm-collect');
		const msg = $('#sm-collect-msg');
		msg.innerHTML = '';
		setBusy(button, true);

		try {
			const data = await call('collect', {}, getOptions(true));
			$('#sm-queue').value = data.urls.join('\n');
			refreshQueue();
			$('#sm-step-urls').hidden = false;
			$('#sm-step-run').hidden = false;
			(data.warnings || []).forEach((w) => notice(msg, 'warning', w));
			if (!data.urls.length) {
				notice(msg, 'warning', t.noUrls);
			}
		} catch (e) {
			notice(msg, 'error', e.message);
		} finally {
			setBusy(button, false);
		}
	});

	$('#sm-queue').addEventListener('change', refreshQueue);

	/* ---------- Preview ---------- */

	$('#sm-preview-btn').addEventListener('click', async () => {
		const url = $('#sm-preview-url').value;
		if (!url) {
			return;
		}
		const button = $('#sm-preview-btn');
		const msg = $('#sm-preview-msg');
		msg.innerHTML = '';
		setBusy(button, true);

		try {
			const d = await call('preview', { url }, getOptions(false));

			$('#sm-pv-title').textContent = d.title;
			$('#sm-pv-slug').textContent = d.slug + (d.parent_id ? ' ' + fmt(t.childOf, d.parent_id) : '');
			$('#sm-pv-desc').textContent = d.meta.description || t.empty;

			const imagesBox = $('#sm-pv-images');
			imagesBox.innerHTML = '';
			if (!d.images.length) {
				imagesBox.textContent = t.none;
			} else {
				const ul = document.createElement('ul');
				ul.className = 'sm-images-list';
				d.images.forEach((img) => {
					const li = document.createElement('li');
					let note = img.alt ? fmt(t.alt, img.alt) : t.noAlt;
					if (img.type === 'background') {
						note = t.cssBackground;
					}
					li.textContent = img.src + '  ' + note;
					ul.appendChild(li);
				});
				imagesBox.appendChild(document.createTextNode(d.images.length + ' '));
				imagesBox.appendChild(ul);
			}

			const warnBox = $('#sm-pv-warnings');
			warnBox.innerHTML = '';
			(d.warnings || []).forEach((w) => notice(warnBox, 'warning', w));

			$('#sm-pv-rendered').innerHTML = d.html_safe;
			$('#sm-pv-code').textContent = d.content;
			$('#sm-preview').hidden = false;
		} catch (e) {
			notice(msg, 'error', e.message);
			$('#sm-preview').hidden = true;
		} finally {
			setBusy(button, false);
		}
	});

	document.querySelectorAll('.sm-tab').forEach((tab) => {
		tab.addEventListener('click', () => {
			document.querySelectorAll('.sm-tab').forEach((t) => {
				const active = t === tab;
				t.setAttribute('aria-pressed', active ? 'true' : 'false');
				$('#' + t.dataset.target).hidden = !active;
			});
		});
	});

	/* ---------- Migration ---------- */

	function addRow(row) {
		const tr = document.createElement('tr');
		['sm-col-url', 'sm-col-status', 'sm-col-new', 'sm-col-img'].forEach((c) => {
			const td = document.createElement('td');
			td.className = c;
			tr.appendChild(td);
		});
		$('#sm-log tbody').appendChild(tr);
		updateRow(tr, row);
		return tr;
	}

	function updateRow(tr, row) {
		tr.querySelector('.sm-col-url').textContent = row.url;

		const status = tr.querySelector('.sm-col-status');
		status.innerHTML = '';
		const strong = document.createElement('span');
		strong.className = 'sm-status sm-status-' + row.status;
		strong.textContent = t.status[row.status] || row.status;
		status.appendChild(strong);
		row.warnings.forEach((w) => {
			const small = document.createElement('span');
			small.className = 'sm-warn';
			small.textContent = w;
			status.appendChild(small);
		});

		const cell = tr.querySelector('.sm-col-new');
		cell.innerHTML = '';
		if (row.edit_link) {
			const a = document.createElement('a');
			a.href = row.edit_link;
			a.target = '_blank';
			a.rel = 'noopener';
			a.textContent = row.new_path;
			cell.appendChild(a);
		} else {
			cell.textContent = row.new_path || '';
		}

		const img = tr.querySelector('.sm-col-img');
		if (row.img_total === 0) {
			img.textContent = row.status === 'error' ? '' : t.none;
		} else {
			img.textContent = fmt(t.imagesOf, row.img_ok, row.img_total) + (row.img_failed.length ? ' ' + fmt(t.imagesFailed, row.img_failed.length) : '');
			img.title = row.img_failed.join('\n');
		}
	}

	function setProgress(done, total, label) {
		const bar = $('#sm-progress');
		bar.max = total;
		bar.value = done;
		$('#sm-run-status').textContent = label;
	}

	$('#sm-start').addEventListener('click', async () => {
		const urls = queueUrls();
		if (!urls.length) {
			return;
		}
		const options = getOptions(false);
		const mode = options.mode || 'all';
		let confirmMsg;
		if (mode === 'images') {
			confirmMsg = fmt(t.confirmImages, urls.length);
		} else {
			confirmMsg = fmt(options.status === 'publish' ? t.confirmPublish : t.confirm, urls.length);
		}
		if (!window.confirm(confirmMsg)) {
			return;
		}

		running = true;
		stopRequested = false;
		rows = [];
		$('#sm-log tbody').innerHTML = '';
		$('#sm-log').hidden = false;
		$('#sm-progress').hidden = false;
		$('#sm-csv').hidden = true;
		lockUI(true);

		const counts = { created: 0, updated: 0, skipped: 0, processed: 0, error: 0 };
		let imagesOk = 0;
		let imagesFailed = 0;

		for (let i = 0; i < urls.length; i++) {
			if (stopRequested) {
				break;
			}
			setProgress(i, urls.length, fmt(t.migrating, i + 1, urls.length, urls[i]));

			const row = { url: urls[i], status: '', post_id: '', old_path: '', new_path: '', edit_link: '', img_ok: 0, img_total: 0, img_failed: [], warnings: [] };
			let tr;

			try {
				const r = await call('import_page', { url: urls[i] }, options);
				Object.assign(row, {
					status: r.status,
					post_id: r.post_id,
					old_path: r.old_path,
					new_path: r.new_path,
					edit_link: r.edit_link || '',
					img_total: r.images.length,
					warnings: r.warnings || [],
				});
				tr = addRow(row);

				for (const image of r.images) {
					if (stopRequested) {
						break;
					}
					try {
						await call('import_image', { post_id: r.post_id, src: image.src, alt: image.alt || '' }, options);
						row.img_ok++;
					} catch (e) {
						row.img_failed.push(image.src);
						row.warnings.push(fmt(t.imageError, e.message));
					}
					updateRow(tr, row);
				}

				if (r.featured && !stopRequested) {
					try {
						await call('import_image', { post_id: r.post_id, src: r.featured, alt: r.title || '', featured: '1' }, options);
					} catch (e) {
						row.warnings.push(fmt(t.featuredError, e.message));
						updateRow(tr, row);
					}
				}
				counts[r.status] = (counts[r.status] || 0) + 1;
			} catch (e) {
				row.status = 'error';
				row.warnings.push(e.message);
				counts.error++;
				if (tr) {
					updateRow(tr, row);
				} else {
					addRow(row);
				}
			}

			rows.push(row);
		}

		const processed = rows.length;
		rows.forEach((r) => {
			imagesOk += r.img_ok;
			imagesFailed += r.img_failed.length;
		});
		const summary = mode === 'images'
			? fmt(t.summaryImages, counts.processed, imagesOk, imagesFailed, counts.error)
			: fmt(t.summary, counts.created, counts.updated, counts.skipped, counts.error);
		setProgress(processed, urls.length, stopRequested
			? fmt(t.stopped, processed, urls.length, summary)
			: fmt(t.finished, summary));

		running = false;
		lockUI(false);
		refreshQueue();
		$('#sm-csv').hidden = rows.length === 0;
	});

	$('#sm-stop').addEventListener('click', () => {
		stopRequested = true;
		$('#sm-stop').disabled = true;
		$('#sm-run-status').textContent = t.stopping;
	});

	$('#sm-csv').addEventListener('click', () => {
		const head = t.csvHeaders;
		const esc = (v) => '"' + String(v === undefined || v === null ? '' : v).replace(/"/g, '""') + '"';
		const lines = [head.map(esc).join(',')].concat(rows.map((r) => [
			r.url, r.old_path, r.post_id, r.new_path, t.status[r.status] || r.status, r.img_ok, r.img_failed.join(' '), r.warnings.join(' | '),
		].map(esc).join(',')));

		const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = t.csvFilename;
		document.body.appendChild(a);
		a.click();
		a.remove();
		setTimeout(() => URL.revokeObjectURL(a.href), 1000);
	});

	window.addEventListener('beforeunload', (e) => {
		if (running) {
			e.preventDefault();
			e.returnValue = '';
		}
	});
})();
