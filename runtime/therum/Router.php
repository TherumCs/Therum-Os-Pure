<?php
declare(strict_types=1);

namespace Therum;

/**
 * Tiny path-based router. No regex placeholders, just exact paths plus a
 * trailing-segment fallback (e.g. `/admin/pages/{slug}/edit`).
 *
 *   $router->get('/admin/pages', fn() => ...);
 *   $router->post('/admin/pages', fn() => ...);
 *   $router->any('/admin/pages/*', fn($slug) => ...);
 *
 * `*` matches one path segment and is passed to the handler as a positional
 * argument. Multiple wildcards work — they're passed left-to-right.
 *
 * Dispatch returns 404 if no route matches. Handlers return strings (echoed)
 * or call header()/exit themselves.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function any(string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => '*', 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $method) continue;
            $captures = $this->match($route['pattern'], $path);
            if ($captures === null) continue;
            $out = call_user_func_array($route['handler'], $captures);
            if (is_string($out)) echo $out;
            return;
        }

        http_response_code(404);
        echo $this->render_404($path);
    }

    /** Returns array of captured wildcard segments, or null if no match. */
    private function match(string $pattern, string $path): ?array
    {
        $pattern = rtrim($pattern, '/') ?: '/';
        $pat_parts = explode('/', $pattern);
        $path_parts = explode('/', $path);
        if (count($pat_parts) !== count($path_parts)) return null;
        $captures = [];
        foreach ($pat_parts as $i => $seg) {
            if ($seg === '*') {
                $captures[] = $path_parts[$i];
            } elseif ($seg !== $path_parts[$i]) {
                return null;
            }
        }
        return $captures;
    }

    private function render_404(string $path): string
    {
        $path_h = htmlspecialchars($path, ENT_QUOTES);
        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Not found</title>
<style>body{font:14px/1.5 system-ui;color:#1a1a1a;background:#fafafa;padding:60px;max-width:560px;margin:auto}
h1{font-size:18px;margin:0 0 10px}code{background:#eee;padding:2px 6px;border-radius:4px}</style>
</head><body>
<h1>404 — Not found</h1>
<p>No route matches <code>{$path_h}</code>.</p>
<p><a href="/">← Home</a></p>
</body></html>
HTML;
    }
}
