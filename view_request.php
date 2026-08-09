<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php'; 

if (function_exists('book_system_sync_connection_timezone')) {
    book_system_sync_connection_timezone($conn);
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;
$current_admin_class = trim(strval($access_context['effective_class_name'] ?? ''));
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$rep_bottom_nav_active = 'requests';

$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 100;
$offset = ($page - 1) * $per_page;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_balance'], $_POST['request_id'], $_POST['student_id'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
    $request_id = intval($_POST['request_id'] ?? 0);
    $student_id = intval($_POST['student_id'] ?? 0);

    if ($request_id <= 0 || $student_id <= 0) {
        header("Location: view_request.php?msg=return_failed");
        exit;
    }

    $req = null;
    $req_stmt = $conn->prepare("SELECT r.total_amount, r.amount_paid, r.credit_used, r.admin_id, COALESCE(s.credit_balance, 0) AS student_credit_balance
        FROM requests r
        JOIN students s ON s.student_id = r.student_id
        WHERE r.request_id = ? AND r.student_id = ? LIMIT 1");
    if ($req_stmt) {
        $req_stmt->bind_param('ii', $request_id, $student_id);
        $req_stmt->execute();
        $req = $req_stmt->get_result();
    }

    if ($req && $req->num_rows === 1) {
        $req_row = $req->fetch_assoc();

        $request_admin_id = intval($req_row['admin_id'] ?? 0);
        if (!$is_super_admin && $request_admin_id !== $current_admin_id) {
            header("Location: view_request.php?msg=unauthorized");
            exit;
        }

        $total_amount = floatval($req_row['total_amount']);
        $amount_paid = floatval($req_row['amount_paid']);
        $credit_used = floatval($req_row['credit_used'] ?? 0);
        $due_after_credit = max(0, $total_amount - $credit_used);
        $overpaid = max(0, $amount_paid - $due_after_credit);
        $stored_credit_balance = max(0, floatval($req_row['student_credit_balance'] ?? 0));
        $total_return = round($overpaid + $stored_credit_balance, 2);

        if ($total_return > 0) {
            $conn->begin_transaction();
            try {
                if ($overpaid > 0) {
                    $upd_stmt = $conn->prepare("UPDATE requests SET amount_paid = ? WHERE request_id = ?" . ($is_super_admin ? "" : " AND admin_id = ?"));
                    if (!$upd_stmt) {
                        throw new RuntimeException('Failed to prepare update.');
                    }
                    $new_amount_paid = (float) number_format($due_after_credit, 2, '.', '');
                    if ($is_super_admin) {
                        $upd_stmt->bind_param('di', $new_amount_paid, $request_id);
                    } else {
                        $upd_stmt->bind_param('dii', $new_amount_paid, $request_id, $current_admin_id);
                    }
                    if (!$upd_stmt->execute()) {
                        throw new RuntimeException('Failed to update request.');
                    }
                }
                if ($stored_credit_balance > 0.009) {
                    $credit_reset_stmt = $conn->prepare("UPDATE students SET credit_balance = 0 WHERE student_id = ? LIMIT 1");
                    if (!$credit_reset_stmt) {
                        throw new RuntimeException('Failed to prepare credit reset.');
                    }
                    $credit_reset_stmt->bind_param('i', $student_id);
                    if (!$credit_reset_stmt->execute()) {
                        throw new RuntimeException('Failed to reset student credit.');
                    }
                }
                $amount_sql = (float) number_format($total_return, 2, '.', '');
                $return_note = ($stored_credit_balance > 0.009 && $overpaid > 0.009)
                    ? 'Returned extra cash and stored credit to student'
                    : (($stored_credit_balance > 0.009) ? 'Returned stored credit to student' : 'Returned to student');
                $ins_stmt = $conn->prepare("INSERT INTO balance_returns (student_id, request_id, amount, notes) VALUES (?, ?, ?, ?)");
                if (!$ins_stmt) {
                    throw new RuntimeException('Failed to prepare insert.');
                }
                $ins_stmt->bind_param('iids', $student_id, $request_id, $amount_sql, $return_note);
                if (!$ins_stmt->execute()) {
                    throw new RuntimeException('Failed to insert balance return.');
                }
                $conn->commit();
                if (function_exists('book_system_audit_log')) {
                    book_system_audit_log($conn, 'return_balance', 'request', $request_id, [
                        'student_id' => $student_id,
                        'amount' => $amount_sql,
                    ]);
                }
                header("Location: view_request.php?msg=returned&amount=$amount_sql");
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                header("Location: view_request.php?msg=return_failed");
                exit;
            }
        }
    }

    header("Location: view_request.php?msg=return_failed");
    exit;
}


$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$request_day = trim(strval($_GET['request_day'] ?? ''));
$notification_view = isset($_GET['notification_view']) && $_GET['notification_view'] === '1';
$today_date = date('Y-m-d');
$request_filter = trim(strval($_GET['filter'] ?? ''));
$selected_book_id = intval($_GET['book_id'] ?? 0);
$book_status = trim(strval($_GET['book_status'] ?? 'all'));
$request_day_is_valid = false;
if ($request_day !== '') {
    $day_obj = DateTime::createFromFormat('Y-m-d', $request_day);
    $request_day_is_valid = ($day_obj && $day_obj->format('Y-m-d') === $request_day);
    if (!$request_day_is_valid) {
        $request_day = '';
    }
}

$collection_filter = isset($_GET['collection_filter']) ? $_GET['collection_filter'] : 'all';
if (!in_array($collection_filter, ['all', 'not_taken'], true)) {
    $collection_filter = 'all';
}
if ($request_filter === '' && $collection_filter === 'not_taken') {
    $request_filter = 'pending';
}
if (!in_array($request_filter, ['all', 'paid', 'partial', 'unpaid', 'pending', 'given_out', 'today'], true)) {
    $request_filter = 'all';
}
if ($request_filter === 'today' && $request_day === '') {
    $request_day = $today_date;
}
if (!in_array($book_status, ['all', 'taken', 'not_taken'], true)) {
    $book_status = 'all';
}
if ($selected_book_id <= 0) {
    $selected_book_id = 0;
    $book_status = 'all';
}

$active_books = [];
$selected_book_label = '';
$books_filter_sql = "SELECT book_id, book_title, COALESCE(course_code, '') AS course_code
    FROM books
    WHERE availability = 'available'
      AND semester_id = ?
      " . ($is_super_admin ? '' : "AND (admin_id = ? OR admin_id IS NULL)") . "
    ORDER BY COALESCE(course_code, ''), book_title ASC";
$books_filter_stmt = $conn->prepare($books_filter_sql);
if ($books_filter_stmt) {
    if ($is_super_admin) {
        $books_filter_stmt->bind_param('i', $semester_id);
    } else {
        $books_filter_stmt->bind_param('ii', $semester_id, $current_admin_id);
    }
    $books_filter_stmt->execute();
    $books_filter_result = $books_filter_stmt->get_result();
    if ($books_filter_result) {
        while ($book_row = $books_filter_result->fetch_assoc()) {
            $book_id = intval($book_row['book_id'] ?? 0);
            $course_code = trim(strval($book_row['course_code'] ?? ''));
            $book_title = trim(strval($book_row['book_title'] ?? ''));
            $book_label = trim($course_code . ' ' . $book_title);
            if ($book_label === '') {
                $book_label = 'Book #' . $book_id;
            }
            $active_books[] = [
                'book_id' => $book_id,
                'book_title' => $book_title,
                'course_code' => $course_code,
                'book_label' => $book_label,
            ];
            if ($book_id === $selected_book_id) {
                $selected_book_label = $book_label;
            }
        }
    }
    $books_filter_stmt->close();
}
if ($selected_book_id > 0 && $selected_book_label === '') {
    $selected_book_id = 0;
    $book_status = 'all';
}

if (!$is_super_admin && $notification_view) {
    $mark_sql = "UPDATE requests SET rep_viewed_at = NOW() WHERE semester_id = ? AND admin_id = ? AND rep_viewed_at IS NULL";
    if ($request_day !== '') {
        $mark_sql .= " AND DATE(created_at) = ?";
    }
    $mark_stmt = $conn->prepare($mark_sql);
    if ($mark_stmt) {
        if ($request_day !== '') {
            $mark_stmt->bind_param('iis', $semester_id, $current_admin_id, $request_day);
        } else {
            $mark_stmt->bind_param('ii', $semester_id, $current_admin_id);
        }
        $mark_stmt->execute();
        $mark_stmt->close();
    }
}

$search_pattern = '%' . $search . '%';
$query_types = 'i';
$query_params = [$semester_id];
$base_where_sql = "r.semester_id = ?";
if (!$is_super_admin) {
    $base_where_sql .= " AND r.admin_id = ?";
    $query_types .= 'i';
    $query_params[] = $current_admin_id;
}

if ($collection_filter === 'not_taken') {
    $base_where_sql .= " AND EXISTS (
        SELECT 1 FROM request_items ri2
        WHERE ri2.request_id = r.request_id
          AND COALESCE(ri2.is_cancelled, 0) = 0
          AND (ri2.is_collected = 0 OR ri2.is_collected IS NULL)
    )";
}

if ($request_filter === 'paid') {
    $base_where_sql .= " AND COALESCE(r.amount_paid, 0) + 0.009 >= GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0)";
} elseif ($request_filter === 'partial') {
    $base_where_sql .= " AND COALESCE(r.amount_paid, 0) > 0.009
                  AND COALESCE(r.amount_paid, 0) + 0.009 < GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0)";
} elseif ($request_filter === 'unpaid') {
    $base_where_sql .= " AND GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0) > 0.009
                  AND COALESCE(r.amount_paid, 0) <= 0.009";
} elseif ($request_filter === 'pending') {
    $base_where_sql .= " AND EXISTS (
        SELECT 1 FROM request_items ri_pending
        WHERE ri_pending.request_id = r.request_id
          AND COALESCE(ri_pending.is_cancelled, 0) = 0
          AND (ri_pending.is_collected = 0 OR ri_pending.is_collected IS NULL)
    )";
} elseif ($request_filter === 'given_out') {
    $base_where_sql .= " AND EXISTS (
        SELECT 1 FROM request_items ri_active
        WHERE ri_active.request_id = r.request_id
          AND COALESCE(ri_active.is_cancelled, 0) = 0
    ) AND NOT EXISTS (
        SELECT 1 FROM request_items ri_pending
        WHERE ri_pending.request_id = r.request_id
          AND COALESCE(ri_pending.is_cancelled, 0) = 0
          AND (ri_pending.is_collected = 0 OR ri_pending.is_collected IS NULL)
    )";
}

if ($request_day !== '') {
    $base_where_sql .= " AND DATE(r.created_at) = ?";
    $query_types .= 's';
    $query_params[] = $request_day;
}

if ($search !== '') {
    $base_where_sql .= " AND (
        s.full_name LIKE ?
        OR s.index_number LIKE ?
        OR s.phone LIKE ?
        OR EXISTS (
            SELECT 1
            FROM request_items ri3
            JOIN books b3 ON ri3.book_id = b3.book_id
            WHERE ri3.request_id = r.request_id
              AND (
                    b3.book_title LIKE ?
                    OR COALESCE(b3.course_code, '') LIKE ?
              )
        )
    )";
    $query_types .= 'sssss';
    array_push($query_params, $search_pattern, $search_pattern, $search_pattern, $search_pattern, $search_pattern);
}

$ids_where_sql = $base_where_sql;
$ids_types = $query_types;
$ids_params = $query_params;
if ($selected_book_id > 0) {
    $ids_where_sql .= " AND EXISTS (
        SELECT 1
        FROM request_items ri_selected
        WHERE ri_selected.request_id = r.request_id
          AND ri_selected.book_id = ?
          AND COALESCE(ri_selected.is_cancelled, 0) = 0";
    $ids_types .= 'i';
    $ids_params[] = $selected_book_id;

    if ($book_status === 'taken') {
        $ids_where_sql .= " AND COALESCE(ri_selected.is_collected, 0) = 1";
    } elseif ($book_status === 'not_taken') {
        $ids_where_sql .= " AND COALESCE(ri_selected.is_collected, 0) = 0
          AND COALESCE(r.amount_paid, 0) + COALESCE(r.credit_used, 0) + 0.009 >= (
              SELECT COALESCE(SUM(COALESCE(ri_covered.unit_price, b_covered.price, 0)), 0)
              FROM request_items ri_covered
              JOIN books b_covered ON b_covered.book_id = ri_covered.book_id
              WHERE ri_covered.request_id = r.request_id
                AND COALESCE(ri_covered.is_cancelled, 0) = 0
                AND ri_covered.item_id <= ri_selected.item_id
          )";
    }
    $ids_where_sql .= ")";
}

$ids_sql = "SELECT r.request_id
        FROM requests r
        JOIN students s ON r.student_id = s.student_id
        WHERE $ids_where_sql";
$ids_sql .= " ORDER BY r.created_at DESC LIMIT ? OFFSET ?";
$ids_types .= 'ii';
$ids_params[] = $per_page;
$ids_params[] = $offset;

$stmt = $conn->prepare($ids_sql);
$request_ids = [];
if ($stmt) {
    $bind_args = [$ids_types];
    foreach ($ids_params as $param_index => $param_value) {
        $bind_args[] = &$ids_params[$param_index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_args);
    $stmt->execute();
    $ids_res = $stmt->get_result();
    if ($ids_res) {
        while ($r = $ids_res->fetch_assoc()) {
            $request_ids[] = intval($r['request_id']);
        }
    }
    $stmt->close();
}

if (count($request_ids) > 0) {
    $ids_in = implode(',', array_map('intval', $request_ids));

    $details_sql = "SELECT 
                r.request_id, r.student_id,
                s.full_name, s.index_number, s.phone,
                COALESCE(s.credit_balance, 0) AS student_credit_balance,
                r.total_amount, r.amount_paid, r.credit_used, r.payment_status, r.created_at,
                COALESCE(br.refunded_amount, 0) AS refunded_amount,
                br.last_return_date,
                COALESCE(cf.carried_forward_amount, 0) AS carried_forward_amount,
                cf.last_carried_at,
                SUM(CASE WHEN COALESCE(ri.is_cancelled, 0) = 0 AND (ri.is_collected = 0 OR ri.is_collected IS NULL) THEN 1 ELSE 0 END) AS pending_items,
                GROUP_CONCAT(CONCAT_WS('~',
                    ri.item_id,
                    ri.book_id,
                    REPLACE(COALESCE(b.book_title, ''), '~', '-'),
                    REPLACE(COALESCE(b.course_code, ''), '~', '-'),
                    COALESCE(ri.unit_price, b.price, 0),
                    COALESCE(ri.is_collected, 0),
                    COALESCE(ri.is_cancelled, 0),
                    COALESCE(ri.cash_refunded_amount, 0),
                    COALESCE(ri.credit_refunded_amount, 0)
                ) ORDER BY ri.item_id ASC SEPARATOR '|' ) AS books_data
            FROM requests r
            JOIN students s ON r.student_id = s.student_id
            LEFT JOIN request_items ri ON r.request_id = ri.request_id
            LEFT JOIN books b ON ri.book_id = b.book_id

            LEFT JOIN (
                SELECT request_id, SUM(amount) AS refunded_amount, MAX(return_date) AS last_return_date
                FROM balance_returns
                WHERE request_id IS NOT NULL
                GROUP BY request_id
            ) br ON r.request_id = br.request_id
            LEFT JOIN (
                SELECT request_id, SUM(amount) AS carried_forward_amount, MAX(carried_at) AS last_carried_at
                FROM semester_balance_carry_forwards
                GROUP BY request_id
            ) cf ON r.request_id = cf.request_id
            WHERE r.request_id IN ($ids_in)
              AND r.semester_id = ?
              " . ($is_super_admin ? '' : "AND r.admin_id = ?") . "
            GROUP BY r.request_id
            ORDER BY FIELD(r.request_id, $ids_in)";

    $stmt = $conn->prepare($details_sql);
    if ($is_super_admin) {
        $stmt->bind_param("i", $semester_id);
    } else {
        $stmt->bind_param("ii", $semester_id, $current_admin_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = null;
}

$request_rows = [];
if ($result) {
    while ($request_row = $result->fetch_assoc()) {
        $request_rows[] = $request_row;
    }
}

$show_next = (count($request_ids) === $per_page);
$prev_page = max(1, $page - 1);
$next_page = $page + 1;

$paid_count = 0;
$partial_count = 0;
$unpaid_count = 0;
$outstanding_count = 0;
$pending_books_count = 0;
$books_given_out_count = 0;
$selected_book_summary = [
    'total' => 0,
    'taken' => 0,
    'not_taken' => 0,
];
if ($selected_book_id > 0) {
    $summary_sql = "SELECT
            COUNT(*) AS total,
            COALESCE(SUM(selected_stats.taken), 0) AS taken,
            COALESCE(SUM(selected_stats.not_taken), 0) AS not_taken
        FROM (
            SELECT
                r.request_id,
                MAX(CASE WHEN COALESCE(ri_selected.is_collected, 0) = 1 THEN 1 ELSE 0 END) AS taken,
                MAX(CASE
                    WHEN COALESCE(ri_selected.is_collected, 0) = 0
                     AND COALESCE(r.amount_paid, 0) + COALESCE(r.credit_used, 0) + 0.009 >= (
                        SELECT COALESCE(SUM(COALESCE(ri_covered.unit_price, b_covered.price, 0)), 0)
                        FROM request_items ri_covered
                        JOIN books b_covered ON b_covered.book_id = ri_covered.book_id
                        WHERE ri_covered.request_id = r.request_id
                          AND COALESCE(ri_covered.is_cancelled, 0) = 0
                          AND ri_covered.item_id <= ri_selected.item_id
                     )
                    THEN 1 ELSE 0
                END) AS not_taken
            FROM requests r
            JOIN students s ON r.student_id = s.student_id
            JOIN request_items ri_selected
              ON ri_selected.request_id = r.request_id
             AND ri_selected.book_id = ?
             AND COALESCE(ri_selected.is_cancelled, 0) = 0
            WHERE $base_where_sql
            GROUP BY r.request_id
        ) selected_stats";
    $summary_stmt = $conn->prepare($summary_sql);
    if ($summary_stmt) {
        $summary_types = 'i' . $query_types;
        $summary_params = array_merge([$selected_book_id], $query_params);
        $summary_bind_args = [$summary_types];
        foreach ($summary_params as $param_index => $param_value) {
            $summary_bind_args[] = &$summary_params[$param_index];
        }
        call_user_func_array([$summary_stmt, 'bind_param'], $summary_bind_args);
        $summary_stmt->execute();
        $summary_result = $summary_stmt->get_result();
        $summary_row = $summary_result ? $summary_result->fetch_assoc() : null;
        if ($summary_row) {
            $selected_book_summary = [
                'total' => intval($summary_row['total'] ?? 0),
                'taken' => intval($summary_row['taken'] ?? 0),
                'not_taken' => intval($summary_row['not_taken'] ?? 0),
            ];
        }
        $summary_stmt->close();
    }
}

function book_system_request_avatar_initials(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'ST';
}

function book_system_request_balance_markup(float $remainingBalance, float $debitAmount, float $refundedAmount, float $carriedForwardAmount): string
{
    if ($refundedAmount > 0) {
        return '<span class="credit-amt">Returned: GH&#8373; ' . number_format($refundedAmount, 2) . '</span>';
    }
    if ($carriedForwardAmount > 0) {
        return '<span class="credit-amt">Carried: GH&#8373; ' . number_format($carriedForwardAmount, 2) . '</span>';
    }
    if ($debitAmount > 0) {
        return '<span class="debit-amt">Owes: GH&#8373; ' . number_format($debitAmount, 2) . '</span>';
    }
    if ($remainingBalance > 0) {
        return '<span class="credit-amt">Balance: GH&#8373; ' . number_format($remainingBalance, 2) . '</span>';
    }
    return '<span class="zero-amt">&mdash;</span>';
}

function book_system_request_balance_label(float $remainingBalance, float $debitAmount, float $refundedAmount, float $carriedForwardAmount): string
{
    if ($refundedAmount > 0) {
        return 'Returned';
    }
    if ($carriedForwardAmount > 0) {
        return 'Carried Forward';
    }
    if ($debitAmount > 0) {
        return 'Outstanding Balance';
    }
    if ($remainingBalance > 0) {
        return 'Extra Balance';
    }
    return 'Balance';
}

function book_system_request_payment_note(
    float $totalAmount,
    float $creditUsed,
    float $amountPaid,
    float $dueAfterCredit,
    float $debitAmount,
    float $refundedAmount,
    float $carriedForwardAmount,
    float $remainingBalance
): string {
    return '';
}

function book_system_request_build_debtor_rows_for_book(
    mysqli $conn,
    int $bookId,
    int $semesterId,
    int $adminId,
    bool $isSuperAdmin
): array
{
    if ($semesterId <= 0) {
        return [];
    }

    $sql = "
        SELECT
            r.request_id,
            r.student_id,
            r.created_at,
            COALESCE(r.total_amount, 0) AS total_amount,
            COALESCE(r.amount_paid, 0) AS amount_paid,
            COALESCE(r.credit_used, 0) AS credit_used,
            ri.item_id,
            ri.book_id,
            COALESCE(ri.unit_price, b.price, 0) AS item_price,
            s.full_name,
            s.index_number,
            s.phone,
            COALESCE(a.class_name, '') AS class_name,
            COALESCE(a.academic_level, '') AS academic_level,
            COALESCE(b.book_title, '') AS book_title,
            COALESCE(b.course_code, '') AS course_code
        FROM requests r
        JOIN request_items ri ON ri.request_id = r.request_id
        JOIN students s ON s.student_id = r.student_id
        LEFT JOIN admins a ON a.admin_id = r.admin_id
        LEFT JOIN books b ON b.book_id = ri.book_id
        WHERE r.semester_id = ?
          " . ($isSuperAdmin ? '' : 'AND r.admin_id = ?') . "
          AND COALESCE(ri.is_cancelled, 0) = 0
          " . ($bookId > 0 ? "
          AND EXISTS (
              SELECT 1
              FROM request_items ri_selected
              WHERE ri_selected.request_id = r.request_id
                AND ri_selected.book_id = ?
                AND COALESCE(ri_selected.is_cancelled, 0) = 0
          )" : "") . "
        ORDER BY r.request_id ASC, ri.item_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($bookId > 0) {
        if ($isSuperAdmin) {
            $stmt->bind_param('ii', $semesterId, $bookId);
        } else {
            $stmt->bind_param('iii', $semesterId, $adminId, $bookId);
        }
    } else {
        if ($isSuperAdmin) {
            $stmt->bind_param('i', $semesterId);
        } else {
            $stmt->bind_param('ii', $semesterId, $adminId);
        }
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    $activeRequestId = 0;
    $remainingCoveredAmount = 0.0;
    $requestOutstanding = 0.0;

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $requestId = intval($row['request_id'] ?? 0);
            if ($requestId !== $activeRequestId) {
                $activeRequestId = $requestId;
                $remainingCoveredAmount = max(
                    0.0,
                    floatval($row['amount_paid'] ?? 0) + floatval($row['credit_used'] ?? 0)
                );
                $requestOutstanding = max(
                    0.0,
                    floatval($row['total_amount'] ?? 0)
                    - floatval($row['credit_used'] ?? 0)
                    - floatval($row['amount_paid'] ?? 0)
                );
            }

            $itemPrice = max(0.0, floatval($row['item_price'] ?? 0));
            $coveredForItem = min($remainingCoveredAmount, $itemPrice);
            $itemFullyCovered = ($coveredForItem + 0.009) >= $itemPrice;

            $rowBookId = intval($row['book_id'] ?? 0);
            if (($bookId <= 0 || $rowBookId === $bookId) && !$itemFullyCovered) {
                $courseCode = trim(strval($row['course_code'] ?? ''));
                $bookTitle = trim(strval($row['book_title'] ?? ''));
                $bookLabel = trim($courseCode . ' ' . $bookTitle);
                if ($bookLabel === '') {
                    $bookLabel = 'Book #' . $rowBookId;
                }
                $rows[] = [
                    'request_id' => $requestId,
                    'student_id' => intval($row['student_id'] ?? 0),
                    'full_name' => strval($row['full_name'] ?? ''),
                    'index_number' => strval($row['index_number'] ?? ''),
                    'phone' => strval($row['phone'] ?? ''),
                    'class_name' => strval($row['class_name'] ?? ''),
                    'academic_level' => strval($row['academic_level'] ?? ''),
                    'created_at' => strval($row['created_at'] ?? ''),
                    'book_id' => $rowBookId,
                    'book_label' => $bookLabel,
                    'book_price' => $itemPrice,
                    'selected_book_owed' => max(0.0, $itemPrice - $coveredForItem),
                    'request_outstanding' => $requestOutstanding,
                ];
            }

            $remainingCoveredAmount = max(0.0, $remainingCoveredAmount - $itemPrice);
        }
    }

    $stmt->close();

    usort($rows, static function (array $left, array $right): int {
        $byName = strcasecmp(strval($left['full_name'] ?? ''), strval($right['full_name'] ?? ''));
        if ($byName !== 0) {
            return $byName;
        }
        return strcasecmp(strval($left['index_number'] ?? ''), strval($right['index_number'] ?? ''));
    });

    return $rows;
}

foreach ($request_rows as &$request_row) {
    $total_amount = floatval($request_row['total_amount'] ?? 0);
    $amount_paid = floatval($request_row['amount_paid'] ?? 0);
    $credit_used = floatval($request_row['credit_used'] ?? 0);
    $payment_math = function_exists('book_system_calculate_request_payment_math')
        ? book_system_calculate_request_payment_math($total_amount, $credit_used, $amount_paid)
        : [
            'payment_status' => (($amount_paid <= 0.009) ? 'unpaid' : ((($amount_paid + 0.009) >= max(0, $total_amount - $credit_used)) ? 'paid' : 'partial')),
            'due_after_credit' => max(0, $total_amount - $credit_used),
            'cash_overpaid' => max(0, $amount_paid - max(0, $total_amount - $credit_used)),
            'outstanding_balance' => max(0, max(0, $total_amount - $credit_used) - $amount_paid),
        ];
    $normalizedPaymentStatus = strval($payment_math['payment_status'] ?? 'unpaid');
    if ($normalizedPaymentStatus === 'paid') {
        $paid_count++;
    } elseif ($normalizedPaymentStatus === 'partial') {
        $partial_count++;
        $outstanding_count++;
    } else {
        $unpaid_count++;
        $outstanding_count++;
    }

    $due_after_credit = floatval($payment_math['due_after_credit'] ?? 0);
    $cash_overpaid = floatval($payment_math['cash_overpaid'] ?? 0);
    $debit_amount = floatval($payment_math['outstanding_balance'] ?? 0);
    $refunded_amount = max(0, floatval($request_row['refunded_amount'] ?? 0));
    $carried_forward_amount = max(0, floatval($request_row['carried_forward_amount'] ?? 0));
    $remaining_balance = max(0, $cash_overpaid - $refunded_amount - $carried_forward_amount);

    $books = [];
    $book_count = 0;
    $collected_count = 0;
    $selected_book_present = false;
    $selected_book_taken = false;
    $selected_book_not_taken = false;
    $remainingCoveredAmount = max(0.0, $amount_paid + $credit_used);
    if (!empty($request_row['books_data'])) {
        foreach (explode('|', strval($request_row['books_data'])) as $book) {
            $parts = explode('~', $book);
            if (count($parts) < 9) {
                continue;
            }
            [$item_id, $book_id, $title, $course_code, $unit_price, $is_collected, $is_cancelled, $cash_refunded_amount, $credit_refunded_amount] = $parts;
            $isCancelled = intval($is_cancelled) === 1;
            $isCollected = intval($is_collected) === 1;
            $bookId = intval($book_id);
            $unitPrice = max(0.0, floatval($unit_price));
            $coveredForItem = min($remainingCoveredAmount, $unitPrice);
            $itemFullyCovered = ($coveredForItem + 0.009) >= $unitPrice;
            $books[] = [
                'item_id' => intval($item_id),
                'book_id' => $bookId,
                'title' => strval($title),
                'course_code' => strval($course_code),
                'unit_price' => $unitPrice,
                'is_collected' => $isCollected,
                'is_cancelled' => $isCancelled,
                'cash_refunded_amount' => floatval($cash_refunded_amount),
                'credit_refunded_amount' => floatval($credit_refunded_amount),
                'is_fully_paid' => (!$isCancelled && $itemFullyCovered),
            ];
            if (!$isCancelled) {
                $book_count++;
                if ($isCollected) {
                    $collected_count++;
                }
                if ($selected_book_id > 0 && $bookId === $selected_book_id) {
                    $selected_book_present = true;
                    if ($isCollected) {
                        $selected_book_taken = true;
                    } elseif ($itemFullyCovered) {
                        $selected_book_not_taken = true;
                    }
                }
            }
            $remainingCoveredAmount = max(0.0, $remainingCoveredAmount - $unitPrice);
        }
    }

    $pending_count = max(0, $book_count - $collected_count);
    $pending_books_count += $pending_count;
    $books_given_out_count += $collected_count;

    $request_row['payment_status_normalized'] = $normalizedPaymentStatus;
    $request_row['avatar_initials'] = book_system_request_avatar_initials(strval($request_row['full_name'] ?? ''));
    $request_row['books_list'] = $books;
    $request_row['book_count'] = $book_count;
    $request_row['collected_count'] = $collected_count;
    $request_row['pending_count'] = $pending_count;
    $request_row['credit_used'] = $credit_used;
    $request_row['due_after_credit'] = $due_after_credit;
    $request_row['remaining_balance'] = $remaining_balance;
    $request_row['debit_amount'] = $debit_amount;
    $request_row['refunded_amount'] = $refunded_amount;
    $request_row['carried_forward_amount'] = $carried_forward_amount;
    $progress_percent = ($due_after_credit <= 0.009)
        ? 100
        : max(0, min(100, (int) round(($amount_paid / max($due_after_credit, 0.01)) * 100)));
    $request_row['payment_progress_percent'] = $progress_percent;
    $request_row['payment_progress_remaining_percent'] = max(0, 100 - $progress_percent);
    $request_row['all_books_given'] = ($book_count > 0 && $pending_count === 0);
    $request_row['selected_book_present'] = $selected_book_present;
    $request_row['selected_book_taken'] = $selected_book_taken;
    $request_row['selected_book_not_taken'] = $selected_book_not_taken;
    $request_row['balance_label'] = book_system_request_balance_label($remaining_balance, $debit_amount, $refunded_amount, $carried_forward_amount);
    $request_row['balance_markup'] = book_system_request_balance_markup($remaining_balance, $debit_amount, $refunded_amount, $carried_forward_amount);
    $request_row['payment_math_note'] = book_system_request_payment_note(
        $total_amount,
        $credit_used,
        $amount_paid,
        $due_after_credit,
        $debit_amount,
        $refunded_amount,
        $carried_forward_amount,
        $remaining_balance
    );
    $request_row['display_class_name'] = $current_admin_class !== '' ? $current_admin_class : 'Class Representative';
    $request_row['display_date'] = function_exists('book_system_format_datetime_local')
        ? book_system_format_datetime_local(strval($request_row['created_at'] ?? ''), 'M j')
        : date('M j', strtotime(strval($request_row['created_at'] ?? 'now')));
    $request_row['display_time'] = function_exists('book_system_format_datetime_local')
        ? book_system_format_datetime_local(strval($request_row['created_at'] ?? ''), 'g:i A')
        : date('g:i A', strtotime(strval($request_row['created_at'] ?? 'now')));
    $request_row['outstanding_balance'] = $debit_amount;
    $request_row['student_credit_balance'] = round(floatval($request_row['student_credit_balance'] ?? 0), 2);
}
unset($request_row);

if ($selected_book_id > 0) {
    $request_rows = array_values(array_filter(
        $request_rows,
        static function (array $request_row) use ($book_status): bool {
            if (empty($request_row['selected_book_present'])) {
                return false;
            }
            if ($book_status === 'taken') {
                return !empty($request_row['selected_book_taken']);
            }
            if ($book_status === 'not_taken') {
                return !empty($request_row['selected_book_not_taken']);
            }
            return true;
        }
    ));
}

$notification_stmt = $conn->prepare("SELECT
        SUM(CASE WHEN rep_viewed_at IS NULL THEN 1 ELSE 0 END) AS unseen_requests,
        SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) AS today_requests
    FROM requests
    WHERE semester_id = ? AND admin_id = ?");
$unseen_request_count = 0;
$today_request_count = 0;
if ($notification_stmt) {
    $notification_stmt->bind_param('sii', $today_date, $semester_id, $current_admin_id);
    $notification_stmt->execute();
    $notification_row = $notification_stmt->get_result()->fetch_assoc();
    $unseen_request_count = intval($notification_row['unseen_requests'] ?? 0);
    $today_request_count = intval($notification_row['today_requests'] ?? 0);
    $notification_stmt->close();
}

$notification_href = 'view_request.php?notification_view=1&request_day=' . urlencode($today_date) . '&filter=today';
$has_active_filters = ($search !== '' || $request_filter !== 'all' || $request_day !== '' || $notification_view || $selected_book_id > 0 || $book_status !== 'all');
$empty_state_message = $has_active_filters ? 'No matching requests found. Try another filter or search term.' : 'No requests found.';

$debtors_entry_href = 'request_debtors.php';
$export_query = [
    'search' => $search,
    'filter' => $request_filter,
];
if ($request_day !== '') {
    $export_query['request_day'] = $request_day;
}
if ($collection_filter !== 'all') {
    $export_query['collection_filter'] = $collection_filter;
}
if ($selected_book_id > 0) {
    $export_query['book_id'] = $selected_book_id;
    if ($book_status !== 'all') {
        $export_query['book_status'] = $book_status;
    }
}

$request_refresh_state = [
    'admin_id' => $current_admin_id,
    'semester_id' => $semester_id,
    'page' => $page,
    'search' => $search,
    'request_day' => $request_day,
    'request_filter' => $request_filter,
    'collection_filter' => $collection_filter,
    'book_id' => $selected_book_id,
    'book_status' => $book_status,
    'notification_view' => $notification_view ? 1 : 0,
    'counts' => [
        'paid' => $paid_count,
        'partial' => $partial_count,
        'unpaid' => $unpaid_count,
        'outstanding' => $outstanding_count,
        'pending_books' => $pending_books_count,
        'books_given_out' => $books_given_out_count,
        'unseen_requests' => $unseen_request_count,
        'today_requests' => $today_request_count,
    ],
    'request_rows' => array_map(static function (array $row): array {
        return [
            'request_id' => intval($row['request_id'] ?? 0),
            'amount_paid' => round(floatval($row['amount_paid'] ?? 0), 2),
            'total_amount' => round(floatval($row['total_amount'] ?? 0), 2),
            'credit_used' => round(floatval($row['credit_used'] ?? 0), 2),
            'pending_count' => intval($row['pending_count'] ?? 0),
            'collected_count' => intval($row['collected_count'] ?? 0),
            'payment_status' => strval($row['payment_status_normalized'] ?? ''),
            'created_at' => strval($row['created_at'] ?? ''),
            'books_data' => strval($row['books_data'] ?? ''),
        ];
    }, $request_rows),
];
$request_refresh_token = sha1(json_encode($request_refresh_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

if (strval($_GET['refresh'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode([
        'success' => true,
        'state_token' => $request_refresh_token,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$csrf_token = csrf_get_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Requests</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.5.0/font/bootstrap-icons.css">
    <style>
        :root {
            --surface: #ffffff;
            --surface-soft: #f8fbff;
            --line: #e8edf7;
            --line-strong: #d9e3f4;
            --text: #0f172a;
            --muted: #64748b;
            --primary: #3269f6;
            --primary-soft: #edf3ff;
            --green: #16a34a;
            --green-soft: #ecfdf3;
            --red: #ef4444;
            --red-soft: #fff1f2;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
            --amber: #f59e0b;
            --amber-soft: #fff7e8;
            --shadow: 0 18px 36px rgba(15, 23, 42, 0.08);
            --shadow-soft: 0 10px 24px rgba(15, 23, 42, 0.05);
            --radius-2xl: 28px;
            --radius-xl: 22px;
            --radius-lg: 18px;
            --radius-md: 14px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top, rgba(50, 105, 246, 0.08), transparent 30%),
                linear-gradient(180deg, #ffffff 0%, #f7f9fc 45%, #f3f6fb 100%);
            min-height: 100vh;
            padding: 18px 12px 32px;
            color: var(--text);
        }
        a { color: inherit; text-decoration: none; }
        button, input, select { font: inherit; }
        .page-container { max-width: 1080px; margin: 0 auto; }
        .page-header {
            background: linear-gradient(135deg, rgba(255,255,255,0.98) 0%, rgba(247,250,255,0.98) 100%);
            padding: 16px 18px;
            border-radius: var(--radius-xl);
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            border: 1px solid rgba(255,255,255,0.92);
            box-shadow: var(--shadow);
        }
        .page-header-copy {
            min-width: 0;
        }
        .page-header h1 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.22rem;
            font-weight: 800;
            color: var(--text);
        }
        .page-header h1 i {
            color: var(--primary);
            font-size: 1rem;
        }
        .page-header .subtitle {
            color: var(--muted);
            margin-top: 4px;
            font-size: 0.8rem;
            line-height: 1.45;
        }
        .header-bell {
            position: relative;
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-soft);
            color: var(--text);
        }
        .header-bell i {
            font-size: 1.15rem;
        }
        .header-bell .bell-dot {
            position: absolute;
            top: 8px;
            right: 9px;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--red);
            box-shadow: 0 0 0 3px #fff;
        }
        .panel {
            background: var(--surface);
            border-radius: var(--radius-2xl);
            padding: 16px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.92);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .summary-card {
            min-width: 0;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
            border: 1px solid #edf2fa;
            border-radius: 20px;
            padding: 14px;
            display: grid;
            gap: 10px;
            box-shadow: var(--shadow-soft);
        }
        .summary-card-link {
            text-decoration: none;
            color: inherit;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .summary-card-link:hover,
        .summary-card-link:focus-visible {
            transform: translateY(-2px);
            box-shadow: 0 18px 42px rgba(50, 105, 246, 0.12);
            border-color: #d8e3fb;
        }
        .summary-card-link:focus-visible {
            outline: 3px solid rgba(50, 105, 246, 0.16);
            outline-offset: 2px;
        }
        .summary-icon {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .summary-icon.blue { background: var(--primary-soft); color: var(--primary); }
        .summary-icon.green { background: var(--green-soft); color: var(--green); }
        .summary-icon.red { background: var(--red-soft); color: var(--red); }
        .summary-icon.purple { background: var(--purple-soft); color: var(--purple); }
        .summary-copy h3 {
            font-size: 0.82rem;
            font-weight: 800;
            color: var(--text);
            margin-bottom: 4px;
        }
        .summary-copy p {
            font-size: 0.7rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .summary-card-note {
            font-size: 0.68rem;
            color: var(--primary);
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .summary-value {
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1;
        }
        .summary-value.blue { color: var(--primary); }
        .summary-value.green { color: var(--green); }
        .summary-value.red { color: var(--red); }
        .summary-value.purple { color: var(--purple); }
        .toolbar-card {
            margin-bottom: 16px;
            padding: 16px;
            border-radius: 22px;
            background: linear-gradient(135deg, #fbfcff 0%, #f6f9ff 100%);
            border: 1px solid var(--line);
        }
        .debtors-panel {
            margin-top: 16px;
            padding: 16px;
            border-radius: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
            border: 1px solid #edf2fa;
            display: grid;
            gap: 14px;
        }
        .debtors-panel-head {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        .debtors-panel-title {
            display: grid;
            gap: 4px;
        }
        .debtors-panel-title h3 {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text);
        }
        .debtors-panel-title p {
            font-size: 0.84rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .debtors-panel-form {
            display: grid;
            gap: 12px;
        }
        .debtors-panel-form label {
            font-size: 0.82rem;
            color: var(--muted);
            font-weight: 700;
        }
        .debtors-panel-form select {
            width: 100%;
            min-height: 48px;
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 0 14px;
            background: #fff;
            color: var(--text);
            font-size: 0.9rem;
        }
        .debtors-meta-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .debtors-meta-card {
            padding: 14px;
            border-radius: 16px;
            border: 1px solid #edf2fa;
            background: #fff;
            display: grid;
            gap: 6px;
        }
        .debtors-meta-card span {
            font-size: 0.72rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 800;
        }
        .debtors-meta-card strong {
            font-size: 1.02rem;
            color: var(--text);
        }
        .debtors-preview {
            display: grid;
            gap: 10px;
        }
        .debtors-preview-row {
            padding: 14px;
            border-radius: 16px;
            border: 1px solid #edf2fa;
            background: #fff;
            display: grid;
            gap: 6px;
        }
        .debtors-preview-row strong {
            font-size: 0.92rem;
            color: var(--text);
        }
        .debtors-preview-row p {
            font-size: 0.82rem;
            color: var(--muted);
            line-height: 1.45;
        }
        .debtors-preview-row .owe-line {
            font-size: 0.84rem;
            font-weight: 800;
            color: #c2410c;
        }
        .toolbar-head {
            display: grid;
            gap: 12px;
            margin-bottom: 12px;
        }
        .search-shell {
            display: block;
        }
        .search-form {
            display: contents;
        }
        .unified-search-control {
            min-height: 52px;
            display: flex;
            align-items: stretch;
            border: 1px solid var(--line);
            border-radius: 17px;
            background: #fff;
            overflow: hidden;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .unified-search-control:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(50, 105, 246, 0.08);
        }
        .search-input {
            width: 100%;
            min-height: 50px;
            padding: 0 16px 0 42px;
            border: 0;
            border-radius: 0;
            font-size: 0.88rem;
            background: #fff;
            color: var(--text);
        }
        .search-field {
            position: relative;
            flex: 1 1 auto;
            min-width: 0;
        }
        .search-field i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.95rem;
            pointer-events: none;
        }
        .search-input:focus {
            outline: none;
        }
        .book-select-field {
            position: relative;
            flex: 0 1 280px;
            min-width: 170px;
            border-left: 1px solid #e8edf5;
            background: #f8faff;
        }
        .book-select {
            width: 100%;
            min-height: 50px;
            padding: 0 42px 0 14px;
            border: 0;
            border-radius: 0;
            font-size: 0.88rem;
            font-weight: 700;
            background: transparent;
            color: var(--text);
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }
        .book-select-field i {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.95rem;
            pointer-events: none;
        }
        .book-select:focus {
            outline: none;
        }
        .toolbar-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn {
            padding: 11px 16px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 0.78rem;
            border: none;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            justify-content: center;
            min-height: 44px;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary) 0%, #4c7cf7 100%); color: white; box-shadow: 0 14px 28px rgba(50,105,246,0.18); }
        .btn-secondary { background: #eef2f7; color: #475569; border: 1px solid var(--line); }
        .btn-success { background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%); color: white; box-shadow: 0 14px 28px rgba(34,197,94,0.18); }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .filter-chip-row {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 4px;
            margin-top: 10px;
            scrollbar-width: none;
        }
        .filter-chip-row::-webkit-scrollbar {
            display: none;
        }
        .filter-chip {
            flex: 0 0 auto;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid #edf0f7;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 0.76rem;
            font-weight: 800;
            white-space: nowrap;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }
        .filter-chip.is-active {
            color: #fff;
            background: linear-gradient(135deg, var(--primary) 0%, #4a78f3 100%);
            border-color: transparent;
            box-shadow: 0 12px 24px rgba(50, 105, 246, 0.18);
        }
        .filter-chip i {
            margin-right: 6px;
        }
        .book-filter-summary {
            margin-top: 12px;
            padding: 14px;
            border-radius: 20px;
            border: 1px solid #e6ecf7;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
        }
        .book-filter-summary-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }
        .book-filter-summary-title {
            font-size: 0.92rem;
            font-weight: 800;
            color: var(--text);
        }
        .book-filter-summary-copy {
            font-size: 0.78rem;
            color: var(--muted);
            margin-top: 4px;
        }
        .book-filter-summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }
        .book-filter-summary-card {
            padding: 12px;
            border-radius: 16px;
            border: 1px solid #edf2fb;
            background: #fff;
        }
        .book-filter-summary-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .book-filter-summary-value {
            display: block;
            margin-top: 6px;
            font-size: 1.2rem;
            font-weight: 800;
            color: #0f172a;
        }
        .book-filter-summary-card.is-taken .book-filter-summary-value {
            color: #15803d;
        }
        .book-filter-summary-card.is-not-taken .book-filter-summary-value {
            color: #c2410c;
        }
        .alert {
            padding: 13px 14px;
            border-radius: 14px;
            margin-bottom: 12px;
            font-size: 0.83rem;
            line-height: 1.5;
        }
        .alert-warning { background: #fff8e1; color: #9a6700; border-left: 4px solid #ffc107; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .requests-grid {
            display: grid;
            gap: 14px;
        }
        .request-card {
            padding: 14px;
            border-radius: 26px;
            border: 1px solid #e8eef8;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
        }
        .request-card[id] {
            scroll-margin-top: 84px;
        }
        .request-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }
        .request-person {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            min-width: 0;
            flex: 1;
        }
        .request-avatar {
            width: 48px;
            height: 48px;
            border-radius: 18px;
            background: linear-gradient(135deg, #eef2ff 0%, #f7eefe 100%);
            color: #6750d8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .request-copy {
            min-width: 0;
        }
        .request-copy .name {
            font-size: 0.98rem;
            font-weight: 800;
            color: var(--text);
            line-height: 1.22;
            text-transform: uppercase;
        }
        .request-copy .meta-line,
        .request-copy .time-line {
            color: #64748b;
            font-size: 0.74rem;
            margin-top: 4px;
            font-weight: 700;
            line-height: 1.35;
        }
        .request-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
        }
        .request-chevron {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            border: 1px solid #edf0f7;
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            cursor: pointer;
            transition: transform 0.2s ease, color 0.2s ease;
        }
        .request-card.is-collapsed .request-chevron {
            transform: rotate(-90deg);
        }
        .request-body {
            display: grid;
            gap: 10px;
        }
        .request-card.is-collapsed .request-body {
            display: none;
        }
        .status-chip-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .status-badge {
            width: auto;
            min-height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            border: none;
            cursor: pointer;
            letter-spacing: 0.04em;
        }
        .status-badge i {
            font-size: 0.82rem;
        }
        .status-paid { background: #e8f9ee; color: #15803d; }
        .status-partial { background: #fff4db; color: #c2410c; }
        .status-unpaid { background: #ffe8ea; color: #dc2626; }
        .owe-chip {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #e5edf8;
            color: #0f172a;
            font-size: 0.7rem;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .summary-shell {
            border-radius: 20px;
            border: 1px solid #ebf1f8;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
            padding: 10px;
            display: grid;
            gap: 8px;
        }
        .summary-shell-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .summary-shell-title {
            font-size: 0.78rem;
            font-weight: 800;
            color: #0f172a;
        }
        .summary-credit-note {
            font-size: 0.72rem;
            color: #7c8698;
            font-weight: 700;
        }
        .summary-grid-mini {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
        }
        .summary-mini-card {
            min-width: 0;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #f0f4fb;
            padding: 8px 9px;
        }
        .summary-mini-label {
            display: block;
            font-size: 0.66rem;
            color: #8b98ab;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .summary-mini-value {
            display: block;
            margin-top: 4px;
            font-size: 0.82rem;
            color: #0f172a;
            font-weight: 800;
            line-height: 1.25;
        }
        .payment-progress-block {
            display: grid;
            gap: 5px;
        }
        .payment-progress-copy {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 0.68rem;
            color: #64748b;
            font-weight: 700;
        }
        .payment-progress-track {
            width: 100%;
            height: 5px;
            border-radius: 999px;
            background: #edf2f7;
            overflow: hidden;
        }
        .payment-progress-fill {
            height: 100%;
            border-radius: 999px;
            width: 0;
        }
        .payment-progress-fill.progress-unpaid { background: #ef4444; }
        .payment-progress-fill.progress-partial { background: #f59e0b; }
        .payment-progress-fill.progress-paid { background: #22c55e; }
        .status-inline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.04em;
        }
        .status-text-paid { background: #e8f9ee; color: #15803d; }
        .status-text-partial { background: #fff4db; color: #c2410c; }
        .status-text-unpaid { background: #ffe8ea; color: #dc2626; }
        .books-panel {
            border-top: 1px solid #edf1f8;
            padding-top: 10px;
            display: grid;
            gap: 8px;
        }
        .books-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .books-label {
            font-size: 0.76rem;
            font-weight: 800;
            color: #0f172a;
        }
        .books-count {
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .books-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .book-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 34px;
            padding: 7px 10px;
            margin: 0;
            border-radius: 14px;
            font-size: 0.7rem;
            font-weight: 800;
            transition: all 0.2s ease;
            background: #fff;
            border: 1px solid transparent;
            line-height: 1.3;
        }
        .book-title-copy {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }
        .book-status-copy {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding-left: 7px;
            border-left: 1px solid currentColor;
            opacity: 0.9;
            font-size: 0.68rem;
            letter-spacing: 0.02em;
        }
        .tag-pending { background: #fff6d9; color: #9a6700; border-color: #f3d37a; }
        .tag-collected { background: #e8f8ea; color: #15803d; border-color: #86efac; }
        .tag-cancelled { background: #eceff4; color: #475569; border-color: #cbd5e1; cursor: default; }
        .book-status-saving {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
        }
        .book-status-saving.is-success { color: #166534; }
        .book-status-saving.is-error { color: #b91c1c; }
        .request-actions {
            display: grid;
            gap: 8px;
        }
        .primary-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .primary-actions > * {
            flex: 1 1 0;
            min-width: 0;
        }
        .secondary-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }
        .secondary-actions-left,
        .secondary-actions-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .action-btn,
        .action-link,
        .action-submit {
            min-width: 0;
            min-height: 44px;
            border-radius: 14px;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 0.74rem;
            font-weight: 800;
            padding: 0 12px;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .action-submit.payment-record {
            background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%);
            color: #ffffff;
            box-shadow: 0 12px 24px rgba(124, 58, 237, 0.18);
        }
        .action-link.books-primary {
            background: linear-gradient(135deg, #ecfdf3 0%, #dcfce7 100%);
            color: #15803d;
            box-shadow: 0 12px 24px rgba(22, 163, 74, 0.12);
        }
        .action-link.books-primary.is-complete {
            background: #f1f5f9;
            color: #64748b;
            box-shadow: none;
            cursor: default;
            pointer-events: none;
        }
        .action-link.history {
            background: #f8fafc;
            color: #334155;
            border-color: #e5edf8;
        }
        .more-menu {
            position: relative;
        }
        .more-menu summary {
            list-style: none;
        }
        .more-menu summary::-webkit-details-marker {
            display: none;
        }
        .more-trigger {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #e5edf8;
        }
        .more-panel {
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            min-width: 170px;
            padding: 8px;
            border-radius: 16px;
            border: 1px solid #e5edf8;
            background: #fff;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.12);
            display: grid;
            gap: 6px;
            z-index: 30;
        }
        .more-panel .action-link,
        .more-panel .action-submit {
            justify-content: flex-start;
            min-height: 40px;
            border-radius: 12px;
            padding-inline: 12px;
            background: #f8fafc;
            color: #334155;
            border-color: transparent;
        }
        .more-panel .action-submit.delete {
            background: #fff1f2;
            color: #dc2626;
        }
        .action-submit.status-badge {
            width: 100%;
            min-height: 44px;
            border-radius: 14px;
            font-size: 0.74rem;
        }
        .sticky-filter-wrap {
            position: sticky;
            top: 10px;
            z-index: 18;
            backdrop-filter: blur(10px);
        }
        .is-saving {
            opacity: 0.72;
            pointer-events: none;
        }
        .ajax-feedback {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 18px;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
            transition: opacity 0.2s ease;
        }
        .ajax-feedback.is-success { color: #166534; }
        .ajax-feedback.is-error { color: #b91c1c; }
        @media (max-width: 767px) {
            .toolbar-card {
                position: static;
            }
            .unified-search-control {
                min-height: 50px;
            }
            .book-select-field {
                flex-basis: 42%;
                min-width: 128px;
            }
            .book-select {
                padding-left: 10px;
                font-size: 0.78rem;
            }
            .book-filter-summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 6px;
            }
            .book-filter-summary {
                padding: 11px;
                border-radius: 16px;
            }
            .book-filter-summary-head {
                margin-bottom: 8px;
            }
            .book-filter-summary-copy {
                display: none;
            }
            .book-filter-summary-card {
                padding: 9px 6px;
                text-align: center;
            }
            .book-filter-summary-label {
                font-size: 0.64rem;
            }
        }
        .page-toast-stack {
            position: fixed;
            right: 16px;
            bottom: 94px;
            z-index: 1200;
            display: grid;
            gap: 10px;
            width: min(320px, calc(100vw - 32px));
        }
        .page-toast {
            padding: 12px 14px;
            border-radius: 14px;
            color: #fff;
            font-size: 0.86rem;
            font-weight: 600;
            box-shadow: 0 14px 32px rgba(15, 23, 42, 0.2);
            animation: toast-in 0.2s ease;
        }
        .page-toast.toast-success { background: #166534; }
        .page-toast.toast-error { background: #b91c1c; }
        .payment-sheet-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.42);
            backdrop-filter: blur(2px);
            z-index: 1250;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }
        .payment-sheet-backdrop.is-open {
            opacity: 1;
            pointer-events: auto;
        }
        .payment-sheet {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 1260;
            background: #fff;
            border-radius: 24px 24px 0 0;
            box-shadow: 0 -18px 40px rgba(15, 23, 42, 0.2);
            padding: 14px 16px calc(18px + env(safe-area-inset-bottom));
            transform: translateY(105%);
            transition: transform 0.24s ease;
        }
        .payment-sheet.is-open {
            transform: translateY(0);
        }
        .payment-sheet-handle {
            width: 54px;
            height: 5px;
            border-radius: 999px;
            background: #d9e3f4;
            margin: 0 auto 12px;
        }
        .payment-sheet-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }
        .payment-sheet-head h3 {
            font-size: 1rem;
            font-weight: 800;
            color: var(--text);
        }
        .payment-sheet-head p {
            margin-top: 4px;
            color: var(--muted);
            font-size: 0.82rem;
        }
        .payment-sheet-close {
            border: 0;
            background: #eef2ff;
            color: var(--primary);
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .payment-sheet-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }
        .payment-sheet-stat {
            background: #f8fbff;
            border: 1px solid #edf2fb;
            border-radius: 16px;
            padding: 12px;
            min-width: 0;
        }
        .payment-sheet-stat .label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .payment-sheet-stat .value {
            display: block;
            font-size: 0.96rem;
            font-weight: 800;
            color: var(--text);
        }
        .payment-sheet-form {
            display: grid;
            gap: 12px;
        }
        .payment-sheet-input label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .payment-sheet-input input {
            width: 100%;
            min-height: 48px;
            border-radius: 14px;
            border: 1px solid #d8e2f3;
            background: #fff;
            padding: 0 14px;
            font-size: 1rem;
            font-weight: 700;
            color: var(--text);
        }
        .payment-sheet-feedback {
            min-height: 20px;
            font-size: 0.78rem;
            color: var(--muted);
        }
        .payment-sheet-feedback.is-success { color: #166534; }
        .payment-sheet-feedback.is-error { color: #b91c1c; }
        .payment-sheet-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .payment-sheet-actions button {
            min-height: 46px;
            border: 0;
            border-radius: 14px;
            font-size: 0.84rem;
            font-weight: 800;
            cursor: pointer;
        }
        .payment-sheet-actions .save-payment-btn {
            background: linear-gradient(135deg, #7c3aed 0%, #8b5cf6 100%);
            color: #fff;
        }
        .payment-sheet-actions .mark-paid-btn {
            background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%);
            color: #fff;
        }
        .payment-sheet-actions button:disabled {
            opacity: 0.7;
            cursor: wait;
        }
        @media (min-width: 768px) {
            .payment-sheet {
                left: 50%;
                right: auto;
                bottom: 24px;
                width: min(520px, calc(100vw - 24px));
                border-radius: 24px;
                transform: translate(-50%, 105%);
            }
            .payment-sheet.is-open {
                transform: translate(-50%, 0);
            }
        }
        @keyframes toast-in {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .credit-amt { color: #28a745; font-weight: 700; }
        .debit-amt { color: #dc3545; font-weight: 700; }
        .zero-amt { color: #ccc; }
        .inline-action-form { display: inline; }
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #64748b;
            border-radius: 24px;
            background: linear-gradient(135deg, #f9fbff 0%, #f3f6fb 100%);
            border: 1px dashed #d7e0f0;
        }
        .empty-state .icon { font-size: 44px; margin-bottom: 14px; color: #94a3b8; }
        .empty-state p {
            font-size: 0.9rem;
            font-weight: 700;
        }
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 18px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .page-indicator { color: #64748b; font-weight: 700; font-size: 0.78rem; }
        @media (min-width: 760px) {
            body { padding: 24px 18px 36px; }
            .summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
            .toolbar-head {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: center;
            }
            .toolbar-meta {
                justify-content: flex-end;
            }
            .debtors-panel-form {
                grid-template-columns: minmax(0, 1fr) auto auto;
                align-items: end;
            }
            .requests-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                align-items: start;
            }
        }
        @media (min-width: 1080px) {
            .requests-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div class="page-header-copy">
            <h1><i class="bi bi-inbox"></i> Student Requests</h1>
            <p class="subtitle">Manage student book requests</p>
        </div>
        <a href="<?php echo htmlspecialchars($notification_href); ?>" class="header-bell" aria-label="Open today's request notifications">
            <i class="bi bi-bell"></i>
            <?php if ($unseen_request_count > 0 || $today_request_count > 0): ?><span class="bell-dot"></span><?php endif; ?>
        </a>
    </div>
    
    <div class="panel">
        <div class="summary-grid">
            <div class="summary-card">
                <span class="summary-icon blue"><i class="bi bi-inbox"></i></span>
                <div class="summary-copy">
                    <h3>Total Requests</h3>
                    <p>Requests visible in this view</p>
                </div>
                <div class="summary-value blue"><?php echo number_format(count($request_rows)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-icon green"><i class="bi bi-check2-circle"></i></span>
                <div class="summary-copy">
                    <h3>Paid Requests</h3>
                    <p>Students who have fully paid</p>
                </div>
                <div class="summary-value green" data-summary-value="paid"><?php echo number_format($paid_count); ?></div>
            </div>
            <a href="<?php echo htmlspecialchars($debtors_entry_href); ?>" class="summary-card summary-card-link">
                <span class="summary-icon red"><i class="bi bi-hourglass-split"></i></span>
                <div class="summary-copy">
                    <h3>Pending Payment</h3>
                    <p>Unpaid and partial balances</p>
                    <div class="summary-card-note">Tap to open the debtors page</div>
                </div>
                <div class="summary-value red" data-summary-value="outstanding"><?php echo number_format($outstanding_count); ?></div>
            </a>
            <div class="summary-card">
                <span class="summary-icon purple"><i class="bi bi-book"></i></span>
                <div class="summary-copy">
                    <h3>Books Given Out</h3>
                    <p>Books already distributed</p>
                </div>
                <div class="summary-value purple"><?php echo number_format($books_given_out_count); ?></div>
            </div>
        </div>

        <div class="toolbar-card">
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'out_of_stock'): ?>
                <div class="alert alert-warning">This book is out of stock. Set the stock quantity in Manage Books before marking it as collected.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'toggle_failed'): ?>
                <div class="alert alert-error">Could not update collection status. Please try again. If it continues, check that the book has stock and exists in the database.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cancelled_item'): ?>
                <div class="alert alert-warning">Cancelled items cannot be marked as collected.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
                <div class="alert alert-success">Request updated successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'Deleted'): ?>
                <div class="alert alert-success">Request deleted successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'returned'): ?>
                <div class="alert alert-success">Balance returned to the student successfully.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'return_failed'): ?>
                <div class="alert alert-error">Could not return the balance to the student. Please try again.</div>
            <?php endif; ?>
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'csrf_invalid'): ?>
                <div class="alert alert-error">Your session token expired. Refresh and try again.</div>
            <?php endif; ?>
            <?php if ($request_day !== ''): ?>
                <div class="alert alert-warning">Showing requests for <?php echo htmlspecialchars($request_day); ?>.</div>
            <?php endif; ?>

            <div class="toolbar-head">
                <form method="GET" class="search-form" id="requestSearchForm">
                    <?php if ($request_day !== ''): ?>
                        <input type="hidden" name="request_day" value="<?php echo htmlspecialchars($request_day); ?>">
                    <?php endif; ?>
                    <?php if ($notification_view): ?>
                        <input type="hidden" name="notification_view" value="1">
                    <?php endif; ?>
                    <?php if ($request_filter !== 'all'): ?>
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($request_filter); ?>">
                    <?php endif; ?>
                    <?php if ($book_status !== 'all' && $selected_book_id > 0): ?>
                        <input type="hidden" name="book_status" value="<?php echo htmlspecialchars($book_status); ?>">
                    <?php endif; ?>
                    <div class="search-shell">
                        <div class="unified-search-control">
                            <div class="search-field">
                                <i class="bi bi-search"></i>
                                <input
                                    type="search"
                                    id="requestSearchInput"
                                    name="search"
                                    class="search-input"
                                    placeholder="Search name or index..."
                                    value="<?php echo htmlspecialchars($search); ?>"
                                    autocomplete="off"
                                    enterkeyhint="search"
                                >
                            </div>
                            <label class="book-select-field" for="requestBookFilter">
                                <select name="book_id" id="requestBookFilter" class="book-select" aria-label="Filter by book">
                                    <option value="0">All Books</option>
                                    <?php foreach ($active_books as $book_option): ?>
                                        <option value="<?php echo intval($book_option['book_id'] ?? 0); ?>"<?php echo intval($book_option['book_id'] ?? 0) === $selected_book_id ? ' selected' : ''; ?>>
                                            <?php echo htmlspecialchars(strval($book_option['book_label'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="bi bi-chevron-down"></i>
                            </label>
                        </div>
                    </div>
                </form>
                <div class="toolbar-meta" id="requestToolbarMeta">
                    <a href="export_excel.php?<?php echo htmlspecialchars(http_build_query($export_query)); ?>" class="btn btn-success"><i class="bi bi-download"></i> Export</a>
                    <?php if ($has_active_filters): ?>
                        <a href="view_request.php" class="btn btn-secondary"><i class="bi bi-arrow-counterclockwise"></i> Reset</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selected_book_id > 0): ?>
                <div class="filter-chip-row">
                    <?php
                    $book_status_chip_definitions = [
                        'all' => ['label' => 'All', 'icon' => 'bi-grid'],
                        'taken' => ['label' => 'Taken', 'icon' => 'bi-check-circle'],
                        'not_taken' => ['label' => 'Not Taken', 'icon' => 'bi-box-seam'],
                    ];
                    foreach ($book_status_chip_definitions as $status_key => $status_meta):
                        $filter_query = [];
                        if ($search !== '') {
                            $filter_query['search'] = $search;
                        }
                        if ($notification_view) {
                            $filter_query['notification_view'] = '1';
                        }
                        if ($request_filter !== 'all') {
                            $filter_query['filter'] = $request_filter;
                        }
                        if ($request_day !== '') {
                            $filter_query['request_day'] = $request_day;
                        }
                        $filter_query['book_id'] = $selected_book_id;
                        if ($status_key !== 'all') {
                            $filter_query['book_status'] = $status_key;
                        }
                        $filter_href = 'view_request.php?' . http_build_query($filter_query);
                    ?>
                        <a href="<?php echo htmlspecialchars($filter_href); ?>" class="filter-chip<?php echo $book_status === $status_key ? ' is-active' : ''; ?>">
                            <i class="bi <?php echo htmlspecialchars($status_meta['icon']); ?>"></i>
                            <?php echo htmlspecialchars($status_meta['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="book-filter-summary">
                    <div class="book-filter-summary-head">
                        <div>
                            <div class="book-filter-summary-title"><?php echo htmlspecialchars($selected_book_label); ?></div>
                            <div class="book-filter-summary-copy">Book collection summary for the current filtered results.</div>
                        </div>
                    </div>
                    <div class="book-filter-summary-grid">
                        <div class="book-filter-summary-card">
                            <span class="book-filter-summary-label">Total Students</span>
                            <span class="book-filter-summary-value"><?php echo number_format(intval($selected_book_summary['total'] ?? 0)); ?></span>
                        </div>
                        <div class="book-filter-summary-card is-taken">
                            <span class="book-filter-summary-label">Taken</span>
                            <span class="book-filter-summary-value"><?php echo number_format(intval($selected_book_summary['taken'] ?? 0)); ?></span>
                        </div>
                        <div class="book-filter-summary-card is-not-taken">
                            <span class="book-filter-summary-label">Not Taken</span>
                            <span class="book-filter-summary-value"><?php echo number_format(intval($selected_book_summary['not_taken'] ?? 0)); ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>

        <div class="requests-grid">
            <?php if (!empty($request_rows)): ?>
                <?php foreach ($request_rows as $row): ?>
                    <?php
                        $history_return_params = [];
                        if ($search !== '') {
                            $history_return_params['search'] = $search;
                        }
                        if ($request_day !== '') {
                            $history_return_params['request_day'] = $request_day;
                        }
                        if ($notification_view) {
                            $history_return_params['notification_view'] = '1';
                        }
                        if ($request_filter !== '' && $request_filter !== 'all') {
                            $history_return_params['filter'] = $request_filter;
                        }
                        if ($collection_filter !== '' && $collection_filter !== 'all') {
                            $history_return_params['collection_filter'] = $collection_filter;
                        }
                        if ($selected_book_id > 0) {
                            $history_return_params['book_id'] = $selected_book_id;
                            if ($book_status !== 'all') {
                                $history_return_params['book_status'] = $book_status;
                            }
                        }
                        if ($page > 1) {
                            $history_return_params['page'] = $page;
                        }
                        $history_return_url = 'view_request.php';
                        if (!empty($history_return_params)) {
                            $history_return_url .= '?' . http_build_query($history_return_params);
                        }
                        $history_return_url .= '#requestCard' . intval($row['request_id']);
                    ?>
                    <article
                        class="request-card"
                        id="requestCard<?php echo intval($row['request_id']); ?>"
                        data-request-card="<?php echo intval($row['request_id']); ?>"
                        data-payment-status="<?php echo htmlspecialchars(strval($row['payment_status_normalized'] ?? 'unpaid')); ?>"
                        data-student-name="<?php echo htmlspecialchars(strval($row['full_name'] ?? 'Student'), ENT_QUOTES); ?>"
                        data-total-amount="<?php echo htmlspecialchars(number_format(floatval($row['total_amount'] ?? 0), 2, '.', ''), ENT_QUOTES); ?>"
                        data-credit-used="<?php echo htmlspecialchars(number_format(floatval($row['credit_used'] ?? 0), 2, '.', ''), ENT_QUOTES); ?>"
                        data-amount-paid="<?php echo htmlspecialchars(number_format(floatval($row['amount_paid'] ?? 0), 2, '.', ''), ENT_QUOTES); ?>"
                        data-outstanding-balance="<?php echo htmlspecialchars(number_format(floatval($row['outstanding_balance'] ?? 0), 2, '.', ''), ENT_QUOTES); ?>"
                    >
                        <div class="request-top">
                            <div class="request-person">
                                <div class="request-avatar"><?php echo htmlspecialchars(strval($row['avatar_initials'] ?? 'ST')); ?></div>
                                <div class="request-copy">
                                    <div class="name"><?php echo htmlspecialchars(strval($row['full_name'] ?? 'Student')); ?></div>
                                    <div class="meta-line"><?php echo htmlspecialchars(strval($row['index_number'] ?? '')); ?> &bull; <?php echo htmlspecialchars(strval($row['display_class_name'] ?? 'Class Representative')); ?></div>
                                    <div class="time-line"><?php echo htmlspecialchars(strval($row['display_date'] ?? '')); ?> &bull; <?php echo htmlspecialchars(strval($row['display_time'] ?? '')); ?></div>
                                </div>
                            </div>
                            <div class="request-meta">
                                <button type="button" class="request-chevron" data-card-toggle="<?php echo intval($row['request_id']); ?>" aria-label="Collapse request details">
                                    <i class="bi bi-chevron-down"></i>
                                </button>
                            </div>
                        </div>
                        <div class="request-body" id="requestBody<?php echo intval($row['request_id']); ?>">
                            <div class="status-chip-row">
                                <button type="button" class="status-badge <?php echo ($row['payment_status_normalized'] === 'paid') ? 'status-paid' : (($row['payment_status_normalized'] === 'partial') ? 'status-partial' : 'status-unpaid'); ?>" data-payment-role="pill" data-request-id="<?php echo intval($row['request_id']); ?>" data-status="<?php echo htmlspecialchars(strval($row['payment_status_normalized'] ?? 'unpaid')); ?>" data-payment-open="<?php echo intval($row['request_id']); ?>">
                                    <i class="bi <?php echo ($row['payment_status_normalized'] === 'paid') ? 'bi-check-circle' : (($row['payment_status_normalized'] === 'partial') ? 'bi-dash-circle' : 'bi-exclamation-circle'); ?>"></i>
                                    <?php echo strtoupper(strval($row['payment_status_normalized'] ?? 'unpaid')); ?>
                                </button>
                                <div class="owe-chip js-balance-label" data-request-id="<?php echo intval($row['request_id']); ?>">
                                    OWES GHS <?php echo number_format(floatval($row['outstanding_balance'] ?? 0), 2); ?>
                                </div>
                                <span class="ajax-feedback" aria-live="polite"></span>
                            </div>
                            <div class="summary-shell">
                                <div class="summary-shell-head">
                                    <div class="summary-shell-title">Payment Summary</div>
                                    <?php if (floatval($row['credit_used'] ?? 0) > 0): ?>
                                        <div class="summary-credit-note js-credit-note" data-request-id="<?php echo intval($row['request_id']); ?>">
                                            Credit applied: <span class="js-credit-used" data-request-id="<?php echo intval($row['request_id']); ?>">GHS <?php echo number_format(floatval($row['credit_used'] ?? 0), 2); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="summary-grid-mini">
                                    <div class="summary-mini-card">
                                        <span class="summary-mini-label">Total</span>
                                        <span class="summary-mini-value">GHS <?php echo number_format(floatval($row['total_amount'] ?? 0), 2); ?></span>
                                    </div>
                                    <div class="summary-mini-card">
                                        <span class="summary-mini-label">Paid</span>
                                        <span class="summary-mini-value js-paid-amount" data-request-id="<?php echo intval($row['request_id']); ?>">GHS <?php echo number_format(floatval($row['amount_paid'] ?? 0), 2); ?></span>
                                    </div>
                                    <div class="summary-mini-card">
                                        <span class="summary-mini-label">Outstanding</span>
                                        <span class="summary-mini-value js-balance-display" data-request-id="<?php echo intval($row['request_id']); ?>">
                                            GHS <?php echo number_format(floatval($row['outstanding_balance'] ?? 0), 2); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="payment-progress-block">
                                    <div class="payment-progress-copy">
                                        <span><?php echo intval($row['payment_progress_percent'] ?? 0); ?>% paid</span>
                                        <span><?php echo intval($row['payment_progress_remaining_percent'] ?? 0); ?>% balance</span>
                                    </div>
                                    <div class="payment-progress-track" aria-hidden="true">
                                        <div class="payment-progress-fill progress-<?php echo htmlspecialchars(strval($row['payment_status_normalized'] ?? 'unpaid')); ?>" style="width: <?php echo intval($row['payment_progress_percent'] ?? 0); ?>%;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="books-panel" id="booksPanel<?php echo intval($row['request_id']); ?>">
                                <div class="books-header">
                                    <div class="books-label">Books</div>
                                    <div class="books-count">(<?php echo number_format(intval($row['book_count'] ?? 0)); ?>)</div>
                                </div>
                                <div class="books-stack">
                                    <?php foreach (($row['books_list'] ?? []) as $book): ?>
                                        <?php if (!empty($book['is_cancelled'])): ?>
                                            <?php
                                                $refundBits = [];
                                                if (floatval($book['cash_refunded_amount'] ?? 0) > 0) {
                                                    $refundBits[] = 'Cash GH&#8373; ' . number_format(floatval($book['cash_refunded_amount']), 2);
                                                }
                                                if (floatval($book['credit_refunded_amount'] ?? 0) > 0) {
                                                    $refundBits[] = 'Credit GH&#8373; ' . number_format(floatval($book['credit_refunded_amount']), 2);
                                                }
                                                $refundText = !empty($refundBits) ? ' - ' . implode(' | ', $refundBits) : ' - Refunded';
                                            ?>
                                            <span class="book-tag tag-cancelled"><span class="book-title-copy"><i class="bi bi-x-circle"></i><?php echo htmlspecialchars(strval($book['title'] ?? '')); ?></span><span class="book-status-copy">Cancelled</span><?php echo $refundText; ?></span>
                                        <?php else: ?>
                                            <form method="POST" action="toggle_book_collection.php" class="inline-action-form inline-toggle-form" data-item-id="<?php echo intval($book['item_id'] ?? 0); ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                                                <input type="hidden" name="item_id" value="<?php echo intval($book['item_id'] ?? 0); ?>">
                                                <input type="hidden" name="desired_state" value="<?php echo !empty($book['is_collected']) ? '0' : '1'; ?>">
                                                <button
                                                    type="submit"
                                                    class="book-tag <?php echo !empty($book['is_collected']) ? 'tag-collected' : 'tag-pending'; ?>"
                                                    data-title="<?php echo htmlspecialchars(strval($book['title'] ?? ''), ENT_QUOTES); ?>"
                                                    data-state-label="<?php echo !empty($book['is_collected']) ? 'Given' : 'Not Given'; ?>"
                                                    data-item-id="<?php echo intval($book['item_id'] ?? 0); ?>"
                                                    data-state="<?php echo !empty($book['is_collected']) ? '1' : '0'; ?>"
                                                >
                                                    <span class="book-title-copy"><i class="bi <?php echo !empty($book['is_collected']) ? 'bi-check-lg' : 'bi-circle'; ?>"></i><?php echo htmlspecialchars(strval($book['title'] ?? '')); ?></span>
                                                    <span class="book-status-copy"><?php echo !empty($book['is_collected']) ? 'Given' : 'Not Given'; ?></span>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="request-actions">
                                <div class="primary-actions">
                                    <button type="button" class="action-submit payment-record" data-payment-role="action" data-request-id="<?php echo intval($row['request_id']); ?>" data-status="<?php echo htmlspecialchars(strval($row['payment_status_normalized'] ?? 'unpaid')); ?>" data-payment-open="<?php echo intval($row['request_id']); ?>">
                                        <i class="bi bi-credit-card-2-front"></i>
                                        <span>Record Payment</span>
                                    </button>
                                    <button type="button" class="action-link books-primary<?php echo !empty($row['all_books_given']) ? ' is-complete' : ''; ?>" data-books-focus="<?php echo intval($row['request_id']); ?>">
                                        <i class="bi <?php echo !empty($row['all_books_given']) ? 'bi-check2-circle' : 'bi-box-seam'; ?>"></i>
                                        <span><?php echo !empty($row['all_books_given']) ? 'Books Given' : 'Give Books'; ?></span>
                                    </button>
                                </div>
                                <div class="secondary-actions">
                                    <div class="secondary-actions-left">
                                        <a href="student_history.php?student_id=<?php echo intval($row['student_id']); ?>&index=<?php echo urlencode(strval($row['index_number'] ?? '')); ?>&semester_id=<?php echo intval($semester_id); ?>&return_url=<?php echo urlencode($history_return_url); ?>" class="action-link history">
                                            <i class="bi bi-clock-history"></i>
                                            <span>History</span>
                                        </a>
                                    </div>
                                    <div class="secondary-actions-right">
                                        <details class="more-menu">
                                            <summary class="action-link more-trigger">
                                                <i class="bi bi-three-dots"></i>
                                                <span>More</span>
                                            </summary>
                                            <div class="more-panel">
                                                <?php
                                                    $edit_return_params = [];
                                                    if ($search !== '') {
                                                        $edit_return_params['search'] = $search;
                                                    }
                                                    if ($request_filter !== 'all') {
                                                        $edit_return_params['filter'] = $request_filter;
                                                    }
                                                    if ($request_day !== '') {
                                                        $edit_return_params['request_day'] = $request_day;
                                                    }
                                                    if ($selected_book_id > 0) {
                                                        $edit_return_params['book_id'] = $selected_book_id;
                                                        if ($book_status !== 'all') {
                                                            $edit_return_params['book_status'] = $book_status;
                                                        }
                                                    }
                                                    if ($current_page > 1) {
                                                        $edit_return_params['page'] = $current_page;
                                                    }
                                                    $edit_return_query = http_build_query($edit_return_params);
                                                    $edit_return_url = 'view_request.php'
                                                        . ($edit_return_query !== '' ? '?' . $edit_return_query : '')
                                                        . '#requestCard' . intval($row['request_id']);
                                                ?>
                                                <a href="edit_request.php?id=<?php echo intval($row['request_id']); ?>&return_url=<?php echo urlencode($edit_return_url); ?>" class="action-link books">
                                                    <i class="bi bi-pencil-square"></i>
                                                    <span>Edit Request</span>
                                                </a>
                                                <?php if (floatval($row['remaining_balance'] ?? 0) > 0.009 || floatval($row['student_credit_balance'] ?? 0) > 0.009): ?>
                                                    <form method="POST" action="view_request.php" class="inline-action-form" onsubmit="return confirm('Return this extra balance to the student now?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                        <input type="hidden" name="return_balance" value="1">
                                                        <input type="hidden" name="request_id" value="<?php echo intval($row['request_id']); ?>">
                                                        <input type="hidden" name="student_id" value="<?php echo intval($row['student_id']); ?>">
                                                        <button type="submit" class="action-submit">
                                                            <i class="bi bi-cash-coin"></i>
                                                            <span>Return Balance</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" action="delete_request.php" class="inline-action-form" onsubmit="return confirm('Delete this request?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="id" value="<?php echo intval($row['request_id']); ?>">
                                                    <button type="submit" class="action-submit delete">
                                                        <i class="bi bi-trash"></i>
                                                        <span>Delete Request</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="icon"><i class="bi bi-inbox"></i></div>
                    <p><?php echo htmlspecialchars($empty_state_message); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="pagination">
            <div>
                <?php if ($page > 1): ?>
                    <a class="btn btn-secondary" href="view_request.php?<?php echo http_build_query(array_merge($_GET, ['page' => $prev_page])); ?>"><i class="bi bi-arrow-left"></i> Prev</a>
                <?php endif; ?>
            </div>
            <div class="page-indicator">Page <?php echo intval($page); ?></div>
            <div>
                <?php if ($show_next): ?>
                    <a class="btn btn-secondary" href="view_request.php?<?php echo http_build_query(array_merge($_GET, ['page' => $next_page])); ?>">Next <i class="bi bi-arrow-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<div id="pageToastStack" class="page-toast-stack" aria-live="polite" aria-atomic="true"></div>
<div id="paymentSheetBackdrop" class="payment-sheet-backdrop" hidden></div>
<section id="paymentSheet" class="payment-sheet" aria-hidden="true">
    <div class="payment-sheet-handle"></div>
    <div class="payment-sheet-head">
        <div>
            <h3>Record Payment</h3>
            <p id="paymentSheetStudentName">Student</p>
        </div>
        <button type="button" class="payment-sheet-close" id="paymentSheetClose" aria-label="Close payment sheet">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div class="payment-sheet-grid">
        <div class="payment-sheet-stat">
            <span class="label">Request Total</span>
            <span class="value" id="paymentSheetTotal">GHS 0.00</span>
        </div>
        <div class="payment-sheet-stat">
            <span class="label">Credit Applied</span>
            <span class="value" id="paymentSheetCredit">GHS 0.00</span>
        </div>
        <div class="payment-sheet-stat">
            <span class="label">Already Paid</span>
            <span class="value" id="paymentSheetPaid">GHS 0.00</span>
        </div>
        <div class="payment-sheet-stat">
            <span class="label">Outstanding Balance</span>
            <span class="value" id="paymentSheetOutstanding">GHS 0.00</span>
        </div>
    </div>
    <form id="paymentSheetForm" class="payment-sheet-form" action="toggle_payment.php" method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="request_id" id="paymentSheetRequestId" value="">
        <input type="hidden" name="payment_nonce" id="paymentSheetNonce" value="">
        <div class="payment-sheet-input">
            <label for="paymentSheetAmount">Amount Received</label>
            <input type="number" min="0" step="0.01" inputmode="decimal" id="paymentSheetAmount" name="amount_received" placeholder="Enter amount received">
        </div>
        <div id="paymentSheetFeedback" class="payment-sheet-feedback" aria-live="polite"></div>
        <div class="payment-sheet-actions">
            <button type="submit" class="save-payment-btn" data-payment-action="record_payment">Save Payment</button>
            <button type="button" class="mark-paid-btn" id="paymentSheetMarkPaid">Mark Fully Paid</button>
        </div>
    </form>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var toastStack = document.getElementById('pageToastStack');
    var paymentSheet = document.getElementById('paymentSheet');
    var paymentSheetBackdrop = document.getElementById('paymentSheetBackdrop');
    var paymentSheetForm = document.getElementById('paymentSheetForm');
    var paymentSheetClose = document.getElementById('paymentSheetClose');
    var paymentSheetMarkPaid = document.getElementById('paymentSheetMarkPaid');
    var paymentSheetStudentName = document.getElementById('paymentSheetStudentName');
    var paymentSheetRequestId = document.getElementById('paymentSheetRequestId');
    var paymentSheetNonce = document.getElementById('paymentSheetNonce');
    var paymentSheetAmount = document.getElementById('paymentSheetAmount');
    var paymentSheetFeedback = document.getElementById('paymentSheetFeedback');
    var paymentSheetTotal = document.getElementById('paymentSheetTotal');
    var paymentSheetCredit = document.getElementById('paymentSheetCredit');
    var paymentSheetPaid = document.getElementById('paymentSheetPaid');
    var paymentSheetOutstanding = document.getElementById('paymentSheetOutstanding');
    var activePaymentRequestId = '';
    var paymentSheetActiveAction = 'record_payment';

    function showToast(message, kind) {
        if (!toastStack || !message) {
            return;
        }
        var toast = document.createElement('div');
        toast.className = 'page-toast ' + (kind === 'error' ? 'toast-error' : 'toast-success');
        toast.textContent = message;
        toastStack.appendChild(toast);
        window.setTimeout(function() {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(8px)';
            window.setTimeout(function() {
                toast.remove();
            }, 180);
        }, 2200);
    }

    function setFeedback(node, message, kind) {
        if (!node) {
            return;
        }
        node.textContent = message || '';
        node.classList.remove('is-success', 'is-error');
        if (kind === 'success') {
            node.classList.add('is-success');
        } else if (kind === 'error') {
            node.classList.add('is-error');
        }
    }

    function applyBookStateToAll(itemId, isCollected) {
        document.querySelectorAll('.book-tag[data-item-id="' + itemId + '"]').forEach(function(button) {
            var title = button.getAttribute('data-title') || button.textContent.trim();
            var stateLabel = isCollected ? 'Given' : 'Not Given';
            button.dataset.state = isCollected ? '1' : '0';
            button.dataset.stateLabel = stateLabel;
            button.classList.remove('tag-pending', 'tag-collected');
            button.classList.add(isCollected ? 'tag-collected' : 'tag-pending');
            button.innerHTML = '<span class="book-title-copy">' + (isCollected ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-circle"></i>') + title + '</span><span class="book-status-copy">' + stateLabel + '</span>';
            var card = button.closest('[data-request-card]');
            if (card) {
                updateGiveBooksButton(card);
            }
        });
        document.querySelectorAll('.inline-toggle-form[data-item-id="' + itemId + '"]').forEach(function(form) {
            var desiredInput = form.querySelector('input[name="desired_state"]');
            if (desiredInput) {
                desiredInput.value = isCollected ? '0' : '1';
            }
        });
    }

    function updateGiveBooksButton(card) {
        if (!card) {
            return;
        }
        var requestId = card.getAttribute('data-request-card');
        var tags = card.querySelectorAll('.book-tag[data-item-id]');
        var hasPending = false;
        tags.forEach(function(tag) {
            if (tag.dataset.state !== '1') {
                hasPending = true;
            }
        });
        card.querySelectorAll('.books-primary[data-books-focus="' + requestId + '"]').forEach(function(button) {
            button.classList.toggle('is-complete', !hasPending && tags.length > 0);
            button.innerHTML = (!hasPending && tags.length > 0)
                ? '<i class="bi bi-check2-circle"></i><span>Books Given</span>'
                : '<i class="bi bi-box-seam"></i><span>Give Books</span>';
        });
    }

    function applyPaymentStateToAll(requestId, newStatus) {
        var iconHtml = '<i class="bi bi-exclamation-circle"></i>';
        var statusClass = 'status-unpaid';
        if (newStatus === 'paid') {
            iconHtml = '<i class="bi bi-check-circle"></i>';
            statusClass = 'status-paid';
        } else if (newStatus === 'partial') {
            iconHtml = '<i class="bi bi-dash-circle"></i>';
            statusClass = 'status-partial';
        }

        document.querySelectorAll('.status-badge[data-request-id="' + requestId + '"]').forEach(function(button) {
            button.dataset.status = newStatus;
            button.classList.remove('status-paid', 'status-partial', 'status-unpaid');
            button.classList.add(statusClass);
            var role = button.getAttribute('data-payment-role') || 'pill';
            if (role === 'action') {
                button.innerHTML = '<i class="bi bi-credit-card-2-front"></i><span>Record Payment</span>';
            } else {
                button.innerHTML = iconHtml + ' ' + String(newStatus || 'unpaid').toUpperCase();
            }
        });

        document.querySelectorAll('.js-payment-status-text[data-request-id="' + requestId + '"]').forEach(function(node) {
            node.textContent = String(newStatus || 'unpaid').toUpperCase();
            node.classList.remove('status-text-paid', 'status-text-partial', 'status-text-unpaid');
            node.classList.add('status-text-' + (newStatus || 'unpaid'));
        });
    }

    function applyPaidAmountToAll(requestId, amountLabel) {
        if (!amountLabel) {
            return;
        }
        document.querySelectorAll('.js-paid-amount[data-request-id="' + requestId + '"]').forEach(function(node) {
            node.innerHTML = amountLabel;
        });
    }

    function applyCreditUsedToAll(requestId, amountLabel) {
        if (!amountLabel) {
            return;
        }
        document.querySelectorAll('.js-credit-used[data-request-id="' + requestId + '"]').forEach(function(node) {
            node.innerHTML = amountLabel;
        });
    }

    function applyBalanceToAll(requestId, balanceHtml) {
        document.querySelectorAll('.js-balance-display[data-request-id="' + requestId + '"]').forEach(function(node) {
            var card = node.closest('[data-request-card]');
            var amount = card ? Number(card.getAttribute('data-outstanding-balance') || 0) : 0;
            node.textContent = formatMoney(amount);
        });
    }

    function applyBalanceLabelToAll(requestId, labelText) {
        document.querySelectorAll('.js-balance-label[data-request-id="' + requestId + '"]').forEach(function(node) {
            var card = node.closest('[data-request-card]');
            var amount = card ? Number(card.getAttribute('data-outstanding-balance') || 0) : 0;
            node.textContent = 'OWES ' + formatMoney(amount);
        });
    }

    function applyPaymentNoteToAll(requestId, noteText) {
        if (!noteText) {
            return;
        }
        document.querySelectorAll('.js-payment-note[data-request-id="' + requestId + '"]').forEach(function(node) {
            node.textContent = noteText;
        });
    }

    function formatMoney(amount) {
        var numericAmount = Number(amount || 0);
        return 'GHS ' + numericAmount.toFixed(2);
    }

    function createPaymentNonce() {
        return 'pay_' + Date.now() + '_' + Math.random().toString(36).slice(2, 12);
    }

    function refreshSummaryCounts() {
        var paid = 0;
        var outstanding = 0;
        document.querySelectorAll('[data-request-card]').forEach(function(card) {
            var status = card.getAttribute('data-payment-status') || 'unpaid';
            if (status === 'paid') {
                paid += 1;
            } else if (status === 'partial' || status === 'unpaid') {
                outstanding += 1;
            }
        });

        var paidNode = document.querySelector('[data-summary-value="paid"]');
        var outstandingNode = document.querySelector('[data-summary-value="outstanding"]');
        if (paidNode) {
            paidNode.textContent = String(paid);
        }
        if (outstandingNode) {
            outstandingNode.textContent = String(outstanding);
        }
    }

    function syncCardPaymentData(requestId, payload) {
        var card = document.querySelector('[data-request-card="' + requestId + '"]');
        if (!card || !payload) {
            return;
        }
        card.setAttribute('data-payment-status', payload.payment_status || 'unpaid');
        if (typeof payload.amount_paid === 'number') {
            card.setAttribute('data-amount-paid', Number(payload.amount_paid).toFixed(2));
        }
        if (typeof payload.credit_used === 'number') {
            card.setAttribute('data-credit-used', Number(payload.credit_used).toFixed(2));
        }
        if (typeof payload.total_amount === 'number') {
            card.setAttribute('data-total-amount', Number(payload.total_amount).toFixed(2));
        }
        if (typeof payload.balance === 'number') {
            card.setAttribute('data-outstanding-balance', Number(payload.balance).toFixed(2));
        }
        var paid = Number(card.getAttribute('data-amount-paid') || 0);
        var total = Number(card.getAttribute('data-total-amount') || 0);
        var credit = Number(card.getAttribute('data-credit-used') || 0);
        var dueAfterCredit = Math.max(0, total - credit);
        var progress = dueAfterCredit <= 0.009 ? 100 : Math.max(0, Math.min(100, Math.round((paid / Math.max(dueAfterCredit, 0.01)) * 100)));
        card.querySelectorAll('.payment-progress-copy').forEach(function(node) {
            node.innerHTML = '<span>' + progress + '% paid</span><span>' + Math.max(0, 100 - progress) + '% balance</span>';
        });
        card.querySelectorAll('.payment-progress-fill').forEach(function(node) {
            node.style.width = progress + '%';
            node.classList.remove('progress-unpaid', 'progress-partial', 'progress-paid');
            node.classList.add('progress-' + (payload.payment_status || 'unpaid'));
        });
    }

    function setPaymentSheetOpen(isOpen) {
        if (!paymentSheet || !paymentSheetBackdrop) {
            return;
        }
        paymentSheet.classList.toggle('is-open', isOpen);
        paymentSheet.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
        paymentSheetBackdrop.classList.toggle('is-open', isOpen);
        paymentSheetBackdrop.hidden = !isOpen;
        document.body.style.overflow = isOpen ? 'hidden' : '';
    }

    function closePaymentSheet() {
        activePaymentRequestId = '';
        paymentSheetActiveAction = 'record_payment';
        if (paymentSheetForm) {
            paymentSheetForm.reset();
        }
        setFeedback(paymentSheetFeedback, '', null);
        setPaymentSheetOpen(false);
    }

    function populatePaymentSheetFromCard(card, requestId) {
        if (!card) {
            return;
        }
        var studentName = card.getAttribute('data-student-name') || 'Student';
        var totalAmount = Number(card.getAttribute('data-total-amount') || 0);
        var creditUsed = Number(card.getAttribute('data-credit-used') || 0);
        var amountPaid = Number(card.getAttribute('data-amount-paid') || 0);
        var outstandingBalance = Number(card.getAttribute('data-outstanding-balance') || 0);

        activePaymentRequestId = String(requestId || '');
        paymentSheetStudentName.textContent = studentName;
        paymentSheetRequestId.value = activePaymentRequestId;
        paymentSheetNonce.value = createPaymentNonce();
        paymentSheetTotal.textContent = formatMoney(totalAmount);
        paymentSheetCredit.textContent = formatMoney(creditUsed);
        paymentSheetPaid.textContent = formatMoney(amountPaid);
        paymentSheetOutstanding.textContent = formatMoney(outstandingBalance);
        paymentSheetAmount.value = '';
        paymentSheetAmount.max = outstandingBalance > 0 ? outstandingBalance.toFixed(2) : '0.00';
        paymentSheetMarkPaid.disabled = outstandingBalance <= 0.009;
        paymentSheetMarkPaid.textContent = outstandingBalance <= 0.009 ? 'Already Paid' : 'Mark Fully Paid';
        setFeedback(paymentSheetFeedback, '', null);
        setPaymentSheetOpen(true);
        window.setTimeout(function() {
            paymentSheetAmount.focus();
        }, 60);
    }

    function openPaymentSheet(requestId) {
        var card = document.querySelector('[data-request-card="' + requestId + '"]');
        if (!card || !paymentSheet) {
            return;
        }
        populatePaymentSheetFromCard(card, requestId);
    }

    function updatePaymentSheetFigures(payload) {
        if (!payload || activePaymentRequestId === '') {
            return;
        }
        paymentSheetPaid.textContent = payload.amount_paid_display || formatMoney(payload.amount_paid || 0);
        paymentSheetCredit.textContent = payload.credit_used_display || formatMoney(payload.credit_used || 0);
        paymentSheetTotal.textContent = payload.total_amount_display || formatMoney(payload.total_amount || 0);
        paymentSheetOutstanding.textContent = payload.balance_display || formatMoney(payload.balance || 0);
        paymentSheetAmount.value = '';
        paymentSheetAmount.max = typeof payload.balance === 'number' ? Number(payload.balance).toFixed(2) : paymentSheetAmount.max;
        paymentSheetNonce.value = createPaymentNonce();
        paymentSheetMarkPaid.disabled = Number(payload.balance || 0) <= 0.009;
        paymentSheetMarkPaid.textContent = Number(payload.balance || 0) <= 0.009 ? 'Already Paid' : 'Mark Fully Paid';
    }

    function submitPaymentSheet(action) {
        if (!paymentSheetForm || !activePaymentRequestId) {
            return;
        }

        if (action === 'record_payment') {
            var enteredAmount = Number(paymentSheetAmount.value || 0);
            var maxOutstanding = Number((document.querySelector('[data-request-card="' + activePaymentRequestId + '"]') || document.body).getAttribute('data-outstanding-balance') || 0);
            if (!paymentSheetAmount.value) {
                setFeedback(paymentSheetFeedback, 'Enter the amount received before saving.', 'error');
                return;
            }
            if (!Number.isFinite(enteredAmount) || enteredAmount <= 0) {
                setFeedback(paymentSheetFeedback, 'Amount received must be greater than GHS 0.00.', 'error');
                return;
            }
            if (enteredAmount > (maxOutstanding + 0.009)) {
                setFeedback(paymentSheetFeedback, 'Amount received cannot be greater than outstanding balance.', 'error');
                return;
            }
        }

        var requestId = activePaymentRequestId;
        var data = new FormData(paymentSheetForm);
        data.append('ajax', '1');
        data.append('action', action);
        if (action === 'mark_fully_paid') {
            data.set('amount_received', '');
        }

        var buttons = paymentSheetForm.querySelectorAll('button');
        buttons.forEach(function(button) {
            button.disabled = true;
        });
        setFeedback(paymentSheetFeedback, 'Saving...', null);

        fetch(paymentSheetForm.getAttribute('action'), {
            method: 'POST',
            body: data,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'same-origin'
        })
            .then(parseJsonResponse)
            .then(function(payload) {
                if (payload && payload.success) {
                    var resolvedRequestId = String(payload.request_id || requestId);
                    applyPaymentStateToAll(resolvedRequestId, payload.payment_status || 'unpaid');
                    applyPaidAmountToAll(resolvedRequestId, payload.amount_paid_display || '');
                    applyCreditUsedToAll(resolvedRequestId, payload.credit_used_display || '');
                    applyBalanceLabelToAll(resolvedRequestId, payload.balance_label || '');
                    applyBalanceToAll(resolvedRequestId, payload.balance_html || '');
                    applyPaymentNoteToAll(resolvedRequestId, payload.payment_note_text || '');
                    syncCardPaymentData(resolvedRequestId, payload);
                    refreshSummaryCounts();
                    updatePaymentSheetFigures(payload);
                    setFeedback(paymentSheetFeedback, 'Payment recorded', 'success');
                    showToast(payload.message || 'Payment recorded successfully.', 'success');
                    if (Number(payload.balance || 0) <= 0.009) {
                        window.setTimeout(function() {
                            closePaymentSheet();
                        }, 420);
                    }
                } else {
                    var errorMessage = (payload && payload.message) ? payload.message : 'Could not record payment. Please try again.';
                    setFeedback(paymentSheetFeedback, errorMessage, 'error');
                    showToast(errorMessage, 'error');
                }
            })
            .catch(function() {
                var fallbackMessage = 'Could not record payment. Please try again.';
                setFeedback(paymentSheetFeedback, fallbackMessage, 'error');
                showToast(fallbackMessage, 'error');
            })
            .finally(function() {
                buttons.forEach(function(button) {
                    button.disabled = false;
                });
            });
    }

    function parseJsonResponse(response) {
        if (!response.ok) {
            throw new Error('Request failed');
        }
        return response.text().then(function(text) {
            var sanitized = String(text || '').replace(/^\uFEFF/, '').trim();
            try {
                return JSON.parse(sanitized);
            } catch (error) {
                throw new Error(sanitized || 'Invalid server response');
            }
        });
    }

    var requestSearchForm = document.getElementById('requestSearchForm');
    var requestBookFilter = document.getElementById('requestBookFilter');
    var requestSearchInput = document.getElementById('requestSearchInput');
    if (requestSearchForm && requestBookFilter) {
        requestBookFilter.addEventListener('change', function() {
            requestSearchForm.submit();
        });
    }
    if (requestSearchForm && requestSearchInput) {
        var lastSubmittedSearchValue = requestSearchInput.value;
        var searchDebounceTimer = null;
        var submitSearchForm = function(nextValue) {
            if (nextValue === lastSubmittedSearchValue) {
                return;
            }
            lastSubmittedSearchValue = nextValue;
            try {
                window.sessionStorage.setItem('requestSearchRestoreFocus', '1');
            } catch (error) {
                // Search still works when browser storage is unavailable.
            }
            requestSearchForm.submit();
        };
        try {
            if (window.sessionStorage.getItem('requestSearchRestoreFocus') === '1') {
                window.sessionStorage.removeItem('requestSearchRestoreFocus');
                requestSearchInput.focus();
                requestSearchInput.setSelectionRange(requestSearchInput.value.length, requestSearchInput.value.length);
            }
        } catch (error) {
            // Focus restoration is an enhancement only.
        }
        requestSearchInput.addEventListener('input', function() {
            var nextValue = requestSearchInput.value;
            window.clearTimeout(searchDebounceTimer);
            if (nextValue.trim() === '') {
                submitSearchForm(nextValue);
                return;
            }
            searchDebounceTimer = window.setTimeout(function() {
                submitSearchForm(nextValue);
            }, 650);
        });
        requestSearchInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(searchDebounceTimer);
                submitSearchForm(requestSearchInput.value);
            }
        });
    }

    document.querySelectorAll('[data-card-toggle]').forEach(function(toggleButton) {
        toggleButton.addEventListener('click', function() {
            var requestId = toggleButton.getAttribute('data-card-toggle');
            var card = document.querySelector('[data-request-card="' + requestId + '"]');
            if (!card) {
                return;
            }
            card.classList.toggle('is-collapsed');
        });
    });

    document.querySelectorAll('[data-books-focus]').forEach(function(button) {
        button.addEventListener('click', function() {
            if (button.classList.contains('is-complete')) {
                return;
            }
            var requestId = button.getAttribute('data-books-focus');
            var card = document.querySelector('[data-request-card="' + requestId + '"]');
            var panel = document.getElementById('booksPanel' + requestId);
            if (!card || !panel) {
                return;
            }
            card.classList.remove('is-collapsed');
            panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    });

    document.querySelectorAll('.inline-toggle-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var button = form.querySelector('.book-tag');
            if (!button) {
                return;
            }

            var itemId = button.getAttribute('data-item-id') || form.getAttribute('data-item-id');
            var previousState = button.dataset.state === '1';
            var nextState = !previousState;
            var feedbackNode = form.querySelector('.book-status-saving');
            if (!feedbackNode) {
                feedbackNode = document.createElement('div');
                feedbackNode.className = 'book-status-saving';
                form.appendChild(feedbackNode);
            }

            var desiredValue = nextState ? '1' : '0';
            var data = new FormData(form);
            data.set('desired_state', desiredValue);
            data.append('ajax', '1');

            applyBookStateToAll(itemId, nextState);
            document.querySelectorAll('.book-tag[data-item-id="' + itemId + '"]').forEach(function(btn) {
                btn.classList.add('is-saving');
            });
            setFeedback(feedbackNode, 'Saving...', null);
            button.disabled = true;

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: data,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
                .then(parseJsonResponse)
                .then(function(payload) {
                    if (payload && payload.success) {
                        var isCollected = parseInt(payload.is_collected || 0, 10) === 1;
                        applyBookStateToAll(String(payload.item_id || itemId), isCollected);
                        setFeedback(feedbackNode, 'Saved', 'success');
                        window.setTimeout(function() {
                            setFeedback(feedbackNode, '', null);
                        }, 1200);
                    } else {
                        applyBookStateToAll(itemId, previousState);
                        var errorMessage = (payload && (payload.error || payload.message)) ? (payload.error || payload.message) : 'Could not update book status. Please try again.';
                        setFeedback(feedbackNode, errorMessage, 'error');
                        showToast(errorMessage, 'error');
                    }
                })
                .catch(function() {
                    applyBookStateToAll(itemId, previousState);
                    var fallbackMessage = 'Could not update book status. Please try again.';
                    setFeedback(feedbackNode, fallbackMessage, 'error');
                    showToast(fallbackMessage, 'error');
                })
                .finally(function() {
                    document.querySelectorAll('.book-tag[data-item-id="' + itemId + '"]').forEach(function(btn) {
                        btn.classList.remove('is-saving');
                        btn.disabled = false;
                    });
                });
        });
    });

    document.querySelectorAll('[data-payment-open]').forEach(function(button) {
        button.addEventListener('click', function() {
            var requestId = button.getAttribute('data-payment-open');
            if (requestId) {
                openPaymentSheet(requestId);
            }
        });
    });

    if (paymentSheetForm) {
        paymentSheetForm.addEventListener('submit', function(e) {
            e.preventDefault();
            paymentSheetActiveAction = 'record_payment';
            submitPaymentSheet('record_payment');
        });
    }

    if (paymentSheetMarkPaid) {
        paymentSheetMarkPaid.addEventListener('click', function() {
            paymentSheetActiveAction = 'mark_fully_paid';
            submitPaymentSheet('mark_fully_paid');
        });
    }

    if (paymentSheetClose) {
        paymentSheetClose.addEventListener('click', closePaymentSheet);
    }

    if (paymentSheetBackdrop) {
        paymentSheetBackdrop.addEventListener('click', closePaymentSheet);
    }

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && paymentSheet && paymentSheet.classList.contains('is-open')) {
            closePaymentSheet();
        }
    });

    document.querySelectorAll('[data-request-card]').forEach(function(card) {
        updateGiveBooksButton(card);
    });
    refreshSummaryCounts();

    (function () {
        var refreshUrl = new URL(window.location.href);
        refreshUrl.searchParams.set('refresh', 'status');
        var currentStateToken = <?php echo json_encode($request_refresh_token); ?>;
        var refreshInFlight = false;
        var lastRefreshAt = 0;

        function checkForRequestChanges() {
            var now = Date.now();
            if (document.visibilityState !== 'visible') {
                return;
            }
            if (refreshInFlight || (now - lastRefreshAt) < 10000) {
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
            .then(function(response) { return response.text(); })
            .then(function(rawText) {
                var payload = JSON.parse(String(rawText || '').replace(/^\uFEFF/, ''));
                if (payload && payload.success && payload.state_token && payload.state_token !== currentStateToken) {
                    window.location.reload();
                }
            })
            .catch(function() {})
            .finally(function() {
                refreshInFlight = false;
            });
        }

        window.setInterval(checkForRequestChanges, 15000);
        window.addEventListener('focus', checkForRequestChanges);
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                checkForRequestChanges();
            }
        });
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                checkForRequestChanges();
            }
        });
    })();
});
</script>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>

</body>
</html>



