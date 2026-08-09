<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/app_helpers.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'payment_method', "VARCHAR(20) NOT NULL DEFAULT 'manual_momo' AFTER account_name");
        book_system_setup_ensure_column($conn, 'admins', 'momo_network', 'VARCHAR(30) NULL AFTER account_name');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER momo_network');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_public_key', 'VARCHAR(255) NULL AFTER paystack_enabled');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_secret_key', 'VARCHAR(255) NULL AFTER paystack_public_key');
        book_system_setup_ensure_column($conn, 'requests', 'payment_reference', 'VARCHAR(120) NULL AFTER credit_used');
        book_system_setup_ensure_column($conn, 'requests', 'payment_gateway', 'VARCHAR(30) NULL AFTER payment_reference');
        book_system_setup_ensure_column($conn, 'requests', 'payment_verified_at', 'DATETIME NULL AFTER payment_gateway');
    }
}

book_system_secure_session_start();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Please refresh and try again.']);
    exit;
}

$requestId = intval($_POST['request_id'] ?? 0);
$checkoutToken = trim(strval($_POST['checkout_token'] ?? ''));
$reference = trim(strval($_POST['reference'] ?? ''));

if (($requestId <= 0 && $checkoutToken === '') || $reference === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing payment verification details.']);
    exit;
}

$isCheckoutMode = ($checkoutToken !== '');
$requestRow = null;
$repAdminId = 0;

if ($isCheckoutMode) {
    $checkoutData = function_exists('book_system_get_paystack_checkout')
        ? book_system_get_paystack_checkout($checkoutToken, function_exists('book_system_paystack_checkout_ttl') ? book_system_paystack_checkout_ttl() : 1800)
        : null;
    if (!is_array($checkoutData)) {
        http_response_code(410);
        echo json_encode([
            'success' => false,
            'status' => 'expired',
            'message' => 'This payment session expired. Please start the request again.',
        ]);
        exit;
    }

    $requestId = intval($checkoutData['request_id'] ?? 0);
    $repAdminId = intval($checkoutData['rep_id'] ?? 0);
} else {
    $requestStmt = $conn->prepare("SELECT admin_id, payment_status FROM requests WHERE request_id = ? LIMIT 1");
    if (!$requestStmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not open the payment request.']);
        exit;
    }
    $requestStmt->bind_param('i', $requestId);
    $requestStmt->execute();
    $requestRow = $requestStmt->get_result()->fetch_assoc();
    $requestStmt->close();

    if (!$requestRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        exit;
    }

    $repAdminId = intval($requestRow['admin_id'] ?? 0);
}

$paymentSettings = book_system_get_admin_payment_settings($conn, $repAdminId, true);
if (($paymentSettings['effective_method'] ?? '') !== 'paystack' || empty($paymentSettings['has_paystack_keys'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'status' => 'manual_only',
        'message' => ($paymentSettings['fallback_warning'] ?? '') !== ''
            ? strval($paymentSettings['fallback_warning'])
            : 'Online payment is not available for this rep right now.',
    ]);
    exit;
}

$verifyResult = book_system_paystack_verify_transaction($conn, $reference, $repAdminId);
if (empty($verifyResult['success'])) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'status' => 'pending',
        'message' => strval($verifyResult['message'] ?? 'We could not verify the payment right now.'),
    ]);
    exit;
}

$verificationData = is_array($verifyResult['data'] ?? null) ? $verifyResult['data'] : [];
$gatewayStatus = strtolower(trim(strval($verificationData['status'] ?? '')));
if ($gatewayStatus !== 'success') {
    echo json_encode([
        'success' => false,
        'status' => $gatewayStatus !== '' ? $gatewayStatus : 'pending',
        'message' => $gatewayStatus === 'abandoned'
            ? 'The payment was not completed.'
            : 'The payment is not yet completed.',
    ]);
    exit;
}

$applyResult = $isCheckoutMode
    ? book_system_create_verified_paystack_request($conn, $checkoutToken, $reference, $verificationData)
    : book_system_apply_verified_request_payment($conn, $requestId, $reference, $verificationData);
echo json_encode([
    'success' => !empty($applyResult['success']),
    'already_paid' => !empty($applyResult['already_paid']),
    'already_created' => !empty($applyResult['already_created']),
    'request_id' => intval($applyResult['request_id'] ?? $requestId),
    'status' => strval($applyResult['status'] ?? 'pending'),
    'message' => strval($applyResult['message'] ?? 'Unable to confirm this payment yet.'),
]);
