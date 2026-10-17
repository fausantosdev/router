<?php

declare(strict_types=1);

namespace CoffeeCode\Router\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    private static mixed $process = null;
    private static string $address;
    private static string $log;
    private static mixed $stderr = null;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException($error);
        }
        self::$address = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        self::$log = tempnam(sys_get_temp_dir(), 'router-http-');
        self::$process = proc_open(
            [
                PHP_BINARY, '-d', 'error_reporting=32767', '-d', 'display_errors=0',
                '-S', self::$address, __DIR__ . '/fixtures/http.php',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', self::$log, 'a'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource(self::$process)) {
            throw new \RuntimeException('Unable to start HTTP fixture.');
        }
        fclose($pipes[0]);
        // Wait for the server's startup message using blocking I/O, not polling.
        $read = [$pipes[2]];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, 5);
        if ($ready !== 1) {
            proc_terminate(self::$process);
            fclose($pipes[2]);
            proc_close(self::$process);
            self::$process = null;
            throw new \RuntimeException('HTTP fixture did not start.');
        }
        $message = fgets($pipes[2]);
        file_put_contents(self::$log, $message ?: '');
        if (!str_contains($message ?: '', 'started')) {
            proc_terminate(self::$process);
            throw new \RuntimeException('HTTP startup failed: ' . $message);
        }
        self::$stderr = $pipes[2];
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            $remaining = stream_get_contents(self::$stderr);
            fclose(self::$stderr);
            file_put_contents(self::$log, $remaining ?: '', FILE_APPEND);
            proc_close(self::$process);
        }
        $log = file_get_contents(self::$log);
        unlink(self::$log);
        if (preg_match('/(?:Deprecated|Warning|Fatal error):/', $log)) {
            throw new \RuntimeException('Unexpected PHP diagnostics: ' . $log);
        }
    }

    /** @return array{int, array<string, string>, string} */
    private function request(string $method, string $route, string $body = '', string $type = ''): array
    {
        $socket = stream_socket_client('tcp://' . self::$address, $errno, $error, 5);
        self::assertIsResource($socket, $error);
        stream_set_timeout($socket, 5);
        $request = $method . ' /?route=' . rawurlencode($route) . " HTTP/1.1\r\nHost: " . self::$address
            . "\r\nConnection: close\r\n";
        if ($type !== '') {
            $request .= 'Content-Type: ' . $type . "\r\n";
        }
        $request .= 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;
        $remaining = $request;
        while ($remaining !== '') {
            $written = fwrite($socket, $remaining);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Failed writing HTTP request.');
            }
            $remaining = substr($remaining, $written);
        }
        $response = stream_get_contents($socket);
        fclose($socket);
        self::assertIsString($response);
        [$head, $body] = explode("\r\n\r\n", $response, 2);
        $lines = explode("\r\n", $head);
        preg_match('/HTTP\/1\.[01] (\d+)/', array_shift($lines), $matches);
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }
        return [(int) $matches[1], $headers, $body];
    }

    #[DataProvider('verbs')]
    public function testRealBodiesAndParameters(string $method): void
    {
        [$status, , $body] = $this->request($method, '/users/42', '{"id":"forged","title":"ok"}', 'application/json');
        self::assertSame(200, $status);
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $expected = ['id' => '42', 'title' => 'ok'];
        self::assertSame($expected, $data['data']);
        self::assertSame($expected, $data['routerData']);
        self::assertSame($expected, $data['currentData']);
    }

    public static function verbs(): array
    {
        return [['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    public function testFormOverride(): void
    {
        [$status, , $body] = $this->request(
            'POST',
            '/users/42',
            '_method=DELETE&title=ok',
            'application/x-www-form-urlencoded',
        );
        self::assertSame(200, $status);
        self::assertSame(['title' => 'ok', 'id' => '42'], json_decode($body, true)['data']);
    }

    public function testMultipartPostAndJsonOverrideIsolation(): void
    {
        $body = "--fixture\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nok\r\n--fixture--\r\n";
        [$status, , $response] = $this->request(
            'POST',
            '/users/42',
            $body,
            'multipart/form-data; boundary=fixture',
        );
        self::assertSame(200, $status);
        self::assertSame(['title' => 'ok', 'id' => '42'], json_decode($response, true)['data']);
        [$status, , $response] = $this->request(
            'POST',
            '/users/42',
            '{"_method":"DELETE"}',
            'application/json',
        );
        self::assertSame(200, $status);
        self::assertSame(['_method' => 'DELETE', 'id' => '42'], json_decode($response, true)['data']);
    }

    public function testHeadAndExplicitHeadHaveNoBody(): void
    {
        [$status, , $body] = $this->request('HEAD', '/users/42');
        self::assertSame(200, $status);
        self::assertSame('', $body);
        [$status, $headers, $body] = $this->request('HEAD', '/explicit');
        self::assertSame(200, $status);
        self::assertSame('head', $headers['x-handler']);
        self::assertSame('', $body);
    }

    public function testOptionsAndMethodNotAllowed(): void
    {
        [$status, $headers, $body] = $this->request('OPTIONS', '/users/42');
        self::assertSame(204, $status);
        self::assertSame('DELETE, GET, HEAD, OPTIONS, PATCH, POST, PUT', $headers['allow']);
        self::assertSame('', $body);
        [$status, $headers] = $this->request('POST', '/named/42');
        self::assertSame(405, $status);
        self::assertSame('GET, HEAD, OPTIONS', $headers['allow']);
        [$status, $headers] = $this->request('OPTIONS', '/explicit');
        self::assertSame(204, $status);
        self::assertSame('options', $headers['x-handler']);
    }

    public function testNotFoundAndBadJson(): void
    {
        self::assertSame(404, $this->request('GET', '/missing')[0]);
        self::assertSame(400, $this->request('POST', '/users/42', '{broken', 'application/json')[0]);
        self::assertSame(415, $this->request('PUT', '/users/42', 'hello', 'text/plain')[0]);
    }

    public function testRedirectStatusAndLocation(): void
    {
        [$status, $headers] = $this->request('GET', '/redirect');
        self::assertSame(303, $status);
        self::assertSame('http://' . self::$address . '/users/42', $headers['location']);
        [$status, $headers] = $this->request('GET', '/named-redirect');
        self::assertSame(307, $status);
        self::assertSame('http://' . self::$address . '/named/a%2Fb', $headers['location']);
    }
}
