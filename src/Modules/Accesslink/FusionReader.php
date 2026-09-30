<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Addressing Avada (Fusion Builder) elements inside a post.
 *
 * A Fusion page is nested shortcodes — container, row, column, element —
 * where every layout tag carries sixty-odd attributes. Asking an agent to
 * regenerate that string is how a page loses its styling or its grid, and a
 * diff of it is unreadable, so the page is addressed the way block pages are:
 * dot-joined paths to single elements, text replaced inside a leaf, siblings
 * inserted, deleted and moved.
 *
 * Parsing follows WordPress's own shortcode rules, because those decide what
 * renders: attributes end at the first `]`, `/]` closes a tag on the spot, and
 * content runs to the first `[/name]` — same-name nesting does not exist. A
 * tag without a closer is void. Unlike the block side nothing is ever
 * re-serialised: every edit is a splice at the node's byte offsets, so all
 * other bytes of the page are untouched by construction.
 *
 * Words live in two places. An element's content — the text of a Text Block,
 * the label of a button — and a handful of attributes: tab and toggle titles,
 * image alt text. FusionSchema lists both.
 */
final class FusionReader implements DocumentReader
{
    /** Fusion descriptors carry no markup, so far more of them fit than blocks. */
    public const MAX_NODES    = 1000;
    public const TEXT_PREVIEW = 200;
    public const ATTR_MAX     = 500;

    /** Block-level tags a TinyMCE region (a Text Block, a tab) holds. */
    public const RICH_TAGS = [
        'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    ];

    /** WordPress's pattern for one opening tag, anchored at an offset. */
    private const OPEN_TAG = '/\G\[([a-zA-Z][\w-]*)(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(\/)?\]/';

    /** Content is Fusion when it carries an element tag and no block delimiters. */
    public static function is_fusion(string $content): bool
    {
        return (bool) preg_match('/\[(?:fusion|awb)_[a-z0-9_]+(?![\w-])/', $content);
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    public function flatten(string $content): array
    {
        $out = [];
        $this->walk($content, $this->parse($content), '', 0, $out);

        $truncated = count($out) > self::MAX_NODES;

        return [
            'blocks'    => $truncated ? array_slice($out, 0, self::MAX_NODES) : $out,
            'total'     => count($out),
            'truncated' => $truncated,
        ];
    }

    public function get_at(string $content, string $path): ?array
    {
        $node = $this->find($this->parse($content), $path);

        return $node === null ? null : $this->describe($content, $node, $path, substr_count($path, '.'), true);
    }

    public function text_html(string $content, string $path): ?string
    {
        $node = $this->find($this->parse($content), $path);
        if ($node === null || !in_array($this->kind($node), [FusionSchema::RICH, FusionSchema::INLINE], true)) {
            return null;
        }

        return $this->inner($content, $node);
    }

    // -------------------------------------------------------------------------
    // Editing words
    // -------------------------------------------------------------------------

    /**
     * Replace an element's content, leaving its opening tag — every attribute
     * that styles it — byte-identical.
     *
     * @param bool $inline_only True when an agent wrote the text: tags are
     *                          held to what the element's editor could have
     *                          produced and no shortcode may appear that was
     *                          not there already. False for a translation,
     *                          whose caller proves the markup is unchanged.
     */
    public function replace_text_at(string $content, string $path, string $inner, bool $inline_only = true): string|\WP_Error
    {
        $node = $this->find($this->parse($content), $path);
        if ($node === null) {
            return new \WP_Error('block_not_found', sprintf('No element at path %s.', $path));
        }
        if ($node['container']) {
            return new \WP_Error(
                'block_has_children',
                sprintf('%s at %s contains other elements; edit the element holding the text.', $node['name'], $path),
            );
        }

        $kind = $this->kind($node);
        if (!in_array($kind, [FusionSchema::RICH, FusionSchema::INLINE], true)) {
            return new \WP_Error('not_text', $this->not_text_reason($node, $path));
        }

        if ($inline_only) {
            $allowed = $kind === FusionSchema::RICH
                ? array_merge(BlockValidator::INLINE_TAGS, self::RICH_TAGS)
                : BlockValidator::INLINE_TAGS;
            // Unlike a block, nothing validates a Text Block's markup, and
            // they hold images and iframes pasted in over the years. Resending
            // what is already there is fine; adding new kinds of tag is not.
            $current = $this->tags_in($this->inner($content, $node));
            foreach (array_diff($this->tags_in($inner), $current) as $tag) {
                if (!in_array($tag, $allowed, true)) {
                    return new \WP_Error(
                        'disallowed_inline_tag',
                        sprintf(
                            '<%s> is not allowed in %s. Its text may contain only: %s.',
                            $tag,
                            $node['name'] ?? 'this text',
                            implode(', ', $allowed),
                        ),
                    );
                }
            }

            $added = $this->added_shortcodes($this->inner($content, $node), $inner);
            if ($added !== []) {
                return new \WP_Error(
                    'shortcode_in_text',
                    sprintf(
                        'Text may not add shortcodes (%s). Keep the ones already in the element exactly as they are; '
                            . 'to add an element, use insert_block.',
                        implode(', ', array_slice($added, 0, 3)),
                    ),
                );
            }
        }

        $result = substr_replace($content, $inner, $node['open_end'], $node['close_start'] - $node['open_end']);

        return $this->same_shape($content, $result);
    }

    public function replace_attr_at(string $content, string $path, string $attr, string $value): string|\WP_Error
    {
        $node = $this->find($this->parse($content), $path);
        if ($node === null) {
            return new \WP_Error('block_not_found', sprintf('No element at path %s.', $path));
        }

        $allowed = $node['name'] === null ? [] : FusionSchema::text_attrs($node['name']);
        if (!in_array($attr, $allowed, true)) {
            return new \WP_Error(
                'not_text_attr',
                $allowed === []
                    ? sprintf('%s at %s has no text attributes.', $node['name'] ?? 'Text', $path)
                    : sprintf('"%s" is not a text attribute of %s. Text attributes: %s.', $attr, $node['name'], implode(', ', $allowed)),
            );
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($value)));
        if (preg_match('/["\[\]]/', $value)) {
            return new \WP_Error(
                'bad_attr_text',
                'An attribute value cannot contain " [ or ] — they end the shortcode. Use typographic quotes (” ’) instead.',
            );
        }
        if (mb_strlen($value) > self::ATTR_MAX) {
            return new \WP_Error('attr_too_long', sprintf('Attribute text is limited to %d characters.', self::ATTR_MAX));
        }

        $open = substr($content, $node['start'], $node['open_end'] - $node['start']);
        $pattern = '/(\s' . preg_quote($attr, '/') . '=")[^"]*(")/';
        if (preg_match($pattern, $open)) {
            $new_open = (string) preg_replace_callback($pattern, static fn (array $m): string => $m[1] . $value . $m[2], $open, 1);
        } elseif (preg_match('/\s' . preg_quote($attr, '/') . '=/', $open)) {
            return new \WP_Error('attr_format', sprintf('%s is written in a form this API does not edit; change it in the builder.', $attr));
        } else {
            // Not there yet — an image without alt text is the common case.
            $tail = str_ends_with($open, '/]') ? '/]' : ']';
            $new_open = rtrim(substr($open, 0, -strlen($tail))) . ' ' . $attr . '="' . $value . '"' . ($tail === '/]' ? ' /]' : ']');
        }

        $result = substr_replace($content, $new_open, $node['start'], $node['open_end'] - $node['start']);

        return $this->same_shape($content, $result);
    }

    /**
     * Replace an element's whole markup — opening tag, content and closer —
     * for changes words alone cannot make, such as a button's link. The
     * element type stays: swapping one element for another is an insert and a
     * delete, which the reviewer sees as such.
     */
    public function replace_at(string $content, string $path, string $html): string|\WP_Error
    {
        $node = $this->find($this->parse($content), $path);
        if ($node === null) {
            return new \WP_Error('block_not_found', sprintf('No element at path %s.', $path));
        }
        if ($node['container']) {
            return new \WP_Error(
                'block_has_children',
                sprintf('%s at %s contains other elements; edit the element holding the text instead.', $node['name'], $path),
            );
        }

        if ($node['name'] === null) {
            if ($this->has_elements($this->parse($html))) {
                return new \WP_Error('not_one_block', 'Replacement for plain text must not contain elements; use insert_block.');
            }
        } elseif (!$this->is_one($html = trim($html), $node['name'])) {
            return new \WP_Error(
                'block_type_changed',
                sprintf(
                    'Expected exactly one %s element. update_block keeps the element type; to swap one element for another, '
                        . 'insert the new one and delete the old.',
                    $node['name'],
                ),
            );
        }

        $result = substr_replace($content, $html, $node['start'], $node['end'] - $node['start']);

        return $this->same_shape($content, $result);
    }

    // -------------------------------------------------------------------------
    // Composition
    // -------------------------------------------------------------------------

    /**
     * Insert one element as a sibling of the one at $path, or with no path at
     * the start or end of the page.
     *
     * The grid is off limits: rows and columns are where Avada keeps widths,
     * spacing and breakpoints, and a column inserted without the right
     * `type`, `first` and `last` breaks the layout in ways only a render shows.
     * Elements go inside existing columns; whole containers go between
     * containers.
     */
    public function insert_block(string $content, string $path, string $position, string $markup): string|\WP_Error
    {
        $root = $path === '';
        if ($root && !in_array($position, ['start', 'end'], true)) {
            return new \WP_Error('bad_position', 'Without a path, position must be "start" or "end".');
        }
        if (!$root && !in_array($position, ['before', 'after'], true)) {
            return new \WP_Error('bad_position', 'position must be "before" or "after".');
        }

        $markup = trim($markup);
        $new = $this->real($this->parse($markup));
        if (!$this->is_one($markup)) {
            return new \WP_Error(
                'not_one_block',
                sprintf('Expected markup for exactly one element and nothing around it, parsed %d.', count($new)),
            );
        }
        $name = (string) $new[0]['name'];

        if (!shortcode_exists($name)) {
            return new \WP_Error(
                'block_not_available',
                sprintf('Element "%s" is not available on this site — the plugin providing it is not active.', $name),
            );
        }
        $unavailable = $this->unavailable_names($new);
        if ($unavailable !== []) {
            return new \WP_Error(
                'block_not_available',
                sprintf('Not available on this site: %s.', implode(', ', $unavailable)),
            );
        }
        $code = array_intersect($this->names($new), FusionSchema::CODE);
        if ($code !== []) {
            return new \WP_Error(
                'code_element',
                sprintf('%s runs code on the site; adding one is left to a person in the builder.', reset($code)),
            );
        }
        $refs = $this->bad_global_refs($new, $markup);
        if ($refs !== []) {
            return new \WP_Error('bad_global', implode(' ', $refs));
        }

        $nodes = $this->parse($content);

        if ($root) {
            $top = $this->real($nodes);
            $sibling = $position === 'start' ? ($top[0] ?? null) : (end($top) ?: null);
            if ($sibling !== null) {
                $fit = $this->fits($sibling, null, $name);
                if (is_wp_error($fit)) {
                    return $fit;
                }
            }
            $offset = $position === 'start' ? $this->lead($content) : strlen(rtrim($content));
        } else {
            $sibling = $this->find($nodes, $path);
            if ($sibling === null) {
                return new \WP_Error('block_not_found', sprintf('No element at path %s.', $path));
            }
            $fit = $this->fits($sibling, $this->parent_of($nodes, $path), $name);
            if (is_wp_error($fit)) {
                return $fit;
            }
            $offset = $position === 'before' ? $sibling['start'] : $sibling['end'];
        }

        $result = substr($content, 0, $offset) . $markup . substr($content, $offset);

        // The new tag must not have reached into its neighbours — a closer
        // missing from the markup would otherwise pair with the next
        // element's, swallowing it.
        if (count($this->names($this->parse($result))) !== count($this->names($nodes)) + count($this->names($new))) {
            return new \WP_Error('block_roundtrip_failed', 'The inserted markup merged with the elements around it; refused.');
        }

        return $result;
    }

    public function delete_block(string $content, string $path): string|\WP_Error
    {
        $nodes = $this->parse($content);
        $node = $this->find($nodes, $path);
        if ($node === null) {
            return new \WP_Error('block_not_found', sprintf('No element at path %s.', $path));
        }
        if ($this->is_grid($node)) {
            return $this->grid_locked($node);
        }

        $result = substr($content, 0, $node['start']) . substr($content, $node['end']);
        if (count($this->names($this->parse($result))) !== count($this->names($nodes)) - count($this->names([$node]))) {
            return new \WP_Error('block_roundtrip_failed', 'Removing that element changed the elements around it; refused.');
        }

        return $result;
    }

    /**
     * Both paths as they are in the current page; the shift from removing the
     * source is handled here, as BlockReader does.
     */
    public function move_block(string $content, string $path, string $target_path, string $position): string|\WP_Error
    {
        if (!in_array($position, ['before', 'after'], true)) {
            return new \WP_Error('bad_position', 'position must be "before" or "after".');
        }
        if ($path === $target_path) {
            return new \WP_Error('same_block', 'Source and target are the same element.');
        }
        if (str_starts_with($target_path, $path . '.')) {
            return new \WP_Error('move_into_self', 'Cannot move an element inside itself.');
        }

        $nodes = $this->parse($content);
        $moving = $this->find($nodes, $path);
        if ($moving === null) {
            return new \WP_Error('block_not_found', sprintf('No element at path %s.', $path));
        }
        if ($this->find($nodes, $target_path) === null) {
            return new \WP_Error('block_not_found', sprintf('No element at path %s.', $target_path));
        }
        if ($moving['name'] === null) {
            return new \WP_Error('not_an_element', 'Only elements can be moved.');
        }

        $markup = substr($content, $moving['start'], $moving['end'] - $moving['start']);
        $removed = $this->delete_block($content, $path);
        if (is_wp_error($removed)) {
            return $removed;
        }

        $src = explode('.', $path);
        $tgt = explode('.', $target_path);
        $src_index = (int) array_pop($src);
        $tgt_index = (int) array_pop($tgt);
        // Same parent and the source sat earlier: everything after it shifted.
        // Removing plain text runs never renumbers, as they are not moved.
        if ($src === $tgt && $src_index < $tgt_index) {
            $tgt_index--;
        }
        $adjusted = $tgt === [] ? (string) $tgt_index : implode('.', $tgt) . '.' . $tgt_index;

        return $this->insert_markup_verbatim($removed, $adjusted, $position, $markup);
    }

    // -------------------------------------------------------------------------
    // Review helpers
    // -------------------------------------------------------------------------

    public function skeleton(string $content): string
    {
        $out = [];
        $offset = 0;
        while (preg_match('/<[^>]*>|\[(\/?)([a-zA-Z][\w-]*)(?![\w-])[^\]]*\]/', $content, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $m[0][1] + strlen($m[0][0]);
            $token = $m[0][0];
            if ($token[0] === '[') {
                $name = $m[2][0];
                foreach (FusionSchema::text_attrs($name) as $attr) {
                    $token = (string) preg_replace('/(\s' . preg_quote($attr, '/') . '=")[^"]*(")/', '$1$2', $token);
                }
            }
            $out[] = $token;
        }

        return implode('', $out);
    }

    /**
     * One attribute per line, so that changing a button's link or an image's
     * alt text is one changed line in the queue rather than a changed
     * three-kilobyte line nobody reads to the end.
     */
    public function display_html(string $html): string
    {
        return (string) preg_replace_callback(
            '/\[([a-zA-Z][\w-]*)(?![\w-])((?:\s+[\w-]+=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+))+)\s*(\/?)\]/',
            static function (array $m): string {
                preg_match_all('/[\w-]+=(?:"[^"]*"|\'[^\']*\'|[^\s\]]+)/', $m[2], $attrs);

                return '[' . $m[1] . "\n    " . implode("\n    ", $attrs[0]) . "\n" . ($m[3] === '/' ? '/]' : ']') . "\n";
            },
            $html,
        );
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    /**
     * The element tree with byte offsets. Public for FusionValidator, so the
     * checks read the page exactly as the edits do.
     *
     * @return array<int, array{name: ?string, start: int, open_end: int, close_start: ?int, end: int, attrs: string, container: bool, children: array}>
     */
    public function parse(string $s): array
    {
        return $this->level($s, 0, strlen($s));
    }

    private function level(string $s, int $from, int $to): array
    {
        $nodes = [];
        $pos = $from;
        $text_from = $from;

        while ($pos < $to) {
            $i = strpos($s, '[', $pos);
            if ($i === false || $i >= $to) {
                break;
            }
            // [[tag]] is WordPress's escape for a literal shortcode.
            if ($i + 1 < $to && $s[$i + 1] === '[') {
                $pos = $i + 2;
                continue;
            }
            // Registration is not asked: some elements register their
            // shortcode only on front-end requests, and wherever the parser
            // looks — the page, a column, a parent's items — a tag is an
            // element. Text inside an element is never parsed.
            if (!preg_match(self::OPEN_TAG, $s, $m, 0, $i) || $i + strlen($m[0]) > $to) {
                $pos = $i + 1;
                continue;
            }

            $name = $m[1];
            $open_end = $i + strlen($m[0]);
            $close_start = null;
            $end = $open_end;
            if (($m[3] ?? '') !== '/') {
                $closer = '[/' . $name . ']';
                $c = strpos($s, $closer, $open_end);
                if ($c !== false && $c + strlen($closer) <= $to) {
                    $close_start = $c;
                    $end = $c + strlen($closer);
                }
            }

            $this->text_run($s, $text_from, $i, $nodes);

            $node = [
                'name'        => $name,
                'start'       => $i,
                'open_end'    => $open_end,
                'close_start' => $close_start,
                'end'         => $end,
                'attrs'       => (string) $m[2],
                'container'   => false,
                'children'    => [],
            ];
            if ($close_start !== null && !array_key_exists($name, FusionSchema::CONTENT_KIND)) {
                $children = $this->level($s, $open_end, $close_start);
                // Layout always holds elements. Anything else is a parent —
                // tabs, a checklist, a gallery — when its content is nothing
                // but elements; otherwise it is text that happens to hold a
                // shortcode, and stays whole.
                if (FusionSchema::is_layout($name)
                    || ($children !== [] && !in_array(null, array_column($children, 'name'), true))) {
                    $node['container'] = true;
                    $node['children'] = $children;
                }
            }
            $nodes[] = $node;

            $pos = $end;
            $text_from = $end;
        }

        $this->text_run($s, $text_from, $to, $nodes);

        return $nodes;
    }

    /** Text between elements, when it is more than whitespace. */
    private function text_run(string $s, int $from, int $to, array &$nodes): void
    {
        if ($to <= $from || trim(substr($s, $from, $to - $from)) === '') {
            return;
        }
        $nodes[] = [
            'name'        => null,
            'start'       => $from,
            'open_end'    => $from,
            'close_start' => $to,
            'end'         => $to,
            'attrs'       => '',
            'container'   => false,
            'children'    => [],
        ];
    }

    // -------------------------------------------------------------------------

    private function walk(string $content, array $nodes, string $prefix, int $depth, array &$out): void
    {
        foreach ($nodes as $i => $node) {
            $path = $prefix === '' ? (string) $i : $prefix . '.' . $i;
            $out[] = $this->describe($content, $node, $path, $depth, false);
            if ($node['children'] !== []) {
                $this->walk($content, $node['children'], $path, $depth + 1, $out);
            }
        }
    }

    /**
     * An element as an agent reads it. The listing leaves the markup out —
     * a single Avada column tag runs to three kilobytes of styling an agent
     * has no use for — and get_at() adds it back for the code that hashes and
     * diffs.
     */
    private function describe(string $content, array $node, string $path, int $depth, bool $with_html): array
    {
        $kind = $this->kind($node);
        $editable = in_array($kind, [FusionSchema::RICH, FusionSchema::INLINE], true);
        $inner = $node['close_start'] === null ? '' : $this->inner($content, $node);
        $text = $node['container'] ? '' : trim(wp_strip_all_tags($inner));
        $atts = $node['name'] === null ? [] : $this->atts($node);

        $out = [
            'path'             => $path,
            'name'             => $node['name'] ?? '(html)',
            'depth'            => $depth,
            'has_inner_blocks' => $node['children'] !== [],
            'editable'         => $editable,
            'text_kind'        => $editable ? $kind : null,
            'text_html'        => $editable ? $inner : null,
            'text'             => mb_substr($text, 0, self::TEXT_PREVIEW)
                . (mb_strlen($text) > self::TEXT_PREVIEW ? '…' : ''),
        ];

        $texts = [];
        foreach (FusionSchema::text_attrs((string) $node['name']) as $attr) {
            if (array_key_exists($attr, $atts)) {
                $texts[$attr] = (string) $atts[$attr];
            }
        }
        if ($texts !== []) {
            $out['texts'] = $texts;
        }
        if (($atts['admin_label'] ?? '') !== '') {
            $out['label'] = (string) $atts['admin_label'];
        }
        if (in_array($node['name'], [FusionSchema::COLUMN, FusionSchema::COLUMN_INNER], true) && isset($atts['type'])) {
            $out['width'] = (string) $atts['type'];
        }
        if ($node['name'] === FusionSchema::GLOBAL_REF) {
            $id = (int) ($atts['id'] ?? 0);
            $out['global_id'] = $id;
            $out['global_title'] = $id > 0 ? (string) get_the_title($id) : '';
        }
        if (array_key_exists('dynamic_params', $atts)) {
            // Avada fills this element from data at render time; the stored
            // text may not be what visitors see.
            $out['dynamic'] = true;
        }
        if ($node['name'] !== null && FusionSchema::is_code($node['name'])) {
            $out['code'] = true;
        }

        if ($with_html) {
            $out['html'] = substr($content, $node['start'], $node['end'] - $node['start']);
        }

        return $out;
    }

    /** What an element's content holds, or null when there is no content to edit. */
    private function kind(array $node): ?string
    {
        if ($node['container']) {
            return null;
        }
        if ($node['name'] === null) {
            return FusionSchema::RICH;
        }
        if ($node['close_start'] === null) {
            return null;
        }

        return FusionSchema::CONTENT_KIND[$node['name']] ?? FusionSchema::RICH;
    }

    private function not_text_reason(array $node, string $path): string
    {
        $name = (string) $node['name'];
        if (FusionSchema::is_code($name)) {
            return sprintf('%s at %s is code; code is changed by a person in the builder.', $name, $path);
        }
        $attrs = FusionSchema::text_attrs($name);
        $hint = $attrs === [] ? '' : sprintf(' Its words are in attributes (%s): send `attr` with update_text.', implode(', ', $attrs));
        if (($node['close_start'] ?? null) === null) {
            return sprintf('%s at %s has no text content.%s', $name, $path, $hint);
        }

        return sprintf('The content of %s at %s is not text (an image URL or encoded data).%s', $name, $path, $hint);
    }

    private function inner(string $content, array $node): string
    {
        return substr($content, $node['open_end'], (int) $node['close_start'] - $node['open_end']);
    }

    /** @return array<string, string> */
    public function atts(array $node): array
    {
        $parsed = shortcode_parse_atts($node['attrs']);

        return is_array($parsed) ? array_map('strval', $parsed) : [];
    }

    private function find(array $nodes, string $path): ?array
    {
        $node = null;
        $level = $nodes;
        foreach (explode('.', $path) as $part) {
            if (!ctype_digit($part) || !isset($level[(int) $part])) {
                return null;
            }
            $node = $level[(int) $part];
            $level = $node['children'];
        }

        return $node;
    }

    private function parent_of(array $nodes, string $path): ?array
    {
        $parts = explode('.', $path);
        array_pop($parts);

        return $parts === [] ? null : $this->find($nodes, implode('.', $parts));
    }

    /** Elements only, text runs dropped. */
    private function real(array $nodes): array
    {
        return array_values(array_filter($nodes, static fn (array $n): bool => $n['name'] !== null));
    }

    private function has_elements(array $nodes): bool
    {
        return $this->real($nodes) !== [];
    }

    /** $markup is one element, end to end, and of type $name when given. */
    private function is_one(string $markup, ?string $name = null): bool
    {
        $nodes = $this->parse($markup);

        return count($nodes) === 1
            && $nodes[0]['name'] !== null
            && ($name === null || $nodes[0]['name'] === $name)
            && $nodes[0]['start'] === 0
            && $nodes[0]['end'] === strlen($markup);
    }

    /** Offset of the first non-whitespace byte. */
    private function lead(string $s): int
    {
        return strlen($s) - strlen(ltrim($s));
    }

    /** @return array<int, string> every element name in document order */
    private function names(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $out[] = $node['name'] ?? '(html)';
            if ($node['children'] !== []) {
                $out = array_merge($out, $this->names($node['children']));
            }
        }

        return $out;
    }

    /**
     * Guard for text and single-element edits: the page must still parse into
     * the same elements. Text that smuggled a closer in would otherwise end an
     * element early and spill the rest of it onto the page.
     */
    private function same_shape(string $before, string $after): string|\WP_Error
    {
        if ($this->names($this->parse($before)) !== $this->names($this->parse($after))) {
            return new \WP_Error('block_roundtrip_failed', 'That edit changed the element structure of the page; refused.');
        }

        return $after;
    }

    private function is_grid(array $node): bool
    {
        return in_array($node['name'], [FusionSchema::ROW, FusionSchema::COLUMN, FusionSchema::ROW_INNER, FusionSchema::COLUMN_INNER], true);
    }

    private function grid_locked(array $node): \WP_Error
    {
        return new \WP_Error(
            'layout_locked',
            sprintf(
                '%s is part of the page grid, where Avada keeps widths and breakpoints; change the grid in the builder. '
                    . 'Elements can be added, removed and moved inside columns, and whole containers between containers.',
                $node['name'],
            ),
        );
    }

    /**
     * Whether an element called $name may sit beside $sibling.
     *
     * Containers go beside containers; elements beside elements inside a
     * column; a tab beside a tab.
     */
    private function fits(array $sibling, ?array $parent, string $name): true|\WP_Error
    {
        if ($this->is_grid($sibling)) {
            return $this->grid_locked($sibling);
        }

        if ($sibling['name'] === FusionSchema::CONTAINER || $name === FusionSchema::CONTAINER) {
            return $sibling['name'] === $name
                ? true
                : new \WP_Error(
                    'wrong_level',
                    $name === FusionSchema::CONTAINER
                        ? 'A container goes beside another container, at the top level of the page.'
                        : sprintf('%s cannot sit beside a container; put it inside a column.', $name),
                );
        }

        if (FusionSchema::is_layout($name)) {
            return new \WP_Error('wrong_level', sprintf('%s is part of the page grid and cannot be inserted here.', $name));
        }

        $owner = FusionSchema::ITEM_PARENT[$name] ?? null;
        if ($owner !== null && ($parent['name'] ?? null) !== $owner) {
            return new \WP_Error('wrong_level', sprintf('%s is an item of %s and goes only inside one.', $name, $owner));
        }

        // Inside tabs, a checklist or a gallery, only more of the same child.
        if ($parent !== null && !FusionSchema::is_layout((string) $parent['name']) && $sibling['name'] !== null && $sibling['name'] !== $name) {
            return new \WP_Error(
                'wrong_level',
                sprintf('%s holds %s items; %s cannot go among them.', $parent['name'], $sibling['name'], $name),
            );
        }

        return true;
    }

    /** move_block's second half: the markup is already on the page, so no availability checks. */
    private function insert_markup_verbatim(string $content, string $path, string $position, string $markup): string|\WP_Error
    {
        $nodes = $this->parse($content);
        $sibling = $this->find($nodes, $path);
        if ($sibling === null) {
            return new \WP_Error('block_not_found', sprintf('No element at path %s.', $path));
        }
        $moving = $this->real($this->parse($markup));
        $fit = $this->fits($sibling, $this->parent_of($nodes, $path), (string) ($moving[0]['name'] ?? ''));
        if (is_wp_error($fit)) {
            return $fit;
        }

        $offset = $position === 'before' ? $sibling['start'] : $sibling['end'];
        $result = substr($content, 0, $offset) . $markup . substr($content, $offset);
        if (count($this->names($this->parse($result))) !== count($this->names($nodes)) + count($this->names($moving))) {
            return new \WP_Error('block_roundtrip_failed', 'The moved element merged with the elements around it; refused.');
        }

        return $result;
    }

    /** @return array<int, string> element names in $nodes this site cannot render */
    private function unavailable_names(array $nodes): array
    {
        $out = [];
        foreach (array_unique($this->names($nodes)) as $name) {
            if ($name !== '(html)' && !shortcode_exists($name)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * A Library reference must point at a Library element that exists, or it
     * renders nothing.
     *
     * @return array<int, string>
     */
    private function bad_global_refs(array $nodes, string $markup): array
    {
        $out = [];
        $stack = $nodes;
        while ($stack !== []) {
            $node = array_pop($stack);
            array_push($stack, ...$node['children']);
            if ($node['name'] !== FusionSchema::GLOBAL_REF) {
                continue;
            }
            $id = (int) ($this->atts($node)['id'] ?? 0);
            $post = $id > 0 ? get_post($id) : null;
            if (!$post instanceof \WP_Post || $post->post_type !== FusionSchema::LIBRARY_POST_TYPE) {
                $out[] = sprintf('fusion_global id %d is not an Avada Library element.', $id);
            }
        }

        return $out;
    }

    /** @return array<int, string> lowercase tag names appearing in $html */
    private function tags_in(string $html): array
    {
        preg_match_all('/<\s*\/?\s*([a-zA-Z][\w:-]*)/', $html, $m);

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /**
     * Shortcode tags in $new that $old does not have as many of — the ones an
     * edit would add. Keeping or removing what is there is fine.
     *
     * @return array<int, string>
     */
    private function added_shortcodes(string $old, string $new): array
    {
        // Every tag, registered here or not: a shortcode registered only for
        // front-end requests would still run on the page.
        $count = static function (string $s): array {
            preg_match_all('/\[\/?[a-zA-Z][\w-]*(?![\w-])[^\]]*\]/', $s, $m);

            return array_count_values($m[0]);
        };

        $had = $count($old);
        $added = [];
        foreach ($count($new) as $tag => $n) {
            if ($n > ($had[$tag] ?? 0)) {
                $added[] = mb_substr($tag, 0, 60);
            }
        }

        return $added;
    }
}
