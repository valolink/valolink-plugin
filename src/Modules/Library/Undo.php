<?php

declare(strict_types=1);

namespace Valolink\Plugin\Modules\Library;

/**
 * What library applies replaced, so `wp valolink library undo` can put it
 * back. One record per site (non-autoloaded option): a key keeps the state
 * from before the first apply that touched it, so undo returns to where the
 * site was before the library, however many applies came in between.
 */
final class Undo
{
    public const OPTION = 'valolink_library_undo';

    private array $items;

    public function __construct()
    {
        $stored = get_option(self::OPTION, []);
        $this->items = is_array($stored) ? (array) ($stored['items'] ?? []) : [];
    }

    public function keep(string $key, array $previous): void
    {
        if (!array_key_exists($key, $this->items)) {
            $this->items[$key] = $previous;
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function get(string $key): array
    {
        return (array) ($this->items[$key] ?? []);
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function save(): void
    {
        if ($this->items === []) {
            delete_option(self::OPTION);

            return;
        }
        update_option(self::OPTION, ['at' => gmdate('c'), 'items' => $this->items], false);
    }
}
