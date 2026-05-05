<?php

require_once __DIR__ . '/security_bootstrap.php';
book_system_send_security_headers();

mysqli_report(MYSQLI_REPORT_OFF);

$db_user = getenv('BOOK_SYSTEM_DB_USER');
$db_user = is_string($db_user) && $db_user !== '' ? $db_user : 'root';

$db_pass = getenv('BOOK_SYSTEM_DB_PASS');
$db_pass = is_string($db_pass) ? $db_pass : '';

$db_name = getenv('BOOK_SYSTEM_DB_NAME');
$db_name = is_string($db_name) && $db_name !== '' ? $db_name : 'book_distribution_system';

$db_host = getenv('BOOK_SYSTEM_DB_HOST');
$db_host = is_string($db_host) && $db_host !== '' ? $db_host : null;

$db_port = getenv('BOOK_SYSTEM_DB_PORT');
$db_port = is_numeric($db_port) ? intval($db_port) : null;

$conn = null;
$last_error = '';
$connection_errors = [];

function book_db_build_attempts(?string $preferred_host, ?int $preferred_port): array {
    $attempts = [];

    if ($preferred_host !== null && $preferred_port !== null) {
        $attempts[] = ['host' => $preferred_host, 'port' => $preferred_port, 'label' => 'configured host'];
    } elseif ($preferred_host !== null) {
        $attempts[] = ['host' => $preferred_host, 'port' => 3306, 'label' => 'configured host'];
    } elseif ($preferred_port !== null) {
        $attempts[] = ['host' => '127.0.0.1', 'port' => $preferred_port, 'label' => 'configured port'];
    }

    $attempts = array_merge($attempts, [
        ['host' => '127.0.0.1', 'port' => 3306, 'label' => 'XAMPP MariaDB TCP'],
        ['host' => 'localhost', 'port' => 3306, 'label' => 'XAMPP MariaDB localhost'],
        ['host' => '127.0.0.1', 'port' => 3307, 'label' => 'alternate TCP'],
        ['host' => 'localhost', 'port' => 3307, 'label' => 'alternate localhost'],
    ]);

    $seen = [];
    $unique = [];
    foreach ($attempts as $attempt) {
        $key = strtolower($attempt['host']) . ':' . strval($attempt['port']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $attempt;
    }

    return $unique;
}

function book_db_connect(string $host, int $port, string $user, string $pass, string $db_name, ?string &$error = null): ?mysqli {
    $link = @mysqli_connect($host, $user, $pass, $db_name, $port);
    if (!$link) {
        $error = mysqli_connect_error() ?: 'connection failed';
        return null;
    }

    @mysqli_set_charset($link, 'utf8mb4');
    return $link;
}

function book_db_connection_hint(string $last_error): string {
    $error = strtolower($last_error);

    if (strpos($error, 'not allowed to connect') !== false || strpos($error, 'access denied') !== false) {
        return 'MariaDB is running, but the configured database user is not allowed to connect from this machine. Repair the MariaDB user grants or update the database credentials.';
    }

    if (strpos($error, "can't connect") !== false || strpos($error, 'connection refused') !== false) {
        return 'MariaDB does not appear to be accepting TCP connections on the configured host and port. Start MariaDB in XAMPP and confirm the port.';
    }

    return 'Check the configured host, port, username, password, and database name.';
}

function book_db_pick_best_error(array $errors, string $fallback): string {
    foreach ($errors as $error) {
        $value = strtolower($error);
        if (strpos($value, 'not allowed to connect') !== false || strpos($value, 'access denied') !== false) {
            return $error;
        }
    }

    foreach ($errors as $error) {
        $value = strtolower($error);
        if (strpos($value, "can't connect") !== false || strpos($value, 'connection refused') !== false) {
            return $error;
        }
    }

    return $fallback;
}

foreach (book_db_build_attempts($db_host, $db_port) as $attempt) {
    try {
        $direct_error = '';
        $conn = book_db_connect($attempt['host'], intval($attempt['port']), $db_user, $db_pass, $db_name, $direct_error);
        if ($conn) {
            break;
        }

        $temp_conn = @mysqli_connect($attempt['host'], $db_user, $db_pass, null, intval($attempt['port']));
        if ($temp_conn) {
            $escaped_db_name = str_replace('`', '``', $db_name);
            @mysqli_query($temp_conn, "CREATE DATABASE IF NOT EXISTS `{$escaped_db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            mysqli_close($temp_conn);

            $temp_error = '';
            $conn = book_db_connect($attempt['host'], intval($attempt['port']), $db_user, $db_pass, $db_name, $temp_error);
            if ($conn) {
                break;
            }

            if ($temp_error !== '') {
                $direct_error = $temp_error;
            }
        } else {
            $temp_error = mysqli_connect_error() ?: 'connection failed';
            $direct_error = $temp_error !== '' ? $temp_error : $direct_error;
        }

        $last_error = ($attempt['label'] ?? ($attempt['host'] . ':' . $attempt['port'])) . ': ' . $direct_error;
        $connection_errors[] = $last_error;
    } catch (Throwable $e) {
        $last_error = $e->getMessage();
        $connection_errors[] = ($attempt['label'] ?? ($attempt['host'] . ':' . $attempt['port'])) . ': ' . $last_error;
    }
}

function csrf_get_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        book_system_secure_session_start();
    }

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 32) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $_SESSION['csrf_token'] = bin2hex((string) microtime(true) . (string) mt_rand());
        }
    }

    return $_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function book_system_client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $parts = explode(',', $candidate);
        $ip = trim(strval($parts[0] ?? ''));
        if ($ip !== '') {
            return $ip;
        }
    }

    return 'unknown';
}

function book_system_security_debug_enabled(): bool {
    $debug = getenv('BOOK_SYSTEM_DEBUG');
    return is_string($debug) && filter_var($debug, FILTER_VALIDATE_BOOL);
}

function book_system_ensure_login_attempts_table(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        attempt_id INT AUTO_INCREMENT PRIMARY KEY,
        login_scope VARCHAR(20) NOT NULL,
        identifier CHAR(64) NOT NULL,
        attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_login_attempts_scope_identifier_time (login_scope, identifier, attempted_at),
        INDEX idx_login_attempts_attempted_at (attempted_at)
    )");
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    $ensured = true;
}

function book_system_login_attempt_identifier(string $username): string {
    return hash('sha256', strtolower(trim($username)) . '|' . book_system_client_ip());
}

function book_system_is_login_rate_limited(mysqli $conn, string $scope, string $username, int $limit = 5, int $windowMinutes = 15): bool {
    if ($username === '') {
        return false;
    }

    book_system_ensure_login_attempts_table($conn);
    $identifier = book_system_login_attempt_identifier($username);

    $stmt = $conn->prepare("SELECT COUNT(*) AS attempt_count
        FROM login_attempts
        WHERE login_scope = ?
          AND identifier = ?
          AND attempted_at >= (NOW() - INTERVAL ? MINUTE)");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssi', $scope, $identifier, $windowMinutes);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = ($res && $res->num_rows === 1) ? intval($res->fetch_assoc()['attempt_count'] ?? 0) : 0;
    $stmt->close();

    return $count >= $limit;
}

function book_system_record_login_attempt(mysqli $conn, string $scope, string $username, bool $success): void {
    if ($username === '') {
        return;
    }

    book_system_ensure_login_attempts_table($conn);
    $identifier = book_system_login_attempt_identifier($username);

    if ($success) {
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE login_scope = ? AND identifier = ?");
        if ($stmt) {
            $stmt->bind_param('ss', $scope, $identifier);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }

    $stmt = $conn->prepare("INSERT INTO login_attempts (login_scope, identifier) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param('ss', $scope, $identifier);
        $stmt->execute();
        $stmt->close();
    }
}

if (!$conn) {
    $last_error = book_db_pick_best_error($connection_errors, $last_error);
    $hint = book_db_connection_hint($last_error);
    error_log("Book System DB connection failed: {$last_error}");
    if (book_system_security_debug_enabled()) {
        die("Database connection failed. {$hint} Last error: {$last_error}");
    }
    die("Database connection failed. Please contact the system administrator.");
}

if (file_exists(__DIR__ . '/app_helpers.php')) {
    require_once __DIR__ . '/app_helpers.php';
    if (function_exists('book_system_ensure_balance_carry_forward_table')) {
        book_system_ensure_balance_carry_forward_table($conn);
    }
}

if (file_exists(__DIR__ . '/cache_helper.php')) {
    require_once __DIR__ . '/cache_helper.php';
}

$ACTIVE_SEMESTER_ID = function_exists('book_system_get_active_semester_id')
    ? book_system_get_active_semester_id($conn)
    : 0;
$ACTIVE_SEMESTER_NAME = function_exists('book_system_get_active_semester_name')
    ? book_system_get_active_semester_name($conn)
    : '';
$ACTIVE_SEMESTER_START_DATE = function_exists('book_system_get_active_semester_start_date')
    ? book_system_get_active_semester_start_date($conn)
    : '';
$ACTIVE_SEMESTER_LABEL = function_exists('book_system_build_semester_label')
    ? book_system_build_semester_label($ACTIVE_SEMESTER_NAME, $ACTIVE_SEMESTER_START_DATE)
    : $ACTIVE_SEMESTER_NAME;

if (function_exists('book_system_enforce_rep_subscription_access')) {
    book_system_enforce_rep_subscription_access($conn);
}
