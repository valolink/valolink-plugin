<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Server-side checks on block markup.
 *
 * An honest statement of the limit first: **PHP cannot fully validate a
 * block.** Gutenberg's validity check runs each block type's JavaScript
 * `save()` against the stored attributes and compares the result to the saved
 * HTML; a mismatch is what produces "This block contains unexpected or invalid
 * content" in the editor. Those `save()` functions exist only in JS, so no
 * amount of PHP reproduces them. A page can round-trip through
 * parse_blocks()/serialize_blocks() perfectly and still be invalid — that is
 * exactly what happened to the first hand-written test post here, where a
 * <svg> was placed inside a core/paragraph whose RichText content can never
 * contain one.
 *
 * What follows therefore catches the *cheap* mistakes, which in practice is
 * most of what an agent gets wrong. The real defence is not validating raw
 * HTML after the fact but never asking an agent to write it — see
 * BlockReader::replace_text_at(), which keeps the wrapper byte-identical so
 * there is nothing for save() to disagree with.
 */
final class BlockValidator
{
    /**
     * Inline formatting RichText actually permits. Anything else inside a
     * rich-text block is how you get an invalid block: the paragraph block's
     * save() emits only these, so a stored <svg> or <div> can never match.
     */
    public const INLINE_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'ins', 'mark',
        'code', 'kbd', 'sub', 'sup', 'br', 'span', 'abbr', 'cite', 'q', 'small',
    ];

    /**
     * Issues a change *introduces*, ignoring anything already wrong.
     *
     * Validating the whole document would make any page with a pre-existing
     * problem uneditable, which is both useless and unfair — an agent asked to
     * fix a typo is not responsible for an <svg> someone pasted into a
     * paragraph two years ago.
     *
     * @return array<int, string>
     */
    public function check_diff(string $before, string $after): array
    {
        $existing = $this->check($before);

        return array_values(array_diff($this->check($after), $existing));
    }

    /**
     * @return array<int, string> human-readable problems; empty means "nothing
     *         cheap is wrong", NOT "Gutenberg will accept this"
     */
    public function check(string $content): array
    {
        $issues = [];

        $blocks = parse_blocks($content);

        // 1. Structural: does it survive a parse/serialize round trip? Catches
        //    malformed delimiters and broken attribute JSON.
        if (serialize_blocks($blocks) !== $content) {
            $issues[] = 'Markup does not round-trip through the block parser — delimiters or attribute JSON are malformed.';
        }

        // 2. Every block name must be something this site can render.
        $registry = \WP_Block_Type_Registry::get_instance();
        foreach ($this->names($blocks) as $name) {
            if ($name !== null && !$registry->is_registered($name)) {
                $issues[] = sprintf('Unknown block type "%s" — not registered on this site.', $name);
            }
        }

        // 3. Rich-text blocks must not contain block-level or foreign markup.
        $this->check_rich_text($blocks, $issues);

        // 4. No HTML comments outside a Custom HTML block.
        $this->check_comments($blocks, $issues, null);

        return array_values(array_unique($issues));
    }

    /** Comments WordPress itself writes and reads, in classic content and in core/more, core/nextpage. */
    private const WP_COMMENTS = ['more', 'nextpage', 'noteaser'];

    /**
     * An HTML comment an agent leaves in block markup — a section label like
     * `<!-- BOX 1: Energiajohtaminen -->` — survives the parser and every
     * server-side check, and then breaks the editor: between the children of
     * a container nothing in save() can produce it, so the container shows as
     * invalid; between top-level blocks it becomes an empty Classic block.
     * That reached renea.demolink.fi through an approved whole-page update
     * (2026-09-22). A Custom HTML block is the one place a comment belongs.
     *
     * Only a block's own markup is read — the literal chunks of innerContent,
     * not its children, which are visited in turn — so each comment is
     * reported once, against the block that holds it.
     */
    private function check_comments(array $blocks, array &$issues, ?string $parent): void
    {
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name === 'core/html') {
                continue;
            }

            $own = implode('', array_filter((array) ($block['innerContent'] ?? []), 'is_string'));
            preg_match_all('/<!--(.*?)-->/s', $own, $m);
            foreach ($m[1] as $text) {
                $text = trim($text);
                if (in_array(strtolower(strtok($text, ' ') ?: $text), self::WP_COMMENTS, true)) {
                    continue;
                }
                $where = $name ?? $parent;
                $issues[] = sprintf(
                    'HTML comment "<!-- %s -->" %s: the editor will show %s. Remove it; explain structure in the proposal note instead.',
                    mb_substr($text, 0, 60),
                    $where === null ? 'between top-level blocks' : 'inside ' . $where,
                    $where === null ? 'it as an empty Classic block' : 'that block as invalid',
                );
            }

            if (!empty($block['innerBlocks'])) {
                $this->check_comments($block['innerBlocks'], $issues, $name);
            }
        }
    }

    /**
     * Tags inside a rich-text region that RichText would never emit. This is
     * the check that would have caught the <svg>-in-a-paragraph case.
     */
    private function check_rich_text(array $blocks, array &$issues): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name !== null && $registry->is_registered($name)) {
                $type = $registry->get_registered($name);
                foreach ((array) $type->attributes as $attr => $def) {
                    if (($def['type'] ?? '') !== 'rich-text' && ($def['source'] ?? '') !== 'rich-text') {
                        continue;
                    }
                    // Only checkable when the selector names elements. A class
                    // selector like `.gb-text` gives no way to tell which tag
                    // is the legitimate wrapper, and guessing produced false
                    // positives on perfectly valid GenerateBlocks pages.
                    if (!$this->selector_is_tags((string) ($def['selector'] ?? ''))) {
                        continue;
                    }
                    $inner = $this->rich_text_region((string) ($block['innerHTML'] ?? ''), (string) $def['selector']);
                    if ($inner === null) {
                        continue;
                    }
                    foreach ($this->tags_in($inner) as $tag) {
                        if (!in_array($tag, self::INLINE_TAGS, true)) {
                            $issues[] = sprintf(
                                '<%s> inside %s: rich text only allows inline formatting (%s), so the editor will flag this block as invalid.',
                                $tag,
                                $name,
                                implode(', ', array_slice(self::INLINE_TAGS, 0, 8)) . '…',
                            );
                        }
                    }
                }
            }

            if (!empty($block['innerBlocks'])) {
                $this->check_rich_text($block['innerBlocks'], $issues);
            }
        }
    }

    /** True when the selector is a plain list of tag names, e.g. "h1,h2,h3". */
    private function selector_is_tags(string $selector): bool
    {
        if (trim($selector) === '') {
            return false;
        }
        foreach (explode(',', $selector) as $part) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', trim($part))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The inside of the element the rich text is sourced from — what RichText
     * owns. Markup around it is the block's own wrapper: core/button stores
     * `<div class="wp-block-button"><a>…</a></div>`, and reading the whole
     * block flagged that <div> on every button there is, which refused any
     * proposal that added one. Null when the element is absent (empty text).
     */
    private function rich_text_region(string $html, string $selector): ?string
    {
        $tags = array_map(static fn (string $t): string => preg_quote(strtolower(trim($t)), '/'), explode(',', $selector));
        if (!preg_match('/<(' . implode('|', $tags) . ')\b[^>]*>/i', $html, $open, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $tag   = preg_quote(strtolower($open[1][0]), '/');
        $start = $open[0][1] + strlen($open[0][0]);
        $depth = 1;
        $pos   = $start;
        while (preg_match('/<(\/?)' . $tag . '\b[^>]*>/i', $html, $next, PREG_OFFSET_CAPTURE, $pos)) {
            $depth += $next[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return substr($html, $start, $next[0][1] - $start);
            }
            $pos = $next[0][1] + strlen($next[0][0]);
        }

        return substr($html, $start);
    }

    /** @return array<int, string> lowercase tag names appearing in $html */
    private function tags_in(string $html): array
    {
        preg_match_all('/<\s*([a-zA-Z][a-zA-Z0-9:-]*)/', $html, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** @return array<int, ?string> */
    private function names(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $out[] = $block['blockName'] ?? null;
            if (!empty($block['innerBlocks'])) {
                $out = array_merge($out, $this->names($block['innerBlocks']));
            }
        }

        return $out;
    }
}
