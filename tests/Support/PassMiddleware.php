<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\Router;

final class PassMiddleware
{
    public function handle(Router $router): bool
    {
        Trace::$events[] = 'pass';
        return true;
    }
}
