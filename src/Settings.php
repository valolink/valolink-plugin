<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class Settings
{
    public const OPTION_KEY = 'valolink_settings';

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION_KEY, []);
            $this->cache = is_array($stored) ? $stored : [];
        }
        return $this->cache;
    }

    public function is_module_enabled(string $module_id): bool
    {
        $all = $this->all();
        return !empty($all['modules'][$module_id]['enabled']);
    }

    public function set_module_enabled(string $module_id, bool $enabled): void
    {
        $all = $this->all();
        $all['modules'][$module_id]['enabled'] = $enabled;
        $this->persist($all);
    }

    public function get_module_setting(string $module_id, string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return $all['modules'][$module_id]['settings'][$key] ?? $default;
    }

    public function set_module_setting(string $module_id, string $key, mixed $value): void
    {
        $all = $this->all();
        $all['modules'][$module_id]['settings'][$key] = $value;
        $this->persist($all);
    }

    public function set_module_settings(string $module_id, array $settings): void
    {
        $all = $this->all();
        $existing = $all['modules'][$module_id]['settings'] ?? [];
        $all['modules'][$module_id]['settings'] = array_merge($existing, $settings);
        $this->persist($all);
    }

    public function forget_module(string $module_id): void
    {
        $all = $this->all();
        unset($all['modules'][$module_id]);
        $this->persist($all);
    }

    /** Top-level (non-module) setting. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $all        = $this->all();
        $all[$key]  = $value;
        $this->persist($all);
    }

    private function persist(array $all): void
    {
        $this->cache = $all;
        update_option(self::OPTION_KEY, $all, false);
    }
}
