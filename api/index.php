<?php

declare(strict_types=1);

require dirname(__DIR__) . '/config.core.php';

$app = require MODX_CORE_PATH . 'components/apikit/bootstrap.php';
$configure = require MODX_CORE_PATH . 'app/bootstrap.php';
$configure($app);

$request = ApiKit\Http\Request::capture('/api');
$response = $app->handle($request);
$response->send();
