<?php

declare(strict_types=1);

namespace Valolink\Plugin;

interface Module
{
    public function should_load(Context $context): bool;

    public function register(): void;

    public function uninstall(): void;
}
