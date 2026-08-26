<?php

declare(strict_types=1);

namespace App;

final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    /** @param callable $handler */
    public function add(string $method, string $pattern, callable $handler): void
    {
        // "/api/orders/{id}" -> "#^/api/orders/(?P<id>[^/]+)$#"
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';

        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $method) {
                continue;
            }

            $args = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            ($route['handler'])($args);

            return;
        }

        $pathMatched
            ? Http::error('method_not_allowed', 405)
            : Http::error('not_found', 404);
    }
}
