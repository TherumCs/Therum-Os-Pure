<?php
declare(strict_types=1);

namespace Therum;

/**
 * Frontend page renderer. Reads a page record from Pages and wraps it in a
 * minimal HTML shell. v1 trusts the body field to be valid HTML — the only
 * editor is the admin which only admins can reach.
 *
 *   /            → renders page slug "home" if it exists, else a placeholder
 *   /page/{slug} → renders the named page, 404 if missing
 */
final class Renderer
{
    public static function home(): string
    {
        $home = Pages::get('home');
        if ($home) return self::render($home);
        return self::placeholder();
    }

    public static function show(string $slug): string
    {
        $page = Pages::get($slug);
        if (!$page) {
            http_response_code(404);
            return self::layout('Not found', '<h1>Not found</h1><p>No page at <code>/' . htmlspecialchars($slug, ENT_QUOTES) . '</code>.</p>');
        }
        return self::render($page);
    }

    private static function render(array $page): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $body = (string) ($page['body'] ?? '');
        return self::layout($h($page['title'] ?? ''), $body);
    }

    private static function placeholder(): string
    {
        $site = (array) t_app()->storage->get('site', []);
        $title = htmlspecialchars((string) ($site['title'] ?? 'Therum site'), ENT_QUOTES);
        return self::layout($title, <<<HTML
<article class="t-fe-empty">
  <h1>{$title}</h1>
  <p>No home page yet. <a href="/admin/pages/new">Create one →</a> (give it the slug <code>home</code>).</p>
</article>
HTML);
    }

    private static function layout(string $title, string $body): string
    {
        $site = (array) t_app()->storage->get('site', []);
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $brand   = $h($site['title']   ?? 'Therum site');
        $tagline = $h($site['tagline'] ?? '');
        $tagline_html = $tagline ? '<p class="t-fe-tagline">' . $tagline . '</p>' : '';

        // Frontend pages can contain arbitrary admin-authored HTML (the editor
        // is HTML-by-design). Lock the public surface with a strict CSP so an
        // admin XSS can't pull third-party scripts. Inline styles are allowed
        // for page-body markup; scripts and external sources are not.
        if (!headers_sent()) {
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} · {$brand}</title>
<link rel="stylesheet" href="/therum/assets/frontend.css">
</head>
<body class="t-fe">
<header class="t-fe-head"><a class="t-fe-brand" href="/">{$brand}</a>{$tagline_html}</header>
<main class="t-fe-main">{$body}</main>
<footer class="t-fe-foot">Powered by Therum OS</footer>
</body>
</html>
HTML;
    }
}
