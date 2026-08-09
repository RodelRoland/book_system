<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'books', 'course_code', 'VARCHAR(20) NULL AFTER book_title');
        book_system_setup_ensure_column($conn, 'books', 'course_code_key', 'VARCHAR(20) NULL AFTER course_code');
        if (function_exists('book_system_setup_ensure_varchar_length')) {
            book_system_setup_ensure_varchar_length($conn, 'books', 'book_title', 191, 'NOT NULL');
        }
    }
}

function book_system_manage_books_normalize_code(string $courseCode): string
{
    return function_exists('book_system_normalize_course_code')
        ? book_system_normalize_course_code($courseCode)
        : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $courseCode));
}

function book_system_manage_books_normalize_title(string $title): string
{
    $normalized = strtoupper(trim($title));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return is_string($normalized) ? $normalized : '';
}

function book_system_manage_books_find_duplicate(
    mysqli $conn,
    int $adminId,
    int $semesterId,
    string $normalizedTitle,
    string $courseCodeKey,
    int $excludeBookId = 0
): int {
    if ($adminId <= 0 || $semesterId <= 0 || $normalizedTitle === '' || $courseCodeKey === '') {
        return 0;
    }

    $sql = "SELECT book_id
        FROM books
        WHERE semester_id = ?
          AND (admin_id = ? OR admin_id IS NULL)
          AND course_code_key = ?
          AND UPPER(TRIM(book_title)) = ?";

    if ($excludeBookId > 0) {
        $sql .= " AND book_id <> ?";
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    if ($excludeBookId > 0) {
        $stmt->bind_param('iissi', $semesterId, $adminId, $courseCodeKey, $normalizedTitle, $excludeBookId);
    } else {
        $stmt->bind_param('iiss', $semesterId, $adminId, $courseCodeKey, $normalizedTitle);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $duplicateId = ($result && $result->num_rows === 1)
        ? intval($result->fetch_assoc()['book_id'] ?? 0)
        : 0;
    $stmt->close();

    return $duplicateId;
}

function book_system_manage_books_book_usage_summary(mysqli $conn, int $bookId): array
{
    $summary = [
        'request_items' => 0,
        'books_received' => 0,
        'lecturer_payments' => 0,
    ];

    if ($bookId <= 0) {
        return $summary;
    }

    $stmt = $conn->prepare("SELECT
            (SELECT COUNT(*) FROM request_items WHERE book_id = ?) AS request_items_count,
            (SELECT COUNT(*) FROM books_received WHERE book_id = ?) AS books_received_count,
            (SELECT COUNT(*) FROM lecturer_payments WHERE book_id = ?) AS lecturer_payments_count");
    if (!$stmt) {
        return $summary;
    }

    $stmt->bind_param('iii', $bookId, $bookId, $bookId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $summary['request_items'] = intval($row['request_items_count'] ?? 0);
    $summary['books_received'] = intval($row['books_received_count'] ?? 0);
    $summary['lecturer_payments'] = intval($row['lecturer_payments_count'] ?? 0);

    return $summary;
}

/* Protect admin page */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
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
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$rep_bottom_nav_active = '';
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}

if (isset($_GET['suggest_course_code'])) {
    header('Content-Type: application/json');
    $normalized_code = book_system_manage_books_normalize_code(trim(strval($_GET['suggest_course_code'] ?? '')));
    if ($normalized_code === '') {
        echo json_encode(['titles' => []]);
        exit;
    }

    $titles = [];
    $suggest_stmt = $conn->prepare("SELECT DISTINCT book_title
        FROM books
        WHERE course_code_key = ?
          AND semester_id = ?
          AND COALESCE(TRIM(book_title), '') <> ''
        ORDER BY book_title ASC
        LIMIT 8");
    if ($suggest_stmt) {
        $suggest_stmt->bind_param('si', $normalized_code, $semester_id);
        $suggest_stmt->execute();
        $suggest_result = $suggest_stmt->get_result();
        if ($suggest_result) {
            while ($suggest_row = $suggest_result->fetch_assoc()) {
                $titles[] = trim(strval($suggest_row['book_title'] ?? ''));
            }
        }
        $suggest_stmt->close();
    }

    echo json_encode(['titles' => array_values(array_filter(array_unique($titles)))]);
    exit;
}
$auto_assigned_count = 0;
$auto_assigned_total = 0;
$page_message = '';

if (isset($_GET['msg'])) {
    $msg = strval($_GET['msg']);
    if ($msg === 'csrf_invalid') {
        $page_message = 'Please retry that action. Your session token needed a refresh.';
    } elseif ($msg === 'unauthorized') {
        $page_message = 'That book update was not allowed in this workspace.';
    } elseif ($msg === 'missing_book_fields') {
        $page_message = 'Book title, course code, and price are required before the book can be saved.';
    } elseif ($msg === 'duplicate_book_exists') {
        $page_message = 'That book already exists in your current semester workspace.';
    } elseif ($msg === 'duplicate_book_hidden') {
        $page_message = 'Duplicate book hidden from students successfully.';
    } elseif ($msg === 'duplicate_book_removed') {
        $page_message = 'Unused duplicate book removed successfully.';
    } elseif ($msg === 'duplicate_book_action_blocked') {
        $page_message = 'That duplicate book already has linked records, so it could only be hidden.';
    } elseif ($msg === 'book_setup_required') {
        $page_message = 'Start by adding at least one book for your class before using the rest of the workspace.';
    }
}

$csrf_token = csrf_get_token();

/**
 * Auto-assign a book to students who have sufficient credit balance.
 * Creates a paid request and deducts from their balance.
 */
function auto_assign_book_to_students_with_balance($conn, $book_id, $book_price, $semester_id, $owner_admin_id = null) {
    $assigned_count = 0;
    $total_deducted = 0;
    
    if ($book_price <= 0) {
        return ['count' => 0, 'total' => 0];
    }
    
    // Find students on the active class list who have enough credit for this book.
    if ($owner_admin_id !== null && $owner_admin_id > 0) {
        $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.credit_balance, s.admin_id
            FROM students s
            JOIN class_students cs
              ON cs.admin_id = ?
             AND cs.semester_id = ?
             AND cs.normalized_index_number = " . book_system_normalized_index_sql('s.index_number') . "
            WHERE s.credit_balance >= ?
              AND s.admin_id = ?
        ");
        $stmt->bind_param("iidi", $owner_admin_id, $semester_id, $book_price, $owner_admin_id);
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT s.student_id, s.full_name, s.credit_balance, s.admin_id
            FROM students s
            JOIN class_students cs
              ON cs.semester_id = ?
             AND cs.admin_id = s.admin_id
             AND cs.normalized_index_number = " . book_system_normalized_index_sql('s.index_number') . "
            WHERE s.credit_balance >= ?
        ");
        $stmt->bind_param("id", $semester_id, $book_price);
    }
    $stmt->execute();
    $students = $stmt->get_result();
    
    while ($student = $students->fetch_assoc()) {
        $student_id = intval($student['student_id']);
        $admin_id = intval($student['admin_id']);
        $request_admin_id = ($owner_admin_id !== null && $owner_admin_id > 0) ? intval($owner_admin_id) : $admin_id;
        
        $conn->begin_transaction();
        try {
            $lock_stmt = $conn->prepare("SELECT credit_balance FROM students WHERE student_id = ? LIMIT 1 FOR UPDATE");
            if (!$lock_stmt) {
                throw new RuntimeException('Could not lock student balance.');
            }
            $lock_stmt->bind_param("i", $student_id);
            $lock_stmt->execute();
            $locked_student = $lock_stmt->get_result();
            $locked_row = ($locked_student && $locked_student->num_rows === 1) ? $locked_student->fetch_assoc() : null;
            $lock_stmt->close();

            $current_balance = round(floatval($locked_row['credit_balance'] ?? 0), 2);
            if ($current_balance + 0.00001 < $book_price) {
                $conn->rollback();
                continue;
            }

            // Check if student already has this book in current semester
            $check_stmt = $conn->prepare("
                SELECT ri.item_id FROM request_items ri 
                JOIN requests r ON ri.request_id = r.request_id 
                WHERE r.student_id = ? AND r.semester_id = ? AND ri.book_id = ? AND COALESCE(ri.is_cancelled, 0) = 0
                LIMIT 1
            ");
            if (!$check_stmt) {
                throw new RuntimeException('Could not verify existing book requests.');
            }
            $check_stmt->bind_param("iii", $student_id, $semester_id, $book_id);
            $check_stmt->execute();
            $existing = $check_stmt->get_result();
            if ($existing && $existing->num_rows > 0) {
                $check_stmt->close();
                $conn->rollback();
                continue;
            }
            $check_stmt->close();

            // Insert request
            $amount_paid = 0.00;
            $credit_used = $book_price;
            $req_stmt = $conn->prepare("
                INSERT INTO requests (student_id, total_amount, amount_paid, credit_used, payment_status, semester_id, admin_id, created_at) 
                VALUES (?, ?, ?, ?, 'paid', ?, ?, NOW())
            ");
            $req_stmt->bind_param("idddii", $student_id, $book_price, $amount_paid, $credit_used, $semester_id, $request_admin_id);
            $req_stmt->execute();
            $request_id = $conn->insert_id;
            
            // Insert request item
            $item_stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id, unit_price, is_collected) VALUES (?, ?, ?, 0)");
            $item_stmt->bind_param("iid", $request_id, $book_id, $book_price);
            $item_stmt->execute();
            
            // Deduct from the live locked balance atomically, then verify it.
            $new_balance = round($current_balance - $book_price, 2);
            $bal_stmt = $conn->prepare("
                UPDATE students
                SET credit_balance = ROUND(COALESCE(credit_balance, 0) - ?, 2)
                WHERE student_id = ?
                  AND COALESCE(credit_balance, 0) + 0.00001 >= ?
                LIMIT 1
            ");
            if (!$bal_stmt) {
                throw new RuntimeException('Could not update student balance.');
            }
            $bal_stmt->bind_param("did", $book_price, $student_id, $book_price);
            $bal_stmt->execute();
            if ($bal_stmt->affected_rows !== 1) {
                $bal_stmt->close();
                throw new RuntimeException('Student balance changed before the book could be assigned.');
            }
            $bal_stmt->close();

            $verify_stmt = $conn->prepare("SELECT credit_balance FROM students WHERE student_id = ? LIMIT 1");
            if (!$verify_stmt) {
                throw new RuntimeException('Could not verify student balance.');
            }
            $verify_stmt->bind_param("i", $student_id);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            $verified_row = ($verify_result && $verify_result->num_rows === 1) ? $verify_result->fetch_assoc() : null;
            $verify_stmt->close();

            $verified_balance = round(floatval($verified_row['credit_balance'] ?? 0), 2);
            if (abs($verified_balance - $new_balance) > 0.009) {
                throw new RuntimeException('Student balance verification failed after auto-assignment.');
            }
            
            $conn->commit();
            $assigned_count++;
            $total_deducted += $book_price;
            
        } catch (Exception $e) {
            $conn->rollback();
        }
    }
    
    return ['count' => $assigned_count, 'total' => $total_deducted];
}

/* Add new book */
if (isset($_POST['add_book'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: manage_books.php?msg=csrf_invalid');
        exit;
    }
    $title = trim(strval($_POST['book_title'] ?? ''));
    $normalized_title = book_system_manage_books_normalize_title($title);
    $course_code = trim($_POST['course_code'] ?? '');
    $course_code_key = book_system_manage_books_normalize_code($course_code);
    $price = floatval($_POST['price']);
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    // Default to available - quantity is optional/for tracking only
    $availability = 'available';

    if ($title === '' || $course_code_key === '' || $price <= 0) {
        header('Location: manage_books.php?msg=missing_book_fields');
        exit;
    }

    $duplicate_book_id = book_system_manage_books_find_duplicate(
        $conn,
        $current_admin_id,
        $semester_id,
        $normalized_title,
        $course_code_key
    );
    if ($duplicate_book_id > 0) {
        header('Location: manage_books.php?msg=duplicate_book_exists');
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO books (book_title, course_code, course_code_key, price, stock_quantity, availability, admin_id, semester_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdisii", $title, $course_code, $course_code_key, $price, $stock_quantity, $availability, $current_admin_id, $semester_id);
    $stmt->execute();
    $new_book_id = $conn->insert_id;

    if (function_exists('book_system_audit_log') && $new_book_id > 0) {
        book_system_audit_log($conn, 'add_book', 'book', $new_book_id, [
            'book_title' => $title,
            'course_code' => $course_code,
            'price' => $price,
            'stock_quantity' => $stock_quantity,
            'availability' => $availability,
        ]);
    }
    
    if (function_exists('clear_books_cache')) clear_books_cache();
    
    // Auto-assign to students with sufficient balance if book is available
    if ($availability === 'available' && $price > 0) {
        $result = auto_assign_book_to_students_with_balance($conn, $new_book_id, $price, $semester_id, $current_admin_id);
        $auto_assigned_count = $result['count'];
        $auto_assigned_total = $result['total'];
    }
}

/* Update book (price or availability) */
if (isset($_POST['update_book'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: manage_books.php?msg=csrf_invalid');
        exit;
    }
    $book_id = intval($_POST['book_id']);
    $title = trim(strval($_POST['book_title'] ?? ''));
    $normalized_title = book_system_manage_books_normalize_title($title);
    $course_code = trim($_POST['course_code'] ?? '');
    $course_code_key = book_system_manage_books_normalize_code($course_code);
    $price = floatval($_POST['price']);
    $effective_date = trim(strval($_POST['effective_date'] ?? ''));
    $stock_quantity = intval($_POST['stock_quantity'] ?? 0);
    $availability = $_POST['availability'] === 'out_of_stock'
        ? 'out_of_stock'
        : 'available';

    $today = date('Y-m-d');
    if ($effective_date === '') {
        $effective_date = $today;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $effective_date);
    if (!$dt || $dt->format('Y-m-d') !== $effective_date) {
        $effective_date = $today;
    }

    if ($book_id <= 0 || $normalized_title === '' || $course_code_key === '' || $price <= 0) {
        header('Location: manage_books.php?msg=missing_book_fields');
        exit;
    }

    $duplicate_book_id = book_system_manage_books_find_duplicate(
        $conn,
        $current_admin_id,
        $semester_id,
        $normalized_title,
        $course_code_key,
        $book_id
    );
    if ($duplicate_book_id > 0) {
        header('Location: manage_books.php?msg=duplicate_book_exists');
        exit;
    }

    // Stock quantity is optional - don't force out_of_stock based on quantity
    // Only use the availability dropdown selection

    // Check if book was previously unavailable and is now being made available
    if ($is_super_admin) {
        $prev_stmt = $conn->prepare("SELECT availability, price FROM books WHERE book_id = ? AND semester_id = ?");
        $prev_stmt->bind_param("ii", $book_id, $semester_id);
    } else {
        $prev_stmt = $conn->prepare("SELECT availability, price FROM books WHERE book_id = ? AND semester_id = ? AND (admin_id = ? OR admin_id IS NULL)");
        $prev_stmt->bind_param("iii", $book_id, $semester_id, $current_admin_id);
    }
    $prev_stmt->execute();
    $prev_result = $prev_stmt->get_result();
    $prev_book = $prev_result->fetch_assoc();
    if (!$prev_book) {
        header('Location: manage_books.php?msg=unauthorized');
        exit;
    }
    $was_unavailable = ($prev_book && $prev_book['availability'] === 'out_of_stock');
    $price_changed = ($prev_book && floatval($prev_book['price']) != $price);
    $old_price = $prev_book ? floatval($prev_book['price']) : null;

    $schedule_price_change = ($price_changed && $effective_date > $today);

    if ($price_changed && $old_price !== null) {
        $sup = $conn->prepare("UPDATE book_price_history SET applied_at = NOW(), notes = CONCAT(IFNULL(notes, ''), IF(IFNULL(notes,'')='', '', ' | '), 'superseded') WHERE book_id = ? AND applied_at IS NULL AND effective_date IS NOT NULL AND effective_date > ?");
        if ($sup) {
            $sup->bind_param('is', $book_id, $today);
            $sup->execute();
        }
    }
    
    if ($schedule_price_change) {
        if ($is_super_admin) {
            $upd_stmt = $conn->prepare("UPDATE books SET book_title = ?, course_code = ?, course_code_key = ?, stock_quantity = ?, availability = ? WHERE book_id = ? AND semester_id = ?");
            $upd_stmt->bind_param("sssisii", $title, $course_code, $course_code_key, $stock_quantity, $availability, $book_id, $semester_id);
        } else {
            $upd_stmt = $conn->prepare("UPDATE books SET book_title = ?, course_code = ?, course_code_key = ?, stock_quantity = ?, availability = ?, admin_id = COALESCE(admin_id, ?) WHERE book_id = ? AND semester_id = ? AND (admin_id = ? OR admin_id IS NULL)");
            $upd_stmt->bind_param("sssisiiii", $title, $course_code, $course_code_key, $stock_quantity, $availability, $current_admin_id, $book_id, $semester_id, $current_admin_id);
        }
        $upd_stmt->execute();
    } else {
        if ($is_super_admin) {
            $upd_stmt = $conn->prepare("UPDATE books SET book_title = ?, course_code = ?, course_code_key = ?, price = ?, stock_quantity = ?, availability = ? WHERE book_id = ? AND semester_id = ?");
            $upd_stmt->bind_param("sssdisii", $title, $course_code, $course_code_key, $price, $stock_quantity, $availability, $book_id, $semester_id);
        } else {
            $upd_stmt = $conn->prepare("UPDATE books SET book_title = ?, course_code = ?, course_code_key = ?, price = ?, stock_quantity = ?, availability = ?, admin_id = COALESCE(admin_id, ?) WHERE book_id = ? AND semester_id = ? AND (admin_id = ? OR admin_id IS NULL)");
            $upd_stmt->bind_param("sssdisiiii", $title, $course_code, $course_code_key, $price, $stock_quantity, $availability, $current_admin_id, $book_id, $semester_id, $current_admin_id);
        }
        $upd_stmt->execute();
    }

    if ($price_changed && $old_price !== null) {
        $hstmt = $conn->prepare("INSERT INTO book_price_history (book_id, old_price, new_price, changed_by_admin_id, effective_date, applied_at) VALUES (?, ?, ?, ?, ?, ?)");
        if ($hstmt) {
            $applied_at = $schedule_price_change ? null : date('Y-m-d H:i:s');
            $hstmt->bind_param('iddiss', $book_id, $old_price, $price, $current_admin_id, $effective_date, $applied_at);
            $hstmt->execute();
        }
    }

    if (function_exists('clear_books_cache')) clear_books_cache();
    
    // Auto-assign if book is now available (was unavailable OR price changed)
    if ($availability === 'available' && $price > 0 && ($was_unavailable || ($price_changed && !$schedule_price_change))) {
        $result = auto_assign_book_to_students_with_balance($conn, $book_id, $price, $semester_id, $is_super_admin ? null : $current_admin_id);
        $auto_assigned_count = $result['count'];
        $auto_assigned_total = $result['total'];
    }

    if (function_exists('book_system_audit_log')) {
        book_system_audit_log($conn, 'update_book', 'book', $book_id, [
            'book_title' => $title,
            'old_price' => $old_price,
            'new_price' => $price,
            'course_code' => $course_code,
            'stock_quantity' => $stock_quantity,
            'availability' => $availability,
            'effective_date' => $effective_date,
            'scheduled_change' => $schedule_price_change,
            'auto_assigned_count' => $auto_assigned_count,
            'auto_assigned_total' => $auto_assigned_total,
        ]);
    }
}

if (isset($_POST['cleanup_duplicate_book'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: manage_books.php?msg=csrf_invalid');
        exit;
    }

    $book_id = intval($_POST['book_id'] ?? 0);
    $cleanup_mode = strval($_POST['cleanup_mode'] ?? '');

    if ($book_id <= 0 || !in_array($cleanup_mode, ['hide', 'remove'], true)) {
        header('Location: manage_books.php?msg=unauthorized');
        exit;
    }

    $book_stmt = $conn->prepare("SELECT book_id, book_title, course_code, course_code_key, availability
        FROM books
        WHERE book_id = ?
          AND semester_id = ?
          AND admin_id = ?
        LIMIT 1");
    if (!$book_stmt) {
        header('Location: manage_books.php?msg=unauthorized');
        exit;
    }
    $book_stmt->bind_param('iii', $book_id, $semester_id, $current_admin_id);
    $book_stmt->execute();
    $book_row = $book_stmt->get_result()->fetch_assoc() ?: null;
    $book_stmt->close();

    if (!$book_row) {
        header('Location: manage_books.php?msg=unauthorized');
        exit;
    }

    $duplicate_book_id = book_system_manage_books_find_duplicate(
        $conn,
        $current_admin_id,
        $semester_id,
        book_system_manage_books_normalize_title(strval($book_row['book_title'] ?? '')),
        trim(strval($book_row['course_code_key'] ?? '')),
        $book_id
    );

    if ($duplicate_book_id <= 0) {
        header('Location: manage_books.php');
        exit;
    }

    $usage_summary = book_system_manage_books_book_usage_summary($conn, $book_id);
    $has_linked_records = $usage_summary['request_items'] > 0
        || $usage_summary['books_received'] > 0
        || $usage_summary['lecturer_payments'] > 0;

    if ($cleanup_mode === 'remove') {
        if ($has_linked_records) {
            header('Location: manage_books.php?msg=duplicate_book_action_blocked');
            exit;
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn->begin_transaction();
            $delete_stmt = $conn->prepare("DELETE FROM books
                WHERE book_id = ?
                  AND semester_id = ?
                  AND admin_id = ?
                LIMIT 1");
            if (!$delete_stmt) {
                throw new RuntimeException('Could not prepare duplicate delete.');
            }
            $delete_stmt->bind_param('iii', $book_id, $semester_id, $current_admin_id);
            $delete_stmt->execute();
            $delete_stmt->close();

            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'remove_duplicate_book', 'book', $book_id, [
                    'book_title' => strval($book_row['book_title'] ?? ''),
                    'course_code' => strval($book_row['course_code'] ?? ''),
                    'semester_id' => $semester_id,
                    'admin_id' => $current_admin_id,
                ]);
            }

            $conn->commit();
        } catch (Throwable $error) {
            if ($conn->errno || $conn->connect_errno === 0) {
                $conn->rollback();
            }
            header('Location: manage_books.php?msg=duplicate_book_action_blocked');
            exit;
        }
        if (function_exists('clear_books_cache')) {
            clear_books_cache();
        }
        header('Location: manage_books.php?msg=duplicate_book_removed');
        exit;
    }

    $hide_stmt = $conn->prepare("UPDATE books
        SET availability = 'out_of_stock'
        WHERE book_id = ?
          AND semester_id = ?
          AND admin_id = ?
        LIMIT 1");
    if ($hide_stmt) {
        $hide_stmt->bind_param('iii', $book_id, $semester_id, $current_admin_id);
        $hide_stmt->execute();
        $hide_stmt->close();
    }
    if (function_exists('book_system_audit_log')) {
        book_system_audit_log($conn, 'hide_duplicate_book', 'book', $book_id, [
            'book_title' => strval($book_row['book_title'] ?? ''),
            'course_code' => strval($book_row['course_code'] ?? ''),
            'semester_id' => $semester_id,
            'admin_id' => $current_admin_id,
        ]);
    }
    if (function_exists('clear_books_cache')) {
        clear_books_cache();
    }
    header('Location: manage_books.php?msg=duplicate_book_hidden');
    exit;
}

/* Fetch books */
$books_sql = "SELECT 
    b.*,
    (
        SELECT bph.new_price
        FROM book_price_history bph
        WHERE bph.book_id = b.book_id
          AND bph.applied_at IS NULL
          AND bph.effective_date IS NOT NULL
          AND bph.effective_date > CURDATE()
        ORDER BY bph.effective_date DESC, bph.history_id DESC
        LIMIT 1
    ) AS scheduled_new_price,
    (
        SELECT bph.effective_date
        FROM book_price_history bph
        WHERE bph.book_id = b.book_id
          AND bph.applied_at IS NULL
          AND bph.effective_date IS NOT NULL
          AND bph.effective_date > CURDATE()
        ORDER BY bph.effective_date DESC, bph.history_id DESC
        LIMIT 1
    ) AS scheduled_effective_date
    FROM books b
    WHERE b.semester_id = ?";

if (!$is_super_admin) {
    $books_sql .= " AND (b.admin_id = ? OR b.admin_id IS NULL)";
}

$books_sql .= " ORDER BY b.book_title ASC";

if ($is_super_admin) {
    $books_stmt = $conn->prepare($books_sql);
    $books_rows = [];
    if ($books_stmt) {
        $books_stmt->bind_param('i', $semester_id);
        $books_stmt->execute();
        $books = $books_stmt->get_result();
        if ($books) {
            while ($book_row = $books->fetch_assoc()) {
                $books_rows[] = $book_row;
            }
        }
    }
} else {
    $books_stmt = $conn->prepare($books_sql);
    $books_rows = [];
    if ($books_stmt) {
        $books_stmt->bind_param('ii', $semester_id, $current_admin_id);
        $books_stmt->execute();
        $books = $books_stmt->get_result();
        if ($books) {
            while ($book_row = $books->fetch_assoc()) {
                $books_rows[] = $book_row;
            }
        }
    }
}

$duplicate_books = [];
$duplicate_stmt = $conn->prepare("SELECT
        b.book_id,
        b.book_title,
        b.course_code,
        b.price,
        b.availability,
        (
            SELECT COUNT(*)
            FROM books d
            WHERE d.semester_id = b.semester_id
              AND d.admin_id = b.admin_id
              AND d.course_code_key = b.course_code_key
              AND UPPER(TRIM(d.book_title)) = UPPER(TRIM(b.book_title))
        ) AS duplicate_total,
        (
            SELECT COUNT(*)
            FROM request_items ri
            WHERE ri.book_id = b.book_id
        ) AS request_items_count,
        (
            SELECT COUNT(*)
            FROM books_received br
            WHERE br.book_id = b.book_id
        ) AS books_received_count,
        (
            SELECT COUNT(*)
            FROM lecturer_payments lp
            WHERE lp.book_id = b.book_id
        ) AS lecturer_payments_count
    FROM books b
    WHERE b.semester_id = ?
      AND b.admin_id = ?
      AND EXISTS (
          SELECT 1
          FROM books d
          WHERE d.semester_id = b.semester_id
            AND d.admin_id = b.admin_id
            AND d.book_id <> b.book_id
            AND d.course_code_key = b.course_code_key
            AND UPPER(TRIM(d.book_title)) = UPPER(TRIM(b.book_title))
      )
    ORDER BY UPPER(TRIM(b.book_title)) ASC, b.course_code_key ASC, b.book_id ASC");
if ($duplicate_stmt) {
    $duplicate_stmt->bind_param('ii', $semester_id, $current_admin_id);
    $duplicate_stmt->execute();
    $duplicate_result = $duplicate_stmt->get_result();
    if ($duplicate_result) {
        while ($duplicate_row = $duplicate_result->fetch_assoc()) {
            $duplicate_books[] = $duplicate_row;
        }
    }
    $duplicate_stmt->close();
}

$manage_books_refresh_state = [
    'semester_id' => $semester_id,
    'admin_id' => $current_admin_id,
    'auto_assigned_count' => $auto_assigned_count,
    'auto_assigned_total' => round($auto_assigned_total, 2),
    'page_message' => $page_message,
    'books' => array_map(static function (array $row): array {
        return [
            'book_id' => intval($row['book_id'] ?? 0),
            'title' => strval($row['book_title'] ?? ''),
            'course_code' => strval($row['course_code'] ?? ''),
            'price' => round(floatval($row['price'] ?? 0), 2),
            'stock_quantity' => intval($row['stock_quantity'] ?? 0),
            'availability' => strval($row['availability'] ?? ''),
            'scheduled_new_price' => round(floatval($row['scheduled_new_price'] ?? 0), 2),
            'scheduled_effective_date' => strval($row['scheduled_effective_date'] ?? ''),
        ];
    }, $books_rows),
    'duplicates' => array_map(static function (array $row): array {
        return [
            'book_id' => intval($row['book_id'] ?? 0),
            'duplicate_total' => intval($row['duplicate_total'] ?? 0),
            'availability' => strval($row['availability'] ?? ''),
            'request_items' => intval($row['request_items_count'] ?? 0),
            'books_received' => intval($row['books_received_count'] ?? 0),
            'lecturer_payments' => intval($row['lecturer_payments_count'] ?? 0),
        ];
    }, $duplicate_books),
];
$manage_books_refresh_token = function_exists('book_system_build_refresh_token')
    ? book_system_build_refresh_token($manage_books_refresh_state)
    : sha1(json_encode($manage_books_refresh_state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
if (function_exists('book_system_maybe_output_refresh_status')) {
    book_system_maybe_output_refresh_status($manage_books_refresh_token);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Books</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background:
                radial-gradient(circle at top, rgba(102, 126, 234, 0.12), transparent 24%),
                linear-gradient(180deg, #fbfcff 0%, #f2f5fb 52%, #eef2f7 100%);
            min-height: 100vh;
            padding: 18px 12px 32px;
        }
        
        .page-container {
            width: min(1100px, 100%);
            margin: 0 auto;
        }
        
        /* Header */
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 18px;
            border-radius: 24px;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            box-shadow: 0 18px 40px rgba(102, 126, 234, 0.22);
        }
        .page-header h1 { font-size: 22px; font-weight: 700; }
        .page-header .subtitle { opacity: 0.92; margin-top: 5px; font-size: 13px; line-height: 1.5; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 11px 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
            white-space: nowrap;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        /* Grid Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 315px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 960px) { .content-grid { grid-template-columns: 1fr; } }
        .title-input {
            width: 100%;
            min-width: 0;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 24px;
            padding: 18px;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
            border: 1px solid rgba(226, 232, 240, 0.9);
        }
        .books-card { overflow: hidden; }
        .card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card h2 .icon {
            width: 36px;
            height: 36px;
            background: #e3f2fd;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        /* Form Styling */
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-group input {
            width: 100%;
            padding: 13px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 14px;
            font-size: 15px;
            transition: border-color 0.3s;
            background: #fbfcff;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        .btn-primary {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        .btn-primary:hover { opacity: 0.9; }
        
        /* Table Styling */
        .table-wrap { width: 100%; overflow-x: auto; }
        .books-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            min-width: 0;
        }
        .books-table th {
            background: #f8f9fa;
            padding: 13px 10px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
        }
        .books-table td {
            padding: 12px 10px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .books-table tr:hover { background: #fafbfc; }
        .books-table th:nth-child(1) { width: 24%; }
        .books-table th:nth-child(2) { width: 14%; }
        .books-table th:nth-child(3) { width: 13%; }
        .books-table th:nth-child(4) { width: 18%; }
        .books-table th:nth-child(5) { width: 10%; }
        .books-table th:nth-child(6) { width: 11%; }
        .books-table th:nth-child(7) { width: 10%; }
        
        .book-title {
            font-weight: 600;
            color: #333;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        
        .price-input {
            width: 100%;
            min-width: 0;
            padding: 8px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 13px;
            text-align: right;
        }
        .price-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .status-select {
            width: 100%;
            min-width: 0;
            padding: 8px 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 13px;
            background: white;
            cursor: pointer;
        }
        .status-select:focus {
            outline: none;
            border-color: #667eea;
        }
        .code-input { text-align: left; }
        .date-input { text-align: left; }
        .scheduled-note {
            margin-top: 6px;
            font-size: 12px;
            color: #6c757d;
            font-weight: 600;
            line-height: 1.4;
        }
        
        .btn-update {
            width: 100%;
            min-width: 0;
            padding: 9px 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .btn-update:hover { background: #218838; }
        
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-available { background: #d4edda; color: #155724; }
        .status-out { background: #f8d7da; color: #721c24; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #888;
        }
        
        /* Success Message */
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .auto-assign-msg {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        .auto-assign-msg strong {
            display: block;
            margin-bottom: 6px;
        }
        .duplicate-warning {
            background: #fff7ed;
            border: 1px solid #fdba74;
            color: #9a3412;
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 18px;
            display: grid;
            gap: 10px;
        }
        .duplicate-warning h3 {
            font-size: 16px;
            color: #7c2d12;
        }
        .duplicate-list {
            display: grid;
            gap: 12px;
        }
        .duplicate-item {
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.75);
            border: 1px solid rgba(251, 146, 60, 0.35);
            padding: 14px;
            display: grid;
            gap: 10px;
        }
        .duplicate-item-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }
        .duplicate-item-head strong {
            color: #7c2d12;
            font-size: 15px;
        }
        .duplicate-meta,
        .duplicate-usage {
            font-size: 13px;
            color: #7c2d12;
            line-height: 1.5;
        }
        .duplicate-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .duplicate-actions form {
            margin: 0;
        }
        .duplicate-btn {
            border: none;
            border-radius: 999px;
            padding: 10px 14px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .duplicate-btn.hide {
            background: #fed7aa;
            color: #9a3412;
        }
        .duplicate-btn.remove {
            background: #dcfce7;
            color: #166534;
        }
        .duplicate-note {
            font-size: 12px;
            color: #7c2d12;
        }
        .muted-hint {
            font-size: 13px;
            color: #64748b;
            margin-top: -8px;
            margin-bottom: 18px;
            line-height: 1.5;
        }
        @media (max-width: 640px) {
            body { padding: 20px 14px; }
            .page-header {
                padding: 20px;
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }
            .back-btn {
                width: 100%;
                text-align: center;
            }
            .card { padding: 20px; }
        }
        @media (max-width: 1180px) {
            .books-table th,
            .books-table td {
                padding: 11px 8px;
            }
            .books-table th {
                font-size: 11px;
            }
            .price-input,
            .status-select {
                padding: 7px 8px;
                font-size: 12px;
            }
            .btn-update {
                padding: 8px 8px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>&#128218; Manage Books</h1>
            <p class="subtitle">Add new books and update prices</p>
        </div>
        <a href="<?= htmlspecialchars($dashboard_url) ?>" class="back-btn">&larr; Back to Dashboard</a>
    </div>
    
    <div class="content-grid">
        <!-- Add New Book Form -->
        <div class="card">
            <?php if ($page_message !== ''): ?>
            <div class="success-msg" style="background:#fff7ed; color:#9a3412; border-color:#fdba74;">
                <?php echo htmlspecialchars($page_message); ?>
            </div>
            <?php endif; ?>
            <?php if ($auto_assigned_count > 0): ?>
            <div style="background: #d4edda; color: #155724; padding: 15px; border-radius: 10px; margin-bottom: 20px; border-left: 4px solid #28a745;">
                <strong>&#9989; Auto-Assignment Complete!</strong><br>
                <span style="font-size: 14px;">Automatically assigned book to <strong><?php echo $auto_assigned_count; ?></strong> student(s) with existing balance.</span><br>
                <span style="font-size: 13px; opacity: 0.8;">Total deducted: GH&#8373; <?php echo number_format($auto_assigned_total, 2); ?></span>
            </div>
            <?php endif; ?>
            <h2><span class="icon">&#10133;</span> Add New Book</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label>Book Title</label>
                    <input type="text" name="book_title" id="new_book_title" list="book_title_suggestions" placeholder="Enter book title" required>
                    <datalist id="book_title_suggestions"></datalist>
                    <div id="book_title_hint" style="margin-top:8px; font-size:12px; color:#64748b;">Enter the course code first to see matching titles already used in the system.</div>
                </div>
                <div class="form-group">
                    <label>Course Code *</label>
                    <input type="text" name="course_code" id="new_course_code" placeholder="e.g. EDC 211" required>
                </div>
                <div class="form-group">
                    <label>Price (GH&#8373;)</label>
                    <input type="number" step="0.01" name="price" placeholder="0.00" required>
                </div>
                <div class="form-group">
                    <label>Stock Quantity <span style="font-weight:normal;color:#888;">(optional)</span></label>
                    <input type="number" name="stock_quantity" min="0" placeholder="0">
                </div>
                <button type="submit" name="add_book" class="btn-primary">Add Book</button>
            </form>
        </div>
        
        <!-- Books List -->
        <div class="card">
            <h2><span class="icon">&#128214;</span> All Books</h2>
            <?php if (!empty($duplicate_books)): ?>
            <div class="duplicate-warning">
                <h3>Duplicate books detected</h3>
                <p class="duplicate-note">These books already appear more than once in your active semester workspace. If a duplicate already has requests or lecturer records, hide it from students instead of deleting it.</p>
                <div class="duplicate-list">
                    <?php foreach ($duplicate_books as $duplicate_row): ?>
                        <?php
                            $duplicate_request_items = intval($duplicate_row['request_items_count'] ?? 0);
                            $duplicate_books_received = intval($duplicate_row['books_received_count'] ?? 0);
                            $duplicate_lecturer_payments = intval($duplicate_row['lecturer_payments_count'] ?? 0);
                            $has_duplicate_history = $duplicate_request_items > 0 || $duplicate_books_received > 0 || $duplicate_lecturer_payments > 0;
                        ?>
                        <div class="duplicate-item">
                            <div class="duplicate-item-head">
                                <div>
                                    <strong><?php echo htmlspecialchars(strval($duplicate_row['book_title'] ?? 'Book')); ?></strong>
                                    <div class="duplicate-meta">
                                        <?php echo htmlspecialchars(strval($duplicate_row['course_code'] ?? '')); ?>
                                        • GH&#8373; <?php echo number_format(floatval($duplicate_row['price'] ?? 0), 2); ?>
                                        • <?php echo !empty($duplicate_row['availability']) && $duplicate_row['availability'] === 'out_of_stock' ? 'Hidden from students' : 'Visible to students'; ?>
                                    </div>
                                </div>
                                <span class="status-badge status-out"><?php echo intval($duplicate_row['duplicate_total'] ?? 2); ?> copies</span>
                            </div>
                            <div class="duplicate-usage">
                                Requests: <strong><?php echo $duplicate_request_items; ?></strong>
                                • Books received: <strong><?php echo $duplicate_books_received; ?></strong>
                                • Lecturer payments: <strong><?php echo $duplicate_lecturer_payments; ?></strong>
                            </div>
                            <div class="duplicate-actions">
                                <?php if ($has_duplicate_history): ?>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="book_id" value="<?php echo intval($duplicate_row['book_id'] ?? 0); ?>">
                                    <input type="hidden" name="cleanup_mode" value="hide">
                                    <button type="submit" name="cleanup_duplicate_book" value="1" class="duplicate-btn hide">Hide From Students</button>
                                </form>
                                <div class="duplicate-note">This duplicate already has linked records, so it should stay archived but hidden.</div>
                                <?php else: ?>
                                <form method="post" onsubmit="return confirm('Remove this unused duplicate book?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="book_id" value="<?php echo intval($duplicate_row['book_id'] ?? 0); ?>">
                                    <input type="hidden" name="cleanup_mode" value="remove">
                                    <button type="submit" name="cleanup_duplicate_book" value="1" class="duplicate-btn remove">Remove Unused Duplicate</button>
                                </form>
                                <div class="duplicate-note">No requests or lecturer records are linked to this row, so it can be removed safely.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($books_rows)): ?>
                <div class="table-wrap">
                <table class="books-table">
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Course Code</th>
                            <th>Price (GH&#8373;)</th>
                            <th>Effective Date</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($books_rows as $row): ?>
                        <?php $form_id = 'book-update-' . intval($row['book_id']); ?>
                        <tr>
                                <td class="book-title">
                                    <input type="text" name="book_title" form="<?php echo $form_id; ?>" class="price-input title-input" value="<?php echo htmlspecialchars($row['book_title']); ?>" required>
                                </td>
                                <td>
                                    <input type="text" name="course_code" form="<?php echo $form_id; ?>" class="price-input code-input" value="<?php echo htmlspecialchars($row['course_code'] ?? ''); ?>" required>
                                </td>
                                <td>
                                    <input type="number" step="0.01" name="price" 
                                           form="<?php echo $form_id; ?>"
                                           class="price-input" value="<?php echo $row['price']; ?>">
                                    <?php if (!empty($row['scheduled_new_price']) && !empty($row['scheduled_effective_date'])): ?>
                                        <div class="scheduled-note">
                                            Scheduled: GH&#8373; <?php echo number_format(floatval($row['scheduled_new_price']), 2); ?> on <?php echo htmlspecialchars($row['scheduled_effective_date']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="date" name="effective_date" form="<?php echo $form_id; ?>" class="price-input date-input" value="<?php echo htmlspecialchars($row['scheduled_effective_date'] ?: date('Y-m-d')); ?>">
                                </td>
                                <td>
                                    <input type="number" name="stock_quantity" min="0" 
                                           form="<?php echo $form_id; ?>"
                                           class="price-input stock-input" value="<?php echo intval($row['stock_quantity'] ?? 0); ?>">
                                </td>
                                <td>
                                    <select name="availability" form="<?php echo $form_id; ?>" class="status-select">
                                        <option value="available" <?php if ($row['availability'] === 'available') echo 'selected'; ?>>
                                            Available
                                        </option>
                                        <option value="out_of_stock" <?php if ($row['availability'] === 'out_of_stock') echo 'selected'; ?>>
                                            Out of Stock
                                        </option>
                                    </select>
                                </td>
                                <td>
                                    <form id="<?php echo $form_id; ?>" method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="book_id" value="<?php echo intval($row['book_id']); ?>">
                                    </form>
                                    <button type="submit" name="update_book" value="1" form="<?php echo $form_id; ?>" class="btn-update">Update</button>
                                </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>No books added yet. Add your first book using the form.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

<script>
(function () {
    const courseCodeInput = document.getElementById('new_course_code');
    const bookTitleInput = document.getElementById('new_book_title');
    const suggestions = document.getElementById('book_title_suggestions');
    const hint = document.getElementById('book_title_hint');

    if (!courseCodeInput || !bookTitleInput || !suggestions || !hint) {
        return;
    }

    let requestToken = 0;

    function setHint(text) {
        hint.textContent = text;
    }

    function renderSuggestions(titles) {
        suggestions.innerHTML = titles.map((title) => `<option value="${String(title).replace(/"/g, '&quot;')}"></option>`).join('');
        if (!titles.length) {
            setHint('No matching title suggestion yet. You can enter your own book name.');
        } else if (titles.length === 1) {
            setHint(`Suggestion found: ${titles[0]}`);
        } else {
            setHint(`${titles.length} title suggestions found for this course code.`);
        }
    }

    async function fetchSuggestions() {
        const value = courseCodeInput.value.trim();
        if (!value) {
            suggestions.innerHTML = '';
            setHint('Enter the course code first to see matching titles already used in the system.');
            return;
        }

        const currentToken = ++requestToken;
        try {
            const response = await fetch(`manage_books.php?suggest_course_code=${encodeURIComponent(value)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error('Suggestion request failed');
            }
            const payload = await response.json();
            if (currentToken !== requestToken) {
                return;
            }
            const titles = Array.isArray(payload.titles) ? payload.titles.filter(Boolean) : [];
            renderSuggestions(titles);
            if (titles.length === 1 && !bookTitleInput.value.trim()) {
                bookTitleInput.value = titles[0];
            }
        } catch (error) {
            if (currentToken !== requestToken) {
                return;
            }
            suggestions.innerHTML = '';
            setHint('Could not load suggestions right now. You can still enter the book name yourself.');
        }
    }

    courseCodeInput.addEventListener('input', fetchSuggestions);
})();
</script>

<?php
if (function_exists('book_system_render_refresh_polling_script')) {
    book_system_render_refresh_polling_script($manage_books_refresh_token, [
        'interval_ms' => 15000,
        'min_gap_ms' => 10000,
        'pause_selectors' => [
            '.card form input:focus',
            '.card form select:focus',
            '.card form textarea:focus',
        ],
    ]);
}
?>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>

</body>
</html>

