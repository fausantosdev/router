<?php

declare(strict_types=1);

namespace CoffeeCode\Router;

/** A small, immutable snapshot. This is not a PSR-7 request. */
final class Request
{
    public const MAX_BODY_BYTES = 1048576;

    /** @var array<string, mixed>|null */
    private ?array $parsed = null;

    /** @param array<string, mixed> $form */
    public function __construct(
        private readonly string $method,
        private readonly string $path = '/',
        private readonly string $body = '',
        private readonly string $contentType = '',
        private readonly array $form = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $route = $_GET['route'] ?? '/';
        $body = '';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $stream = fopen('php://input', 'rb');
            if ($stream !== false) {
                $body = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
                fclose($stream);
                if ($body === false) {
                    throw new ConfigurationException('Cannot read the request body.');
                }
            }
        }

        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) {
            $body = str_repeat('x', self::MAX_BODY_BYTES + 1);
        }

        return new self(
            $method,
            is_string($route) ? $route : "\0",
            $body,
            (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            $_POST,
        );
    }

    public function path(): string
    {
        if ($this->path === '' || $this->path[0] !== '/' || preg_match('/[\x00-\x1F\x7F?#]/', $this->path)) {
            throw new RequestException('Invalid request path.', 400);
        }

        return rtrim($this->path, '/') ?: '/';
    }

    public function method(): string
    {
        $method = strtoupper($this->method);
        if (!preg_match('/^[A-Z]+$/D', $method)) {
            throw new RequestException('Invalid request method.', 400);
        }

        // Overrides are intentionally restricted to POST forms.
        if ($method === 'POST' && $this->isForm()) {
            $override = $this->data()['_method'] ?? null;
            if (is_string($override) && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->parsed ??= $this->parseData();
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->data();
        if (strtoupper($this->method) === 'POST' && $this->isForm()) {
            unset($data['_method']);
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function parseData(): array
    {
        if (strlen($this->body) > self::MAX_BODY_BYTES) {
            throw new RequestException('Request body exceeds 1 MiB.', 413);
        }
        if (in_array(strtoupper($this->method), ['GET', 'HEAD'], true)) {
            return [];
        }
        $type = $this->mediaType();
        if ($type === 'multipart/form-data') {
            if (strtoupper($this->method) !== 'POST') {
                throw new RequestException('Multipart is supported only for POST.', 415);
            }
            return $this->form;
        }
        if ($type === 'application/x-www-form-urlencoded' || ($type === '' && $this->form !== [])) {
            if (strtoupper($this->method) === 'POST' && $this->form !== []) {
                return $this->form;
            }
            parse_str($this->body, $data);
            return $data;
        }
        if ($this->body === '') {
            return [];
        }
        if ($type === 'application/json' || preg_match('~^application/[a-z0-9!#$&^_.+-]+\+json$~D', $type)) {
            try {
                $object = json_decode($this->body, false, 512, JSON_THROW_ON_ERROR);
                if (!$object instanceof \stdClass) {
                    throw new RequestException('The JSON body must be an object.', 400);
                }
                /** @var array<string, mixed> $data */
                $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
                return $data;
            } catch (\JsonException $exception) {
                throw new RequestException('Malformed JSON body.', 400, $exception);
            }
        }

        throw new RequestException('Unsupported request Content-Type.', 415);
    }

    private function isForm(): bool
    {
        return in_array($this->mediaType(), ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
            || ($this->mediaType() === '' && $this->form !== []);
    }

    private function mediaType(): string
    {
        return strtolower(trim(explode(';', $this->contentType, 2)[0]));
    }
}
