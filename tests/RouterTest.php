<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\ConfigurationException;
use CoffeeCode\Router\Request;
use CoffeeCode\Router\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(string $path = '/', string $method = 'GET', string $body = '', string $type = ''): Router
    {
        return new Router('https://example.com/base', request: new Request($method, $path, $body, $type));
    }

    public function testEmptyRouterIsSafeAndNotFound(): void
    {
        $r = $this->router();
        self::assertNull($r->route('missing'));
        self::assertNull($r->current());
        self::assertIsArray($r->__debugInfo());
        self::assertFalse($r->dispatch());
        self::assertSame(404, $r->error());
    }

    public function testSelectedDataIsConsistentAndPathWinsOverBody(): void
    {
        $r = $this->router('/users/42', 'POST', 'id=forged&title=ok', 'application/x-www-form-urlencoded');
        $received = null;
        $r->post('/users/{id}', function ($data, Router $router) use (&$received): void {
            $received = $data;
            self::assertSame($data, $router->data());
            self::assertSame($data, $router->current()->data);
        });
        $r->post('/other/{unrelated}', static function (): void {
        });
        self::assertNull($r->data());
        self::assertTrue($r->dispatch());
        self::assertSame(['id' => '42', 'title' => 'ok'], $received);
        self::assertSame($received, $r->data());
    }

    public function testGroupDoesNotStripTextInsideParameterAndMetadataIsCaptured(): void
    {
        $r = $this->router('/admin/admin123/');
        $r->group('admin')->namespace(__NAMESPACE__);
        $r->get('/{id}', 'TestController:show');
        $r->group('later')->namespace(null);
        TestController::$received = null;
        self::assertTrue($r->dispatch());
        self::assertSame(['id' => 'admin123'], TestController::$received);
        self::assertSame('admin', $r->current()->group);
        self::assertSame(__NAMESPACE__, $r->current()->namespace);
    }

    public function testLiteralRegexCharactersAreEscaped(): void
    {
        foreach (['/file.json', '/a+b', '/a(b)', '/a~b'] as $path) {
            $r = $this->router($path);
            $r->get($path, static function (): void {
            });
            self::assertTrue($r->dispatch());
        }
        $r = $this->router('/fileXjson');
        $r->get('/file.json', static function (): void {
        });
        self::assertFalse($r->dispatch());
        self::assertSame(404, $r->error());
    }

    public function testStaticRoutesWinRegardlessOfRegistrationOrder(): void
    {
        foreach ([true, false] as $staticFirst) {
            $r = $this->router('/users/new');
            $selected = '';
            $static = function () use (&$selected): void {
                $selected = 'static';
            };
            $dynamic = function () use (&$selected): void {
                $selected = 'dynamic';
            };
            if ($staticFirst) {
                $r->get('/users/new', $static);
                $r->get('/users/{id}', $dynamic);
            } else {
                $r->get('/users/{id}', $dynamic);
                $r->get('/users/new', $static);
            }
            self::assertTrue($r->dispatch());
            self::assertSame('static', $selected);
        }
    }

    public function testDynamicOverlapsUseFirstRegistration(): void
    {
        $r = $this->router('/x/y');
        $selected = '';
        $r->get('/{a}/{b}', function () use (&$selected): void {
            $selected = 'first';
        });
        $r->get('/x/{id}', function () use (&$selected): void {
            $selected = 'second';
        });
        self::assertTrue($r->dispatch());
        self::assertSame('first', $selected);
    }

    public function testMethodNotAllowedAndAllowedMethods(): void
    {
        $r = $this->router('/resource', 'DELETE');
        $r->get('/resource', static function (): void {
        });
        $r->post('/resource', static function (): void {
        });
        self::assertFalse($r->dispatch());
        self::assertSame(405, $r->error());
        self::assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], $r->allowedMethods());
    }

    public function testMiddlewareCannotReplaceGroupGuard(): void
    {
        Trace::$events = [];
        $r = $this->router('/admin/secret');
        $r->group('admin', DenyMiddleware::class);
        $r->get('/secret', static function (): void {
            Trace::$events[] = 'handler';
        }, middleware: PassMiddleware::class);
        self::assertFalse($r->dispatch());
        self::assertSame(['deny'], Trace::$events);
        self::assertNull($r->error());
    }

    public function testMiddlewareOrderAndSelectedData(): void
    {
        Trace::$events = [];
        $r = $this->router('/g/42');
        $r->group('g', PassMiddleware::class);
        $r->get('/{id}', static function (): void {
            Trace::$events[] = 'handler';
        }, middleware: DataMiddleware::class);
        $r->get('/unrelated/{x}', static function (): void {
        });
        self::assertTrue($r->dispatch());
        self::assertSame(['pass', '42', 'handler'], Trace::$events);
    }

    public function testNamedUrlEncodesParametersAndQuery(): void
    {
        $r = $this->router();
        $r->get('/users/{id}', static function (): void {
        }, 'user');
        self::assertSame(
            'https://example.com/base/users/a%2Fb%3Fx%3D1?q=hello%20world',
            $r->route('user', ['id' => 'a/b?x=1', 'q' => 'hello world']),
        );
    }

    #[DataProvider('badUrlParameters')]
    public function testInvalidNamedParametersAreRejected(array $data): void
    {
        $r = $this->router();
        $r->get('/{id}', static function (): void {
        }, 'user');
        $this->expectException(ConfigurationException::class);
        $r->route('user', $data);
    }

    public static function badUrlParameters(): array
    {
        return [[[]], [['id' => null]], [['id' => []]], [['id' => '']], [['id' => '.']], [['id' => '..']]];
    }

    public function testMultipleAndEncodedParametersArePreserved(): void
    {
        $r = $this->router('/x/x/a%2Fb');
        $r->get('/{a}/x/{b}', static function (): void {
        });
        self::assertTrue($r->dispatch());
        self::assertSame(['a' => 'x', 'b' => 'a%2Fb'], $r->data());
    }

    #[DataProvider('invalidRegistrations')]
    public function testInvalidRouteDefinitionsAreRejected(string $path): void
    {
        $r = $this->router();
        $this->expectException(ConfigurationException::class);
        $r->get($path, static function (): void {
        });
    }

    public static function invalidRegistrations(): array
    {
        return [['missing-slash'], ['/x/{bad'], ['/{id}/{id}'], ['/a?x=1'], ['/a#x'], ['/a b']];
    }

    public function testDuplicateNamesAndPathsAreRejected(): void
    {
        $r = $this->router();
        $r->get('/a', static function (): void {
        }, 'same');
        try {
            $r->post('/b', static function (): void {
            }, 'same');
            self::fail('Duplicate name accepted');
        } catch (ConfigurationException) {
            self::assertTrue(true);
        }
        $this->expectException(ConfigurationException::class);
        $r->get('/a/', static function (): void {
        });
    }

    #[DataProvider('badHandlers')]
    public function testInvalidControllerHandlersThrowConfigurationException(string $handler): void
    {
        $r = $this->router();
        $r->get('/', $handler);
        $this->expectException(ConfigurationException::class);
        $r->dispatch();
    }

    public static function badHandlers(): array
    {
        return [
            ['MissingController:show'], [TestController::class . ':hidden'],
            [TestController::class . ':missing'], [TestController::class . ':__construct'], ['broken'],
        ];
    }

    public function testCallableArrayAndFunctionStringWork(): void
    {
        $r = $this->router();
        $receiver = new CallableReceiver();
        $r->get('/', [$receiver, 'receive']);
        self::assertTrue($r->dispatch());
        self::assertTrue($receiver->called);
        $r = $this->router();
        $r->get('/', __NAMESPACE__ . '\\testFunction');
        self::assertTrue($r->dispatch());
    }

    public function testControllerExceptionsAreNotMasked(): void
    {
        $r = $this->router();
        $r->get('/', static function (): void {
            throw new \RuntimeException('application failure');
        });
        $this->expectExceptionMessage('application failure');
        $r->dispatch();
    }

    public function testHeadSuppressesHandlerAndMiddlewareOutput(): void
    {
        $r = $this->router('/', 'HEAD');
        $r->get('/', static function (): void {
            echo 'body';
        }, middleware: EchoMiddleware::class);
        ob_start();
        self::assertTrue($r->dispatch());
        self::assertSame('', ob_get_clean());
    }

    public function testHeadCleanupOnException(): void
    {
        $r = $this->router('/', 'HEAD');
        $r->get('/', static function (): void {
            echo 'body';
            throw new \RuntimeException('failure');
        });
        $level = ob_get_level();
        try {
            $r->dispatch();
            self::fail('Missing exception');
        } catch (\RuntimeException) {
            self::assertSame($level, ob_get_level());
        }
    }

    public function testRepeatedDispatchResetsErrorAndData(): void
    {
        $r = $this->router('/ok');
        self::assertFalse($r->dispatch());
        $r->get('/ok', static function (): void {
        });
        self::assertTrue($r->dispatch());
        self::assertNull($r->error());
        self::assertSame([], $r->data());
    }

    #[DataProvider('invalidRedirects')]
    public function testUnsafeRedirectsAreRejected(string $target): void
    {
        $r = $this->router();
        $this->expectException(ConfigurationException::class);
        $r->redirect($target);
    }

    public static function invalidRedirects(): array
    {
        return [["/x\r\nInjected: yes"], ['//evil.example'], ['javascript:alert(1)'], ['ftp://example.com']];
    }
}
