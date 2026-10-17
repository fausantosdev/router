<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\ConfigurationException;
use CoffeeCode\Router\Request;
use CoffeeCode\Router\Router;
use PHPUnit\Framework\TestCase;

final class EdgeCaseTest extends TestCase
{
    public function testZeroGroupIsNotTreatedAsEmpty(): void
    {
        $router = new Router('https://example.com', request: new Request('GET', '/0/42'));
        $router->group('0');
        $router->get('/{id}', static function (): void {
        });
        self::assertTrue($router->dispatch());
        self::assertSame(['id' => '42'], $router->data());
    }

    public function testBackslashRedirectIsRejected(): void
    {
        $router = new Router('https://example.com', request: new Request('GET'));
        $this->expectException(ConfigurationException::class);
        $router->redirect('/\\evil.example');
    }

    public function testOversizedSapiBodyIsRejectedEvenWhenPhpInputIsEmpty(): void
    {
        $server = $_SERVER;
        $post = $_POST;
        $get = $_GET;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
            $_SERVER['CONTENT_LENGTH'] = Request::MAX_BODY_BYTES + 1;
            $_POST = [];
            $_GET = ['route' => '/'];
            $router = new Router('https://example.com');
            $router->post('/', static function (): void {
            });
            self::assertFalse($router->dispatch());
            self::assertSame(413, $router->error());
        } finally {
            $_SERVER = $server;
            $_POST = $post;
            $_GET = $get;
        }
    }

    public function testPrivateMiddlewareHandleIsRejected(): void
    {
        $middleware = new class {
            private function handle(Router $router): bool
            {
                return true;
            }
        };
        $router = new Router('https://example.com', request: new Request('GET'));
        $router->get('/', static function (): void {
        }, middleware: $middleware::class);
        $this->expectException(ConfigurationException::class);
        $router->dispatch();
    }

    public function testMiddlewareConstructorRequirementsAreConfigurationErrors(): void
    {
        $middleware = new class ('initial') {
            public function __construct(string $required)
            {
            }

            public function handle(Router $router): bool
            {
                return true;
            }
        };
        $router = new Router('https://example.com', request: new Request('GET'));
        $router->get('/', static function (): void {
        }, middleware: $middleware::class);
        $this->expectException(ConfigurationException::class);
        $router->dispatch();
    }
}
