<?php
declare(strict_types=1);
namespace HMD;

/** Admin website sessions, CSRF, login throttling. */
final class Auth
{
    private static ?array $user = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('HMDSESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $idle = 60 * 60 * 8;
        if (isset($_SESSION['last']) && time() - $_SESSION['last'] > $idle) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['last'] = time();
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        $u = Db::one('SELECT u.user_id, u.name, u.email, u.role_id, r.name AS role_name, r.permissions FROM hm_user u JOIN hm_role r ON r.role_id=u.role_id WHERE u.user_id=? AND u.status=1 AND u.deleted_at IS NULL', [$id]);
        if (!$u) {
            return null;
        }
        $u['perms'] = json_decode($u['permissions'], true) ?: [];
        return self::$user = $u;
    }

    public static function setUser(?array $u): void
    {
        self::$user = $u;
    }

    /** Returns null on success or an error message. */
    public static function login(string $email, string $password): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        if (!RateLimit::hit('login:' . $ip, 20, 900)) {
            return 'Too many login attempts from this network. Try again in 15 minutes.';
        }
        $u = Db::one('SELECT * FROM hm_user WHERE email=? AND deleted_at IS NULL', [strtolower(trim($email))]);
        $generic = 'Email or password is incorrect.';
        if (!$u) {
            password_verify($password, '$2y$10$abcdefghijklmnopqrstuuJ7sbI1lZ0xVv1y8f5pD7wq5C0g6Z0a'); // equalise timing
            return $generic;
        }
        if ((int)$u['status'] !== 1) {
            return 'This account is disabled. Ask your administrator.';
        }
        if ($u['locked_until'] && strtotime($u['locked_until'] . ' UTC') > time()) {
            return 'Account locked after too many wrong passwords. Try again after 15 minutes.';
        }
        if (!password_verify($password, $u['password_hash'])) {
            $f = (int)$u['failed_logins'] + 1;
            Db::update('hm_user', ['failed_logins' => $f, 'locked_until' => $f >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null], 'user_id=?', [$u['user_id']]);
            return $generic;
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('hm_user', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'user_id=?', [$u['user_id']]);
        }
        Db::update('hm_user', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => Fmt::nowUtc()], 'user_id=?', [$u['user_id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['user_id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        self::$user = null;
        Audit::log('login', 'user', $u['user_id'], null, null, ['type' => 'admin', 'id' => $u['user_id'], 'name' => $u['name']]);
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $t): bool
    {
        return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
    }
}
