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

    /** A Custom HTML block (core/html), delimiters included, as one capture. */
    private const HTML_BLOCK = '/(<!--\s*wp:html(?:\s[^>]*)?-->.*?<!--\s*\/wp:html\s*-->)/is';

    /**
     * A script, style or iframe element with its contents. An iframe is an
     * embed — a map, a booking widget, a video — and a Custom HTML block is
     * where a site keeps those; `post` kses has no iframe, so without this a
     * page carrying a map lost it the moment an agent proposed the page.
     */
    private const CODE = '/<(script|style|iframe)\b[^>]*>.*?<\/\1\s*>/is';

    /** A block delimiter, opening or closing — the seams a document is compared at. */
    private const DELIMITER = '/(<!--\s+\/?wp:.*?-->)/s';

    /**
     * A Fusion body has no block delimiters, so it is cut at its shortcode
     * tags instead. Without this the whole page is one piece: changing one
     * word ran kses over all of it and stripped the iframes and styles the
     * site's own Text Blocks carry — the defect 0.2.7 fixed for blocks.
     */
    private const FUSION_DELIMITER = '/(<!--\s+\/?wp:.*?-->|\[\/?[a-zA-Z][\w-]*(?![\w-])[^\]]*\])/s';

    /** One whole delimiter, in the block parser's own grammar (WP_Block_Parser::next_token). */
    private const BLOCK_DELIMITER = '/^<!--\s+(?P<closer>\/)?wp:(?P<name>(?:[a-z][a-z0-9_-]*\/)?[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->$/s';

    /**
     * A colour function with plain arguments: numbers, units, commas, slashes,
     * keywords like `none` or `deg`. No parentheses, quotes, backslashes or
     * `&=}`, so nothing inside it can open a url() or an expression.
     */
    private const COLOUR_FUNCTION = '/\b(?:rgba?|hsla?)\([\w.,%\s\/+-]*\)/i';

    /**
     * Filter agent-authored markup.
     *
     * A Custom HTML block is the one place on a page where <script>,
     * <style> and <iframe> are the content rather than an intrusion: that is what the
     * block exists for, and a site's small custom widgets live there. Inside
     * such a block they are kept verbatim, the rest of the block is filtered
     * as usual, and the proposal's summary says the block carries code, so
     * the reviewer reads it before approving. Everywhere else the two are
     * stripped as before.
     *
     * A whole-body update is where most of the document is *not* the agent's:
     * it carries the page over and changes one part. Filtering all of it
     * rewrote the site's own markup — `background-color:rgba(0, 0, 0, 0)` on
     * the editor's highlights fails core's CSS check, and a bare <mark> is
     * yellow. So when the current document is given, every piece of the
     * proposal that the current document already contains, in the same kind
     * of context, passes through as it is; only new or changed pieces are
     * filtered. Pieces are what lies between block delimiters, plus the
     * delimiters themselves, and a Custom HTML block as one unit. Passing a
     * piece through adds nothing the site does not already serve.
     *
     * @param bool $html_block The fragment is the content of a core/html
     *   block (a block edit addressed by path); markup and whole documents
     *   are recognised by their delimiters instead.
     * @param string|null $current The document this one replaces, for an
     *   update of a whole post body; null for anything new.
     */
    public static function filter(string $content, bool $html_block = false, ?string $current = null): string
    {
        add_filter('safecss_filter_attr_allow_css', [self::class, 'allow_colour_functions'], 10, 2);
        try {
            return $html_block
                ? self::filter_keeping_code($content)
                : self::filter_document($content, $current ?? '');
        } finally {
            remove_filter('safecss_filter_attr_allow_css', [self::class, 'allow_colour_functions'], 10);
        }
    }

    /**
     * Let rgb(), rgba(), hsl() and hsla() through core's inline-CSS check.
     * Core allows var(), calc() and a few others but refuses any other
     * parenthesis, and the editor's text-colour tool writes exactly
     * `background-color:rgba(0, 0, 0, 0)`. Registered only while this class
     * filters, so the rest of the site keeps core's rule.
     */
    public static function allow_colour_functions(bool $allow, string $css_test_string): bool
    {
        if ($allow) {
            return true;
        }
        $stripped = (string) preg_replace(self::COLOUR_FUNCTION, '', $css_test_string);

        // The same test core applies, on what is left.
        return $stripped !== $css_test_string && !preg_match('%[\\\\(&=}]|/\*%', $stripped);
    }

    private static function filter_document(string $content, string $current): string
    {
        $basis = $current !== '' ? $current : $content;
        $split = FusionReader::is_fusion($basis) && preg_match(self::DELIMITER, $basis) !== 1
            ? self::FUSION_DELIMITER
            : self::DELIMITER;
        [$known_html, $known] = self::pieces_of($current, $split);

        $out = '';
        foreach (self::split_html_blocks($content) as $piece) {
            if (self::is_html_block($piece)) {
                $out .= isset($known_html[$piece]) ? $piece : self::filter_keeping_code($piece);
                continue;
            }
            foreach (preg_split($split, $piece, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (isset($known[$token])) {
                    $out .= $token;
                } elseif (preg_match(self::BLOCK_DELIMITER, $token, $m) === 1) {
                    $out .= self::filter_delimiter($token, $m);
                } else {
                    $out .= wp_kses($token, self::allowed_html());
                }
            }
        }

        return $out;
    }

    /**
     * A block delimiter on its own must not go through wp_kses(). Core hooks
     * pre_kses to parse its input as blocks and serialise them back, and an
     * opener with no closer parses as a void block: `<!-- wp:paragraph -->`
     * comes back as `<!-- wp:paragraph /-->`, and every container on a new
     * page is emptied, its content spilling out as loose HTML. That shipped
     * in 0.2.7 and broke every create and insert_block.
     *
     * What kses does to a delimiter in a whole document is filter its
     * attribute values, through filter_block_kses_value(). That is done here
     * directly, and the delimiter is rebuilt only when filtering changed
     * something, so clean attributes keep their exact JSON.
     *
     * @param array<int|string, string> $m BLOCK_DELIMITER's match.
     */
    private static function filter_delimiter(string $token, array $m): string
    {
        if (($m['attrs'] ?? '') === '') {
            return $token;
        }
        $closer = $m['closer'] ?? '';
        $void   = $m['void'] ?? '';

        $attrs = json_decode($m['attrs'], true);
        if (!is_array($attrs)) {
            // The block parser reads unparseable attributes as none at all.
            return '<!-- ' . $closer . 'wp:' . $m['name'] . ' ' . $void . '-->';
        }

        $filtered = filter_block_kses_value($attrs, self::allowed_html(), wp_allowed_protocols(), ['blockName' => $m['name']]);
        if ($filtered === $attrs) {
            return $token;
        }

        return '<!-- ' . $closer . 'wp:' . $m['name'] . ' ' . serialize_block_attributes($filtered) . ' ' . $void . '-->';
    }

    /**
     * The current document's pieces, as sets: its Custom HTML blocks whole,
     * and everything else split at block delimiters. Kept apart, so markup
     * that lives inside an HTML block — where code is allowed — cannot pass
     * through raw anywhere else.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private static function pieces_of(string $current, string $split = self::DELIMITER): array
    {
        $known_html = [];
        $known = [];
        foreach (self::split_html_blocks($current) as $piece) {
            if (self::is_html_block($piece)) {
                $known_html[$piece] = true;
                continue;
            }
            foreach (preg_split($split, $piece, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $known[$token] = true;
            }
        }

        return [$known_html, $known];
    }

    /** @return array<int, string> */
    private static function split_html_blocks(string $content): array
    {
        return preg_split(self::HTML_BLOCK, $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private static function is_html_block(string $piece): bool
    {
        return preg_match(self::HTML_BLOCK, $piece) === 1 && str_starts_with(ltrim($piece), '<!--');
    }

    /** Whether the markup carries a <script>, <style> or <iframe>, for the reviewer. */
    public static function has_code(string $content): bool
    {
        return (bool) preg_match(self::CODE, $content);
    }

    private static function filter_keeping_code(string $content): string
    {
        $kept = [];
        $stripped = (string) preg_replace_callback(self::CODE, static function (array $m) use (&$kept): string {
            $kept[] = $m[0];

            return '<!--valolink-code-' . (count($kept) - 1) . '-->';
        }, $content);
        $filtered = wp_kses($stripped, self::allowed_html());

        return (string) preg_replace_callback(
            '/<!--valolink-code-(\d+)-->/',
            static fn (array $m): string => $kept[(int) $m[1]] ?? '',
            $filtered,
        );
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
