<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\Router;

final class CallableReceiver
{
    public bool $called = false;
    public function receive(array $data, Router $router): void
    {
        $this->called = true;
    }
}
