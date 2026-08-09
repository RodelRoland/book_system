<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
define('BOOK_SYSTEM_SKIP_USAGE_TRACKING', true);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required.',
        'notifications' => [],
        'unread_count' => 0,
    ]);
    exit;
}

$adminId = intval($_SESSION['admin_id'] ?? 0);
$role = strval($_SESSION['admin_role'] ?? '');

if ($adminId <= 0 || $role === '') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Notification access is unavailable for this session.',
        'notifications' => [],
        'unread_count' => 0,
    ]);
    exit;
}

$payload = function_exists('book_system_get_unread_notifications')
    ? book_system_get_unread_notifications($conn, $adminId, $role, 20)
    : ['count' => 0, 'items' => []];

echo json_encode([
    'success' => true,
    'notifications' => array_values(is_array($payload['items'] ?? null) ? $payload['items'] : []),
    'unread_count' => intval($payload['count'] ?? 0),
    'server_time' => date('c'),
]);
exit;
