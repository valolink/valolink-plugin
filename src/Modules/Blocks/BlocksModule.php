<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Blocks;

use Valolink\Plugin\Context;
use Valolink\Plugin\Module;
use Valolink\Plugin\Settings;

/**
 * Generic block support shared by every site: what a site plugin would
 * otherwise copy. One piece so far.
 *
 * Pattern overrides for GenerateBlocks Text. A synced pattern (wp_block) lets
 * each page fill in named slots — core does it for headings, paragraphs,
 * buttons and images. GenerateBlocks Text is the block our patterns use for
 * eyebrows, labels and link buttons, and two things keep it out:
 *
 *  1. Core binds only the attributes a block opts into
 *     (block_bindings_supported_attributes_{block}); GB Text opts into none.
 *  2. Core then swaps the bound value into the saved HTML through
 *     WP_Block::replace_html(), which finds the attribute's element by tag name
 *     only — GB Text's `content` attribute is sourced from the `.gb-text`
 *     class selector, so the swap silently does nothing (core's own TODO says
 *     CSS selectors are not supported yet).
 *
 * So `content` is opted in, and a render_block filter puts the bound value
 * inside GB Text's outer tag. Tested 2026-10-01/02 on kuumalahde dev1 with GB
 * 2.4.1/Pro 2.7.1 and GB 2.5 RC/Pro 2.8 RC: front end renders the page's value,
 * the editor shows and edits it. Remove when core or GenerateBlocks handles it
 * (tests/pattern-overrides-wp.php fails first — it checks core's own swap).
 */
final class BlocksModule implements Module
{
    public const MODULE_ID = 'blocks';

    private const GB_TEXT = 'generateblocks/text';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function should_load(Context $context): bool
    {
        // Two filters, both no-ops unless a bound GB Text block renders.
        return true;
    }

    public function register(): void
    {
        add_filter('block_bindings_supported_attributes_' . self::GB_TEXT, [$this, 'gb_text_bindable'], 10, 1);
        add_filter('render_block', [$this, 'gb_text_bound_content'], 10, 3);
    }

    public function uninstall(): void
    {
        $this->settings->forget_module(self::MODULE_ID);
    }

    /** @param mixed $attributes */
    public function gb_text_bindable($attributes): array
    {
        return array_values(array_unique(array_merge(is_array($attributes) ? $attributes : [], ['content'])));
    }

    /**
     * The bound value inside GB Text's outer tag.
     *
     * @param mixed $html
     * @param mixed $block
     * @param mixed $instance
     */
    public function gb_text_bound_content($html, $block, $instance = null): string
    {
        $html = (string) $html;
        if (!is_array($block) || ($block['blockName'] ?? '') !== self::GB_TEXT
            || empty($block['attrs']['metadata']['bindings']) || !$instance instanceof \WP_Block) {
            return $html;
        }
        // An icon sits next to the text inside the tag; leave those to core.
        if (!empty($block['attrs']['icon'])) {
            return $html;
        }
        $value = $instance->attributes['content'] ?? null;
        if (!is_string($value) || !preg_match('/^(\s*<([a-z0-9]+)\b[^>]*>)(.*)(<\/\2>\s*)$/is', $html, $m)) {
            return $html;
        }

        return $m[1] . $value . $m[4];
    }
}
