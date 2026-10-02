<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * A short section description → block markup the editor accepts.
 *
 * Writing GenerateBlocks markup by hand is where agent-made pages go wrong: a
 * missing tagName saves no wrapper, a class in the HTML but not in the
 * attributes (or the other way round), `--` unescaped in a block comment.
 * Here the agent describes the section and the markup is generated, styled
 * only with the site's global styles:
 *
 *   {"element": "section", "class": "kl-section kl-section--stone", "children": [
 *     {"heading": "Ulkoporealtaat", "level": 2},
 *     {"element": "div", "class": "kl-grid-3", "children": [
 *       {"element": "div", "class": "kl-card", "children": [
 *         {"text": "Uutuus", "class": "kl-eyebrow"},
 *         {"heading": "HotSpring Highlife", "level": 3},
 *         {"paragraph": "Tilava allas <strong>koko perheelle</strong>."},
 *         {"button": "Tutustu", "href": "/highlife/", "class": "kl-button"}]}]}]}
 *
 * Nodes: element (GenerateBlocks Element: a container), heading and paragraph
 * (core blocks — inline-editable and pattern-overridable), text (GenerateBlocks
 * Text: eyebrows, labels), button (GenerateBlocks Text as a link), list, image
 * (an attachment), pattern (a synced pattern with slot values). Heading,
 * paragraph, text, button and image take "slot": "Name" — in a synced pattern
 * that block's content becomes an override each page fills in. Classes must be
 * global styles that exist. Blocks are built as parsed-block arrays and written
 * by WordPress' own serialize_blocks(), so the comment JSON is escaped exactly
 * as the editor escapes it.
 */
final class BlockCompiler
{
    public const MAX_NODES = 300;
    public const MAX_DEPTH = 8;

    private const ELEMENT_TAGS = ['div', 'section', 'article', 'aside', 'header', 'footer', 'nav', 'figure', 'ul', 'ol', 'li'];
    private const TEXT_TAGS    = ['p', 'span', 'div', 'strong', 'small', 'figcaption'];
    private const INLINE       = [
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'br' => [], 'sup' => [], 'sub' => [], 'mark' => [],
        'a' => ['href' => true, 'target' => true, 'rel' => true],
        'span' => ['class' => true],
    ];

    /** @var array<string, true> */
    private array $classes = [];
    /** @var list<string> */
    private array $issues = [];
    /** @var array<string, true> */
    private array $used = [];
    private int $count = 0;
    private string $seed = '';
    /** @var array<string, true> */
    private array $ids = [];
    /** @var array<string, true> */
    private array $slots = [];

    /** Blocks whose content a synced pattern lets each page override (GB Text through the Blocks module). */
    private const SLOT_BLOCKS = ['core/heading', 'core/paragraph', 'core/image', 'generateblocks/text'];

    public function __construct(private readonly StyleReader $reader = new StyleReader())
    {
    }

    /**
     * @param array|list<array> $tree one node or a list of nodes
     * @return array{markup: string, blocks: int, classes: list<string>}|\WP_Error
     */
    public function compile(array $tree, string $seed = ''): array|\WP_Error
    {
        $this->classes = [];
        foreach ($this->reader->styles() as $style) {
            if (str_starts_with($style['selector'], '.')) {
                $this->classes[substr($style['selector'], 1)] = true;
            }
        }
        $this->issues = [];
        $this->used = [];
        $this->count = 0;
        $this->ids = [];
        $this->slots = [];
        $this->seed = $seed !== '' ? $seed : wp_generate_password(8, false);

        $nodes = array_is_list($tree) ? $tree : [$tree];
        $blocks = [];
        foreach ($nodes as $i => $node) {
            $block = $this->node($node, (string) $i, 0);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }
        if ($this->count > self::MAX_NODES) {
            $this->issues[] = sprintf('%d nodes; at most %d per compile.', $this->count, self::MAX_NODES);
        }
        if ($this->issues !== []) {
            return new \WP_Error('invalid_tree', implode(' ', $this->issues), ['status' => 400, 'issues' => $this->issues]);
        }

        $markup = serialize_blocks($blocks);
        $check = Documents::check($markup);
        if ($check !== []) {
            return new \WP_Error('invalid_block_markup', implode(' ', $check), ['status' => 500, 'issues' => $check]);
        }

        return ['markup' => $markup, 'blocks' => $this->count, 'classes' => array_keys($this->used)];
    }

    // -------------------------------------------------------------------------

    private function node(mixed $node, string $path, int $depth): ?array
    {
        if (!is_array($node)) {
            $this->issues[] = sprintf('%s: a node is an object.', $path);

            return null;
        }
        if ($depth >= self::MAX_DEPTH) {
            $this->issues[] = sprintf('%s: nested deeper than %d.', $path, self::MAX_DEPTH);

            return null;
        }
        $this->count++;

        $block = match (true) {
            isset($node['element'])   => $this->element($node, $path, $depth),
            isset($node['heading'])   => $this->heading($node, $path),
            isset($node['paragraph']) => $this->paragraph($node, $path),
            isset($node['text'])      => $this->text($node, $path),
            isset($node['button'])    => $this->button($node, $path),
            isset($node['list'])      => $this->list($node, $path),
            isset($node['image'])     => $this->image($node, $path),
            isset($node['pattern'])   => $this->pattern($node, $path),
            default                   => $this->unknown($node, $path),
        };

        return $block !== null && isset($node['slot']) ? $this->slot($block, $node['slot'], $path) : $block;
    }

    /** Mark a block as a pattern override slot. */
    private function slot(array $block, mixed $name, string $path): ?array
    {
        $name = is_string($name) ? trim($name) : '';
        if ($name === '' || mb_strlen($name) > 40 || preg_match('/[<>"{}]/', $name)) {
            $this->issues[] = sprintf('%s: slot is a short name (at most 40 characters), e.g. "Otsikko".', $path);

            return $block;
        }
        if (!in_array($block['blockName'], self::SLOT_BLOCKS, true)) {
            $this->issues[] = sprintf('%s: only heading, paragraph, text, button and image can be slots.', $path);

            return $block;
        }
        if (isset($this->slots[$name])) {
            $this->issues[] = sprintf('%s: slot "%s" is used twice; slot names are unique within a pattern.', $path, $name);

            return $block;
        }
        $this->slots[$name] = true;
        $block['attrs'] = ['metadata' => [
            'name'     => $name,
            'bindings' => ['__default' => ['source' => 'core/pattern-overrides']],
        ]] + $block['attrs'];

        return $block;
    }

    private function element(array $node, string $path, int $depth): ?array
    {
        $tag = (string) $node['element'];
        if (!in_array($tag, self::ELEMENT_TAGS, true)) {
            $this->issues[] = sprintf('%s: element must be one of %s.', $path, implode(', ', self::ELEMENT_TAGS));

            return null;
        }
        $classes = $this->classes((string) ($node['class'] ?? ''), $path);
        $children = [];
        foreach ((array) ($node['children'] ?? []) as $i => $child) {
            $block = $this->node($child, $path . '.' . $i, $depth + 1);
            if ($block !== null) {
                $children[] = $block;
            }
        }
        $attrs = ['uniqueId' => $this->unique_id($path), 'tagName' => $tag];
        if ($classes !== []) {
            $attrs['globalClasses'] = $classes;
        }
        // GenerateBlocks' save writes the global classes only; gb-element is
        // added when the block renders.
        $open = '<' . $tag . ($classes !== [] ? ' class="' . esc_attr(implode(' ', $classes)) . '"' : '') . '>';

        return $this->block('generateblocks/element', $attrs, $children, $open, '</' . $tag . '>');
    }

    private function heading(array $node, string $path): ?array
    {
        $level = (int) ($node['level'] ?? 2);
        if ($level < 1 || $level > 6) {
            $this->issues[] = sprintf('%s: level is 1–6.', $path);

            return null;
        }
        $classes = $this->classes((string) ($node['class'] ?? ''), $path);
        $attrs = [];
        if ($level !== 2) {
            $attrs['level'] = $level;
        }
        if ($classes !== []) {
            $attrs['className'] = implode(' ', $classes);
        }
        $class = trim('wp-block-heading ' . implode(' ', $classes));

        return $this->block('core/heading', $attrs, [], sprintf('<h%d class="%s">%s</h%1$d>', $level, esc_attr($class), $this->inline((string) $node['heading'], $path)));
    }

    private function paragraph(array $node, string $path): ?array
    {
        $classes = $this->classes((string) ($node['class'] ?? ''), $path);
        $attrs = $classes !== [] ? ['className' => implode(' ', $classes)] : [];
        $open = $classes !== [] ? '<p class="' . esc_attr(implode(' ', $classes)) . '">' : '<p>';

        return $this->block('core/paragraph', $attrs, [], $open . $this->inline((string) $node['paragraph'], $path) . '</p>');
    }

    private function text(array $node, string $path): ?array
    {
        $tag = (string) ($node['tag'] ?? 'p');
        if (!in_array($tag, self::TEXT_TAGS, true)) {
            $this->issues[] = sprintf('%s: text tag must be one of %s.', $path, implode(', ', self::TEXT_TAGS));

            return null;
        }
        $classes = $this->classes((string) ($node['class'] ?? ''), $path);
        $attrs = ['uniqueId' => $this->unique_id($path), 'tagName' => $tag];
        if ($classes !== []) {
            $attrs['globalClasses'] = $classes;
        }
        $class = trim('gb-text ' . implode(' ', $classes));

        return $this->block('generateblocks/text', $attrs, [], sprintf('<%s class="%s">%s</%1$s>', $tag, esc_attr($class), $this->inline((string) $node['text'], $path)));
    }

    private function button(array $node, string $path): ?array
    {
        $href = trim((string) ($node['href'] ?? ''));
        if ($href === '' || !preg_match('#^(https?://|/|\#|mailto:|tel:)#i', $href)) {
            $this->issues[] = sprintf('%s: a button needs an href (https://…, /path/, #anchor, mailto: or tel:).', $path);

            return null;
        }
        $classes = $this->classes((string) ($node['class'] ?? ''), $path);
        $html_attrs = ['href' => esc_url_raw($href)];
        if (!empty($node['new_tab'])) {
            $html_attrs['target'] = '_blank';
            $html_attrs['rel'] = 'noopener';
        }
        $attrs = ['uniqueId' => $this->unique_id($path), 'tagName' => 'a'];
        if ($classes !== []) {
            $attrs['globalClasses'] = $classes;
        }
        $attrs['htmlAttributes'] = $html_attrs;
        $html = '';
        foreach ($html_attrs as $name => $value) {
            $html .= sprintf(' %s="%s"', $name, esc_attr($value));
        }
        $class = trim('gb-text ' . implode(' ', $classes));

        return $this->block('generateblocks/text', $attrs, [], sprintf('<a class="%s"%s>%s</a>', esc_attr($class), $html, $this->inline((string) $node['button'], $path)));
    }

    private function list(array $node, string $path): ?array
    {
        $items = $node['list'];
        if (!is_array($items) || $items === []) {
            $this->issues[] = sprintf('%s: list is a list of item texts.', $path);

            return null;
        }
        $ordered = !empty($node['ordered']);
        $classes = $this->classes((string) ($node['class'] ?? ''), $path);
        $children = [];
        foreach (array_values($items) as $i => $item) {
            $this->count++;
            $children[] = $this->block('core/list-item', [], [], '<li>' . $this->inline((string) $item, $path . '.' . $i) . '</li>');
        }
        $attrs = [];
        if ($ordered) {
            $attrs['ordered'] = true;
        }
        if ($classes !== []) {
            $attrs['className'] = implode(' ', $classes);
        }
        $tag = $ordered ? 'ol' : 'ul';

        return $this->block('core/list', $attrs, $children, sprintf('<%s class="%s">', $tag, esc_attr(trim('wp-block-list ' . implode(' ', $classes)))), '</' . $tag . '>');
    }

    private function image(array $node, string $path): ?array
    {
        $id = (int) $node['image'];
        $size = (string) ($node['size'] ?? 'large');
        $src = $id > 0 ? wp_get_attachment_image_url($id, $size) : false;
        if (!$src) {
            $this->issues[] = sprintf('%s: image is an attachment id from GET /media (size "%s").', $path, $size);

            return null;
        }
        $alt = isset($node['alt']) ? (string) $node['alt'] : (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $classes = $this->classes((string) ($node['class'] ?? ''), $path);
        $attrs = ['id' => $id, 'sizeSlug' => $size, 'linkDestination' => 'none'];
        if ($classes !== []) {
            $attrs['className'] = implode(' ', $classes);
        }
        $figure = trim('wp-block-image size-' . $size . ' ' . implode(' ', $classes));

        return $this->block('core/image', $attrs, [], sprintf(
            '<figure class="%s"><img src="%s" alt="%s" class="wp-image-%d"/></figure>',
            esc_attr($figure),
            esc_url($src),
            esc_attr($alt),
            $id,
        ));
    }

    private function pattern(array $node, string $path): ?array
    {
        $ref = $node['pattern'];
        $post = is_numeric($ref)
            ? get_post((int) $ref)
            : (get_posts(['post_type' => 'wp_block', 'title' => (string) $ref, 'post_status' => 'publish', 'numberposts' => 1])[0] ?? null);
        if (!$post instanceof \WP_Post || $post->post_type !== 'wp_block' || $post->post_status !== 'publish') {
            $this->issues[] = sprintf('%s: no published pattern %s — see GET /styles → patterns.', $path, (string) wp_json_encode($ref));

            return null;
        }
        $slots = [];
        foreach ($this->reader->patterns() as $pattern) {
            if ($pattern['id'] === (int) $post->ID) {
                foreach ($pattern['slots'] as $slot) {
                    $slots[$slot['name']] = $slot['block'];
                }
            }
        }
        $content = [];
        foreach ((array) ($node['slots'] ?? []) as $name => $value) {
            if (!isset($slots[$name])) {
                $this->issues[] = sprintf('%s: pattern "%s" has no slot "%s" (it has: %s).', $path, $post->post_title, $name, implode(', ', array_keys($slots)) ?: 'none');
                continue;
            }
            if ($slots[$name] === 'core/button') {
                $value = is_array($value) ? $value : ['text' => (string) $value];
                $entry = ['text' => $this->inline((string) ($value['text'] ?? ''), $path)];
                if (isset($value['url'])) {
                    $entry['url'] = esc_url_raw((string) $value['url']);
                }
                $content[$name] = $entry;
            } elseif ($slots[$name] === 'core/image') {
                $id = (int) (is_array($value) ? ($value['id'] ?? 0) : $value);
                $url = $id > 0 ? wp_get_attachment_image_url($id, 'large') : false;
                if (!$url) {
                    $this->issues[] = sprintf('%s: slot "%s" takes an attachment id.', $path, $name);
                    continue;
                }
                $content[$name] = ['id' => $id, 'url' => $url, 'alt' => (string) (is_array($value) ? ($value['alt'] ?? '') : '')];
            } else {
                $content[$name] = ['content' => $this->inline((string) $value, $path)];
            }
        }
        $attrs = ['ref' => (int) $post->ID];
        if ($content !== []) {
            $attrs['content'] = $content;
        }

        return ['blockName' => 'core/block', 'attrs' => $attrs, 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
    }

    private function unknown(array $node, string $path): ?array
    {
        $this->issues[] = sprintf(
            '%s: unknown node (keys: %s). Use element, heading, paragraph, text, button, list, image or pattern.',
            $path,
            implode(', ', array_keys($node)),
        );

        return null;
    }

    /** @return list<string> */
    private function classes(string $class, string $path): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($class)) ?: [] as $name) {
            if ($name === '') {
                continue;
            }
            $name = ltrim($name, '.');
            if (!isset($this->classes[$name])) {
                $this->issues[] = sprintf('%s: "%s" is not a global style on this site (GET /styles lists them; propose one with set_style).', $path, $name);
                continue;
            }
            $this->used[$name] = true;
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }

    /** Inline text: the formatting a paragraph allows, nothing more. */
    private function inline(string $html, string $path): string
    {
        $clean = wp_kses($html, self::INLINE);
        if (trim($clean) === '' && trim($html) !== '') {
            $this->issues[] = sprintf('%s: the text was empty after removing markup that is not inline.', $path);
        }

        return $clean;
    }

    /** 8 lowercase hex, unique within this compile, stable for the same seed and path. */
    private function unique_id(string $path): string
    {
        $n = 0;
        do {
            $id = substr(md5($this->seed . '|' . $path . '|' . $n++), 0, 8);
        } while (isset($this->ids[$id]));
        $this->ids[$id] = true;

        return $id;
    }

    /** A parsed-block array; inner blocks go between the opening and closing HTML. */
    private function block(string $name, array $attrs, array $children, string $open, string $close = ''): array
    {
        $content = [$open];
        foreach ($children as $i => $child) {
            $content[] = null;
            if ($i < count($children) - 1) {
                $content[] = '';
            }
        }
        $content[] = $close;
        $content = array_values(array_filter($content, static fn ($c): bool => $c !== ''));
        $inner_html = implode('', array_filter($content, 'is_string'));

        return [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $children,
            'innerHTML'    => $inner_html,
            'innerContent' => $content,
        ];
    }
}
