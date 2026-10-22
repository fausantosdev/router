<?php

declare(strict_types=1);

namespace CoffeeCode\Router;

/** @internal Compiled once at registration; never captures request data. */
final class RouteDefinition
{
    public readonly string $regex;
    /** @var list<string> */
    public readonly array $parameters;
    /** @var list<string> Trailing, whole-segment parameters that may be omitted. */
    public readonly array $optionalParameters;

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
        // Peel off the optional suffix, leaving the existing required parser intact.
        $segments = explode('/', $path);
        $optional = [];
        while (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_-]*)\?\}$/D', $segments[count($segments) - 1] ?? '', $match)) {
            array_unshift($optional, $match[1]);
            array_pop($segments);
        }
        $requiredPath = implode('/', $segments);
        $parts = preg_split('/(\{[a-zA-Z_][a-zA-Z0-9_-]*\})/', $requiredPath, -1, PREG_SPLIT_DELIM_CAPTURE);
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
        foreach ($optional as $name) {
            if (in_array($name, $names, true)) {
                throw new ConfigurationException('Duplicate route parameter: ' . $name);
            }
            $names[] = $name;
            // Nested groups prohibit skipping an earlier optional segment.
            $regex .= '(?:/([^/]+)';
        }
        $regex .= str_repeat(')?', count($optional));
        if ($requiredPath === '' && $optional !== []) {
            // The normalized root is '/', not the empty string.
            $regex = '(?:' . $regex . '|/)';
        }
        $this->regex = '~\A' . $regex . '\z~D';
        $this->parameters = $names;
        $this->optionalParameters = $optional;
    }

    /** @return array<string, string|null>|null */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        $data = [];
        foreach ($this->parameters as $index => $name) {
            // Deliberately preserve encoded separators. No double decoding.
            $data[$name] = $matches[$index + 1] ?? null;
        }
        return $data;
    }

    public function static(): bool
    {
        return $this->parameters === [];
    }
}
