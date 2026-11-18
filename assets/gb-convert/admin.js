/**
 * Valolink → GenerateBlocks v2: converts the ticked posts one after another by
 * loading each in the block editor inside a hidden iframe (editor.js, run mode),
 * which saves and reports back with postMessage.
 */
(function () {
	'use strict';

	const $ = s => document.querySelector(s);
	const run = $('#vgc-run');
	const stop = $('#vgc-stop');
	const status = $('#vgc-status');
	const frame = $('#vgc-frame');
	const all = $('#vgc-all');
	if (!run || !frame) return;

	const picks = () => [...document.querySelectorAll('.vgc-pick')];
	const sync = () => { run.disabled = !picks().some(p => p.checked); };
	picks().forEach(p => p.addEventListener('change', sync));
	if (all) all.addEventListener('change', () => { picks().forEach(p => { p.checked = all.checked; }); sync(); });

	let stopping = false;
	stop.addEventListener('click', () => { stopping = true; stop.disabled = true; });

	const fmt = o => Object.entries(o || {}).map(([k, v]) => k + ' ' + v).join(', ');

	function convert(row) {
		return new Promise(resolve => {
			const timer = setTimeout(() => done({ error: 'no answer in 3 minutes' }), 180000);
			function done(report) {
				clearTimeout(timer);
				window.removeEventListener('message', onMessage);
				resolve(report);
			}
			function onMessage(e) {
				if (e.origin !== window.location.origin || !e.data || e.data.type !== 'valolink-gb-convert') return;
				done(e.data.report || {});
			}
			window.addEventListener('message', onMessage);
			frame.src = row.dataset.run;
		});
	}

	run.addEventListener('click', async () => {
		const rows = picks().filter(p => p.checked).map(p => p.closest('tr'));
		if (!rows.length || !window.confirm('Convert and save ' + rows.length + ' posts? Each one\'s v1 content is backed up first.')) return;
		run.disabled = true;
		stopping = false;
		stop.style.display = '';
		stop.disabled = false;
		let ok = 0;
		for (const [i, row] of rows.entries()) {
			if (stopping) break;
			status.textContent = (i + 1) + ' / ' + rows.length + ': ' + row.querySelector('strong').textContent;
			const cell = row.querySelector('.vgc-result');
			cell.textContent = 'converting…';
			const r = await convert(row);
			if (r.error) {
				cell.textContent = 'error: ' + r.error;
			} else if (r.saved) {
				ok++;
				cell.textContent = 'saved: ' + fmt(r.converted) + (Object.keys(r.stays || {}).length ? ' — stays v1: ' + fmt(r.stays) : '');
				row.querySelector('.vgc-pick').checked = false;
			} else {
				cell.textContent = 'not saved' + (r.missingCss ? ' (' + r.missingCss + ' blocks without CSS)' : '') + (Object.keys(r.stays || {}).length ? ' — stays v1: ' + fmt(r.stays) : '');
			}
		}
		frame.src = 'about:blank';
		stop.style.display = 'none';
		status.textContent = ok + ' / ' + rows.length + ' saved' + (stopping ? ' (stopped)' : '') + '. Reload the page to rescan.';
		sync();
	});
})();
