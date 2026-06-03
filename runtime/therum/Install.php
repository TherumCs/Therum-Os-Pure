<?php
declare(strict_types=1);

namespace Therum;

/**
 * First-run installer. Single page, single POST endpoint. Asks for site
 * title, admin name, admin email, admin password. Writes site.json + the
 * first record into users via Auth::create_user(), then redirects to login.
 *
 * Rendered by index.php when has_any_user() is false. Pure has no separate
 * install URL — visiting `/` before setup lands here.
 */
final class Install
{
    public static function render(?array $errors = null, ?array $values = null): string
    {
        $errors = $errors ?? [];
        $values = $values ?? ['site_title' => '', 'name' => '', 'email' => ''];
        $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
        $err_html = '';
        if (!empty($errors)) {
            $err_html = '<div class="t-err">' . implode('<br>', array_map($h, $errors)) . '</div>';
        }
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set up Therum OS</title>
<link rel="stylesheet" href="/therum/assets/admin.css">
</head>
<body class="t-install">
  <main class="t-install-card">
    <div class="t-brand">Therum OS</div>
    <h1>Set up your install</h1>
    <p class="t-lede">One-time setup. We'll create your site and your first admin account.</p>
    {$err_html}
    <form method="post" action="/install" class="t-form" autocomplete="off">
      <label>Site title
        <input name="site_title" required value="{$h($values['site_title'])}" placeholder="My Therum Site" />
      </label>
      <label>Your name
        <input name="name" required value="{$h($values['name'])}" placeholder="Jane Doe" />
      </label>
      <label>Email
        <input type="email" name="email" required value="{$h($values['email'])}" placeholder="you@example.com" />
      </label>
      <label>Password <small>(8+ characters)</small>
        <input type="password" name="password" required minlength="8" autocomplete="new-password" />
      </label>
      <button type="submit" class="t-btn t-btn-primary">Create site →</button>
    </form>
  </main>
</body>
</html>
HTML;
    }

    public static function handle_post(): string
    {
        $auth = t_app()->auth;
        $storage = t_app()->storage;

        $site_title = trim((string) ($_POST['site_title'] ?? ''));
        $name       = trim((string) ($_POST['name'] ?? ''));
        $email      = trim((string) ($_POST['email'] ?? ''));
        $password   = (string) ($_POST['password'] ?? '');

        $errors = [];
        if ($site_title === '') $errors[] = 'Site title is required.';
        if ($name === '')       $errors[] = 'Your name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

        if ($errors) {
            return self::render($errors, [
                'site_title' => $site_title, 'name' => $name, 'email' => $email,
            ]);
        }

        if (!$auth->create_user($email, $password, $name)) {
            return self::render(['Could not create user — check email + password and try again.']);
        }

        $storage->set('site', [
            'title'       => $site_title,
            'tagline'     => 'Built with Therum OS.',
            'installed_at' => time(),
        ]);

        // Auto-login + redirect to admin.
        $auth->login($email, $password);
        header('Location: /admin');
        exit;
    }
}
