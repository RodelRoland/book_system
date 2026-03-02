<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php';

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

if (isset($_GET['id'])) {
    if (!csrf_validate($_GET['csrf_token'] ?? null)) {
        header("Location: view_request.php?msg=csrf_invalid");
        exit;
    }
    $request_id = intval($_GET['id']);
    if ($request_id <= 0) {
        header("Location: view_request.php?msg=invalid_request");
        exit;
    }

    // Fetch request details to restore credit if needed
    if ($is_super_admin) {
        $fetch = $conn->prepare("SELECT student_id, credit_used FROM requests WHERE request_id = ? LIMIT 1");
        $fetch->bind_param('i', $request_id);
    } else {
        $fetch = $conn->prepare("SELECT student_id, credit_used FROM requests WHERE request_id = ? AND admin_id = ? LIMIT 1");
        $fetch->bind_param('ii', $request_id, $current_admin_id);
    }
    $fetch->execute();
    $req_data = $fetch->get_result();
    if (!$req_data || $req_data->num_rows !== 1) {
        header("Location: view_request.php?msg=unauthorized");
        exit;
    }
    $req_row = $req_data->fetch_assoc();
    $credit_to_restore = floatval($req_row['credit_used'] ?? 0);
    $del_student_id = intval($req_row['student_id']);

    $conn->begin_transaction();
    try {
        // Restore credit balance to student
        if ($credit_to_restore > 0.001 && $del_student_id > 0) {
            $restore = $conn->prepare("UPDATE students SET credit_balance = credit_balance + ? WHERE student_id = ?");
            $restore->bind_param('di', $credit_to_restore, $del_student_id);
            $restore->execute();
        }

        if ($is_super_admin) {
            $delItems = $conn->prepare("DELETE FROM request_items WHERE request_id = ?");
            if ($delItems) {
                $delItems->bind_param('i', $request_id);
                $delItems->execute();
            }

            $delReq = $conn->prepare("DELETE FROM requests WHERE request_id = ?");
            if ($delReq) {
                $delReq->bind_param('i', $request_id);
                $delReq->execute();
            }
        } else {
            // Rep: only delete if the request belongs to them
            $delItems = $conn->prepare("DELETE ri FROM request_items ri JOIN requests r ON r.request_id = ri.request_id WHERE ri.request_id = ? AND r.admin_id = ?");
            if ($delItems) {
                $delItems->bind_param('ii', $request_id, $current_admin_id);
                $delItems->execute();
            }

            $delReq = $conn->prepare("DELETE FROM requests WHERE request_id = ? AND admin_id = ?");
            if ($delReq) {
                $delReq->bind_param('ii', $request_id, $current_admin_id);
                $delReq->execute();
                if ($delReq->affected_rows !== 1) {
                    throw new RuntimeException('Delete failed');
                }
            }
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        header("Location: view_request.php?msg=delete_failed");
        exit;
    }

    header("Location: view_request.php?msg=Deleted");
    exit;
}
?>