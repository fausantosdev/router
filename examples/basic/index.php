<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use CoffeeCode\Router\Router;

// Local development example; configure your public URL in production.
$router = new Router('http://127.0.0.1:8080');
$router->get('/', static function (): void {
    header('Content-Type: application/json');
    echo json_encode(['example' => '/?route=/hello/Ana'], JSON_THROW_ON_ERROR);
});
$router->get('/hello/{name}', static function (array $data): void {
    header('Content-Type: application/json');
    echo json_encode(['hello' => $data['name']], JSON_THROW_ON_ERROR);
}, 'hello');
$router->post('/hello/{name}', static function (array $data): void {
    header('Content-Type: application/json');
    echo json_encode(['received' => $data], JSON_THROW_ON_ERROR);
});

if (!$router->dispatch() && $router->error() !== null) {
    http_response_code($router->error());
    if ($router->error() === 405) {
        header('Allow: ' . implode(', ', $router->allowedMethods()));
    }
    header('Content-Type: application/json');
    echo json_encode(['error' => $router->error()], JSON_THROW_ON_ERROR);
}
