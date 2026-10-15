<?php

declare(strict_types=1);

use ApiKit\Application;

return static function (Application $app): void {
    $config = require __DIR__ . '/config.php';

    $app->debug((bool) ($config['debug'] ?? false));
    $app->container()->instance('config', $config);

    $registerRoutes = require __DIR__ . '/routes/api.php';
    $registerRoutes($app->router());
};
