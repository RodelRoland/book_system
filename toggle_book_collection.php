<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
$is_ajax = isset($_POST['ajax']) || isset($_GET['ajax']) || (strtolower(strval($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest');

function toggle_book_json_response(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['admin_logged_in'])) {
    if ($is_ajax) {
        toggle_book_json_response(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
    }
    header('Location: admin.php');
    exit;
}
include 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
    }
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Rep workspace access is required']);
        exit;
    }
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        if ($is_ajax) {
            toggle_book_json_response(['success' => false, 'message' => 'Security check failed. Please refresh and try again.']);
        }
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
    $item_id = intval($_POST['item_id'] ?? 0);

    if ($item_id <= 0) {
        if ($is_ajax) {
            toggle_book_json_response(['success' => false, 'message' => 'Invalid book item selected.']);
        }
        header("Location: view_request.php?msg=invalid_item");
        exit;
    }

    $current_status = 0;
    $is_cancelled = 0;
    $book_id = 0;
    $request_semester_id = 0;
    $request_admin_id = 0;
    $new_status = 0;
    $stock_remaining = null;
    $requested_state_raw = trim(strval($_POST['desired_state'] ?? ''));

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn->begin_transaction();

        if ($is_super_admin) {
            $current_stmt = $conn->prepare("SELECT
                    ri.is_collected,
                    COALESCE(ri.is_cancelled, 0) AS is_cancelled,
                    ri.book_id,
                    r.semester_id,
                    r.admin_id
                FROM request_items ri
                JOIN requests r ON r.request_id = ri.request_id
                WHERE ri.item_id = ?
                LIMIT 1
                FOR UPDATE");
        } else {
            $current_stmt = $conn->prepare("SELECT
                    ri.is_collected,
                    COALESCE(ri.is_cancelled, 0) AS is_cancelled,
                    ri.book_id,
                    r.semester_id,
                    r.admin_id
                FROM request_items ri
                JOIN requests r ON r.request_id = ri.request_id
                WHERE ri.item_id = ? AND r.admin_id = ?
                LIMIT 1
                FOR UPDATE");
        }

        $current_result = false;
        if ($current_stmt) {
            if ($is_super_admin) {
                $current_stmt->bind_param('i', $item_id);
            } else {
                $current_stmt->bind_param('ii', $item_id, $current_admin_id);
            }
            $current_stmt->execute();
            $current_result = $current_stmt->get_result();
            if ($current_result && $current_result->num_rows === 1) {
                $current_row = $current_result->fetch_assoc();
                $current_status = intval($current_row['is_collected'] ?? 0);
                $is_cancelled = intval($current_row['is_cancelled'] ?? 0);
                $book_id = intval($current_row['book_id'] ?? 0);
                $request_semester_id = intval($current_row['semester_id'] ?? 0);
                $request_admin_id = intval($current_row['admin_id'] ?? 0);
            }
            $current_stmt->close();
        }

        if ($is_cancelled === 1) {
            throw new RuntimeException('Cancelled items cannot be marked as given out.');
        }

        if (!$current_result || $current_result->num_rows !== 1 || $book_id <= 0 || $request_semester_id <= 0 || $request_admin_id <= 0) {
            throw new RuntimeException('You do not have permission to update this book item.');
        }

        if ($requested_state_raw === '0' || $requested_state_raw === '1') {
            $new_collected_status = intval($requested_state_raw);
        } else {
            $new_collected_status = 1 - $current_status;
        }

        if ($new_collected_status === $current_status) {
            $new_status = $current_status;
            $conn->commit();
            if ($is_ajax) {
                toggle_book_json_response([
                    'success' => true,
                    'item_id' => $item_id,
                    'is_collected' => $new_status,
                    'new_status' => $new_status === 1 ? 'given_out' : 'pending',
                    'message' => 'Book status unchanged',
                ]);
            }
            header("Location: view_request.php");
            exit;
        }

        if ($new_collected_status === 1) {
            $stmt = $conn->prepare("UPDATE request_items SET is_collected = 1, received_at = NOW() WHERE item_id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE request_items SET is_collected = 0, received_at = NULL WHERE item_id = ?");
        }

        $result = false;
        if ($stmt) {
            $stmt->bind_param('i', $item_id);
            $result = $stmt->execute();
            $stmt->close();
        }

        if (!$result) {
            throw new RuntimeException('Could not update book status. Please try again.');
        }

        $new_status = $new_collected_status;
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        if ($is_ajax) {
            $errorMessage = $e->getMessage() !== '' ? $e->getMessage() : 'Could not update book status. Please try again.';
            toggle_book_json_response([
                'success' => false,
                'error' => $errorMessage,
                'message' => $errorMessage,
                'stock_remaining' => $stock_remaining === null ? null : max(0, intval($stock_remaining)),
            ]);
        }
        $redirectMsg = ($e->getMessage() === 'This book is out of stock. Please record more copies received before giving it out.')
            ? 'out_of_stock'
            : 'toggle_failed';
        header("Location: view_request.php?msg=" . urlencode($redirectMsg));
        exit;
    } finally {
        mysqli_report(MYSQLI_REPORT_OFF);
    }

    if (function_exists('book_system_audit_log')) {
        book_system_audit_log($conn, 'toggle_collection', 'request_item', $item_id, [
            'is_collected' => $new_status,
        ]);
    }

    if ($is_ajax) {
        toggle_book_json_response([
            'success' => true,
            'item_id' => $item_id,
            'is_collected' => $new_status,
            'new_status' => $new_status === 1 ? 'given_out' : 'pending',
            'message' => 'Book status updated',
        ]);
    }

    header("Location: view_request.php");
    exit;
}
?>

