<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\Router;

final class TestController
{
    public static ?array $received = null;
    public function __construct(Router $router)
    {
    }
    public function show(array $data): void
    {
        self::$received = $data;
    }
    private function hidden(array $data): void
    {
    }
}
