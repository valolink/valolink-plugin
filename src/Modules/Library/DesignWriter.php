<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Library;

use Valolink\Plugin\Modules\Accesslink\StyleApplier;
use Valolink\Plugin\Modules\Accesslink\StyleCompiler;
use Valolink\Plugin\Modules\Accesslink\StyleReader;

/**
 * Design tokens and global styles from a library's design.json, written
 * through Accesslink's StyleApplier (the code behind set_style and
 * set_tokens), so the library and an agent's proposal make the same records.
 * Ported from valolink-kuumalahde's fold DesignStep. Tokens need GenerateBlocks
 * Pro 2.8+; a library without tokens works on older Pro too.
 */
final class DesignWriter
{
    private StyleReader $reader;

    public function __construct(private readonly Library $library)
    {
        $this->reader = new StyleReader();
    }

    /** @return list<string> plan lines; ones starting with "CONFLICT" stop an apply */
    public function plan(): array
    {
        $lines = [];
        if ($this->library->tokens() !== []) {
            if (!StyleReader::tokens_available()) {
                $lines[] = sprintf('CONFLICT %d tokens, but this site has no GenerateBlocks Pro design tokens (needs Pro 2.8+)', count($this->library->tokens()));
            } else {
                $tokens = $this->current_tokens();
                $t = ['new' => 0, 'change' => 0, 'same' => 0];
                foreach ($this->library->tokens() as $row) {
                    $t[!isset($tokens[$row['name']]) ? 'new' : ($this->same_token($tokens[$row['name']], $row) ? 'same' : 'change')]++;
                }
                $lines[] = sprintf('tokens: %d new, %d to change, %d already so', $t['new'], $t['change'], $t['same']);
            }
        }
        $s = ['new' => 0, 'change' => 0, 'same' => 0];
        foreach ($this->library->styles() as $style) {
            $found = $this->reader->find($style['selector']);
            $s[$found === null ? 'new' : ($this->same_style($found, $style) ? 'same' : 'change')]++;
            foreach (StyleCompiler::validate($style['styles']) as $issue) {
                $lines[] = 'CONFLICT ' . $style['selector'] . ': ' . $issue;
            }
        }
        array_splice($lines, $this->library->tokens() !== [] ? 1 : 0, 0, [sprintf('global styles: %d new, %d to change, %d already so', $s['new'], $s['change'], $s['same'])]);

        return $lines;
    }

    public function apply(Undo $undo): string
    {
        $applier = new StyleApplier($this->reader);
        $changed_tokens = [];
        if ($this->library->tokens() !== []) {
            $tokens = $this->current_tokens();
            foreach ($this->library->tokens() as $row) {
                $current = $tokens[$row['name']] ?? null;
                if ($current !== null && $this->same_token($current, $row)) {
                    continue;
                }
                $undo->keep('token:' . $row['name'], $current ?? ['absent' => true]);
                $changed_tokens[] = $row;
            }
            if ($changed_tokens !== []) {
                $result = $applier->apply_tokens($changed_tokens);
                if (is_wp_error($result)) {
                    throw new \RuntimeException('tokens: ' . $result->get_error_message());
                }
            }
        }

        $styles = 0;
        foreach ($this->library->styles() as $style) {
            $found = $this->reader->find($style['selector']);
            if ($found !== null && $this->same_style($found, $style)) {
                continue;
            }
            $undo->keep('style:' . $style['selector'], $found === null ? ['absent' => true] : [
                'styles' => $found['styles'], 'css' => $found['css'], 'category' => $found['category'],
            ]);
            $result = $applier->apply_style($style['selector'], $style['styles'], $style['category']);
            if (is_wp_error($result)) {
                throw new \RuntimeException($style['selector'] . ': ' . $result->get_error_message());
            }
            $styles++;
        }

        return sprintf('%d tokens and %d global styles written', count($changed_tokens), $styles);
    }

    /** Delete what the library created, restore what it changed; a style or token edited since is kept unless $force. */
    public function undo(Undo $undo, bool $force): string
    {
        $kept = 0;
        $styles = 0;
        foreach ($this->keys($undo, 'style:') as $selector) {
            $key = 'style:' . $selector;
            $found = $this->reader->find($selector);
            $ours = $this->library_style($selector);
            if ($found !== null && !$force && ($ours === null || !$this->same_style($found, $ours))) {
                \WP_CLI::warning(sprintf('%s changed since the library wrote it — kept (--force restores it)', $selector));
                $kept++;
                continue;
            }
            $old = $undo->get($key);
            if (isset($old['absent'])) {
                if ($found !== null) {
                    wp_delete_post($found['id'], true);
                }
            } elseif ($found !== null) {
                update_post_meta($found['id'], 'gb_style_data', $old['styles']);
                update_post_meta($found['id'], 'gb_style_css', $old['css']);
                update_post_meta($found['id'], 'gb_style_category', $old['category']);
            }
            $undo->forget($key);
            $styles++;
        }

        $tokens = 0;
        $names = $this->keys($undo, 'token:');
        if ($names !== [] && StyleReader::tokens_available()) {
            // One write of the :root record through GenerateBlocks' own save.
            $state = \GenerateBlocks_Pro_Styles_Root::get_state();
            $values = is_array($state['styles'] ?? null) ? $state['styles'] : [];
            $registry = array_values((array) ($state['tokens'] ?? []));
            foreach ($names as $name) {
                $key = 'token:' . $name;
                $ours = $this->library_token($name);
                if (!$force && ($ours === null || (string) ($values[$name] ?? '') !== $ours['value'])) {
                    \WP_CLI::warning(sprintf('%s changed since the library wrote it — kept (--force restores it)', $name));
                    $kept++;
                    continue;
                }
                $old = $undo->get($key);
                $registry = array_values(array_filter($registry, static fn ($t): bool => ($t['name'] ?? '') !== $name));
                if (isset($old['absent'])) {
                    unset($values[$name]);
                } else {
                    $values[$name] = $old['value'];
                    $registry[] = [
                        'name' => $old['name'], 'type' => $old['type'] === 'unregistered' ? 'text' : $old['type'],
                        'label' => $old['label'], 'category' => $old['category'], 'scope' => $old['scope'],
                    ];
                }
                $undo->forget($key);
                $tokens++;
            }
            if ($tokens > 0) {
                $result = \GenerateBlocks_Pro_Styles_Root::save($values, StyleCompiler::compile(':root', $values), (string) $state['checksum'], $registry);
                if (is_wp_error($result)) {
                    throw new \RuntimeException('tokens: ' . $result->get_error_message());
                }
            }
        }
        StyleApplier::rebuild();

        return sprintf('%d tokens and %d global styles restored, %d kept', $tokens, $styles, $kept);
    }

    /** The site's tokens and the global styles whose selector starts with $prefix, in design.json's shape. */
    public static function capture(string $prefix): array
    {
        $reader = new StyleReader();
        $tokens = StyleReader::tokens_available() ? array_map(static fn (array $t): array => [
            'name' => $t['name'], 'value' => $t['value'], 'type' => $t['type'] === 'unregistered' ? 'text' : $t['type'],
            'label' => $t['label'], 'category' => $t['category'], 'scope' => $t['scope'],
        ], $reader->tokens()) : [];
        $styles = [];
        foreach ($reader->styles() as $style) {
            if (str_starts_with($style['selector'], $prefix)) {
                $styles[] = ['selector' => $style['selector'], 'category' => $style['category'], 'styles' => $style['styles']];
            }
        }

        return ['tokens' => $tokens, 'styles' => $styles];
    }

    // -------------------------------------------------------------------------

    /** @return list<string> the names after $prefix, newest first */
    private function keys(Undo $undo, string $prefix): array
    {
        $out = [];
        foreach ([...array_column($this->library->styles(), 'selector'), ...array_column($this->library->tokens(), 'name')] as $name) {
            if ($undo->has($prefix . $name)) {
                $out[] = $name;
            }
        }

        return array_reverse(array_values(array_unique($out)));
    }

    private function library_style(string $selector): ?array
    {
        foreach ($this->library->styles() as $style) {
            if ($style['selector'] === $selector) {
                return $style;
            }
        }

        return null;
    }

    private function library_token(string $name): ?array
    {
        foreach ($this->library->tokens() as $row) {
            if (($row['name'] ?? '') === $name) {
                return $row;
            }
        }

        return null;
    }

    /** @return array<string, array> name => token as StyleReader reads it */
    private function current_tokens(): array
    {
        $out = [];
        foreach ($this->reader->tokens() as $token) {
            $out[$token['name']] = $token;
        }

        return $out;
    }

    private function same_token(array $current, array $row): bool
    {
        return $current['value'] === $row['value']
            && $current['type'] === $row['type']
            && $current['label'] === ($row['label'] ?? '')
            && $current['category'] === ($row['category'] ?? '')
            && $current['scope'] === array_values((array) ($row['scope'] ?? []));
    }

    private function same_style(array $found, array $style): bool
    {
        return wp_json_encode($found['styles']) === wp_json_encode($style['styles'])
            && $found['category'] === $style['category'];
    }
}
