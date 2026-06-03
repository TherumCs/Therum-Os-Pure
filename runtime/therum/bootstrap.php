<?php
declare(strict_types=1);

/**
 * Therum OS Pure — bootstrap.
 *
 * Loaded once by index.php at the bundle root. Sets up:
 *   - Session
 *   - Class autoloader (PSR-4-ish under namespace Therum\ → therum/*.php)
 *   - Data directory (therum/data/) — creates if missing
 *   - Storage + Auth singletons made available via t_app()
 *
 * No WordPress, no Composer. The autoloader maps Therum\Storage → therum/Storage.php
 * and Therum\Foo\Bar → therum/Foo/Bar.php, so file paths mirror namespaces.
 *
 * NOTE: this file is in the global namespace on purpose. The runtime's
 * classes live under Therum\, but t_app() is a top-level helper callable
 * from both namespaced classes (\t_app()) and the unnamespaced front
 * controller (index.php). Putting it in namespace Therum\ broke index.php.
 */

// PHP ≥ 8.2 required (matches _therum/composer.json).
if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Therum OS Pure needs PHP 8.2 or newer.');
}

session_start();

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Therum\\')) return;
    $rel = str_replace('\\', '/', substr($class, strlen('Therum\\')));
    $path = __DIR__ . '/' . $rel . '.php';
    if (is_file($path)) require $path;
});

$__therum_bundle_root = dirname(__DIR__);
$__therum_data_dir    = __DIR__ . '/data';

if (!is_dir($__therum_data_dir)) {
    if (!@mkdir($__therum_data_dir, 0755, true)) {
        http_response_code(500);
        exit('Therum OS: data directory <code>' . htmlspecialchars($__therum_data_dir) . '</code> could not be created. Make the bundle directory writable by the web server.');
    }
}

if (!is_writable($__therum_data_dir)) {
    http_response_code(500);
    exit('Therum OS: data directory <code>' . htmlspecialchars($__therum_data_dir) . '</code> is not writable.');
}

// Container — singletons. Accessed via t_app() from handlers (both
// namespaced and global). Build storage first, then pass it into Auth.
$__therum_storage = new \Therum\Storage($__therum_data_dir);
$__therum_auth    = new \Therum\Auth($__therum_storage);

$GLOBALS['__therum_app'] = (object) [
    'bundle_root' => $__therum_bundle_root,
    'data_dir'    => $__therum_data_dir,
    'storage'     => $__therum_storage,
    'auth'        => $__therum_auth,
];

function t_app(): \stdClass
{
    return $GLOBALS['__therum_app'];
}
