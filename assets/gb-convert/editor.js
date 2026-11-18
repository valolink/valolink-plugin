/**
 * GenerateBlocks v1 → v2 in the block editor, through GB's own block transforms.
 * Loaded only when the editor URL carries ?valolink_gb_convert=preview|run.
 *
 *  preview: converts, leaves the post unsaved and says so in a notice.
 *  run:     converts, saves, and posts the result to the parent window (the
 *           module's admin page runs posts one by one in a hidden iframe).
 *
 * Which blocks stay v1 must match GbConvertModule::stays_reason().
 */
(function () {
	'use strict';

	const cfg = window.valolinkGbConvert || {};
	const { select, dispatch, subscribe } = wp.data;
	const { switchToBlockType, createBlock, getBlockType } = wp.blocks;

	const MAP = {
		'generateblocks/grid': 'generateblocks/element',
		'generateblocks/container': 'generateblocks/element',
		'generateblocks/headline': 'generateblocks/text',
		'generateblocks/button': 'generateblocks/text',
	};
	const V2 = [
		'generateblocks/element', 'generateblocks/text', 'generateblocks/media',
		'generateblocks-pro/accordion', 'generateblocks-pro/accordion-item', 'generateblocks-pro/accordion-toggle',
		'generateblocks-pro/accordion-toggle-icon', 'generateblocks-pro/accordion-content',
	];
	const CURRENT = '&:is(.gb-block-is-current, .gb-block-is-current:hover, .gb-block-is-current:focus)';
	const HOVER = '&:is(:hover, :focus)';
	const sleep = ms => new Promise(r => setTimeout(r, ms));

	function staysReason(b, inLoop) {
		const a = b.attributes;
		if (inLoop) return 'query loop';
		if (b.name === 'generateblocks/button-container') return 'button container';
		if (a.useDynamicData || a.dynamicContentType || a.dynamicLinkType) return 'dynamic data';
		return null; // the transforms' own isMatch() refuses the rest
	}

	function convertTree(blocks) {
		const report = { converted: {}, stays: {} };
		const bump = (o, k) => { o[k] = (o[k] || 0) + 1; };

		// v2 styles of a v1 block: GB's transform on a copy without the variant role and anchor
		const v2Of = (b, target) => switchToBlockType({ ...b, attributes: { ...b.attributes, variantRole: '', anchor: '' } }, target)[0];

		function accordion(acc) {
			const items = acc.innerBlocks.map(item => {
				const toggle = item.innerBlocks.find(c => c.attributes.variantRole === 'accordion-toggle');
				const content = item.innerBlocks.find(c => c.attributes.variantRole === 'accordion-content');
				const ta = toggle.attributes;
				const t = v2Of(toggle, 'generateblocks/text');
				const styles = { ...t.attributes.styles };
				delete styles.textDecoration;
				delete styles['.gb-shape svg'];
				// v1 was a <button> styled with button.gb-button-x, which beat the theme's button:hover;
				// v2's class selectors do not, so pin the hover background unless v1 had its own.
				if (ta.backgroundColor && !ta.backgroundColorHover) styles[HOVER] = { backgroundColor: ta.backgroundColor, ...styles[HOVER] };
				const cur = {};
				if (ta.textColorCurrent) cur.color = ta.textColorCurrent;
				if (ta.backgroundColorCurrent) cur.backgroundColor = ta.backgroundColorCurrent;
				if (Object.keys(cur).length) styles[CURRENT] = cur;

				const doc = new DOMParser().parseFromString(toggle.originalContent || '', 'text/html');
				const svg = cls => {
					const s = doc.querySelector('svg.' + cls);
					if (!s) return '';
					['class', 'width', 'height', 'ariahidden', 'role'].forEach(n => s.removeAttribute(n));
					s.setAttribute('aria-hidden', 'true');
					return s.outerHTML;
				};
				const size = ta.iconStyles && ta.iconStyles.width;
				const iconStyles = { svg: { width: size || '1em', height: (ta.iconStyles && ta.iconStyles.height) || size || '1em' } };

				// A closed v2 content box still shows its own padding and margins, so the v1 content's
				// styles go on an element inside it, as in GB's own v2 accordion pattern.
				const inner = createBlock('generateblocks/element', { tagName: 'div', styles: v2Of(content, 'generateblocks/element').attributes.styles },
					content.innerBlocks.flatMap(c => conv(c, null, false)));

				bump(report.converted, 'accordion item');
				return createBlock('generateblocks-pro/accordion-item', {
					tagName: 'div',
					styles: v2Of(item, 'generateblocks/element').attributes.styles,
					openByDefault: !!item.attributes.accordionItemOpen,
				}, [
					createBlock('generateblocks-pro/accordion-toggle', { tagName: ta.buttonType === 'link' ? 'div' : 'button', styles }, [
						createBlock('generateblocks/text', { tagName: 'span', content: ta.text || t.attributes.content }),
						createBlock('generateblocks-pro/accordion-toggle-icon', {
							tagName: 'span', styles: iconStyles,
							openIcon: svg('gb-accordion__icon'), closeIcon: svg('gb-accordion__icon-open'),
						}),
					]),
					createBlock('generateblocks-pro/accordion-content', { tagName: 'div' }, [inner]),
				]);
			});
			const a = v2Of(acc, 'generateblocks/element').attributes;
			const html = {};
			if (acc.attributes.accordionTransition) html['data-transition'] = acc.attributes.accordionTransition;
			if (acc.attributes.accordionMultipleOpen) html['data-accordion-multiple-open'] = '';
			return [createBlock('generateblocks-pro/accordion', { tagName: 'div', styles: a.styles, htmlAttributes: html, className: a.className }, items)];
		}

		// GB's grid transform looks column widths up in a small table: 33.33% + 66.66% comes out as three
		// equal columns, and an empty tablet width becomes 50/50 although v1 inherits the desktop widths
		// there. Rebuild the templates from the v1 widths when they are percentages filling a row.
		function gridColumns(grid, el) {
			const sizes = grid.innerBlocks.map(c => c.attributes.sizing || {});
			const at = sizes.map(s => {
				const d = s.width || '';
				const t = s.widthTablet || d;
				return [d, t, s.widthMobile || t];
			});
			const template = i => {
				const w = at.map(x => /^\d+(\.\d+)?%$/.test(x[i]) ? parseFloat(x[i]) : NaN);
				if (!w.length || w.some(isNaN)) return null;
				if (w.every(v => Math.abs(v - w[0]) < 0.5)) {
					const n = Math.max(1, Math.round(100 / w[0]));
					return n === 1 ? '1fr' : 'repeat(' + n + ', minmax(0, 1fr))';
				}
				const sum = w.reduce((s, v) => s + v, 0);
				if (sum < 98 || sum > 102) return null;
				const min = Math.min(...w);
				return w.map(v => 'minmax(0, ' + Math.round(v / min * 100) / 100 + 'fr)').join(' ');
			};
			const styles = { ...el.attributes.styles };
			const media = Object.keys(styles).filter(k => k.startsWith('@media') && /max-width:\s*\d+/.test(k))
				.sort((a, b) => parseInt(b.match(/max-width:\s*(\d+)/)[1], 10) - parseInt(a.match(/max-width:\s*(\d+)/)[1], 10));
			const [d, t, m] = [0, 1, 2].map(template);
			if (d) styles.gridTemplateColumns = d;
			if (t && media[0]) styles[media[0]] = { ...styles[media[0]], gridTemplateColumns: t === d ? '' : t };
			if (m && media[1]) styles[media[1]] = { ...styles[media[1]], gridTemplateColumns: m === (t || d) ? '' : m };
			// The transform drops the v1 grid's vertical alignment.
			const ga = grid.attributes;
			const align = v => (v && v !== 'inherit' ? v : '');
			if (align(ga.verticalAlignment)) styles.alignItems = ga.verticalAlignment;
			if (align(ga.verticalAlignmentTablet) && media[0]) styles[media[0]] = { ...styles[media[0]], alignItems: ga.verticalAlignmentTablet };
			if (align(ga.verticalAlignmentMobile) && media[1]) styles[media[1]] = { ...styles[media[1]], alignItems: ga.verticalAlignmentMobile };
			return { ...el, attributes: { ...el.attributes, styles } };
		}

		function isAccordion(b) {
			return b.name === 'generateblocks/container' && b.attributes.variantRole === 'accordion' && getBlockType('generateblocks-pro/accordion')
				&& b.innerBlocks.every(i => i.attributes.variantRole === 'accordion-item'
					&& i.innerBlocks.some(c => c.attributes.variantRole === 'accordion-toggle')
					&& i.innerBlocks.some(c => c.attributes.variantRole === 'accordion-content'));
		}

		function conv(b, parent, inLoop) {
			inLoop = inLoop || b.name === 'generateblocks/query-loop';
			if (!inLoop && isAccordion(b)) return accordion(b);
			// A converted grid leaves its items flagged isGrid, which GB's editor would clear once the
			// parent is no longer a grid — but only after the transform has refused them.
			if (b.attributes.isGrid && parent !== 'generateblocks/grid') b = { ...b, attributes: { ...b.attributes, isGrid: false, gridId: '' } };

			let res = [b];
			let target = MAP[b.name];
			if (b.name === 'generateblocks/image') target = b.innerBlocks.length ? 'generateblocks/element' : 'generateblocks/media';
			const v1 = target || b.name === 'generateblocks/button-container' || b.name === 'generateblocks/query-loop';
			if (v1) {
				const short = b.name.replace('generateblocks/', '');
				const why = staysReason(b, inLoop);
				const r = !why && target ? switchToBlockType(b, target) : null;
				if (r && r.length) {
					res = b.name === 'generateblocks/grid' ? [gridColumns(b, r[0])] : r;
					bump(report.converted, short);
				} else {
					bump(report.stays, why || (b.attributes.variantRole ? 'variant "' + b.attributes.variantRole + '"' : 'no transform (' + short + ')'));
				}
			}
			return res.map(x => ({ ...x, innerBlocks: x.innerBlocks.flatMap(c => conv(c, x.name, inLoop)) }));
		}

		return { blocks: blocks.flatMap(b => conv(b, null, false)), report };
	}

	// v2 blocks get a uniqueId and build their `css` attribute while the editor renders them.
	function pendingCss() {
		let n = 0;
		const walk = bs => bs.forEach(b => {
			const a = b.attributes;
			if (V2.includes(b.name) && (!a.uniqueId || (a.styles && Object.keys(a.styles).length && !a.css))) n++;
			walk(b.innerBlocks);
		});
		walk(select('core/block-editor').getBlocks());
		return n;
	}

	function editorReady() {
		return new Promise(resolve => {
			const ready = () => select('core/editor').getCurrentPostId()
				&& (!select('core/editor').__unstableIsEditorReady || select('core/editor').__unstableIsEditorReady())
				&& getBlockType('generateblocks/element');
			if (ready()) return resolve();
			const unsub = subscribe(() => { if (ready()) { unsub(); resolve(); } });
		});
	}

	const fmt = o => Object.entries(o).map(([k, v]) => k + ' ' + v).join(', ');
	const total = o => Object.values(o).reduce((s, v) => s + v, 0);

	async function main() {
		await editorReady();
		await sleep(1500); // let GB's own attribute migrations on mount settle
		const postId = select('core/editor').getCurrentPostId();
		const { blocks, report } = convertTree(select('core/block-editor').getBlocks());
		report.postId = postId;

		if (total(report.converted)) {
			dispatch('core/block-editor').resetBlocks(blocks);
			for (let i = 0; i < 80 && pendingCss(); i++) await sleep(250);
			report.missingCss = pendingCss();
		}

		if (cfg.mode === 'run') {
			report.saved = false;
			if (total(report.converted) && !report.missingCss) {
				await dispatch('core/editor').savePost();
				report.saved = select('core/editor').didPostSaveRequestSucceed();
			}
			window.parent.postMessage({ type: 'valolink-gb-convert', report }, window.location.origin);
			return;
		}

		const draft = select('core/editor').getEditedPostAttribute('status') !== 'publish';
		const msg = total(report.converted)
			? 'GenerateBlocks v2: converted ' + fmt(report.converted) + '. Not saved yet: check the page here or with Preview'
				+ (draft ? ' (on a draft, Preview saves the draft)' : '')
				+ ', then Save/Update to keep it, or leave without saving.'
				+ (report.missingCss ? ' Warning: ' + report.missingCss + ' blocks did not build their CSS.' : '')
			: 'GenerateBlocks v2: nothing to convert.';
		const stays = total(report.stays) ? ' Stays v1: ' + fmt(report.stays) + '.' : '';
		dispatch('core/notices').createWarningNotice(msg + stays, {
			id: 'valolink-gb-convert',
			isDismissible: true,
			actions: [{ label: 'Back to the list', url: cfg.adminUrl }],
		});
	}

	wp.domReady(() => {
		main().catch(e => {
			console.error('[valolink-gb-convert]', e);
			if (cfg.mode === 'run') {
				window.parent.postMessage({ type: 'valolink-gb-convert', report: { error: String(e && e.message || e) } }, window.location.origin);
			} else {
				dispatch('core/notices').createErrorNotice('GenerateBlocks v2 conversion failed: ' + (e && e.message || e));
			}
		});
	});
})();
