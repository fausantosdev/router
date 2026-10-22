<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use CoffeeCode\Router\Router;

// Real HTTP fixture. No injected Request: exercise globals and php://input.
$router = new Router('http://' . $_SERVER['HTTP_HOST']);
$echo = static function (array $data, Router $router): void {
    header('Content-Type: application/json');
    echo json_encode(
        ['data' => $data, 'routerData' => $router->data(), 'currentData' => $router->current()->data],
        JSON_THROW_ON_ERROR,
    );
};
$router->get('/users/{id}', $echo);
$router->get('/optional/{id?}/{tab?}', $echo);
$router->post('/users/{id}', $echo);
$router->put('/users/{id}', $echo);
$router->patch('/users/{id}', $echo);
$router->delete('/users/{id}', $echo);
$router->get('/other/{unrelated}', $echo);
$router->post('/other/{unrelated}', $echo);
$router->get('/redirect', static function (array $data, Router $r): void {
    $r->redirect('/users/42', status: 303);
});
$router->get('/named/{id}', $echo, 'named');
$router->get('/named-redirect', static function (array $data, Router $r): void {
    $r->redirect('named', ['id' => 'a/b'], 307);
});
$router->head('/explicit', static function (): void {
    header('X-Handler: head');
    echo 'discard';
});
$router->options('/explicit', static function (): void {
    header('X-Handler: options');
    http_response_code(204);
});

if (!$router->dispatch() && $router->error() !== null) {
    http_response_code($router->error());
    if ($router->error() === 405) {
        header('Allow: ' . implode(', ', $router->allowedMethods()));
    }
    header('Content-Type: application/json');
    echo json_encode(['error' => $router->error()], JSON_THROW_ON_ERROR);
}
