<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();

$is_ajax = isset($_POST['ajax']) || (strtolower(strval($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');

function toggle_payment_json_response(array $payload): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function toggle_payment_respond(bool $success, string $message, array $extra = []): void
{
    $payload = array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra);

    toggle_payment_json_response($payload);
}

function toggle_payment_nonce_cache_path(string $nonceKey): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'classbookhub_payment_' . sha1($nonceKey) . '.json';
}

function toggle_payment_load_cached_payload(string $nonceKey, int $ttlSeconds = 1800): ?array
{
    $cachePath = toggle_payment_nonce_cache_path($nonceKey);
    if (!is_file($cachePath)) {
        return null;
    }

    $raw = @file_get_contents($cachePath);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return null;
    }

    $createdAt = intval($payload['_cached_at'] ?? 0);
    if ($createdAt <= 0 || (time() - $createdAt) > $ttlSeconds) {
        @unlink($cachePath);
        return null;
    }

    unset($payload['_cached_at']);
    return $payload;
}

function toggle_payment_store_cached_payload(string $nonceKey, array $payload): void
{
    $cachePath = toggle_payment_nonce_cache_path($nonceKey);
    $payload['_cached_at'] = time();
    @file_put_contents($cachePath, json_encode($payload), LOCK_EX);
}

function toggle_payment_build_balance_markup(array $row): array
{
    $total_amount = floatval($row['total_amount'] ?? 0);
    $amount_paid = floatval($row['amount_paid'] ?? 0);
    $credit_used = floatval($row['credit_used'] ?? 0);
    $refunded_amount = max(0, floatval($row['refunded_amount'] ?? 0));
    $carried_forward_amount = max(0, floatval($row['carried_forward_amount'] ?? 0));
    $payment_math = function_exists('book_system_calculate_request_payment_math')
        ? book_system_calculate_request_payment_math($total_amount, $credit_used, $amount_paid)
        : [
            'due_after_credit' => max(0, $total_amount - $credit_used),
            'cash_overpaid' => max(0, $amount_paid - max(0, $total_amount - $credit_used)),
            'outstanding_balance' => max(0, max(0, $total_amount - $credit_used) - $amount_paid),
        ];
    $cash_overpaid = floatval($payment_math['cash_overpaid'] ?? 0);
    $debit_amount = floatval($payment_math['outstanding_balance'] ?? 0);
    $remaining_balance = max(0, $cash_overpaid - $refunded_amount - $carried_forward_amount);

    if ($refunded_amount > 0) {
        return [
            'html' => '<span class="credit-amt">Returned: GH&#8373; ' . number_format($refunded_amount, 2) . '</span>',
            'class' => 'credit-amt',
        ];
    }
    if ($carried_forward_amount > 0) {
        return [
            'html' => '<span class="credit-amt">Carried Forward: GH&#8373; ' . number_format($carried_forward_amount, 2) . '</span>',
            'class' => 'credit-amt',
        ];
    }
    if ($debit_amount > 0) {
        return [
            'html' => '<span class="debit-amt">Owes: GH&#8373; ' . number_format($debit_amount, 2) . '</span>',
            'class' => 'debit-amt',
        ];
    }
    if ($remaining_balance > 0) {
        return [
            'html' => '<span class="credit-amt">Balance: GH&#8373; ' . number_format($remaining_balance, 2) . '</span>',
            'class' => 'credit-amt',
        ];
    }

    return [
        'html' => '<span class="zero-amt">&mdash;</span>',
        'class' => 'zero-amt',
    ];
}

function toggle_payment_balance_label(array $row): string
{
    $total_amount = floatval($row['total_amount'] ?? 0);
    $amount_paid = floatval($row['amount_paid'] ?? 0);
    $credit_used = floatval($row['credit_used'] ?? 0);
    $refunded_amount = max(0, floatval($row['refunded_amount'] ?? 0));
    $carried_forward_amount = max(0, floatval($row['carried_forward_amount'] ?? 0));
    $payment_math = function_exists('book_system_calculate_request_payment_math')
        ? book_system_calculate_request_payment_math($total_amount, $credit_used, $amount_paid)
        : ['outstanding_balance' => max(0, max(0, $total_amount - $credit_used) - $amount_paid), 'cash_overpaid' => max(0, $amount_paid - max(0, $total_amount - $credit_used))];
    $debit_amount = floatval($payment_math['outstanding_balance'] ?? 0);
    $remaining_balance = max(0, floatval($payment_math['cash_overpaid'] ?? 0) - $refunded_amount - $carried_forward_amount);

    if ($refunded_amount > 0) {
        return 'Returned';
    }
    if ($carried_forward_amount > 0) {
        return 'Carried Forward';
    }
    if ($debit_amount > 0) {
        return 'Outstanding Balance';
    }
    if ($remaining_balance > 0) {
        return 'Extra Balance';
    }

    return 'Balance';
}

function toggle_payment_build_note(array $row): string
{
    $total_amount = floatval($row['total_amount'] ?? 0);
    $amount_paid = floatval($row['amount_paid'] ?? 0);
    $credit_used = floatval($row['credit_used'] ?? 0);
    $refunded_amount = max(0, floatval($row['refunded_amount'] ?? 0));
    $carried_forward_amount = max(0, floatval($row['carried_forward_amount'] ?? 0));
    $payment_math = function_exists('book_system_calculate_request_payment_math')
        ? book_system_calculate_request_payment_math($total_amount, $credit_used, $amount_paid)
        : [
            'due_after_credit' => max(0, $total_amount - $credit_used),
            'outstanding_balance' => max(0, max(0, $total_amount - $credit_used) - $amount_paid),
            'cash_overpaid' => max(0, $amount_paid - max(0, $total_amount - $credit_used)),
        ];
    $due_after_credit = floatval($payment_math['due_after_credit'] ?? 0);
    $debit_amount = floatval($payment_math['outstanding_balance'] ?? 0);
    $remaining_balance = max(0, floatval($payment_math['cash_overpaid'] ?? 0) - $refunded_amount - $carried_forward_amount);

    if ($refunded_amount > 0) {
        return 'Net due was GH₵ ' . number_format($due_after_credit, 2) . '. Cash paid: GH₵ ' . number_format($amount_paid, 2) . '. Returned to student: GH₵ ' . number_format($refunded_amount, 2) . '.';
    }
    if ($carried_forward_amount > 0) {
        return 'Net due was GH₵ ' . number_format($due_after_credit, 2) . '. Cash paid: GH₵ ' . number_format($amount_paid, 2) . '. Carried forward: GH₵ ' . number_format($carried_forward_amount, 2) . '.';
    }
    if ($debit_amount > 0) {
        if ($credit_used > 0) {
            return 'Total GH₵ ' . number_format($total_amount, 2) . ' - credit applied GH₵ ' . number_format($credit_used, 2) . ' - paid amount GH₵ ' . number_format($amount_paid, 2) . ' = outstanding GH₵ ' . number_format($debit_amount, 2) . '.';
        }
        return 'Total GH₵ ' . number_format($total_amount, 2) . ' - paid amount GH₵ ' . number_format($amount_paid, 2) . ' = outstanding GH₵ ' . number_format($debit_amount, 2) . '.';
    }
    if ($remaining_balance > 0) {
        return 'Net due was GH₵ ' . number_format($due_after_credit, 2) . '. Cash paid: GH₵ ' . number_format($amount_paid, 2) . '. Extra balance: GH₵ ' . number_format($remaining_balance, 2) . '.';
    }
    if ($credit_used > 0) {
        return 'Total GH₵ ' . number_format($total_amount, 2) . ' - credit applied GH₵ ' . number_format($credit_used, 2) . ' = net due GH₵ ' . number_format($due_after_credit, 2) . '. Request is fully settled.';
    }

    return 'Total GH₵ ' . number_format($total_amount, 2) . '. Paid amount GH₵ ' . number_format($amount_paid, 2) . '. Request is fully settled.';
}

function toggle_payment_fetch_request_snapshot(mysqli $conn, int $request_id, int $current_admin_id, bool $is_super_admin): ?array
{
    $sql = "SELECT
                r.request_id,
                r.student_id,
                r.total_amount,
                r.amount_paid,
                r.credit_used,
                r.payment_status,
                s.full_name,
                s.index_number,
                COALESCE(br.refunded_amount, 0) AS refunded_amount,
                COALESCE(cf.carried_forward_amount, 0) AS carried_forward_amount
            FROM requests r
            JOIN students s ON s.student_id = r.student_id
            LEFT JOIN (
                SELECT request_id, SUM(amount) AS refunded_amount
                FROM balance_returns
                WHERE request_id IS NOT NULL
                GROUP BY request_id
            ) br ON r.request_id = br.request_id
            LEFT JOIN (
                SELECT request_id, SUM(amount) AS carried_forward_amount
                FROM semester_balance_carry_forwards
                GROUP BY request_id
            ) cf ON r.request_id = cf.request_id
            WHERE r.request_id = ?" . ($is_super_admin ? "" : " AND r.admin_id = ?") . " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    if ($is_super_admin) {
        $stmt->bind_param('i', $request_id);
    } else {
        $stmt->bind_param('ii', $request_id, $current_admin_id);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function toggle_payment_build_success_payload(array $snapshot, float $amountReceived, string $message, string $nonce): array
{
    $balance = toggle_payment_build_balance_markup($snapshot);
    $payment_math = function_exists('book_system_calculate_request_payment_math')
        ? book_system_calculate_request_payment_math(
            floatval($snapshot['total_amount'] ?? 0),
            floatval($snapshot['credit_used'] ?? 0),
            floatval($snapshot['amount_paid'] ?? 0)
        )
        : [
            'payment_status' => strtolower(trim(strval($snapshot['payment_status'] ?? 'unpaid'))),
            'outstanding_balance' => max(0, max(0, floatval($snapshot['total_amount'] ?? 0) - floatval($snapshot['credit_used'] ?? 0)) - floatval($snapshot['amount_paid'] ?? 0)),
            'due_after_credit' => max(0, floatval($snapshot['total_amount'] ?? 0) - floatval($snapshot['credit_used'] ?? 0)),
        ];

    return [
        'success' => true,
        'request_id' => intval($snapshot['request_id'] ?? 0),
        'payment_status' => strval($payment_math['payment_status'] ?? 'unpaid'),
        'payment_status_label' => strtoupper(strval($payment_math['payment_status'] ?? 'unpaid')),
        'amount_received' => round($amountReceived, 2),
        'amount_paid' => round(floatval($snapshot['amount_paid'] ?? 0), 2),
        'amount_paid_display' => 'GHS ' . number_format(floatval($snapshot['amount_paid'] ?? 0), 2),
        'total_amount' => round(floatval($snapshot['total_amount'] ?? 0), 2),
        'total_amount_display' => 'GHS ' . number_format(floatval($snapshot['total_amount'] ?? 0), 2),
        'credit_used' => round(floatval($snapshot['credit_used'] ?? 0), 2),
        'credit_used_display' => 'GHS ' . number_format(floatval($snapshot['credit_used'] ?? 0), 2),
        'balance' => round(floatval($payment_math['outstanding_balance'] ?? 0), 2),
        'balance_display' => 'GHS ' . number_format(floatval($payment_math['outstanding_balance'] ?? 0), 2),
        'due_after_credit' => round(floatval($payment_math['due_after_credit'] ?? 0), 2),
        'balance_label' => toggle_payment_balance_label($snapshot),
        'balance_html' => $balance['html'],
        'payment_note_text' => toggle_payment_build_note($snapshot),
        'student_name' => strval($snapshot['full_name'] ?? 'Student'),
        'message' => $message,
        'payment_nonce' => $nonce,
    ];
}

if (!isset($_SESSION['admin_logged_in'])) {
    if ($is_ajax) {
        toggle_payment_respond(false, 'Your session has expired. Please sign in again.');
    }
    header('Location: admin.php');
    exit;
}

include 'db.php';

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    if ($is_ajax) {
        toggle_payment_respond(false, 'Rep workspace access is required.');
    }
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}

$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$is_super_admin = false;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($is_ajax) {
        toggle_payment_respond(false, 'Invalid request method.');
    }
    header('Location: view_request.php');
    exit;
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    if ($is_ajax) {
        toggle_payment_respond(false, 'Security check failed. Please refresh and try again.');
    }
    header('Location: view_request.php?msg=csrf_invalid');
    exit;
}

$request_id = intval($_POST['request_id'] ?? 0);
if ($request_id <= 0) {
    toggle_payment_respond(false, 'Invalid request selected.');
}

$action = trim(strval($_POST['action'] ?? 'record_payment'));
if (!in_array($action, ['record_payment', 'mark_fully_paid'], true)) {
    toggle_payment_respond(false, 'Unknown payment action.');
}

$nonce = trim(strval($_POST['payment_nonce'] ?? ''));
if ($nonce === '' || strlen($nonce) < 12) {
    toggle_payment_respond(false, 'Please reopen the payment sheet and try again.');
}

if (!isset($_SESSION['request_payment_nonce_log']) || !is_array($_SESSION['request_payment_nonce_log'])) {
    $_SESSION['request_payment_nonce_log'] = [];
}

$nonceScopePrefix = 'rep:' . $current_admin_id . ':request:' . $request_id . ':';
$now = time();
foreach ($_SESSION['request_payment_nonce_log'] as $storedKey => $storedValue) {
    $loggedAt = intval($storedValue['time'] ?? 0);
    if ($loggedAt <= 0 || ($now - $loggedAt) > 1800) {
        unset($_SESSION['request_payment_nonce_log'][$storedKey]);
    }
}

$nonceKey = $nonceScopePrefix . $nonce;
if (($cachedPayload = toggle_payment_load_cached_payload($nonceKey)) !== null) {
    toggle_payment_json_response($cachedPayload);
}

if (isset($_SESSION['request_payment_nonce_log'][$nonceKey])) {
    $storedPayload = $_SESSION['request_payment_nonce_log'][$nonceKey]['payload'] ?? null;
    if (is_array($storedPayload)) {
        toggle_payment_json_response($storedPayload);
    }
    toggle_payment_respond(false, 'This payment is already being processed.');
}

$rawAmountReceived = trim(strval($_POST['amount_received'] ?? ''));
$amountReceived = null;
if ($action === 'record_payment') {
    $normalizedAmount = str_replace([',', ' '], '', $rawAmountReceived);
    if ($normalizedAmount === '' || !is_numeric($normalizedAmount)) {
        toggle_payment_respond(false, 'Enter the amount received before saving.');
    }
    $amountReceived = round(floatval($normalizedAmount), 2);
    if ($amountReceived <= 0) {
        toggle_payment_respond(false, 'Amount received must be greater than GHS 0.00.');
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$transactionStarted = false;

try {
    $conn->begin_transaction();
    $transactionStarted = true;

    $lockingSql = "SELECT
            r.request_id,
            r.student_id,
            r.total_amount,
            r.amount_paid,
            r.credit_used,
            r.payment_status,
            r.admin_id,
            s.full_name,
            s.index_number
        FROM requests r
        JOIN students s ON s.student_id = r.student_id
        WHERE r.request_id = ?" . ($is_super_admin ? "" : " AND r.admin_id = ?") . " LIMIT 1 FOR UPDATE";
    $lockStmt = $conn->prepare($lockingSql);
    if (!$lockStmt) {
        throw new RuntimeException('Could not prepare the payment update.');
    }
    if ($is_super_admin) {
        $lockStmt->bind_param('i', $request_id);
    } else {
        $lockStmt->bind_param('ii', $request_id, $current_admin_id);
    }
    $lockStmt->execute();
    $lockResult = $lockStmt->get_result();
    $requestRow = ($lockResult && $lockResult->num_rows === 1) ? $lockResult->fetch_assoc() : null;
    $lockStmt->close();

    if (!$requestRow) {
        throw new InvalidArgumentException('You do not have permission to update this request.');
    }

    $paymentMath = function_exists('book_system_calculate_request_payment_math')
        ? book_system_calculate_request_payment_math(
            floatval($requestRow['total_amount'] ?? 0),
            floatval($requestRow['credit_used'] ?? 0),
            floatval($requestRow['amount_paid'] ?? 0)
        )
        : [
            'due_after_credit' => max(0, floatval($requestRow['total_amount'] ?? 0) - floatval($requestRow['credit_used'] ?? 0)),
            'outstanding_balance' => max(0, max(0, floatval($requestRow['total_amount'] ?? 0) - floatval($requestRow['credit_used'] ?? 0)) - floatval($requestRow['amount_paid'] ?? 0)),
            'payment_status' => strtolower(trim(strval($requestRow['payment_status'] ?? 'unpaid'))),
        ];

    $currentAmountPaid = round(floatval($requestRow['amount_paid'] ?? 0), 2);
    $outstandingBalance = round(floatval($paymentMath['outstanding_balance'] ?? 0), 2);

    if ($action === 'mark_fully_paid') {
        if ($outstandingBalance <= 0.009) {
            $amountReceived = 0.0;
        } else {
            $amountReceived = $outstandingBalance;
        }
    }

    $amountReceived = round(floatval($amountReceived ?? 0), 2);
    if ($amountReceived < 0) {
        throw new InvalidArgumentException('Amount received cannot be negative.');
    }

    if ($amountReceived > ($outstandingBalance + 0.009)) {
        throw new InvalidArgumentException('Amount received cannot be greater than outstanding balance.');
    }

    $newAmountPaid = round($currentAmountPaid + $amountReceived, 2);
    $newPaymentMath = function_exists('book_system_calculate_request_payment_math')
        ? book_system_calculate_request_payment_math(
            floatval($requestRow['total_amount'] ?? 0),
            floatval($requestRow['credit_used'] ?? 0),
            $newAmountPaid
        )
        : [
            'payment_status' => ($newAmountPaid <= 0.009 ? 'unpaid' : (($newAmountPaid + 0.009) >= floatval($paymentMath['due_after_credit'] ?? 0) ? 'paid' : 'partial')),
        ];
    $newStatus = strval($newPaymentMath['payment_status'] ?? 'unpaid');

    $updateStmt = $conn->prepare("UPDATE requests SET amount_paid = ?, payment_status = ? WHERE request_id = ? LIMIT 1");
    if (!$updateStmt) {
        throw new RuntimeException('Could not update the request payment.');
    }
    $updateStmt->bind_param('dsi', $newAmountPaid, $newStatus, $request_id);
    $updateStmt->execute();
    $updateStmt->close();

    if (function_exists('book_system_audit_log') && $amountReceived > 0) {
        book_system_audit_log($conn, 'record_payment', 'request', $request_id, [
            'amount_received' => $amountReceived,
            'new_amount_paid' => $newAmountPaid,
            'payment_status' => $newStatus,
            'source' => 'view_requests_partial_payment',
            'action' => $action,
        ]);
    }

    $conn->commit();
    $transactionStarted = false;

    $snapshot = toggle_payment_fetch_request_snapshot($conn, $request_id, $current_admin_id, $is_super_admin);
    if (!$snapshot) {
        throw new RuntimeException('The payment was saved, but the refreshed request data could not be loaded.');
    }

    $successMessage = $amountReceived > 0
        ? 'Payment recorded successfully.'
        : 'This request is already fully settled.';
    $payload = toggle_payment_build_success_payload($snapshot, $amountReceived, $successMessage, $nonce);
    $_SESSION['request_payment_nonce_log'][$nonceKey] = [
        'time' => $now,
        'payload' => $payload,
    ];
    toggle_payment_store_cached_payload($nonceKey, $payload);

    toggle_payment_json_response($payload);
} catch (InvalidArgumentException $e) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    toggle_payment_respond(false, $e->getMessage());
} catch (Throwable $e) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log('ClassBookHub record payment failed: ' . $e->getMessage() . ' [request_id=' . $request_id . ', admin_id=' . $current_admin_id . ', action=' . $action . ']');
    toggle_payment_respond(false, 'Could not record this payment right now. Please try again.');
}
