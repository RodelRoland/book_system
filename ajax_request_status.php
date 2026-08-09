<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (function_exists('book_system_sync_connection_timezone')) {
    book_system_sync_connection_timezone($conn);
}
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
        book_system_setup_ensure_column($conn, 'request_items', 'cash_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
        book_system_setup_ensure_column($conn, 'request_items', 'credit_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    }
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$rawIndex = trim(strval($_GET['index'] ?? ''));
$index = function_exists('book_system_normalize_index_number')
    ? book_system_normalize_index_number($rawIndex)
    : strtoupper(trim($rawIndex));
if ($index === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Enter a valid index number.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$semesterId = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$whereSemester = $semesterId > 0 ? " AND r.semester_id = ?" : '';
$normalizedIndexSql = function_exists('book_system_normalized_index_sql')
    ? book_system_normalized_index_sql('s.index_number')
    : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(s.index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";

$sql = "SELECT
        r.request_id,
        r.created_at,
        r.total_amount,
        r.amount_paid,
        r.credit_used,
        r.payment_status,
        s.index_number,
        s.full_name,
        a.admin_id,
        a.full_name AS rep_full_name,
        a.public_display_name,
        a.class_name,
        b.book_title,
        ri.item_id,
        ri.unit_price,
        COALESCE(ri.is_collected, 0) AS is_collected,
        COALESCE(ri.is_cancelled, 0) AS is_cancelled,
        COALESCE(ri.cash_refunded_amount, 0) AS cash_refunded_amount,
        COALESCE(ri.credit_refunded_amount, 0) AS credit_refunded_amount
    FROM requests r
    JOIN students s ON s.student_id = r.student_id
    JOIN request_items ri ON ri.request_id = r.request_id
    JOIN books b ON b.book_id = ri.book_id
    LEFT JOIN admins a ON a.admin_id = r.admin_id
    WHERE " . $normalizedIndexSql . " = ?" . $whereSemester . "
    ORDER BY r.created_at DESC, ri.item_id ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Status lookup is unavailable right now.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($semesterId > 0) {
    $stmt->bind_param('si', $index, $semesterId);
} else {
    $stmt->bind_param('s', $index);
}

$stmt->execute();
$result = $stmt->get_result();

$items = [];
$summary = [
    'books_requested' => 0,
    'books_collected' => 0,
    'pending_collection' => 0,
    'total_paid' => 0.0,
    'outstanding_balance' => 0.0,
];
$studentName = '';
$studentIndex = $index;
$repName = '';
$className = '';
$seenRequestIds = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $studentName = trim(strval($row['full_name'] ?? $studentName));
        $studentIndex = trim(strval($row['index_number'] ?? $studentIndex));

        $currentRepName = trim(strval($row['public_display_name'] ?? ''));
        if ($currentRepName === '') {
            $currentRepName = trim(strval($row['rep_full_name'] ?? ''));
        }
        if ($repName === '' && $currentRepName !== '') {
            $repName = $currentRepName;
        }
        if ($className === '' && trim(strval($row['class_name'] ?? '')) !== '') {
            $className = trim(strval($row['class_name'] ?? ''));
        }

        $requestId = intval($row['request_id'] ?? 0);
        $totalAmount = round(floatval($row['total_amount'] ?? 0), 2);
        $amountPaid = round(floatval($row['amount_paid'] ?? 0), 2);
        $creditUsed = round(floatval($row['credit_used'] ?? 0), 2);
        $dueAfterCredit = max(0, round($totalAmount - $creditUsed, 2));
        $outstanding = max(0, round($dueAfterCredit - $amountPaid, 2));
        $paymentStatus = strtolower(trim(strval($row['payment_status'] ?? 'unpaid')));
        $isCollected = intval($row['is_collected'] ?? 0) === 1;
        $isCancelled = intval($row['is_cancelled'] ?? 0) === 1;

        $items[] = [
            'request_id' => $requestId,
            'item_id' => intval($row['item_id'] ?? 0),
            'book_title' => trim(strval($row['book_title'] ?? '')),
            'request_date' => strval($row['created_at'] ?? ''),
            'request_date_display' => function_exists('book_system_format_datetime_local')
                ? book_system_format_datetime_local(strval($row['created_at'] ?? ''), 'M j, Y g:i A')
                : strval($row['created_at'] ?? ''),
            'amount' => round(floatval($row['unit_price'] ?? 0), 2),
            'payment_status' => $paymentStatus,
            'collection_status' => $isCancelled ? 'cancelled' : ($isCollected ? 'collected' : 'pending'),
            'is_collected' => $isCollected,
            'is_cancelled' => $isCancelled,
            'cash_refunded_amount' => round(floatval($row['cash_refunded_amount'] ?? 0), 2),
            'credit_refunded_amount' => round(floatval($row['credit_refunded_amount'] ?? 0), 2),
            'outstanding_balance' => $outstanding,
        ];

        if (!$isCancelled) {
            $summary['books_requested']++;
            if ($isCollected) {
                $summary['books_collected']++;
            } else {
                $summary['pending_collection']++;
            }
        }

        if ($requestId > 0 && !isset($seenRequestIds[$requestId])) {
            $seenRequestIds[$requestId] = true;
            $summary['total_paid'] += max(0, round($amountPaid + $creditUsed, 2));
            $summary['outstanding_balance'] += $outstanding;
        }
    }
}

$stmt->close();

if (empty($items)) {
    echo json_encode([
        'success' => false,
        'message' => 'No request record found for this index number.',
        'suggestion' => 'Please check the index number or request books first.',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'student' => [
        'full_name' => $studentName,
        'index_number' => $studentIndex,
        'rep_name' => $repName,
        'class_name' => $className,
    ],
    'summary' => [
        'books_requested' => intval($summary['books_requested']),
        'books_collected' => intval($summary['books_collected']),
        'pending_collection' => intval($summary['pending_collection']),
        'total_paid' => round(floatval($summary['total_paid']), 2),
        'outstanding_balance' => round(floatval($summary['outstanding_balance']), 2),
    ],
    'items' => $items,
    'checked_at' => date('c'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
