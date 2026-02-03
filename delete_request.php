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
    $request_id = intval($_GET['id']);
    if ($request_id <= 0) {
        header("Location: view_request.php?msg=invalid_request");
        exit;
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

        header("Location: view_request.php?msg=Deleted");
        exit;
    }

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
            header("Location: view_request.php?msg=unauthorized");
            exit;
        }
    }

    header("Location: view_request.php?msg=Deleted");
    exit;
}
?>