<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
define('BOOK_SYSTEM_SKIP_USAGE_TRACKING', true);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request token.']);
    exit;
}

$notificationId = intval($_POST['notification_id'] ?? 0);
$adminId = intval($_SESSION['admin_id'] ?? 0);
$role = strval($_SESSION['admin_role'] ?? '');

if ($notificationId <= 0 || $adminId <= 0 || $role === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid notification request.']);
    exit;
}

$marked = function_exists('book_system_mark_notification_read')
    ? book_system_mark_notification_read($conn, $notificationId, $adminId, $role)
    : false;

$payload = function_exists('book_system_get_unread_notifications')
    ? book_system_get_unread_notifications($conn, $adminId, $role, 20)
    : ['count' => 0, 'items' => []];

echo json_encode([
    'success' => $marked,
    'message' => $marked ? 'Notification marked as read.' : 'Notification was already read or unavailable.',
    'unread_count' => intval($payload['count'] ?? 0),
]);
exit;
