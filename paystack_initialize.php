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
$isCheckoutMode = ($requestId <= 0 && $checkoutToken !== '');
if ($requestId <= 0 && !$isCheckoutMode) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$requestRow = null;
if ($isCheckoutMode) {
    $checkoutData = function_exists('book_system_get_paystack_checkout')
        ? book_system_get_paystack_checkout($checkoutToken, function_exists('book_system_paystack_checkout_ttl') ? book_system_paystack_checkout_ttl() : 1800)
        : null;
    if (!is_array($checkoutData)) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'This payment session expired. Please start again.']);
        exit;
    }

    $existingRequestId = intval($checkoutData['request_id'] ?? 0);
    if ($existingRequestId > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Payment already verified for this request.',
            'request_id' => $existingRequestId,
            'already_created' => true,
        ]);
        exit;
    }

    $requestRow = [
        'request_id' => 0,
        'total_amount' => round(floatval($checkoutData['total_amount'] ?? 0), 2),
        'amount_paid' => 0,
        'credit_used' => round(floatval($checkoutData['credit_used'] ?? 0), 2),
        'payment_status' => 'unpaid',
        'admin_id' => intval($checkoutData['rep_id'] ?? 0),
        'index_number' => trim(strval($checkoutData['index_number'] ?? '')),
        'full_name' => trim(strval($checkoutData['full_name'] ?? '')),
        'payment_reference' => trim(strval($checkoutData['verified_reference'] ?? '')),
        'payment_gateway' => 'paystack',
    ];
} else {
    $stmt = $conn->prepare("SELECT
            r.request_id,
            r.total_amount,
            r.amount_paid,
            r.credit_used,
            r.payment_status,
            r.admin_id,
            s.index_number,
            s.full_name
        FROM requests r
        JOIN students s ON s.student_id = r.student_id
        WHERE r.request_id = ?
        LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not open the request for payment.']);
        exit;
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $result = $stmt->get_result();
    $requestRow = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$requestRow) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        exit;
    }
}

$repAdminId = intval($requestRow['admin_id'] ?? 0);
$paymentSettings = book_system_get_admin_payment_settings($conn, $repAdminId, true);
if (($paymentSettings['effective_method'] ?? '') !== 'paystack' || empty($paymentSettings['has_paystack_keys'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => ($paymentSettings['fallback_warning'] ?? '') !== ''
            ? strval($paymentSettings['fallback_warning'])
            : 'Online payment is not available for this rep right now.',
    ]);
    exit;
}

$breakdown = book_system_get_request_payment_breakdown($requestRow, $paymentSettings, $conn);
$paymentStatus = strtolower(trim(strval($requestRow['payment_status'] ?? 'unpaid')));
if ($paymentStatus === 'paid' || !empty($breakdown['is_paid']) || floatval($breakdown['total_payable'] ?? 0) <= 0) {
    echo json_encode(['success' => false, 'message' => 'This request is already fully paid.']);
    exit;
}

$reference = $isCheckoutMode
    ? book_system_generate_paystack_checkout_reference($checkoutToken)
    : book_system_generate_paystack_reference($requestId);
$amountMinor = intval(round(floatval($breakdown['total_payable'] ?? 0) * 100));
$email = book_system_build_request_payment_email(
    strval($requestRow['index_number'] ?? ''),
    strval($requestRow['full_name'] ?? '')
);

$scheme = book_system_is_https() ? 'https' : 'http';
$host = trim(strval($_SERVER['HTTP_HOST'] ?? ''));
$scriptDir = str_replace('\\', '/', dirname(strval($_SERVER['SCRIPT_NAME'] ?? '/')));
$scriptDir = rtrim($scriptDir, '/');
$callbackUrl = ($host !== '')
    ? $scheme . '://' . $host . $scriptDir . '/payment_instructions.php?' . ($isCheckoutMode
        ? 'checkout_token=' . urlencode($checkoutToken)
        : 'request_id=' . $requestId)
    : '';

$payload = [
    'email' => $email,
    'amount' => strval($amountMinor),
    'currency' => strval($paymentSettings['paystack_currency'] ?? 'GHS'),
    'reference' => $reference,
    'callback_url' => $callbackUrl,
    'metadata' => [
        'request_id' => $requestId,
        'checkout_token' => $checkoutToken,
        'student_index' => strval($requestRow['index_number'] ?? ''),
        'student_name' => strval($requestRow['full_name'] ?? ''),
        'rep_admin_id' => $repAdminId,
        'request_balance' => round(floatval($breakdown['balance_due'] ?? 0), 2),
        'handling_charge' => round(floatval($breakdown['handling_charge'] ?? 0), 2),
        'paystack_fee' => round(floatval($breakdown['paystack_fee'] ?? 0), 2),
        'processing_fee' => round(floatval($breakdown['processing_fee'] ?? 0), 2),
        'expected_settlement' => round(floatval($breakdown['expected_settlement'] ?? 0), 2),
        'source' => 'classbookhub_request_portal',
    ],
];

$initializeResult = book_system_paystack_initialize_transaction($conn, $payload, $repAdminId);
if (empty($initializeResult['success'])) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => strval($initializeResult['message'] ?? 'Could not initialize the Paystack payment.'),
    ]);
    exit;
}

$gatewayData = is_array($initializeResult['data'] ?? null) ? $initializeResult['data'] : [];
$gatewayReference = strval($gatewayData['reference'] ?? $reference);

if ($isCheckoutMode) {
    if (function_exists('book_system_update_paystack_checkout')) {
        book_system_update_paystack_checkout($checkoutToken, [
            'initialized_reference' => $gatewayReference,
            'initialized_at' => time(),
        ], function_exists('book_system_paystack_checkout_ttl') ? book_system_paystack_checkout_ttl() : 1800);
    }
} else {
    $metaUpdate = $conn->prepare("UPDATE requests SET payment_reference = ?, payment_gateway = 'paystack' WHERE request_id = ? LIMIT 1");
    if ($metaUpdate) {
        $metaUpdate->bind_param('si', $gatewayReference, $requestId);
        $metaUpdate->execute();
        $metaUpdate->close();
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Payment initialized successfully.',
    'reference' => $gatewayReference,
    'access_code' => strval($gatewayData['access_code'] ?? ''),
    'authorization_url' => strval($gatewayData['authorization_url'] ?? ''),
    'public_key' => strval($paymentSettings['paystack_public_key'] ?? ''),
    'amount_display' => 'GHS ' . number_format(floatval($breakdown['total_payable'] ?? 0), 2),
    'request_id' => $requestId,
    'checkout_token' => $checkoutToken,
]);
