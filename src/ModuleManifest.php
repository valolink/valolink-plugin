<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class ModuleManifest
{
    /**
     * label/description are closures so __() runs at render time (admin
     * screens, post-init) instead of during registration at plugins_loaded —
     * translating that early trips WP 6.7's _load_textdomain_just_in_time.
     */
    public function __construct(
        public readonly string $id,
        private readonly \Closure $label,
        private readonly \Closure $description,
        public readonly string $class,
        public readonly bool $default_enabled = false,
        public readonly array $constructor_args = [],
    ) {}

    public function label(): string
    {
        return ($this->label)();
    }

    public function description(): string
    {
        return ($this->description)();
    }
}
