<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Autoloader.php';
\Valolink\Plugin\Autoloader::register();

\Valolink\Plugin\Uninstaller::run();
