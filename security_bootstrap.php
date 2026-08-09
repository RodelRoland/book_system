<?php

function book_system_session_lifetime_seconds(): int {
    return 60 * 60 * 24 * 30;
}

function book_system_refresh_session_cookie(): void {
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }

    $params = session_get_cookie_params();
    $lifetime = book_system_session_lifetime_seconds();
    $expiresAt = time() + $lifetime;
    $sessionName = session_name();
    $sessionId = session_id();

    if ($sessionName === '' || $sessionId === '') {
        return;
    }

    if (PHP_VERSION_ID >= 70300) {
        setcookie($sessionName, $sessionId, [
            'expires' => $expiresAt,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => !empty($params['secure']),
            'httponly' => !empty($params['httponly']),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
        return;
    }

    setcookie(
        $sessionName,
        $sessionId,
        $expiresAt,
        ($params['path'] ?? '/') . '; samesite=' . ($params['samesite'] ?? 'Lax'),
        $params['domain'] ?? '',
        !empty($params['secure']),
        !empty($params['httponly'])
    );
}

function book_system_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower(strval($_SERVER['HTTPS'])) !== 'off') {
        return true;
    }

    if (!empty($_SERVER['SERVER_PORT']) && intval($_SERVER['SERVER_PORT']) === 443) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(strval($_SERVER['HTTP_X_FORWARDED_PROTO'])) === 'https') {
        return true;
    }

    return false;
}

function book_system_send_security_headers(): void {
    static $sent = false;

    if ($sent || headers_sent()) {
        return;
    }

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header('Cross-Origin-Resource-Policy: same-origin');

    $sent = true;
}

function book_system_secure_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        book_system_send_security_headers();
        return;
    }

    if (headers_sent()) {
        @session_start();
        book_system_send_security_headers();
        return;
    }

    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_secure', book_system_is_https() ? '1' : '0');
    @ini_set('session.cookie_samesite', 'Lax');
    @ini_set('session.gc_maxlifetime', strval(book_system_session_lifetime_seconds()));

    if (function_exists('session_set_cookie_params')) {
        session_set_cookie_params([
            'lifetime' => book_system_session_lifetime_seconds(),
            'path' => '/',
            'domain' => '',
            'secure' => book_system_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    @session_start();
    book_system_refresh_session_cookie();
    book_system_send_security_headers();
}

function book_system_secure_session_regenerate(bool $deleteOldSession = true): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    @session_regenerate_id($deleteOldSession);
    book_system_refresh_session_cookie();
}
