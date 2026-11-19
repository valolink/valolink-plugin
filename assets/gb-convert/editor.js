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

	// v1 class names the site's CSS refers to (GbConvertModule::legacy_css_classes()); a converted
	// block keeps the ones v1 put on that block, so rules like `.gb-container { position: relative }` still apply.
	const KEEP = new Set(cfg.keepClasses || []);
	function legacyClasses(b) {
		const a = b.attributes;
		const base = { 'generateblocks/container': 'gb-container', 'generateblocks/grid': 'gb-grid-wrapper', 'generateblocks/headline': 'gb-headline', 'generateblocks/button': 'gb-button', 'generateblocks/image': 'gb-image' }[b.name];
		if (!base) return [];
		const own = [base, base + '-' + a.uniqueId];
		if (b.name === 'generateblocks/headline' && !a.hasIcon) own.push('gb-headline-text');
		if (b.name === 'generateblocks/button' && !a.hasIcon) own.push('gb-button-text');
		return own.filter(c => KEEP.has(c));
	}
	function withClasses(block, classes) {
		if (!classes.length) return block;
		const cur = (block.attributes.className || '').split(/\s+/).filter(Boolean);
		const className = [...new Set([...cur, ...classes])].join(' ');
		return { ...block, attributes: { ...block.attributes, className } };
	}

	// GB's media queries, as its v2 blocks write them.
	const MQ = {
		desktop: '@media (min-width:1025px)',
		tablet: '@media (max-width:1024px) and (min-width:768px)',
		tabletDown: '@media (max-width:1024px)',
		mobile: '@media (max-width:767px)',
	};
	const addAt = (styles, at, add) => { styles[at] = { ...(styles[at] || {}), ...add }; };
	const advGradientOk = g => g && g.type === 'gradient' && (g.target || 'self') === 'self' && (g.device || 'all') === 'all' && (g.state || 'normal') === 'normal';

	// v1 attributes GB's transforms drop (found by transforming each attribute in use on
	// valolink.fi on its own and comparing): put their CSS into the v2 styles.
	function restoreDropped(b, el) {
		const a = b.attributes;
		const styles = { ...el.attributes.styles };
		let className = el.attributes.className || '';
		// Hide on device (GB Pro): v1 printed display:none !important per device.
		if (a.hideOnDesktop) addAt(styles, MQ.desktop, { display: 'none !important' });
		if (a.hideOnTablet) addAt(styles, MQ.tablet, { display: 'none !important' });
		if (a.hideOnMobile) addAt(styles, MQ.mobile, { display: 'none !important' });
		// v1 icons were `.gb-icon svg { fill: currentColor }`; v2 text blocks only get it for buttons.
		if (a.icon && (b.name === 'generateblocks/headline' || b.name === 'generateblocks/button')) {
			const svgKey = Object.keys(styles).find(k => /\bsvg$/.test(k) && !k.startsWith('@')) || '.gb-shape svg';
			if (!(styles[svgKey] || {}).fill) styles[svgKey] = { ...(styles[svgKey] || {}), fill: 'currentColor' };
		}
		// Image alignment sat on v1's wrapper <figure>; v2 media is the <img> alone.
		if (b.name === 'generateblocks/image') {
			const al = v => (v === 'center' ? { display: 'block', marginLeft: 'auto', marginRight: 'auto' } : v === 'right' ? { display: 'block', marginLeft: 'auto' } : null);
			if (al(a.alignment)) Object.assign(styles, al(a.alignment));
			if (al(a.alignmentTablet)) addAt(styles, MQ.tabletDown, al(a.alignmentTablet));
			if (al(a.alignmentMobile)) addAt(styles, MQ.mobile, al(a.alignmentMobile));
		}
		// v1 printed `border: 2px solid` with no colour, so the border took the text colour (and
		// beat a class's border colour); v2 writes width and style only.
		const bo = a.borders || {};
		['Top', 'Right', 'Bottom', 'Left'].forEach(side => {
			if (bo['border' + side + 'Width'] && !bo['border' + side + 'Color'] && !styles['border' + side + 'Color']) styles['border' + side + 'Color'] = 'currentColor';
		});
		// Wide/full alignment was a class on v1's container.
		if (a.align === 'wide' || a.align === 'full') className = (className + ' align' + a.align).trim();
		// Advanced backgrounds (GB Pro): plain gradients on the block itself map to background-image.
		if (a.useAdvBackgrounds && (a.advBackgrounds || []).length) {
			const g = a.advBackgrounds[0];
			const stop = s => (s === '' || s === undefined || s === null ? '' : ' ' + s + '%');
			styles.backgroundImage = 'linear-gradient(' + (g.direction || 0) + 'deg, ' + g.colorOne + stop(g.stopOne) + ', ' + g.colorTwo + stop(g.stopTwo) + ')';
		}
		return { ...el, attributes: { ...el.attributes, styles, className } };
	}

	function staysReason(b, inLoop) {
		const a = b.attributes;
		if (inLoop) return 'query loop';
		if (b.name === 'generateblocks/button-container') return 'button container';
		if (a.useDynamicData || a.dynamicContentType || a.dynamicLinkType) return 'dynamic data';
		if (a.useAdvBackgrounds && ((a.advBackgrounds || []).length > 1 || !(a.advBackgrounds || []).every(advGradientOk))) return 'advanced background';
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
				// v1's icon wrapper had line-height: 0.
				const iconStyles = { lineHeight: '0', svg: { width: size || '1em', height: (ta.iconStyles && ta.iconStyles.height) || size || '1em' } };

				// A closed v2 content box still shows its own padding and margins, so the v1 content's
				// styles go on an element inside it, as in GB's own v2 accordion pattern.
				const inner = withClasses(createBlock('generateblocks/element', { tagName: 'div', styles: v2Of(content, 'generateblocks/element').attributes.styles },
					content.innerBlocks.flatMap(c => conv(c, null, false))), legacyClasses(content));

				bump(report.converted, 'accordion item');
				return withClasses(createBlock('generateblocks-pro/accordion-item', {
					tagName: 'div',
					styles: v2Of(item, 'generateblocks/element').attributes.styles,
					openByDefault: !!item.attributes.accordionItemOpen,
				}, [
					withClasses(createBlock('generateblocks-pro/accordion-toggle', { tagName: ta.buttonType === 'link' ? 'div' : 'button', styles }, [
						createBlock('generateblocks/text', { tagName: 'span', content: ta.text || t.attributes.content }),
						createBlock('generateblocks-pro/accordion-toggle-icon', {
							tagName: 'span', styles: iconStyles,
							openIcon: svg('gb-accordion__icon'), closeIcon: svg('gb-accordion__icon-open'),
						}),
					]), legacyClasses(toggle)),
					createBlock('generateblocks-pro/accordion-content', { tagName: 'div' }, [inner]),
				]), legacyClasses(item));
			});
			const a = v2Of(acc, 'generateblocks/element').attributes;
			const html = {};
			if (acc.attributes.accordionTransition) html['data-transition'] = acc.attributes.accordionTransition;
			if (acc.attributes.accordionMultipleOpen) html['data-accordion-multiple-open'] = '';
			return [withClasses(createBlock('generateblocks-pro/accordion', { tagName: 'div', styles: a.styles, htmlAttributes: html, className: a.className }, items), legacyClasses(acc))];
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
			// v1 horizontal gap per breakpoint, inherited downwards like the widths.
			const ga = grid.attributes;
			const gd = Number(ga.horizontalGap) || 0;
			const gt = ga.horizontalGapTablet === '' || ga.horizontalGapTablet === undefined ? gd : Number(ga.horizontalGapTablet) || 0;
			const gaps = [gd, gt, ga.horizontalGapMobile === '' || ga.horizontalGapMobile === undefined ? gt : Number(ga.horizontalGapMobile) || 0];
			const template = i => {
				const w = at.map(x => /^\d+(\.\d+)?%$/.test(x[i]) ? parseFloat(x[i]) : NaN);
				if (!w.length || w.some(isNaN)) return null;
				if (w.every(v => Math.abs(v - w[0]) < 0.5)) {
					const n = Math.max(1, Math.round(100 / w[0]));
					return n === 1 ? '1fr' : 'repeat(' + n + ', minmax(0, 1fr))';
				}
				const sum = w.reduce((s, v) => s + v, 0);
				if (sum < 98 || sum > 102) return null;
				// A v1 column was w% of (row + gap) minus the gap, i.e. w% - (1 - w/100) * gap.
				const g = gaps[i];
				return w.map(v => {
					const off = Math.round((1 - v / 100) * g * 100) / 100;
					return off ? 'calc(' + v + '% - ' + off + 'px)' : v + '%';
				}).join(' ');
			};
			const styles = { ...el.attributes.styles };
			const media = Object.keys(styles).filter(k => k.startsWith('@media') && /max-width:\s*\d+/.test(k))
				.sort((a, b) => parseInt(b.match(/max-width:\s*(\d+)/)[1], 10) - parseInt(a.match(/max-width:\s*(\d+)/)[1], 10));
			const [d, t, m] = [0, 1, 2].map(template);
			if (d) styles.gridTemplateColumns = d;
			if (t && media[0]) styles[media[0]] = { ...styles[media[0]], gridTemplateColumns: t === d ? '' : t };
			if (m && media[1]) styles[media[1]] = { ...styles[media[1]], gridTemplateColumns: m === (t || d) ? '' : m };
			// The transform drops the v1 grid's vertical alignment.
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
					res = [withClasses(restoreDropped(b, res[0]), legacyClasses(b)), ...res.slice(1)];
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
			// the core/editor store can register after this script runs on a slow load
			const ready = () => select('core/editor')?.getCurrentPostId()
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
