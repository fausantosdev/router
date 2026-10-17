<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\Router;

final class DenyMiddleware
{
    public function handle(Router $router): bool
    {
        Trace::$events[] = 'deny';
        return false;
    }
}
