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
    }
}

function manual_order_set_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['manual_order_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function manual_order_pop_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $flash = $_SESSION['manual_order_flash'] ?? null;
    unset($_SESSION['manual_order_flash']);

    return is_array($flash) ? $flash : null;
}

function manual_order_reset_idempotency_scope(string $scope, bool $clearRecent = false): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    unset($_SESSION['submission_tokens'][$scope]);
    if ($clearRecent) {
        unset($_SESSION['recent_submissions'][$scope]);
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
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
$admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$rep_bottom_nav_active = 'add-payment';

$csrf_token = csrf_get_token();
$submission_scope = 'manual_request';
$flash_notice = manual_order_pop_flash();
$submission_token = '';

if (!$conn) {
    die("Database connection failed. Please check your database settings.");
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}

if ($is_super_admin) {
    $books_stmt = $conn->prepare("SELECT * FROM books WHERE availability = 'available' AND semester_id = ? ORDER BY book_title ASC");
    $books_res = false;
    if ($books_stmt) {
        $books_stmt->bind_param('i', $semester_id);
        $books_stmt->execute();
        $books_res = $books_stmt->get_result();
    }
} else {
    $books_stmt = $conn->prepare("SELECT * FROM books WHERE availability = 'available' AND semester_id = ? AND (admin_id = ? OR admin_id IS NULL) ORDER BY book_title ASC");
    $books_res = false;
    if ($books_stmt) {
        $books_stmt->bind_param('ii', $semester_id, $admin_id);
        $books_stmt->execute();
        $books_res = $books_stmt->get_result();
    }
}

$class_list_count = 0;
$class_list_stmt = $conn->prepare("SELECT COUNT(*) AS total_students FROM class_students WHERE admin_id = ? AND semester_id = ?");
if ($class_list_stmt) {
    $class_list_stmt->bind_param('ii', $admin_id, $semester_id);
    $class_list_stmt->execute();
    $class_list_res = $class_list_stmt->get_result();
    if ($class_list_res && $class_list_res->num_rows === 1) {
        $class_list_count = intval($class_list_res->fetch_assoc()['total_students'] ?? 0);
    }
    $class_list_stmt->close();
}
$has_uploaded_class_list = ($class_list_count > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submission_token = trim(strval($_POST['submission_token'] ?? ''));
    $submission_token_valid = function_exists('book_system_consume_submission_token')
        ? book_system_consume_submission_token($submission_scope, $submission_token)
        : true;
    $transaction_started = false;
    $shared_submission_lock_acquired = false;

    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $full_name = trim(strval($_POST['full_name'] ?? ''));
        $index_number = trim(strval($_POST['index_number'] ?? ''));
        $phone = trim(strval($_POST['phone'] ?? ''));
        $selected_student_index = trim(strval($_POST['selected_student_index'] ?? ''));
        $selected_books = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['books'] ?? null) ? $_POST['books'] : []), static function ($book_id) {
            return $book_id > 0;
        })));
        $cash_received = max(0, round(floatval($_POST['cash_received'] ?? 0), 2));

        if (!$has_uploaded_class_list) {
            $error = 'Your class list has not been uploaded yet. Please upload your class list before using manual request.';
        } elseif ($index_number === '' || $full_name === '') {
            $error = 'Index number and student name are required.';
        } elseif (empty($selected_books)) {
            $error = 'Please select at least one book.';
        } else {
            $roster_name = '';
            $roster_index = '';
            $roster_lookup_index = ($selected_student_index !== '' ? $selected_student_index : $index_number);
            $normalized_roster_lookup = function_exists('book_system_normalize_index_number')
                ? book_system_normalize_index_number($roster_lookup_index)
                : strtoupper(trim($roster_lookup_index));
            $normalized_index_sql = function_exists('book_system_normalized_index_sql')
                ? book_system_normalized_index_sql('index_number')
                : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";
            $roster_stmt = $conn->prepare("SELECT index_number, student_name
                FROM class_students
                WHERE admin_id = ? AND semester_id = ? AND $normalized_index_sql = ?
                LIMIT 1");
            if ($roster_stmt) {
                $roster_stmt->bind_param('iis', $admin_id, $semester_id, $normalized_roster_lookup);
                $roster_stmt->execute();
                $roster_result = $roster_stmt->get_result();
                if ($roster_result && $roster_result->num_rows === 1) {
                    $roster_row = $roster_result->fetch_assoc();
                    $roster_index = trim(strval($roster_row['index_number'] ?? ''));
                    $roster_name = trim(strval($roster_row['student_name'] ?? ''));
                }
                $roster_stmt->close();
            }

            if ($roster_index === '' || $roster_name === '') {
                $error = 'The selected student could not be verified in your uploaded class list.';
            } else {
                $index_number = $roster_index;
                $full_name = $roster_name;
            }
        }

        if (!isset($error)) {
            $request_fingerprint = hash('sha256', implode('|', [
                'manual_request',
                strval($admin_id),
                strval($semester_id),
                $index_number,
                implode(',', $selected_books),
                number_format($cash_received, 2, '.', ''),
            ]));

            $shared_recent_result = function_exists('book_system_get_shared_submission_result')
                ? book_system_get_shared_submission_result($submission_scope, $request_fingerprint)
                : null;
            if (is_array($shared_recent_result) && !empty($shared_recent_result['redirect'])) {
                header('Location: ' . strval($shared_recent_result['redirect']));
                exit;
            }

            if (function_exists('book_system_acquire_submission_processing_lock')) {
                $shared_submission_lock_acquired = book_system_acquire_submission_processing_lock($submission_scope, $request_fingerprint);
                if (!$shared_submission_lock_acquired) {
                    $shared_pending_result = function_exists('book_system_get_shared_submission_result')
                        ? book_system_get_shared_submission_result($submission_scope, $request_fingerprint)
                        : null;
                    if (is_array($shared_pending_result) && !empty($shared_pending_result['redirect'])) {
                        header('Location: ' . strval($shared_pending_result['redirect']));
                        exit;
                    }
                    $error = 'That same manual request is already being processed in another session. Please wait a moment.';
                }
            }

            if (!isset($error) && !$submission_token_valid) {
                $recent_result = function_exists('book_system_get_recent_submission_result')
                    ? book_system_get_recent_submission_result($submission_scope, $request_fingerprint)
                    : null;
                if (is_array($recent_result) && !empty($recent_result['redirect'])) {
                    header('Location: ' . strval($recent_result['redirect']));
                    exit;
                }
                manual_order_set_flash(
                    'info',
                    $submission_token === ''
                        ? 'The manual request form was refreshed for safety. Please review and submit again.'
                        : 'The page was refreshed with a new submission token. Please submit again if you still want to continue.'
                );
                header('Location: admin_manual_order.php');
                exit;
            }
        }

        if (!isset($error)) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            try {
                $conn->begin_transaction();
                $transaction_started = true;

                $student_id = 0;
                $existing_credit = 0.0;
                $student_stmt = $conn->prepare("SELECT student_id, credit_balance, admin_id FROM students WHERE index_number = ? LIMIT 1 FOR UPDATE");
                $student_stmt->bind_param('s', $index_number);
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();

                if ($student_result && $student_result->num_rows === 1) {
                    $student_row = $student_result->fetch_assoc();
                    $student_id = intval($student_row['student_id'] ?? 0);
                    $existing_credit = round(floatval($student_row['credit_balance'] ?? 0), 2);
                    $student_admin_id = intval($student_row['admin_id'] ?? 0);

                    if (!$is_super_admin && $student_admin_id <= 0) {
                        $claim = $conn->prepare("UPDATE students SET admin_id = ? WHERE student_id = ? AND (admin_id IS NULL OR admin_id = 0)");
                        $claim->bind_param('ii', $admin_id, $student_id);
                        $claim->execute();
                        $claim->close();
                    }

                    $student_update = $conn->prepare("UPDATE students SET full_name = ?, phone = ? WHERE student_id = ?");
                    if ($student_update) {
                        $student_update->bind_param('ssi', $full_name, $phone, $student_id);
                        $student_update->execute();
                        $student_update->close();
                    }
                } else {
                    $insert_student = $conn->prepare("INSERT INTO students (index_number, full_name, phone, credit_balance, admin_id) VALUES (?, ?, ?, 0, ?)");
                    $insert_student->bind_param('sssi', $index_number, $full_name, $phone, $admin_id);
                    $insert_student->execute();
                    $student_id = intval($conn->insert_id);
                    $insert_student->close();
                }
                $student_stmt->close();

                $duplicate_titles = [];
                foreach ($selected_books as $book_id) {
                    $student_index_expr = function_exists('book_system_normalized_index_sql')
                        ? book_system_normalized_index_sql('s.index_number')
                        : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(s.index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";
                    if ($is_super_admin) {
                        $dup_stmt = $conn->prepare("SELECT b.book_title FROM request_items ri JOIN requests r ON ri.request_id = r.request_id JOIN students s ON r.student_id = s.student_id JOIN books b ON ri.book_id = b.book_id WHERE r.semester_id = ? AND $student_index_expr = ? AND ri.book_id = ? AND COALESCE(ri.is_cancelled, 0) = 0 LIMIT 1");
                        $dup_stmt->bind_param('isi', $semester_id, $normalized_roster_lookup, $book_id);
                    } else {
                        $dup_stmt = $conn->prepare("SELECT b.book_title FROM request_items ri JOIN requests r ON ri.request_id = r.request_id JOIN students s ON r.student_id = s.student_id JOIN books b ON ri.book_id = b.book_id WHERE r.semester_id = ? AND r.admin_id = ? AND $student_index_expr = ? AND ri.book_id = ? AND COALESCE(ri.is_cancelled, 0) = 0 LIMIT 1");
                        $dup_stmt->bind_param('iisi', $semester_id, $admin_id, $normalized_roster_lookup, $book_id);
                    }

                    if ($dup_stmt) {
                        $dup_stmt->execute();
                        $dup_res = $dup_stmt->get_result();
                        if ($dup_res && $dup_res->num_rows > 0) {
                            $row = $dup_res->fetch_assoc();
                            $duplicate_titles[] = strval($row['book_title'] ?? '');
                        }
                        $dup_stmt->close();
                    }
                }

                if (!empty($duplicate_titles)) {
                    throw new RuntimeException('Duplicate Request: This student has already requested: ' . implode(', ', $duplicate_titles));
                }

                $total_amount = 0.0;
                $book_prices = [];
                foreach ($selected_books as $book_id) {
                    $price_stmt = $conn->prepare("SELECT price FROM books WHERE book_id = ? AND semester_id = ? LIMIT 1");
                    $unit_price = null;
                    if ($price_stmt) {
                        $price_stmt->bind_param('ii', $book_id, $semester_id);
                        $price_stmt->execute();
                        $price_result = $price_stmt->get_result();
                        if ($price_result && $price_result->num_rows === 1) {
                            $unit_price = round(floatval($price_result->fetch_assoc()['price'] ?? 0), 2);
                        }
                        $price_stmt->close();
                    }

                    if ($unit_price === null) {
                        continue;
                    }

                    $book_prices[$book_id] = $unit_price;
                    $total_amount += $unit_price;
                }

                if (empty($book_prices)) {
                    throw new RuntimeException('No valid books were selected for this request.');
                }

                $total_payment = $existing_credit + $cash_received;
                if ($total_payment >= $total_amount) {
                    $credit_used = max(0, round($total_amount - $cash_received, 2));
                    if ($credit_used > $existing_credit) {
                        $credit_used = $existing_credit;
                    }
                    $amount_paid = $cash_received;
                    $new_credit = round($total_payment - $total_amount, 2);
                    $payment_status = 'paid';
                } else {
                    $credit_used = $existing_credit;
                    $amount_paid = $cash_received;
                    $new_credit = 0.0;
                    $payment_status = 'unpaid';
                }

                $credit_stmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ?");
                if ($credit_stmt) {
                    $credit_stmt->bind_param('di', $new_credit, $student_id);
                    $credit_stmt->execute();
                    $credit_stmt->close();
                }

                $request_stmt = $conn->prepare("INSERT INTO requests (student_id, total_amount, amount_paid, credit_used, payment_status, created_at, semester_id, admin_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
                $request_stmt->bind_param('idddsii', $student_id, $total_amount, $amount_paid, $credit_used, $payment_status, $semester_id, $admin_id);
                $request_stmt->execute();
                $request_id = intval($conn->insert_id);
                $request_stmt->close();

                $item_stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id, unit_price) VALUES (?, ?, ?)");
                foreach ($selected_books as $book_id) {
                    if (!isset($book_prices[$book_id])) {
                        continue;
                    }
                    $unit_price = floatval($book_prices[$book_id]);
                    $item_stmt->bind_param('iid', $request_id, $book_id, $unit_price);
                    $item_stmt->execute();
                }
                if ($item_stmt) {
                    $item_stmt->close();
                }

                $redirect_url = 'view_request.php?msg=manual_success&search=' . urlencode($index_number);
                if ($new_credit > 0) {
                    $redirect_url .= '&credit=' . urlencode(number_format($new_credit, 2, '.', ''));
                }
                if (function_exists('book_system_remember_submission_result')) {
                    book_system_remember_submission_result($submission_scope, $request_fingerprint, [
                        'request_id' => $request_id,
                        'redirect' => $redirect_url,
                    ]);
                }
                if (function_exists('book_system_remember_shared_submission_result')) {
                    book_system_remember_shared_submission_result($submission_scope, $request_fingerprint, [
                        'request_id' => $request_id,
                        'redirect' => $redirect_url,
                    ]);
                }

                $conn->commit();
                if ($shared_submission_lock_acquired && function_exists('book_system_release_submission_processing_lock')) {
                    book_system_release_submission_processing_lock($submission_scope, $request_fingerprint);
                    $shared_submission_lock_acquired = false;
                }
                header('Location: ' . $redirect_url);
                exit;
            } catch (Throwable $e) {
                if ($transaction_started) {
                    $conn->rollback();
                }
                error_log('[ClassBookHub][admin_manual_order] ' . json_encode([
                    'message' => $e->getMessage(),
                    'admin_id' => $admin_id,
                    'index_number' => $index_number,
                    'selected_books' => $selected_books,
                    'cash_received' => $cash_received,
                    'submission_token_present' => ($submission_token !== ''),
                    'submission_token_valid' => $submission_token_valid,
                    'request_fingerprint' => $request_fingerprint ?? '',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $error = book_system_security_debug_enabled()
                    ? $e->getMessage()
                    : 'Unable to complete the manual request right now. Please try again.';
            } finally {
                if ($shared_submission_lock_acquired && function_exists('book_system_release_submission_processing_lock')) {
                    book_system_release_submission_processing_lock($submission_scope, $request_fingerprint ?? '');
                }
                mysqli_report(MYSQLI_REPORT_OFF);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    manual_order_reset_idempotency_scope($submission_scope, true);
}

$submission_token = function_exists('book_system_issue_submission_token')
    ? book_system_issue_submission_token($submission_scope)
    : '';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Order</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 550px; margin: 0 auto; }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .page-header h1 { font-size: 22px; font-weight: 600; }
        .page-header .subtitle { opacity: 0.9; margin-top: 3px; font-size: 13px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
            font-size: 14px;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .error-box {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #f44336;
        }

        .info-box {
            background: #eff6ff;
            color: #1d4ed8;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #60a5fa;
        }
        
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
        }
        .form-input:focus { outline: none; border-color: #667eea; }
        .form-input.valid { border-color: #28a745; background: #f8fff8; }
        .form-input.searching { border-color: #f59e0b; background: #fffaf0; }
        .form-input.invalid { border-color: #ef4444; background: #fff5f5; }
        .form-input.readonly { background: #f8f9fa; color: #333; }

        .lookup-hint {
            margin-top: 8px;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
        }

        .lookup-state {
            display: none;
            margin-bottom: 20px;
            padding: 16px 18px;
            border-radius: 14px;
            font-size: 14px;
            line-height: 1.6;
            border: 1px solid transparent;
        }
        .lookup-state.show { display: block; }
        .lookup-state.info { background: #eff6ff; color: #1d4ed8; border-color: rgba(59,130,246,0.18); }
        .lookup-state.warning { background: #fff7ed; color: #9a3412; border-color: rgba(249,115,22,0.18); }
        .lookup-state.error { background: #fef2f2; color: #b91c1c; border-color: rgba(239,68,68,0.18); }

        .student-found-card {
            display: none;
            margin-bottom: 20px;
            padding: 20px;
            border-radius: 16px;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfeff 100%);
            border: 1px solid rgba(34,197,94,0.18);
            box-shadow: 0 12px 28px rgba(15,23,42,0.08);
        }
        .student-found-card.show { display: block; }
        .student-found-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(34,197,94,0.12);
            color: #15803d;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .student-found-name {
            margin-top: 14px;
            font-size: 22px;
            font-weight: 700;
            color: #0f172a;
        }
        .student-found-meta {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }
        .student-found-meta div {
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(255,255,255,0.78);
            color: #334155;
            font-size: 14px;
        }

        .duplicate-card {
            display: none;
            margin-bottom: 20px;
            padding: 18px;
            border-radius: 16px;
            background: #fffaf0;
            border: 1px solid rgba(245,158,11,0.18);
        }
        .duplicate-card.show { display: block; }
        .duplicate-title {
            font-size: 15px;
            font-weight: 700;
            color: #9a3412;
            margin-bottom: 12px;
        }
        .duplicate-options {
            display: grid;
            gap: 10px;
        }
        .duplicate-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid #fde68a;
            cursor: pointer;
        }
        .duplicate-option strong {
            display: block;
            color: #0f172a;
            font-size: 14px;
        }
        .duplicate-option span {
            display: block;
            color: #64748b;
            font-size: 13px;
            margin-top: 2px;
        }

        .class-list-warning {
            margin-bottom: 22px;
            padding: 18px 20px;
            border-radius: 14px;
            background: #fff7ed;
            border: 1px solid rgba(249,115,22,0.18);
            color: #9a3412;
            line-height: 1.6;
        }
        .class-list-warning a {
            display: inline-flex;
            margin-top: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            background: #ea580c;
            color: #fff;
            text-decoration: none;
            font-weight: 700;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .books-list {
            max-height: 220px;
            overflow-y: auto;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .book-item {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        .book-item:hover { background: #f8f9fa; }
        .book-item:last-child { border-bottom: none; }
        .book-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }
        .book-item .title { flex: 1; font-weight: 500; color: #333; }
        .book-item .price { color: #667eea; font-weight: 700; }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .summary-row:last-child { margin-bottom: 0; }
        .summary-label { font-size: 14px; opacity: 0.9; }
        .summary-value { font-size: 28px; font-weight: 700; }
        
        .cash-input-group { margin-top: 15px; }
        .cash-input-group label { color: rgba(255,255,255,0.9); margin-bottom: 8px; display: block; font-weight: 600; }
        .cash-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 10px;
            font-size: 20px;
            font-weight: 700;
            background: rgba(255,255,255,0.15);
            color: white;
            text-align: center;
        }
        .cash-input::placeholder { color: rgba(255,255,255,0.5); }
        .cash-input:focus { outline: none; border-color: white; background: rgba(255,255,255,0.25); }
        
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-submit:hover { background: #218838; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(40,167,69,0.3); }
        .btn-submit:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        @media (max-width: 640px) {
            body { padding: 20px 14px 110px; }
            .card { padding: 22px 18px; }
            .page-header {
                padding: 20px;
                border-radius: 18px;
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }
            .page-header h1 { font-size: 20px; }
            .student-found-name { font-size: 20px; }
            .summary-value { font-size: 24px; }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>&#10133; Manual Order</h1>
            <p class="subtitle">Record cash payment & issue books</p>
        </div>
        <a href="<?= htmlspecialchars($dashboard_url) ?>" class="back-btn">&larr; Back</a>
    </div>
    
    <div class="card">
        <?php if ($flash_notice && !empty($flash_notice['message']) && strval($flash_notice['type'] ?? '') === 'info'): ?>
            <div class="info-box"><?php echo htmlspecialchars(strval($flash_notice['message'] ?? '')); ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error-box"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!$has_uploaded_class_list): ?>
            <div class="class-list-warning">
                <strong>Your class list has not been uploaded yet.</strong><br>
                Please upload your class list before using manual request.
                <a href="upload_class.php">Upload Class List</a>
            </div>
        <?php endif; ?>
        
        <form method="post" id="orderForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($submission_token); ?>">
            <input type="hidden" name="selected_student_index" id="selected_student_index" value="">
            <div class="form-group">
                <label>Last 3 Digits of Index Number</label>
                <input type="text" id="index_number" name="index_number" class="form-input" placeholder="Enter last 3 digits, e.g. 552" inputmode="numeric" autocomplete="off" required>
                <div class="lookup-hint">Type at least the last 3 digits. We will search only your uploaded class list automatically.</div>
            </div>

            <div id="lookup_state" class="lookup-state info"></div>

            <div id="student_found_card" class="student-found-card">
                <span class="student-found-badge">Student Found</span>
                <div class="student-found-name" id="student_found_name">Student Name</div>
                <div class="student-found-meta">
                    <div><strong>Index Number:</strong> <span id="student_found_index"></span></div>
                    <div><strong>Class:</strong> <?php echo htmlspecialchars(strval($_SESSION['class_name'] ?? ($access_context['effective_class_name'] ?? 'Your class list'))); ?></div>
                </div>
            </div>

            <div id="duplicate_card" class="duplicate-card">
                <div class="duplicate-title">Multiple matches found. Please select the correct student.</div>
                <div id="duplicate_options" class="duplicate-options"></div>
            </div>
            
            <div class="form-group">
                <label>Student Name</label>
                <input type="text" id="full_name" name="full_name" class="form-input readonly" placeholder="Auto-filled from index" readonly>
            </div>
            
            <div class="form-group">
                <label>Phone (Optional)</label>
                <input type="text" id="phone" name="phone" class="form-input" placeholder="Enter phone number">
            </div>
            
            <div class="section-title">Select Books</div>
            <div class="books-list">
                <?php if ($books_res && $books_res->num_rows > 0): ?>
                    <?php while($b = $books_res->fetch_assoc()): ?>
                        <label class="book-item">
                            <input type="checkbox" name="books[]" class="book-checkbox" 
                                   data-price="<?php echo $b['price']; ?>" 
                                   value="<?php echo $b['book_id']; ?>">
                            <span class="title"><?php echo htmlspecialchars($b['book_title']); ?></span>
                            <span class="price">GH&#8373; <?php echo number_format($b['price'], 2); ?></span>
                        </label>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 20px; text-align: center; color: #888;">No books available</div>
                <?php endif; ?>
            </div>
            
            <div id="credit_info" style="display: none; background: #d4edda; border: 2px solid #28a745; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <strong style="color: #155724;"> Student has existing balance!</strong>
                <div style="font-size: 24px; color: #28a745; font-weight: bold; margin-top: 5px;">
                    GH&#8373; <span id="existing_credit">0.00</span>
                </div>
                <small style="color: #155724;">This will be automatically applied to the order.</small>
            </div>
            
            <div class="summary-card">
                <div class="summary-row">
                    <span class="summary-label">Total Amount</span>
                    <span class="summary-value">GH&#8373; <span id="display_total">0.00</span></span>
                </div>
                <div id="balance_applied_row" class="summary-row" style="display: none; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 10px;">
                    <span class="summary-label">Balance Applied</span>
                    <span style="font-size: 18px; font-weight: 600;">- GH&#8373; <span id="balance_applied">0.00</span></span>
                </div>
                <div id="amount_due_row" class="summary-row" style="display: none; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 10px;">
                    <span class="summary-label">Amount Due</span>
                    <span style="font-size: 22px; font-weight: 700;">GH&#8373; <span id="amount_due">0.00</span></span>
                </div>
                <div class="cash-input-group">
                    <label>Cash Received</label>
                    <input type="number" step="0.01" name="cash_received" id="cash_received" class="cash-input" placeholder="0.00" required>
                </div>
            </div>
            
            <button type="submit" class="btn-submit" id="submit_button" <?php echo $has_uploaded_class_list ? 'disabled' : 'disabled'; ?>> Complete Transaction</button>
        </form>
    </div>
</div>

<script>
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});

const indexInput = document.getElementById('index_number');
const nameInput = document.getElementById('full_name');
const phoneInput = document.getElementById('phone');
const selectedStudentIndexInput = document.getElementById('selected_student_index');
const lookupState = document.getElementById('lookup_state');
const studentFoundCard = document.getElementById('student_found_card');
const studentFoundName = document.getElementById('student_found_name');
const studentFoundIndex = document.getElementById('student_found_index');
const duplicateCard = document.getElementById('duplicate_card');
const duplicateOptions = document.getElementById('duplicate_options');
const submitButton = document.getElementById('submit_button');
const orderForm = document.getElementById('orderForm');
const hasUploadedClassList = <?php echo $has_uploaded_class_list ? 'true' : 'false'; ?>;
const uploadClassHref = 'upload_class.php';
let lookupTimeout = null;
let activeLookupKey = '';
let studentCredit = 0;
const creditInfo = document.getElementById('credit_info');
const existingCreditEl = document.getElementById('existing_credit');
const balanceAppliedRow = document.getElementById('balance_applied_row');
const balanceAppliedEl = document.getElementById('balance_applied');
const amountDueRow = document.getElementById('amount_due_row');
const amountDueEl = document.getElementById('amount_due');

function setLookupState(type, message, allowHtml = false) {
    if (!lookupState) return;
    lookupState.className = 'lookup-state show ' + type;
    if (allowHtml) {
        lookupState.innerHTML = message;
    } else {
        lookupState.textContent = message;
    }
}

function clearLookupState() {
    if (!lookupState) return;
    lookupState.className = 'lookup-state info';
    lookupState.textContent = '';
}

function resetStudentSelection(message) {
    selectedStudentIndexInput.value = '';
    nameInput.value = '';
    phoneInput.value = '';
    nameInput.placeholder = 'Auto-filled from index';
    nameInput.classList.remove('valid', 'invalid');
    studentFoundCard.classList.remove('show');
    duplicateCard.classList.remove('show');
    duplicateOptions.innerHTML = '';
    submitButton.disabled = true;
    updateCreditDisplay(0);
    checkOwnedBooks('');
    if (message) {
        setLookupState('warning', message);
    } else {
        clearLookupState();
    }
}

function applyFoundStudent(studentName, fullIndex, phone, creditBalance) {
    selectedStudentIndexInput.value = fullIndex;
    indexInput.value = fullIndex;
    nameInput.value = studentName;
    nameInput.classList.remove('invalid', 'searching');
    nameInput.classList.add('valid');
    if (phoneInput && phone) {
        phoneInput.value = phone;
    }
    studentFoundName.textContent = studentName;
    studentFoundIndex.textContent = fullIndex;
    studentFoundCard.classList.add('show');
    duplicateCard.classList.remove('show');
    duplicateOptions.innerHTML = '';
    submitButton.disabled = false;
    setLookupState('info', 'Student found. You can continue with the request.');
    updateCreditDisplay(Number(creditBalance || 0));
    checkOwnedBooks(fullIndex);
}

function renderDuplicateMatches(matches) {
    duplicateOptions.innerHTML = '';
    matches.forEach(function (match) {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'duplicate-option';
        option.innerHTML =
            '<input type="radio" aria-hidden="true" tabindex="-1">' +
            '<div><strong>' + escapeHtml(match.student_name || '') + '</strong><span>' + escapeHtml(match.full_index || '') + '</span></div>';
        option.addEventListener('click', function () {
            setLookupState('info', 'Loading the selected student...');
            fetchStudentCredit(match.full_index, function (creditPayload) {
                applyFoundStudent(
                    String(match.student_name || ''),
                    String(match.full_index || ''),
                    creditPayload.found ? String(creditPayload.phone || '') : '',
                    creditPayload.found ? Number(creditPayload.credit_balance || 0) : 0
                );
            });
        });
        duplicateOptions.appendChild(option);
    });
    duplicateCard.classList.add('show');
    studentFoundCard.classList.remove('show');
    submitButton.disabled = true;
}

function updateCreditDisplay(credit) {
    studentCredit = credit;
    if (credit > 0) {
        existingCreditEl.textContent = credit.toFixed(2);
        creditInfo.style.display = 'block';
    } else {
        creditInfo.style.display = 'none';
    }
    calculateTotal();
}

function fetchStudentCredit(indexNumber, callback) {
    const creditUrl = new URL('get_student_credit.php', window.location.href);
    creditUrl.searchParams.set('index', indexNumber);

    fetch(creditUrl.toString(), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
    })
        .then(response => response.text())
        .then(text => {
            const cleanText = String(text || '').replace(/^\uFEFF/, '').trim();
            const data = cleanText !== '' ? JSON.parse(cleanText) : null;
            callback(data || { found: false, credit_balance: 0 });
        })
        .catch(() => callback({ found: false, credit_balance: 0 }));
}

function performLookup(rawValue) {
    const digits = String(rawValue || '').replace(/\D+/g, '');

    if (!hasUploadedClassList) {
        resetStudentSelection('Your class list has not been uploaded yet. Please upload your class list before using manual request.');
        return;
    }

    if (digits.length < 3) {
        indexInput.classList.remove('searching', 'invalid');
        resetStudentSelection('');
        return;
    }

    activeLookupKey = digits;
    indexInput.classList.remove('invalid');
    indexInput.classList.add('searching');
    setLookupState('info', 'Searching...');
    studentFoundCard.classList.remove('show');
    duplicateCard.classList.remove('show');
    submitButton.disabled = true;

    const lookupUrl = new URL('ajax_student_lookup.php', window.location.href);
    lookupUrl.searchParams.set('index_number', digits);

    fetch(lookupUrl.toString(), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
    })
        .then(response => response.text().then(text => ({ ok: response.ok, status: response.status, text })))
        .then(({ ok, status, text }) => {
            const cleanText = String(text || '').replace(/^\uFEFF/, '').trim();
            let data = null;

            try {
                data = cleanText !== '' ? JSON.parse(cleanText) : null;
            } catch (error) {
                console.error('Student lookup returned invalid JSON', { status, cleanText });
                throw new Error('invalid_json');
            }

            if (activeLookupKey !== digits) {
                return;
            }

            indexInput.classList.remove('searching');

            if (!ok && (!data || !data.message)) {
                throw new Error('server_error');
            }

            if (data && data.success && data.full_index) {
                fetchStudentCredit(data.full_index, function (creditPayload) {
                    applyFoundStudent(
                        String(data.student_name || ''),
                        String(data.full_index || ''),
                        creditPayload.found ? String(creditPayload.phone || '') : '',
                        creditPayload.found ? Number(creditPayload.credit_balance || 0) : 0
                    );
                });
                return;
            }

            if (data && data.status === 'multiple' && Array.isArray(data.matches) && data.matches.length > 0) {
                indexInput.classList.add('invalid');
                setLookupState('warning', String(data.message || 'Multiple matches found. Please select the correct student.'));
                renderDuplicateMatches(data.matches);
                updateCreditDisplay(0);
                checkOwnedBooks('');
                return;
            }

            if (data && data.status === 'no_class_list') {
                indexInput.classList.add('invalid');
                resetStudentSelection('Your class list has not been uploaded yet. Please upload your class list before using manual request.');
                return;
            }

            indexInput.classList.add('invalid');
            resetStudentSelection((data && data.message) ? String(data.message) : 'No student found with these index digits in your uploaded class list.');
        })
        .catch((error) => {
            if (activeLookupKey !== digits) {
                return;
            }
            indexInput.classList.remove('searching');
            indexInput.classList.add('invalid');
            console.error('Student lookup failed', { digits, error: error && error.message ? error.message : error });
            resetStudentSelection('Lookup failed right now. Please try again.');
        });
}

indexInput.addEventListener('input', function() {
    const index = this.value.trim();
    if (lookupTimeout) clearTimeout(lookupTimeout);
    lookupTimeout = setTimeout(() => {
        performLookup(index);
    }, 300);
});

const checkboxes = document.querySelectorAll('.book-checkbox');
const displayTotal = document.getElementById('display_total');
const cashInput = document.getElementById('cash_received');

let userEditedCash = false;

function calculateTotal() {
    let total = 0;
    checkboxes.forEach(cb => {
        if (cb.checked) total += parseFloat(cb.getAttribute('data-price'));
    });
    displayTotal.innerText = total.toFixed(2);
    
    if (studentCredit > 0 && total > 0) {
        const creditToApply = Math.min(studentCredit, total);
        const amountDue = Math.max(0, total - studentCredit);
        
        balanceAppliedEl.textContent = creditToApply.toFixed(2);
        amountDueEl.textContent = amountDue.toFixed(2);
        balanceAppliedRow.style.display = 'flex';
        amountDueRow.style.display = 'flex';
        
        if (!userEditedCash) {
            cashInput.value = amountDue.toFixed(2);
        }
    } else {
        balanceAppliedRow.style.display = 'none';
        amountDueRow.style.display = 'none';
        if (!userEditedCash) {
            cashInput.value = total.toFixed(2);
        }
    }
}

cashInput.addEventListener('input', function() {
    userEditedCash = true;
    
    const enteredAmount = parseFloat(this.value) || 0;
    const allBooksTotal = getAllAvailableBooksTotal();
    
    if (enteredAmount > 0 && Math.abs(enteredAmount - allBooksTotal) < 0.01) {
        checkboxes.forEach(cb => {
            if (!cb.disabled) {
                cb.checked = true;
            }
        });
        calculateTotal();
    }
});

function getAllAvailableBooksTotal() {
    let total = 0;
    checkboxes.forEach(cb => {
        if (!cb.disabled) {
            total += parseFloat(cb.getAttribute('data-price')) || 0;
        }
    });
    return total;
}

checkboxes.forEach(cb => cb.addEventListener('change', calculateTotal));

function checkOwnedBooks(indexNumber) {
    if (!indexNumber || indexNumber.length < 3) {
        checkboxes.forEach(checkbox => {
            checkbox.disabled = false;
            checkbox.checked = false;
            checkbox.parentElement.style.opacity = "1";
            checkbox.parentElement.style.textDecoration = "";
            checkbox.parentElement.title = "";
            const badge = checkbox.parentElement.querySelector('.owned-badge');
            if (badge) badge.remove();
        });
        calculateTotal();
        return;
    }
    
    fetch('check_student_books.php?index=' + encodeURIComponent(indexNumber))
        .then(response => response.json())
        .then(ownedBooks => {
            checkboxes.forEach(checkbox => {
                checkbox.disabled = false;
                checkbox.checked = false;
                checkbox.parentElement.style.opacity = "1";
                checkbox.parentElement.style.textDecoration = "";
                checkbox.parentElement.title = "";
                const badge = checkbox.parentElement.querySelector('.owned-badge');
                if (badge) badge.remove();
            });
            
            ownedBooks.forEach(bookId => {
                const checkbox = document.querySelector(`.book-checkbox[value="${bookId}"]`);
                if (checkbox) {
                    checkbox.disabled = true;
                    checkbox.checked = false;
                    checkbox.parentElement.style.opacity = "0.5";
                    checkbox.parentElement.style.textDecoration = "line-through";
                    checkbox.parentElement.title = "This student has already requested this book.";
                    const badge = document.createElement('span');
                    badge.className = 'owned-badge';
                    badge.style.cssText = 'background:#dc3545;color:white;font-size:10px;padding:2px 6px;border-radius:4px;margin-left:8px;';
                    badge.textContent = 'Already Requested';
                    checkbox.parentElement.appendChild(badge);
                }
            });
            
            calculateTotal();
        });
}

document.addEventListener('DOMContentLoaded', function () {
    var backBtn = document.querySelector('.back-btn');
    if (backBtn) {
        backBtn.href = <?= json_encode($dashboard_url) ?>;
        backBtn.innerHTML = '&larr; Back to Dashboard';
    }

    if (!hasUploadedClassList) {
        submitButton.disabled = true;
        setLookupState(
            'warning',
            'Your class list has not been uploaded yet. Please upload your class list before using manual request.'
        );
    }
});

orderForm.addEventListener('submit', function (event) {
    if (!selectedStudentIndexInput.value) {
        event.preventDefault();
        setLookupState('error', 'Please find and confirm a valid student before completing the transaction.');
        indexInput.focus();
        return;
    }

    if (submitButton.disabled) {
        event.preventDefault();
        return;
    }

    submitButton.disabled = true;
    submitButton.textContent = 'Saving...';
});

function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (character) {
        return ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[character];
    });
}
</script>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
<?php include 'footer.php'; ?>

</body>
</html>
