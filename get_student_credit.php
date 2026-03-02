<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (isset($_GET['index'])) {
    $index = trim(strval($_GET['index'] ?? ''));
    $rep_id = isset($_GET['rep_id']) ? intval($_GET['rep_id']) : 0;

    $current_admin_id = intval($_SESSION['admin_id'] ?? 0);
    $current_admin_role = $_SESSION['admin_role'] ?? 'rep';
    $is_super_admin = (($current_admin_role ?? '') === 'super_admin');

    // If authenticated, allow using session admin_id as scope (manual order uses this endpoint).
    if ($rep_id <= 0 && isset($_SESSION['admin_logged_in']) && $current_admin_id > 0) {
        $rep_id = $current_admin_id;
    }

    // Public calls must include a rep_id scope.
    if (!isset($_SESSION['admin_logged_in']) && $rep_id <= 0) {
        echo json_encode(['found' => false, 'credit_balance' => 0]);
        exit;
    }

    $exact_index = (bool) preg_match('/^\d{10}$/', $index);
    $like_pattern = '%' . $index;

    // Only return students.credit_balance for exact index matches.
    if ($exact_index) {
        $result = null;
        if ($is_super_admin) {
            $stmt = $conn->prepare("SELECT index_number, full_name, phone, credit_balance FROM students WHERE index_number = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $index);
                $stmt->execute();
                $result = $stmt->get_result();
            }
        } else {
            $stmt = $conn->prepare("SELECT index_number, full_name, phone, credit_balance FROM students WHERE index_number = ? AND (admin_id = ? OR admin_id IS NULL OR admin_id = 0) LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('si', $index, $rep_id);
                $stmt->execute();
                $result = $stmt->get_result();
            }
        }

        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            echo json_encode([
                'found' => true,
                'full_name' => $row['full_name'],
                'phone' => $row['phone'],
                'credit_balance' => floatval($row['credit_balance']),
                'full_index' => $row['index_number']
            ]);
            exit;
        }
    }

    // Fallback: check class_students table (rep's uploaded roster) for name only.
    // Allow partial matching, but only within the rep scope.
    $result2 = null;
    if ($rep_id > 0) {
        $stmt2 = $conn->prepare("SELECT index_number, student_name FROM class_students WHERE admin_id = ? AND index_number LIKE ? LIMIT 1");
        if ($stmt2) {
            $stmt2->bind_param("is", $rep_id, $like_pattern);
            $stmt2->execute();
            $result2 = $stmt2->get_result();
        }
    }

    if ($result2 && $result2->num_rows > 0) {
        $row2 = $result2->fetch_assoc();
        echo json_encode([
            'found' => true,
            'full_name' => $row2['student_name'],
            'phone' => '',
            'credit_balance' => 0,
            'full_index' => $row2['index_number']
        ]);
    } else {
        echo json_encode(['found' => false, 'credit_balance' => 0]);
    }
} else {
    echo json_encode(['found' => false, 'credit_balance' => 0]);
}
