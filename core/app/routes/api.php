<?php

declare(strict_types=1);

use ApiKit\Mcp\McpServer;
use ApiKit\Routing\Router;
use App\Http\Controllers\HealthController;

return static function (Router $router): void {
    $router->get('/health', [HealthController::class, 'index']);

    // MCP-серверы: /api/mcp/metrika, /api/mcp/webmaster и др. из config['mcp']
    $router->post('/mcp/{name}', [McpServer::class, 'handle']);
};
