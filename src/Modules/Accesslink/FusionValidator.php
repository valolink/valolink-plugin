<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Accesslink;

/**
 * Server-side checks on Fusion Builder markup.
 *
 * Fusion Builder does not re-run each element's save and compare, the way
 * Gutenberg does, so there is no "invalid block" verdict to reproduce. The
 * failures that matter show on the rendered page or in the builder's grid: a
 * tag left open that swallows the rest of the page, a closer with nothing to
 * close printed as text, an element outside a column, words sitting loose
 * between elements where the builder has no element to edit them through.
 *
 * Messages carry element names and never paths, so the same problem reads the
 * same before and after an edit that renumbered the page, and check_diff()
 * can tell what is new.
 */
final class FusionValidator
{
    /**
     * Issues a change introduces. Counted rather than de-duplicated, so adding
     * a second loose paragraph to a page that already had one still reports.
     *
     * @return array<int, string>
     */
    public function check_diff(string $before, string $after): array
    {
        $had = array_count_values($this->check_all($before));
        $out = [];
        foreach (array_count_values($this->check_all($after)) as $issue => $n) {
            if ($n > ($had[$issue] ?? 0)) {
                $out[] = $issue;
            }
        }

        foreach ($this->code_changes($before, $after) as $issue) {
            $out[] = $issue;
        }

        return $out;
    }

    /** @return array<int, string> */
    public function check(string $content): array
    {
        return array_values(array_unique($this->check_all($content)));
    }

    /** @return array<int, string> every occurrence, duplicates kept */
    private function check_all(string $content): array
    {
        $issues = [];
        $reader = new FusionReader();
        $tree = $reader->parse($content);
        $this->visit($reader, $content, $tree, null, $issues, $this->is_page($tree));

        return $issues;
    }

    /**
     * A page built in the builder starts with a container. A Library element
     * or a Layout section may be a lone element, and must not be told off for
     * having no grid.
     */
    private function is_page(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node['name'] === FusionSchema::CONTAINER) {
                return true;
            }
        }

        return false;
    }

    private function visit(FusionReader $reader, string $content, array $nodes, ?array $parent, array &$issues, bool $page): void
    {
        $parent_name = $parent['name'] ?? null;

        foreach ($nodes as $node) {
            $name = $node['name'];

            if ($name === null) {
                $raw = substr($content, $node['start'], $node['end'] - $node['start']);
                $text = trim(wp_strip_all_tags($raw));
                if ($this->has_stray_closer($raw)) {
                    $issues[] = sprintf(
                        'A closing shortcode with nothing open %s: it prints on the page as text.',
                        $parent_name === null ? 'at the top level' : 'inside ' . $parent_name,
                    );
                } elseif ($parent_name !== null && FusionSchema::is_layout($parent_name) && $text !== '') {
                    $issues[] = sprintf(
                        'Text directly inside %s, outside any element: it renders unstyled and the builder has no element to edit it through. Put it in a Text Block.',
                        $parent_name,
                    );
                } elseif ($parent_name === null && $page && $text !== '') {
                    $issues[] = 'Text between containers: it renders outside the page layout and the builder has no element to edit it through.';
                }
                continue;
            }

            // Only Fusion's own tags: a site's custom element may register its
            // shortcode for front-end requests alone, and this runs over REST.
            if (preg_match('/^(?:fusion|awb)_/', $name) && !shortcode_exists($name)) {
                $issues[] = sprintf('Unknown element "%s" — not available on this site, so it prints as text.', $name);
            }

            if ($node['close_start'] === null && FusionSchema::is_layout($name)) {
                $issues[] = sprintf('%s is never closed: add [/%s], or it swallows what follows.', $name, $name);
            }

            // A Library reference can stand for a container, a column or an
            // element, so it fits wherever it is; and a Library element or
            // Layout section without containers may start at any level.
            $expected = array_key_exists($name, FusionSchema::LAYOUT_PARENT) ? FusionSchema::LAYOUT_PARENT[$name] : false;
            if ($name === FusionSchema::GLOBAL_REF || ($parent_name === null && !$page)) {
                // placement is not checkable
            } elseif ($expected !== false && $expected !== $parent_name) {
                $issues[] = $expected === null
                    ? sprintf('%s inside %s: a container belongs at the top level of the page.', $name, $parent_name)
                    : sprintf('%s inside %s: it belongs directly inside %s.', $name, $parent_name ?? 'the page', $expected);
            } elseif ($expected === false && in_array($parent_name, [FusionSchema::CONTAINER, FusionSchema::ROW, FusionSchema::ROW_INNER], true)) {
                $issues[] = sprintf('%s directly inside %s: elements go inside a column.', $name, $parent_name);
            } elseif ($expected === false && $parent_name === null && $page) {
                $issues[] = sprintf('%s between containers: elements go inside a column.', $name);
            }

            foreach ($reader->atts($node) as $attr => $value) {
                if (preg_match('/^\s*(?:(?:java|vb)script\s*:|data\s*:\s*text\/html)/i', $value) || stripos($value, '<script') !== false) {
                    $issues[] = sprintf('%s: attribute %s holds a script, which is not allowed.', $name, $attr);
                }
            }

            if ($node['children'] !== []) {
                $this->visit($reader, $content, $node['children'], $node, $issues, $page);
            }
        }
    }

    /**
     * A Code Block's content is base64 by default: a reviewer cannot read it
     * in the diff, and it runs as whatever it decodes to. Adding or changing
     * one is left to a person in the builder.
     *
     * @return array<int, string>
     */
    private function code_changes(string $before, string $after): array
    {
        $had = array_count_values($this->code_blocks($before));
        $out = [];
        foreach (array_count_values($this->code_blocks($after)) as $markup => $n) {
            if ($n > ($had[$markup] ?? 0)) {
                preg_match('/^\[([\w-]+)/', $markup, $m);
                $out[] = sprintf(
                    '%s added or changed: code elements are edited by a person in the builder, not proposed.',
                    $m[1] ?? 'A code element',
                );
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<int, string> the full markup of every code element */
    private function code_blocks(string $content): array
    {
        $out = [];
        foreach (FusionSchema::CODE as $name) {
            if (preg_match_all('/\[' . preg_quote($name, '/') . '(?![\w-])[^\]]*\].*?\[\/' . preg_quote($name, '/') . '\]/s', $content, $m)) {
                array_push($out, ...$m[0]);
            }
        }

        return $out;
    }

    private function has_stray_closer(string $text): bool
    {
        return (bool) preg_match('/\[\/(?:fusion|awb)_[a-z0-9_]+\]/', $text);
    }
}
