<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class ModuleManifest
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly string $class,
        public readonly bool $default_enabled = false,
    ) {}
}
