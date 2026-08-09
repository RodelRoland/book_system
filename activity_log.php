<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'requests', 'credit_used', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
        book_system_setup_ensure_column($conn, 'request_items', 'received_at', 'DATETIME NULL AFTER is_collected');
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}

$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$back_link = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$rep_bottom_nav_active = 'reports';

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$semester_label = trim(strval($ACTIVE_SEMESTER_NAME ?? $ACTIVE_SEMESTER_LABEL ?? ''));
$semester_pill_label = $semester_label !== '' ? $semester_label : 'This Semester';
$last_updated_label = date('g:i A');

$metrics = function_exists('book_system_get_dashboard_metrics')
    ? book_system_get_dashboard_metrics($conn, $semester_id, $current_admin_id)
    : [
        'cash_collected' => 0,
        'available_balance' => 0,
        'unpaid_requests' => 0,
        'collected_items' => 0,
        'pending_items' => 0,
        'lecturer_paid' => 0,
    ];
$activity_rows = function_exists('book_system_fetch_recent_activity')
    ? book_system_fetch_recent_activity($conn, 12, $current_admin_id, false, null)
    : [];

function book_system_reports_scalar(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $loader = static function () use ($conn, $sql, $types, $params) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $value = 0;
        if ($res && $row = $res->fetch_assoc()) {
            $value = array_values($row)[0] ?? 0;
        }
        $stmt->close();
        return $value;
    };

    if (function_exists('cache_get')) {
        $cacheKey = 'reports_scalar_v1_' . md5($sql . '|' . $types . '|' . serialize($params));
        return cache_get($cacheKey, 60, $loader);
    }

    return $loader();
}

function book_system_reports_query_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $loader = static function () use ($conn, $sql, $types, $params): array {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '' && !empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();
        return $rows;
    };

    if (function_exists('cache_get')) {
        $cacheKey = 'reports_rows_v1_' . md5($sql . '|' . $types . '|' . serialize($params));
        $cachedRows = cache_get($cacheKey, 60, $loader);
        return is_array($cachedRows) ? $cachedRows : [];
    }

    return $loader();
}

function book_system_reports_money(float $amount): string {
    return 'GHS ' . number_format($amount, 2);
}

function book_system_reports_build_pickup_needed_rows(mysqli $conn, int $semester_id, int $admin_id): array {
    $book_rows = book_system_reports_query_rows(
        $conn,
        "SELECT book_id, book_title, COALESCE(price, 0) AS book_price
         FROM books
         WHERE semester_id = ? AND admin_id = ?",
        'ii',
        [$semester_id, $admin_id]
    );

    $books_by_id = [];
    foreach ($book_rows as $book_row) {
        $book_id = intval($book_row['book_id'] ?? 0);
        if ($book_id <= 0) {
            continue;
        }
        $books_by_id[$book_id] = [
            'book_id' => $book_id,
            'book_title' => strval($book_row['book_title'] ?? 'Book'),
            'book_price' => max(0.0, floatval($book_row['book_price'] ?? 0)),
        ];
    }

    $received_rows = book_system_reports_query_rows(
        $conn,
        "SELECT book_id, COALESCE(SUM(copies_received), 0) AS total_received
         FROM books_received
         WHERE semester_id = ? AND admin_id = ?
         GROUP BY book_id",
        'ii',
        [$semester_id, $admin_id]
    );
    $received_by_book = [];
    foreach ($received_rows as $received_row) {
        $received_by_book[intval($received_row['book_id'] ?? 0)] = max(0, intval($received_row['total_received'] ?? 0));
    }

    $request_item_rows = book_system_reports_query_rows(
        $conn,
        "SELECT
            r.request_id,
            COALESCE(r.total_amount, 0) AS total_amount,
            COALESCE(r.amount_paid, 0) AS amount_paid,
            COALESCE(r.credit_used, 0) AS credit_used,
            ri.item_id,
            ri.book_id,
            COALESCE(ri.unit_price, b.price, 0) AS book_price
         FROM requests r
         JOIN request_items ri ON ri.request_id = r.request_id
         JOIN books b ON b.book_id = ri.book_id
         WHERE r.semester_id = ?
           AND r.admin_id = ?
           AND COALESCE(ri.is_cancelled, 0) = 0
         ORDER BY r.request_id ASC, ri.item_id ASC",
        'ii',
        [$semester_id, $admin_id]
    );

    $covered_requested_by_book = [];
    $active_request_id = 0;
    $remaining_covered_amount = 0.0;
    foreach ($request_item_rows as $item_row) {
        $request_id = intval($item_row['request_id'] ?? 0);
        if ($request_id <= 0) {
            continue;
        }

        if ($request_id !== $active_request_id) {
            $active_request_id = $request_id;
            $remaining_covered_amount = max(0.0, floatval($item_row['amount_paid'] ?? 0) + floatval($item_row['credit_used'] ?? 0));
        }

        $book_id = intval($item_row['book_id'] ?? 0);
        if ($book_id <= 0) {
            continue;
        }

        $book_price = max(0.0, floatval($item_row['book_price'] ?? 0));
        if (($remaining_covered_amount + 0.009) < $book_price) {
            continue;
        }

        $covered_requested_by_book[$book_id] = intval($covered_requested_by_book[$book_id] ?? 0) + 1;
        $remaining_covered_amount = max(0.0, $remaining_covered_amount - $book_price);
    }

    $pickup_rows = [];
    foreach ($books_by_id as $book_id => $book_row) {
        $pickup_needed = max(0, intval($covered_requested_by_book[$book_id] ?? 0) - intval($received_by_book[$book_id] ?? 0));
        if ($pickup_needed <= 0) {
            continue;
        }

        $pickup_rows[] = [
            'book_id' => $book_row['book_id'],
            'book_title' => $book_row['book_title'],
            'book_price' => $book_row['book_price'],
            'pickup_needed' => $pickup_needed,
        ];
    }

    usort($pickup_rows, static function (array $a, array $b): int {
        $a_count = intval($a['pickup_needed'] ?? 0);
        $b_count = intval($b['pickup_needed'] ?? 0);
        if ($a_count !== $b_count) {
            return $b_count <=> $a_count;
        }
        return strcasecmp(strval($a['book_title'] ?? ''), strval($b['book_title'] ?? ''));
    });

    return $pickup_rows;
}

function book_system_reports_activity_label(array $activity): string {
    $action = strval($activity['action_type'] ?? '');
    $map = [
        'set_active_semester' => 'Changed the active semester',
        'create_semester' => 'Created a semester',
        'carry_forward_balance' => 'Carried forward student balances',
        'toggle_payment' => 'Updated a payment status',
        'toggle_collection' => 'Updated a collection status',
        'mark_paid' => 'Marked a request as paid',
        'delete_request' => 'Deleted a request',
        'return_balance' => 'Returned a student balance',
        'add_book' => 'Added a book',
        'update_book' => 'Updated a book',
        'record_books_received' => 'Recorded books received',
        'update_books_received' => 'Updated books received',
        'delete_books_received' => 'Deleted books received',
        'record_lecturer_payment' => 'Recorded a lecturer payment',
        'update_lecturer_payment' => 'Updated a lecturer payment',
        'delete_lecturer_payment' => 'Deleted a lecturer payment',
        'upload_class_excel' => 'Imported class data',
        'upload_class_csv' => 'Imported class data',
        'add_class_student' => 'Added a class member',
        'delete_class_student' => 'Removed a class member',
        'clear_class_students' => 'Cleared the class list',
    ];
    return $map[$action] ?? str_replace('_', ' ', ucwords($action, '_'));
}

function book_system_reports_current_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    return $scheme . '://' . $host . $uri;
}

function book_system_reports_build_query(array $overrides = []): string {
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    return http_build_query($params);
}

$period = strtolower(trim(strval($_GET['period'] ?? 'month')));
if (!in_array($period, ['today', 'week', 'month', 'semester'], true)) {
    $period = 'month';
}
$selected_book_id = intval($_GET['book_id'] ?? 0);
$status_filter = strtolower(trim(strval($_GET['status'] ?? 'all')));
if (!in_array($status_filter, ['all', 'paid', 'unpaid', 'partial', 'collected', 'pending'], true)) {
    $status_filter = 'all';
}
$search = trim(strval($_GET['search'] ?? ''));

$book_rows = book_system_reports_query_rows(
    $conn,
    "SELECT book_id, book_title FROM books WHERE admin_id = ? AND semester_id = ? ORDER BY book_title ASC",
    'ii',
    [$current_admin_id, $semester_id]
);

$base_where = " WHERE r.semester_id = ? AND r.admin_id = ? AND COALESCE(ri.is_cancelled, 0) = 0";
$base_types = 'ii';
$base_params = [$semester_id, $current_admin_id];

if ($period === 'today') {
    $base_where .= " AND DATE(r.created_at) = CURDATE()";
} elseif ($period === 'week') {
    $base_where .= " AND YEARWEEK(r.created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($period === 'month') {
    $base_where .= " AND YEAR(r.created_at) = YEAR(CURDATE()) AND MONTH(r.created_at) = MONTH(CURDATE())";
}

if ($selected_book_id > 0) {
    $base_where .= " AND b.book_id = ?";
    $base_types .= 'i';
    $base_params[] = $selected_book_id;
}

if ($search !== '') {
    $base_where .= " AND (s.full_name LIKE ? OR s.index_number LIKE ? OR b.book_title LIKE ?)";
    $search_like = '%' . $search . '%';
    $base_types .= 'sss';
    $base_params[] = $search_like;
    $base_params[] = $search_like;
    $base_params[] = $search_like;
}

$preview_sql = "SELECT
        r.request_id,
        s.index_number,
        s.full_name,
        r.total_amount,
        r.amount_paid,
        COALESCE(r.credit_used, 0) AS credit_used,
        r.payment_status,
        MAX(r.created_at) AS created_at,
        GROUP_CONCAT(DISTINCT b.book_title ORDER BY b.book_title SEPARATOR ' + ') AS books_requested,
        COUNT(DISTINCT ri.item_id) AS request_item_count,
        SUM(CASE WHEN ri.is_collected = 1 THEN 1 ELSE 0 END) AS collected_item_count
    FROM requests r
    JOIN students s ON s.student_id = r.student_id
    JOIN request_items ri ON ri.request_id = r.request_id
    JOIN books b ON b.book_id = ri.book_id
    $base_where
    GROUP BY r.request_id, s.index_number, s.full_name, r.total_amount, r.amount_paid, r.credit_used, r.payment_status
    ORDER BY MAX(r.created_at) DESC";

$preview_rows_raw = book_system_reports_query_rows($conn, $preview_sql, $base_types, $base_params);
$filtered_preview_rows = [];
foreach ($preview_rows_raw as $row) {
    $total_amount = floatval($row['total_amount'] ?? 0);
    $amount_paid = floatval($row['amount_paid'] ?? 0);
    $credit_used = floatval($row['credit_used'] ?? 0);
    $paid_total = $amount_paid + $credit_used;
    $items_total = max(0, intval($row['request_item_count'] ?? 0));
    $collected_total = max(0, intval($row['collected_item_count'] ?? 0));

    if ($paid_total <= 0.009) {
        $payment_state = 'unpaid';
    } elseif ($paid_total + 0.009 < $total_amount) {
        $payment_state = 'partial';
    } else {
        $payment_state = 'paid';
    }

    if ($items_total > 0 && $collected_total >= $items_total) {
        $collection_state = 'collected';
    } else {
        $collection_state = 'pending';
    }

    $matches_status = $status_filter === 'all'
        || $payment_state === $status_filter
        || $collection_state === $status_filter;
    if (!$matches_status) {
        continue;
    }

    $row['payment_state'] = $payment_state;
    $row['collection_state'] = $collection_state;
    $row['display_amount'] = $paid_total > 0 ? $paid_total : $total_amount;
    $filtered_preview_rows[] = $row;
}

$preview_total = count($filtered_preview_rows);
$preview_rows = array_slice($filtered_preview_rows, 0, 10);

$outstanding_balance = floatval(book_system_reports_scalar(
    $conn,
    "SELECT COALESCE(SUM(GREATEST(total_amount - COALESCE(amount_paid, 0) - COALESCE(credit_used, 0), 0)), 0)
     FROM requests
     WHERE semester_id = ? AND admin_id = ? AND GREATEST(COALESCE(total_amount, 0) - COALESCE(credit_used, 0), 0) > (COALESCE(amount_paid, 0) + 0.009)",
    'ii',
    [$semester_id, $current_admin_id]
));

$requested_books_total = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(*)
     FROM request_items ri
     JOIN requests r ON r.request_id = ri.request_id
     WHERE r.semester_id = ? AND r.admin_id = ? AND COALESCE(ri.is_cancelled, 0) = 0",
    'ii',
    [$semester_id, $current_admin_id]
));

$students_requested_total = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(DISTINCT student_id)
     FROM requests
     WHERE semester_id = ? AND admin_id = ?",
    'ii',
    [$semester_id, $current_admin_id]
));

$students_paid_total = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(DISTINCT student_id)
     FROM requests
     WHERE semester_id = ? AND admin_id = ? AND (COALESCE(amount_paid, 0) + COALESCE(credit_used, 0)) >= COALESCE(total_amount, 0)",
    'ii',
    [$semester_id, $current_admin_id]
));

$students_unpaid_total = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(DISTINCT student_id)
     FROM requests
     WHERE semester_id = ? AND admin_id = ? AND (COALESCE(amount_paid, 0) + COALESCE(credit_used, 0)) < COALESCE(total_amount, 0)",
    'ii',
    [$semester_id, $current_admin_id]
));

$students_collected_total = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(DISTINCT r.student_id)
     FROM request_items ri
     JOIN requests r ON r.request_id = ri.request_id
     WHERE r.semester_id = ? AND r.admin_id = ? AND COALESCE(ri.is_cancelled, 0) = 0 AND ri.is_collected = 1",
    'ii',
    [$semester_id, $current_admin_id]
));

$lecturer_payment_entries = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(*) FROM lecturer_payments WHERE semester_id = ? AND admin_id = ?",
    'ii',
    [$semester_id, $current_admin_id]
));

$lecturer_paid_total = floatval(book_system_reports_scalar(
    $conn,
    "SELECT COALESCE(SUM(amount_paid), 0) FROM lecturer_payments WHERE semester_id = ? AND admin_id = ?",
    'ii',
    [$semester_id, $current_admin_id]
));

$lecturer_paid_copies = intval(book_system_reports_scalar(
    $conn,
    "SELECT COALESCE(SUM(copies_paid), 0) FROM lecturer_payments WHERE semester_id = ? AND admin_id = ?",
    'ii',
    [$semester_id, $current_admin_id]
));

$books_received_total = intval(book_system_reports_scalar(
    $conn,
    "SELECT COALESCE(SUM(copies_received), 0) FROM books_received WHERE semester_id = ? AND admin_id = ?",
    'ii',
    [$semester_id, $current_admin_id]
));

$books_remaining_total = max(0, $books_received_total - intval($metrics['collected_items'] ?? 0));
$outstanding_copies_total = max(0, $books_received_total - $lecturer_paid_copies);

$pickup_needed_rows = book_system_reports_build_pickup_needed_rows($conn, $semester_id, $current_admin_id);
$pickup_needed_total = 0;
$pickup_needed_cost_total = 0.0;
$pickup_needed_book_count = 0;
foreach ($pickup_needed_rows as $pickup_row) {
    $pickup_count = max(0, intval($pickup_row['pickup_needed'] ?? 0));
    if ($pickup_count <= 0) {
        continue;
    }
    $pickup_needed_book_count++;
    $pickup_needed_total += $pickup_count;
    $pickup_needed_cost_total += $pickup_count * max(0.0, floatval($pickup_row['book_price'] ?? 0));
}

$summary_cards = [
    [
        'title' => 'Book Request Summary',
        'description' => 'Track requested, paid, unpaid and given-out books',
        'accent' => 'blue',
        'icon' => 'books',
        'empty_note' => 'No request summary records found yet.',
        'stats' => [
            ['label' => 'Requested', 'value' => number_format($requested_books_total)],
            ['label' => 'Given Out', 'value' => number_format(intval($metrics['collected_items'] ?? 0))],
            ['label' => 'Pending', 'value' => number_format(intval($metrics['pending_items'] ?? 0))],
        ],
        'href' => '#studentReportPreview',
        'export_href' => 'export_excel.php?' . book_system_reports_build_query([
            'search' => $search,
            'collection_filter' => $status_filter === 'pending' ? 'not_taken' : 'all',
        ]),
    ],
    [
        'title' => 'Financial Summary',
        'description' => 'See collected cash, balances and payment activity',
        'accent' => 'green',
        'icon' => 'wallet',
        'empty_note' => 'No financial activity recorded yet.',
        'stats' => [
            ['label' => 'Collected', 'value' => book_system_reports_money(floatval($metrics['cash_collected'] ?? 0))],
            ['label' => 'Outstanding', 'value' => book_system_reports_money($outstanding_balance)],
            ['label' => 'Paid to Lecturers', 'value' => book_system_reports_money($lecturer_paid_total)],
        ],
        'href' => '#financialSummarySection',
        'export_href' => 'export_excel.php?' . book_system_reports_build_query(['search' => $search]),
    ],
    [
        'title' => 'Student Request Report',
        'description' => 'View requests made by students',
        'accent' => 'orange',
        'icon' => 'students',
        'empty_note' => 'No student requests found for this filter.',
        'stats' => [
            ['label' => 'Students Paid', 'value' => number_format($students_paid_total)],
            ['label' => 'With Balance', 'value' => number_format($students_unpaid_total)],
            ['label' => 'Collected Books', 'value' => number_format(intval($metrics['collected_items'] ?? 0))],
        ],
        'href' => '#studentReportPreview',
        'export_href' => 'export_excel.php?' . book_system_reports_build_query([
            'search' => $search,
            'collection_filter' => $status_filter === 'pending' ? 'not_taken' : 'all',
        ]),
    ],
    [
        'title' => 'Lecturer Payment Report',
        'description' => 'Track payments made to lecturers',
        'accent' => 'purple',
        'icon' => 'receipt',
        'empty_note' => 'No lecturer payments recorded yet.',
        'stats' => [
            ['label' => 'Payments Logged', 'value' => number_format($lecturer_payment_entries)],
            ['label' => 'Outstanding', 'value' => number_format($outstanding_copies_total)],
        ],
        'href' => '#lecturerPaymentSection',
        'export_href' => 'lecturer_payments.php',
    ],
    [
        'title' => 'Books Received Report',
        'description' => 'Monitor books received and distributed',
        'accent' => 'cyan',
        'icon' => 'box',
        'empty_note' => 'No books received records found.',
        'stats' => [
            ['label' => 'Received', 'value' => number_format($books_received_total)],
            ['label' => 'Distributed', 'value' => number_format(intval($metrics['collected_items'] ?? 0))],
            ['label' => 'Remaining', 'value' => number_format($books_remaining_total)],
        ],
        'href' => '#booksReceivedSection',
        'export_href' => 'lecturer_payments.php',
    ],
    [
        'title' => 'Pickup Needed',
        'description' => 'See which books still need pickup from lecturers',
        'accent' => 'orange',
        'icon' => 'box',
        'empty_note' => 'No extra books need to be picked up right now.',
        'stats' => [
            ['label' => 'To Collect', 'value' => number_format($pickup_needed_total) . ' Copies'],
            ['label' => 'Total Cost', 'value' => book_system_reports_money($pickup_needed_cost_total)],
        ],
        'href' => '#pickupNeededSection',
        'export_href' => 'lecturer_payments.php',
    ],
    [
        'title' => 'Class Activity Report',
        'description' => 'See who requested, paid or collected books',
        'accent' => 'pink',
        'icon' => 'team',
        'empty_note' => 'No class activity logged yet.',
        'stats' => [
            ['label' => 'Requested', 'value' => number_format($students_requested_total)],
            ['label' => 'Paid', 'value' => number_format($students_paid_total)],
            ['label' => 'Collected', 'value' => number_format($students_collected_total)],
        ],
        'href' => '#classActivitySection',
        'export_href' => 'export_excel.php?' . book_system_reports_build_query(['search' => $search]),
    ],
];

$status_filter_options = [
    'all' => 'All Status',
    'paid' => 'Paid',
    'unpaid' => 'Unpaid',
    'partial' => 'Partial',
    'collected' => 'Collected',
    'pending' => 'Pending',
];
$period_options = [
    'today' => 'Today',
    'week' => 'This Week',
    'month' => 'This Month',
    'semester' => 'Semester',
];

$share_url = book_system_reports_current_url();
$excel_export_href = 'export_excel.php?' . book_system_reports_build_query([
    'search' => $search,
    'collection_filter' => $status_filter === 'pending' ? 'not_taken' : 'all',
]);

$report_views = ['hub', 'financial', 'student', 'pickup', 'lecturer', 'distribution', 'activity'];
$report_view = strtolower(trim(strval($_GET['view'] ?? 'hub')));
if (!in_array($report_view, $report_views, true)) {
    $report_view = 'hub';
}

$total_requests_count = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(*) FROM requests WHERE semester_id = ? AND admin_id = ?",
    'ii',
    [$semester_id, $current_admin_id]
));

$paid_requests_count = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(*)
     FROM requests
     WHERE semester_id = ? AND admin_id = ?
       AND (COALESCE(amount_paid, 0) + COALESCE(credit_used, 0)) >= COALESCE(total_amount, 0)",
    'ii',
    [$semester_id, $current_admin_id]
));

$partial_requests_count = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(*)
     FROM requests
     WHERE semester_id = ? AND admin_id = ?
       AND (COALESCE(amount_paid, 0) + COALESCE(credit_used, 0)) > 0.009
       AND (COALESCE(amount_paid, 0) + COALESCE(credit_used, 0) + 0.009) < COALESCE(total_amount, 0)",
    'ii',
    [$semester_id, $current_admin_id]
));

$unpaid_requests_count = intval(book_system_reports_scalar(
    $conn,
    "SELECT COUNT(*)
     FROM requests
     WHERE semester_id = ? AND admin_id = ?
       AND (COALESCE(amount_paid, 0) + COALESCE(credit_used, 0)) <= 0.009",
    'ii',
    [$semester_id, $current_admin_id]
));

$lecturer_report_rows = book_system_reports_query_rows(
    $conn,
    "SELECT
        b.book_id,
        b.book_title,
        COALESCE(NULLIF(MAX(br.lecturer_name), ''), 'Lecturer not specified') AS lecturer_name,
        COALESCE(rec.received_copies, 0) AS received_copies,
        COALESCE(rec.amount_due, 0) AS amount_due,
        COALESCE(pay.amount_paid, 0) AS amount_paid,
        GREATEST(COALESCE(rec.amount_due, 0) - COALESCE(pay.amount_paid, 0), 0) AS balance_due
     FROM books b
     LEFT JOIN (
        SELECT
            book_id,
            MAX(NULLIF(lecturer_name, '')) AS lecturer_name,
            COALESCE(SUM(copies_received), 0) AS received_copies,
            COALESCE(SUM(copies_received * unit_price), 0) AS amount_due
        FROM books_received
        WHERE semester_id = ? AND admin_id = ?
        GROUP BY book_id
     ) rec ON rec.book_id = b.book_id
     LEFT JOIN (
        SELECT book_id, COALESCE(SUM(amount_paid), 0) AS amount_paid
        FROM lecturer_payments
        WHERE semester_id = ? AND admin_id = ?
        GROUP BY book_id
     ) pay ON pay.book_id = b.book_id
     LEFT JOIN books_received br ON br.book_id = b.book_id AND br.semester_id = ? AND br.admin_id = ?
     WHERE b.semester_id = ? AND b.admin_id = ?
       AND (COALESCE(rec.amount_due, 0) > 0 OR COALESCE(pay.amount_paid, 0) > 0)
     GROUP BY b.book_id, b.book_title, rec.received_copies, rec.amount_due, pay.amount_paid
     ORDER BY balance_due DESC, b.book_title ASC",
    'iiiiiiii',
    [$semester_id, $current_admin_id, $semester_id, $current_admin_id, $semester_id, $current_admin_id, $semester_id, $current_admin_id]
);

$distribution_rows = book_system_reports_query_rows(
    $conn,
    "SELECT
        b.book_id,
        b.book_title,
        COALESCE(req.requested_copies, 0) AS requested_copies,
        COALESCE(req.collected_copies, 0) AS collected_copies,
        GREATEST(COALESCE(req.requested_copies, 0) - COALESCE(req.collected_copies, 0), 0) AS pending_collection
     FROM books b
     LEFT JOIN (
        SELECT
            ri.book_id,
            COUNT(*) AS requested_copies,
            SUM(CASE WHEN ri.is_collected = 1 THEN 1 ELSE 0 END) AS collected_copies
        FROM request_items ri
        JOIN requests r ON r.request_id = ri.request_id
        WHERE r.semester_id = ? AND r.admin_id = ? AND COALESCE(ri.is_cancelled, 0) = 0
        GROUP BY ri.book_id
     ) req ON req.book_id = b.book_id
     WHERE b.semester_id = ? AND b.admin_id = ?
       AND COALESCE(req.requested_copies, 0) > 0
     ORDER BY pending_collection DESC, b.book_title ASC",
    'iiii',
    [$semester_id, $current_admin_id, $semester_id, $current_admin_id]
);

$hub_cards = [
    [
        'view' => 'financial',
        'title' => 'Financial Summary',
        'description' => 'Cash collected, balances, requests, and distribution totals.',
        'icon' => 'wallet',
        'accent' => 'green',
        'meta' => book_system_reports_money(floatval($metrics['cash_collected'] ?? 0)),
    ],
    [
        'view' => 'student',
        'title' => 'Student Requests',
        'description' => 'Review request summaries, payment state, and top request activity.',
        'icon' => 'students',
        'accent' => 'blue',
        'meta' => number_format($total_requests_count) . ' requests',
    ],
    [
        'view' => 'pickup',
        'title' => 'Pickup Needed',
        'description' => 'See what books still need to be collected from lecturers.',
        'icon' => 'box',
        'accent' => 'orange',
        'meta' => number_format($pickup_needed_total) . ' copies',
        'submeta' => book_system_reports_money($pickup_needed_cost_total),
    ],
    [
        'view' => 'lecturer',
        'title' => 'Lecturer Payments',
        'description' => 'Track lecturer settlements, paid amounts, and balances due.',
        'icon' => 'receipt',
        'accent' => 'purple',
        'meta' => book_system_reports_money($lecturer_paid_total),
    ],
    [
        'view' => 'distribution',
        'title' => 'Books Distribution',
        'description' => 'Monitor books given out, pending collection, and remaining demand.',
        'icon' => 'books',
        'accent' => 'cyan',
        'meta' => number_format(intval($metrics['collected_items'] ?? 0)) . ' given out',
    ],
    [
        'view' => 'activity',
        'title' => 'Activity Log',
        'description' => 'Follow recent class request, payment, and collection activity.',
        'icon' => 'team',
        'accent' => 'pink',
        'meta' => number_format(count($activity_rows)) . ' updates',
    ],
];

$reports_hub_url = 'activity_log.php';
$report_tabs = [
    'financial' => 'Financial',
    'student' => 'Student',
    'pickup' => 'Pickup',
    'lecturer' => 'Lecturer',
    'distribution' => 'Distribution',
    'activity' => 'Activity',
];
$report_view_links = [];
foreach ($report_tabs as $key => $label) {
    $query = $_GET;
    $query['view'] = $key;
    $report_view_links[$key] = 'activity_log.php?' . http_build_query($query);
}

$reports_refresh_state = [
    'semester_id' => $semester_id,
    'admin_id' => $current_admin_id,
    'view' => $view,
    'status_filter' => $status_filter,
    'search' => $search,
    'metrics' => [
        'cash_collected' => round(floatval($metrics['cash_collected'] ?? 0), 2),
        'available_balance' => round(floatval($metrics['available_balance'] ?? 0), 2),
        'unpaid_requests' => intval($metrics['unpaid_requests'] ?? 0),
        'collected_items' => intval($metrics['collected_items'] ?? 0),
        'pending_items' => intval($metrics['pending_items'] ?? 0),
        'lecturer_paid' => round(floatval($metrics['lecturer_paid'] ?? 0), 2),
    ],
    'hub_cards' => array_map(static function (array $card): array {
        return [
            'view' => strval($card['view'] ?? ''),
            'meta' => strval($card['meta'] ?? ''),
            'submeta' => strval($card['submeta'] ?? ''),
        ];
    }, $hub_cards),
    'distribution_rows' => array_map(static function (array $row): array {
        return [
            'book_id' => intval($row['book_id'] ?? 0),
            'requested_copies' => intval($row['requested_copies'] ?? 0),
            'collected_copies' => intval($row['collected_copies'] ?? 0),
            'pending_collection' => intval($row['pending_collection'] ?? 0),
        ];
    }, $distribution_rows),
    'lecturer_rows' => array_map(static function (array $row): array {
        return [
            'book_id' => intval($row['book_id'] ?? 0),
            'received_copies' => intval($row['received_copies'] ?? 0),
            'amount_due' => round(floatval($row['amount_due'] ?? 0), 2),
            'amount_paid' => round(floatval($row['amount_paid'] ?? 0), 2),
            'balance_due' => round(floatval($row['balance_due'] ?? 0), 2),
        ];
    }, $lecturer_report_rows),
    'activity_rows' => array_map(static function (array $row): array {
        return [
            'activity_id' => intval($row['activity_id'] ?? 0),
            'action_type' => strval($row['action_type'] ?? ''),
            'entity_type' => strval($row['entity_type'] ?? ''),
            'created_at' => strval($row['created_at'] ?? ''),
            'details' => strval($row['details'] ?? ''),
        ];
    }, $activity_rows),
];
$reports_refresh_token = function_exists('book_system_build_refresh_token')
    ? book_system_build_refresh_token($reports_refresh_state)
    : sha1(json_encode($reports_refresh_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if (function_exists('book_system_maybe_output_refresh_status')) {
    book_system_maybe_output_refresh_status($reports_refresh_token);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <style>
        :root {
            --surface: #ffffff;
            --surface-soft: #f8fbff;
            --line: #e3ebf6;
            --text: #162033;
            --muted: #64748b;
            --blue: #2563eb;
            --green: #16a34a;
            --orange: #f97316;
            --purple: #7c3aed;
            --cyan: #06b6d4;
            --pink: #ec4899;
            --shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 10px 24px rgba(15, 23, 42, 0.05);
            --radius-xl: 26px;
            --radius-lg: 20px;
            --radius-md: 16px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top, rgba(37, 99, 235, 0.08), transparent 28%),
                linear-gradient(180deg, #ffffff 0%, #f7f9fc 52%, #f2f6fb 100%);
            min-height: 100vh;
            padding: 18px 12px 48px;
        }
        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; }
        .page-shell { width: min(100%, 1160px); margin: 0 auto; display: grid; gap: 16px; }
        .top-header { display: flex; align-items: center; gap: 12px; margin-bottom: 0; }
        .back-button {
            width: 44px; height: 44px; border-radius: 15px; border: 1px solid rgba(209, 219, 229, 0.95);
            background: rgba(255,255,255,0.96); box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .header-copy { min-width: 0; flex: 1; }
        .header-copy h1 { margin: 0; font-size: 1.62rem; line-height: 1.02; font-weight: 900; letter-spacing: -0.03em; color: #0f172a; }
        .header-copy p { margin: 6px 0 0; color: #64748b; font-size: 0.82rem; line-height: 1.4; font-weight: 600; }
        .semester-pill-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 10px 14px;
            align-items: center;
            padding: 11px 14px;
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.96));
            border: 1px solid rgba(226, 232, 240, 0.98);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.045);
        }
        .semester-pill {
            display: inline-flex; align-items: center; gap: 8px; padding: 9px 13px; border-radius: 999px;
            border: 1px solid rgba(191, 219, 254, 0.95); background: #ffffff; box-shadow: inset 0 1px 0 rgba(255,255,255,0.92);
            color: #1d4ed8; font-size: 0.82rem; font-weight: 800;
        }
        .last-updated { color: #64748b; font-size: 0.74rem; font-weight: 700; letter-spacing: -0.01em; }
        .hub-grid, .stats-grid, .pickup-grid, .student-grid, .distribution-grid, .lecturer-grid, .timeline-list, .book-summary-grid {
            display: grid; gap: 14px;
        }
        .hub-grid, .pickup-grid, .student-grid, .distribution-grid, .lecturer-grid, .book-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .hub-card, .report-shell, .metric-tile, .focus-card, .pickup-summary-card, .student-card, .distribution-card, .lecturer-card, .timeline-card, .empty-state, .note-card {
            background: var(--surface); border: 1px solid rgba(255,255,255,0.9); box-shadow: var(--shadow); border-radius: var(--radius-xl);
        }
        .hub-card {
            padding: 16px 16px 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            position: relative;
            min-height: 148px;
            overflow: hidden;
            border-color: rgba(226, 232, 240, 0.94);
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.055);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .hub-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            border-radius: 22px 0 0 22px;
            background: #cbd5e1;
        }
        .hub-card::after {
            content: "";
            position: absolute;
            right: -20px;
            top: -26px;
            width: 96px;
            height: 96px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(148, 163, 184, 0.06), rgba(148, 163, 184, 0));
            pointer-events: none;
        }
        .hub-card:hover {
            transform: translateY(-2px);
            border-color: rgba(59, 130, 246, 0.16);
            box-shadow: 0 22px 42px rgba(15, 23, 42, 0.08);
        }
        .hub-card--green::before { background: #16a34a; }
        .hub-card--orange::before { background: #f97316; }
        .hub-card--purple::before { background: #7c3aed; }
        .hub-card--cyan::before { background: #0891b2; }
        .hub-card--pink::before { background: #ec4899; }
        .hub-card--blue::before { background: #2563eb; }
        .hub-card-top { display: flex; gap: 10px; align-items: flex-start; justify-content: flex-start; }
        .hub-head { display: flex; gap: 10px; align-items: flex-start; min-width: 0; flex: 1; }
        .hub-icon, .section-icon {
            width: 46px; height: 46px; border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .hub-icon svg, .section-icon svg { width: 20px; height: 20px; }
        .accent-blue { background: #eef4ff; color: var(--blue); }
        .accent-green { background: #ecfdf3; color: var(--green); }
        .accent-orange { background: #fff7ed; color: var(--orange); }
        .accent-purple { background: #f5f3ff; color: var(--purple); }
        .accent-cyan { background: #ecfeff; color: var(--cyan); }
        .accent-pink { background: #fdf2f8; color: var(--pink); }
        .hub-copy { min-width: 0; display: grid; gap: 3px; padding-top: 1px; }
        .hub-copy h3 { margin: 0; font-size: 0.96rem; line-height: 1.14; font-weight: 820; letter-spacing: -0.02em; color: #111827; }
        .hub-copy p { display: none; }
        .hub-meta {
            display: grid;
            gap: 4px;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
            margin-top: 4px;
            padding-right: 0;
        }
        .hub-meta strong { color: #0f172a; font-size: 1.38rem; line-height: 1; font-weight: 900; letter-spacing: -0.03em; }
        .hub-submeta {
            font-size: 0.76rem;
            color: #64748b;
            font-weight: 780;
            line-height: 1.22;
        }
        .hub-arrow {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 32px;
            height: 32px;
            border-radius: 999px;
            background: rgba(241, 245, 249, 0.96);
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 0 1px rgba(219, 234, 254, 0.95);
            flex-shrink: 0;
            margin-top: 0;
        }
        .hub-arrow svg { width: 15px; height: 15px; }
        .report-shell { padding: 16px; display: grid; gap: 18px; }
        .report-topline { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .reports-home-link { display: inline-flex; align-items: center; gap: 8px; color: #1d4ed8; font-size: 0.82rem; font-weight: 800; }
        .top-tab-bar { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: none; }
        .top-tab-bar::-webkit-scrollbar { display: none; }
        .top-tab {
            min-height: 42px; padding: 0 14px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface-soft);
            color: #475569; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;
            font-size: 0.78rem; font-weight: 800; box-shadow: var(--shadow-soft);
        }
        .top-tab.active { color: #fff; border-color: transparent; background: linear-gradient(135deg, #2563eb, #4f46e5); }
        .section-head { display: grid; gap: 8px; }
        .section-header-row { display: flex; align-items: center; gap: 12px; }
        .section-head h2 { margin: 0; font-size: 1.22rem; line-height: 1.1; font-weight: 900; color: #111827; }
        .section-head p { margin: 0; color: var(--muted); font-size: 0.83rem; line-height: 1.5; }
        .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .student-stats-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .metric-tile, .focus-card, .student-card, .distribution-card, .lecturer-card, .timeline-card, .note-card { padding: 15px; display: grid; gap: 12px; }
        .student-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .student-card {
            min-height: 170px;
            border-radius: 20px;
            background:
                linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,251,255,0.94)),
                var(--surface);
        }
        .student-card h3 {
            font-size: 0.9rem;
            line-height: 1.18;
            letter-spacing: -0.02em;
        }
        .student-card .meta-list {
            gap: 7px;
        }
        .student-card .meta-row {
            font-size: 0.74rem;
        }
        .student-card .meta-row strong {
            font-size: 0.76rem;
        }
        .student-card .status-row {
            margin-top: auto;
        }
        .student-card .status-pill {
            min-height: 28px;
            padding: 0 9px;
            font-size: 0.64rem;
        }
        .metric-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; font-weight: 800; }
        .metric-number { margin-top: 8px; font-size: 1.24rem; line-height: 1.05; font-weight: 900; color: #111827; }
        .metric-note { margin-top: 6px; color: var(--muted); font-size: 0.76rem; line-height: 1.4; }
        .focus-card h3, .student-card h3, .distribution-card h3, .lecturer-card h3, .pickup-card h3, .timeline-card h3 { margin: 0; font-size: 0.95rem; line-height: 1.25; font-weight: 800; color: #111827; }
        .meta-list { display: grid; gap: 8px; }
        .meta-row { display: flex; justify-content: space-between; gap: 10px; color: #475569; font-size: 0.78rem; }
        .meta-row strong { color: #111827; text-align: right; }
        .status-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .status-pill {
            min-height: 30px; padding: 0 10px; border-radius: 999px; font-size: 0.68rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em;
            display: inline-flex; align-items: center;
        }
        .status-paid, .status-collected { background: #ecfdf3; color: #15803d; }
        .status-unpaid, .status-pending { background: #fff7ed; color: #c2410c; }
        .status-partial { background: #eff6ff; color: #1d4ed8; }
        .pickup-section { display: grid; gap: 18px; }
        .pickup-heading { text-align: center; display: grid; gap: 8px; justify-items: center; }
        .pickup-heading h2 { margin: 0; font-size: 1.28rem; line-height: 1.05; font-weight: 900; }
        .pickup-heading p { margin: 0; color: var(--muted); font-size: 0.84rem; }
        .pickup-summary-card {
            padding: 24px 20px;
            text-align: center;
            display: grid;
            gap: 12px;
            background: linear-gradient(135deg, #eff6ff 0%, #eef2ff 52%, #f8faff 100%);
            border-color: #dbeafe;
            box-shadow: 0 18px 32px rgba(37, 99, 235, 0.12);
        }
        .pickup-summary-card span {
            color: #475569;
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .pickup-summary-card strong { font-size: 2rem; line-height: 1; color: #1d4ed8; letter-spacing: -0.03em; }
        .pickup-summary-subline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.08);
            color: #1e3a8a;
            font-size: 0.82rem;
            font-weight: 800;
        }
        .pickup-card {
            padding: 18px 16px;
            display: grid;
            gap: 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-soft);
            text-align: center;
            justify-items: center;
        }
        .pickup-card strong { font-size: 1rem; }
        .pickup-card-price {
            font-size: 0.74rem;
            color: var(--muted);
            font-weight: 700;
            line-height: 1.35;
        }
        .pickup-card.success { background: #f2fbf5; border-color: #bbf7d0; }
        .pickup-card.warning { background: #fff9f0; border-color: #fed7aa; }
        .pickup-card.danger { background: #fff3f3; border-color: #fecaca; }
        .timeline-card { background: var(--surface-soft); border: 1px solid var(--line); box-shadow: none; }
        .timeline-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .timeline-time { color: var(--muted); font-size: 0.72rem; text-align: right; white-space: nowrap; }
        .timeline-detail { color: #475569; font-size: 0.8rem; line-height: 1.5; }
        .card-toolbar { display: flex; gap: 10px; flex-wrap: wrap; }
        .toolbar-link, .toolbar-button {
            min-height: 42px; padding: 0 14px; border-radius: 14px; border: 1px solid var(--line); background: #fff;
            color: #1d4ed8; display: inline-flex; align-items: center; justify-content: center; font-size: 0.78rem; font-weight: 800; box-shadow: var(--shadow-soft); cursor: pointer;
        }
        .toolbar-button.primary { border-color: transparent; background: linear-gradient(135deg, #2563eb, #4f46e5); color: #fff; }
        .filters-form { display: grid; gap: 12px; }
        .filters-grid { display: grid; gap: 12px; }
        .filter-field { display: grid; gap: 8px; }
        .filter-field label { color: #475569; font-size: 0.74rem; font-weight: 700; }
        .filter-select, .search-input {
            width: 100%; min-height: 46px; padding: 0 14px; border-radius: 14px; border: 1px solid var(--line); background: #fff; color: var(--text);
        }
        .search-input { padding-left: 40px; }
        .search-wrap { position: relative; }
        .search-wrap svg { position: absolute; left: 14px; top: 40px; color: #94a3b8; }
        .empty-state, .note-card { padding: 20px 18px; color: var(--muted); font-size: 0.84rem; line-height: 1.6; }
        .empty-state { text-align: center; border: 1px dashed #cbd5e1; background: #f8fbff; box-shadow: none; }
        .note-card strong { display: block; margin-bottom: 6px; color: #111827; font-size: 0.92rem; }
        @media (max-width: 389px) {
            .hub-grid,
            .distribution-grid,
            .lecturer-grid,
            .book-summary-grid,
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .student-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .student-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .student-card {
                min-height: 156px;
                padding: 12px;
                border-radius: 18px;
                gap: 10px;
            }
            .student-card h3 {
                font-size: 0.82rem;
                line-height: 1.12;
            }
            .student-card .meta-row {
                font-size: 0.68rem;
            }
            .student-card .meta-row strong {
                font-size: 0.7rem;
            }
            .student-card .status-pill {
                font-size: 0.6rem;
                min-height: 26px;
                padding: 0 8px;
            }
        }
        @media (max-width: 559px) {
            .page-shell { gap: 14px; }
            .top-header { gap: 10px; }
            .back-button { width: 40px; height: 40px; border-radius: 14px; }
            .header-copy h1 { font-size: 1.22rem; }
            .header-copy p { margin-top: 4px; font-size: 0.72rem; }
            .semester-pill-row { gap: 8px; align-items: flex-start; padding: 11px 12px; border-radius: 19px; }
            .semester-pill { width: 100%; font-size: 0.74rem; padding: 9px 12px; }
            .last-updated { width: 100%; font-size: 0.68rem; }
            .hub-grid { gap: 12px; }
            .hub-card-top { gap: 10px; }
            .hub-icon { width: 44px; height: 44px; border-radius: 16px; }
            .hub-icon svg { width: 19px; height: 19px; }
            .hub-card { min-height: 142px; padding: 14px 14px 13px; gap: 9px; }
            .hub-copy h3 { font-size: 0.88rem; }
            .hub-meta strong { font-size: 1.18rem; }
            .hub-submeta { font-size: 0.72rem; }
            .hub-arrow { width: 28px; height: 28px; }
            .student-stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .student-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
            .student-card {
                min-height: 164px;
                padding: 13px;
                border-radius: 19px;
            }
        }
        @media (min-width: 560px) {
            body { padding: 24px 18px 56px; }
            .filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .search-wrap { grid-column: 1 / -1; }
        }
        @media (min-width: 920px) {
            body { padding: 28px 24px 64px; }
            .hub-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .book-summary-grid, .lecturer-grid, .distribution-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="reports-page">
<div class="page-shell">
    <header class="top-header">
        <a href="<?php echo htmlspecialchars($back_link); ?>" class="back-button" aria-label="Back">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 18-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <div class="header-copy">
            <h1>Reports</h1>
            <p><?php echo $report_view === 'hub' ? 'Your report shortcuts for this semester' : 'Focused report view for your current semester'; ?></p>
        </div>
    </header>

    <div class="semester-pill-row">
        <div class="semester-pill">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4h14a1 1 0 0 1 1 1v13l-4-2-4 2-4-2-4 2V5a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8 9h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span><?php echo htmlspecialchars($semester_pill_label); ?></span>
        </div>
        <div class="last-updated">Last updated: <?php echo htmlspecialchars($last_updated_label); ?></div>
    </div>

    <?php if ($report_view === 'hub'): ?>
        <section class="hub-grid">
            <?php foreach ($hub_cards as $card): ?>
                <a href="<?php echo htmlspecialchars($report_view_links[$card['view']]); ?>" class="hub-card hub-card--<?php echo htmlspecialchars($card['accent']); ?>">
                    <div class="hub-card-top">
                        <div class="hub-head">
                            <span class="hub-icon accent-<?php echo htmlspecialchars($card['accent']); ?>">
                                <?php if ($card['icon'] === 'wallet'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 8h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a3 3 0 0 1 0-6h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php elseif ($card['icon'] === 'students'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5.5 18a4.5 4.5 0 0 1 9 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10.5 9a4.5 4.5 0 0 1 9 0M15 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php elseif ($card['icon'] === 'box'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 12 4 7.5M12 12l8-4.5M12 12v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php elseif ($card['icon'] === 'receipt'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 4h10v16l-2-1.3L13 20l-2-1.3L9 20l-2-1.3L5 20V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 9h6M9 13h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php elseif ($card['icon'] === 'books'): ?>
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 5h6a2 2 0 0 1 2 2v12H8a2 2 0 0 0-2 2V5Zm8 2a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2h-4V7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 18a4 4 0 0 1 8 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10 9a4 4 0 0 1 8 0M14 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                <?php endif; ?>
                            </span>
                            <div class="hub-copy">
                                <h3><?php echo htmlspecialchars($card['title']); ?></h3>
                                <p><?php echo htmlspecialchars($card['description']); ?></p>
                            </div>
                        </div>
                        <span class="hub-arrow" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none"><path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                    </div>
                    <div class="hub-meta">
                        <strong><?php echo htmlspecialchars($card['meta']); ?></strong>
                        <?php if (!empty($card['submeta'])): ?>
                            <span class="hub-submeta"><?php echo htmlspecialchars(strval($card['submeta'])); ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </section>
    <?php else: ?>
        <section class="report-shell">
            <div class="report-topline">
                <a href="<?php echo htmlspecialchars($reports_hub_url); ?>" class="reports-home-link">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m15 18-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Reports</span>
                </a>
                <?php if ($report_view !== 'pickup'): ?>
                    <div class="card-toolbar">
                        <a href="<?php echo htmlspecialchars($excel_export_href); ?>" class="toolbar-link">Export Excel</a>
                    </div>
                <?php endif; ?>
            </div>

            <nav class="top-tab-bar" aria-label="Report tabs">
                <?php foreach ($report_tabs as $key => $label): ?>
                    <a href="<?php echo htmlspecialchars($report_view_links[$key]); ?>" class="top-tab<?php echo $report_view === $key ? ' active' : ''; ?>"><?php echo htmlspecialchars($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ($report_view === 'financial'): ?>
                <div class="section-head">
                    <div class="section-header-row">
                        <span class="section-icon accent-green"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 8h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a3 3 0 0 1 0-6h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                        <div><h2>Financial Summary</h2><p>Cash, outstanding balances, requests, and distribution totals for the active semester.</p></div>
                    </div>
                </div>
                <section class="stats-grid student-stats-grid">
                    <article class="metric-tile"><div class="metric-label">Total Cash Collected</div><div class="metric-number"><?php echo htmlspecialchars(book_system_reports_money(floatval($metrics['cash_collected'] ?? 0))); ?></div><div class="metric-note">Actual money received from students.</div></article>
                    <article class="metric-tile"><div class="metric-label">Outstanding Balance</div><div class="metric-number"><?php echo htmlspecialchars(book_system_reports_money($outstanding_balance)); ?></div><div class="metric-note">Positive remaining balances only.</div></article>
                    <article class="metric-tile"><div class="metric-label">Total Requests</div><div class="metric-number"><?php echo number_format($total_requests_count); ?></div><div class="metric-note">All requests recorded this semester.</div></article>
                    <article class="metric-tile"><div class="metric-label">Pending Requests</div><div class="metric-number"><?php echo number_format(intval($metrics['unpaid_requests'] ?? 0)); ?></div><div class="metric-note">Requests still needing settlement.</div></article>
                    <article class="metric-tile"><div class="metric-label">Books Given Out</div><div class="metric-number"><?php echo number_format(intval($metrics['collected_items'] ?? 0)); ?></div><div class="metric-note">Books marked collected by students.</div></article>
                    <article class="metric-tile"><div class="metric-label">Paid to Lecturers</div><div class="metric-number"><?php echo htmlspecialchars(book_system_reports_money($lecturer_paid_total)); ?></div><div class="metric-note">Amount already settled to lecturers.</div></article>
                </section>
            <?php elseif ($report_view === 'student'): ?>
                <div class="section-head">
                    <div class="section-header-row">
                        <span class="section-icon accent-blue"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 5h9a2 2 0 0 1 2 2v12l-4-2-4 2-4-2-4 2V7a2 2 0 0 1 2-2h3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M7 9h7M7 13h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                        <div><h2>Student Requests</h2><p>Request summaries, payment states, and the most recent student activity.</p></div>
                    </div>
                </div>
                <section class="stats-grid student-stats-grid">
                    <article class="metric-tile"><div class="metric-label">Total Requests</div><div class="metric-number"><?php echo number_format($total_requests_count); ?></div></article>
                    <article class="metric-tile"><div class="metric-label">Paid / Completed</div><div class="metric-number"><?php echo number_format($paid_requests_count); ?></div></article>
                    <article class="metric-tile"><div class="metric-label">Partial</div><div class="metric-number"><?php echo number_format($partial_requests_count); ?></div></article>
                    <article class="metric-tile"><div class="metric-label">Unpaid</div><div class="metric-number"><?php echo number_format($unpaid_requests_count); ?></div></article>
                    <article class="metric-tile"><div class="metric-label">Students Paid</div><div class="metric-number"><?php echo number_format($students_paid_total); ?></div></article>
                    <article class="metric-tile"><div class="metric-label">Books Given Out</div><div class="metric-number"><?php echo number_format(intval($metrics['collected_items'] ?? 0)); ?></div></article>
                </section>
                <section class="focus-card">
                    <h3>Filter Request Preview</h3>
                    <form method="GET" class="filters-form">
                        <input type="hidden" name="view" value="student">
                        <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
                        <div class="filters-grid">
                            <div class="filter-field">
                                <label for="book_id">Book</label>
                                <select id="book_id" name="book_id" class="filter-select">
                                    <option value="0">All Books</option>
                                    <?php foreach ($book_rows as $book_row): ?>
                                        <option value="<?php echo intval($book_row['book_id'] ?? 0); ?>" <?php echo $selected_book_id === intval($book_row['book_id'] ?? 0) ? 'selected' : ''; ?>><?php echo htmlspecialchars(strval($book_row['book_title'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="filter-select">
                                    <?php foreach ($status_filter_options as $status_value => $status_label): ?>
                                        <option value="<?php echo htmlspecialchars($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>><?php echo htmlspecialchars($status_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field search-wrap">
                                <label for="search">Search</label>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.7"/><path d="m16 16 4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                                <input type="text" id="search" name="search" class="search-input" placeholder="Search student, index no., or book..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>
                        <div class="card-toolbar">
                            <button type="submit" class="toolbar-button primary">Apply Filters</button>
                            <a href="<?php echo htmlspecialchars($report_view_links['student']); ?>" class="toolbar-link">Reset</a>
                        </div>
                    </form>
                </section>
                <?php if (!empty($preview_rows)): ?>
                    <section class="student-grid">
                        <?php foreach ($preview_rows as $row): ?>
                            <article class="student-card">
                                <h3><?php echo htmlspecialchars(strval($row['full_name'] ?? 'Student')); ?></h3>
                                <div class="meta-list">
                                    <div class="meta-row"><span>Index</span><strong><?php echo htmlspecialchars(strval($row['index_number'] ?? '')); ?></strong></div>
                                    <div class="meta-row"><span>Books</span><strong><?php echo htmlspecialchars(strval($row['books_requested'] ?? '')); ?></strong></div>
                                    <div class="meta-row"><span>Total</span><strong><?php echo htmlspecialchars(book_system_reports_money(floatval($row['display_amount'] ?? 0))); ?></strong></div>
                                </div>
                                <div class="status-row">
                                    <span class="status-pill status-<?php echo htmlspecialchars(strval($row['payment_state'] ?? 'pending')); ?>"><?php echo ucfirst(htmlspecialchars(strval($row['payment_state'] ?? 'pending'))); ?></span>
                                    <span class="status-pill status-<?php echo htmlspecialchars(strval($row['collection_state'] ?? 'pending')); ?>"><?php echo ucfirst(htmlspecialchars(strval($row['collection_state'] ?? 'pending'))); ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php else: ?>
                    <div class="empty-state">No student requests found for this filter.</div>
                <?php endif; ?>
                <?php if (!empty($distribution_rows)): ?>
                    <section class="focus-card">
                        <h3>Book-level Request Summary</h3>
                        <div class="book-summary-grid">
                            <?php foreach (array_slice($distribution_rows, 0, 6) as $row): ?>
                                <article class="distribution-card">
                                    <h3><?php echo htmlspecialchars(strval($row['book_title'] ?? 'Book')); ?></h3>
                                    <div class="meta-list">
                                        <div class="meta-row"><span>Requested</span><strong><?php echo number_format(intval($row['requested_copies'] ?? 0)); ?></strong></div>
                                        <div class="meta-row"><span>Collected</span><strong><?php echo number_format(intval($row['collected_copies'] ?? 0)); ?></strong></div>
                                        <div class="meta-row"><span>Pending</span><strong><?php echo number_format(intval($row['pending_collection'] ?? 0)); ?></strong></div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php elseif ($report_view === 'pickup'): ?>
                <section class="pickup-section">
                    <div class="pickup-heading"><h2>Pickup Needed</h2><p>Books waiting to be collected from lecturers</p></div>
                    <div class="pickup-summary-card">
                        <span>Total Books to Collect</span>
                        <strong><?php echo number_format($pickup_needed_total); ?> Copies</strong>
                        <div class="pickup-summary-cost">
                            <em>GHS Amount</em>
                            <b><?php echo htmlspecialchars(book_system_reports_money($pickup_needed_cost_total)); ?></b>
                        </div>
                        <div class="pickup-summary-footnote">Across <?php echo number_format($pickup_needed_book_count); ?> Books</div>
                    </div>
                    <?php if (!empty($pickup_needed_rows)): ?>
                        <div class="pickup-grid">
                            <?php foreach ($pickup_needed_rows as $pickup_row): ?>
                                <?php $pickup_count = max(0, intval($pickup_row['pickup_needed'] ?? 0)); $pickup_tone = $pickup_count <= 2 ? 'success' : ($pickup_count <= 5 ? 'warning' : 'danger'); ?>
                                <article class="pickup-card <?php echo htmlspecialchars($pickup_tone); ?>">
                                    <h3><?php echo htmlspecialchars(strval($pickup_row['book_title'] ?? 'Book')); ?></h3>
                                    <strong><?php echo number_format($pickup_count); ?> Copies</strong>
                                    <div class="pickup-card-price">
                                        <?php echo htmlspecialchars(book_system_reports_money($pickup_count * max(0.0, floatval($pickup_row['book_price'] ?? 0)))); ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">You’re all set.<br>No extra books need to be picked up right now.</div>
                    <?php endif; ?>
                </section>
            <?php elseif ($report_view === 'lecturer'): ?>
                <div class="section-head">
                    <div class="section-header-row">
                        <span class="section-icon accent-purple"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 4h10v16l-2-1.3L13 20l-2-1.3L9 20l-2-1.3L5 20V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 9h6M9 13h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                        <div><h2>Lecturer Payments</h2><p>Track lecturer name, book, amount due, amount paid, and the current balance.</p></div>
                    </div>
                </div>
                <?php if (!empty($lecturer_report_rows)): ?>
                    <section class="lecturer-grid">
                        <?php foreach ($lecturer_report_rows as $row): ?>
                            <article class="lecturer-card">
                                <h3><?php echo htmlspecialchars(strval($row['lecturer_name'] ?? 'Lecturer not specified')); ?></h3>
                                <div class="meta-list">
                                    <div class="meta-row"><span>Book</span><strong><?php echo htmlspecialchars(strval($row['book_title'] ?? 'Book')); ?></strong></div>
                                    <div class="meta-row"><span>Amount Due</span><strong><?php echo htmlspecialchars(book_system_reports_money(floatval($row['amount_due'] ?? 0))); ?></strong></div>
                                    <div class="meta-row"><span>Amount Paid</span><strong><?php echo htmlspecialchars(book_system_reports_money(floatval($row['amount_paid'] ?? 0))); ?></strong></div>
                                    <div class="meta-row"><span>Balance</span><strong><?php echo htmlspecialchars(book_system_reports_money(floatval($row['balance_due'] ?? 0))); ?></strong></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php else: ?>
                    <div class="empty-state">No lecturer payment activity has been recorded for this semester yet.</div>
                <?php endif; ?>
            <?php elseif ($report_view === 'distribution'): ?>
                <div class="section-head">
                    <div class="section-header-row">
                        <span class="section-icon accent-cyan"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 12 4 7.5M12 12l8-4.5M12 12v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                        <div><h2>Books Distribution</h2><p>See books given out, pending collection, and the current distribution picture.</p></div>
                    </div>
                </div>
                <section class="stats-grid">
                    <article class="metric-tile"><div class="metric-label">Books Given Out</div><div class="metric-number"><?php echo number_format(intval($metrics['collected_items'] ?? 0)); ?></div></article>
                    <article class="metric-tile"><div class="metric-label">Pending Collection</div><div class="metric-number"><?php echo number_format(intval($metrics['pending_items'] ?? 0)); ?></div></article>
                    <article class="metric-tile"><div class="metric-label">Books Remaining</div><div class="metric-number"><?php echo number_format($books_remaining_total); ?></div></article>
                </section>
                <?php if (!empty($distribution_rows)): ?>
                    <section class="distribution-grid">
                        <?php foreach ($distribution_rows as $row): ?>
                            <article class="distribution-card">
                                <h3><?php echo htmlspecialchars(strval($row['book_title'] ?? 'Book')); ?></h3>
                                <div class="meta-list">
                                    <div class="meta-row"><span>Requested</span><strong><?php echo number_format(intval($row['requested_copies'] ?? 0)); ?></strong></div>
                                    <div class="meta-row"><span>Given Out</span><strong><?php echo number_format(intval($row['collected_copies'] ?? 0)); ?></strong></div>
                                    <div class="meta-row"><span>Pending</span><strong><?php echo number_format(intval($row['pending_collection'] ?? 0)); ?></strong></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php else: ?>
                    <div class="empty-state">No distribution records are available yet for this semester.</div>
                <?php endif; ?>
            <?php elseif ($report_view === 'activity'): ?>
                <div class="section-head">
                    <div class="section-header-row">
                        <span class="section-icon accent-pink"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 18a4 4 0 0 1 8 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10 9a4 4 0 0 1 8 0M14 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                        <div><h2>Activity Log</h2><p>Recent request, payment, and collection events for your current class workspace.</p></div>
                    </div>
                </div>
                <?php if (!empty($activity_rows)): ?>
                    <section class="timeline-list">
                        <?php foreach ($activity_rows as $activity): ?>
                            <article class="timeline-card">
                                <div class="timeline-top">
                                    <h3><?php echo htmlspecialchars(book_system_reports_activity_label($activity)); ?></h3>
                                    <div class="timeline-time"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime(strval($activity['created_at'] ?? 'now')))); ?></div>
                                </div>
                                <?php if (trim(strval($activity['details'] ?? '')) !== ''): ?>
                                    <div class="timeline-detail"><?php echo htmlspecialchars(strval($activity['details'])); ?></div>
                                <?php endif; ?>
                                <div class="status-row">
                                    <?php if (trim(strval($activity['admin_name'] ?? '')) !== ''): ?><span class="status-pill status-partial"><?php echo htmlspecialchars(strval($activity['admin_name'])); ?></span><?php endif; ?>
                                    <?php if (trim(strval($activity['student_name'] ?? '')) !== ''): ?><span class="status-pill status-collected"><?php echo htmlspecialchars(strval($activity['student_name'])); ?></span><?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </section>
                <?php else: ?>
                    <div class="empty-state">No recent activity yet.</div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php
if (function_exists('book_system_render_refresh_polling_script')) {
    book_system_render_refresh_polling_script($reports_refresh_token, [
        'interval_ms' => 15000,
        'min_gap_ms' => 10000,
        'pause_selectors' => [
            '.filter-select:focus',
            '.filter-input:focus',
        ],
    ]);
}
?>
<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
<?php include 'footer.php'; ?>
</body>
</html>
<?php exit; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports</title>
    <style>
        :root {
            --bg: #f6f8fc;
            --surface: #ffffff;
            --surface-soft: #fbfcff;
            --line: #e6edf7;
            --text: #1f2937;
            --muted: #6b7280;
            --primary: #2563eb;
            --green: #16a34a;
            --red: #ef4444;
            --purple: #7c3aed;
            --orange: #f97316;
            --blue: #2563eb;
            --cyan: #06b6d4;
            --pink: #ec4899;
            --shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 10px 24px rgba(15, 23, 42, 0.05);
            --radius-xl: 24px;
            --radius-lg: 18px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background:
                radial-gradient(circle at top, rgba(37, 99, 235, 0.08), transparent 28%),
                linear-gradient(180deg, #ffffff 0%, #f7f9fc 50%, #f2f6fb 100%);
            min-height: 100vh;
            color: var(--text);
            padding: 16px 10px 48px;
        }
        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; }
        .page-shell {
            width: 100%;
            max-width: 1180px;
            margin: 0 auto;
        }
        .reports-workspace {
            display: grid;
            gap: 18px;
            margin-top: 6px;
        }
        .report-main {
            min-width: 0;
        }
        .report-sidebar {
            display: grid;
            gap: 12px;
        }
        .report-sidebar-card,
        .report-panel {
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }
        .report-sidebar-card {
            padding: 14px;
        }
        .report-sidebar-title {
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 10px;
        }
        .report-nav {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }
        .report-nav::-webkit-scrollbar {
            display: none;
        }
        .report-nav-button {
            border: 1px solid var(--line);
            background: #fff;
            color: #334155;
            border-radius: 14px;
            min-height: 46px;
            padding: 11px 13px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: var(--shadow-soft);
            white-space: nowrap;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }
        .report-nav-button:hover {
            transform: translateY(-1px);
            border-color: rgba(37, 99, 235, 0.18);
        }
        .report-nav-button.is-active {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(135deg, #1d4ed8, #3b82f6);
            box-shadow: 0 16px 30px rgba(37, 99, 235, 0.2);
        }
        .report-nav-button svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        .report-nav-copy {
            display: grid;
            gap: 2px;
            text-align: left;
        }
        .report-nav-copy strong {
            font-size: 0.8rem;
            line-height: 1.15;
        }
        .report-nav-copy span {
            font-size: 0.66rem;
            line-height: 1.25;
            color: inherit;
            opacity: 0.78;
            font-weight: 700;
        }
        .report-panel {
            padding: 16px;
            display: none;
            gap: 18px;
        }
        .report-panel.is-active {
            display: grid;
        }
        .panel-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .panel-heading-copy {
            display: grid;
            gap: 4px;
            min-width: 0;
        }
        .panel-heading h2 {
            font-size: 1.12rem;
            line-height: 1.15;
            font-weight: 900;
            color: #111827;
        }
        .panel-heading p {
            font-size: 0.78rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .report-home-link {
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.76rem;
            font-weight: 800;
            box-shadow: var(--shadow-soft);
            flex-shrink: 0;
        }
        .top-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        .back-button,
        .filter-button {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: rgba(255, 255, 255, 0.92);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-soft);
            flex-shrink: 0;
        }
        .header-copy { flex: 1; min-width: 0; }
        .header-copy h1 {
            font-size: 1.5rem;
            line-height: 1.1;
            font-weight: 800;
            color: #111827;
        }
        .header-copy p {
            margin-top: 4px;
            font-size: 0.78rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .semester-pill-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 20px;
            background: rgba(255,255,255,0.78);
            border: 1px solid rgba(226, 232, 240, 0.92);
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.05);
        }
        .semester-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            background: #ffffff;
            border: 1px solid rgba(191, 219, 254, 0.92);
            border-radius: 999px;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.08);
            color: #1d4ed8;
            font-weight: 800;
            font-size: 0.82rem;
        }
        .last-updated {
            font-size: 0.76rem;
            color: #64748b;
            font-weight: 700;
        }
        .section-heading {
            font-size: 1rem;
            font-weight: 800;
            color: #111827;
            margin: 2px 4px 10px;
        }
        .hub-intro {
            display: grid;
            gap: 5px;
            margin: 2px 4px 16px;
        }
        .hub-intro h2 {
            font-size: 1.08rem;
            line-height: 1.12;
            font-weight: 900;
            color: #0f172a;
        }
        .hub-intro p {
            font-size: 0.78rem;
            line-height: 1.4;
            color: #64748b;
        }
        .overview-grid,
        .report-grid {
            display: grid;
            gap: 12px;
        }
        [id] {
            scroll-margin-top: 84px;
        }
        .overview-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .metric-card,
        .report-card,
        .filters-card,
        .preview-card,
        .export-card,
        .activity-card {
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.9);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
        }
        .metric-card {
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid transparent;
            width: 100%;
            text-align: left;
            border-top: none;
            border-right: none;
            border-bottom: none;
            cursor: pointer;
        }
        .overview-grid .metric-card {
            min-height: 132px;
            padding: 12px 11px;
            display: grid;
            grid-template-columns: 40px minmax(0, 1fr);
            gap: 8px 10px;
            align-items: start;
        }
        .metric-card.green { border-left-color: #16a34a; }
        .metric-card.red { border-left-color: #ef4444; }
        .metric-card.blue { border-left-color: #2563eb; }
        .metric-card.purple { border-left-color: #7c3aed; }
        .metric-card.orange { border-left-color: #f97316; }
        .metric-icon {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .metric-icon.green { background: #ecfdf3; color: var(--green); }
        .metric-icon.red { background: #fff1f2; color: var(--red); }
        .metric-icon.blue { background: #eef4ff; color: var(--blue); }
        .metric-icon.purple { background: #f5f3ff; color: var(--purple); }
        .metric-icon.orange { background: #fff7ed; color: var(--orange); }
        .metric-icon svg { width: 26px; height: 26px; }
        .overview-grid .metric-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
        }
        .overview-grid .metric-icon svg {
            width: 18px;
            height: 18px;
        }
        .metric-copy { flex: 1; min-width: 0; }
        .overview-grid .metric-copy {
            display: grid;
            gap: 4px;
            align-self: start;
        }
        .metric-copy h3 {
            font-size: 0.95rem;
            font-weight: 800;
            color: #111827;
            margin-bottom: 4px;
        }
        .overview-grid .metric-copy h3 {
            font-size: 0.8rem;
            line-height: 1.2;
            margin-bottom: 0;
        }
        .metric-copy p {
            font-size: 0.75rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .overview-grid .metric-copy p {
            font-size: 0.64rem;
            line-height: 1.35;
        }
        .metric-value {
            text-align: right;
            min-width: 96px;
        }
        .overview-grid .metric-value {
            grid-column: 1 / -1;
            min-width: 0;
            text-align: right;
            margin-top: 2px;
        }
        .metric-value strong {
            display: block;
            font-size: 1.22rem;
            line-height: 1.15;
            font-weight: 800;
        }
        .overview-grid .metric-value strong {
            font-size: 1.08rem;
            line-height: 1.1;
        }
        .metric-value.green strong { color: var(--green); }
        .metric-value.red strong { color: var(--red); }
        .metric-value.blue strong { color: var(--blue); }
        .metric-value.purple strong { color: var(--purple); }
        .metric-value.orange strong { color: var(--orange); }
        .metric-value span {
            display: block;
            margin-top: 8px;
            font-size: 0.72rem;
            color: #94a3b8;
        }
        .overview-grid .metric-value span {
            margin-top: 4px;
            font-size: 0.62rem;
            line-height: 1.3;
        }
        .report-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .report-card {
            min-height: 172px;
            padding: 16px 16px 15px;
            display: grid;
            gap: 12px;
            align-content: space-between;
            cursor: pointer;
            position: relative;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            overflow: hidden;
        }
        .report-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            border-radius: 20px 0 0 20px;
            background: #cbd5e1;
        }
        .report-card:hover {
            transform: translateY(-2px);
            border-color: rgba(59, 130, 246, 0.16);
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.08);
        }
        .report-card--green::before { background: #16a34a; }
        .report-card--orange::before { background: #f97316; }
        .report-card--purple::before { background: #7c3aed; }
        .report-card--cyan::before { background: #0891b2; }
        .report-card--pink::before { background: #ec4899; }
        .report-card--blue::before { background: #2563eb; }
        .report-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .report-head {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }
        .report-icon {
            width: 56px;
            height: 56px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .report-icon.blue { background: #eef4ff; color: var(--blue); }
        .report-icon.green { background: #ecfdf3; color: var(--green); }
        .report-icon.orange { background: #fff7ed; color: var(--orange); }
        .report-icon.purple { background: #f5f3ff; color: var(--purple); }
        .report-icon.cyan { background: #ecfeff; color: var(--cyan); }
        .report-icon.pink { background: #fdf2f8; color: var(--pink); }
        .report-icon svg { width: 24px; height: 24px; }
        .report-chevron {
            width: 38px;
            height: 38px;
            border-radius: 999px;
            background: #eef4ff;
            color: #2563eb;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .report-chevron svg {
            width: 18px;
            height: 18px;
        }
        .report-copy {
            flex: 1;
            min-width: 0;
        }
        .report-copy h3 {
            font-size: 0.94rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.22;
            margin: 0;
        }
        .report-copy p {
            margin-top: 4px;
            font-size: 0.74rem;
            color: #64748b;
            line-height: 1.42;
        }
        .report-primary {
            display: grid;
            gap: 5px;
        }
        .report-primary-label {
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
        }
        .report-primary-value {
            font-size: 1.5rem;
            line-height: 1;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.02em;
        }
        .report-secondary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding-top: 10px;
            border-top: 1px solid #edf2f7;
        }
        .report-secondary-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
        }
        .report-secondary-value {
            font-size: 0.84rem;
            font-weight: 800;
            color: #334155;
        }
        .report-actions {
            display: none;
        }
        .report-link {
            flex: 1;
            min-height: 42px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.84rem;
            font-weight: 800;
            box-shadow: 0 12px 24px rgba(37, 99, 235, 0.18);
            border: none;
            cursor: pointer;
        }
        .report-link.blue { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .report-link.green { background: linear-gradient(135deg, #16a34a, #22c55e); }
        .report-link.orange { background: linear-gradient(135deg, #f97316, #fb923c); }
        .report-link.purple { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        .report-link.cyan { background: linear-gradient(135deg, #0891b2, #06b6d4); }
        .report-link.pink { background: linear-gradient(135deg, #db2777, #ec4899); }
        .report-export {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #0f766e;
            box-shadow: var(--shadow-soft);
            flex-shrink: 0;
        }
        .filters-card,
        .preview-card,
        .activity-card,
        .export-card {
            padding: 16px;
        }
        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .card-head h2 {
            font-size: 0.98rem;
            font-weight: 800;
            color: #111827;
        }
        .card-head p {
            margin-top: 4px;
            font-size: 0.74rem;
            color: var(--muted);
        }
        .chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }
        .filter-chip {
            padding: 10px 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
            box-shadow: var(--shadow-soft);
        }
        .filter-chip.active {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: #fff;
            border-color: transparent;
        }
        .filters-form {
            display: grid;
            gap: 12px;
        }
        .filters-grid {
            display: grid;
            gap: 12px;
        }
        .filter-field label {
            display: block;
            margin-bottom: 7px;
            font-size: 0.78rem;
            color: #475569;
            font-weight: 700;
        }
        .filter-select,
        .search-input {
            width: 100%;
            min-height: 46px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            padding: 0 14px;
            color: #111827;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.92);
        }
        .search-wrap {
            position: relative;
        }
        .search-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }
        .search-input {
            padding-left: 42px;
        }
        .filters-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .apply-button,
        .reset-link {
            min-height: 46px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 18px;
            font-weight: 800;
        }
        .apply-button {
            border: none;
            background: linear-gradient(135deg, #2563eb, #3b82f6);
            color: #fff;
            box-shadow: 0 14px 26px rgba(37, 99, 235, 0.18);
            cursor: pointer;
        }
        .reset-link {
            border: 1px solid var(--line);
            background: #fff;
            color: #475569;
        }
        .preview-summary {
            font-size: 0.76rem;
            color: #64748b;
        }
        .table-wrap {
            display: none;
        }
        .student-card-list {
            display: grid;
            gap: 12px;
        }
        .student-card {
            padding: 14px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            box-shadow: var(--shadow-soft);
        }
        .student-card h3 {
            font-size: 0.95rem;
            font-weight: 800;
            color: #111827;
            margin-bottom: 10px;
        }
        .student-meta-grid {
            display: grid;
            gap: 8px;
        }
        .student-meta-row {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
        }
        .student-meta-row span:first-child {
            font-size: 0.76rem;
            color: #64748b;
            font-weight: 700;
            min-width: 84px;
        }
        .student-meta-row span:last-child {
            text-align: right;
            font-size: 0.8rem;
            color: #111827;
            font-weight: 700;
            line-height: 1.45;
            flex: 1;
        }
        .status-pill-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 800;
        }
        .status-paid { background: #dcfce7; color: #15803d; }
        .status-unpaid { background: #fee2e2; color: #dc2626; }
        .status-partial { background: #ffedd5; color: #ea580c; }
        .status-collected { background: #dbeafe; color: #1d4ed8; }
        .status-pending { background: #e5e7eb; color: #4b5563; }
        .empty-state {
            padding: 20px 18px;
            border-radius: 18px;
            background: linear-gradient(135deg, #f9fbff 0%, #f3f6fb 100%);
            border: 1px dashed #d6dfef;
            text-align: center;
            color: #64748b;
            font-size: 0.84rem;
            line-height: 1.6;
        }
        .pickup-section {
            display: grid;
            gap: 16px;
        }
        .pickup-head {
            text-align: center;
            margin-bottom: 0;
        }
        .pickup-head h2 {
            font-size: 1.08rem;
        }
        .pickup-summary-card {
            width: min(100%, 420px);
            margin: 0 auto;
            padding: 22px 20px 20px;
            border-radius: 28px;
            background: linear-gradient(180deg, #dfe8f6 0%, #ced9ea 100%);
            border: 1.5px solid #7fa0d8;
            text-align: center;
            box-shadow: 0 14px 30px rgba(59, 130, 246, 0.12);
        }
        .pickup-summary-card span {
            display: block;
            color: rgba(255, 255, 255, 0.92);
            font-size: 0.73rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }
        .pickup-summary-card strong {
            display: block;
            color: #ffffff;
            font-size: 1.7rem;
            line-height: 1.1;
            font-weight: 900;
        }
        .pickup-summary-cost {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.26);
        }
        .pickup-summary-cost em {
            display: block;
            color: rgba(255, 255, 255, 0.8);
            font-style: normal;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 5px;
        }
        .pickup-summary-cost b {
            color: #ffffff;
            font-size: 1.08rem;
            font-weight: 900;
        }
        .pickup-summary-footnote {
            margin-top: 10px;
            font-size: 0.82rem;
            font-weight: 800;
            color: rgba(255, 255, 255, 0.88);
        }
        .pickup-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .pickup-card {
            min-height: 152px;
            padding: 18px 14px 16px;
            border-radius: 22px;
            border: 1.5px solid #7fa0d8;
            background: #fff4ea;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
            display: grid;
            align-content: start;
            justify-items: center;
            gap: 10px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .pickup-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 6px;
            background: #f59e0b;
        }
        .pickup-card h3 {
            margin: 0;
            color: #111827;
            font-size: 0.96rem;
            line-height: 1.2;
            font-weight: 900;
            word-break: break-word;
            min-height: 2.3em;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pickup-card .pickup-figure {
            display: grid;
            gap: 7px;
            justify-items: center;
            width: 100%;
            padding: 10px 8px 4px;
            border-radius: 14px;
            background: transparent;
            box-shadow: none;
        }
        .pickup-card .pickup-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 6px;
            font-size: 1.02rem;
            line-height: 1.15;
            font-weight: 900;
            color: #1f2937;
            text-align: center;
        }
        .pickup-card .pickup-amount {
            font-size: 0.8rem;
            font-weight: 900;
            color: #6b7280;
            text-align: center;
        }
        .pickup-card.success {
            background: linear-gradient(180deg, #fff7ef 0%, #ffefd9 100%);
            border-color: #8fb0df;
        }
        .pickup-card.success::before {
            background: linear-gradient(90deg, #22c55e, #16a34a);
        }
        .pickup-card.success .pickup-count,
        .pickup-card.success .pickup-amount {
            color: #166534;
        }
        .pickup-card.warning {
            background: linear-gradient(180deg, #fff6ec 0%, #ffe8d3 100%);
            border-color: #8fb0df;
        }
        .pickup-card.warning::before {
            background: linear-gradient(90deg, #fb923c, #f97316);
        }
        .pickup-card.warning .pickup-count,
        .pickup-card.warning .pickup-amount {
            color: #c2410c;
        }
        .pickup-card.danger {
            background: linear-gradient(180deg, #fff4f0 0%, #ffe1db 100%);
            border-color: #8fb0df;
        }
        .pickup-card.danger::before {
            background: linear-gradient(90deg, #f43f5e, #e11d48);
        }
        .pickup-card.danger .pickup-count,
        .pickup-card.danger .pickup-amount {
            color: #be123c;
        }
        @media (max-width: 759px) {
            .pickup-summary-card {
                width: 100%;
                max-width: none;
                border-radius: 24px;
            }
            .pickup-summary-footnote {
                font-size: 0.76rem;
            }
            .pickup-grid {
                gap: 12px;
            }
            .pickup-card {
                min-height: 142px;
                padding: 16px 12px 14px;
                border-radius: 20px;
            }
            .pickup-card h3 {
                font-size: 0.9rem;
            }
            .pickup-card .pickup-count {
                font-size: 0.94rem;
            }
            .pickup-card .pickup-amount {
                font-size: 0.76rem;
            }
        }
        .activity-list {
            display: grid;
            gap: 12px;
        }
        .activity-item {
            padding: 16px;
            border-radius: 20px;
            border: 1px solid var(--line);
            border-left: 4px solid #2563eb;
            background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: var(--shadow-soft);
        }
        .activity-item h3 {
            font-size: 0.95rem;
            font-weight: 800;
            color: #111827;
            margin-bottom: 10px;
            padding-left: 2px;
        }
        .activity-topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .activity-actor {
            font-size: 0.79rem;
            color: #475569;
            font-weight: 700;
        }
        .activity-time {
            font-size: 0.73rem;
            color: #94a3b8;
            font-weight: 700;
        }
        .activity-chip-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .activity-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 800;
        }
        .activity-details {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid var(--line);
            color: #475569;
            font-size: 0.75rem;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .export-actions {
            display: grid;
            gap: 12px;
        }
        .export-button {
            min-height: 50px;
            border-radius: 16px;
            border: none;
            color: #fff;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
            cursor: pointer;
            padding: 0 18px;
            width: 100%;
        }
        .export-button.green { background: linear-gradient(135deg, #16a34a, #22c55e); }
        .export-button.red { background: linear-gradient(135deg, #ef4444, #f97316); }
        .export-button.purple { background: linear-gradient(135deg, #7c3aed, #8b5cf6); }
        .export-button svg { width: 19px; height: 19px; }
        .jump-section {
            position: relative;
            transition: box-shadow 0.28s ease, border-color 0.28s ease, background-color 0.28s ease;
        }
        .scroll-highlight {
            border-color: rgba(37, 99, 235, 0.22) !important;
            box-shadow:
                0 0 0 3px rgba(59, 130, 246, 0.10),
                0 22px 42px rgba(37, 99, 235, 0.12) !important;
            background:
                linear-gradient(180deg, rgba(239, 246, 255, 0.92) 0%, rgba(255, 255, 255, 0.98) 22%),
                #ffffff !important;
        }
        .inline-section-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #fff;
            color: #2563eb;
            font-size: 0.78rem;
            font-weight: 800;
            box-shadow: var(--shadow-soft);
        }
        .inline-section-link:hover {
            border-color: rgba(37, 99, 235, 0.18);
        }
        .reports-page,
        .reports-page .page-shell,
        .reports-page .reports-workspace,
        .reports-page .report-main,
        .reports-page .report-sidebar,
        .reports-page .report-panel,
        .reports-page .report-grid,
        .reports-page .overview-grid,
        .reports-page .pickup-grid,
        .reports-page .student-grid,
        .reports-page .distribution-grid,
        .reports-page .lecturer-grid,
        .reports-page .activity-list,
        .reports-page .filters-grid,
        .reports-page .table-wrap {
            min-width: 0;
            max-width: 100%;
        }
        .reports-page {
            overflow-x: hidden;
        }
        .reports-page .report-copy,
        .reports-page .metric-copy,
        .reports-page .student-meta-row span:last-child,
        .reports-page .activity-details,
        .reports-page .pickup-card h3,
        .reports-page .mini-chip .mini-value {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        @media (max-width: 759px) {
            body {
                padding: 14px 8px 46px;
            }
            .reports-page {
                background: #f7f9fc;
                overflow-x: hidden;
            }
            .reports-page .page-shell {
                width: 100%;
            }
            .reports-page .report-sidebar-card,
            .reports-page .report-panel,
            .reports-page .metric-card,
            .reports-page .report-card,
            .reports-page .filters-card,
            .reports-page .preview-card,
            .reports-page .export-card,
            .reports-page .activity-card,
            .reports-page .student-card,
            .reports-page .pickup-card,
            .reports-page .distribution-card,
            .reports-page .lecturer-card,
            .reports-page .activity-item,
            .reports-page .pickup-summary-card {
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06) !important;
                background-image: none !important;
                filter: none !important;
                backdrop-filter: none !important;
            }
            .reports-page .report-nav-button,
            .reports-page .back-button,
            .reports-page .filter-button,
            .reports-page .inline-section-link,
            .reports-page .pickup-list-button {
                box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05) !important;
                transform: none !important;
                transition: none !important;
                filter: none !important;
                backdrop-filter: none !important;
            }
            .reports-page .report-nav-button:hover,
            .reports-page .inline-section-link:hover {
                transform: none !important;
                box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05) !important;
            }
            .reports-page .report-nav-button.is-active,
            .reports-page .report-link,
            .reports-page .apply-button,
            .reports-page .export-button {
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.14) !important;
            }
            .reports-page .scroll-highlight {
                box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.08) !important;
                background: #ffffff !important;
            }
            .reports-page .pickup-card::before {
                height: 4px;
            }
            .reports-page .report-nav {
                overflow-x: auto;
                overscroll-behavior-x: contain;
            }
            .reports-page .report-nav-button {
                min-width: 0;
                max-width: 100%;
            }
            .reports-page .report-nav-copy span {
                display: none;
            }
            .reports-page .report-panel,
            .reports-page .activity-card,
            .reports-page .filters-card,
            .reports-page .preview-card,
            .reports-page .export-card {
                overflow: hidden;
            }
            .reports-page .panel-heading {
                align-items: center;
            }
            .reports-page .panel-heading p,
            .reports-page .card-head p {
                display: none;
            }
            .reports-page .report-home-link {
                min-height: 36px;
                padding: 0 12px;
                font-size: 0.72rem;
            }
            .report-grid {
                gap: 10px;
            }
            .report-card {
                min-height: 160px;
                padding: 14px 13px 13px;
            }
            .report-top {
                gap: 10px;
            }
            .report-icon {
                width: 46px;
                height: 46px;
                border-radius: 16px;
            }
            .report-icon svg {
                width: 20px;
                height: 20px;
            }
            .report-copy h3 {
                font-size: 0.88rem;
            }
            .report-copy p {
                font-size: 0.66rem;
            }
            .report-chevron {
                width: 34px;
                height: 34px;
            }
            .report-primary-value {
                font-size: 1.28rem;
            }
            .report-secondary-label {
                font-size: 0.66rem;
            }
            .report-secondary-value {
                font-size: 0.76rem;
            }
            .metric-card,
            .activity-item {
                background: #ffffff;
                box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
            }
        }
        @media (max-width: 359px) {
            .overview-grid {
                gap: 8px;
            }
            .overview-grid .metric-card {
                min-height: 126px;
                padding: 11px 10px;
                grid-template-columns: 36px minmax(0, 1fr);
                gap: 7px 8px;
            }
            .overview-grid .metric-icon {
                width: 36px;
                height: 36px;
                border-radius: 12px;
            }
            .overview-grid .metric-copy h3 {
                font-size: 0.74rem;
            }
            .overview-grid .metric-copy p {
                font-size: 0.6rem;
            }
            .overview-grid .metric-value strong {
                font-size: 1rem;
            }
            .overview-grid .metric-value span {
                font-size: 0.58rem;
            }
        }
        @media (min-width: 760px) {
            body {
                padding: 18px 12px 58px;
            }
            .report-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 14px;
            }
            .filters-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .filters-form {
                gap: 14px;
            }
            .search-wrap {
                grid-column: span 2;
            }
            .table-wrap {
                display: block;
                overflow-x: auto;
                border-radius: 18px;
                border: 1px solid var(--line);
                background: #fff;
            }
            .student-card-list {
                display: none;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                padding: 14px 12px;
                text-align: left;
                border-bottom: 1px solid #eef2f7;
                font-size: 0.83rem;
                vertical-align: top;
            }
            th {
                font-size: 0.72rem;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: #64748b;
                background: #f9fbff;
            }
            .export-actions {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (min-width: 760px) and (max-width: 979px) {
            .report-grid {
                gap: 14px;
            }
            .report-card {
                min-height: 162px;
                padding: 18px 17px 16px;
            }
            .report-copy h3 {
                font-size: 1rem;
            }
        }
        @media (min-width: 980px) {
            .reports-workspace {
                grid-template-columns: 280px minmax(0, 1fr);
                align-items: start;
            }
            .report-sidebar {
                position: sticky;
                top: 22px;
            }
            .report-nav {
                display: grid;
                gap: 10px;
                overflow: visible;
                padding-bottom: 0;
            }
            .report-nav-button {
                width: 100%;
                justify-content: flex-start;
                white-space: normal;
            }
            .report-panel {
                padding: 20px;
            }
            .report-nav-copy span {
                display: block;
            }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <header class="top-header">
        <a href="<?php echo htmlspecialchars($back_link); ?>" class="back-button" aria-label="Back to dashboard">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M15 6 9 12l6 6" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        <div class="header-copy">
            <h1>Reports</h1>
            <p>Choose a report to view</p>
        </div>
        <button type="button" class="filter-button" data-report-tab-target="student" aria-label="Open student reports">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M4 6h16M7 12h10M10 18h4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                <circle cx="18" cy="6" r="2" fill="#2563eb"/>
                <circle cx="6" cy="12" r="2" fill="#2563eb"/>
                <circle cx="14" cy="18" r="2" fill="#2563eb"/>
            </svg>
        </button>
    </header>

    <div class="semester-pill-row">
        <div class="semester-pill">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="4" y="5" width="16" height="15" rx="3" stroke="currentColor" stroke-width="1.8"/>
                <path d="M8 3v4M16 3v4M4 10h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            <span><?php echo htmlspecialchars($semester_pill_label); ?></span>
        </div>
        <div class="last-updated">Last updated: <?php echo htmlspecialchars($last_updated_label); ?></div>
    </div>

    <div class="reports-workspace">
    <aside class="report-sidebar">
        <section class="report-sidebar-card">
            <div class="report-sidebar-title">Report Views</div>
            <nav class="report-nav" aria-label="Report sections">
                <button type="button" class="report-nav-button is-active" data-report-tab-target="overview">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 12h6V5H4v7Zm0 7h6v-5H4v5Zm10 0h6V12h-6v7Zm0-14v5h6V5h-6Z" fill="currentColor"/></svg>
                    <span class="report-nav-copy"><strong>Overview</strong><span>Snapshot and categories</span></span>
                </button>
                <button type="button" class="report-nav-button" data-report-tab-target="student">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 5h9a2 2 0 0 1 2 2v12l-4-2-4 2-4-2-4 2V7a2 2 0 0 1 2-2h3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M7 9h7M7 13h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="report-nav-copy"><strong>Student Requests</strong><span>Filters and request preview</span></span>
                </button>
                <button type="button" class="report-nav-button" data-report-tab-target="financial">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 8h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a3 3 0 0 1 0-6h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="report-nav-copy"><strong>Financial</strong><span>Cash, balances, lecturer payouts</span></span>
                </button>
                <button type="button" class="report-nav-button" data-report-tab-target="lecturers">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 4h10v16l-2-1.3L13 20l-2-1.3L9 20l-2-1.3L5 20V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 9h6M9 13h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="report-nav-copy"><strong>Lecturer Payments</strong><span>Settlement and outstanding copies</span></span>
                </button>
                <button type="button" class="report-nav-button" data-report-tab-target="inventory">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 12 4 7.5M12 12l8-4.5M12 12v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="report-nav-copy"><strong>Books Received</strong><span>Inventory and distribution</span></span>
                </button>
                <button type="button" class="report-nav-button" data-report-tab-target="pickup">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4v16M4 12h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="report-nav-copy"><strong>Pickup Needed</strong><span>Books waiting for collection</span></span>
                </button>
                <button type="button" class="report-nav-button" data-report-tab-target="activity">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 18a4 4 0 0 1 8 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10 9a4 4 0 0 1 8 0M14 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                    <span class="report-nav-copy"><strong>Class Activity</strong><span>Recent student and rep actions</span></span>
                </button>
                <button type="button" class="report-nav-button" data-report-tab-target="exports">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4v10m0 0 4-4m-4 4-4-4M5 18h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="report-nav-copy"><strong>Exports</strong><span>Download, print, or share</span></span>
                </button>
            </nav>
        </section>
    </aside>

    <main class="report-main">
    <section class="report-panel is-active" data-report-panel="overview">
    <div class="hub-intro" id="overviewMetrics">
        <h2>Reports Hub</h2>
        <p>Open the exact report you need without digging through one long page.</p>
    </div>
    <section class="overview-grid">
        <button type="button" class="metric-card green" data-report-tab-target="financial">
            <span class="metric-icon green">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M7 8h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a3 3 0 0 1 0-6h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M7 12h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </span>
            <div class="metric-copy">
                <h3>Total Cash Collected</h3>
                <p>Money received from students</p>
            </div>
            <div class="metric-value green">
                <strong><?php echo htmlspecialchars(book_system_reports_money(floatval($metrics['cash_collected'] ?? 0))); ?></strong>
                <span>Tap to view finance summary</span>
            </div>
        </button>

        <button type="button" class="metric-card red" data-report-tab-target="financial">
            <span class="metric-icon red">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M12 8v4M12 16h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </span>
            <div class="metric-copy">
                <h3>Outstanding Balance</h3>
                <p>Pending student payments</p>
            </div>
            <div class="metric-value red">
                <strong><?php echo htmlspecialchars(book_system_reports_money($outstanding_balance)); ?></strong>
                <span>Tap to view finance summary</span>
            </div>
        </button>

        <button type="button" class="metric-card blue" data-report-tab-target="student">
            <span class="metric-icon blue">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M7 5h9a2 2 0 0 1 2 2v12l-4-2-4 2-4-2-4 2V7a2 2 0 0 1 2-2h3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M7 9h7M7 13h7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </span>
            <div class="metric-copy">
                <h3>Books Given Out</h3>
                <p>Books successfully distributed</p>
            </div>
            <div class="metric-value blue">
                <strong><?php echo number_format(intval($metrics['collected_items'] ?? 0)); ?></strong>
                <span>Tap to view student preview</span>
            </div>
        </button>

        <button type="button" class="metric-card purple" data-report-tab-target="student">
            <span class="metric-icon purple">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M12 8v4l2.5 1.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <div class="metric-copy">
                <h3>Pending Requests</h3>
                <p>Students yet to complete payment</p>
            </div>
            <div class="metric-value purple">
                <strong><?php echo number_format(intval($metrics['unpaid_requests'] ?? 0)); ?></strong>
                <span>Tap to view student preview</span>
            </div>
        </button>

        <button type="button" class="metric-card orange" data-report-tab-target="pickup">
            <span class="metric-icon orange">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M12 12 4 7.5M12 12l8-4.5M12 12v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
            </span>
            <div class="metric-copy">
                <h3>Pickup Needed</h3>
                <p>Books to collect from lecturers</p>
            </div>
            <div class="metric-value orange">
                <strong><?php echo number_format($pickup_needed_total); ?></strong>
                <span>Tap to view pickup list</span>
            </div>
        </button>
    </section>

    <h2 class="section-heading">Report Categories</h2>
    <section class="report-grid">
        <?php foreach ($summary_cards as $card): ?>
            <?php
            $card_has_data = false;
            foreach (($card['stats'] ?? []) as $chip) {
                if (trim(strval($chip['value'] ?? '')) !== '0' && trim(strval($chip['value'] ?? '')) !== 'GHS 0.00') {
                    $card_has_data = true;
                    break;
                }
            }
            $report_tab_target = 'overview';
            $report_href = strval($card['href'] ?? '#');
            if ($report_href === '#studentReportPreview') {
                $report_tab_target = 'student';
            } elseif ($report_href === '#financialSummarySection') {
                $report_tab_target = 'financial';
            } elseif ($report_href === '#lecturerPaymentSection') {
                $report_tab_target = 'lecturers';
            } elseif ($report_href === '#booksReceivedSection') {
                $report_tab_target = 'inventory';
            } elseif ($report_href === '#pickupNeededSection') {
                $report_tab_target = 'pickup';
            } elseif ($report_href === '#classActivitySection') {
                $report_tab_target = 'activity';
            }
            $primary_stat = $card['stats'][0] ?? ['label' => 'Summary', 'value' => '0'];
            $secondary_stat = $card['stats'][1] ?? null;
            ?>
            <article
                class="report-card report-card--<?php echo htmlspecialchars(strval($card['accent'] ?? 'blue')); ?>"
                id="<?php echo htmlspecialchars(preg_replace('/[^a-z0-9]+/i', '', str_replace(' ', '', strval($card['title'] ?? 'report')))); ?>"
                data-report-tab-target="<?php echo htmlspecialchars($report_tab_target); ?>"
                role="button"
                tabindex="0"
                aria-label="Open <?php echo htmlspecialchars(strval($card['title'] ?? 'report')); ?>"
            >
                <div class="report-top">
                    <div class="report-head">
                        <span class="report-icon <?php echo htmlspecialchars(strval($card['accent'] ?? 'blue')); ?>">
                            <?php if (($card['icon'] ?? '') === 'books'): ?>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 5h6a2 2 0 0 1 2 2v12H8a2 2 0 0 0-2 2V5Zm8 2a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v14a2 2 0 0 0-2-2h-4V7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            <?php elseif (($card['icon'] ?? '') === 'wallet'): ?>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 8h12a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a3 3 0 0 1 0-6h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 12h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <?php elseif (($card['icon'] ?? '') === 'students'): ?>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5.5 18a4.5 4.5 0 0 1 9 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10.5 9a4.5 4.5 0 0 1 9 0M15 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <?php elseif (($card['icon'] ?? '') === 'receipt'): ?>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 4h10v16l-2-1.3L13 20l-2-1.3L9 20l-2-1.3L5 20V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 9h6M9 13h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <?php elseif (($card['icon'] ?? '') === 'box'): ?>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 12 4 7.5M12 12l8-4.5M12 12v9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 18a4 4 0 0 1 8 0M4 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Zm10 9a4 4 0 0 1 8 0M14 9a3 3 0 1 1 6 0 3 3 0 1 1-6 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <?php endif; ?>
                        </span>
                        <div class="report-copy">
                            <h3><?php echo htmlspecialchars(strval($card['title'] ?? 'Report')); ?></h3>
                            <?php if (!$card_has_data): ?>
                                <p><?php echo htmlspecialchars(strval($card['empty_note'] ?? 'No records yet for this report.')); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="report-chevron" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M10 7l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </span>
                </div>
                <div class="report-primary">
                    <span class="report-primary-label"><?php echo htmlspecialchars(strval($primary_stat['label'] ?? 'Summary')); ?></span>
                    <strong class="report-primary-value"><?php echo htmlspecialchars(strval($primary_stat['value'] ?? '0')); ?></strong>
                </div>
                <?php if ($secondary_stat): ?>
                    <div class="report-secondary">
                        <span class="report-secondary-label"><?php echo htmlspecialchars(strval($secondary_stat['label'] ?? '')); ?></span>
                        <span class="report-secondary-value"><?php echo htmlspecialchars(strval($secondary_stat['value'] ?? '0')); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!$secondary_stat && !$card_has_data): ?>
                    <div class="report-secondary">
                        <span class="report-secondary-label">Status</span>
                        <span class="report-secondary-value">No activity yet</span>
                    </div>
                <?php endif; ?>
                <div class="report-actions">
                </div>
            </article>
        <?php endforeach; ?>
    </section>
    </section>

    <section class="report-panel" data-report-panel="student">
        <div class="panel-heading">
            <div class="panel-heading-copy">
                <h2>Student Requests</h2>
                <p>Filter and review student requests.</p>
            </div>
            <button type="button" class="report-home-link" data-report-tab-target="overview">Reports Hub</button>
        </div>
    <section class="filters-card" id="filtersPanel">
        <div class="card-head">
            <div>
                <h2>Filters &amp; Search</h2>
                <p>Refine the request list quickly.</p>
            </div>
        </div>

        <div class="chip-row">
            <?php foreach ($period_options as $period_key => $period_label): ?>
                <a
                    href="?<?php echo htmlspecialchars(book_system_reports_build_query(['period' => $period_key])); ?>"
                    class="filter-chip<?php echo $period === $period_key ? ' active' : ''; ?>"
                ><?php echo htmlspecialchars($period_label); ?></a>
            <?php endforeach; ?>
        </div>

        <form method="GET" class="filters-form">
            <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
            <div class="filters-grid">
                <div class="filter-field">
                    <label for="book_id">All Books</label>
                    <select id="book_id" name="book_id" class="filter-select">
                        <option value="0">All Books</option>
                        <?php foreach ($book_rows as $book_row): ?>
                            <option value="<?php echo intval($book_row['book_id'] ?? 0); ?>" <?php echo $selected_book_id === intval($book_row['book_id'] ?? 0) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(strval($book_row['book_title'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="status">All Status</label>
                    <select id="status" name="status" class="filter-select">
                        <?php foreach ($status_filter_options as $status_value => $status_label): ?>
                            <option value="<?php echo htmlspecialchars($status_value); ?>" <?php echo $status_filter === $status_value ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field search-wrap">
                    <label for="search">Search</label>
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="1.7"/>
                        <path d="m16 16 4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                    </svg>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        class="search-input"
                        placeholder="Search student, index no., or book..."
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>
            </div>
            <div class="filters-actions">
                <button type="submit" class="apply-button">Apply Filters</button>
                <a href="activity_log.php" class="reset-link">Reset</a>
            </div>
        </form>
    </section>

    <section class="preview-card jump-section" id="studentReportPreview">
        <div class="card-head">
            <div>
                <h2>Request Preview</h2>
                <p>Student requests for the current filters.</p>
            </div>
            <div class="preview-summary">
                Showing <?php echo number_format(count($preview_rows)); ?> of <?php echo number_format($preview_total); ?>
            </div>
        </div>

        <?php if (!empty($preview_rows)): ?>
            <div class="student-card-list">
                <?php foreach ($preview_rows as $row): ?>
                    <article class="student-card">
                        <h3><?php echo htmlspecialchars(strval($row['full_name'] ?? 'Student')); ?></h3>
                        <div class="student-meta-grid">
                            <div class="student-meta-row">
                                <span>Index</span>
                                <span><?php echo htmlspecialchars(strval($row['index_number'] ?? '')); ?></span>
                            </div>
                            <div class="student-meta-row">
                                <span>Books</span>
                                <span><?php echo htmlspecialchars(strval($row['books_requested'] ?? '')); ?></span>
                            </div>
                            <div class="student-meta-row">
                                <span>Amount</span>
                                <span><?php echo htmlspecialchars(book_system_reports_money(floatval($row['display_amount'] ?? 0))); ?></span>
                            </div>
                        </div>
                        <div class="status-pill-row">
                            <span class="status-pill status-<?php echo htmlspecialchars(strval($row['payment_state'] ?? 'pending')); ?>">
                                <?php echo ucfirst(htmlspecialchars(strval($row['payment_state'] ?? 'pending'))); ?>
                            </span>
                            <span class="status-pill status-<?php echo htmlspecialchars(strval($row['collection_state'] ?? 'pending')); ?>">
                                <?php echo ucfirst(htmlspecialchars(strval($row['collection_state'] ?? 'pending'))); ?>
                            </span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Index Number</th>
                            <th>Student Name</th>
                            <th>Books Requested</th>
                            <th>Total Amount</th>
                            <th>Payment Status</th>
                            <th>Collection Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview_rows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(strval($row['index_number'] ?? '')); ?></td>
                                <td><strong><?php echo htmlspecialchars(strval($row['full_name'] ?? 'Student')); ?></strong></td>
                                <td><?php echo htmlspecialchars(strval($row['books_requested'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(book_system_reports_money(floatval($row['display_amount'] ?? 0))); ?></td>
                                <td><span class="status-pill status-<?php echo htmlspecialchars(strval($row['payment_state'] ?? 'pending')); ?>"><?php echo ucfirst(htmlspecialchars(strval($row['payment_state'] ?? 'pending'))); ?></span></td>
                                <td><span class="status-pill status-<?php echo htmlspecialchars(strval($row['collection_state'] ?? 'pending')); ?>"><?php echo ucfirst(htmlspecialchars(strval($row['collection_state'] ?? 'pending'))); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No student requests found for this filter.</div>
        <?php endif; ?>
    </section>
    </section>

    <section class="report-panel" data-report-panel="financial">
        <div class="panel-heading">
            <div class="panel-heading-copy">
                <h2>Financial Summary</h2>
                <p>Review collections and balances.</p>
            </div>
            <button type="button" class="report-home-link" data-report-tab-target="overview">Reports Hub</button>
        </div>
    <section class="activity-card jump-section" id="financialSummarySection" style="margin-top: 18px;">
        <div class="card-head">
            <div>
                <h2>Financial Summary</h2>
                <p>Accountability snapshot for this semester.</p>
            </div>
            <div class="preview-summary"><?php echo htmlspecialchars($semester_pill_label); ?></div>
        </div>
        <div class="mini-stats">
            <div class="mini-chip">
                <span class="mini-label">Collected Cash</span>
                <span class="mini-value"><?php echo htmlspecialchars(book_system_reports_money(floatval($metrics['cash_collected'] ?? 0))); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Outstanding Balance</span>
                <span class="mini-value"><?php echo htmlspecialchars(book_system_reports_money($outstanding_balance)); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Paid to Lecturers</span>
                <span class="mini-value"><?php echo htmlspecialchars(book_system_reports_money($lecturer_paid_total)); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Books Given Out</span>
                <span class="mini-value"><?php echo number_format(intval($metrics['collected_items'] ?? 0)); ?></span>
            </div>
        </div>
    </section>
    </section>

    <section class="report-panel" data-report-panel="lecturers">
        <div class="panel-heading">
            <div class="panel-heading-copy">
                <h2>Lecturer Payments</h2>
                <p>Track lecturer settlement and outstanding copies.</p>
            </div>
            <button type="button" class="report-home-link" data-report-tab-target="overview">Reports Hub</button>
        </div>
    <section class="activity-card jump-section" id="lecturerPaymentSection" style="margin-top: 18px;">
        <div class="card-head">
            <div>
                <h2>Lecturer Payments</h2>
                <p>Review payout activity and remaining settlement.</p>
            </div>
            <a href="lecturer_payments.php" class="inline-section-link">Open Full Page</a>
        </div>
        <div class="mini-stats">
            <div class="mini-chip">
                <span class="mini-label">Payments Logged</span>
                <span class="mini-value"><?php echo number_format($lecturer_payment_entries); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Paid to Lecturers</span>
                <span class="mini-value"><?php echo htmlspecialchars(book_system_reports_money($lecturer_paid_total)); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Copies Paid</span>
                <span class="mini-value"><?php echo number_format($lecturer_paid_copies); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Outstanding Copies</span>
                <span class="mini-value"><?php echo number_format($outstanding_copies_total); ?></span>
            </div>
        </div>
    </section>
    </section>

    <section class="report-panel" data-report-panel="inventory">
        <div class="panel-heading">
            <div class="panel-heading-copy">
                <h2>Books Distribution</h2>
                <p>Check incoming stock and books already handed out.</p>
            </div>
            <button type="button" class="report-home-link" data-report-tab-target="overview">Reports Hub</button>
        </div>
    <section class="activity-card jump-section" id="booksReceivedSection" style="margin-top: 18px;">
        <div class="card-head">
            <div>
                <h2>Books Distribution</h2>
                <p>Incoming stock, books given out, and remaining copies.</p>
            </div>
            <a href="lecturer_payments.php" class="inline-section-link">Open Inventory</a>
        </div>
        <div class="mini-stats">
            <div class="mini-chip">
                <span class="mini-label">Books Received</span>
                <span class="mini-value"><?php echo number_format($books_received_total); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Books Given Out</span>
                <span class="mini-value"><?php echo number_format(intval($metrics['collected_items'] ?? 0)); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Books Remaining</span>
                <span class="mini-value"><?php echo number_format($books_remaining_total); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Pending Requests</span>
                <span class="mini-value"><?php echo number_format(intval($metrics['pending_items'] ?? 0)); ?></span>
            </div>
        </div>
    </section>
    </section>

    <section class="report-panel" data-report-panel="pickup">
        <div class="panel-heading">
            <div class="panel-heading-copy">
                <h2>Pickup Needed</h2>
                <p>See which books still need collection.</p>
            </div>
            <button type="button" class="report-home-link" data-report-tab-target="overview">Reports Hub</button>
        </div>
    <section class="activity-card jump-section pickup-section" id="pickupNeededSection" style="margin-top: 18px;">
        <div class="card-head pickup-head">
            <div>
                <h2>Pickup Needed</h2>
                <p>Books waiting to be collected from lecturers</p>
            </div>
        </div>
        <div class="pickup-summary-card">
            <span>Total Books to Collect</span>
            <strong><?php echo number_format($pickup_needed_total); ?> Copies</strong>
            <div class="pickup-summary-subline">
                <span>GHS Amount</span>
                <strong><?php echo htmlspecialchars(book_system_reports_money($pickup_needed_cost_total)); ?></strong>
            </div>
            <div class="pickup-summary-footnote">Across <?php echo number_format($pickup_needed_book_count); ?> Books</div>
        </div>
        <?php if (!empty($pickup_needed_rows)): ?>
            <div class="pickup-grid">
                <?php foreach ($pickup_needed_rows as $pickup_row): ?>
                    <?php
                    $pickup_count = max(0, intval($pickup_row['pickup_needed'] ?? 0));
                    if ($pickup_count <= 2) {
                        $pickup_tone = 'success';
                    } elseif ($pickup_count <= 5) {
                        $pickup_tone = 'warning';
                    } else {
                        $pickup_tone = 'danger';
                    }
                    ?>
                    <article class="pickup-card <?php echo htmlspecialchars($pickup_tone); ?>">
                        <h3><?php echo htmlspecialchars(strval($pickup_row['book_title'] ?? 'Book')); ?></h3>
                        <div class="pickup-figure">
                            <div class="pickup-count">
                                <?php echo number_format($pickup_count); ?> Copies
                            </div>
                            <div class="pickup-amount">
                                (<?php echo htmlspecialchars(book_system_reports_money($pickup_count * max(0.0, floatval($pickup_row['book_price'] ?? 0)))); ?>)
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                You're all set.<br>
                No extra books need to be picked up right now.
            </div>
        <?php endif; ?>
    </section>
    </section>

    <section class="report-panel" data-report-panel="activity">
        <div class="panel-heading">
            <div class="panel-heading-copy">
                <h2>Activity Log</h2>
                <p>Follow recent request, payment, and collection activity.</p>
            </div>
            <button type="button" class="report-home-link" data-report-tab-target="overview">Reports Hub</button>
        </div>
    <section class="activity-card jump-section" id="classActivitySection" style="margin-top: 18px;">
        <div class="card-head">
            <div>
                <h2>Activity Log</h2>
                <p>Recent request, payment, and collection activity.</p>
            </div>
            <div class="preview-summary"><?php echo number_format(count($activity_rows)); ?> recent updates</div>
        </div>
        <div class="mini-stats" style="margin-bottom: 14px;">
            <div class="mini-chip">
                <span class="mini-label">Students Requested</span>
                <span class="mini-value"><?php echo number_format($students_requested_total); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Students Paid</span>
                <span class="mini-value"><?php echo number_format($students_paid_total); ?></span>
            </div>
            <div class="mini-chip">
                <span class="mini-label">Students Collected</span>
                <span class="mini-value"><?php echo number_format($students_collected_total); ?></span>
            </div>
        </div>
        <?php if (!empty($activity_rows)): ?>
        <div class="activity-list">
            <?php foreach (array_slice($activity_rows, 0, 4) as $activity): ?>
            <article class="activity-item">
                <div class="activity-topline">
                    <div class="activity-actor"><?php echo htmlspecialchars(book_system_reports_activity_label($activity)); ?></div>
                    <div class="activity-time"><?php echo htmlspecialchars(date('d M Y, g:i A', strtotime(strval($activity['created_at'] ?? 'now')))); ?></div>
                </div>
                <div class="activity-chip-row">
                    <?php if (trim(strval($activity['admin_name'] ?? '')) !== ''): ?>
                    <span class="activity-chip"><?php echo htmlspecialchars(strval($activity['admin_name'])); ?></span>
                    <?php endif; ?>
                    <?php if (trim(strval($activity['student_name'] ?? '')) !== ''): ?>
                    <span class="activity-chip"><?php echo htmlspecialchars(strval($activity['student_name'])); ?></span>
                    <?php endif; ?>
                </div>
                <?php if (trim(strval($activity['details'] ?? '')) !== ''): ?>
                <div class="activity-details"><?php echo htmlspecialchars(strval($activity['details'])); ?></div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">Recent class activity will appear here once requests, payments, and book updates are recorded.</div>
        <?php endif; ?>
    </section>
    </section>

    <section class="report-panel" data-report-panel="exports">
        <div class="panel-heading">
            <div class="panel-heading-copy">
                <h2>Exports</h2>
                <p>Download or share the current report view.</p>
            </div>
            <button type="button" class="report-home-link" data-report-tab-target="overview">Reports Hub</button>
        </div>
    <section class="export-card jump-section" id="exportActions" style="margin-top: 18px;">
        <div class="card-head">
            <div>
                <h2>Exports</h2>
                <p>Download or share this report.</p>
            </div>
        </div>
        <div class="export-actions">
            <a href="<?php echo htmlspecialchars($excel_export_href); ?>" class="export-button green">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 4v10m0 0 4-4m-4 4-4-4M5 18h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Export Excel</span>
            </a>
            <button type="button" class="export-button red" onclick="window.print()">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 8V4h10v4M7 16H5a2 2 0 0 1-2-2v-3a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v3a2 2 0 0 1-2 2h-2M7 12h10v8H7v-8Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <span>Export PDF</span>
            </button>
            <button type="button" class="export-button purple" onclick="shareReport()">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M15 8a3 3 0 1 0-2.83-4H12a3 3 0 0 0 0 6c.96 0 1.82-.45 2.38-1.14L8.9 11.78a3 3 0 1 0 0 4.44l5.48 2.92A3 3 0 1 0 15 16a2.98 2.98 0 0 0-.62.06L8.9 13.14a3.02 3.02 0 0 0 0-2.28l5.48-2.92c.17.04.35.06.52.06Z" fill="currentColor"/></svg>
                <span>Share Report</span>
            </button>
        </div>
    </section>
    </section>
    </main>
    </div>
</div>

<script>
function shareReport() {
    const reportUrl = <?php echo json_encode($share_url); ?>;
    const reportTitle = 'ClassBookHub Reports';
    const reportText = 'View this ClassBookHub report summary.';

    if (navigator.share) {
        navigator.share({
            title: reportTitle,
            text: reportText,
            url: reportUrl
        }).catch(function () {});
        return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(reportUrl).then(function () {
            alert('Report link copied to clipboard.');
        }).catch(function () {
            prompt('Copy this report link:', reportUrl);
        });
        return;
    }

    prompt('Copy this report link:', reportUrl);
}

(function () {
    var navButtons = Array.prototype.slice.call(document.querySelectorAll('[data-report-tab-target]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-report-panel]'));
    var hubCards = Array.prototype.slice.call(document.querySelectorAll('.report-card[data-report-tab-target]'));
    var hashToTabMap = {
        '#overviewMetrics': 'overview',
        '#filtersPanel': 'student',
        '#studentReportPreview': 'student',
        '#financialSummarySection': 'financial',
        '#lecturerPaymentSection': 'lecturers',
        '#booksReceivedSection': 'inventory',
        '#pickupNeededSection': 'pickup',
        '#classActivitySection': 'activity',
        '#exportActions': 'exports'
    };

    function activateReportTab(tabId, updateHash) {
        if (!tabId) {
            return;
        }

        navButtons.forEach(function(button) {
            var isActive = button.getAttribute('data-report-tab-target') === tabId;
            button.classList.toggle('is-active', isActive);
        });

        panels.forEach(function(panel) {
            var isActive = panel.getAttribute('data-report-panel') === tabId;
            panel.classList.toggle('is-active', isActive);
        });

        if (updateHash) {
            try {
                window.history.replaceState(null, '', '#tab-' + tabId);
            } catch (error) {}
        }
    }

    navButtons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            var tabId = button.getAttribute('data-report-tab-target') || 'overview';
            activateReportTab(tabId, true);
        });
    });

    hubCards.forEach(function(card) {
        card.addEventListener('click', function() {
            var tabId = card.getAttribute('data-report-tab-target') || 'overview';
            activateReportTab(tabId, true);
        });
        card.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                var tabId = card.getAttribute('data-report-tab-target') || 'overview';
                activateReportTab(tabId, true);
            }
        });
    });

    var initialHash = window.location.hash || '';
    if (initialHash.indexOf('#tab-') === 0) {
        activateReportTab(initialHash.replace('#tab-', ''), false);
    } else if (hashToTabMap[initialHash]) {
        activateReportTab(hashToTabMap[initialHash], false);
    } else {
        activateReportTab('overview', false);
    }
})();

(function () {
    var pickupButtons = Array.prototype.slice.call(document.querySelectorAll('.pickup-list-button[data-pickup-list]'));

    function showPickupListFallback(text) {
        window.prompt('Copy this pickup list:', text);
    }

    pickupButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var pickupText = button.getAttribute('data-pickup-list') || '';
            if (!pickupText) {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(pickupText).then(function () {
                    var label = button.querySelector('span');
                    if (label) {
                        label.textContent = 'Pickup List Copied';
                    }
                    button.classList.add('is-copied');
                    window.setTimeout(function () {
                        if (label) {
                            label.textContent = 'Generate Pickup List';
                        }
                        button.classList.remove('is-copied');
                    }, 1800);
                }).catch(function () {
                    showPickupListFallback(pickupText);
                });
                return;
            }

            showPickupListFallback(pickupText);
        });
    });
})();
</script>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
<?php include 'footer.php'; ?>
</body>
</html>
