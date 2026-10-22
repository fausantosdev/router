<?php

declare(strict_types=1);

namespace CoffeeCode\Router;

abstract class Dispatch
{
    use RouterTrait;

    public const BAD_REQUEST = 400;
    public const NOT_FOUND = 404;
    public const METHOD_NOT_ALLOWED = 405;
    public const NOT_IMPLEMENTED = 501;
    public const PAYLOAD_TOO_LARGE = 413;
    public const UNSUPPORTED_MEDIA_TYPE = 415;

    protected string $projectUrl;
    protected string $separator;
    protected ?string $namespace = null;
    protected ?string $group = null;
    /** @var list<string> */
    protected array $middleware = [];
    /** @var list<RouteDefinition> */
    protected array $routes = [];
    /** @var array<string, RouteDefinition> */
    protected array $namedRoutes = [];
    protected ?RouteDefinition $route = null;
    /** @var array<string, mixed>|null */
    protected ?array $data = null;
    protected ?int $error = null;
    /** @var list<string> */
    protected array $allowed = [];
    protected Request $request;
    protected string $path = '/';

    public function __construct(string $projectUrl, ?string $separator = ':', ?Request $request = null)
    {
        if (
            !filter_var($projectUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($projectUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
            || preg_match('/[\x00-\x20\x7F]/', $projectUrl)
            || parse_url($projectUrl, PHP_URL_QUERY) !== null
            || parse_url($projectUrl, PHP_URL_FRAGMENT) !== null
            || parse_url($projectUrl, PHP_URL_USER) !== null
        ) {
            throw new ConfigurationException(
                'Project URL must be an absolute HTTP(S) URL without credentials, query or fragment.',
            );
        }
        $this->projectUrl = rtrim($projectUrl, '/');
        $this->separator = $separator ?? ':';
        if ($this->separator === '') {
            throw new ConfigurationException('Handler separator cannot be empty.');
        }
        $this->request = $request ?? Request::fromGlobals();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['routes' => $this->routes, 'current' => $this->route, 'error' => $this->error];
    }

    /** @param array<string, mixed>|null $data */
    public function route(string $name, ?array $data = null): ?string
    {
        $route = $this->namedRoutes[$name] ?? null;
        if ($route === null) {
            return null;
        }
        $data ??= [];
        $url = $route->path;
        $omittedOptional = false;
        foreach ($route->parameters as $parameter) {
            $optional = in_array($parameter, $route->optionalParameters, true);
            if ($optional && (!array_key_exists($parameter, $data) || $data[$parameter] === null)) {
                $url = str_replace('/{' . $parameter . '?}', '', $url);
                unset($data[$parameter]);
                $omittedOptional = true;
                continue;
            }
            if ($optional && $omittedOptional) {
                throw new ConfigurationException('Optional URL parameters cannot skip an earlier segment.');
            }
            if (!array_key_exists($parameter, $data) || !is_scalar($data[$parameter])) {
                throw new ConfigurationException('Missing or non-scalar URL parameter: ' . $parameter);
            }
            $value = (string) $data[$parameter];
            if ($value === '' || $value === '.' || $value === '..') {
                throw new ConfigurationException('URL parameter cannot be empty or a dot segment.');
            }
            $placeholder = '{' . $parameter . ($optional ? '?' : '') . '}';
            $url = str_replace($placeholder, rawurlencode($value), $url);
            unset($data[$parameter]);
        }
        $url = $url !== '' ? $url : '/';
        $query = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        return $this->projectUrl . $url . ($query !== '' ? '?' . $query : '');
    }

    public function namespace(?string $namespace): Dispatch
    {
        $this->namespace = $namespace !== null && $namespace !== '' ? trim($namespace, '\\') : null;
        return $this;
    }

    /** @param array<string>|string|null $middleware */
    public function group(?string $group, array|string|null $middleware = null): Dispatch
    {
        $this->group = $group !== null && trim($group, '/') !== '' ? trim($group, '/') : null;
        $this->middleware = $this->normalizeMiddleware($middleware);
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function data(): ?array
    {
        return $this->data;
    }

    public function current(): ?object
    {
        if ($this->route === null) {
            return null;
        }
        return (object) [
            'namespace' => $this->route->namespace,
            'group' => $this->route->group,
            'path' => $this->path,
            'route' => $this->route->path,
            'name' => $this->route->name,
            'method' => $this->route->method,
            'middlewares' => $this->route->middlewares,
            'handler' => $this->route->handler,
            'action' => is_string($this->route->handler) && str_contains($this->route->handler, $this->separator)
                ? explode($this->separator, $this->route->handler, 2)[1] : null,
            'data' => $this->data,
        ];
    }

    public function home(): string
    {
        return $this->projectUrl;
    }

    public function request(): Request
    {
        return $this->request;
    }

    /** @param array<string, mixed>|null $data */
    public function redirect(string $route, ?array $data = null, int $status = 302): never
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new ConfigurationException('Invalid redirect status.');
        }
        $target = $this->route($route, $data);
        if ($target === null) {
            if (
                preg_match('/[\x00-\x20\x7F]/', $route)
                || str_contains($route, '\\')
                || str_starts_with($route, '//')
            ) {
                throw new ConfigurationException('Invalid redirect target.');
            }
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $route)) {
                if (
                    !filter_var($route, FILTER_VALIDATE_URL)
                    || !in_array(strtolower((string) parse_url($route, PHP_URL_SCHEME)), ['http', 'https'], true)
                ) {
                    throw new ConfigurationException('Redirect URL must use HTTP(S).');
                }
                $target = $route;
            } else {
                $target = $this->projectUrl . '/' . ltrim($route, '/');
            }
        }
        if (headers_sent()) {
            throw new ConfigurationException('Cannot redirect after response output.');
        }
        header('Location: ' . $target, true, $status);
        exit;
    }

    public function error(): ?int
    {
        return $this->error;
    }

    /** @return list<string> Methods for the matched path, including HEAD/OPTIONS. */
    public function allowedMethods(): array
    {
        return $this->allowed;
    }

    public function dispatch(): bool
    {
        $this->route = null;
        $this->data = null;
        $this->error = null;
        $this->allowed = [];
        try {
            $this->path = $this->request->path();
            $method = $this->request->method();
            $body = $this->request->payload();
        } catch (RequestException $exception) {
            $this->error = $exception->status;
            return false;
        }

        // Stable sorting: literals first; registration order resolves dynamic overlaps.
        $routes = $this->routes;
        usort(
            $routes,
            static fn (RouteDefinition $a, RouteDefinition $b): int => (int) $b->static() <=> (int) $a->static(),
        );
        $matches = [];
        foreach ($routes as $route) {
            $parameters = $route->match($this->path);
            if ($parameters !== null) {
                $matches[] = [$route, $parameters];
                $this->allowed[] = $route->method;
            }
        }
        if ($matches === []) {
            $this->error = self::NOT_FOUND;
            return false;
        }
        if (in_array('GET', $this->allowed, true)) {
            $this->allowed[] = 'HEAD';
        }
        $this->allowed[] = 'OPTIONS';
        $this->allowed = array_values(array_unique($this->allowed));
        sort($this->allowed);
        $selected = null;
        foreach ($matches as $match) {
            if ($match[0]->method === $method) {
                $selected = $match;
                break;
            }
        }
        if ($selected === null && $method === 'HEAD') {
            foreach ($matches as $match) {
                if ($match[0]->method === 'GET') {
                    $selected = $match;
                    break;
                }
            }
        }
        if ($selected === null && $method === 'OPTIONS') {
            header('Allow: ' . implode(', ', $this->allowed));
            http_response_code(204);
            return true;
        }
        if ($selected === null) {
            $this->error = self::METHOD_NOT_ALLOWED;
            return false;
        }
        [$this->route, $parameters] = $selected;
        $this->data = array_replace($body, $parameters);
        if ($method !== 'HEAD') {
            return $this->execute();
        }
        // Suppress output from both middleware and handler, including on exceptions.
        $level = ob_get_level();
        ob_start(static fn (string $buffer): string => '');
        try {
            return $this->execute();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
