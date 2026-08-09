<?php

/**
 * Apply every due catalog price without modifying historical request prices.
 */
function book_system_apply_scheduled_book_prices(mysqli $conn): int {
    $tableResult = $conn->query("SHOW TABLES LIKE 'book_price_history'");
    if (!$tableResult || $tableResult->num_rows === 0) {
        return 0;
    }

    $appliedCount = 0;
    $conn->begin_transaction();

    try {
        $due = $conn->query("SELECT history_id, book_id, new_price
            FROM book_price_history
            WHERE applied_at IS NULL
              AND effective_date IS NOT NULL
              AND effective_date <= CURDATE()
            ORDER BY effective_date ASC, history_id ASC
            FOR UPDATE");
        if (!$due) {
            throw new RuntimeException('Could not load scheduled book prices.');
        }

        $updateBook = $conn->prepare('UPDATE books SET price = ? WHERE book_id = ?');
        $markApplied = $conn->prepare('UPDATE book_price_history SET applied_at = NOW() WHERE history_id = ? AND applied_at IS NULL');
        if (!$updateBook || !$markApplied) {
            throw new RuntimeException('Could not prepare scheduled price activation.');
        }

        while ($row = $due->fetch_assoc()) {
            $historyId = intval($row['history_id']);
            $bookId = intval($row['book_id']);
            $newPrice = round(floatval($row['new_price']), 2);

            $updateBook->bind_param('di', $newPrice, $bookId);
            if (!$updateBook->execute()) {
                throw new RuntimeException('Could not activate a scheduled book price.');
            }

            $markApplied->bind_param('i', $historyId);
            if (!$markApplied->execute()) {
                throw new RuntimeException('Could not mark a scheduled book price as applied.');
            }
            $appliedCount++;
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Scheduled book price activation failed: ' . $e->getMessage());
        return 0;
    }

    if ($appliedCount > 0 && function_exists('clear_books_cache')) {
        clear_books_cache();
    }

    return $appliedCount;
}

function book_system_normalize_index_number(string $value): string {
    $normalized = strtoupper(trim($value));
    $normalized = preg_replace('/[^A-Z0-9]+/', '', $normalized);
    return is_string($normalized) ? $normalized : '';
}

function book_system_normalized_index_sql(string $column): string {
    return "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM($column)), '/', ''), ' ', ''), '-', ''), '.', '')";
}

function book_system_portal_lookup_cache_version(mysqli $conn): int {
    static $versionCache = null;
    if ($versionCache !== null) {
        return $versionCache;
    }

    $stmt = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'portal_lookup_cache_version' LIMIT 1");
    if (!$stmt) {
        $versionCache = 1;
        return $versionCache;
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $value = ($res && $res->num_rows === 1) ? intval($res->fetch_assoc()['meta_value'] ?? 1) : 1;
    $stmt->close();

    $versionCache = max(1, $value);
    return $versionCache;
}

function book_system_bump_portal_lookup_cache_version(mysqli $conn): void {
    static $alreadyBumped = false;
    $currentVersion = book_system_portal_lookup_cache_version($conn);
    $nextVersion = $currentVersion + 1;

    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value)
        VALUES ('portal_lookup_cache_version', ?)
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    if ($stmt) {
        $value = strval($nextVersion);
        $stmt->bind_param('s', $value);
        $stmt->execute();
        $stmt->close();
    }

    if (function_exists('cache_clear')) {
        cache_clear('public_portal_reps_mobile_v1');
    }
    if (function_exists('cache_clear_all') && !$alreadyBumped) {
        cache_clear_all();
    }

    $alreadyBumped = true;
}

function book_system_submission_gc(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $now = time();
    $ttlSeconds = 900;

    foreach (['submission_tokens', 'recent_submissions'] as $bucket) {
        if (!isset($_SESSION[$bucket]) || !is_array($_SESSION[$bucket])) {
            $_SESSION[$bucket] = [];
            continue;
        }

        foreach ($_SESSION[$bucket] as $scope => $entries) {
            if (!is_array($entries)) {
                unset($_SESSION[$bucket][$scope]);
                continue;
            }

            foreach ($entries as $key => $entry) {
                $createdAt = 0;
                if (is_array($entry)) {
                    $createdAt = intval($entry['created_at'] ?? 0);
                } else {
                    $createdAt = intval($entry);
                }

                if ($createdAt <= 0 || ($now - $createdAt) > $ttlSeconds) {
                    unset($_SESSION[$bucket][$scope][$key]);
                }
            }

            if (empty($_SESSION[$bucket][$scope])) {
                unset($_SESSION[$bucket][$scope]);
            }
        }
    }
}

function book_system_issue_submission_token(string $scope): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        book_system_secure_session_start();
    }

    book_system_submission_gc();

    try {
        $token = bin2hex(random_bytes(24));
    } catch (Throwable $e) {
        $token = hash('sha256', $scope . '|' . microtime(true) . '|' . mt_rand());
    }

    if (!isset($_SESSION['submission_tokens']) || !is_array($_SESSION['submission_tokens'])) {
        $_SESSION['submission_tokens'] = [];
    }
    if (!isset($_SESSION['submission_tokens'][$scope]) || !is_array($_SESSION['submission_tokens'][$scope])) {
        $_SESSION['submission_tokens'][$scope] = [];
    }

    $_SESSION['submission_tokens'][$scope][$token] = time();
    return $token;
}

function book_system_submission_token_exists(string $scope, ?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE || !is_string($token) || trim($token) === '') {
        return false;
    }

    book_system_submission_gc();

    return (
        isset($_SESSION['submission_tokens'][$scope]) &&
        is_array($_SESSION['submission_tokens'][$scope]) &&
        array_key_exists($token, $_SESSION['submission_tokens'][$scope])
    );
}

function book_system_consume_submission_token(string $scope, ?string $token): bool {
    if (!book_system_submission_token_exists($scope, $token)) {
        return false;
    }

    unset($_SESSION['submission_tokens'][$scope][$token]);
    return true;
}

function book_system_remember_submission_result(string $scope, string $fingerprint, array $result): void {
    if (session_status() !== PHP_SESSION_ACTIVE || $fingerprint === '') {
        return;
    }

    book_system_submission_gc();

    if (!isset($_SESSION['recent_submissions']) || !is_array($_SESSION['recent_submissions'])) {
        $_SESSION['recent_submissions'] = [];
    }
    if (!isset($_SESSION['recent_submissions'][$scope]) || !is_array($_SESSION['recent_submissions'][$scope])) {
        $_SESSION['recent_submissions'][$scope] = [];
    }

    $result['created_at'] = time();
    $_SESSION['recent_submissions'][$scope][$fingerprint] = $result;
}

function book_system_get_recent_submission_result(string $scope, string $fingerprint, int $ttlSeconds = 300): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE || $fingerprint === '') {
        return null;
    }

    book_system_submission_gc();

    $entry = $_SESSION['recent_submissions'][$scope][$fingerprint] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $createdAt = intval($entry['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > $ttlSeconds) {
        unset($_SESSION['recent_submissions'][$scope][$fingerprint]);
        return null;
    }

    return $entry;
}

function book_system_forget_submission_result(string $scope, string $fingerprint): void {
    if (session_status() !== PHP_SESSION_ACTIVE || trim($fingerprint) === '') {
        return;
    }

    if (isset($_SESSION['recent_submissions'][$scope]) && is_array($_SESSION['recent_submissions'][$scope])) {
        unset($_SESSION['recent_submissions'][$scope][$fingerprint]);
    }
}

function book_system_submission_shared_cache_dir(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'submission_guard';
}

function book_system_submission_shared_paths(string $scope, string $fingerprint): array {
    $safeScope = preg_replace('/[^a-z0-9_\-]+/i', '_', trim($scope));
    $safeFingerprint = preg_replace('/[^a-f0-9]+/i', '', strtolower(trim($fingerprint)));
    if ($safeScope === '') {
        $safeScope = 'default';
    }
    if ($safeFingerprint === '') {
        $safeFingerprint = hash('sha256', $scope . '|' . $fingerprint);
    }

    $baseDir = book_system_submission_shared_cache_dir();
    $baseName = $safeScope . '_' . $safeFingerprint;

    return [
        'dir' => $baseDir,
        'lock' => $baseDir . DIRECTORY_SEPARATOR . $baseName . '.lock',
        'result' => $baseDir . DIRECTORY_SEPARATOR . $baseName . '.json',
    ];
}

function book_system_get_shared_submission_result(string $scope, string $fingerprint, int $ttlSeconds = 300): ?array {
    if ($fingerprint === '') {
        return null;
    }

    $paths = book_system_submission_shared_paths($scope, $fingerprint);
    $resultPath = $paths['result'];
    if (!is_file($resultPath)) {
        return null;
    }

    $raw = @file_get_contents($resultPath);
    if (!is_string($raw) || trim($raw) === '') {
        @unlink($resultPath);
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        @unlink($resultPath);
        return null;
    }

    $createdAt = intval($decoded['created_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > max(30, $ttlSeconds)) {
        @unlink($resultPath);
        return null;
    }

    return $decoded;
}

function book_system_remember_shared_submission_result(string $scope, string $fingerprint, array $result): void {
    if ($fingerprint === '') {
        return;
    }

    $paths = book_system_submission_shared_paths($scope, $fingerprint);
    if (!is_dir($paths['dir'])) {
        @mkdir($paths['dir'], 0775, true);
    }

    $result['created_at'] = time();
    @file_put_contents($paths['result'], json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function book_system_acquire_submission_processing_lock(string $scope, string $fingerprint, int $ttlSeconds = 180): bool {
    if ($fingerprint === '') {
        return false;
    }

    $paths = book_system_submission_shared_paths($scope, $fingerprint);
    if (!is_dir($paths['dir'])) {
        @mkdir($paths['dir'], 0775, true);
    }

    $lockPath = $paths['lock'];
    if (is_file($lockPath)) {
        $raw = @file_get_contents($lockPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $createdAt = is_array($decoded) ? intval($decoded['created_at'] ?? 0) : 0;
        if ($createdAt > 0 && (time() - $createdAt) <= max(30, $ttlSeconds)) {
            return false;
        }
        @unlink($lockPath);
    }

    $payload = [
        'created_at' => time(),
        'scope' => $scope,
        'fingerprint' => $fingerprint,
    ];

    return @file_put_contents($lockPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function book_system_release_submission_processing_lock(string $scope, string $fingerprint): void {
    if ($fingerprint === '') {
        return;
    }

    $paths = book_system_submission_shared_paths($scope, $fingerprint);
    if (is_file($paths['lock'])) {
        @unlink($paths['lock']);
    }
}

function book_system_build_refresh_token(array $state): string {
    return sha1(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function book_system_maybe_output_refresh_status(string $token): void {
    if (strval($_GET['refresh'] ?? '') !== 'status') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode([
        'success' => true,
        'state_token' => $token,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function book_system_render_refresh_polling_script(string $token, array $options = []): void {
    if ($token === '') {
        return;
    }

    $config = [
        'interval_ms' => max(5000, intval($options['interval_ms'] ?? 15000)),
        'min_gap_ms' => max(3000, intval($options['min_gap_ms'] ?? 10000)),
        'pause_selectors' => array_values(array_filter(array_map('strval', $options['pause_selectors'] ?? []))),
    ];

    $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tokenJson = json_encode($token, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    echo <<<HTML
<script>
(function () {
    var currentStateToken = {$tokenJson};
    var refreshConfig = {$configJson} || {};
    var refreshUrl = new URL(window.location.href);
    refreshUrl.searchParams.set('refresh', 'status');
    refreshUrl.hash = '';

    var refreshInFlight = false;
    var lastRefreshAt = 0;

    function isPaused() {
        if (document.visibilityState !== 'visible') {
            return true;
        }

        var selectors = Array.isArray(refreshConfig.pause_selectors) ? refreshConfig.pause_selectors : [];
        return selectors.some(function (selector) {
            try {
                return !!document.querySelector(selector);
            } catch (error) {
                return false;
            }
        });
    }

    function checkForWorkspaceChanges() {
        var now = Date.now();
        if (isPaused()) {
            return;
        }
        if (refreshInFlight || (now - lastRefreshAt) < (refreshConfig.min_gap_ms || 10000)) {
            return;
        }

        refreshInFlight = true;
        lastRefreshAt = now;
        refreshUrl.searchParams.set('_rt', String(now));

        fetch(refreshUrl.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            cache: 'no-store'
        })
        .then(function (response) { return response.text(); })
        .then(function (rawText) {
            var payload = JSON.parse(String(rawText || '').replace(/^\uFEFF/, ''));
            if (payload && payload.success && payload.state_token && payload.state_token !== currentStateToken) {
                window.location.reload();
            }
        })
        .catch(function () {})
        .finally(function () {
            refreshInFlight = false;
        });
    }

    window.setInterval(checkForWorkspaceChanges, refreshConfig.interval_ms || 15000);
    window.addEventListener('focus', checkForWorkspaceChanges);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            checkForWorkspaceChanges();
        }
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            checkForWorkspaceChanges();
        }
    });
})();
</script>
HTML;
}

function book_system_paystack_checkout_ttl(): int {
    return 1800;
}

function book_system_paystack_checkout_gc(int $ttlSeconds = 1800): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $ttlSeconds = max(60, $ttlSeconds);
    $now = time();
    $checkoutBucket = $_SESSION['paystack_checkouts'] ?? [];
    if (!is_array($checkoutBucket)) {
        $_SESSION['paystack_checkouts'] = [];
        $checkoutBucket = [];
    }

    foreach ($checkoutBucket as $token => $entry) {
        if (!is_array($entry)) {
            unset($_SESSION['paystack_checkouts'][$token]);
            continue;
        }

        $createdAt = intval($entry['created_at'] ?? 0);
        if ($createdAt <= 0 || ($now - $createdAt) > $ttlSeconds) {
            unset($_SESSION['paystack_checkouts'][$token]);
        }
    }

    $fingerprintMap = $_SESSION['paystack_checkout_fingerprints'] ?? [];
    if (!is_array($fingerprintMap)) {
        $_SESSION['paystack_checkout_fingerprints'] = [];
        return;
    }

    foreach ($fingerprintMap as $fingerprint => $token) {
        if (!isset($_SESSION['paystack_checkouts'][strval($token)])) {
            unset($_SESSION['paystack_checkout_fingerprints'][$fingerprint]);
        }
    }
}

function book_system_store_paystack_checkout(array $payload, int $ttlSeconds = 1800): ?string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    book_system_paystack_checkout_gc($ttlSeconds);

    try {
        $token = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $token = md5((string) microtime(true) . '|' . mt_rand());
    }

    $payload['created_at'] = time();
    $payload['checkout_token'] = $token;

    if (!isset($_SESSION['paystack_checkouts']) || !is_array($_SESSION['paystack_checkouts'])) {
        $_SESSION['paystack_checkouts'] = [];
    }

    $_SESSION['paystack_checkouts'][$token] = $payload;

    $fingerprint = trim(strval($payload['fingerprint'] ?? ''));
    if ($fingerprint !== '') {
        if (!isset($_SESSION['paystack_checkout_fingerprints']) || !is_array($_SESSION['paystack_checkout_fingerprints'])) {
            $_SESSION['paystack_checkout_fingerprints'] = [];
        }
        $_SESSION['paystack_checkout_fingerprints'][$fingerprint] = $token;
    }

    return $token;
}

function book_system_get_paystack_checkout(string $token, int $ttlSeconds = 1800): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE || trim($token) === '') {
        return null;
    }

    book_system_paystack_checkout_gc($ttlSeconds);

    $entry = $_SESSION['paystack_checkouts'][$token] ?? null;
    return is_array($entry) ? $entry : null;
}

function book_system_find_paystack_checkout_by_fingerprint(string $fingerprint, int $ttlSeconds = 1800): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE || trim($fingerprint) === '') {
        return null;
    }

    book_system_paystack_checkout_gc($ttlSeconds);

    $token = $_SESSION['paystack_checkout_fingerprints'][$fingerprint] ?? null;
    if (!is_string($token) || trim($token) === '') {
        return null;
    }

    return book_system_get_paystack_checkout($token, $ttlSeconds);
}

function book_system_update_paystack_checkout(string $token, array $updates, int $ttlSeconds = 1800): bool {
    $token = trim($token);
    if ($token === '') {
        return false;
    }

    $entry = book_system_get_paystack_checkout($token, $ttlSeconds);
    if (!is_array($entry)) {
        return false;
    }

    $fingerprintBefore = trim(strval($entry['fingerprint'] ?? ''));
    $updatedEntry = array_merge($entry, $updates);
    $updatedEntry['checkout_token'] = $token;

    $_SESSION['paystack_checkouts'][$token] = $updatedEntry;

    $fingerprintAfter = trim(strval($updatedEntry['fingerprint'] ?? ''));
    if ($fingerprintBefore !== '' && $fingerprintBefore !== $fingerprintAfter) {
        unset($_SESSION['paystack_checkout_fingerprints'][$fingerprintBefore]);
    }
    if ($fingerprintAfter !== '') {
        $_SESSION['paystack_checkout_fingerprints'][$fingerprintAfter] = $token;
    }

    return true;
}

function book_system_remove_paystack_checkout(string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $token = trim($token);
    if ($token === '') {
        return;
    }

    $entry = $_SESSION['paystack_checkouts'][$token] ?? null;
    if (is_array($entry)) {
        $fingerprint = trim(strval($entry['fingerprint'] ?? ''));
        if ($fingerprint !== '') {
            unset($_SESSION['paystack_checkout_fingerprints'][$fingerprint]);
        }
    }

    unset($_SESSION['paystack_checkouts'][$token]);
}

function book_system_normalize_payment_method(?string $value): string {
    $value = strtolower(trim(strval($value ?? '')));
    return $value === 'paystack' ? 'paystack' : 'manual_momo';
}

function book_system_normalize_momo_network(?string $value): string {
    $value = trim(strval($value ?? ''));
    if ($value === '') {
        return '';
    }

    $normalized = strtolower($value);
    $map = [
        'mtn' => 'MTN',
        'mtn momo' => 'MTN',
        'telecel' => 'Telecel',
        'vodafone' => 'Telecel',
        'airteltigo' => 'AirtelTigo',
        'airteltigo' => 'AirtelTigo',
        'airtel tigo' => 'AirtelTigo',
    ];

    return $map[$normalized] ?? $value;
}

function book_system_get_request_manual_reference(string $indexNumber, int $requestId): string {
    $cleanIndex = preg_replace('/\s+/', '', trim($indexNumber));
    $cleanIndex = is_string($cleanIndex) ? $cleanIndex : trim($indexNumber);
    if ($cleanIndex === '') {
        $cleanIndex = 'STUDENT';
    }
    return $cleanIndex . '-REQ' . max(0, $requestId);
}

function book_system_paystack_fee_rate(mysqli $conn): float {
    return 0.0195;
}

function book_system_round_money_up(float $amount): float {
    return ceil(max(0, $amount) * 100) / 100;
}

function book_system_paystack_gross_up_amount(float $intendedAmount, float $feeRate): array {
    $intendedAmount = round(max(0, $intendedAmount), 2);
    $feeRate = max(0, min($feeRate, 0.99));

    if ($intendedAmount <= 0 || $feeRate <= 0) {
        return [
            'intended_amount' => $intendedAmount,
            'charge_amount' => $intendedAmount,
            'processing_fee' => 0.0,
            'expected_settlement' => $intendedAmount,
        ];
    }

    $rawChargeAmount = $intendedAmount / (1 - $feeRate);
    $chargeAmount = book_system_round_money_up($rawChargeAmount);
    $gatewayFee = round($chargeAmount * $feeRate, 2);
    $expectedSettlement = round(max(0, $chargeAmount - $gatewayFee), 2);

    while ($expectedSettlement + 0.00001 < $intendedAmount) {
        $chargeAmount = round($chargeAmount + 0.01, 2);
        $gatewayFee = round($chargeAmount * $feeRate, 2);
        $expectedSettlement = round(max(0, $chargeAmount - $gatewayFee), 2);
    }

    $processingFee = round(max(0, $chargeAmount - $expectedSettlement), 2);

    return [
        'intended_amount' => $intendedAmount,
        'charge_amount' => $chargeAmount,
        'processing_fee' => $processingFee,
        'expected_settlement' => $expectedSettlement,
    ];
}

function book_system_app_timezone(): DateTimeZone {
    static $timezone = null;
    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Africa/Accra');
    }
    return $timezone;
}

function book_system_sync_connection_timezone(mysqli $conn): void {
    static $syncedConnections = [];
    $connectionKey = spl_object_id($conn);
    if (isset($syncedConnections[$connectionKey])) {
        return;
    }

    $syncedConnections[$connectionKey] = true;
    try {
        $conn->query("SET time_zone = '+00:00'");
    } catch (Throwable $e) {
    }
}

function book_system_format_datetime_local(?string $value, string $format = 'M j, Y g:i A'): string {
    $value = trim(strval($value ?? ''));
    if ($value === '') {
        return '';
    }

    try {
        $timezone = book_system_app_timezone();
        $dateTime = new DateTime($value, $timezone);
        $dateTime->setTimezone($timezone);
        return $dateTime->format($format);
    } catch (Throwable $e) {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }
        return date($format, $timestamp);
    }
}

function book_system_get_admin_payment_settings(mysqli $conn, int $adminId, bool $includeSecrets = false): array {
    $settings = [
        'admin_id' => $adminId,
        'admin_role' => '',
        'payment_method' => 'manual_momo',
        'effective_method' => 'manual_momo',
        'momo_number' => '',
        'momo_account_name' => '',
        'account_name' => '',
        'momo_network' => '',
        'has_manual_details' => false,
        'paystack_enabled' => false,
        'paystack_public_key' => '',
        'paystack_secret_key' => '',
        'has_paystack_keys' => false,
        'paystack_currency' => 'GHS',
        'fallback_warning' => '',
        'status_message' => '',
    ];

    if ($adminId > 0) {
        $stmt = $conn->prepare("SELECT role, payment_method, momo_number, account_name, momo_network, paystack_enabled, paystack_public_key, paystack_secret_key
            FROM admins
            WHERE admin_id = ?
            LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();

            if (!empty($row)) {
                $settings['admin_role'] = trim(strval($row['role'] ?? ''));
                $settings['payment_method'] = book_system_normalize_payment_method($row['payment_method'] ?? 'manual_momo');
                $settings['momo_number'] = trim(strval($row['momo_number'] ?? ''));
                $settings['momo_account_name'] = trim(strval($row['account_name'] ?? ''));
                $settings['account_name'] = $settings['momo_account_name'];
                $settings['momo_network'] = book_system_normalize_momo_network($row['momo_network'] ?? '');
                $settings['paystack_enabled'] = intval($row['paystack_enabled'] ?? 0) === 1;
                $settings['paystack_public_key'] = trim(strval($row['paystack_public_key'] ?? ''));
                if ($includeSecrets) {
                    $settings['paystack_secret_key'] = trim(strval($row['paystack_secret_key'] ?? ''));
                }
            }
        }
    }

    if ($adminId <= 0 && $settings['paystack_public_key'] === '') {
        $settings['paystack_public_key'] = trim(strval(getenv('BOOK_SYSTEM_PAYSTACK_PUBLIC_KEY') ?: ''));
    }
    if ($adminId <= 0 && $includeSecrets && $settings['paystack_secret_key'] === '') {
        $settings['paystack_secret_key'] = trim(strval(getenv('BOOK_SYSTEM_PAYSTACK_SECRET_KEY') ?: ''));
    }

    if (function_exists('book_system_get_app_meta')) {
        if ($adminId <= 0 && $settings['paystack_public_key'] === '') {
            $settings['paystack_public_key'] = trim(book_system_get_app_meta($conn, 'paystack_public_key', ''));
        }
        if ($adminId <= 0 && $includeSecrets && $settings['paystack_secret_key'] === '') {
            $settings['paystack_secret_key'] = trim(book_system_get_app_meta($conn, 'paystack_secret_key', ''));
        }
        $currency = trim(book_system_get_app_meta($conn, 'paystack_currency', 'GHS'));
        if ($currency !== '') {
            $settings['paystack_currency'] = strtoupper($currency);
        }
    }

    $settings['has_manual_details'] = ($settings['momo_number'] !== '' && $settings['momo_account_name'] !== '');
    $settings['has_paystack_keys'] = ($settings['paystack_public_key'] !== '' && (!$includeSecrets || $settings['paystack_secret_key'] !== ''));

    if ($settings['payment_method'] === 'paystack') {
        if ($settings['paystack_enabled'] && $settings['has_paystack_keys']) {
            $settings['effective_method'] = 'paystack';
        } elseif ($settings['has_manual_details']) {
            $settings['effective_method'] = 'manual_momo';
            $settings['fallback_warning'] = 'This rep is set to Paystack, but the Paystack keys are incomplete. Please use the manual MoMo details below.';
        } else {
            $settings['effective_method'] = 'unavailable';
            $settings['fallback_warning'] = 'Payment details are not available. Please contact your class rep.';
        }
    } elseif ($settings['has_manual_details']) {
        $settings['effective_method'] = 'manual_momo';
    } else {
        $settings['effective_method'] = 'unavailable';
        $settings['fallback_warning'] = 'Payment details are not available. Please contact your class rep.';
    }

    return $settings;
}

function book_system_get_request_payment_breakdown(array $requestRow, array $paymentSettings, mysqli $conn): array {
    $subtotal = round(floatval($requestRow['total_amount'] ?? 0), 2);
    $creditUsed = round(floatval($requestRow['credit_used'] ?? 0), 2);
    $amountPaid = round(floatval($requestRow['amount_paid'] ?? 0), 2);
    $paymentMath = book_system_calculate_request_payment_math($subtotal, $creditUsed, $amountPaid);
    $requestBalance = round(floatval($paymentMath['due_after_credit'] ?? 0), 2);
    $balanceDue = round(floatval($paymentMath['outstanding_balance'] ?? 0), 2);
    $handlingCharge = round($balanceDue * 0.01, 2);
    $intendedAmount = round($balanceDue + $handlingCharge, 2);
    $paystackFee = 0.0;
    $processingFee = 0.0;
    $totalPayable = $intendedAmount;
    $expectedSettlement = $intendedAmount;
    $effectiveMethod = strval($paymentSettings['effective_method'] ?? 'manual_momo');
    if ($effectiveMethod === 'paystack' && $intendedAmount > 0) {
        $grossedUp = book_system_paystack_gross_up_amount($intendedAmount, book_system_paystack_fee_rate($conn));
        $paystackFee = round(floatval($grossedUp['processing_fee'] ?? 0), 2);
        $processingFee = $paystackFee;
        $totalPayable = round(floatval($grossedUp['charge_amount'] ?? $intendedAmount), 2);
        $expectedSettlement = round(floatval($grossedUp['expected_settlement'] ?? $intendedAmount), 2);
    }
    $totalPayable = round($totalPayable, 2);
    $effectiveStatus = strval($paymentMath['payment_status'] ?? 'unpaid');
    $isPaid = ($effectiveStatus === 'paid');

    return [
        'subtotal' => $subtotal,
        'credit_used' => $creditUsed,
        'amount_paid' => $amountPaid,
        'request_balance' => $requestBalance,
        'balance_due' => $balanceDue,
        'outstanding_balance' => $balanceDue,
        'handling_charge' => $handlingCharge,
        'intended_amount' => $intendedAmount,
        'paystack_fee' => $paystackFee,
        'processing_fee' => $processingFee,
        'total_payable' => $totalPayable,
        'expected_settlement' => $expectedSettlement,
        'is_paid' => $isPaid,
        'payment_status' => $effectiveStatus,
        'has_credit_cover' => ($requestBalance <= 0),
        'effective_method' => $effectiveMethod,
    ];
}

function book_system_calculate_request_payment_math(float $totalAmount, float $creditUsed, float $amountPaid): array {
    $totalAmount = round(max(0, $totalAmount), 2);
    $creditUsed = round(max(0, $creditUsed), 2);
    $amountPaid = round(max(0, $amountPaid), 2);

    $dueAfterCredit = round(max(0, $totalAmount - $creditUsed), 2);
    $outstandingBalance = round(max(0, $dueAfterCredit - $amountPaid), 2);
    $cashOverpaid = round(max(0, $amountPaid - $dueAfterCredit), 2);

    if ($dueAfterCredit <= 0.009) {
        $paymentStatus = 'paid';
    } elseif ($amountPaid <= 0.009) {
        $paymentStatus = 'unpaid';
    } elseif ($outstandingBalance <= 0.009) {
        $paymentStatus = 'paid';
    } else {
        $paymentStatus = 'partial';
    }

    return [
        'total_amount' => $totalAmount,
        'credit_used' => $creditUsed,
        'amount_paid' => $amountPaid,
        'due_after_credit' => $dueAfterCredit,
        'outstanding_balance' => $outstandingBalance,
        'cash_overpaid' => $cashOverpaid,
        'payment_status' => $paymentStatus,
        'is_paid' => ($paymentStatus === 'paid'),
    ];
}

function book_system_request_payment_status_from_row(array $row): string {
    $math = book_system_calculate_request_payment_math(
        floatval($row['total_amount'] ?? 0),
        floatval($row['credit_used'] ?? 0),
        floatval($row['amount_paid'] ?? 0)
    );

    return strval($math['payment_status'] ?? 'unpaid');
}

function book_system_get_dashboard_metrics(mysqli $conn, int $semesterId, ?int $adminId): array {
    $semesterId = max(0, $semesterId);
    $adminId = $adminId === null ? 0 : max(0, $adminId);

    $empty = [
        'cash_collected' => 0.0,
        'paid_revenue' => 0.0,
        'credit_used' => 0.0,
        'lecturer_paid' => 0.0,
        'refundable_cash' => 0.0,
        'available_balance' => 0.0,
        'unpaid_requests' => 0,
        'collected_items' => 0,
        'pending_items' => 0,
        'low_stock_books' => 0,
        'overdue_requests' => 0,
    ];

    if ($semesterId <= 0) {
        return $empty;
    }

    $cacheKey = 'dashboard_metrics_v2_' . $semesterId . '_' . $adminId;
    $loader = static function () use ($conn, $semesterId, $adminId, $empty): array {
        $metrics = $empty;

        $requestTotalsSql = "SELECT
                COALESCE(SUM(COALESCE(amount_paid, 0)), 0) AS cash_collected,
                COALESCE(SUM(COALESCE(credit_used, 0)), 0) AS credit_used,
                COALESCE(SUM(GREATEST(COALESCE(total_amount, 0) - COALESCE(credit_used, 0) - COALESCE(amount_paid, 0), 0)), 0) AS outstanding_balance,
                COALESCE(SUM(CASE
                    WHEN GREATEST(COALESCE(total_amount, 0) - COALESCE(credit_used, 0) - COALESCE(amount_paid, 0), 0) > 0.009
                    THEN 1 ELSE 0
                END), 0) AS pending_requests
            FROM requests
            WHERE semester_id = ?";
        if ($adminId > 0) {
            $requestTotalsSql .= " AND admin_id = ?";
        }
        $requestTotalsStmt = $conn->prepare($requestTotalsSql);
        if ($requestTotalsStmt) {
            if ($adminId > 0) {
                $requestTotalsStmt->bind_param('ii', $semesterId, $adminId);
            } else {
                $requestTotalsStmt->bind_param('i', $semesterId);
            }
            $requestTotalsStmt->execute();
            $requestTotalsResult = $requestTotalsStmt->get_result();
            $requestTotals = $requestTotalsResult ? $requestTotalsResult->fetch_assoc() : null;
            $requestTotalsStmt->close();

            if (is_array($requestTotals)) {
                $metrics['cash_collected'] = round(floatval($requestTotals['cash_collected'] ?? 0), 2);
                $metrics['paid_revenue'] = $metrics['cash_collected'];
                $metrics['credit_used'] = round(floatval($requestTotals['credit_used'] ?? 0), 2);
                $metrics['unpaid_requests'] = intval($requestTotals['pending_requests'] ?? 0);
                $metrics['overdue_requests'] = $metrics['unpaid_requests'];
            }
        }

        $lecturerPaidSql = "SELECT COALESCE(SUM(COALESCE(amount_paid, 0)), 0) AS lecturer_paid
            FROM lecturer_payments
            WHERE semester_id = ?";
        if ($adminId > 0) {
            $lecturerPaidSql .= " AND admin_id = ?";
        }
        $lecturerPaidStmt = $conn->prepare($lecturerPaidSql);
        if ($lecturerPaidStmt) {
            if ($adminId > 0) {
                $lecturerPaidStmt->bind_param('ii', $semesterId, $adminId);
            } else {
                $lecturerPaidStmt->bind_param('i', $semesterId);
            }
            $lecturerPaidStmt->execute();
            $lecturerPaidResult = $lecturerPaidStmt->get_result();
            $lecturerPaidRow = $lecturerPaidResult ? $lecturerPaidResult->fetch_assoc() : null;
            $lecturerPaidStmt->close();
            if (is_array($lecturerPaidRow)) {
                $metrics['lecturer_paid'] = round(floatval($lecturerPaidRow['lecturer_paid'] ?? 0), 2);
            }
        }

        $itemTotalsSql = "SELECT
                COALESCE(SUM(CASE WHEN COALESCE(ri.is_cancelled, 0) = 0 AND COALESCE(ri.is_collected, 0) = 1 THEN 1 ELSE 0 END), 0) AS collected_items,
                COALESCE(SUM(CASE WHEN COALESCE(ri.is_cancelled, 0) = 0 AND COALESCE(ri.is_collected, 0) = 0 THEN 1 ELSE 0 END), 0) AS pending_items
            FROM request_items ri
            INNER JOIN requests r ON r.request_id = ri.request_id
            WHERE r.semester_id = ?";
        if ($adminId > 0) {
            $itemTotalsSql .= " AND r.admin_id = ?";
        }
        $itemTotalsStmt = $conn->prepare($itemTotalsSql);
        if ($itemTotalsStmt) {
            if ($adminId > 0) {
                $itemTotalsStmt->bind_param('ii', $semesterId, $adminId);
            } else {
                $itemTotalsStmt->bind_param('i', $semesterId);
            }
            $itemTotalsStmt->execute();
            $itemTotalsResult = $itemTotalsStmt->get_result();
            $itemTotals = $itemTotalsResult ? $itemTotalsResult->fetch_assoc() : null;
            $itemTotalsStmt->close();

            if (is_array($itemTotals)) {
                $metrics['collected_items'] = intval($itemTotals['collected_items'] ?? 0);
                $metrics['pending_items'] = intval($itemTotals['pending_items'] ?? 0);
            }
        }

        $lowStockSql = "SELECT COUNT(*) AS total
            FROM books
            WHERE semester_id = ?
              AND LOWER(TRIM(COALESCE(availability, 'available'))) <> 'available'";
        if ($adminId > 0) {
            $lowStockSql .= " AND admin_id = ?";
        }
        $lowStockStmt = $conn->prepare($lowStockSql);
        if ($lowStockStmt) {
            if ($adminId > 0) {
                $lowStockStmt->bind_param('ii', $semesterId, $adminId);
            } else {
                $lowStockStmt->bind_param('i', $semesterId);
            }
            $lowStockStmt->execute();
            $lowStockResult = $lowStockStmt->get_result();
            $lowStockRow = $lowStockResult ? $lowStockResult->fetch_assoc() : null;
            $lowStockStmt->close();
            if (is_array($lowStockRow)) {
                $metrics['low_stock_books'] = intval($lowStockRow['total'] ?? 0);
            }
        }

        $metrics['available_balance'] = round($metrics['cash_collected'] - $metrics['lecturer_paid'], 2);
        $metrics['refundable_cash'] = 0.0;

        return $metrics;
    };

    if (function_exists('cache_get')) {
        $cached = cache_get($cacheKey, 120, $loader);
        return is_array($cached) ? array_merge($empty, $cached) : $empty;
    }

    return $loader();
}

function book_system_get_paystack_config(mysqli $conn, int $adminId = 0): array {
    $paymentSettings = $adminId > 0
        ? book_system_get_admin_payment_settings($conn, $adminId, true)
        : [
            'paystack_public_key' => trim(strval(getenv('BOOK_SYSTEM_PAYSTACK_PUBLIC_KEY') ?: '')),
            'paystack_secret_key' => trim(strval(getenv('BOOK_SYSTEM_PAYSTACK_SECRET_KEY') ?: '')),
            'paystack_currency' => trim(strval(getenv('BOOK_SYSTEM_PAYSTACK_CURRENCY') ?: 'GHS')),
        ];

    if ($adminId <= 0 && function_exists('book_system_get_app_meta')) {
        if (($paymentSettings['paystack_public_key'] ?? '') === '') {
            $paymentSettings['paystack_public_key'] = trim(book_system_get_app_meta($conn, 'paystack_public_key', ''));
        }
        if (($paymentSettings['paystack_secret_key'] ?? '') === '') {
            $paymentSettings['paystack_secret_key'] = trim(book_system_get_app_meta($conn, 'paystack_secret_key', ''));
        }
        $currency = trim(book_system_get_app_meta($conn, 'paystack_currency', 'GHS'));
        if ($currency !== '') {
            $paymentSettings['paystack_currency'] = $currency;
        }
    }

    return [
        'enabled' => trim(strval($paymentSettings['paystack_public_key'] ?? '')) !== '' && trim(strval($paymentSettings['paystack_secret_key'] ?? '')) !== '',
        'public_key' => trim(strval($paymentSettings['paystack_public_key'] ?? '')),
        'secret_key' => trim(strval($paymentSettings['paystack_secret_key'] ?? '')),
        'currency' => strtoupper(trim(strval($paymentSettings['paystack_currency'] ?? 'GHS'))) ?: 'GHS',
    ];
}

function book_system_paystack_request(mysqli $conn, string $method, string $endpoint, array $payload = [], int $adminId = 0): array {
    $config = book_system_get_paystack_config($conn, $adminId);
    if (empty($config['enabled'])) {
        return ['success' => false, 'message' => 'Paystack is not configured for this rep.'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'cURL is not available on this server.'];
    }

    $url = 'https://api.paystack.co/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    if (!$ch) {
        return ['success' => false, 'message' => 'Could not start a Paystack request.'];
    }

    $headers = [
        'Authorization: Bearer ' . $config['secret_key'],
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    if (!empty($payload)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);

    if ($raw === false || $curlError !== '') {
        error_log('ClassBookHub Paystack request error: ' . $curlError . ' endpoint=' . $endpoint . ' admin_id=' . $adminId);
        return ['success' => false, 'message' => 'Unable to reach Paystack right now.'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('ClassBookHub Paystack invalid response endpoint=' . $endpoint . ' http=' . $httpCode . ' body=' . substr($raw, 0, 500));
        return ['success' => false, 'message' => 'Paystack returned an invalid response.'];
    }

    if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['status'])) {
        $message = trim(strval($decoded['message'] ?? 'Unable to complete the Paystack request.'));
        error_log('ClassBookHub Paystack API failure endpoint=' . $endpoint . ' http=' . $httpCode . ' message=' . $message . ' admin_id=' . $adminId);
        return ['success' => false, 'message' => $message, 'data' => $decoded['data'] ?? null];
    }

    return ['success' => true, 'message' => strval($decoded['message'] ?? ''), 'data' => $decoded['data'] ?? []];
}

function book_system_paystack_initialize_transaction(mysqli $conn, array $payload, int $adminId = 0): array {
    return book_system_paystack_request($conn, 'POST', 'transaction/initialize', $payload, $adminId);
}

function book_system_paystack_verify_transaction(mysqli $conn, string $reference, int $adminId = 0): array {
    $reference = rawurlencode(trim($reference));
    if ($reference === '') {
        return ['success' => false, 'message' => 'Missing Paystack reference.'];
    }
    return book_system_paystack_request($conn, 'GET', 'transaction/verify/' . $reference, [], $adminId);
}

function book_system_generate_paystack_reference(int $requestId): string {
    try {
        $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } catch (Throwable $e) {
        $suffix = strtoupper(substr(md5((string) microtime(true)), 0, 8));
    }
    return 'CBH-REQ' . max(0, $requestId) . '-' . $suffix;
}

function book_system_generate_paystack_checkout_reference(string $checkoutToken = ''): string {
    $checkoutToken = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($checkoutToken)), 0, 8));
    if ($checkoutToken === '') {
        try {
            $checkoutToken = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        } catch (Throwable $e) {
            $checkoutToken = strtoupper(substr(md5((string) microtime(true)), 0, 8));
        }
    }

    return 'CBH-CHK-' . $checkoutToken;
}

function book_system_build_request_payment_email(string $indexNumber, string $fullName = ''): string {
    $indexNumber = strtolower(trim($indexNumber));
    $indexNumber = preg_replace('/[^a-z0-9]+/', '', $indexNumber);
    $indexNumber = is_string($indexNumber) ? $indexNumber : 'student';
    if ($indexNumber === '') {
        $indexNumber = 'student';
    }
    return 'cbh+' . $indexNumber . '@example.com';
}

function book_system_get_active_semester_id(mysqli $conn): int {
    $details = book_system_get_active_semester_details($conn);
    return intval($details['semester_id'] ?? 0);
}

function book_system_get_active_semester_details(mysqli $conn): array {
    static $detailsCache = null;
    if (is_array($detailsCache)) {
        return $detailsCache;
    }

    $default = [
        'semester_id' => 0,
        'semester_name' => '',
        'semester_start_date' => '',
    ];

    $res = $conn->query("SELECT semester_id, semester_name, semester_start_date FROM semesters WHERE is_active = 1 ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc() ?: [];
        $detailsCache = [
            'semester_id' => intval($row['semester_id'] ?? 0),
            'semester_name' => strval($row['semester_name'] ?? ''),
            'semester_start_date' => strval($row['semester_start_date'] ?? ''),
        ];
        return $detailsCache;
    }

    $res = $conn->query("SELECT semester_id, semester_name, semester_start_date FROM semesters ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc() ?: [];
        $detailsCache = [
            'semester_id' => intval($row['semester_id'] ?? 0),
            'semester_name' => strval($row['semester_name'] ?? ''),
            'semester_start_date' => strval($row['semester_start_date'] ?? ''),
        ];
        return $detailsCache;
    }

    $detailsCache = $default;
    return $detailsCache;
}

function book_system_get_active_semester_name(mysqli $conn): string {
    $details = book_system_get_active_semester_details($conn);
    return strval($details['semester_name'] ?? '');
}

function book_system_get_active_semester_start_date(mysqli $conn): string {
    $details = book_system_get_active_semester_details($conn);
    return strval($details['semester_start_date'] ?? '');
}

function book_system_format_semester_start_date(?string $startDate): string {
    $startDate = trim(strval($startDate ?? ''));
    if ($startDate === '') {
        return '';
    }

    $timestamp = strtotime($startDate);
    if ($timestamp === false) {
        return '';
    }

    return date('M d, Y', $timestamp);
}

function book_system_build_semester_label(string $semesterName, ?string $startDate = null): string {
    $semesterName = trim($semesterName);
    if ($semesterName === '') {
        return '';
    }

    $formattedDate = book_system_format_semester_start_date($startDate);
    if ($formattedDate === '') {
        return $semesterName;
    }

    return $semesterName . ' • Starts ' . $formattedDate;
}

function book_system_get_app_meta(mysqli $conn, string $key, string $default = ''): string {
    $stmt = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $value = ($res && $res->num_rows === 1) ? strval($res->fetch_assoc()['meta_value'] ?? $default) : $default;
    $stmt->close();
    return $value;
}

function book_system_set_app_meta(mysqli $conn, string $key, string $value): bool {
    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function book_system_get_access_mode_config(mysqli $conn): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $rawMode = trim(book_system_get_app_meta($conn, 'system_access_mode', 'premium_active'));
    $startDate = trim(book_system_get_app_meta($conn, 'premium_start_date', ''));
    $notice = trim(book_system_get_app_meta($conn, 'premium_notice_message', ''));

    $validModes = ['free', 'premium_scheduled', 'premium_active'];
    if (!in_array($rawMode, $validModes, true)) {
        $rawMode = 'premium_active';
    }

    $effectiveMode = $rawMode;
    if ($rawMode === 'premium_scheduled' && $startDate !== '') {
        $today = date('Y-m-d');
        if ($today >= $startDate) {
            $effectiveMode = 'premium_active';
        }
    }

    $cache = [
        'raw_mode' => $rawMode,
        'effective_mode' => $effectiveMode,
        'premium_start_date' => $startDate,
        'premium_notice_message' => $notice,
    ];
    return $cache;
}

function book_system_rep_subscription_enforcement_enabled(mysqli $conn): bool {
    $config = book_system_get_access_mode_config($conn);
    return strval($config['effective_mode'] ?? 'premium_active') === 'premium_active';
}

function book_system_rep_trials_enabled(mysqli $conn): bool {
    return book_system_rep_subscription_enforcement_enabled($conn);
}

function book_system_get_rep_rollout_notice(mysqli $conn): array {
    $config = book_system_get_access_mode_config($conn);
    if (strval($config['raw_mode'] ?? '') !== 'premium_scheduled') {
        return ['show' => false];
    }

    $startDate = trim(strval($config['premium_start_date'] ?? ''));
    if ($startDate === '') {
        return ['show' => false];
    }

    $todayTs = strtotime(date('Y-m-d'));
    $startTs = strtotime($startDate);
    if ($todayTs === false || $startTs === false || $todayTs >= $startTs) {
        return ['show' => false];
    }

    $daysLeft = max(0, (int) ceil(($startTs - $todayTs) / 86400));
    $formattedDate = book_system_format_semester_start_date($startDate);
    $customMessage = trim(strval($config['premium_notice_message'] ?? ''));
    $defaultMessage = 'Free access will end on ' . $formattedDate . '. After that date, subscription will be required to continue using your rep workspace.';

    return [
        'show' => true,
        'days_left' => $daysLeft,
        'start_date' => $startDate,
        'formatted_start_date' => $formattedDate,
        'message' => $customMessage !== '' ? $customMessage : $defaultMessage,
    ];
}

function book_system_admin_trial_columns_available(mysqli $conn): bool {
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }

    $checked = true;
    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    $dbName = ($dbRes && $dbRes->num_rows === 1) ? strval($dbRes->fetch_assoc()['db_name'] ?? '') : '';
    if ($dbName === '') {
        return false;
    }

    $required = [
        'trial_started_at',
        'trial_expires_at',
        'subscription_active',
        'subscription_started_at',
        'subscription_expires_at',
    ];

    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $types = str_repeat('s', count($required) + 1);
    $stmt = $conn->prepare("SELECT COUNT(*) AS c
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = 'admins'
          AND COLUMN_NAME IN ($placeholders)");
    if (!$stmt) {
        return false;
    }

    $params = array_merge([$dbName], $required);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = ($res && $res->num_rows === 1) ? intval($res->fetch_assoc()['c'] ?? 0) : 0;
    $stmt->close();

    $available = ($count === count($required));
    return $available;
}

function book_system_trial_expiry_from_start(string $startAt): string {
    $startAt = trim($startAt);
    if ($startAt === '') {
        return '';
    }

    try {
        $timezone = new DateTimeZone('Africa/Accra');
        $start = new DateTime($startAt, $timezone);
        $start->setTime(0, 0, 0);
        $start->modify('+7 days');
        return $start->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return '';
    }
}

function book_system_build_rep_access_status_from_row(array $adminRow): array {
    $timezone = new DateTimeZone('Africa/Accra');
    $now = new DateTime('now', $timezone);

    $role = strval($adminRow['role'] ?? '');
    $approvedAt = trim(strval($adminRow['approved_at'] ?? ''));
    $createdAt = trim(strval($adminRow['created_at'] ?? ''));
    $trialStartedAt = trim(strval($adminRow['trial_started_at'] ?? ''));
    $trialExpiresAt = trim(strval($adminRow['trial_expires_at'] ?? ''));
    $subscriptionActive = intval($adminRow['subscription_active'] ?? 0) === 1;
    $subscriptionStartedAt = trim(strval($adminRow['subscription_started_at'] ?? ''));
    $subscriptionExpiresAt = trim(strval($adminRow['subscription_expires_at'] ?? ''));

    if ($role !== 'rep') {
        return [
            'role' => $role,
            'is_trial_active' => false,
            'is_trial_expiring_soon' => false,
            'is_subscription_active' => true,
            'is_expired' => false,
            'can_access' => true,
            'status_key' => 'unrestricted',
            'trial_started_at' => $trialStartedAt,
            'trial_expires_at' => $trialExpiresAt,
            'subscription_started_at' => $subscriptionStartedAt,
            'subscription_expires_at' => $subscriptionExpiresAt,
            'seconds_left' => null,
            'days_left' => null,
            'reminder_message' => '',
        ];
    }

    if ($trialStartedAt === '') {
        $trialStartedAt = $approvedAt !== '' ? $approvedAt : $createdAt;
    }
    if ($trialExpiresAt === '' && $trialStartedAt !== '') {
        $trialExpiresAt = book_system_trial_expiry_from_start($trialStartedAt);
    }

    $subscriptionStillValid = false;
    if ($subscriptionActive) {
        if ($subscriptionExpiresAt === '') {
            $subscriptionStillValid = true;
        } else {
            try {
                $subscriptionExpiryDate = new DateTime($subscriptionExpiresAt, $timezone);
                $subscriptionStillValid = ($subscriptionExpiryDate > $now);
            } catch (Throwable $e) {
                $subscriptionStillValid = false;
            }
        }
    }

    $secondsLeft = null;
    $daysLeft = null;
    $isExpired = false;
    $isTrialActive = false;
    $isTrialExpiringSoon = false;
    $statusKey = 'trial_unknown';
    $reminderMessage = '';

    if ($subscriptionStillValid) {
        $statusKey = 'subscribed';
    } elseif ($trialExpiresAt !== '') {
        try {
            $trialExpiryDate = new DateTime($trialExpiresAt, $timezone);
            $secondsLeft = $trialExpiryDate->getTimestamp() - $now->getTimestamp();
            $daysLeft = max(0, (int) ceil($secondsLeft / 86400));
            $isExpired = ($secondsLeft <= 0);
            $isTrialActive = !$isExpired;
            $isTrialExpiringSoon = ($secondsLeft > 0 && $secondsLeft <= 172800);
            $statusKey = $isExpired ? 'trial_expired' : ($isTrialExpiringSoon ? 'trial_expiring' : 'trial_active');

            if ($isTrialExpiringSoon && $daysLeft !== null) {
                $reminderMessage = $daysLeft <= 1
                    ? 'Your free trial will expire at 12:00 AM tomorrow.'
                    : 'Your free trial will expire in ' . $daysLeft . ' days.';
            } elseif ($isExpired) {
                $reminderMessage = 'Your 7-day free trial has expired. Please subscribe to continue using your rep workspace.';
            }
        } catch (Throwable $e) {
            $statusKey = 'trial_unknown';
        }
    }

    return [
        'role' => $role,
        'is_trial_active' => $isTrialActive,
        'is_trial_expiring_soon' => $isTrialExpiringSoon,
        'is_subscription_active' => $subscriptionStillValid,
        'is_expired' => $isExpired,
        'can_access' => ($subscriptionStillValid || $isTrialActive),
        'status_key' => $statusKey,
        'trial_started_at' => $trialStartedAt,
        'trial_expires_at' => $trialExpiresAt,
        'subscription_started_at' => $subscriptionStartedAt,
        'subscription_expires_at' => $subscriptionExpiresAt,
        'seconds_left' => $secondsLeft,
        'days_left' => $daysLeft,
        'reminder_message' => $reminderMessage,
    ];
}

function book_system_build_free_mode_rep_access_status(array $adminRow): array {
    $role = strval($adminRow['role'] ?? '');
    $trialStartedAt = trim(strval($adminRow['trial_started_at'] ?? ''));
    $trialExpiresAt = trim(strval($adminRow['trial_expires_at'] ?? ''));
    $subscriptionStartedAt = trim(strval($adminRow['subscription_started_at'] ?? ''));
    $subscriptionExpiresAt = trim(strval($adminRow['subscription_expires_at'] ?? ''));

    if ($role !== 'rep') {
        return book_system_build_rep_access_status_from_row($adminRow);
    }

    return [
        'role' => $role,
        'is_trial_active' => false,
        'is_trial_expiring_soon' => false,
        'is_subscription_active' => false,
        'is_expired' => false,
        'can_access' => true,
        'status_key' => 'free_mode',
        'trial_started_at' => $trialStartedAt,
        'trial_expires_at' => $trialExpiresAt,
        'subscription_started_at' => $subscriptionStartedAt,
        'subscription_expires_at' => $subscriptionExpiresAt,
        'seconds_left' => null,
        'days_left' => null,
        'reminder_message' => '',
    ];
}

function book_system_start_rep_trial_if_needed(mysqli $conn, int $adminId): void {
    if ($adminId <= 0 || !book_system_rep_trials_enabled($conn) || !book_system_admin_trial_columns_available($conn)) {
        return;
    }

    $stmt = $conn->prepare("SELECT role, trial_started_at, trial_expires_at, subscription_active FROM admins WHERE admin_id = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row) || strval($row['role'] ?? '') !== 'rep' || intval($row['subscription_active'] ?? 0) === 1) {
        return;
    }

    $trialStartedAt = trim(strval($row['trial_started_at'] ?? ''));
    $trialExpiresAt = trim(strval($row['trial_expires_at'] ?? ''));
    if ($trialStartedAt !== '' || $trialExpiresAt !== '') {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $trialExpiry = book_system_trial_expiry_from_start($now);
    if ($trialExpiry === '') {
        return;
    }

    $update = $conn->prepare("UPDATE admins SET trial_started_at = ?, trial_expires_at = ? WHERE admin_id = ? AND role = 'rep' AND (trial_started_at IS NULL OR trial_started_at = '') AND (trial_expires_at IS NULL OR trial_expires_at = '') LIMIT 1");
    if (!$update) {
        return;
    }
    $update->bind_param('ssi', $now, $trialExpiry, $adminId);
    $update->execute();
    $update->close();
}

function book_system_reset_all_rep_trials(mysqli $conn): bool {
    if (!book_system_admin_trial_columns_available($conn)) {
        return true;
    }
    return (bool) $conn->query("UPDATE admins SET trial_started_at = NULL, trial_expires_at = NULL WHERE role = 'rep'");
}

function book_system_get_rep_access_status(mysqli $conn, int $adminId): array {
    if ($adminId <= 0) {
        return book_system_build_rep_access_status_from_row([]);
    }

    if (book_system_rep_trials_enabled($conn)) {
        book_system_start_rep_trial_if_needed($conn, $adminId);
    }

    $columnsAvailable = book_system_admin_trial_columns_available($conn);
    $extraColumns = $columnsAvailable
        ? ", trial_started_at, trial_expires_at, subscription_active, subscription_started_at, subscription_expires_at"
        : '';

    $stmt = $conn->prepare("SELECT admin_id, role, approved_at, created_at{$extraColumns} FROM admins WHERE admin_id = ? LIMIT 1");
    if (!$stmt) {
        return book_system_build_rep_access_status_from_row([]);
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : [];
    $stmt->close();

    if (!book_system_rep_trials_enabled($conn)) {
        return book_system_build_free_mode_rep_access_status(is_array($row) ? $row : []);
    }

    return book_system_build_rep_access_status_from_row(is_array($row) ? $row : []);
}

function book_system_temp_admin_permissions_catalog(): array {
    return [
        'rep_workspace_access' => 'Rep workspace access',
        'semester_management' => 'Semester management',
        'manage_reps' => 'Manage reps',
        'manage_rep_signups' => 'Rep signup approvals',
        'manage_lecturers' => 'Manage lecturers',
        'manage_ads' => 'Manage portal ads',
        'system_setup' => 'System setup',
        'maintenance' => 'Maintenance',
    ];
}

function book_system_decode_temp_admin_permissions(?string $encoded): array {
    $encoded = trim(strval($encoded ?? ''));
    if ($encoded === '') {
        return [];
    }

    $decoded = json_decode($encoded, true);
    if (!is_array($decoded)) {
        return [];
    }

    $catalog = book_system_temp_admin_permissions_catalog();
    $allowed = array_keys($catalog);
    $normalized = [];
    foreach ($decoded as $permission) {
        $permission = trim(strval($permission));
        if ($permission !== '' && in_array($permission, $allowed, true)) {
            $normalized[] = $permission;
        }
    }

    return array_values(array_unique($normalized));
}

function book_system_encode_temp_admin_permissions(array $permissions): string {
    return json_encode(book_system_decode_temp_admin_permissions(json_encode($permissions)));
}

function book_system_temp_admin_has_rep_workspace_access(array $permissions): bool {
    return in_array('rep_workspace_access', $permissions, true);
}

function book_system_temp_admin_expired(?string $expiresAt): bool {
    $expiresAt = trim(strval($expiresAt ?? ''));
    if ($expiresAt === '') {
        return true;
    }

    try {
        $timezone = new DateTimeZone('Africa/Accra');
        $expiry = new DateTime($expiresAt, $timezone);
        $now = new DateTime('now', $timezone);
        return $expiry <= $now;
    } catch (Throwable $e) {
        return true;
    }
}

function book_system_get_temporary_admin_status(mysqli $conn, int $adminId): array {
    $default = [
        'can_access' => false,
        'is_active' => false,
        'is_expired' => true,
        'expires_at' => '',
        'permissions' => [],
        'full_name' => '',
        'username' => '',
    ];

    if ($adminId <= 0) {
        return $default;
    }

    $stmt = $conn->prepare("SELECT admin_id, username, full_name, role, is_active, temp_admin_permissions, temp_admin_expires_at
        FROM admins
        WHERE admin_id = ?
        LIMIT 1");
    if (!$stmt) {
        return $default;
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || strval($row['role'] ?? '') !== 'temporary_admin') {
        return $default;
    }

    $isActive = intval($row['is_active'] ?? 0) === 1;
    $expiresAt = trim(strval($row['temp_admin_expires_at'] ?? ''));
    $isExpired = book_system_temp_admin_expired($expiresAt);
    $permissions = book_system_decode_temp_admin_permissions(strval($row['temp_admin_permissions'] ?? ''));

    return [
        'can_access' => ($isActive && !$isExpired && !empty($permissions)),
        'is_active' => $isActive,
        'is_expired' => $isExpired,
        'expires_at' => $expiresAt,
        'permissions' => $permissions,
        'full_name' => trim(strval($row['full_name'] ?? '')),
        'username' => trim(strval($row['username'] ?? '')),
    ];
}

function book_system_enforce_temporary_admin_access(mysqli $conn): void {
    if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'temporary_admin') {
        return;
    }

    $adminId = intval($_SESSION['admin_id'] ?? 0);
    $status = book_system_get_temporary_admin_status($conn, $adminId);
    if (empty($status['can_access'])) {
        session_destroy();
        header('Location: login.php?msg=temp_admin_expired');
        exit;
    }

    $_SESSION['admin_full_name'] = $status['full_name'] !== '' ? $status['full_name'] : ($_SESSION['admin_full_name'] ?? '');
    $_SESSION['temp_admin_permissions'] = $status['permissions'];
    $_SESSION['temp_admin_expires_at'] = $status['expires_at'];

    $currentScript = basename(strval($_SERVER['SCRIPT_NAME'] ?? ''));
    $hasRepWorkspaceAccess = book_system_temp_admin_has_rep_workspace_access($status['permissions'] ?? []);
    if ($hasRepWorkspaceAccess) {
        $adminOnlyScripts = [
            'admin.php',
            'super_admin_home.php',
            'manage_reps.php',
            'manage_rep_signups.php',
            'manage_temp_admins.php',
            'manage_access_mode.php',
            'manage_lecturers.php',
            'manage_departments.php',
            'manage_ads.php',
            'admin_setup.php',
            'maintenance.php',
            'generate_access_code.php',
            'view_rep_data.php',
            'manage_assistants.php',
        ];
        if (in_array($currentScript, $adminOnlyScripts, true)) {
            header('Location: rep_dashboard.php');
            exit;
        }
    }

    if ($hasRepWorkspaceAccess && trim(strval($status['full_name'] ?? '')) === '') {
        $allowlist = [
            'login.php',
            'logout.php',
            'my_profile.php',
            'change_admin_password.php',
            'forgot_password.php',
        ];
        if (!in_array($currentScript, $allowlist, true)) {
            header('Location: my_profile.php?setup=1');
            exit;
        }
    }
}

function book_system_get_assistant_rep_workspace_context(mysqli $conn, int $assistantAdminId): ?array {
    if ($assistantAdminId <= 0) {
        return null;
    }

    $status = book_system_get_temporary_admin_status($conn, $assistantAdminId);
    if (empty($status['can_access']) || !book_system_temp_admin_has_rep_workspace_access($status['permissions'] ?? [])) {
        return null;
    }

    $assistantStmt = $conn->prepare("SELECT admin_id, username, full_name, profile_photo_path, recovery_email, delegated_by_admin_id, is_active
        FROM admins
        WHERE admin_id = ? AND role = 'temporary_admin'
        LIMIT 1");
    if (!$assistantStmt) {
        return null;
    }
    $assistantStmt->bind_param('i', $assistantAdminId);
    $assistantStmt->execute();
    $assistantRes = $assistantStmt->get_result();
    $assistantRow = ($assistantRes && $assistantRes->num_rows === 1) ? $assistantRes->fetch_assoc() : null;
    $assistantStmt->close();

    if (!$assistantRow || intval($assistantRow['is_active'] ?? 0) !== 1) {
        return null;
    }

    $repAdminId = intval($assistantRow['delegated_by_admin_id'] ?? 0);
    if ($repAdminId <= 0) {
        return null;
    }

    $ownerStmt = $conn->prepare("SELECT admin_id, role, is_active
        FROM admins
        WHERE admin_id = ?
        LIMIT 1");
    if (!$ownerStmt) {
        return null;
    }
    $ownerStmt->bind_param('i', $repAdminId);
    $ownerStmt->execute();
    $ownerRes = $ownerStmt->get_result();
    $ownerRow = ($ownerRes && $ownerRes->num_rows === 1) ? $ownerRes->fetch_assoc() : null;
    $ownerStmt->close();

    if (!$ownerRow || intval($ownerRow['is_active'] ?? 0) !== 1) {
        return null;
    }

    $ownerRole = strval($ownerRow['role'] ?? '');
    if ($ownerRole === 'rep') {
        $repStmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access, is_active
            FROM admins
            WHERE admin_id = ? AND role = 'rep'
            LIMIT 1");
        if (!$repStmt) {
            return null;
        }
        $repStmt->bind_param('i', $repAdminId);
        $repStmt->execute();
        $repRes = $repStmt->get_result();
        $repRow = ($repRes && $repRes->num_rows === 1) ? $repRes->fetch_assoc() : null;
        $repStmt->close();
    } elseif ($ownerRole === 'super_admin') {
        $repRow = book_system_get_super_admin_own_rep_context($conn, $repAdminId);
    } else {
        $repRow = null;
    }

    if (!$repRow || intval($repRow['is_active'] ?? 0) !== 1) {
        return null;
    }

    return [
        'rep' => $repRow,
        'assistant' => [
            'admin_id' => intval($assistantRow['admin_id'] ?? 0),
            'username' => trim(strval($assistantRow['username'] ?? '')),
            'full_name' => trim(strval($assistantRow['full_name'] ?? '')),
            'profile_photo_path' => trim(strval($assistantRow['profile_photo_path'] ?? '')),
            'recovery_email' => trim(strval($assistantRow['recovery_email'] ?? '')),
        ],
        'permissions' => $status['permissions'] ?? [],
        'expires_at' => strval($status['expires_at'] ?? ''),
    ];
}

function book_system_user_can_access_admin_feature(string $featureKey): bool {
    $role = strval($_SESSION['admin_role'] ?? '');
    if ($role === 'super_admin') {
        return true;
    }
    if ($role !== 'temporary_admin') {
        return false;
    }

    $permissions = $_SESSION['temp_admin_permissions'] ?? [];
    if (!is_array($permissions)) {
        return false;
    }

    return in_array($featureKey, $permissions, true);
}

function book_system_require_admin_feature(mysqli $conn, string $featureKey): void {
    if (!isset($_SESSION['admin_logged_in'])) {
        header('Location: login.php');
        exit;
    }

    book_system_enforce_temporary_admin_access($conn);
    if (book_system_user_can_access_admin_feature($featureKey)) {
        return;
    }

    header('Location: admin.php?msg=access_denied');
    exit;
}

function book_system_rep_has_books(mysqli $conn, int $adminId): bool
{
    if ($adminId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total_books FROM books WHERE admin_id = ?");
    if (!$stmt) {
        return true;
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return intval($row['total_books'] ?? 0) > 0;
}

function book_system_clear_pending_rep_session(): void
{
    unset(
        $_SESSION['rep_access_gate_status'],
        $_SESSION['pending_rep_signup_id'],
        $_SESSION['pending_rep_admin_id'],
        $_SESSION['pending_rep_message'],
        $_SESSION['pending_rep_username'],
        $_SESSION['pending_rep_full_name'],
        $_SESSION['pending_rep_class_name']
    );
}

function book_system_start_pending_rep_session_from_signup(array $signupRow): void
{
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = 0;
    $_SESSION['admin_role'] = 'rep';
    $_SESSION['admin_username'] = strval($signupRow['username'] ?? '');
    $_SESSION['admin_full_name'] = strval($signupRow['full_name'] ?? '');
    $_SESSION['admin_class_name'] = strval($signupRow['class_name'] ?? '');
    $_SESSION['temp_admin_permissions'] = [];
    $_SESSION['temp_admin_expires_at'] = '';
    $_SESSION['rep_access_gate_status'] = strval($signupRow['status'] ?? 'pending');
    $_SESSION['pending_rep_signup_id'] = intval($signupRow['signup_id'] ?? 0);
    $_SESSION['pending_rep_admin_id'] = intval($signupRow['created_admin_id'] ?? 0);
    $_SESSION['pending_rep_username'] = strval($signupRow['username'] ?? '');
    $_SESSION['pending_rep_full_name'] = strval($signupRow['full_name'] ?? '');
    $_SESSION['pending_rep_class_name'] = strval($signupRow['class_name'] ?? '');
}

function book_system_start_restricted_rep_session_from_admin(array $adminRow, string $state = 'inactive'): void
{
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = intval($adminRow['admin_id'] ?? 0);
    $_SESSION['admin_role'] = 'rep';
    $_SESSION['admin_username'] = strval($adminRow['username'] ?? '');
    $_SESSION['admin_full_name'] = strval($adminRow['full_name'] ?? '');
    $_SESSION['admin_class_name'] = strval($adminRow['class_name'] ?? '');
    $_SESSION['temp_admin_permissions'] = [];
    $_SESSION['temp_admin_expires_at'] = '';
    $_SESSION['rep_access_gate_status'] = $state;
    $_SESSION['pending_rep_signup_id'] = 0;
    $_SESSION['pending_rep_admin_id'] = intval($adminRow['admin_id'] ?? 0);
    $_SESSION['pending_rep_username'] = strval($adminRow['username'] ?? '');
    $_SESSION['pending_rep_full_name'] = strval($adminRow['full_name'] ?? '');
    $_SESSION['pending_rep_class_name'] = strval($adminRow['class_name'] ?? '');
}

function book_system_activate_rep_session_from_admin_row(array $adminRow): void
{
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = intval($adminRow['admin_id'] ?? 0);
    $_SESSION['admin_role'] = 'rep';
    $_SESSION['admin_username'] = strval($adminRow['username'] ?? '');
    $_SESSION['admin_full_name'] = strval($adminRow['full_name'] ?? '');
    $_SESSION['admin_class_name'] = strval($adminRow['class_name'] ?? '');
    $_SESSION['temp_admin_permissions'] = [];
    $_SESSION['temp_admin_expires_at'] = '';
    book_system_clear_pending_rep_session();
}

function book_system_fetch_rep_admin_row_by_id(mysqli $conn, int $adminId): ?array
{
    if ($adminId <= 0) {
        return null;
    }

    $trialColumnsAvailable = function_exists('book_system_admin_trial_columns_available')
        ? book_system_admin_trial_columns_available($conn)
        : false;
    $trialSelect = $trialColumnsAvailable
        ? ", trial_started_at, trial_expires_at, subscription_active, subscription_started_at, subscription_expires_at, approved_at, created_at"
        : ", approved_at, created_at";

    $stmt = $conn->prepare("SELECT admin_id, username, password_hash, full_name, class_name, role, is_active{$trialSelect}
        FROM admins
        WHERE admin_id = ? AND role = 'rep'
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? $row : null;
}

function book_system_get_pending_rep_gate_status(mysqli $conn): ?array
{
    if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'rep') {
        return null;
    }

    $state = trim(strval($_SESSION['rep_access_gate_status'] ?? ''));
    if ($state === '') {
        return null;
    }

    $signupId = intval($_SESSION['pending_rep_signup_id'] ?? 0);
    $adminId = intval($_SESSION['pending_rep_admin_id'] ?? intval($_SESSION['admin_id'] ?? 0));

    if ($signupId > 0) {
        $stmt = $conn->prepare("SELECT signup_id, username, full_name, class_name, status, created_admin_id
            FROM rep_signup_requests
            WHERE signup_id = ?
            LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $signupId);
            $stmt->execute();
            $res = $stmt->get_result();
            $signupRow = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();

            if (is_array($signupRow)) {
                $state = trim(strval($signupRow['status'] ?? $state));
                $_SESSION['rep_access_gate_status'] = $state;
                $_SESSION['pending_rep_admin_id'] = intval($signupRow['created_admin_id'] ?? 0);
                $_SESSION['pending_rep_username'] = strval($signupRow['username'] ?? $_SESSION['pending_rep_username'] ?? '');
                $_SESSION['pending_rep_full_name'] = strval($signupRow['full_name'] ?? $_SESSION['pending_rep_full_name'] ?? '');
                $_SESSION['pending_rep_class_name'] = strval($signupRow['class_name'] ?? $_SESSION['pending_rep_class_name'] ?? '');

                if ($state === 'approved') {
                    $adminId = intval($signupRow['created_admin_id'] ?? 0);
                }
            }
        }
    }

    if (($state === 'approved' || $state === 'inactive') && $adminId > 0) {
        $adminRow = book_system_fetch_rep_admin_row_by_id($conn, $adminId);
        if ($adminRow && intval($adminRow['is_active'] ?? 0) === 1) {
            book_system_activate_rep_session_from_admin_row($adminRow);
            return ['state' => 'approved', 'admin_id' => intval($adminRow['admin_id'] ?? 0)];
        }
        if ($state === 'approved') {
            $state = 'inactive';
            $_SESSION['rep_access_gate_status'] = 'inactive';
        }
    }

    $messages = [
        'pending' => 'Your account has been created successfully and is awaiting admin approval. Once the super admin reviews and approves your request, you will be able to access your dashboard. Please check back later.',
        'rejected' => 'Your signup request was reviewed but not approved. Please contact the super admin or submit a fresh signup request if you need access again.',
        'inactive' => 'Your rep account is currently inactive or suspended. Please contact the super admin for help before trying again.',
    ];

    return [
        'state' => $state,
        'admin_id' => $adminId,
        'signup_id' => $signupId,
        'username' => strval($_SESSION['pending_rep_username'] ?? $_SESSION['admin_username'] ?? ''),
        'full_name' => strval($_SESSION['pending_rep_full_name'] ?? $_SESSION['admin_full_name'] ?? ''),
        'class_name' => strval($_SESSION['pending_rep_class_name'] ?? $_SESSION['admin_class_name'] ?? ''),
        'message' => $messages[$state] ?? $messages['pending'],
    ];
}

function book_system_enforce_rep_activation_access(mysqli $conn): void
{
    if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'rep') {
        return;
    }

    $status = book_system_get_pending_rep_gate_status($conn);
    if ($status === null) {
        $adminId = intval($_SESSION['admin_id'] ?? 0);
        if ($adminId > 0) {
            $adminRow = book_system_fetch_rep_admin_row_by_id($conn, $adminId);
            if (!$adminRow || intval($adminRow['is_active'] ?? 0) !== 1) {
                $fallbackAdminRow = $adminRow ?: [
                    'admin_id' => $adminId,
                    'username' => strval($_SESSION['admin_username'] ?? ''),
                    'full_name' => strval($_SESSION['admin_full_name'] ?? ''),
                    'class_name' => strval($_SESSION['admin_class_name'] ?? ''),
                ];
                book_system_start_restricted_rep_session_from_admin($fallbackAdminRow, 'inactive');
                $status = book_system_get_pending_rep_gate_status($conn);
            }
        }
    }

    if ($status === null || strval($status['state'] ?? '') === 'approved') {
        return;
    }

    $currentScript = basename(strval($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowlist = [
        'login.php',
        'logout.php',
        'pending_approval.php',
        'common_request_portal.php',
        'index.php',
        'submit_request.php',
        'check_student_books.php',
        'get_student_request_history.php',
        'get_student_credit.php',
        'portal_ad_redirect.php',
    ];
    if (in_array($currentScript, $allowlist, true)) {
        return;
    }

    header('Location: pending_approval.php');
    exit;
}

function book_system_enforce_rep_subscription_access(mysqli $conn): void {
    if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'rep') {
        return;
    }

    $pendingStatus = book_system_get_pending_rep_gate_status($conn);
    if (is_array($pendingStatus) && strval($pendingStatus['state'] ?? '') !== 'approved') {
        return;
    }

    if (!book_system_rep_subscription_enforcement_enabled($conn)) {
        return;
    }

    $currentScript = basename(strval($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowlist = [
        'login.php',
        'logout.php',
        'pending_approval.php',
        'rep_signup.php',
        'rep_subscription.php',
        'index.php',
        'submit_request.php',
        'check_student_books.php',
        'get_student_request_history.php',
        'get_student_credit.php',
        'common_request_portal.php',
        'portal_ad_redirect.php',
    ];
    if (in_array($currentScript, $allowlist, true)) {
        return;
    }

    $adminId = intval($_SESSION['admin_id'] ?? 0);
    $status = book_system_get_rep_access_status($conn, $adminId);
    if (!empty($status['can_access'])) {
        return;
    }

    header('Location: rep_subscription.php');
    exit;
}

function book_system_get_rep_workspace_context(mysqli $conn, int $sessionAdminId, string $sessionRole): ?array {
    if ($sessionRole === 'super_admin') {
        $repId = intval($_SESSION['super_admin_rep_context_id'] ?? 0);
        if ($repId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access, is_active
            FROM admins
            WHERE admin_id = ? AND role = 'rep'
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $repId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row || intval($row['is_active'] ?? 0) !== 1 || intval($row['allow_super_admin_access'] ?? 0) !== 1) {
            unset($_SESSION['super_admin_rep_context_id']);
            return null;
        }

        return $row;
    }

    $stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access, is_active
        FROM admins
        WHERE admin_id = ? AND role = 'rep'
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $sessionAdminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || intval($row['is_active'] ?? 0) !== 1) {
        return null;
    }

    return $row;
}

function book_system_get_super_admin_own_rep_context(mysqli $conn, int $sessionAdminId): ?array {
    if ($sessionAdminId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name,
            COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access,
            is_active
        FROM admins
        WHERE admin_id = ? AND role = 'super_admin'
        LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $sessionAdminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || intval($row['is_active'] ?? 0) !== 1) {
        return null;
    }

    return $row;
}

function book_system_get_effective_rep_access_context(mysqli $conn): ?array {
    $sessionRole = strval($_SESSION['admin_role'] ?? '');
    $sessionAdminId = intval($_SESSION['admin_id'] ?? 0);
    $repContext = null;
    $assistantContext = null;
    $isWorkspaceMode = false;
    $isOwnRepMode = false;
    $isAssistantMode = false;

    if ($sessionRole === 'super_admin') {
        $superAdminScope = strval($_SESSION['super_admin_data_scope'] ?? 'own');
        $superAdminWorkspaceMode = strval($_SESSION['super_admin_workspace_mode'] ?? 'admin');
        if ($superAdminScope === 'workspace') {
            $repContext = book_system_get_rep_workspace_context($conn, $sessionAdminId, $sessionRole);
            $isWorkspaceMode = ($repContext !== null);
        } elseif ($superAdminWorkspaceMode === 'own_rep') {
            $repContext = book_system_get_super_admin_own_rep_context($conn, $sessionAdminId);
            $isOwnRepMode = ($repContext !== null);
        }
    } elseif ($sessionRole === 'temporary_admin') {
        $assistantContext = book_system_get_assistant_rep_workspace_context($conn, $sessionAdminId);
        if ($assistantContext) {
            $repContext = $assistantContext['rep'] ?? null;
            $isAssistantMode = ($repContext !== null);
        }
    } else {
        $repContext = book_system_get_rep_workspace_context($conn, $sessionAdminId, $sessionRole);
    }

    if (!$repContext) {
        return null;
    }

    $actorUsername = strval($_SESSION['admin_username'] ?? '');
    $actorFullName = strval($_SESSION['admin_full_name'] ?? '');
    $actorClassName = strval($_SESSION['admin_class_name'] ?? '');
    $actorProfilePhotoPath = '';
    $assistantPermissions = [];
    $delegatedRepAdminId = 0;
    if ($isAssistantMode && is_array($assistantContext)) {
        $assistantRow = $assistantContext['assistant'] ?? [];
        $actorUsername = strval($assistantRow['username'] ?? $actorUsername);
        $actorFullName = strval($assistantRow['full_name'] ?? $actorFullName);
        $actorProfilePhotoPath = strval($assistantRow['profile_photo_path'] ?? '');
        $assistantPermissions = is_array($assistantContext['permissions'] ?? null) ? $assistantContext['permissions'] : [];
        $delegatedRepAdminId = intval($repContext['admin_id'] ?? 0);
    }

    return [
        'session_role' => $sessionRole,
        'session_admin_id' => $sessionAdminId,
        'session_username' => strval($_SESSION['admin_username'] ?? ''),
        'is_workspace_mode' => $isWorkspaceMode,
        'is_own_rep_mode' => $isOwnRepMode,
        'is_assistant_mode' => $isAssistantMode,
        'effective_admin_id' => intval($repContext['admin_id'] ?? 0),
        'effective_role' => 'rep',
        'effective_username' => strval($repContext['username'] ?? ''),
        'effective_full_name' => strval($repContext['full_name'] ?? ''),
        'effective_class_name' => strval($repContext['class_name'] ?? ''),
        'actor_admin_id' => $sessionAdminId,
        'actor_role' => $sessionRole,
        'actor_username' => $actorUsername,
        'actor_full_name' => $actorFullName,
        'actor_class_name' => $actorClassName,
        'actor_profile_photo_path' => $actorProfilePhotoPath,
        'assistant_permissions' => $assistantPermissions,
        'delegated_rep_admin_id' => $delegatedRepAdminId,
        'rep_context' => $repContext,
    ];
}

function book_system_ensure_balance_carry_forward_table(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS semester_balance_carry_forwards (
        carry_id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        student_id INT NOT NULL,
        admin_id INT NOT NULL DEFAULT 0,
        source_semester_id INT NOT NULL,
        target_semester_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        carried_by_role VARCHAR(30) NOT NULL DEFAULT 'system',
        notes VARCHAR(255) NULL,
        carried_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_balance_carry_request (request_id),
        INDEX idx_balance_carry_target (target_semester_id, admin_id),
        INDEX idx_balance_carry_student (student_id),
        CONSTRAINT fk_balance_carry_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
        CONSTRAINT fk_balance_carry_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
    )");

    $ensured = true;
}

function book_system_run_balance_carry_forward(mysqli $conn, int $targetSemesterId): array {
    $summary = [
        'carried_count' => 0,
        'carried_total' => 0.0,
        'target_semester_id' => $targetSemesterId,
    ];

    if ($targetSemesterId <= 0) {
        return $summary;
    }

    book_system_ensure_balance_carry_forward_table($conn);

    $sql = "SELECT
                r.request_id,
                r.student_id,
                COALESCE(r.admin_id, 0) AS admin_id,
                COALESCE(r.semester_id, 0) AS source_semester_id,
                GREATEST(
                    GREATEST(COALESCE(r.amount_paid, 0) - GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0), 0)
                    - COALESCE(br.refunded_amount, 0)
                    - COALESCE(cf.carried_amount, 0),
                    0
                ) AS carry_amount
            FROM requests r
            LEFT JOIN (
                SELECT request_id, SUM(amount) AS refunded_amount
                FROM balance_returns
                WHERE request_id IS NOT NULL
                GROUP BY request_id
            ) br ON br.request_id = r.request_id
            LEFT JOIN (
                SELECT request_id, SUM(amount) AS carried_amount
                FROM semester_balance_carry_forwards
                GROUP BY request_id
            ) cf ON cf.request_id = r.request_id
            WHERE r.semester_id IS NOT NULL
              AND r.semester_id < ?
            HAVING carry_amount > 0.009
            ORDER BY r.semester_id ASC, r.admin_id ASC, r.student_id ASC, r.request_id ASC";

    $eligible_rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $targetSemesterId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $eligible_rows[] = $row;
            }
        }
        $stmt->close();
    }

    if (empty($eligible_rows)) {
        return $summary;
    }

    $conn->begin_transaction();
    try {
        $insertCarry = $conn->prepare("INSERT INTO semester_balance_carry_forwards (
                request_id,
                student_id,
                admin_id,
                source_semester_id,
                target_semester_id,
                amount,
                carried_by_role,
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, 'system', ?)");
        $updateCredit = $conn->prepare("UPDATE students SET credit_balance = COALESCE(credit_balance, 0) + ? WHERE student_id = ?");

        if (!$insertCarry || !$updateCredit) {
            throw new RuntimeException('Unable to prepare carry-forward statements.');
        }

        foreach ($eligible_rows as $row) {
            $requestId = intval($row['request_id'] ?? 0);
            $studentId = intval($row['student_id'] ?? 0);
            $adminId = intval($row['admin_id'] ?? 0);
            $sourceSemesterId = intval($row['source_semester_id'] ?? 0);
            $amount = round(floatval($row['carry_amount'] ?? 0), 2);

            if ($requestId <= 0 || $studentId <= 0 || $sourceSemesterId <= 0 || $amount <= 0) {
                continue;
            }

            $notes = 'Auto-carried into semester ' . $targetSemesterId;
            $insertCarry->bind_param(
                'iiiiids',
                $requestId,
                $studentId,
                $adminId,
                $sourceSemesterId,
                $targetSemesterId,
                $amount,
                $notes
            );
            $insertCarry->execute();

            $updateCredit->bind_param('di', $amount, $studentId);
            $updateCredit->execute();

            $summary['carried_count']++;
            $summary['carried_total'] += $amount;
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Book System balance carry-forward failed: ' . $e->getMessage());
    }

    return $summary;
}

function book_system_json_encode(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '{}';
}

function book_system_profile_photo_upload_dir(): string {
    return __DIR__ . '/uploads/rep_profiles';
}

function book_system_ensure_profile_photo_upload_dir(): bool {
    $dir = book_system_profile_photo_upload_dir();
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true);
}

function book_system_store_profile_photo_upload(array $file, string $prefix = 'rep'): array {
    $result = [
        'success' => false,
        'path' => '',
        'message' => 'Unable to upload profile picture.',
    ];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $result['message'] = 'Please choose a profile picture to upload.';
        return $result;
    }

    if (!book_system_ensure_profile_photo_upload_dir()) {
        $result['message'] = 'Profile picture folder is not available.';
        return $result;
    }

    $tmpPath = strval($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $result['message'] = 'Uploaded profile picture could not be verified.';
        return $result;
    }

    $maxBytes = 3 * 1024 * 1024;
    if (intval($file['size'] ?? 0) > $maxBytes) {
        $result['message'] = 'Profile picture must be 3 MB or less.';
        return $result;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = strval(finfo_file($finfo, $tmpPath) ?: '');
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = strval(mime_content_type($tmpPath) ?: '');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        $result['message'] = 'Profile picture must be JPG, PNG, or WEBP.';
        return $result;
    }

    $extension = $allowed[$mime];
    try {
        $filename = $prefix . '_' . bin2hex(random_bytes(10)) . '.' . $extension;
    } catch (Throwable $e) {
        $filename = $prefix . '_' . str_replace('.', '', (string) microtime(true)) . '_' . mt_rand(1000, 9999) . '.' . $extension;
    }

    $targetPath = book_system_profile_photo_upload_dir() . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        $result['message'] = 'Could not save the uploaded profile picture.';
        return $result;
    }

    $result['success'] = true;
    $result['path'] = 'uploads/rep_profiles/' . $filename;
    $result['message'] = '';
    return $result;
}

function book_system_delete_profile_photo(?string $relativePath): void {
    $relativePath = trim(strval($relativePath ?? ''));
    if ($relativePath === '' || strpos($relativePath, 'uploads/rep_profiles/') !== 0) {
        return;
    }

    $absolutePath = __DIR__ . '/' . str_replace(['\\', '..'], ['/', ''], $relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function book_system_audit_log(
    mysqli $conn,
    string $actionType,
    string $entityType,
    int $entityId = 0,
    array $details = [],
    ?int $targetAdminId = null
): void {
    static $auditTableChecked = false;
    static $auditTableAvailable = false;

    if (!$auditTableChecked) {
        $auditTableChecked = true;
        $check = $conn->query("SHOW TABLES LIKE 'audit_logs'");
        $auditTableAvailable = ($check && $check->num_rows === 1);
    }

    if (!$auditTableAvailable) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        book_system_secure_session_start();
    }

    $actorAdminId = intval($_SESSION['admin_id'] ?? 0);
    $actorRole = strval($_SESSION['admin_role'] ?? 'guest');
    $actorUsername = strval($_SESSION['admin_username'] ?? '');
    $entityId = max(0, $entityId);
    $targetAdminId = $targetAdminId !== null ? max(0, $targetAdminId) : null;
    $detailsJson = book_system_json_encode($details);

    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (
            actor_admin_id,
            actor_role,
            actor_username,
            action_type,
            entity_type,
            entity_id,
            target_admin_id,
            details_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'issssiss',
        $actorAdminId,
        $actorRole,
        $actorUsername,
        $actionType,
        $entityType,
        $entityId,
        $targetAdminId,
        $detailsJson
    );
    $stmt->execute();
    $stmt->close();
}

function book_system_reset_semester_workspace_fetch_scalar(mysqli $conn, string $sql, string $types, array $params): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = 0;
    if ($result && ($row = $result->fetch_assoc())) {
        $value = intval(array_values($row)[0] ?? 0);
    }
    $stmt->close();

    return $value;
}

function book_system_get_rep_semester_workspace_summary(mysqli $conn, int $adminId, int $semesterId): array
{
    $summary = [
        'class_students' => 0,
        'books' => 0,
        'requests' => 0,
        'request_items' => 0,
        'payment_records' => 0,
        'balance_returns' => 0,
        'books_received' => 0,
        'lecturer_payments' => 0,
        'notifications' => 0,
        'export_batches' => 0,
    ];

    if ($adminId <= 0 || $semesterId <= 0) {
        return $summary;
    }

    $params = [$adminId, $semesterId];

    $summary['class_students'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM class_students WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['books'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM books WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['requests'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM requests WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['request_items'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM request_items ri
         INNER JOIN requests r ON r.request_id = ri.request_id
         WHERE r.admin_id = ? AND r.semester_id = ?",
        'ii',
        $params
    );
    $summary['payment_records'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM requests
         WHERE admin_id = ? AND semester_id = ?
           AND (
               COALESCE(amount_paid, 0) > 0
               OR COALESCE(credit_used, 0) > 0
               OR LOWER(TRIM(COALESCE(payment_status, 'unpaid'))) <> 'unpaid'
               OR COALESCE(payment_reference, '') <> ''
               OR COALESCE(payment_gateway, '') <> ''
               OR payment_verified_at IS NOT NULL
           )",
        'ii',
        $params
    );
    $summary['balance_returns'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM balance_returns br
         INNER JOIN requests r ON r.request_id = br.request_id
         WHERE r.admin_id = ? AND r.semester_id = ?",
        'ii',
        $params
    );
    $summary['books_received'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM books_received WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['lecturer_payments'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM lecturer_payments WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['notifications'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM notifications n
         INNER JOIN requests r ON r.request_id = n.related_id
         WHERE r.admin_id = ? AND r.semester_id = ?
           AND n.notification_type IN ('student_request', 'student_payment', 'request_item_cancelled')",
        'ii',
        $params
    );
    $summary['export_batches'] = book_system_reset_semester_workspace_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM lecturer_export_batches WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );

    return $summary;
}

function book_system_reset_rep_semester_workspace(mysqli $conn, int $adminId, int $semesterId): array
{
    $response = [
        'success' => false,
        'message' => 'Workspace reset could not be completed.',
        'summary' => book_system_get_rep_semester_workspace_summary($conn, $adminId, $semesterId),
    ];

    if ($adminId <= 0 || $semesterId <= 0) {
        $response['message'] = 'Invalid workspace reset scope.';
        return $response;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn->begin_transaction();

        $creditRestoreRows = [];
        $creditRestoreStmt = $conn->prepare("SELECT
                r.student_id,
                ROUND(SUM(GREATEST(COALESCE(r.credit_used, 0) - COALESCE(refunds.credit_refunded_total, 0), 0)), 2) AS credit_restore
            FROM requests r
            LEFT JOIN (
                SELECT request_id, SUM(COALESCE(credit_refunded_amount, 0)) AS credit_refunded_total
                FROM request_items
                GROUP BY request_id
            ) refunds ON refunds.request_id = r.request_id
            WHERE r.admin_id = ? AND r.semester_id = ?
            GROUP BY r.student_id
            HAVING credit_restore > 0.009");
        if (!$creditRestoreStmt) {
            throw new RuntimeException('Could not prepare the student credit reset.');
        }
        $creditRestoreStmt->bind_param('ii', $adminId, $semesterId);
        $creditRestoreStmt->execute();
        $creditRestoreResult = $creditRestoreStmt->get_result();
        if ($creditRestoreResult) {
            while ($creditRestoreRow = $creditRestoreResult->fetch_assoc()) {
                $creditRestoreRows[] = [
                    'student_id' => intval($creditRestoreRow['student_id'] ?? 0),
                    'credit_restore' => round(floatval($creditRestoreRow['credit_restore'] ?? 0), 2),
                ];
            }
        }
        $creditRestoreStmt->close();

        if (!empty($creditRestoreRows)) {
            $restoreStudentCreditStmt = $conn->prepare("UPDATE students
                SET credit_balance = COALESCE(credit_balance, 0) + ?
                WHERE student_id = ?
                LIMIT 1");
            if (!$restoreStudentCreditStmt) {
                throw new RuntimeException('Could not prepare the student credit update.');
            }
            foreach ($creditRestoreRows as $creditRestoreRow) {
                if ($creditRestoreRow['student_id'] <= 0 || $creditRestoreRow['credit_restore'] <= 0) {
                    continue;
                }
                $restoreStudentCreditStmt->bind_param('di', $creditRestoreRow['credit_restore'], $creditRestoreRow['student_id']);
                $restoreStudentCreditStmt->execute();
            }
            $restoreStudentCreditStmt->close();
        }

        $notificationDeleteStmt = $conn->prepare("DELETE n
            FROM notifications n
            INNER JOIN requests r ON r.request_id = n.related_id
            WHERE r.admin_id = ? AND r.semester_id = ?
              AND n.notification_type IN ('student_request', 'student_payment', 'request_item_cancelled')");
        if (!$notificationDeleteStmt) {
            throw new RuntimeException('Could not prepare the notification cleanup.');
        }
        $notificationDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $notificationDeleteStmt->execute();
        $notificationDeleteStmt->close();

        $balanceDeleteStmt = $conn->prepare("DELETE br
            FROM balance_returns br
            INNER JOIN requests r ON r.request_id = br.request_id
            WHERE r.admin_id = ? AND r.semester_id = ?");
        if (!$balanceDeleteStmt) {
            throw new RuntimeException('Could not prepare the balance cleanup.');
        }
        $balanceDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $balanceDeleteStmt->execute();
        $balanceDeleteStmt->close();

        $batchItemsDeleteStmt = $conn->prepare("DELETE bei
            FROM lecturer_export_batch_items bei
            INNER JOIN lecturer_export_batches leb ON leb.batch_id = bei.batch_id
            WHERE leb.admin_id = ? AND leb.semester_id = ?");
        if (!$batchItemsDeleteStmt) {
            throw new RuntimeException('Could not prepare the export batch item cleanup.');
        }
        $batchItemsDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $batchItemsDeleteStmt->execute();
        $batchItemsDeleteStmt->close();

        $batchDeleteStmt = $conn->prepare("DELETE FROM lecturer_export_batches
            WHERE admin_id = ? AND semester_id = ?");
        if (!$batchDeleteStmt) {
            throw new RuntimeException('Could not prepare the export batch cleanup.');
        }
        $batchDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $batchDeleteStmt->execute();
        $batchDeleteStmt->close();

        $booksReceivedDeleteStmt = $conn->prepare("DELETE FROM books_received
            WHERE admin_id = ? AND semester_id = ?");
        if (!$booksReceivedDeleteStmt) {
            throw new RuntimeException('Could not prepare the books received cleanup.');
        }
        $booksReceivedDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $booksReceivedDeleteStmt->execute();
        $booksReceivedDeleteStmt->close();

        $lecturerPaymentDeleteStmt = $conn->prepare("DELETE FROM lecturer_payments
            WHERE admin_id = ? AND semester_id = ?");
        if (!$lecturerPaymentDeleteStmt) {
            throw new RuntimeException('Could not prepare the lecturer payment cleanup.');
        }
        $lecturerPaymentDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $lecturerPaymentDeleteStmt->execute();
        $lecturerPaymentDeleteStmt->close();

        $requestDeleteStmt = $conn->prepare("DELETE FROM requests
            WHERE admin_id = ? AND semester_id = ?");
        if (!$requestDeleteStmt) {
            throw new RuntimeException('Could not prepare the request cleanup.');
        }
        $requestDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $requestDeleteStmt->execute();
        $requestDeleteStmt->close();

        $classListDeleteStmt = $conn->prepare("DELETE FROM class_students
            WHERE admin_id = ? AND semester_id = ?");
        if (!$classListDeleteStmt) {
            throw new RuntimeException('Could not prepare the class list cleanup.');
        }
        $classListDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $classListDeleteStmt->execute();
        $classListDeleteStmt->close();

        $booksDeleteStmt = $conn->prepare("DELETE FROM books
            WHERE admin_id = ? AND semester_id = ?");
        if (!$booksDeleteStmt) {
            throw new RuntimeException('Could not prepare the books cleanup.');
        }
        $booksDeleteStmt->bind_param('ii', $adminId, $semesterId);
        $booksDeleteStmt->execute();
        $booksDeleteStmt->close();

        if (function_exists('book_system_bump_portal_lookup_cache_version')) {
            book_system_bump_portal_lookup_cache_version($conn);
        }
        if (function_exists('cache_clear_all')) {
            cache_clear_all();
        }

        if (function_exists('book_system_audit_log')) {
            book_system_audit_log(
                $conn,
                'reset_current_semester_workspace',
                'rep_semester_workspace',
                $adminId,
                [
                    'rep_admin_id' => $adminId,
                    'semester_id' => $semesterId,
                    'counts' => $response['summary'],
                ],
                $adminId
            );
        }

        $conn->commit();
        $response['success'] = true;
        $response['message'] = 'Workspace reset successfully. You can now upload a new class list and start fresh.';
    } catch (Throwable $e) {
        $conn->rollback();
        $response['message'] = book_system_security_debug_enabled()
            ? $e->getMessage()
            : 'The workspace could not be reset. Nothing was removed.';
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }

    return $response;
}

function book_system_cancel_request_item(
    mysqli $conn,
    int $itemId,
    ?int $adminId = null,
    bool $isSuperAdmin = false,
    string $reason = ''
): array {
    $response = [
        'success' => false,
        'message' => 'Unable to cancel this item.',
        'cash_refunded' => 0.0,
        'credit_refunded' => 0.0,
        'request_id' => 0,
    ];

    $reason = trim($reason);
    if ($itemId <= 0) {
        $response['message'] = 'Invalid request item.';
        return $response;
    }

    $sql = "SELECT
            ri.item_id,
            ri.request_id,
            ri.book_id,
            ri.is_collected,
            COALESCE(ri.is_cancelled, 0) AS is_cancelled,
            COALESCE(ri.unit_price, b.price, 0) AS unit_price,
            b.book_title,
            r.student_id,
            r.admin_id,
            r.total_amount,
            r.amount_paid,
            COALESCE(r.credit_used, 0) AS credit_used,
            COALESCE(s.credit_balance, 0) AS credit_balance
        FROM request_items ri
        JOIN requests r ON r.request_id = ri.request_id
        JOIN students s ON s.student_id = r.student_id
        LEFT JOIN books b ON b.book_id = ri.book_id
        WHERE ri.item_id = ?";

    if (!$isSuperAdmin && $adminId !== null && $adminId > 0) {
        $sql .= " AND r.admin_id = ?";
    }

    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $response['message'] = 'Could not prepare refund lookup.';
        return $response;
    }

    if (!$isSuperAdmin && $adminId !== null && $adminId > 0) {
        $stmt->bind_param('ii', $itemId, $adminId);
    } else {
        $stmt->bind_param('i', $itemId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $item = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$item) {
        $response['message'] = 'This item could not be found.';
        return $response;
    }

    if (intval($item['is_cancelled'] ?? 0) === 1) {
        $response['message'] = 'This item has already been cancelled.';
        return $response;
    }

    if (intval($item['is_collected'] ?? 0) === 1) {
        $response['message'] = 'Collected books cannot be cancelled.';
        return $response;
    }

    $requestId = intval($item['request_id'] ?? 0);
    $studentId = intval($item['student_id'] ?? 0);
    $unitPrice = round(floatval($item['unit_price'] ?? 0), 2);
    $oldTotal = round(floatval($item['total_amount'] ?? 0), 2);
    $oldAmountPaid = round(floatval($item['amount_paid'] ?? 0), 2);
    $oldCreditUsed = round(floatval($item['credit_used'] ?? 0), 2);
    $bookTitle = trim(strval($item['book_title'] ?? 'Book'));

    $newTotal = round(max(0, $oldTotal - $unitPrice), 2);
    $newCreditUsed = round(min($oldCreditUsed, $newTotal), 2);
    $creditRefund = round(max(0, $oldCreditUsed - $newCreditUsed), 2);
    $newDueAfterCredit = round(max(0, $newTotal - $newCreditUsed), 2);
    $newAmountPaid = round(min($oldAmountPaid, $newDueAfterCredit), 2);
    $cashRefund = round(max(0, $oldAmountPaid - $newAmountPaid), 2);
    $newStatus = book_system_request_payment_status_from_row([
        'total_amount' => $newTotal,
        'credit_used' => $newCreditUsed,
        'amount_paid' => $newAmountPaid,
    ]);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn->begin_transaction();

        $updateItem = $conn->prepare("UPDATE request_items
            SET is_cancelled = 1,
                cancelled_at = NOW(),
                cancel_reason = ?,
                cash_refunded_amount = ?,
                credit_refunded_amount = ?
            WHERE item_id = ?
            LIMIT 1");
        if (!$updateItem) {
            throw new RuntimeException('Could not update the cancelled item.');
        }
        $updateItem->bind_param('sddi', $reason, $cashRefund, $creditRefund, $itemId);
        $updateItem->execute();
        $updateItem->close();

        $updateRequest = $conn->prepare("UPDATE requests
            SET total_amount = ?,
                amount_paid = ?,
                credit_used = ?,
                payment_status = ?
            WHERE request_id = ?
            LIMIT 1");
        if (!$updateRequest) {
            throw new RuntimeException('Could not update the parent request.');
        }
        $updateRequest->bind_param('dddsi', $newTotal, $newAmountPaid, $newCreditUsed, $newStatus, $requestId);
        $updateRequest->execute();
        $updateRequest->close();

        if ($creditRefund > 0) {
            $updateStudent = $conn->prepare("UPDATE students SET credit_balance = COALESCE(credit_balance, 0) + ? WHERE student_id = ? LIMIT 1");
            if (!$updateStudent) {
                throw new RuntimeException('Could not restore student credit.');
            }
            $updateStudent->bind_param('di', $creditRefund, $studentId);
            $updateStudent->execute();
            $updateStudent->close();
        }

        if (function_exists('book_system_create_notification')) {
            $summaryParts = [];
            if ($cashRefund > 0) {
                $summaryParts[] = 'cash refund GH? ' . number_format($cashRefund, 2);
            }
            if ($creditRefund > 0) {
                $summaryParts[] = 'credit returned GH? ' . number_format($creditRefund, 2);
            }
            $summary = empty($summaryParts) ? 'No refund was due.' : implode(', ', $summaryParts) . '.';
            book_system_create_notification(
                $conn,
                intval($item['admin_id'] ?? 0),
                null,
                'request_item_cancelled',
                'Request item cancelled',
                ($bookTitle !== '' ? $bookTitle : 'A requested book') . ' was cancelled. ' . $summary,
                $requestId
            );
        }

        if (function_exists('book_system_audit_log')) {
            book_system_audit_log(
                $conn,
                'request_item_cancelled',
                'request_item',
                $itemId,
                [
                    'request_id' => $requestId,
                    'student_id' => $studentId,
                    'book_title' => $bookTitle,
                    'cash_refunded' => $cashRefund,
                    'credit_refunded' => $creditRefund,
                    'cancel_reason' => $reason,
                ],
                intval($item['admin_id'] ?? 0)
            );
        }

        $conn->commit();

        $response['success'] = true;
        $response['message'] = 'This item was cancelled successfully.';
        $response['cash_refunded'] = $cashRefund;
        $response['credit_refunded'] = $creditRefund;
        $response['request_id'] = $requestId;
        return $response;
    } catch (Throwable $e) {
        $conn->rollback();
        $response['message'] = book_system_security_debug_enabled()
            ? $e->getMessage()
            : 'We could not cancel this item right now. Please try again.';
        return $response;
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

function book_system_create_verified_paystack_request(
    mysqli $conn,
    string $checkoutToken,
    string $reference,
    array $verificationData = []
): array {
    $response = [
        'success' => false,
        'already_created' => false,
        'request_id' => 0,
        'status' => 'pending',
        'message' => 'We could not confirm this payment right now. Please try again.',
    ];

    $checkoutToken = trim($checkoutToken);
    $reference = trim($reference);
    if ($checkoutToken === '' || $reference === '') {
        $response['message'] = 'Missing payment verification details.';
        return $response;
    }

    book_system_sync_connection_timezone($conn);

    $checkout = book_system_get_paystack_checkout($checkoutToken, book_system_paystack_checkout_ttl());
    if (!is_array($checkout)) {
        $response['message'] = 'This payment session expired. Please start the request again.';
        $response['status'] = 'expired';
        return $response;
    }

    $existingRequestId = intval($checkout['request_id'] ?? 0);
    if ($existingRequestId > 0) {
        $response['success'] = true;
        $response['already_created'] = true;
        $response['request_id'] = $existingRequestId;
        $response['status'] = 'paid';
        $response['message'] = 'Payment verified successfully.';
        return $response;
    }

    $gatewayStatus = strtolower(trim(strval($verificationData['status'] ?? '')));
    if ($gatewayStatus !== 'success') {
        $response['status'] = $gatewayStatus !== '' ? $gatewayStatus : 'pending';
        $response['message'] = $gatewayStatus === 'abandoned'
            ? 'The payment was not completed.'
            : 'The payment is not yet completed.';
        return $response;
    }

    $gatewayCurrency = strtoupper(trim(strval($verificationData['currency'] ?? 'GHS')));
    if ($gatewayCurrency !== 'GHS') {
        $response['message'] = 'The payment currency did not match this checkout.';
        return $response;
    }

    $expectedAmount = intval(round(floatval($checkout['total_payable'] ?? 0) * 100));
    $paidAmountFromGateway = intval($verificationData['amount'] ?? 0);
    if ($expectedAmount <= 0 || $paidAmountFromGateway !== $expectedAmount) {
        $response['message'] = 'The verified payment amount did not match the payable total.';
        return $response;
    }

    $metadata = is_array($verificationData['metadata'] ?? null) ? $verificationData['metadata'] : [];
    $metadataCheckoutToken = trim(strval($metadata['checkout_token'] ?? ''));
    if ($metadataCheckoutToken !== '' && !hash_equals($checkoutToken, $metadataCheckoutToken)) {
        $response['message'] = 'The payment reference did not match this checkout.';
        return $response;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $transactionStarted = false;

    try {
        $existingReferenceStmt = $conn->prepare("SELECT request_id, payment_status
            FROM requests
            WHERE payment_reference = ?
            LIMIT 1");
        if (!$existingReferenceStmt) {
            throw new RuntimeException('Could not prepare payment lookup.');
        }
        $existingReferenceStmt->bind_param('s', $reference);
        $existingReferenceStmt->execute();
        $existingReferenceResult = $existingReferenceStmt->get_result();
        $existingReferenceRow = ($existingReferenceResult && $existingReferenceResult->num_rows === 1)
            ? $existingReferenceResult->fetch_assoc()
            : null;
        $existingReferenceStmt->close();

        if ($existingReferenceRow) {
            $requestId = intval($existingReferenceRow['request_id'] ?? 0);
            if ($requestId > 0) {
                book_system_update_paystack_checkout($checkoutToken, [
                    'request_id' => $requestId,
                    'verified_reference' => $reference,
                    'verified_at' => time(),
                ], book_system_paystack_checkout_ttl());

                $response['success'] = true;
                $response['already_created'] = true;
                $response['request_id'] = $requestId;
                $response['status'] = strtolower(trim(strval($existingReferenceRow['payment_status'] ?? 'paid')));
                $response['message'] = 'Payment verified successfully.';
                return $response;
            }
        }

        $repId = intval($checkout['rep_id'] ?? 0);
        $semesterId = intval($checkout['semester_id'] ?? 0);
        $indexNumber = trim(strval($checkout['index_number'] ?? ''));
        $fullName = trim(strval($checkout['full_name'] ?? ''));
        $phone = trim(strval($checkout['phone'] ?? ''));
        $selectedBookIds = array_values(array_unique(array_filter(array_map('intval', is_array($checkout['selected_book_ids'] ?? null) ? $checkout['selected_book_ids'] : []), static function ($bookId) {
            return $bookId > 0;
        })));

        $bookPrices = [];
        foreach ((array) ($checkout['book_prices'] ?? []) as $bookId => $unitPrice) {
            $normalizedBookId = intval($bookId);
            if ($normalizedBookId > 0) {
                $bookPrices[$normalizedBookId] = round(floatval($unitPrice), 2);
            }
        }

        $totalAmount = round(floatval($checkout['total_amount'] ?? 0), 2);
        $creditUsed = round(floatval($checkout['credit_used'] ?? 0), 2);
        $amountPaid = round(max(0, $totalAmount - $creditUsed), 2);

        if ($repId <= 0 || $semesterId <= 0 || $indexNumber === '' || empty($selectedBookIds) || empty($bookPrices) || $totalAmount <= 0) {
            throw new RuntimeException('The saved payment session is incomplete.');
        }

        $paymentSettings = book_system_get_admin_payment_settings($conn, $repId, true);
        if (($paymentSettings['effective_method'] ?? '') !== 'paystack') {
            throw new RuntimeException('This rep is no longer configured for Paystack payment.');
        }

        $conn->begin_transaction();
        $transactionStarted = true;

        $bookPlaceholders = implode(',', array_fill(0, count($selectedBookIds), '?'));
        $bookCheckSql = "SELECT book_id
            FROM books
            WHERE book_id IN ($bookPlaceholders)
              AND admin_id = ?
              AND semester_id = ?";
        $bookCheckStmt = $conn->prepare($bookCheckSql);
        if (!$bookCheckStmt) {
            throw new RuntimeException('Could not confirm the selected books.');
        }
        $bookCheckTypes = str_repeat('i', count($selectedBookIds)) . 'ii';
        $bookCheckParams = array_merge($selectedBookIds, [$repId, $semesterId]);
        $bookCheckStmt->bind_param($bookCheckTypes, ...$bookCheckParams);
        $bookCheckStmt->execute();
        $bookCheckResult = $bookCheckStmt->get_result();
        $confirmedBookIds = [];
        if ($bookCheckResult) {
            while ($bookCheckRow = $bookCheckResult->fetch_assoc()) {
                $confirmedBookIds[] = intval($bookCheckRow['book_id'] ?? 0);
            }
        }
        $bookCheckStmt->close();

        sort($confirmedBookIds);
        $expectedBookIds = $selectedBookIds;
        sort($expectedBookIds);
        if ($confirmedBookIds !== $expectedBookIds) {
            throw new RuntimeException('One or more selected books are no longer available for this semester.');
        }

        $studentId = 0;
        $studentCreditBalance = 0.0;
        $studentStmt = $conn->prepare("SELECT student_id, credit_balance, admin_id
            FROM students
            WHERE index_number = ?
            LIMIT 1
            FOR UPDATE");
        if (!$studentStmt) {
            throw new RuntimeException('Could not prepare the student lookup.');
        }
        $studentStmt->bind_param('s', $indexNumber);
        $studentStmt->execute();
        $studentResult = $studentStmt->get_result();

        if ($studentResult && $studentResult->num_rows > 0) {
            $studentRow = $studentResult->fetch_assoc();
            $studentId = intval($studentRow['student_id'] ?? 0);
            $studentCreditBalance = round(floatval($studentRow['credit_balance'] ?? 0), 2);
            $studentAdminId = intval($studentRow['admin_id'] ?? 0);

            if ($studentAdminId <= 0) {
                $claimStmt = $conn->prepare("UPDATE students
                    SET admin_id = ?
                    WHERE student_id = ?
                      AND (admin_id IS NULL OR admin_id = 0)");
                if ($claimStmt) {
                    $claimStmt->bind_param('ii', $repId, $studentId);
                    $claimStmt->execute();
                    $claimStmt->close();
                }
            }

            $studentUpdate = $conn->prepare("UPDATE students
                SET full_name = ?, phone = ?
                WHERE student_id = ?");
            if ($studentUpdate) {
                $studentUpdate->bind_param('ssi', $fullName, $phone, $studentId);
                $studentUpdate->execute();
                $studentUpdate->close();
            }
        } else {
            $insertStudentStmt = $conn->prepare("INSERT INTO students
                (full_name, index_number, phone, credit_balance, admin_id)
                VALUES (?, ?, ?, 0, ?)");
            if (!$insertStudentStmt) {
                throw new RuntimeException('Could not save the student for this payment.');
            }
            $insertStudentStmt->bind_param('sssi', $fullName, $indexNumber, $phone, $repId);
            $insertStudentStmt->execute();
            $studentId = intval($conn->insert_id);
            $insertStudentStmt->close();
        }
        $studentStmt->close();

        if ($studentId <= 0) {
            throw new RuntimeException('The student could not be linked to this payment.');
        }

        if ($creditUsed > 0) {
            if ($studentCreditBalance + 0.00001 < $creditUsed) {
                throw new RuntimeException('This payment session expired because the student credit changed. Please start again.');
            }

            $newCreditBalance = round($studentCreditBalance - $creditUsed, 2);
            $creditUpdateStmt = $conn->prepare("UPDATE students
                SET credit_balance = ?
                WHERE student_id = ?
                LIMIT 1");
            if (!$creditUpdateStmt) {
                throw new RuntimeException('Could not apply the saved student credit.');
            }
            $creditUpdateStmt->bind_param('di', $newCreditBalance, $studentId);
            $creditUpdateStmt->execute();
            $creditUpdateStmt->close();
        }

        $requestStmt = $conn->prepare("INSERT INTO requests
            (student_id, total_amount, amount_paid, credit_used, payment_status, semester_id, admin_id, rep_viewed_at, payment_reference, payment_gateway, payment_verified_at)
            VALUES (?, ?, ?, ?, 'paid', ?, ?, NULL, ?, 'paystack', NOW())");
        if (!$requestStmt) {
            throw new RuntimeException('Could not save the verified request.');
        }
        $requestStmt->bind_param('idddiis', $studentId, $totalAmount, $amountPaid, $creditUsed, $semesterId, $repId, $reference);
        $requestStmt->execute();
        $requestId = intval($conn->insert_id);
        $requestStmt->close();

        $itemValues = [];
        $itemTypes = '';
        $itemParams = [];
        foreach ($selectedBookIds as $bookId) {
            if (!isset($bookPrices[$bookId])) {
                continue;
            }
            $itemValues[] = '(?, ?, ?)';
            $itemTypes .= 'iid';
            $itemParams[] = $requestId;
            $itemParams[] = $bookId;
            $itemParams[] = $bookPrices[$bookId];
        }

        if (empty($itemValues)) {
            throw new RuntimeException('No books were available to attach to this request.');
        }

        $itemsSql = "INSERT INTO request_items (request_id, book_id, unit_price)
            VALUES " . implode(', ', $itemValues);
        $itemsStmt = $conn->prepare($itemsSql);
        if (!$itemsStmt) {
            throw new RuntimeException('Could not save the request items.');
        }
        $itemsStmt->bind_param($itemTypes, ...$itemParams);
        $itemsStmt->execute();
        $itemsStmt->close();

        if (function_exists('book_system_create_notification')) {
            $studentLabel = $fullName !== '' ? $fullName : $indexNumber;
            $bookCount = count($selectedBookIds);
            $notificationMessage = $studentLabel . ' submitted a request';
            if ($bookCount > 0) {
                $notificationMessage .= ' for ' . $bookCount . ' book' . ($bookCount === 1 ? '' : 's');
            }
            $notificationMessage .= ' and completed Paystack payment.';
            if ($indexNumber !== '') {
                $notificationMessage .= ' Index: ' . $indexNumber . '.';
            }

            book_system_create_notification(
                $conn,
                $repId,
                null,
                'student_payment',
                'Student payment confirmed',
                $notificationMessage,
                $requestId
            );
        }

        $conn->commit();
        $transactionStarted = false;

        book_system_update_paystack_checkout($checkoutToken, [
            'request_id' => $requestId,
            'verified_reference' => $reference,
            'verified_at' => time(),
        ], book_system_paystack_checkout_ttl());

        $fingerprint = trim(strval($checkout['fingerprint'] ?? ''));
        if ($fingerprint !== '' && function_exists('book_system_remember_submission_result')) {
            book_system_remember_submission_result('portal_request', $fingerprint, [
                'request_id' => $requestId,
                'redirect' => 'payment_instructions.php?request_id=' . $requestId,
            ]);
        }

        $response['success'] = true;
        $response['request_id'] = $requestId;
        $response['status'] = 'paid';
        $response['message'] = 'Payment verified successfully.';
        return $response;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        $response['message'] = book_system_security_debug_enabled()
            ? $e->getMessage()
            : 'We could not confirm this payment right now. Please try again.';
        return $response;
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

function book_system_apply_verified_request_payment(
    mysqli $conn,
    int $requestId,
    string $reference,
    array $verificationData = []
): array {
    $response = [
        'success' => false,
        'already_paid' => false,
        'status' => 'unpaid',
        'message' => 'We could not confirm this payment right now. Please try again.',
    ];

    $requestId = max(0, $requestId);
    $reference = trim($reference);
    if ($requestId <= 0 || $reference === '') {
        $response['message'] = 'Invalid payment verification request.';
        return $response;
    }

    book_system_sync_connection_timezone($conn);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare("SELECT
                r.request_id,
                r.student_id,
                r.total_amount,
                r.amount_paid,
                COALESCE(r.credit_used, 0) AS credit_used,
                r.payment_status,
                r.admin_id,
                r.payment_reference,
                s.index_number,
                s.full_name
            FROM requests r
            JOIN students s ON s.student_id = r.student_id
            WHERE r.request_id = ?
            LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare payment lookup.');
        }
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $result = $stmt->get_result();
        $requestRow = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$requestRow) {
            throw new RuntimeException('The request linked to this payment could not be found.');
        }

        $paymentSettings = book_system_get_admin_payment_settings($conn, intval($requestRow['admin_id'] ?? 0), true);
        if (($paymentSettings['effective_method'] ?? '') !== 'paystack') {
            throw new RuntimeException('This request is not configured for Paystack payment.');
        }

        $breakdown = book_system_get_request_payment_breakdown($requestRow, $paymentSettings, $conn);
        $dueAfterCredit = round(floatval($breakdown['balance_due'] ?? 0), 2);
        $expectedAmount = intval(round(floatval($breakdown['total_payable'] ?? 0) * 100));
        $paidAmountFromGateway = intval($verificationData['amount'] ?? 0);
        $gatewayStatus = strtolower(trim(strval($verificationData['status'] ?? '')));
        $gatewayCurrency = strtoupper(trim(strval($verificationData['currency'] ?? 'GHS')));
        $metadata = is_array($verificationData['metadata'] ?? null) ? $verificationData['metadata'] : [];
        $metadataRequestId = intval($metadata['request_id'] ?? 0);

        if ($gatewayStatus !== 'success') {
            $response['message'] = 'The payment is not yet completed.';
            $response['status'] = $gatewayStatus !== '' ? $gatewayStatus : 'pending';
            $conn->commit();
            return $response;
        }

        if ($gatewayCurrency !== 'GHS') {
            throw new RuntimeException('The payment currency did not match this request.');
        }

        if ($metadataRequestId > 0 && $metadataRequestId !== $requestId) {
            throw new RuntimeException('The payment reference did not match this request.');
        }

        if ($paidAmountFromGateway !== $expectedAmount) {
            throw new RuntimeException('The verified payment amount did not match the payable total.');
        }

        $oldStatus = strtolower(trim(strval($requestRow['payment_status'] ?? 'unpaid')));
        $newAmountPaid = $dueAfterCredit;

        if ($oldStatus !== 'paid' || round(floatval($requestRow['amount_paid'] ?? 0), 2) !== $newAmountPaid) {
            $update = $conn->prepare("UPDATE requests
                SET payment_status = 'paid',
                    amount_paid = ?,
                    payment_reference = ?,
                    payment_gateway = 'paystack',
                    payment_verified_at = NOW()
                WHERE request_id = ?
                LIMIT 1");
            if (!$update) {
                throw new RuntimeException('Could not save the verified payment.');
            }
            $update->bind_param('dsi', $newAmountPaid, $reference, $requestId);
            $update->execute();
            $update->close();

            if (function_exists('book_system_create_notification')) {
                $studentLabel = trim(strval($requestRow['full_name'] ?? ''));
                if ($studentLabel === '') {
                    $studentLabel = trim(strval($requestRow['index_number'] ?? ''));
                }
                $notificationMessage = $studentLabel . ' completed a Paystack payment.';
                if (trim(strval($requestRow['index_number'] ?? '')) !== '') {
                    $notificationMessage .= ' Index: ' . trim(strval($requestRow['index_number'])) . '.';
                }
                book_system_create_notification(
                    $conn,
                    intval($requestRow['admin_id'] ?? 0),
                    null,
                    'student_payment',
                    'Student payment confirmed',
                    $notificationMessage,
                    $requestId
                );
            }
        }

        $conn->commit();

        $response['success'] = true;
        $response['already_paid'] = ($oldStatus === 'paid');
        $response['status'] = 'paid';
        $response['message'] = ($oldStatus === 'paid')
            ? 'This request was already marked as paid.'
            : 'Payment verified successfully.';
        return $response;
    } catch (Throwable $e) {
        $conn->rollback();
        $response['message'] = book_system_security_debug_enabled()
            ? $e->getMessage()
            : 'We could not confirm this payment right now. Please try again.';
        return $response;
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }
}

function book_system_normalize_recovery_email(?string $email): string {
    $email = strtolower(trim(strval($email ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    return substr($email, 0, 120);
}

function book_system_mask_email(string $email): string {
    $email = book_system_normalize_recovery_email($email);
    if ($email === '') {
        return '';
    }

    [$localPart, $domainPart] = array_pad(explode('@', $email, 2), 2, '');
    if ($localPart === '' || $domainPart === '') {
        return '';
    }

    $visibleLocal = strlen($localPart) <= 2
        ? substr($localPart, 0, 1)
        : substr($localPart, 0, 2);

    $domainPieces = explode('.', $domainPart);
    $domainName = strval($domainPieces[0] ?? '');
    $domainSuffix = implode('.', array_slice($domainPieces, 1));
    $visibleDomain = strlen($domainName) <= 2
        ? substr($domainName, 0, 1)
        : substr($domainName, 0, 2);

    return $visibleLocal . str_repeat('*', max(2, strlen($localPart) - strlen($visibleLocal)))
        . '@'
        . $visibleDomain . str_repeat('*', max(2, strlen($domainName) - strlen($visibleDomain)))
        . ($domainSuffix !== '' ? '.' . $domainSuffix : '');
}

function book_system_email_password_reset_enabled(mysqli $conn): bool {
    $fromEmail = '';
    if (function_exists('book_system_get_password_reset_sender_email')) {
        $fromEmail = book_system_get_password_reset_sender_email($conn);
    }

    return function_exists('mail') && $fromEmail !== '';
}

function book_system_get_password_reset_sender_email(mysqli $conn): string {
    $value = getenv('BOOK_SYSTEM_PASSWORD_RESET_FROM_EMAIL');
    if (is_string($value) && trim($value) !== '') {
        return book_system_normalize_recovery_email($value);
    }

    if (function_exists('book_system_get_app_meta')) {
        return book_system_normalize_recovery_email(book_system_get_app_meta($conn, 'password_reset_from_email', ''));
    }

    return '';
}

function book_system_get_password_reset_sender_name(mysqli $conn): string {
    $value = getenv('BOOK_SYSTEM_PASSWORD_RESET_FROM_NAME');
    if (is_string($value) && trim($value) !== '') {
        return substr(trim($value), 0, 80);
    }

    if (function_exists('book_system_get_app_meta')) {
        $metaValue = trim(book_system_get_app_meta($conn, 'password_reset_from_name', 'ClassBookHub'));
        return $metaValue !== '' ? substr($metaValue, 0, 80) : 'ClassBookHub';
    }

    return 'ClassBookHub';
}

function book_system_generate_email_reset_code(): string {
    try {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        return str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

function book_system_send_password_reset_email(mysqli $conn, string $email, string $code, string $displayName = ''): array {
    $email = book_system_normalize_recovery_email($email);
    if ($email === '') {
        return ['success' => false, 'message' => 'A valid recovery email is required.'];
    }

    if (!function_exists('mail')) {
        return ['success' => false, 'message' => 'Email delivery is not available on this server.'];
    }

    $fromEmail = book_system_get_password_reset_sender_email($conn);
    if ($fromEmail === '') {
        return ['success' => false, 'message' => 'Recovery email sending is not configured yet.'];
    }

    $fromName = book_system_get_password_reset_sender_name($conn);
    $subject = 'Your password reset code';
    $displayName = trim($displayName);
    $greetingName = $displayName !== '' ? $displayName : 'there';
    $message = "Hello {$greetingName},\r\n\r\n"
        . "Use this password reset code to continue: {$code}\r\n\r\n"
        . "This code will expire in 10 minutes. If you did not request this reset, you can ignore this email.\r\n\r\n"
        . "Regards,\r\n{$fromName}";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: PHP/' . phpversion(),
    ];

    $sent = @mail($email, $subject, $message, implode("\r\n", $headers));
    if (!$sent) {
        return ['success' => false, 'message' => 'Could not send the recovery email. Please contact the administrator.'];
    }

    return ['success' => true];
}

function book_system_lookup_password_reset_account(mysqli $conn, string $accountType, string $username): ?array {
    $accountType = trim($accountType);
    $username = trim($username);
    if ($username === '') {
        return null;
    }

    if ($accountType === 'lecturer') {
        $stmt = $conn->prepare("SELECT lecturer_id AS user_id, username, full_name, phone_number, is_active
            FROM lecturers
            WHERE username = ?
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();
        if (!$row || intval($row['is_active'] ?? 0) !== 1) {
            return null;
        }

        $phone = book_system_normalize_ghana_phone(strval($row['phone_number'] ?? ''));
        if ($phone === '') {
            return null;
        }

        return [
            'account_type' => 'lecturer',
            'user_id' => intval($row['user_id'] ?? 0),
            'username' => strval($row['username'] ?? ''),
            'display_name' => strval($row['full_name'] ?? ''),
            'phone_e164' => $phone,
            'masked_phone' => book_system_mask_phone($phone),
        ];
    }

    $stmt = $conn->prepare("SELECT admin_id AS user_id, username, full_name, role, momo_number, recovery_email, is_active
        FROM admins
        WHERE username = ?
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || intval($row['is_active'] ?? 0) !== 1) {
        return null;
    }

    $role = strval($row['role'] ?? '');
    if (!in_array($role, ['super_admin', 'rep', 'temporary_admin'], true)) {
        return null;
    }

    $recoveryEmail = book_system_normalize_recovery_email(strval($row['recovery_email'] ?? ''));
    $phone = book_system_normalize_ghana_phone(strval($row['momo_number'] ?? ''));
    if ($recoveryEmail === '' && $phone === '') {
        return null;
    }

    $deliveryMethod = $recoveryEmail !== '' ? 'email' : 'sms';

    return [
        'account_type' => 'admin',
        'user_id' => intval($row['user_id'] ?? 0),
        'username' => strval($row['username'] ?? ''),
        'display_name' => strval($row['full_name'] ?? ''),
        'recovery_email' => $recoveryEmail,
        'masked_email' => book_system_mask_email($recoveryEmail),
        'phone_e164' => $phone,
        'masked_phone' => book_system_mask_phone($phone),
        'role' => $role,
        'delivery_method' => $deliveryMethod,
    ];
}

function book_system_apply_password_reset(mysqli $conn, string $accountType, int $userId, string $newPassword): bool {
    if ($userId <= 0 || strlen($newPassword) < 6) {
        return false;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    if ($accountType === 'lecturer') {
        $stmt = $conn->prepare("UPDATE lecturers SET password_hash = ? WHERE lecturer_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $newHash, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    $stmt = $conn->prepare("UPDATE admins
        SET password_hash = ?
        WHERE admin_id = ?
        LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $newHash, $userId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}




