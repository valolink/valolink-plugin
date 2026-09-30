<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * What Accesslink needs to know about Avada's elements, written down.
 *
 * Fusion Builder does describe its elements — every parameter with a type —
 * but it builds that map only on its own editor screens, because building it
 * is expensive. Rather than pay for it on every agent request, the few facts
 * that matter are recorded here from the map as Fusion Builder 3.16 (Avada
 * 7.16) builds it: which elements are layout, what an element's content is,
 * and which attributes hold words a visitor reads. An element missing from
 * these lists still works — it is read by shape, see FusionReader.
 */
final class FusionSchema
{
    public const CONTAINER    = 'fusion_builder_container';
    public const ROW          = 'fusion_builder_row';
    public const COLUMN       = 'fusion_builder_column';
    public const ROW_INNER    = 'fusion_builder_row_inner';
    public const COLUMN_INNER = 'fusion_builder_column_inner';

    /** A reference to an Avada Library element, rendered in its place. */
    public const GLOBAL_REF = 'fusion_global';

    /** Library elements (`fusion_global`) and Layout sections (header, footer, …). */
    public const LIBRARY_POST_TYPE = 'fusion_element';
    public const SECTION_POST_TYPE = 'fusion_tb_section';

    /** Layout: which parent each structural element belongs in. */
    public const LAYOUT_PARENT = [
        self::CONTAINER    => null,
        self::ROW          => self::CONTAINER,
        self::COLUMN       => self::ROW,
        self::ROW_INNER    => self::COLUMN,
        self::COLUMN_INNER => self::ROW_INNER,
    ];

    /**
     * Items that exist only inside their parent element — a tab outside its
     * tabs renders as nothing useful — by the parent they belong to.
     */
    public const ITEM_PARENT = [
        'fusion_tab'                 => 'fusion_tabs',
        'fusion_toggle'              => 'fusion_accordion',
        'fusion_li_item'             => 'fusion_checklist',
        'fusion_content_box'         => 'fusion_content_boxes',
        'fusion_counter_box'         => 'fusion_counters_box',
        'fusion_counter_circle'      => 'fusion_counters_circle',
        'fusion_flip_box'            => 'fusion_flip_boxes',
        'fusion_gallery_image'       => 'fusion_gallery',
        'fusion_image'               => 'fusion_images',
        'fusion_image_hotspot_point' => 'fusion_image_hotspots',
        'fusion_pricing_column'      => 'fusion_pricing_table',
        'fusion_slide'               => 'fusion_slider',
        'fusion_testimonial'         => 'fusion_testimonials',
        'fusion_circle_info'         => 'fusion_circles_info',
        'fusion_chart_dataset'       => 'fusion_chart',
        'fusion_openstreetmap_marker' => 'fusion_openstreetmap',
        'valolink_carousel_item'     => 'valolink_carousel',
    ];

    /** Content kinds. */
    public const RICH   = 'rich';    // TinyMCE: paragraphs, headings, lists
    public const INLINE = 'inline';  // one line of text with inline formatting
    public const OPAQUE = 'opaque';  // a URL or encoded code, not words

    /**
     * Leaf elements by what their content holds. Anything not listed whose
     * content is not purely child shortcodes is treated as rich text.
     */
    public const CONTENT_KIND = [
        'fusion_text'                  => self::RICH,
        'fusion_alert'                 => self::RICH,
        'fusion_li_item'               => self::RICH,
        'fusion_content_box'           => self::RICH,
        'fusion_flip_box'              => self::RICH,
        'fusion_modal'                 => self::RICH,
        'fusion_popover'               => self::RICH,
        'fusion_pricing_column'        => self::RICH,
        'fusion_table'                 => self::RICH,
        'fusion_tab'                   => self::RICH,
        'fusion_tagline_box'           => self::RICH,
        'fusion_testimonial'           => self::RICH,
        'fusion_toggle'                => self::RICH,
        'fusion_tb_archives'           => self::RICH,
        'fusion_tb_post_card_archives' => self::RICH,
        'fusion_tb_woo_archives'       => self::RICH,
        'fusion_woo_cart_table'        => self::RICH,
        'fusion_post_cards'            => self::RICH,
        // A heading is TinyMCE in the builder, but a block-level tag inside
        // the <hN> Avada wraps it in is broken HTML.
        'fusion_title'                 => self::INLINE,
        'fusion_button'                => self::INLINE,
        'fusion_counter_box'           => self::INLINE,
        'fusion_counter_circle'        => self::INLINE,
        'awb_text_path'                => self::INLINE,
        'fusion_progress'              => self::INLINE,
        'fusion_stripe_button'         => self::INLINE,
        'fusion_dropcap'               => self::INLINE,
        'fusion_highlight'             => self::INLINE,
        'fusion_modal_text_link'       => self::INLINE,
        'fusion_one_page_text_link'    => self::INLINE,
        'fusion_person'                => self::INLINE,
        'fusion_tooltip'               => self::INLINE,
        // The image URL, not a caption.
        'fusion_imageframe'            => self::OPAQUE,
        'fusion_slide'                 => self::OPAQUE,
        'fusion_code'                  => self::OPAQUE,
        'fusion_syntax_highlighter'    => self::OPAQUE,
        'fusion_woo_shortcodes'        => self::OPAQUE,
        'fusion_map'                   => self::OPAQUE,
    ];

    /**
     * Elements whose content is code the site runs — base64 by default in a
     * Code Block. A reviewer cannot read it in a diff, so an agent may not add
     * or change one; that stays with a person in the builder.
     */
    public const CODE = ['fusion_code', 'fusion_syntax_highlighter', 'fusion_woo_shortcodes'];

    /**
     * Attributes holding words a visitor reads. Only these can be set with
     * `update_text` + `attr`, and only these may differ in a translation.
     */
    public const TEXT_ATTRS = [
        'fusion_tab'                 => ['title'],
        'fusion_toggle'              => ['title'],
        'fusion_testimonial'         => ['name', 'title_role', 'company'],
        'fusion_content_box'         => ['title', 'linktext'],
        'fusion_imageframe'          => ['alt', 'caption_title', 'caption_text'],
        'fusion_image'               => ['alt'],
        'fusion_title'               => ['before_text', 'highlight_text', 'after_text'],
        'fusion_button'              => ['title', 'hover_text'],
        'fusion_flip_box'            => ['title_front', 'title_back', 'text_front'],
        'fusion_modal'               => ['title'],
        'fusion_popover'             => ['title'],
        'fusion_tooltip'             => ['title'],
        'fusion_countdown'           => ['heading_text', 'subheading_text'],
        'fusion_person'              => ['name', 'title'],
        'fusion_lightbox'            => ['alt_text', 'title', 'description'],
        'fusion_circle_info'         => ['title'],
        'fusion_image_before_after'  => ['before_label', 'after_label'],
        'fusion_gallery'             => ['load_more_btn_text'],
        'fusion_search'              => ['placeholder'],
        'fusion_pricing_column'      => ['title', 'sub_heading', 'badge_text'],
        'fusion_youtube'             => ['title_attribute'],
        'fusion_vimeo'               => ['title_attribute'],
        'fusion_news_ticker'         => ['ticker_title'],
        'fusion_breadcrumbs'         => ['prefix', 'home_label'],
        'valolink_carousel_item'     => ['element_title'],
    ];

    public static function is_layout(string $name): bool
    {
        return array_key_exists($name, self::LAYOUT_PARENT);
    }

    /** @return array<int, string> */
    public static function text_attrs(string $name): array
    {
        return self::TEXT_ATTRS[$name] ?? [];
    }

    public static function is_code(string $name): bool
    {
        return in_array($name, self::CODE, true);
    }

    /** Fusion Builder is running here, so its shortcodes render. */
    public static function active(): bool
    {
        return defined('FUSION_BUILDER_VERSION') || shortcode_exists(self::CONTAINER);
    }
}
