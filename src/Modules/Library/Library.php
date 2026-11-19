<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Library;

/**
 * A site's design library, read from a directory kept in the site's repo:
 *
 *   design.json    {"tokens": [{name, value, type, label, category, scope}, …],
 *                   "styles": [{selector, category, styles}, …]}
 *   patterns.json  {"category": {slug, label}, "patterns": [{slug, title, synced, tree}, …]}
 *   showcase.json  {"pages": [{slug, title, meta?, blocks: [tree, …]}, …]}
 *   icons/<name>.svg
 *
 * The same shapes as valolink-kuumalahde's fold 02, so either can be applied
 * with the other's tools. Every file is optional. Trees are BlockCompiler
 * trees; in them "icon": "@name" is icons/<name>.svg and "pattern": "@slug"
 * is the library pattern with that slug.
 */
final class Library
{
    private array $design = [];
    private array $patterns = [];
    private array $showcase = [];
    /** @var array<string, string> */
    private array $icons = [];

    public function __construct(public readonly string $dir)
    {
        $this->design = $this->json('design.json');
        $this->patterns = $this->json('patterns.json');
        $this->showcase = $this->json('showcase.json');
        foreach (glob($dir . '/icons/*.svg') ?: [] as $file) {
            $this->icons[basename($file, '.svg')] = trim((string) file_get_contents($file));
        }
    }

    /** @return list<array> token rows as design.json has them */
    public function tokens(): array
    {
        return array_values((array) ($this->design['tokens'] ?? []));
    }

    /** @return list<array{selector: string, category: string, styles: array}> */
    public function styles(): array
    {
        return array_map(static fn (array $s): array => [
            'selector' => (string) $s['selector'],
            'category' => (string) ($s['category'] ?? ''),
            'styles'   => (array) $s['styles'],
        ], array_values((array) ($this->design['styles'] ?? [])));
    }

    /** @return array{slug?: string, label?: string} */
    public function pattern_category(): array
    {
        return (array) ($this->patterns['category'] ?? []);
    }

    /** @return list<array{slug: string, title: string, synced: bool, tree: array}> */
    public function patterns(): array
    {
        return array_map(fn (array $p): array => [
            'slug'   => sanitize_title((string) $p['slug']),
            'title'  => (string) $p['title'],
            'synced' => (bool) ($p['synced'] ?? true),
            'tree'   => $this->resolve((array) $p['tree']),
        ], array_values((array) ($this->patterns['patterns'] ?? [])));
    }

    public function page_count(): int
    {
        return count((array) ($this->showcase['pages'] ?? []));
    }

    /** @return list<array{slug: string, title: string, blocks: list<array>, meta: array}> resolved, so the patterns they name must exist */
    public function pages(): array
    {
        return array_map(fn (array $p): array => [
            'slug'   => sanitize_title((string) $p['slug']),
            'title'  => (string) $p['title'],
            'blocks' => array_map(fn ($b): array => $this->resolve((array) $b), array_values((array) ($p['blocks'] ?? []))),
            'meta'   => (array) ($p['meta'] ?? []),
        ], array_values((array) ($this->showcase['pages'] ?? [])));
    }

    /** "@name" references in a tree → the icon's SVG, the pattern's id. */
    public function resolve(array $node): array
    {
        if (isset($node['icon']) && is_string($node['icon']) && str_starts_with($node['icon'], '@')) {
            $name = substr($node['icon'], 1);
            if (!isset($this->icons[$name])) {
                throw new \RuntimeException(sprintf('no icon "%s" in %s/icons/', $name, $this->dir));
            }
            $node['icon'] = $this->icons[$name];
        }
        if (isset($node['pattern']) && is_string($node['pattern']) && str_starts_with($node['pattern'], '@')) {
            $post = PatternWriter::find(sanitize_title(substr($node['pattern'], 1)));
            if ($post === null) {
                throw new \RuntimeException(sprintf('no pattern "%s" on this site yet (apply the patterns first)', $node['pattern']));
            }
            $node['pattern'] = $post->ID;
        }
        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = array_map(fn ($c): array => is_array($c) ? $this->resolve($c) : $c, $node['children']);
        }
        if (array_is_list($node)) {
            return array_map(fn ($c): array => is_array($c) ? $this->resolve($c) : $c, $node);
        }

        return $node;
    }

    private function json(string $file): array
    {
        $path = $this->dir . '/' . $file;
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('%s is not valid JSON: %s', $path, json_last_error_msg()));
        }

        return $data;
    }
}
