<?php

declare(strict_types=1);

// PHP's built-in server: let it serve real files (cpresources, plugin assets) directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
$app->run();
