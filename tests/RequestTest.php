<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\Request;
use CoffeeCode\Router\RequestException;
use CoffeeCode\Router\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    #[DataProvider('bodies')]
    public function testParsing(string $method, string $body, string $type, array $expected): void
    {
        $request = new Request($method, '/', $body, $type);
        self::assertSame($expected, $request->payload());
        self::assertSame($expected, $request->payload());
    }

    public static function bodies(): array
    {
        return [
            ['POST', '{"hello":"world"}', 'application/json', ['hello' => 'world']],
            ['PATCH', '{"a":{"b":1}}', 'application/merge-patch+json; charset=utf-8', ['a' => ['b' => 1]]],
            ['PUT', 'a=1&b=two', 'application/x-www-form-urlencoded', ['a' => '1', 'b' => 'two']],
            ['DELETE', '', '', []],
            ['GET', 'ignored', 'text/plain', []],
            ['POST', '{}', 'application/json', []],
            ['POST', '{"_method":"DELETE"}', 'application/json', ['_method' => 'DELETE']],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function testBadBodiesBecomeRequestErrors(string $body, string $type, int $status): void
    {
        $r = new Router('https://example.com', request: new Request('POST', '/', $body, $type));
        $r->post('/', static function (): void {
        });
        self::assertFalse($r->dispatch());
        self::assertSame($status, $r->error());
    }

    public static function invalidBodies(): array
    {
        return [
            ['{bad', 'application/json', 400],
            ['[]', 'application/json', 400],
            ['null', 'application/json', 400],
            ['"scalar"', 'application/json', 400],
            ['hello', 'text/plain', 415],
            ['hello', '', 415],
            [str_repeat('a', Request::MAX_BODY_BYTES + 1), 'application/json', 413],
        ];
    }

    #[DataProvider('overrides')]
    public function testMethodOverrideIsRestricted(string $method, string $body, string $type, string $expected): void
    {
        $r = new Request($method, '/', $body, $type);
        self::assertSame($expected, $r->method());
    }

    public static function overrides(): array
    {
        return [
            ['POST', '_method=PUT', 'application/x-www-form-urlencoded', 'PUT'],
            ['POST', '_method=PATCH', 'application/x-www-form-urlencoded', 'PATCH'],
            ['POST', '_method=DELETE', 'application/x-www-form-urlencoded', 'DELETE'],
            ['POST', '_method=GET', 'application/x-www-form-urlencoded', 'POST'],
            ['POST', '_method=delete', 'application/x-www-form-urlencoded', 'POST'],
            ['POST', '{"_method":"DELETE"}', 'application/json', 'POST'],
            ['GET', '_method=DELETE', 'application/x-www-form-urlencoded', 'GET'],
            ['PUT', '_method=DELETE', 'application/x-www-form-urlencoded', 'PUT'],
        ];
    }

    public function testMultipartPostUsesSapiForm(): void
    {
        $r = new Request('POST', '/', '', 'multipart/form-data; boundary=test', ['_method' => 'PATCH', 'name' => 'ok']);
        self::assertSame('PATCH', $r->method());
        self::assertSame(['name' => 'ok'], $r->payload());
    }

    public function testMultipartPutIsExplicitlyUnsupported(): void
    {
        $r = new Request('PUT', '/', '', 'multipart/form-data');
        $this->expectException(RequestException::class);
        $r->data();
    }

    #[DataProvider('invalidPaths')]
    public function testInvalidPathsBecome400(string $path): void
    {
        $r = new Router('https://example.com', request: new Request('GET', $path));
        self::assertFalse($r->dispatch());
        self::assertSame(400, $r->error());
    }

    public static function invalidPaths(): array
    {
        return [['relative'], ["/x\0"], ['/x?y'], ['/x#y']];
    }

    public function testGlobalsAreCapturedBeforeRegistrationAndDefaultCliMethodIsGet(): void
    {
        $oldGet = $_GET;
        $oldPost = $_POST;
        $oldServer = $_SERVER;
        try {
            $_GET = ['route' => '/original'];
            $_POST = [];
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE']);
            $request = Request::fromGlobals();
            $_GET['route'] = '/changed';
            self::assertSame('/original', $request->path());
            self::assertSame('GET', $request->method());
        } finally {
            $_GET = $oldGet;
            $_POST = $oldPost;
            $_SERVER = $oldServer;
        }
    }
}
