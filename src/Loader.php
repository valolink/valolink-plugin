<?php

declare(strict_types=1);

namespace Valolink\Plugin;

final class Loader
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Registry $registry,
        private readonly Context $context,
    ) {}

    public function load(): void
    {
        foreach ($this->registry->all() as $manifest) {
            if (!$this->settings->is_module_enabled($manifest->id)) {
                continue;
            }

            if (!class_exists($manifest->class)) {
                continue;
            }

            /** @var Module $module */
            $module = new ($manifest->class)();

            if (!$module->should_load($this->context)) {
                continue;
            }

            $module->register();
        }
    }
}
