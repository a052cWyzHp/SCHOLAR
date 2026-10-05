<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Auth;

// static helpers let pages call Session::start() without creating an object, like functions bundled up together
class Session {
    // start session
    public static function start(): void {
        session_name('scholar_session'); // separate cookie name for this application
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_save_path(__DIR__ . '/../../storage/sessions'); // private session files

        $isHttps = false;
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $isHttps = true;
        }

        session_set_cookie_params([
            'lifetime' => 0, // browser-session cookie, not a custom inactivity timeout
            'path' => '/',
            'secure' => $isHttps, // use HTTPS-only cookies when this request uses HTTPS
            'httponly' => true, // prevent JavaScript from reading the session cookie
            'samesite' => 'Lax', // limit when cookies accompany requests from other sites
        ]);
        session_start(); // restore the current session or create a new one
        header('Cache-Control: no-store'); // avoid caching protected responses
        header('X-Content-Type-Options: nosniff'); // respect the declared content type
    }

    // create CSRF token
    public static function csrfToken(): string {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // unpredictable form token
        }

        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf($token): bool {
        // no string type here: malformed forms can submit an array, which we must reject safely
        if (!is_string($token) || !isset($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token); // compare without timing-sensitive character matching
    }

    // check if session has id (meaning if user is logged in) otherwise, redirect to login page
    public static function requireAuthentication(): void {
        if (!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) {
            header('Location: login.php', true, 302);
            exit; // stop before protected HTML is rendered
        }
    }

    // removes every information inside the session, primarily used for logout button
    public static function invalidate(): void {
        $_SESSION = []; // remove the logged-in identity and form token
        $cookieSettings = session_get_cookie_params();

        // expire the browser cookie with the same path/security settings
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $cookieSettings['path'],
            'secure' => $cookieSettings['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_destroy(); // remove server-side data so the old ID cannot restore access
    }
}
