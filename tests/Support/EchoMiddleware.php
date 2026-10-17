<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\Router;

final class EchoMiddleware
{
    public function handle(Router $router): bool
    {
        echo 'middleware';
        return true;
    }
}
