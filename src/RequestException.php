<?php

declare(strict_types=1);

namespace CoffeeCode\Router;

final class RequestException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
