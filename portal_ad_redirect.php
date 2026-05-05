<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();

require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'portal_ads', 'click_count', 'INT NOT NULL DEFAULT 0 AFTER view_count');
        book_system_setup_ensure_column($conn, 'portal_ads', 'last_clicked_at', 'DATETIME NULL AFTER last_viewed_at');
    }
}

function portal_ad_normalize_target(?string $url): string {
    $value = trim(strval($url ?? ''));
    if ($value === '') {
        return '';
    }

    if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)) {
        $value = 'https://' . ltrim($value, '/');
    }

    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

$ad_id = intval($_GET['ad_id'] ?? 0);
if ($ad_id <= 0) {
    header('Location: common_request_portal.php');
    exit;
}

$target_url = '';
$stmt = $conn->prepare("SELECT link_url FROM portal_ads WHERE ad_id = ? AND is_active = 1 LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $ad_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
        $target_url = portal_ad_normalize_target($res->fetch_assoc()['link_url'] ?? '');
    }
    $stmt->close();
}

if ($target_url === '') {
    header('Location: common_request_portal.php');
    exit;
}

$session_clicks = isset($_SESSION['portal_ad_clicks']) && is_array($_SESSION['portal_ad_clicks']) ? $_SESSION['portal_ad_clicks'] : [];
$session_clicks[$ad_id] = time();
$_SESSION['portal_ad_clicks'] = $session_clicks;

$update = $conn->prepare("UPDATE portal_ads SET click_count = click_count + 1, last_clicked_at = NOW() WHERE ad_id = ? LIMIT 1");
if ($update) {
    $update->bind_param('i', $ad_id);
    $update->execute();
    $update->close();
}

header('Location: ' . $target_url);
exit;

