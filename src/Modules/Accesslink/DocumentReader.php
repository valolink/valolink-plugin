<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Path addressing over a post body, whatever the body is built from.
 *
 * Two page builders store their structure in `post_content`: Gutenberg as
 * HTML-comment delimiters, Avada's Fusion Builder as nested shortcodes. The
 * agent-facing contract is the same for both — a flat list of dot-joined
 * paths, text replaced inside a leaf, siblings inserted, deleted and moved —
 * so every caller asks Documents::reader() for the right implementation and
 * never needs to know which one it got.
 */
interface DocumentReader
{
    /** @return array{blocks: array<int, array>, total: int, truncated: bool} */
    public function flatten(string $content): array;

    /** @return array|null the node descriptor at $path, including its full `html` */
    public function get_at(string $content, string $path): ?array;

    /** The editable text of the node at $path, or null when it has none. */
    public function text_html(string $content, string $path): ?string;

    public function replace_text_at(string $content, string $path, string $inner, bool $inline_only = true): string|\WP_Error;

    /**
     * Replace one text-bearing attribute of the node at $path — a tab title,
     * an image's alt text. Only Fusion keeps copy in attributes; a block's
     * attributes are data and are refused.
     */
    public function replace_attr_at(string $content, string $path, string $attr, string $value): string|\WP_Error;

    public function replace_at(string $content, string $path, string $html): string|\WP_Error;

    public function insert_block(string $content, string $path, string $position, string $markup): string|\WP_Error;

    public function delete_block(string $content, string $path): string|\WP_Error;

    public function move_block(string $content, string $path, string $target_path, string $position): string|\WP_Error;

    /**
     * Every piece of markup with the words taken out. Two documents with the
     * same skeleton differ only in their text, which is what a translation is
     * allowed to change.
     */
    public function skeleton(string $content): string;

    /** A node's markup laid out for a line diff in the review queue. */
    public function display_html(string $html): string;
}
