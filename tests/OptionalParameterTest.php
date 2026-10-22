<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use CoffeeCode\Router\ConfigurationException;
use CoffeeCode\Router\Request;
use CoffeeCode\Router\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionalParameterTest extends TestCase
{
    #[DataProvider('paths')]
    public function testMatching(string $pattern, string $path, ?array $expected): void
    {
        $router = new Router('https://example.com', request: new Request('GET', $path));
        $received = null;
        $router->get($pattern, static function (array $data, Router $router) use (&$received): void {
            $received = $data;
            self::assertSame($data, $router->data());
            self::assertSame($data, $router->current()->data);
        });
        self::assertSame($expected !== null, $router->dispatch());
        self::assertSame($expected, $received);
        self::assertSame($expected === null ? 404 : null, $router->error());
    }

    public static function paths(): array
    {
        return [
            ['/users/{id}', '/users/123', ['id' => '123']],
            ['/users/{id}', '/users', null],
            ['/users/{id?}', '/users/123', ['id' => '123']],
            ['/users/{id?}', '/users', ['id' => null]],
            ['/users/{id?}', '/users/', ['id' => null]],
            ['/users/{id?}', '/users/123/extra', null],
            ['/users/{user}/posts/{post?}', '/users/10/posts/20', ['user' => '10', 'post' => '20']],
            ['/users/{user}/posts/{post?}', '/users/10/posts', ['user' => '10', 'post' => null]],
            ['/users/{user}/posts/{post?}', '/users/posts/20', null],
            ['/users/{user}/posts/{post?}', '/users/10', null],
            ['/users/{id?}/{tab?}', '/users', ['id' => null, 'tab' => null]],
            ['/users/{id?}/{tab?}', '/users/10', ['id' => '10', 'tab' => null]],
            ['/users/{id?}/{tab?}', '/users/10/posts', ['id' => '10', 'tab' => 'posts']],
            ['/users/{id?}/{tab?}', '/users//posts', null],
            ['/users/{id?}/{tab?}', '/users/10/posts/extra', null],
            ['/{first?}/{second?}', '/', ['first' => null, 'second' => null]],
            ['/{first?}/{second?}', '/one', ['first' => 'one', 'second' => null]],
            ['/{first?}/{second?}', '/one/two', ['first' => 'one', 'second' => 'two']],
            ['/files/{name}.{ext}/{version?}', '/files/readme.md',
                ['name' => 'readme', 'ext' => 'md', 'version' => null]],
            ['/files/{name}.{ext}/{version?}', '/files/readme.md/2',
                ['name' => 'readme', 'ext' => 'md', 'version' => '2']],
            ['/file.json/{id?}', '/fileXjson', null],
            ['/users/{id?}', '/users/a%2Fb', ['id' => 'a%2Fb']],
            ['/users/{id?}', '/users/0', ['id' => '0']],
        ];
    }

    public function testStaticWinsInBothRegistrationOrders(): void
    {
        foreach ([true, false] as $optionalFirst) {
            $router = new Router('https://example.com', request: new Request('GET', '/users'));
            $received = '';
            $static = static function () use (&$received): void {
                $received = 'static';
            };
            $optional = static function () use (&$received): void {
                $received = 'optional';
            };
            if ($optionalFirst) {
                $router->get('/users/{id?}', $optional);
                $router->get('/users', $static);
            } else {
                $router->get('/users', $static);
                $router->get('/users/{id?}', $optional);
            }
            self::assertTrue($router->dispatch());
            self::assertSame('static', $received);
        }
    }

    public function testDynamicPriorityRemainsFirstRegistered(): void
    {
        foreach ([true, false] as $optionalFirst) {
            $router = new Router('https://example.com', request: new Request('GET', '/users/10'));
            $selected = '';
            $first = $optionalFirst ? '/users/{id?}' : '/users/{id}';
            $second = $optionalFirst ? '/users/{id}' : '/users/{id?}';
            $router->get($first, static function () use (&$selected): void {
                $selected = 'first';
            });
            $router->get($second, static function () use (&$selected): void {
                $selected = 'second';
            });
            self::assertTrue($router->dispatch());
            self::assertSame('first', $selected);
        }
    }

    public function testAbsentParameterOverridesBodyAndGroupDoesNotCorruptValue(): void
    {
        foreach (['/admin/users', '/admin/users/admin123'] as $path) {
            $router = new Router('https://example.com', request: new Request(
                'POST',
                $path,
                '{"id":"forged"}',
                'application/json',
            ));
            $router->group('admin');
            $router->post('/users/{id?}', static function (): void {
            });
            self::assertTrue($router->dispatch());
            self::assertSame(['id' => $path === '/admin/users' ? null : 'admin123'], $router->data());
        }
    }

    #[DataProvider('urls')]
    public function testNamedUrls(string $pattern, array $data, string $expected): void
    {
        $router = new Router('https://example.com', request: new Request('GET'));
        $router->get($pattern, static function (): void {
        }, 'named');
        self::assertSame('https://example.com' . $expected, $router->route('named', $data));
    }

    public static function urls(): array
    {
        return [
            ['/users/{id?}', [], '/users'],
            ['/users/{id?}', ['id' => null], '/users'],
            ['/users/{id?}', ['id' => 'a/b'], '/users/a%2Fb'],
            ['/users/{id?}/{tab?}', [], '/users'],
            ['/users/{id?}/{tab?}', ['id' => 10], '/users/10'],
            ['/users/{id?}/{tab?}', ['id' => 10, 'tab' => 'posts'], '/users/10/posts'],
            ['/users/{id?}', ['id' => null, 'q' => 'hello world'], '/users?q=hello%20world'],
            ['/{id?}', [], '/'],
            ['/users/{user}/posts/{post?}', ['user' => 10], '/users/10/posts'],
        ];
    }

    public function testNamedUrlCannotSkipEarlierOptional(): void
    {
        $router = new Router('https://example.com', request: new Request('GET'));
        $router->get('/users/{id?}/{tab?}', static function (): void {
        }, 'named');
        $this->expectException(ConfigurationException::class);
        $router->route('named', ['tab' => 'posts']);
    }

    #[DataProvider('invalidPatterns')]
    public function testMalformedAndNonTrailingOptionalsAreRejected(string $pattern): void
    {
        $router = new Router('https://example.com', request: new Request('GET'));
        $this->expectException(ConfigurationException::class);
        $router->get($pattern, static function (): void {
        });
    }

    public static function invalidPatterns(): array
    {
        return [
            ['/users/{id?}/posts'], ['/users/{id?}/{post}'], ['/users/pre-{id?}'],
            ['/users/{id?}.json'], ['/users/{id??}'], ['/users/{?}'],
            ['/users/{id}/{id?}'], ['/users/{id?}/{id?}'], ['/users?query'],
        ];
    }
}
