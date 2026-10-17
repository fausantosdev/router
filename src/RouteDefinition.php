<?php

declare(strict_types=1);

namespace CoffeeCode\Router;

/** @internal Compiled once at registration; never captures request data. */
final class RouteDefinition
{
    public readonly string $regex;
    /** @var list<string> */
    public readonly array $parameters;

    /**
     * @param callable|string $handler
     * @param list<string> $middlewares
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly mixed $handler,
        public readonly ?string $name,
        public readonly ?string $namespace,
        public readonly ?string $group,
        public readonly array $middlewares,
    ) {
        $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_-]*\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new ConfigurationException('Cannot compile route.');
        }
        $names = [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_-]*)\}$/D', $part, $match)) {
                if (in_array($match[1], $names, true)) {
                    throw new ConfigurationException('Duplicate route parameter: ' . $match[1]);
                }
                $names[] = $match[1];
                $regex .= '([^/]+)';
            } else {
                if (strpbrk($part, '{}') !== false) {
                    throw new ConfigurationException('Malformed route placeholder.');
                }
                $regex .= preg_quote($part, '~');
            }
        }
        $this->regex = '~\A' . $regex . '\z~D';
        $this->parameters = $names;
    }

    /** @return array<string, string>|null */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }
        $data = [];
        foreach ($this->parameters as $index => $name) {
            // Deliberately preserve encoded separators. No double decoding.
            $data[$name] = $matches[$index + 1];
        }
        return $data;
    }

    public function static(): bool
    {
        return $this->parameters === [];
    }
}
