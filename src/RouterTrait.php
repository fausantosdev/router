<?php

declare(strict_types=1);

namespace CoffeeCode\Router;

trait RouterTrait
{
    /** @param array<string>|string|null $middleware */
    protected function addRoute(
        string $method,
        string $route,
        callable|string $handler,
        ?string $name = null,
        array|string|null $middleware = null,
    ): void {
        if ($route === '' || $route[0] !== '/') {
            throw new ConfigurationException('Route paths must start with /.');
        }
        $route = rtrim($route, '/') ?: '/';
        $path = $this->group !== null ? '/' . $this->group . ($route === '/' ? '' : $route) : $route;
        $literalPath = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_-]*\?\}/', '', $path);
        if (preg_match('/[\x00-\x20\x7F?#]/', $literalPath ?? $path)) {
            throw new ConfigurationException('Route paths cannot contain whitespace, controls, query or fragment.');
        }
        if ($name !== null && (trim($name) === '' || isset($this->namedRoutes[$name]))) {
            throw new ConfigurationException('Route names must be non-empty and unique.');
        }
        foreach ($this->routes as $existing) {
            if ($existing->method === $method && $existing->path === $path) {
                throw new ConfigurationException('Duplicate route: ' . $method . ' ' . $path);
            }
        }
        $definition = new RouteDefinition(
            $method,
            $path,
            $handler,
            $name,
            $this->namespace,
            $this->group,
            array_merge($this->middleware, $this->normalizeMiddleware($middleware)),
        );
        $this->routes[] = $definition;
        if ($name !== null) {
            $this->namedRoutes[$name] = $definition;
        }
    }

    /**
     * @param array<string>|string|null $middleware
     * @return list<string>
     */
    protected function normalizeMiddleware(array|string|null $middleware): array
    {
        if ($middleware === null) {
            return [];
        }
        $items = is_array($middleware) ? array_values($middleware) : [$middleware];
        foreach ($items as $item) {
            if (!is_string($item) || $item === '') {
                throw new ConfigurationException('Middleware entries must be class names.');
            }
        }
        return $items;
    }

    private function execute(): bool
    {
        $route = $this->route;
        if ($route === null) {
            throw new ConfigurationException('No selected route.');
        }
        foreach ($route->middlewares as $class) {
            if (!class_exists($class)) {
                throw new ConfigurationException('Middleware class not found: ' . $class);
            }
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                throw new ConfigurationException('Middleware is not instantiable: ' . $class);
            }
            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new ConfigurationException('Middleware constructor must not require arguments: ' . $class);
            }
            if (!$reflection->hasMethod('handle') || !$reflection->getMethod('handle')->isPublic()) {
                throw new ConfigurationException('Middleware requires a public handle() method: ' . $class);
            }
            $middleware = new $class();
            $result = $middleware->handle($this);
            if (!is_bool($result)) {
                throw new ConfigurationException('Middleware handle() must return bool: ' . $class);
            }
            if (!$result) {
                // Authorization is application policy; false alone is not an HTTP error.
                return false;
            }
        }
        $handler = $route->handler;
        if (is_callable($handler)) {
            $handler($this->data ?? [], $this);
            return true;
        }
        if (!is_string($handler)) {
            throw new ConfigurationException('Invalid route handler.');
        }
        $parts = explode($this->separator, $handler);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ConfigurationException('Controller handler must use Class' . $this->separator . 'method syntax.');
        }
        [$class, $method] = $parts;
        if ($class[0] !== '\\' && $route->namespace !== null) {
            $class = $route->namespace . '\\' . $class;
        }
        $class = ltrim($class, '\\');
        if (!class_exists($class)) {
            throw new ConfigurationException('Controller class not found: ' . $class);
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable() || !$reflection->hasMethod($method)) {
            throw new ConfigurationException('Controller or action is not invocable: ' . $handler);
        }
        $action = $reflection->getMethod($method);
        if (!$action->isPublic() || $action->isStatic() || str_starts_with($method, '__')) {
            throw new ConfigurationException('Controller action must be a public instance method: ' . $handler);
        }
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 1) {
            throw new ConfigurationException('Controller constructor may require only the Router: ' . $class);
        }
        $controller = new $class($this);
        $controller->$method($this->data ?? []);
        return true;
    }
}
