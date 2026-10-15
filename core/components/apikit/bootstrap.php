<?php

declare(strict_types=1);

$frameworkPath = __DIR__ . '/src/';
$appPath = MODX_CORE_PATH . 'app/';

spl_autoload_register(static function (string $class) use ($frameworkPath, $appPath): void {
    $prefixes = [
        'ApiKit\\' => $frameworkPath,
        'App\\' => $appPath,
    ];

    foreach ($prefixes as $prefix => $path) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = $path . str_replace('\\', '/', $relativeClass) . '.php';

        if (is_file($file)) {
            require_once $file;
        }

        return;
    }
});

return new ApiKit\Application();
