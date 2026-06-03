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

    public function __construct(Storage $storage)
    {
        $this->storage = $storage;
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
        // Regenerate session id on successful login to thwart fixation.
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
