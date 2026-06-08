<?php
declare(strict_types=1);

namespace Therum;

/**
 * Session-based auth. Users live in storage as a flat list keyed by email;
 * each record: { email, password_hash, name, created_at }.
 *
 * Login writes the email into $_SESSION['therum_user']. Logout clears it.
 * require_auth() redirects to /admin/login when the session is missing.
 *
 * Single-user expected for v1, but the schema is plural so we don't have to
 * migrate when a second admin gets added.
 */
final class Auth
{
    private Storage $storage;
    private const SESSION_KEY = 'therum_user';
    private const CSRF_KEY    = 'therum_csrf';
    public  const CSRF_FIELD  = '_csrf';

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
    }

    /**
     * Per-session CSRF token. Generated lazily on first read, persisted in
     * the session, regenerated on login/logout. All state-changing POST
     * handlers MUST verify the token via require_csrf().
     */
    public function csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    /** Verify token submitted with a POST. Returns false on mismatch. */
    public function verify_csrf(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $expected = $_SESSION[self::CSRF_KEY] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') return false;
        return hash_equals($expected, $token);
    }

    /** Abort the request with a 403 if CSRF token is missing or wrong. */
    public function require_csrf(): void
    {
        $tok = $_POST[self::CSRF_FIELD] ?? '';
        if (!is_string($tok) || !$this->verify_csrf($tok)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 — invalid or missing CSRF token.";
            exit;
        }
    }

    /** Render a hidden CSRF input. Use this in every state-changing form. */
    public function csrf_field(): string
    {
        return '<input type="hidden" name="' . self::CSRF_FIELD . '" value="' . htmlspecialchars($this->csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public function has_any_user(): bool
    {
        $users = $this->storage->get('users', []);
        return is_array($users) && count($users) > 0;
    }

    public function create_user(string $email, string $password, string $name = ''): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        if (strlen($password) < 8) return false;
        $users = (array) $this->storage->get('users', []);
        $users[$email] = [
            'email'         => $email,
            'name'          => $name ?: $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at'    => time(),
        ];
        $this->storage->set('users', $users);
        return true;
    }

    public function login(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $users = (array) $this->storage->get('users', []);
        if (!isset($users[$email])) return false;
        if (!password_verify($password, $users[$email]['password_hash'] ?? '')) return false;
        // Defense in depth: explicitly start the session before regenerating
        // the id so a fresh request that never started one still gets a clean
        // session id post-auth (prevents session fixation regardless of where
        // we are in the request lifecycle).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::SESSION_KEY] = $email;
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function current_user(): ?array
    {
        $email = $_SESSION[self::SESSION_KEY] ?? null;
        if (!$email) return null;
        $users = (array) $this->storage->get('users', []);
        return $users[$email] ?? null;
    }

    public function require_auth(): void
    {
        if (!$this->current_user()) {
            header('Location: /admin/login');
            exit;
        }
    }
}
