<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

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

$requestId = intval($_REQUEST['request_id'] ?? 0);
$checkoutToken = trim(strval($_REQUEST['checkout_token'] ?? ''));
$isResumeCheckoutFlow = isset($_GET['resume_checkout']) && strval($_GET['resume_checkout']) === '1';
$isRefreshStatusRequest = strval($_GET['refresh'] ?? '') === 'status';
$isCheckoutMode = ($requestId <= 0 && $checkoutToken !== '');
if ($requestId <= 0 && !$isCheckoutMode) {
    http_response_code(400);
    exit('Invalid payment request.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isCheckoutMode && strval($_POST['action'] ?? '') === 'cancel_checkout') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your session expired. Please refresh and try again.');
    }

    $checkoutData = function_exists('book_system_get_paystack_checkout')
        ? book_system_get_paystack_checkout($checkoutToken, function_exists('book_system_paystack_checkout_ttl') ? book_system_paystack_checkout_ttl() : 1800)
        : null;
    if (is_array($checkoutData)) {
        $fingerprint = trim(strval($checkoutData['fingerprint'] ?? ''));
        if ($fingerprint !== '' && function_exists('book_system_forget_submission_result')) {
            book_system_forget_submission_result('portal_request', $fingerprint);
        }
        if (function_exists('book_system_remove_paystack_checkout')) {
            book_system_remove_paystack_checkout($checkoutToken);
        }
        $_SESSION['portal_request_flash'] = [
            'type' => 'info',
            'message' => 'Your unfinished payment was cancelled. You can start a new request now.',
        ];
    }

    $repRedirectId = intval($checkoutData['rep_id'] ?? 0);
    $redirectTarget = $repRedirectId > 0 ? 'index.php?rep_id=' . $repRedirectId : 'common_request_portal.php';
    header('Location: ' . $redirectTarget, true, 303);
    exit;
}

$data = [];
$repAdminId = 0;
$studentName = 'Student';
$indexNumber = '';
$paymentSettings = ['effective_method' => 'unavailable'];
$breakdown = [
    'subtotal' => 0,
    'credit_used' => 0,
    'amount_paid' => 0,
    'balance_due' => 0,
    'handling_charge' => 0,
    'processing_fee' => 0,
    'paystack_fee' => 0,
    'total_payable' => 0,
    'is_paid' => false,
    'has_credit_cover' => false,
    'expected_settlement' => 0,
];
$paymentSummaryItems = [];
$paymentSummaryCount = 0;
$checkoutSelectedBookIds = [];
$manualReference = '';
$storedReference = '';
$displayReference = '';
$effectiveMethod = 'unavailable';
$isPaid = false;
$hasCreditCover = false;
$csrfToken = csrf_get_token();
$statusNotice = '';
$statusTone = 'info';
$repName = '';
$isResumeFlow = isset($_GET['resume']) && strval($_GET['resume']) === '1';

if ($isCheckoutMode) {
    $checkoutData = function_exists('book_system_get_paystack_checkout')
        ? book_system_get_paystack_checkout($checkoutToken, function_exists('book_system_paystack_checkout_ttl') ? book_system_paystack_checkout_ttl() : 1800)
        : null;
    if (!is_array($checkoutData)) {
        http_response_code(410);
        exit('This payment session expired. Please start the request again.');
    }

    $existingRequestId = intval($checkoutData['request_id'] ?? 0);
    if ($existingRequestId > 0) {
        header('Location: payment_instructions.php?request_id=' . $existingRequestId, true, 303);
        exit;
    }

    $data = [
        'request_id' => 0,
        'total_amount' => round(floatval($checkoutData['total_amount'] ?? 0), 2),
        'amount_paid' => 0,
        'credit_used' => round(floatval($checkoutData['credit_used'] ?? 0), 2),
        'payment_status' => 'unpaid',
        'admin_id' => intval($checkoutData['rep_id'] ?? 0),
        'payment_reference' => '',
        'payment_gateway' => 'paystack',
        'index_number' => trim(strval($checkoutData['index_number'] ?? '')),
        'full_name' => trim(strval($checkoutData['full_name'] ?? 'Student')),
        'credit_balance' => 0,
    ];
    $checkoutSelectedBookIds = array_values(array_unique(array_filter(array_map('intval', is_array($checkoutData['selected_book_ids'] ?? null) ? $checkoutData['selected_book_ids'] : []))));
} else {
    $requestStmt = $conn->prepare("SELECT
            r.request_id,
            r.total_amount,
            r.amount_paid,
            r.credit_used,
            r.payment_status,
            r.admin_id,
            r.payment_reference,
            r.payment_gateway,
            s.index_number,
            s.full_name,
            s.credit_balance
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        WHERE r.request_id = ?
        LIMIT 1");
    $requestQuery = null;
    if ($requestStmt) {
        $requestStmt->bind_param('i', $requestId);
        $requestStmt->execute();
        $requestQuery = $requestStmt->get_result();
    }

    if (!$requestQuery || $requestQuery->num_rows === 0) {
        http_response_code(404);
        exit('Payment record not found.');
    }

    $data = $requestQuery->fetch_assoc();
    $requestStmt->close();
}

$repAdminId = intval($data['admin_id'] ?? 0);
$studentName = trim(strval($data['full_name'] ?? 'Student'));
$indexNumber = trim(strval($data['index_number'] ?? ''));
$paymentSettings = book_system_get_admin_payment_settings($conn, $repAdminId);
$breakdown = book_system_get_request_payment_breakdown($data, $paymentSettings, $conn);
$manualReference = $requestId > 0
    ? book_system_get_request_manual_reference($indexNumber, $requestId)
    : book_system_generate_paystack_checkout_reference($checkoutToken);
$storedReference = trim(strval($data['payment_reference'] ?? ''));
$displayReference = $storedReference !== '' ? $storedReference : $manualReference;
$effectiveMethod = strval($paymentSettings['effective_method'] ?? 'unavailable');
$isPaid = !empty($breakdown['is_paid']);
$hasCreditCover = !empty($breakdown['has_credit_cover']);

if ($isCheckoutMode && !$isPaid && $effectiveMethod !== 'paystack') {
    $redirectTarget = $repAdminId > 0 ? 'index.php?rep_id=' . $repAdminId : 'common_request_portal.php';
    if ($isRefreshStatusRequest) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        echo json_encode([
            'success' => true,
            'changed' => true,
            'redirect_url' => $redirectTarget,
            'message' => $effectiveMethod === 'manual_momo'
                ? 'Paystack is no longer enabled for this rep. Please continue with the rep\'s MoMo payment details instead.'
                : 'This Paystack payment session is no longer available. Please start the request again.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (function_exists('book_system_remove_paystack_checkout')) {
        book_system_remove_paystack_checkout($checkoutToken);
    }
    $_SESSION['portal_request_flash'] = [
        'type' => 'info',
        'message' => $effectiveMethod === 'manual_momo'
            ? 'Paystack is no longer enabled for this rep. Please continue with the rep\'s MoMo payment details instead.'
            : 'This Paystack payment session is no longer available. Please start the request again.',
    ];

    header('Location: ' . $redirectTarget, true, 303);
    exit;
}

if ($repAdminId > 0) {
    $repStmt = $conn->prepare("SELECT full_name FROM admins WHERE admin_id = ? LIMIT 1");
    if ($repStmt) {
        $repStmt->bind_param('i', $repAdminId);
        $repStmt->execute();
        $repName = trim(strval($repStmt->get_result()->fetch_assoc()['full_name'] ?? ''));
        $repStmt->close();
    }
}

if ($isCheckoutMode) {
    if (!empty($checkoutSelectedBookIds)) {
        $placeholders = implode(',', array_fill(0, count($checkoutSelectedBookIds), '?'));
        $summarySql = "SELECT book_title FROM books WHERE book_id IN ($placeholders) ORDER BY book_title ASC";
        $summaryStmt = $conn->prepare($summarySql);
        if ($summaryStmt) {
            $summaryTypes = str_repeat('i', count($checkoutSelectedBookIds));
            $summaryStmt->bind_param($summaryTypes, ...$checkoutSelectedBookIds);
            $summaryStmt->execute();
            $summaryResult = $summaryStmt->get_result();
            while ($summaryResult && ($summaryRow = $summaryResult->fetch_assoc())) {
                $title = trim(strval($summaryRow['book_title'] ?? ''));
                if ($title !== '') {
                    $paymentSummaryItems[] = $title;
                }
            }
            $summaryStmt->close();
        }
    }
} else {
    $summaryStmt = $conn->prepare("SELECT b.book_title
        FROM request_items ri
        JOIN books b ON b.book_id = ri.book_id
        WHERE ri.request_id = ? AND COALESCE(ri.is_cancelled, 0) = 0
        ORDER BY b.book_title ASC");
    if ($summaryStmt) {
        $summaryStmt->bind_param('i', $requestId);
        $summaryStmt->execute();
        $summaryResult = $summaryStmt->get_result();
        while ($summaryResult && ($summaryRow = $summaryResult->fetch_assoc())) {
            $title = trim(strval($summaryRow['book_title'] ?? ''));
            if ($title !== '') {
                $paymentSummaryItems[] = $title;
            }
        }
        $summaryStmt->close();
    }
}
$paymentSummaryCount = count($paymentSummaryItems);

if ($paymentSettings['fallback_warning'] !== '') {
    $statusNotice = $paymentSettings['fallback_warning'];
    $statusTone = ($effectiveMethod === 'unavailable') ? 'warning' : 'info';
}

if ($isCheckoutMode && !$isPaid && $effectiveMethod === 'paystack' && $statusNotice === '') {
    $statusNotice = $isResumeCheckoutFlow
        ? 'You have an unfinished payment for these books. Continue your payment below or cancel to start over.'
        : 'Complete your Paystack payment below. Your request will be created only after payment is verified.';
    $statusTone = 'info';
} elseif (!$isPaid && $isResumeFlow && $effectiveMethod === 'paystack' && $statusNotice === '') {
    $statusNotice = 'An unpaid request already exists for these books. Continue your payment below.';
    $statusTone = 'info';
}

if (!$isPaid && isset($_GET['reference']) && $effectiveMethod === 'paystack') {
    $reference = trim(strval($_GET['reference'] ?? ''));
    if ($reference !== '') {
        $verifyResult = book_system_paystack_verify_transaction($conn, $reference, $repAdminId);
        if (!empty($verifyResult['success'])) {
            $verificationPayload = is_array($verifyResult['data'] ?? null) ? $verifyResult['data'] : [];
            $applyResult = $isCheckoutMode
                ? book_system_create_verified_paystack_request($conn, $checkoutToken, $reference, $verificationPayload)
                : book_system_apply_verified_request_payment($conn, $requestId, $reference, $verificationPayload);
            $statusNotice = strval($applyResult['message'] ?? '');
            $statusTone = !empty($applyResult['success']) ? 'success' : 'warning';
            if (!empty($applyResult['success'])) {
                if ($isCheckoutMode && intval($applyResult['request_id'] ?? 0) > 0) {
                    header('Location: payment_instructions.php?request_id=' . intval($applyResult['request_id']), true, 303);
                    exit;
                }

                $isPaid = true;
                $displayReference = $reference;
                $data['payment_status'] = 'paid';
                $data['amount_paid'] = $breakdown['request_balance'] ?? ($breakdown['balance_due'] ?? 0);
                $data['payment_reference'] = $reference;
                $data['payment_gateway'] = 'paystack';
                $breakdown = book_system_get_request_payment_breakdown($data, $paymentSettings, $conn);
            }
        } else {
            $statusNotice = strval($verifyResult['message'] ?? 'We could not confirm the Paystack payment yet.');
            $statusTone = 'warning';
        }
    }
}

$portalHomeHref = 'common_request_portal.php';
$paystackEnabled = (!$isPaid && $effectiveMethod === 'paystack' && $breakdown['total_payable'] > 0 && !empty($paymentSettings['has_paystack_keys']));
$refreshState = [
    'request_id' => $requestId,
    'checkout_token' => $checkoutToken,
    'effective_method' => $effectiveMethod,
    'payment_status' => strval($data['payment_status'] ?? ''),
    'payment_gateway' => strval($data['payment_gateway'] ?? ''),
    'payment_reference' => $displayReference,
    'is_paid' => $isPaid ? 1 : 0,
    'total_payable' => round(floatval($breakdown['total_payable'] ?? 0), 2),
    'amount_paid' => round(floatval($data['amount_paid'] ?? 0), 2),
    'status_notice' => $statusNotice,
    'paystack_enabled' => $paystackEnabled ? 1 : 0,
];
$refreshStateToken = sha1(json_encode($refreshState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if ($isRefreshStatusRequest) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode([
        'success' => true,
        'state_token' => $refreshStateToken,
        'is_paid' => $isPaid,
        'effective_method' => $effectiveMethod,
        'redirect_url' => '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Payment</title>
    <link rel="stylesheet" href="style.css">
    <?php if ($paystackEnabled): ?>
    <script src="https://js.paystack.co/v2/inline.js"></script>
    <?php endif; ?>
    <style>
        body {
            background:
                radial-gradient(circle at top, rgba(59, 130, 246, 0.12), transparent 40%),
                linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
            min-height: 100vh;
        }
        .payment-shell { max-width: 820px; margin: 0 auto; padding: 26px 18px 44px; }
        .payment-topbar { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:18px; }
        .topbar-link {
            display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:12px 16px;
            border-radius:999px; border:1px solid rgba(148,163,184,0.22); background:rgba(255,255,255,0.92);
            color:#0f172a; font-weight:700; text-decoration:none; box-shadow:0 16px 32px rgba(15,23,42,0.08);
        }
        .payment-hero {
            padding:24px; border-radius:28px; background:linear-gradient(135deg, #0f172a 0%, #1d4ed8 55%, #3b82f6 100%);
            color:#fff; box-shadow:0 28px 60px rgba(30,64,175,0.26); margin-bottom:18px;
        }
        .payment-kicker { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:999px; background:rgba(255,255,255,0.14); font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; }
        .payment-hero h1 { margin:14px 0 8px; font-size:clamp(1.7rem, 4vw, 2.35rem); line-height:1.08; }
        .payment-hero p { margin:0; max-width:560px; color:rgba(255,255,255,0.85); font-size:0.98rem; }
        .student-summary-card { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; padding:16px 18px; border-radius:22px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.14); margin-top:20px; }
        .summary-label { display:block; margin-bottom:6px; font-size:12px; letter-spacing:0.03em; text-transform:uppercase; color:rgba(255,255,255,0.7); }
        .summary-value { font-size:1rem; font-weight:700; color:#fff; word-break:break-word; }
        .status-banner { display:none; margin-bottom:18px; padding:14px 16px; border-radius:18px; border:1px solid transparent; box-shadow:0 18px 42px rgba(15,23,42,0.08); }
        .status-banner.show { display:block; }
        .status-banner.success { background:#ecfdf3; color:#166534; border-color:rgba(34,197,94,0.18); }
        .status-banner.warning { background:#fff7ed; color:#9a3412; border-color:rgba(249,115,22,0.18); }
        .status-banner.info { background:#eff6ff; color:#1d4ed8; border-color:rgba(59,130,246,0.18); }
        .payment-grid { display:grid; gap:18px; }
        .payment-panel { padding:22px; border-radius:24px; background:rgba(255,255,255,0.96); border:1px solid rgba(148,163,184,0.18); box-shadow:0 26px 56px rgba(15,23,42,0.10); }
        .payment-panel h2, .payment-panel h3 { margin:0 0 10px; color:#0f172a; }
        .payment-panel p { margin:0; color:#64748b; line-height:1.6; }
        .money-grid { display:grid; gap:12px; margin-top:18px; }
        .money-row { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 16px; border-radius:16px; background:#f8fafc; border:1px solid rgba(226,232,240,0.9); }
        .money-row strong { color:#0f172a; font-size:1rem; }
        .money-row.emphasis { background:linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-color:rgba(59,130,246,0.20); }
        .money-row.success { background:linear-gradient(135deg, #ecfdf3 0%, #dcfce7 100%); border-color:rgba(34,197,94,0.20); }
        .summary-item-list { display:grid; gap:10px; margin-top:18px; }
        .summary-item-card {
            padding:14px 16px; border-radius:16px; background:#f8fafc;
            border:1px solid rgba(226,232,240,0.9);
        }
        .summary-item-card strong { display:block; color:#0f172a; font-size:0.98rem; line-height:1.4; }
        .summary-item-card span { display:block; margin-top:4px; color:#64748b; font-size:0.85rem; }
        .summary-note { margin-top:14px; font-size:13px; color:#475569; }
        .payment-method-card { padding:20px; border-radius:22px; background:#fff; border:1px solid rgba(226,232,240,0.9); box-shadow:0 18px 38px rgba(15,23,42,0.06); }
        .payment-method-card.primary { border-color:rgba(37,99,235,0.18); background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); }
        .payment-method-card.manual { border-color:rgba(14,165,233,0.16); background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%); }
        .method-badge { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:999px; background:rgba(37,99,235,0.10); color:#1d4ed8; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; }
        .method-amount { margin-top:16px; font-size:clamp(1.65rem, 5vw, 2.4rem); font-weight:800; color:#0f172a; }
        .method-meta { margin-top:12px; display:grid; gap:10px; }
        .method-meta-row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:12px 14px; border-radius:14px; background:#f8fafc; color:#0f172a; }
        .method-meta-row span:first-child { color:#64748b; }
        .paystack-button, .secondary-button { width:100%; margin-top:18px; padding:16px 18px; border:none; border-radius:18px; cursor:pointer; font-size:1rem; font-weight:800; transition:transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease; }
        .paystack-button { background:linear-gradient(135deg, #1d4ed8 0%, #2563eb 55%, #3b82f6 100%); color:#fff; box-shadow:0 20px 38px rgba(37,99,235,0.30); }
        .secondary-button { background:#eff6ff; color:#1d4ed8; box-shadow:inset 0 0 0 1px rgba(37,99,235,0.12); }
        .paystack-button:hover, .secondary-button:hover, .topbar-link:hover { transform:translateY(-1px); }
        .paystack-button[disabled], .secondary-button[disabled] { cursor:wait; opacity:0.72; transform:none; }
        .checkout-actions { display:grid; gap:12px; margin-top:18px; }
        .cancel-checkout-form { margin:0; }
        .cancel-checkout-button { width:100%; padding:14px 16px; border-radius:16px; border:none; cursor:pointer; font-size:0.95rem; font-weight:700; background:#fff1f2; color:#be123c; box-shadow:inset 0 0 0 1px rgba(244,63,94,0.18); }
        .cancel-checkout-button:hover { transform:translateY(-1px); }
        .paid-state { display:grid; gap:16px; }
        .paid-chip { display:inline-flex; align-items:center; justify-content:center; width:fit-content; padding:10px 14px; border-radius:999px; background:#dcfce7; color:#166534; font-size:13px; font-weight:800; letter-spacing:0.03em; text-transform:uppercase; }
        .helper-note { margin-top:14px; font-size:13px; color:#475569; }
        @media (min-width: 760px) { .payment-grid { grid-template-columns: 1.05fr 0.95fr; align-items: start; } }
        @media (max-width: 520px) {
            .payment-shell { padding-inline:14px; }
            .payment-topbar { flex-direction:column; align-items:stretch; }
            .student-summary-card { grid-template-columns:1fr; }
            .money-row, .method-meta-row { flex-direction:column; align-items:flex-start; }
        }
    </style>
</head>
<body>
<div class="payment-shell">
    <div class="payment-topbar">
        <a class="topbar-link" href="<?php echo htmlspecialchars($portalHomeHref); ?>">&larr; Back to Home</a>
        <a class="topbar-link" href="<?php echo htmlspecialchars($portalHomeHref); ?>">Close</a>
    </div>

    <section class="payment-hero">
        <span class="payment-kicker">Request Payment</span>
        <h1><?php echo $effectiveMethod === 'manual_momo' ? 'Pay with your rep\'s MoMo details' : ($effectiveMethod === 'paystack' ? 'Review your total and complete payment' : 'Payment details unavailable'); ?></h1>
        <p><?php echo $effectiveMethod === 'manual_momo' ? 'Please send the exact amount shown below. Your rep will confirm the payment after receiving it.' : ($effectiveMethod === 'paystack' ? 'Use the secure Paystack flow below. Your request will be marked as paid after server-side verification.' : 'Please contact your class rep for payment instructions.'); ?></p>
        <div class="student-summary-card">
            <div>
                <span class="summary-label">Student Name</span>
                <div class="summary-value"><?php echo htmlspecialchars($studentName); ?></div>
            </div>
            <div>
                <span class="summary-label">Index Number</span>
                <div class="summary-value"><?php echo htmlspecialchars($indexNumber); ?></div>
            </div>
        </div>
    </section>

    <div id="statusBanner" class="status-banner<?php echo $statusNotice !== '' ? ' show ' . htmlspecialchars($statusTone) : ''; ?>">
        <?php echo htmlspecialchars($statusNotice); ?>
    </div>

    <div class="payment-grid">
        <section class="payment-panel">
            <h2>Payment Summary</h2>
            <p>Review your selected course materials and the final amount to pay.</p>
            <?php if ($paymentSummaryCount > 0): ?>
            <div class="summary-item-list">
                <?php foreach ($paymentSummaryItems as $paymentSummaryItem): ?>
                <div class="summary-item-card">
                    <strong><?php echo htmlspecialchars($paymentSummaryItem); ?></strong>
                    <span>Course material selected</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="money-grid">
                <div class="money-row">
                    <span>Total items</span>
                    <strong><?php echo intval($paymentSummaryCount); ?> <?php echo intval($paymentSummaryCount) === 1 ? 'item' : 'items'; ?></strong>
                </div>
                <div class="money-row emphasis">
                    <span><?php echo $isPaid ? 'Payment Status' : 'Total Payable'; ?></span>
                    <strong id="summaryOutstanding"><?php echo $isPaid ? 'PAID' : 'GHS ' . number_format(floatval($breakdown['total_payable'] ?? 0), 2); ?></strong>
                </div>
            </div>
            <?php if (!$isPaid): ?>
            <p class="summary-note">This amount includes payment processing charges.</p>
            <?php endif; ?>
        </section>

        <section class="payment-panel">
            <?php if ($isPaid): ?>
            <div class="paid-state" id="paidState">
                <span class="paid-chip">Request Paid</span>
                <h3>Payment completed</h3>
                <p><?php echo $hasCreditCover ? 'Your available credit already covered this request.' : 'Your request has been marked as paid successfully.'; ?></p>
                <button type="button" class="secondary-button" onclick="window.location.href='<?php echo htmlspecialchars($portalHomeHref, ENT_QUOTES); ?>'">Back to Home</button>
            </div>
            <?php elseif ($effectiveMethod === 'manual_momo'): ?>
            <h3>Manual MoMo payment</h3>
            <p>Please send the exact amount below and keep the payment reference exactly as shown.</p>
            <div class="payment-method-card manual">
                <span class="method-badge">Manual MoMo</span>
                <div class="method-amount">GHS <?php echo number_format(floatval($breakdown['total_payable'] ?? 0), 2); ?></div>
                <div class="method-meta">
                    <div class="method-meta-row">
                        <span>MoMo Number</span>
                        <strong><?php echo htmlspecialchars(strval($paymentSettings['momo_number'] ?? '')); ?></strong>
                    </div>
                    <div class="method-meta-row">
                        <span>Account Name</span>
                        <strong><?php echo htmlspecialchars(strval($paymentSettings['account_name'] ?? '')); ?></strong>
                    </div>
                    <div class="method-meta-row">
                        <span>Network</span>
                        <strong><?php echo htmlspecialchars(strval($paymentSettings['momo_network'] ?? 'Not specified')); ?></strong>
                    </div>
                    <div class="method-meta-row">
                        <span>Payment Reference</span>
                        <strong><?php echo htmlspecialchars($displayReference); ?></strong>
                    </div>
                </div>
                <p class="helper-note">Please send the exact amount to the number above. Your rep will confirm payment after receiving it<?php echo $repName !== '' ? ' (' . htmlspecialchars($repName) . ')' : ''; ?>.</p>
            </div>
            <?php elseif ($effectiveMethod === 'paystack'): ?>
            <h3>Complete payment with Paystack</h3>
            <p>Use the secure Paystack popup below. Your payment will be verified on the server before this request is marked as paid.</p>
            <div class="payment-method-card primary">
                <span class="method-badge">Paystack</span>
                <div class="method-amount" id="paystackAmount">GHS <?php echo number_format(floatval($breakdown['total_payable'] ?? 0), 2); ?></div>
                <div class="method-meta">
                    <div class="method-meta-row">
                        <span>Reference</span>
                        <strong><?php echo htmlspecialchars($displayReference); ?></strong>
                    </div>
                    <div class="method-meta-row">
                        <span>Rep</span>
                        <strong><?php echo htmlspecialchars($repName !== '' ? $repName : 'Assigned rep'); ?></strong>
                    </div>
                </div>
                <?php if ($paystackEnabled): ?>
                <div class="checkout-actions">
                    <button type="button" class="paystack-button" id="paystackButton"><?php echo $isResumeCheckoutFlow ? 'Continue Payment' : 'Pay with Paystack'; ?></button>
                    <button type="button" class="secondary-button" id="verifyPaystackButton" style="display:none;">I have completed payment</button>
                    <?php if ($isCheckoutMode): ?>
                    <form method="post" class="cancel-checkout-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="checkout_token" value="<?php echo htmlspecialchars($checkoutToken); ?>">
                        <input type="hidden" name="action" value="cancel_checkout">
                        <button type="submit" class="cancel-checkout-button">Cancel</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="status-banner show warning" style="margin-top:18px;">Paystack is not available for this rep right now.</div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <h3>Payment unavailable</h3>
            <p>Payment details are not available for this request right now. Please contact your class rep.</p>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
(function () {
    var portalHomeHref = <?php echo json_encode($portalHomeHref); ?>;

    if (!(window.history && typeof window.history.pushState === 'function' && typeof window.history.replaceState === 'function')) {
        return;
    }

    try {
        window.history.replaceState({
            page: 'payment_instructions',
            requestId: <?php echo intval($requestId); ?>,
            portalHomeHref: portalHomeHref
        }, '', window.location.href);
        window.history.pushState({
            page: 'payment_instructions_guard',
            requestId: <?php echo intval($requestId); ?>,
            portalHomeHref: portalHomeHref
        }, '', window.location.href);

        window.addEventListener('popstate', function () {
            window.location.replace(portalHomeHref);
        });
    } catch (error) {}
})();
</script>

<?php if (!$isPaid): ?>
<script>
(function () {
    var requestId = <?php echo intval($requestId); ?>;
    var checkoutToken = <?php echo json_encode($checkoutToken); ?>;
    var resumeFlag = <?php echo $isResumeFlow ? '1' : '0'; ?>;
    var refreshTimer = null;
    var refreshInFlight = false;
    var lastRefreshAt = 0;
    var currentStateToken = <?php echo json_encode($refreshStateToken); ?>;

    function buildRefreshUrl() {
        if (checkoutToken) {
            return 'payment_instructions.php?checkout_token=' + encodeURIComponent(checkoutToken);
        }
        return 'payment_instructions.php?request_id=' + requestId + (resumeFlag ? '&resume=1' : '');
    }

    function buildStatusUrl() {
        return buildRefreshUrl() + (buildRefreshUrl().indexOf('?') === -1 ? '?' : '&') + 'refresh=status&_rt=' + Date.now();
    }

    function refreshIfChanged() {
        var now = Date.now();
        if (document.visibilityState !== 'visible') {
            return;
        }
        if ((now - lastRefreshAt) < 10000) {
            return;
        }
        if (refreshInFlight) {
            return;
        }
        lastRefreshAt = now;
        refreshInFlight = true;

        fetch(buildStatusUrl(), {
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
            var cleanText = String(rawText || '').replace(/^\uFEFF/, '');
            var payload = JSON.parse(cleanText);
            if (!payload || !payload.success) {
                return;
            }
            if (payload.redirect_url) {
                window.location.replace(String(payload.redirect_url));
                return;
            }
            if (payload.state_token && payload.state_token !== currentStateToken) {
                window.location.replace(buildRefreshUrl());
            }
        })
        .catch(function () {})
        .finally(function () {
            refreshInFlight = false;
        });
    }

    function scheduleRefreshLoop() {
        if (refreshTimer) {
            window.clearInterval(refreshTimer);
        }
        refreshTimer = window.setInterval(function () {
            refreshIfChanged();
        }, 15000);
    }

    window.addEventListener('focus', refreshIfChanged);
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            refreshIfChanged();
        }
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            refreshIfChanged();
        }
    });

    scheduleRefreshLoop();
})();
</script>
<?php endif; ?>

<?php if ($paystackEnabled): ?>
<script>
(function () {
    var requestId = <?php echo intval($requestId); ?>;
    var checkoutToken = <?php echo json_encode($checkoutToken); ?>;
    var repAdminId = <?php echo intval($repAdminId); ?>;
    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var portalHomeHref = <?php echo json_encode($portalHomeHref); ?>;
    var pendingStorageKey = checkoutToken
        ? 'cbh_paystack_checkout_' + checkoutToken
        : 'cbh_paystack_request_' + requestId;
    var paystackButton = document.getElementById('paystackButton');
    var verifyButton = document.getElementById('verifyPaystackButton');
    var statusBanner = document.getElementById('statusBanner');
    var summaryOutstanding = document.getElementById('summaryOutstanding');
    var pollTimer = null;
    var isVerifying = false;

    function buildFinalRequestUrl(id) {
        return 'payment_instructions.php?request_id=' + String(id || requestId || 0);
    }

    function showBanner(message, tone) {
        if (!statusBanner) return;
        statusBanner.textContent = message || '';
        statusBanner.className = 'status-banner show ' + (tone || 'info');
    }

    function setButtonState(isLoading, label) {
        if (!paystackButton) return;
        paystackButton.disabled = !!isLoading;
        paystackButton.textContent = isLoading ? (label || 'Preparing payment...') : 'Pay with Paystack';
    }

    function markPaid(message, paidRequestId) {
        localStorage.removeItem(pendingStorageKey);
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
        if (paidRequestId) {
            showBanner(message || 'Payment verified successfully.', 'success');
            window.setTimeout(function () {
                window.location.replace(buildFinalRequestUrl(paidRequestId));
            }, 400);
            return;
        }
        if (summaryOutstanding) {
            summaryOutstanding.textContent = 'PAID';
        }
        showBanner(message || 'Payment verified successfully.', 'success');
        var paidPanel = document.createElement('div');
        paidPanel.className = 'payment-panel';
        paidPanel.innerHTML = '<div class="paid-state"><span class="paid-chip">Request Paid</span><h3>Payment completed</h3><p>Your request has been marked as paid successfully.</p><button type="button" class="secondary-button" id="returnToRequest">Back to Home</button></div>';
        var rightPanel = document.querySelector('.payment-grid .payment-panel:last-child');
        if (rightPanel) {
            rightPanel.replaceWith(paidPanel);
            var returnButton = document.getElementById('returnToRequest');
            if (returnButton) {
                returnButton.addEventListener('click', function () {
                    window.location.href = portalHomeHref;
                });
            }
        }
    }

    function verifyReference(reference, silent) {
        if (!reference || isVerifying) return;
        isVerifying = true;
        if (!silent) {
            showBanner('Confirming your payment...', 'info');
        }

        fetch('paystack_verify.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept': 'application/json'
            },
            body: (function () {
                var params = new URLSearchParams({
                    csrf_token: csrfToken,
                    rep_admin_id: String(repAdminId),
                    reference: reference
                });
                if (checkoutToken) {
                    params.set('checkout_token', checkoutToken);
                } else {
                    params.set('request_id', String(requestId));
                }
                return params.toString();
            })()
        })
        .then(function (response) { return response.text(); })
        .then(function (rawText) {
            var cleanText = String(rawText || '').replace(/^\uFEFF/, '');
            var payload = JSON.parse(cleanText);
            if (payload && payload.success) {
                markPaid(payload.message || 'Payment verified successfully.', payload.request_id || 0);
                return;
            }
            var status = payload && payload.status ? String(payload.status) : '';
            if (status === 'pending' || status === 'abandoned') {
                if (!silent) {
                    showBanner(payload.message || 'Payment has not been completed yet.', 'warning');
                }
                return;
            }
            showBanner((payload && payload.message) || 'We could not verify the payment right now.', 'error');
        })
        .catch(function () {
            if (!silent) {
                showBanner('We could not verify the payment right now. Please try again.', 'error');
            }
        })
        .finally(function () {
            isVerifying = false;
        });
    }

    function scheduleFocusVerification() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }
        pollTimer = window.setTimeout(function () {
            var pendingReference = localStorage.getItem(pendingStorageKey);
            if (pendingReference) {
                verifyReference(pendingReference, true);
            }
        }, 2500);
    }

    if (verifyButton) {
        verifyButton.addEventListener('click', function () {
            var pendingReference = localStorage.getItem(pendingStorageKey);
            if (pendingReference) {
                verifyReference(pendingReference, false);
            } else {
                showBanner('Start the Paystack payment first, then come back here if needed.', 'warning');
            }
        });
    }

    if (paystackButton) {
        paystackButton.addEventListener('click', function () {
            setButtonState(true, 'Preparing payment...');
            showBanner('Preparing your Paystack checkout...', 'info');

            fetch('paystack_initialize.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: (function () {
                    var params = new URLSearchParams({
                        csrf_token: csrfToken,
                        rep_admin_id: String(repAdminId)
                    });
                    if (checkoutToken) {
                        params.set('checkout_token', checkoutToken);
                    } else {
                        params.set('request_id', String(requestId));
                    }
                    return params.toString();
                })()
            })
            .then(function (response) { return response.text(); })
            .then(function (rawText) {
                var cleanText = String(rawText || '').replace(/^\uFEFF/, '');
                var payload = JSON.parse(cleanText);
                if (!payload || !payload.success || !payload.access_code) {
                    throw new Error((payload && payload.message) || 'Could not initialize the payment.');
                }
                localStorage.setItem(pendingStorageKey, String(payload.reference || ''));
                if (verifyButton) {
                    verifyButton.style.display = 'block';
                }
                showBanner('Paystack checkout opened. Complete the payment, then we will verify it automatically.', 'info');
                if (window.Paystack) {
                    var popup = new window.Paystack();
                    popup.resumeTransaction(payload.access_code);
                    scheduleFocusVerification();
                } else if (payload.authorization_url) {
                    window.location.href = payload.authorization_url;
                    return;
                } else {
                    throw new Error('Paystack popup could not load on this browser.');
                }
            })
            .catch(function (error) {
                showBanner(error && error.message ? error.message : 'Could not start the Paystack payment.', 'error');
                localStorage.removeItem(pendingStorageKey);
            })
            .finally(function () {
                setButtonState(false);
            });
        });
    }

    window.addEventListener('focus', function () {
        var pendingReference = localStorage.getItem(pendingStorageKey);
        if (pendingReference) {
            verifyReference(pendingReference, true);
        }
    });

    var queryReference = new URLSearchParams(window.location.search).get('reference');
    if (queryReference) {
        localStorage.setItem(pendingStorageKey, queryReference);
    }
    var existingPendingReference = localStorage.getItem(pendingStorageKey);
    if (existingPendingReference) {
        verifyReference(existingPendingReference, true);
    }
})();
</script>
<?php endif; ?>

<?php include 'footer.php'; ?>
</body>
</html>
