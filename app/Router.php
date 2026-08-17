<?php

declare(strict_types=1);

final class RouteMatch
{
    /** @param list<string> $middleware */
    public function __construct(
        public readonly string $group,
        public readonly array $middleware,
        /** @var array<string, string> */
        public readonly array $parameters = [],
    ) {
    }

    public function hasMiddleware(string $name): bool
    {
        return in_array($name, $this->middleware, true);
    }
}

final class Router
{
    /** @var list<array{group: string, pattern: string, methods: list<string>, middleware: list<string>}> */
    private array $routes = [];

    /** @param list<string> $methods @param list<string> $middleware */
    public function add(string $group, string $pattern, array $methods, array $middleware = []): void
    {
        $this->routes[] = [
            'group' => $group,
            'pattern' => $pattern,
            'methods' => $methods,
            'middleware' => $middleware,
        ];
    }

    public function match(string $method, string $path): RouteMatch
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            $matches = [];
            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }
            if ($allowed !== []) {
                break;
            }
            $allowed = $route['methods'];
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }
            $parameters = [];
            foreach ($matches as $name => $value) {
                if (is_string($name)) {
                    $parameters[$name] = $value;
                }
            }
            return new RouteMatch($route['group'], $route['middleware'], $parameters);
        }
        method_not_allowed($allowed === [] ? ['GET', 'HEAD'] : $allowed);
    }
}
