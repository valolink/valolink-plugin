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

            // Isolate each module. A module whose constructor, should_load(), or
            // register() throws must never take down the site or stop sibling
            // modules from loading — the plugin's Graceful Failure principle.
            // (This guards load-time only; a throw inside a hook callback fires
            // later and is outside any try/catch here — modules must still guard
            // their own runtime work.)
            try {
                /** @var Module $module */
                $module = new ($manifest->class)(...$manifest->constructor_args);

                if (!$module->should_load($this->context)) {
                    continue;
                }

                $module->register();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[valolink-plugin] module "%s" failed to load and was skipped: %s in %s:%d',
                    $manifest->id,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
        }
    }
}
