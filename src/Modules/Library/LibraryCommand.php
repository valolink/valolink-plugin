<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Library;

use Valolink\Plugin\Modules\Accesslink\StyleApplier;
use Valolink\Plugin\Modules\Accesslink\StyleReader;

/**
 * A site's design library — design tokens, global classes, section patterns
 * and showcase pages — applied from a directory in the site's repo (see
 * Library for the files). The library is reviewable data; the site's records
 * are made from it, and wp-admin edits come back with `capture`.
 */
final class LibraryCommand
{
    /**
     * Shows what apply would change. Writes nothing.
     *
     * ## OPTIONS
     *
     * <dir>
     * : The library directory.
     *
     * ## EXAMPLES
     *
     *     wp valolink library plan ~/valolink-fi/library
     */
    public function plan(array $args, array $assoc): void
    {
        $library = $this->library($args[0]);
        $lines = $this->guard(static fn (): array => [...(new DesignWriter($library))->plan(), ...(new PatternWriter($library))->plan()]);
        foreach ($lines as $line) {
            \WP_CLI::log($line);
        }
        \WP_CLI::log(sprintf('showcase: %d pages (built with `showcase`, not by apply)', $library->page_count()));
    }

    /**
     * Writes the tokens and global styles, then the patterns. What they
     * replaced is kept for `undo`.
     *
     * ## OPTIONS
     *
     * <dir>
     * : The library directory.
     *
     * [--only=<part>]
     * : design or patterns.
     *
     * ## EXAMPLES
     *
     *     wp valolink library apply ~/valolink-fi/library
     */
    public function apply(array $args, array $assoc): void
    {
        $library = $this->library($args[0]);
        $only = $assoc['only'] ?? null;
        $design = new DesignWriter($library);
        $conflicts = array_filter($design->plan(), static fn (string $l): bool => str_starts_with($l, 'CONFLICT'));
        if ($conflicts !== [] && $only !== 'patterns') {
            \WP_CLI::error(implode("\n", $conflicts));
        }
        $undo = new Undo();
        try {
            if ($only !== 'patterns') {
                \WP_CLI::log($design->apply($undo));
            }
            if ($only !== 'design') {
                \WP_CLI::log((new PatternWriter($library))->apply($undo));
            }
        } catch (\RuntimeException $e) {
            $failed = $e->getMessage();
        } finally {
            $undo->save();
            $this->purge();
        }
        if (isset($failed)) {
            \WP_CLI::error($failed . sprintf(' (stopped there; %d records kept for undo)', $undo->count()));
        }
        \WP_CLI::success(sprintf('Applied. %d records kept for undo.', $undo->count()));
    }

    /**
     * Puts back what library applies replaced: deletes the tokens, styles and
     * patterns they created, restores the ones they changed. Ones edited since
     * are kept unless --force.
     *
     * ## OPTIONS
     *
     * <dir>
     * : The library directory (the same one that was applied).
     *
     * [--force]
     * : Restore even what was edited since, and delete patterns pages use.
     */
    public function undo(array $args, array $assoc): void
    {
        $library = $this->library($args[0]);
        $undo = new Undo();
        if ($undo->count() === 0) {
            \WP_CLI::success('Nothing to undo.');

            return;
        }
        $force = !empty($assoc['force']);
        try {
            \WP_CLI::log((new PatternWriter($library))->undo($undo, $force));
            \WP_CLI::log((new DesignWriter($library))->undo($undo, $force));
        } catch (\RuntimeException $e) {
            $failed = $e->getMessage();
        } finally {
            $undo->save();
            $this->purge();
        }
        if (isset($failed)) {
            \WP_CLI::error($failed . sprintf(' (stopped there; %d records kept for undo)', $undo->count()));
        }
        \WP_CLI::success(sprintf('%d records left to undo.', $undo->count()));
    }

    /**
     * Writes the site's tokens and the global styles with a prefix back into
     * design.json, so changes made in wp-admin reach the repo.
     *
     * ## OPTIONS
     *
     * <dir>
     * : The library directory.
     *
     * --prefix=<prefix>
     * : Selector prefix of the library's classes, e.g. .vl-
     */
    public function capture(array $args, array $assoc): void
    {
        $dir = rtrim($args[0], '/');
        $file = $dir . '/design.json';
        $current = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        $captured = DesignWriter::capture((string) $assoc['prefix']);
        $out = array_merge($current, $captured);
        file_put_contents($file, wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        \WP_CLI::success(sprintf('%s: %d tokens, %d styles', $file, count($captured['tokens']), count($captured['styles'])));
    }

    /**
     * Builds the showcase pages (style guide and examples), or removes them.
     *
     * ## OPTIONS
     *
     * <dir>
     * : The library directory.
     *
     * [--status=<status>]
     * : private (logged-in only) or publish.
     * ---
     * default: private
     * ---
     *
     * [--remove]
     * : Delete the showcase pages instead.
     */
    public function showcase(array $args, array $assoc): void
    {
        if (!empty($assoc['remove'])) {
            foreach (Showcase::remove() as $slug) {
                \WP_CLI::log('deleted ' . $slug);
            }
            \WP_CLI::success('Showcase removed.');

            return;
        }
        $status = in_array($assoc['status'] ?? 'private', ['private', 'publish'], true) ? $assoc['status'] ?? 'private' : 'private';
        $library = $this->library($args[0]);
        $urls = $this->guard(static fn (): array => (new Showcase($library))->build($status));
        $this->purge();
        \WP_CLI::success("Showcase:\n" . implode("\n", $urls));
    }

    /**
     * Puts a showcase page's content into the page it replaces (its "replaces" in
     * showcase.json), so a converted page goes live at its own URL with its own
     * title and meta. Refused when the target changed since the conversion was
     * made from it. The old content is kept for --back (and as a revision).
     * Run showcase first, on the same site: the copy is that page's content.
     *
     * ## OPTIONS
     *
     * <dir>
     * : The library directory.
     *
     * <slug>
     * : The showcase page.
     *
     * [--back]
     * : Put the replaced content back.
     *
     * [--force]
     * : Swap even though the target changed since; with --back, restore even though the page was edited after the swap.
     *
     * ## EXAMPLES
     *
     *     wp valolink library swap ~/valolink-fi/library vl-muunnos-etusivu
     */
    public function swap(array $args, array $assoc): void
    {
        $library = $this->library($args[0]);
        $slug = sanitize_title($args[1]);
        $target = $library->replaces($slug) ?? \WP_CLI::error($slug . ' replaces no page (no "replaces" in showcase.json).');
        $post = get_post($target['id']);
        if (!$post instanceof \WP_Post || $post->post_type === 'revision') {
            \WP_CLI::error(sprintf('No post %d here.', $target['id']));
        }
        $undo = new Undo();
        $key = 'swap:' . $post->ID;
        $force = !empty($assoc['force']);

        if (!empty($assoc['back'])) {
            if (!$undo->has($key)) {
                \WP_CLI::error(sprintf('Nothing kept for %d.', $post->ID));
            }
            $kept = $undo->get($key);
            if (sha1($post->post_content) !== $kept['swapped'] && !$force) {
                \WP_CLI::error(sprintf('%d was edited after the swap; --force puts the old content back anyway.', $post->ID));
            }
            $this->guard(static fn (): int => PatternWriter::write($post->ID, ['post_content' => $kept['content']]));
            $undo->forget($key);
            $undo->save();
            $this->purge();
            \WP_CLI::success(sprintf('%s: old content back.', get_permalink($post->ID)));

            return;
        }

        $preview = get_page_by_path($slug, OBJECT, 'page');
        if (!$preview instanceof \WP_Post || !get_post_meta($preview->ID, Showcase::META, true)) {
            \WP_CLI::error($slug . ' is not built here: run showcase first.');
        }
        $now = sha1($post->post_content);
        if ($now === sha1($preview->post_content)) {
            \WP_CLI::success('Already swapped.');

            return;
        }
        if (!in_array($now, $target['sha1'], true) && !$force) {
            \WP_CLI::error(sprintf('%d changed since it was converted (sha1 %s is not in "replaces"). Convert it again from its current content, or --force.', $post->ID, $now));
        }
        $undo->keep($key, ['content' => $post->post_content, 'swapped' => sha1($preview->post_content)]);
        $this->guard(static fn (): int => PatternWriter::write($post->ID, ['post_content' => $preview->post_content]));
        $undo->save();
        $this->purge();
        \WP_CLI::success(sprintf('%s now has the content of %s. Back with --back.', get_permalink($post->ID), $slug));
    }

    // -------------------------------------------------------------------------

    private function library(string $dir): Library
    {
        if (!StyleReader::available() || !class_exists(StyleApplier::class)) {
            \WP_CLI::error('Needs GenerateBlocks Pro (global styles).');
        }
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            \WP_CLI::error($dir . ' is not a directory.');
        }
        try {
            return new Library($dir);
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    /** Runs a step that keeps no undo record; a library or write error ends the command with its message. */
    private function guard(callable $step): mixed
    {
        try {
            return $step();
        } catch (\RuntimeException $e) {
            \WP_CLI::error($e->getMessage());
        }
    }

    private function purge(): void
    {
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_minify();
            rocket_clean_domain();
        }
    }
}
