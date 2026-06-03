<?php
declare(strict_types=1);

namespace Therum;

/**
 * Admin chrome — layout, login, dashboard, settings. Page-list and
 * editor views live in Builder.php to keep this file focused on the shell.
 *
 * Render style: each view returns an HTML string. The layout() helper wraps
 * a body fragment with the sidebar + topbar. Inline styles are deliberately
 * minimal; the assets/admin.css file holds the real styling.
 */
final class Admin
{
    /** Login screen. POST handler at /admin/login validates and redirects. */
    public static function login(?string $error = null, string $email = ''): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $err_html = $error ? '<div class="t-err">' . $h($error) . '</div>' : '';
        return self::page('Sign in', <<<HTML
<main class="t-install-card">
  <div class="t-brand">Therum OS</div>
  <h1>Sign in</h1>
  {$err_html}
  <form method="post" action="/admin/login" class="t-form" autocomplete="on">
    <label>Email <input type="email" name="email" required value="{$h($email)}" /></label>
    <label>Password <input type="password" name="password" required autocomplete="current-password" /></label>
    <button type="submit" class="t-btn t-btn-primary">Sign in →</button>
  </form>
</main>
HTML, /* bare */ true);
    }

    public static function handle_login(): string
    {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (t_app()->auth->login($email, $password)) {
            header('Location: /admin');
            exit;
        }
        return self::login('Email or password did not match.', $email);
    }

    public static function dashboard(): string
    {
        $pages  = Pages::list();
        $count  = count($pages);
        $plural = $count === 1 ? '' : 's';
        $site   = (array) t_app()->storage->get('site', []);
        $title  = htmlspecialchars((string) ($site['title'] ?? 'Therum site'), ENT_QUOTES);
        $recent = array_slice($pages, 0, 5, true);
        $recent_html = '';
        if ($recent) {
            $rows = '';
            foreach ($recent as $slug => $p) {
                $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
                $when = date('M j, Y · g:i a', (int) ($p['updated_at'] ?? 0));
                $rows .= '<tr><td><a href="/admin/pages/' . $h($slug) . '/edit">' . $h($p['title'] ?? $slug) . '</a></td><td class="t-muted">' . $h($when) . '</td></tr>';
            }
            $recent_html = '<h3>Recent pages</h3><table class="t-table">' . $rows . '</table>';
        }
        return self::layout('Dashboard', <<<HTML
<div class="t-page-head">
  <h1>Welcome back.</h1>
  <p class="t-lede">{$title}</p>
</div>
<div class="t-cards">
  <a class="t-card" href="/admin/pages">
    <div class="t-card-num">{$count}</div>
    <div class="t-card-label">Page{$plural}</div>
  </a>
  <a class="t-card" href="/admin/pages/new">
    <div class="t-card-num">＋</div>
    <div class="t-card-label">New page</div>
  </a>
  <a class="t-card" href="/admin/settings">
    <div class="t-card-num">⚙</div>
    <div class="t-card-label">Settings</div>
  </a>
</div>
{$recent_html}
HTML);
    }

    public static function settings(?string $flash = null): string
    {
        $site = (array) t_app()->storage->get('site', []);
        $h = fn(string $s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $flash_html = $flash ? '<div class="t-ok">' . $h($flash) . '</div>' : '';
        $title   = $h($site['title']   ?? '');
        $tagline = $h($site['tagline'] ?? '');
        return self::layout('Settings', <<<HTML
<div class="t-page-head"><h1>Site settings</h1></div>
{$flash_html}
<form method="post" action="/admin/settings" class="t-form">
  <label>Site title <input name="title" required value="{$title}" /></label>
  <label>Tagline <input name="tagline" value="{$tagline}" /></label>
  <button type="submit" class="t-btn t-btn-primary">Save</button>
</form>
HTML);
    }

    public static function handle_settings(): string
    {
        $title   = trim((string) ($_POST['title']   ?? ''));
        $tagline = trim((string) ($_POST['tagline'] ?? ''));
        $site = (array) t_app()->storage->get('site', []);
        $site['title']   = $title ?: ($site['title'] ?? 'Therum site');
        $site['tagline'] = $tagline;
        t_app()->storage->set('site', $site);
        return self::settings('Settings saved.');
    }

    // ── Layout helpers ────────────────────────────────────────────────────

    /** Outermost HTML wrapper. */
    public static function page(string $title, string $body, bool $bare = false): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $site = (array) t_app()->storage->get('site', []);
        $brand = $h($site['title'] ?? 'Therum OS');
        $bodyclass = $bare ? 't-install' : 't-admin';
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$h($title)} · {$brand}</title>
<link rel="stylesheet" href="/therum/assets/admin.css">
</head>
<body class="{$bodyclass}">
{$body}
</body>
</html>
HTML;
    }

    /** Admin shell: sidebar + topbar + content area. */
    public static function layout(string $title, string $body): string
    {
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $user = t_app()->auth->current_user() ?? ['name' => '', 'email' => ''];
        $name = $h($user['name'] ?: $user['email']);
        $site = (array) t_app()->storage->get('site', []);
        $brand = $h($site['title'] ?? 'Therum OS');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $cls = function (string $prefix) use ($uri): string {
            return str_starts_with($uri, $prefix) ? ' is-active' : '';
        };
        return self::page($title, <<<HTML
<div class="t-shell">
  <aside class="t-sidebar">
    <div class="t-sidebar-brand">{$brand}</div>
    <nav class="t-nav">
      <a class="t-nav-item{$cls('/admin/pages')}{$cls('/admin/dashboard')}" href="/admin">Dashboard</a>
      <a class="t-nav-item{$cls('/admin/pages')}" href="/admin/pages">Pages</a>
      <a class="t-nav-item{$cls('/admin/settings')}" href="/admin/settings">Settings</a>
      <a class="t-nav-item{$cls('/admin/updates')}" href="/admin/updates">Updates</a>
    </nav>
    <div class="t-sidebar-foot">
      <div class="t-user">{$name}</div>
      <a href="/admin/logout" class="t-link-muted">Sign out</a>
    </div>
  </aside>
  <main class="t-main">
    {$body}
  </main>
</div>
HTML);
    }
}
