<?php

use App\Kernel;

// When served by the PHP built-in server (`php -S ... public/index.php`), let
// existing static files under public/ (app.css, player.js, …) be served
// directly with their correct MIME type instead of being routed through
// Symfony. Guarded to the cli-server SAPI, so real web servers are unaffected;
// the realpath containment check keeps it strictly inside public/.
if (PHP_SAPI === 'cli-server') {
    $requested = realpath(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($requested !== false
        && is_file($requested)
        && $requested !== __FILE__
        && str_starts_with($requested, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
