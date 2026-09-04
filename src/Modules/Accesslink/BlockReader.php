<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Addressing individual blocks inside a post.
 *
 * Regenerating a whole `post_content` is how an agent destroys a page. On a
 * GenerateBlocks site the copy sits four or five levels down inside container
 * wrappers whose delimiters carry JSON attributes; an LLM asked to "rewrite
 * this page" and handed the raw string will reformat that JSON, drop an
 * attribute, or mis-nest a wrapper, and the editor then shows "this block
 * contains unexpected or invalid content". The diff is also unreadable, so a
 * reviewer cannot catch it.
 *
 * So an agent addresses one block by path and replaces only that block's own
 * HTML. Everything around it — attributes, nesting, sibling blocks — is
 * re-serialised untouched from the parsed tree.
 *
 * Paths are dot-joined child indices from the document root: "1.0.2" is the
 * third child of the first child of the second top-level block. They are
 * positional, so they shift if blocks are inserted or removed — which is
 * exactly what the staleness hash is for.
 */
final class BlockReader
{
    public const MAX_BLOCKS   = 400;
    public const TEXT_PREVIEW = 200;

    /**
     * Flatten the tree into an addressable list.
     *
     * @return array{blocks: array<int, array>, total: int, truncated: bool}
     */
    public function flatten(string $content): array
    {
        $out = [];
        $this->walk(parse_blocks($content), '', 0, $out);

        $truncated = count($out) > self::MAX_BLOCKS;

        return [
            'blocks'    => $truncated ? array_slice($out, 0, self::MAX_BLOCKS) : $out,
            'total'     => count($out),
            'truncated' => $truncated,
        ];
    }

    /** @return array|null the block descriptor at $path, or null */
    public function get_at(string $content, string $path): ?array
    {
        $block = $this->find($parsed = parse_blocks($content), $path);
        if ($block === null) {
            return null;
        }

        return $this->describe($block, $path, substr_count($path, '.'));
    }

    /**
     * Replace only the text *inside* a block's wrapper element, leaving the
     * wrapper — its tag, classes and every attribute — byte-identical.
     *
     * This is the safe way to edit a block and should be preferred over
     * replace_at(). Gutenberg decides a block is valid by re-running its
     * JavaScript save() against the stored attributes and comparing to the
     * saved HTML, so anything that alters the wrapper risks a mismatch that
     * PHP cannot predict. Leave the wrapper alone and there is nothing to
     * disagree about; the only remaining requirement is that the replacement
     * uses inline formatting RichText can actually emit.
     */
    /**
     * @param bool $inline_only Enforce that the replacement is inline formatting
     *                          RichText could emit. True for update_text, where
     *                          the text is authored by an agent. False for a
     *                          translation, where the inner HTML is the source
     *                          block's own — inline SVG icons and all — and the
     *                          caller has already proved the markup is unchanged.
     */
    public function replace_text_at(
        string $content,
        string $path,
        string $inner,
        bool $inline_only = true,
    ): string|\WP_Error {
        $block = $this->get_at($content, $path);
        if ($block === null) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path));
        }
        if ($block['has_inner_blocks']) {
            return new \WP_Error(
                'block_has_children',
                sprintf('Block at %s contains other blocks; edit the leaf holding the text.', $path),
            );
        }

        $parts = $this->split_wrapper((string) $block['html']);
        if ($parts === null) {
            return new \WP_Error(
                'no_single_wrapper',
                sprintf(
                    'Block at %s has no single wrapping element, so its text cannot be replaced in isolation. Use update_block with full HTML if you are sure.',
                    $path,
                ),
            );
        }

        foreach ($inline_only ? $this->tags_in($inner) : [] as $tag) {
            if (!in_array($tag, BlockValidator::INLINE_TAGS, true)) {
                return new \WP_Error(
                    'disallowed_inline_tag',
                    sprintf(
                        '<%s> is not inline formatting. Block text may contain only: %s.',
                        $tag,
                        implode(', ', BlockValidator::INLINE_TAGS),
                    ),
                );
            }
        }

        return $this->replace_at($content, $path, $parts[0] . $inner . $parts[2]);
    }

    /** The editable text of a block: the inner HTML of its wrapper element. */
    public function text_html(string $content, string $path): ?string
    {
        $block = $this->get_at($content, $path);
        if ($block === null) {
            return null;
        }
        $parts = $this->split_wrapper((string) $block['html']);

        return $parts === null ? null : $parts[1];
    }

    /**
     * Split "<p class=x>hello</p>" into open tag / inner / close tag. Returns
     * null when the HTML isn't a single wrapping element, in which case there
     * is no unambiguous "inside" to replace.
     *
     * @return array{0:string,1:string,2:string}|null
     */
    private function split_wrapper(string $html): ?array
    {
        if (!preg_match('/^(\s*<([a-zA-Z][\w:-]*)\b[^>]*>)(.*)(<\/\2>\s*)$/s', $html, $m)) {
            return null;
        }

        return [$m[1], $m[3], $m[4]];
    }

    /** @return array<int, string> */
    private function tags_in(string $html): array
    {
        preg_match_all('/<\s*([a-zA-Z][\w:-]*)/', $html, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /**
     * Replace one block's own HTML, leaving its attributes, its children and
     * every other block exactly as they were.
     */
    public function replace_at(string $content, string $path, string $html): string|\WP_Error
    {
        $blocks = parse_blocks($content);

        $before = $this->names($blocks);

        $target = $this->find($blocks, $path);
        if ($target === null) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path));
        }
        if (!empty($target['innerBlocks'])) {
            return new \WP_Error(
                'block_has_children',
                sprintf(
                    'Block %s at %s contains other blocks; edit the leaf block holding the text instead.',
                    $target['blockName'] ?? '(html)',
                    $path,
                ),
            );
        }

        if (!$this->apply_at($blocks, $path, $html)) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path));
        }

        $serialized = serialize_blocks($blocks);

        // Round-trip guard: the edit must not have changed the shape of the
        // document. If re-parsing yields a different set of block names, the
        // replacement produced markup that no longer parses the same way and
        // it is safer to refuse than to save it.
        $after = $this->names(parse_blocks($serialized));
        if ($before !== $after) {
            return new \WP_Error(
                'block_roundtrip_failed',
                'Replacing that block changed the document structure; refused.',
            );
        }

        return $serialized;
    }


    // -------------------------------------------------------------------------
    // Composition: adding, removing and moving blocks
    // -------------------------------------------------------------------------

    /**
     * Insert new block markup as a sibling of the block at $path.
     *
     * Sibling-only on purpose. Inserting *inside* an arbitrary block raises the
     * question of where among its existing children and text the new one goes,
     * and for a leaf there is no sensible answer at all. "Before/after this
     * block" covers what is actually asked for — add a paragraph after the
     * intro — with no ambiguity.
     */
    public function insert_block(string $content, string $path, string $position, string $markup): string|\WP_Error
    {
        if (!in_array($position, ['before', 'after'], true)) {
            return new \WP_Error('bad_position', 'position must be "before" or "after".');
        }

        $parsed = parse_blocks($markup);
        // parse_blocks emits whitespace-only filler blocks; ignore them.
        $real = array_values(array_filter(
            $parsed,
            static fn (array $b): bool => ($b['blockName'] ?? null) !== null,
        ));
        if (count($real) !== 1) {
            return new \WP_Error(
                'not_one_block',
                sprintf('Expected markup for exactly one block, parsed %d.', count($real)),
            );
        }
        $new = $real[0];

        $registry = \WP_Block_Type_Registry::get_instance();
        if (!$registry->is_registered((string) $new['blockName'])) {
            return new \WP_Error(
                'block_not_available',
                sprintf(
                    'Block type "%s" is not available on this site — the plugin providing it is not installed.',
                    $new['blockName'],
                ),
            );
        }

        $blocks = parse_blocks($content);
        $ok = $this->at_parent($blocks, $path, function (array &$children, ?array &$inner, int $index) use ($new, $position): bool {
            $at = $position === 'after' ? $index + 1 : $index;
            array_splice($children, $at, 0, [$new]);
            if ($inner !== null) {
                $this->splice_null($inner, $at, true);
            }

            return true;
        });

        if (!$ok) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path));
        }

        return serialize_blocks($blocks);
    }

    public function delete_block(string $content, string $path): string|\WP_Error
    {
        $blocks = parse_blocks($content);
        $ok = $this->at_parent($blocks, $path, function (array &$children, ?array &$inner, int $index): bool {
            array_splice($children, $index, 1);
            if ($inner !== null) {
                $this->splice_null($inner, $index, false);
            }

            return true;
        });

        if (!$ok) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path));
        }

        return serialize_blocks($blocks);
    }

    /**
     * Move the block at $path to sit before/after the block at $target_path.
     *
     * Both paths are given as they appear in the *current* document. Removing
     * the source first shifts anything after it within the same parent, so the
     * target index is adjusted by one in that case — the alternative is asking
     * the agent to reason about post-removal indices, which is a reliable way
     * to get silently wrong placements.
     */
    public function move_block(string $content, string $path, string $target_path, string $position): string|\WP_Error
    {
        if (!in_array($position, ['before', 'after'], true)) {
            return new \WP_Error('bad_position', 'position must be "before" or "after".');
        }
        if ($path === $target_path) {
            return new \WP_Error('same_block', 'Source and target are the same block.');
        }
        if (str_starts_with($target_path, $path . '.')) {
            return new \WP_Error('move_into_self', 'Cannot move a block inside itself.');
        }

        $moving = $this->get_at($content, $path);
        if ($moving === null) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path));
        }
        if ($this->get_at($content, $target_path) === null) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $target_path));
        }

        $src_parent = $this->parent_path($path);
        $src_index  = $this->last_index($path);
        $tgt_parent = $this->parent_path($target_path);
        $tgt_index  = $this->last_index($target_path);

        $markup = $this->serialize_one($content, $path);
        if ($markup === null) {
            return new \WP_Error('block_not_found', sprintf('No block at path %s.', $path));
        }

        $removed = $this->delete_block($content, $path);
        if (is_wp_error($removed)) {
            return $removed;
        }

        // Same parent and the source sat earlier: everything after it shifted.
        if ($src_parent === $tgt_parent && $src_index < $tgt_index) {
            $tgt_index--;
        }
        $adjusted = $tgt_parent === '' ? (string) $tgt_index : $tgt_parent . '.' . $tgt_index;

        return $this->insert_block($removed, $adjusted, $position, $markup);
    }

    /** Serialize just the block at $path, for re-insertion elsewhere. */
    private function serialize_one(string $content, string $path): ?string
    {
        $block = $this->find(parse_blocks($content), $path);

        return $block === null ? null : serialize_block($block);
    }

    /**
     * Run $op against the child list that contains $path, handing it the
     * parent's innerContent too so the two stay in step.
     */
    private function at_parent(array &$blocks, string $path, callable $op): bool
    {
        $parts = explode('.', $path);
        $index = (int) array_pop($parts);

        if ($parts === []) {
            $nothing = null;

            return $op($blocks, $nothing, $index);
        }

        return $this->descend($blocks, $parts, $index, $op);
    }

    /** Walk to the parent block named by $parts, then run $op on its children. */
    private function descend(array &$level, array $parts, int $index, callable $op): bool
    {
        $i = (int) array_shift($parts);
        if (!isset($level[$i]) || !is_array($level[$i]['innerBlocks'] ?? null)) {
            return false;
        }

        if ($parts === []) {
            if (!isset($level[$i]['innerBlocks'][$index]) && $index > count($level[$i]['innerBlocks'])) {
                return false;
            }

            return $op($level[$i]['innerBlocks'], $level[$i]['innerContent'], $index);
        }

        return $this->descend($level[$i]['innerBlocks'], $parts, $index, $op);
    }

    /**
     * innerContent interleaves literal HTML with nulls, one null per child in
     * order. Adding or removing a child means adding or removing the matching
     * null, or the parent renders its children in the wrong places.
     */
    private function splice_null(array &$inner, int $child_index, bool $insert): void
    {
        $nulls = [];
        foreach ($inner as $k => $v) {
            if ($v === null) {
                $nulls[] = $k;
            }
        }

        if ($insert) {
            if (isset($nulls[$child_index])) {
                $at = $nulls[$child_index];
            } elseif ($nulls !== []) {
                $at = end($nulls) + 1;
            } else {
                // No children yet: drop the placeholder just inside the wrapper.
                $at = count($inner) > 1 ? count($inner) - 1 : count($inner);
            }
            array_splice($inner, $at, 0, [null]);

            return;
        }

        if (isset($nulls[$child_index])) {
            array_splice($inner, $nulls[$child_index], 1);
        }
    }

    private function parent_path(string $path): string
    {
        $parts = explode('.', $path);
        array_pop($parts);

        return implode('.', $parts);
    }

    private function last_index(string $path): int
    {
        $parts = explode('.', $path);

        return (int) end($parts);
    }

    // -------------------------------------------------------------------------

    private function walk(array $blocks, string $prefix, int $depth, array &$out): void
    {
        foreach ($blocks as $i => $block) {
            $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
            // Skip the whitespace-only "null" blocks parse_blocks emits between
            // real ones; they are not addressable and would just be noise.
            if (($block['blockName'] ?? null) !== null || trim((string) ($block['innerHTML'] ?? '')) !== '') {
                $out[] = $this->describe($block, $path, $depth);
            }
            if (!empty($block['innerBlocks'])) {
                $this->walk($block['innerBlocks'], $path, $depth + 1, $out);
            }
        }
    }

    private function describe(array $block, string $path, int $depth): array
    {
        $html = (string) ($block['innerHTML'] ?? '');
        $text = trim(wp_strip_all_tags($html));

        return [
            'path'             => $path,
            'name'             => $block['blockName'] ?? '(html)',
            'depth'            => $depth,
            'has_inner_blocks' => !empty($block['innerBlocks']),
            'editable'         => empty($block['innerBlocks']) && trim($html) !== '',
            'html'             => $html,
            // The inner HTML of the wrapper — what update_text replaces. Null
            // when the block has no single wrapping element.
            'text_html'        => $this->split_wrapper($html)[1] ?? null,
            'text'             => mb_substr($text, 0, self::TEXT_PREVIEW)
                . (mb_strlen($text) > self::TEXT_PREVIEW ? '…' : ''),
        ];
    }

    private function find(array $blocks, string $path): ?array
    {
        $parts = explode('.', $path);
        $node = null;
        $level = $blocks;

        foreach ($parts as $part) {
            if (!ctype_digit($part) || !isset($level[(int) $part])) {
                return null;
            }
            $node = $level[(int) $part];
            $level = $node['innerBlocks'] ?? [];
        }

        return $node;
    }

    /** Mutates $blocks in place. */
    private function apply_at(array &$blocks, string $path, string $html): bool
    {
        $parts = explode('.', $path);
        $index = (int) array_shift($parts);

        if (!isset($blocks[$index])) {
            return false;
        }

        if ($parts === []) {
            $blocks[$index]['innerHTML'] = $html;
            // innerContent interleaves strings with nulls marking child
            // positions. A leaf has no children, so it is a single string —
            // which is why editing a block with children is refused above.
            $blocks[$index]['innerContent'] = [$html];

            return true;
        }

        if (empty($blocks[$index]['innerBlocks'])) {
            return false;
        }

        return $this->apply_at($blocks[$index]['innerBlocks'], implode('.', $parts), $html);
    }

    /** @return array<int, string> every block name in document order */
    private function names(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $out[] = (string) ($block['blockName'] ?? '(html)');
            if (!empty($block['innerBlocks'])) {
                $out = array_merge($out, $this->names($block['innerBlocks']));
            }
        }

        return $out;
    }
}
