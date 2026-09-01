<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Content filtering for the case where the approving user lacks
 * `unfiltered_html`.
 *
 * `wp_kses_post()` alone is not usable here. It preserves block delimiters
 * fine — those are HTML comments and modern kses keeps them — but it has no
 * allowlist for inline SVG, and on a GeneratePress/GenerateBlocks site SVG
 * icons are everywhere. Measured on this project's own front page, plain
 * wp_kses_post() removed 42 <svg>, 82 <path>, and every <g>/<circle>/<defs>/
 * <mask>/<rect>, costing 14% of the document. Icons would silently vanish from
 * a page the moment a non-administrator approved a change to it.
 *
 * So the allowlist is `post` plus a deliberately conservative SVG subset.
 * Omitted on purpose, because each is an XSS vector inside SVG:
 *   - <script>, <foreignObject>, <animate>, <set>, <handler>
 *   - <use> (can pull in external documents via href)
 *   - every on* event attribute — kses drops unlisted attributes, and none are listed
 *   - <style> (CSS injection; costs a couple of gradient definitions, accepted)
 */
final class ContentSanitizer
{
    /** Shape-only SVG elements and the attributes each may carry. */
    private const SVG_COMMON = [
        'class'           => true,
        'style'           => true,
        'fill'            => true,
        'fill-opacity'    => true,
        'fill-rule'       => true,
        'clip-rule'       => true,
        'stroke'          => true,
        'stroke-width'    => true,
        'stroke-linecap'  => true,
        'stroke-linejoin' => true,
        'stroke-opacity'  => true,
        'opacity'         => true,
        'transform'       => true,
        'id'              => true,
        'data-name'       => true,
    ];

    public static function filter(string $content): string
    {
        return wp_kses($content, self::allowed_html());
    }

    /** @return array<string, array<string, bool>> */
    public static function allowed_html(): array
    {
        $allowed = wp_kses_allowed_html('post');

        $allowed['svg'] = array_merge(self::SVG_COMMON, [
            'xmlns'               => true,
            'xmlns:xlink'         => true,
            'viewbox'             => true,
            'width'               => true,
            'height'              => true,
            'preserveaspectratio' => true,
            'version'             => true,
            'role'                => true,
            'aria-hidden'         => true,
            'aria-label'          => true,
            'focusable'           => true,
            'x'                   => true,
            'y'                   => true,
        ]);

        $allowed['g']    = array_merge(self::SVG_COMMON, ['mask' => true, 'clip-path' => true]);
        $allowed['path'] = array_merge(self::SVG_COMMON, ['d' => true]);
        $allowed['circle'] = array_merge(self::SVG_COMMON, ['cx' => true, 'cy' => true, 'r' => true]);
        $allowed['ellipse'] = array_merge(self::SVG_COMMON, ['cx' => true, 'cy' => true, 'rx' => true, 'ry' => true]);
        $allowed['rect'] = array_merge(self::SVG_COMMON, [
            'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'mask' => true,
        ]);
        $allowed['line'] = array_merge(self::SVG_COMMON, ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true]);
        $allowed['polygon']  = array_merge(self::SVG_COMMON, ['points' => true]);
        $allowed['polyline'] = array_merge(self::SVG_COMMON, ['points' => true]);
        $allowed['defs']     = self::SVG_COMMON;
        $allowed['mask']     = array_merge(self::SVG_COMMON, [
            'maskunits' => true, 'x' => true, 'y' => true, 'width' => true, 'height' => true,
        ]);
        $allowed['clippath'] = array_merge(self::SVG_COMMON, ['clippathunits' => true]);
        $allowed['lineargradient'] = array_merge(self::SVG_COMMON, [
            'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradientunits' => true,
        ]);
        $allowed['radialgradient'] = array_merge(self::SVG_COMMON, [
            'cx' => true, 'cy' => true, 'r' => true, 'fx' => true, 'fy' => true, 'gradientunits' => true,
        ]);
        $allowed['stop']  = array_merge(self::SVG_COMMON, ['offset' => true, 'stop-color' => true, 'stop-opacity' => true]);
        $allowed['title'] = ['id' => true];
        $allowed['desc']  = ['id' => true];

        return $allowed;
    }
}
