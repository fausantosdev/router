<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\Router;

final class DataMiddleware
{
    public function handle(Router $router): bool
    {
        Trace::$events[] = $router->data()['id'];
        return true;
    }
}
