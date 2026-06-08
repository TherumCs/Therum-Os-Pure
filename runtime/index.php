<?php
declare(strict_types=1);

/**
 * Therum OS Pure — front controller.
 *
 * Entry point for every request. Bootstraps the standalone runtime, then
 * dispatches via the Router.
 *
 * No WordPress, no wp-load, no $wpdb. Sessions are PHP-native, storage is
 * file-backed JSON under therum/data/, auth is password_hash + session.
 *
 * Asset serving: when running under `php -S`, PHP's built-in server hands
 * static files (CSS/JS/images) directly when they exist on disk and only
 * falls through to index.php for unknown paths. On a real web server,
 * .htaccess (Apache) or a try_files rule (nginx) should route everything
 * non-existent to index.php. We don't ship those files yet — for a smoke
 * test, php -S is enough.
 */

require __DIR__ . '/therum/bootstrap.php';

use Therum\Admin;
use Therum\Auth;
use Therum\Builder;
use Therum\Install;
use Therum\Renderer;
use Therum\Router;
use Therum\Updates;

$app    = t_app();
$auth   = $app->auth;
$router = new Router();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI']    ?? '/';

// First-run gate. If no users exist yet, the install screen takes over the
// whole site — every URL routes to /install. The POST handler verifies CSRF
// before creating the first admin user.
if (!$auth->has_any_user()) {
    if ($method === 'POST' && str_starts_with($uri, '/install')) {
        $auth->require_csrf();
        echo Install::handle_post();
    } else {
        echo Install::render();
    }
    return;
}

// ── Frontend ─────────────────────────────────────────────────────────────
$router->get('/',           fn() => Renderer::home());
$router->get('/page/*',     fn(string $slug) => Renderer::show($slug));

// ── Auth ─────────────────────────────────────────────────────────────────
$router->get ('/admin/login',  fn() => Admin::login());
$router->post('/admin/login',  function () use ($auth) {
    $auth->require_csrf();
    return Admin::handle_login();
});
$router->get ('/admin/logout', function () use ($auth) {
    $auth->logout();
    header('Location: /admin/login');
    exit;
});

// ── Authenticated admin ──────────────────────────────────────────────────
// Routes below require a logged-in user. Each handler calls require_auth()
// first; we don't do route-level middleware to keep the router dumb.
$router->get('/admin', function () use ($auth) {
    $auth->require_auth();
    return Admin::dashboard();
});
$router->get('/admin/dashboard', function () use ($auth) {
    $auth->require_auth();
    return Admin::dashboard();
});

$router->get('/admin/pages', function () use ($auth) {
    $auth->require_auth();
    $flash = null;
    if (!empty($_GET['deleted'])) $flash = 'Page deleted.';
    return Builder::list_view($flash);
});

$router->get('/admin/pages/new', function () use ($auth) {
    $auth->require_auth();
    return Builder::edit_view();
});
$router->post('/admin/pages/new', function () use ($auth) {
    $auth->require_auth();
    $auth->require_csrf();
    return Builder::handle_new();
});

$router->get('/admin/pages/*/edit', function (string $slug) use ($auth) {
    $auth->require_auth();
    $existing = Therum\Pages::get($slug);
    if (!$existing) {
        http_response_code(404);
        return Admin::layout('Not found', '<h1>Page not found</h1><p><a href="/admin/pages">← Back to pages</a></p>');
    }
    return Builder::edit_view($existing);
});
$router->post('/admin/pages/*/edit', function (string $slug) use ($auth) {
    $auth->require_auth();
    $auth->require_csrf();
    return Builder::handle_update($slug);
});
$router->post('/admin/pages/*/delete', function (string $slug) use ($auth) {
    $auth->require_auth();
    $auth->require_csrf();
    return Builder::handle_delete($slug);
});

$router->get('/admin/settings', function () use ($auth) {
    $auth->require_auth();
    return Admin::settings();
});
$router->post('/admin/settings', function () use ($auth) {
    $auth->require_auth();
    $auth->require_csrf();
    return Admin::handle_settings();
});

// ── Updates ──────────────────────────────────────────────────────────────
$router->get('/admin/updates', function () use ($auth) {
    $auth->require_auth();
    return Updates::status_view();
});
$router->post('/admin/updates/check', function () use ($auth) {
    $auth->require_auth();
    $auth->require_csrf();
    return Updates::handle_check();
});
$router->post('/admin/updates/apply', function () use ($auth) {
    $auth->require_auth();
    $auth->require_csrf();
    return Updates::handle_apply();
});
$router->post('/admin/updates/upload', function () use ($auth) {
    $auth->require_auth();
    $auth->require_csrf();
    return Updates::handle_upload();
});

$router->dispatch($method, $uri);
