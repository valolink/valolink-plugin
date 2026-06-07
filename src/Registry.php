<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class Registry
{
    /** @var array<string, ModuleManifest> */
    private array $manifests = [];

    public function register(ModuleManifest $manifest): void
    {
        $this->manifests[$manifest->id] = $manifest;
    }

    /** @return array<string, ModuleManifest> */
    public function all(): array
    {
        return $this->manifests;
    }

    public function get(string $id): ?ModuleManifest
    {
        return $this->manifests[$id] ?? null;
    }
}
