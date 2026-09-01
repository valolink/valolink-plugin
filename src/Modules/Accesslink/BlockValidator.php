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

        return array_values(array_unique($issues));
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
                    $inner = (string) ($block['innerHTML'] ?? '');
                    foreach ($this->tags_in($inner) as $tag) {
                        if (!in_array($tag, self::INLINE_TAGS, true) && !$this->is_wrapper_tag($tag, $def)) {
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

    /** The element the rich text is sourced from is legitimately present. */
    private function is_wrapper_tag(string $tag, array $def): bool
    {
        $selector = (string) ($def['selector'] ?? '');
        if ($selector === '') {
            return false;
        }
        foreach (explode(',', $selector) as $part) {
            if (strtolower(trim($part)) === $tag) {
                return true;
            }
        }

        return false;
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
