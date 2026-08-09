<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
}

if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

book_system_require_admin_feature($conn, 'manage_reps');

$csrf_token = csrf_get_token();
$success_msg = '';
$error_msg = '';

function reset_rep_semester_fetch_scalar(mysqli $conn, string $sql, string $types, array $params): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = 0;
    if ($result && $row = $result->fetch_assoc()) {
        $value = intval(array_values($row)[0] ?? 0);
    }
    $stmt->close();
    return $value;
}

function reset_rep_semester_fetch_rep(mysqli $conn, int $adminId): ?array
{
    if ($adminId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT admin_id, full_name, username, class_name
        FROM admins
        WHERE admin_id = ? AND role = 'rep'
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function reset_rep_semester_fetch_semester(mysqli $conn, int $semesterId): ?array
{
    if ($semesterId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT semester_id, semester_name, semester_start_date, is_active
        FROM semesters
        WHERE semester_id = ?
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $semesterId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function reset_rep_semester_fetch_summary(mysqli $conn, int $adminId, int $semesterId): array
{
    $params = [$adminId, $semesterId];
    $summary = [
        'requests' => 0,
        'request_items' => 0,
        'payment_records' => 0,
        'balance_returns' => 0,
        'books_received' => 0,
        'lecturer_payments' => 0,
        'notifications' => 0,
        'export_batches' => 0,
    ];

    if ($adminId <= 0 || $semesterId <= 0) {
        return $summary;
    }

    $summary['requests'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM requests WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['request_items'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM request_items ri
         INNER JOIN requests r ON r.request_id = ri.request_id
         WHERE r.admin_id = ? AND r.semester_id = ?",
        'ii',
        $params
    );
    $summary['payment_records'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM requests
         WHERE admin_id = ? AND semester_id = ?
           AND (
               COALESCE(amount_paid, 0) > 0
               OR COALESCE(credit_used, 0) > 0
               OR LOWER(TRIM(COALESCE(payment_status, 'unpaid'))) <> 'unpaid'
               OR COALESCE(payment_reference, '') <> ''
               OR COALESCE(payment_gateway, '') <> ''
               OR payment_verified_at IS NOT NULL
           )",
        'ii',
        $params
    );
    $summary['balance_returns'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM balance_returns br
         INNER JOIN requests r ON r.request_id = br.request_id
         WHERE r.admin_id = ? AND r.semester_id = ?",
        'ii',
        $params
    );
    $summary['books_received'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM books_received WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['lecturer_payments'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM lecturer_payments WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );
    $summary['notifications'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM notifications n
         INNER JOIN requests r ON r.request_id = n.related_id
         WHERE r.admin_id = ? AND r.semester_id = ?
           AND n.notification_type IN ('student_request', 'student_payment', 'request_item_cancelled')",
        'ii',
        $params
    );
    $summary['export_batches'] = reset_rep_semester_fetch_scalar(
        $conn,
        "SELECT COUNT(*) FROM lecturer_export_batches WHERE admin_id = ? AND semester_id = ?",
        'ii',
        $params
    );

    return $summary;
}

$selected_admin_id = intval($_REQUEST['admin_id'] ?? $_GET['admin_id'] ?? 0);
$selected_semester_id = intval($_REQUEST['semester_id'] ?? $_GET['semester_id'] ?? 0);

$rep_options = [];
$rep_result = $conn->query("SELECT admin_id, full_name, username, class_name
    FROM admins
    WHERE role = 'rep'
    ORDER BY full_name ASC, username ASC");
if ($rep_result) {
    while ($row = $rep_result->fetch_assoc()) {
        $rep_options[] = $row;
    }
}

$semester_options = [];
$semester_result = $conn->query("SELECT semester_id, semester_name, semester_start_date, is_active
    FROM semesters
    ORDER BY semester_id DESC");
if ($semester_result) {
    while ($row = $semester_result->fetch_assoc()) {
        $semester_options[] = $row;
    }
}

$selected_rep = reset_rep_semester_fetch_rep($conn, $selected_admin_id);
$selected_semester = reset_rep_semester_fetch_semester($conn, $selected_semester_id);
$summary = reset_rep_semester_fetch_summary($conn, $selected_admin_id, $selected_semester_id);
$has_reset_scope = ($selected_rep !== null && $selected_semester !== null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && strval($_POST['action'] ?? '') === 'clear_semester_records') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } elseif ($selected_admin_id <= 0 || $selected_semester_id <= 0) {
        $error_msg = 'Choose a valid rep and semester before continuing.';
    } elseif ($selected_rep === null || $selected_semester === null) {
        $error_msg = 'The selected rep or semester could not be found.';
    } elseif (trim(strval($_POST['confirm_keyword'] ?? '')) !== 'RESET') {
        $error_msg = 'Type RESET exactly before clearing records.';
    } elseif (empty($_POST['confirm_scope'])) {
        $error_msg = 'Confirm that you understand this action cannot be undone.';
    } else {
        $summary = reset_rep_semester_fetch_summary($conn, $selected_admin_id, $selected_semester_id);

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $conn->begin_transaction();

            $creditRestoreRows = [];
            $creditRestoreStmt = $conn->prepare("SELECT
                    r.student_id,
                    ROUND(SUM(GREATEST(COALESCE(r.credit_used, 0) - COALESCE(refunds.credit_refunded_total, 0), 0)), 2) AS credit_restore
                FROM requests r
                LEFT JOIN (
                    SELECT request_id, SUM(COALESCE(credit_refunded_amount, 0)) AS credit_refunded_total
                    FROM request_items
                    GROUP BY request_id
                ) refunds ON refunds.request_id = r.request_id
                WHERE r.admin_id = ? AND r.semester_id = ?
                GROUP BY r.student_id
                HAVING credit_restore > 0.009");
            if (!$creditRestoreStmt) {
                throw new RuntimeException('Could not prepare the student credit reset.');
            }
            $creditRestoreStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $creditRestoreStmt->execute();
            $creditRestoreResult = $creditRestoreStmt->get_result();
            if ($creditRestoreResult) {
                while ($creditRestoreRow = $creditRestoreResult->fetch_assoc()) {
                    $creditRestoreRows[] = [
                        'student_id' => intval($creditRestoreRow['student_id'] ?? 0),
                        'credit_restore' => round(floatval($creditRestoreRow['credit_restore'] ?? 0), 2),
                    ];
                }
            }
            $creditRestoreStmt->close();

            if (!empty($creditRestoreRows)) {
                $restoreStudentCreditStmt = $conn->prepare("UPDATE students
                    SET credit_balance = COALESCE(credit_balance, 0) + ?
                    WHERE student_id = ?
                    LIMIT 1");
                if (!$restoreStudentCreditStmt) {
                    throw new RuntimeException('Could not prepare the student credit update.');
                }
                foreach ($creditRestoreRows as $creditRestoreRow) {
                    if ($creditRestoreRow['student_id'] <= 0 || $creditRestoreRow['credit_restore'] <= 0) {
                        continue;
                    }
                    $restoreStudentCreditStmt->bind_param('di', $creditRestoreRow['credit_restore'], $creditRestoreRow['student_id']);
                    $restoreStudentCreditStmt->execute();
                }
                $restoreStudentCreditStmt->close();
            }

            $notificationDeleteStmt = $conn->prepare("DELETE n
                FROM notifications n
                INNER JOIN requests r ON r.request_id = n.related_id
                WHERE r.admin_id = ? AND r.semester_id = ?
                  AND n.notification_type IN ('student_request', 'student_payment', 'request_item_cancelled')");
            if (!$notificationDeleteStmt) {
                throw new RuntimeException('Could not prepare the notification cleanup.');
            }
            $notificationDeleteStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $notificationDeleteStmt->execute();
            $notificationDeleteStmt->close();

            $balanceDeleteStmt = $conn->prepare("DELETE br
                FROM balance_returns br
                INNER JOIN requests r ON r.request_id = br.request_id
                WHERE r.admin_id = ? AND r.semester_id = ?");
            if (!$balanceDeleteStmt) {
                throw new RuntimeException('Could not prepare the balance cleanup.');
            }
            $balanceDeleteStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $balanceDeleteStmt->execute();
            $balanceDeleteStmt->close();

            $batchItemsDeleteStmt = $conn->prepare("DELETE bei
                FROM lecturer_export_batch_items bei
                INNER JOIN lecturer_export_batches leb ON leb.batch_id = bei.batch_id
                WHERE leb.admin_id = ? AND leb.semester_id = ?");
            if (!$batchItemsDeleteStmt) {
                throw new RuntimeException('Could not prepare the export batch item cleanup.');
            }
            $batchItemsDeleteStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $batchItemsDeleteStmt->execute();
            $batchItemsDeleteStmt->close();

            $batchDeleteStmt = $conn->prepare("DELETE FROM lecturer_export_batches
                WHERE admin_id = ? AND semester_id = ?");
            if (!$batchDeleteStmt) {
                throw new RuntimeException('Could not prepare the export batch cleanup.');
            }
            $batchDeleteStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $batchDeleteStmt->execute();
            $batchDeleteStmt->close();

            $booksReceivedDeleteStmt = $conn->prepare("DELETE FROM books_received
                WHERE admin_id = ? AND semester_id = ?");
            if (!$booksReceivedDeleteStmt) {
                throw new RuntimeException('Could not prepare the books received cleanup.');
            }
            $booksReceivedDeleteStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $booksReceivedDeleteStmt->execute();
            $booksReceivedDeleteStmt->close();

            $lecturerPaymentDeleteStmt = $conn->prepare("DELETE FROM lecturer_payments
                WHERE admin_id = ? AND semester_id = ?");
            if (!$lecturerPaymentDeleteStmt) {
                throw new RuntimeException('Could not prepare the lecturer payment cleanup.');
            }
            $lecturerPaymentDeleteStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $lecturerPaymentDeleteStmt->execute();
            $lecturerPaymentDeleteStmt->close();

            $requestDeleteStmt = $conn->prepare("DELETE FROM requests
                WHERE admin_id = ? AND semester_id = ?");
            if (!$requestDeleteStmt) {
                throw new RuntimeException('Could not prepare the request cleanup.');
            }
            $requestDeleteStmt->bind_param('ii', $selected_admin_id, $selected_semester_id);
            $requestDeleteStmt->execute();
            $requestDeleteStmt->close();

            if (function_exists('book_system_audit_log')) {
                book_system_audit_log(
                    $conn,
                    'clear_semester_records',
                    'rep_semester_scope',
                    $selected_admin_id,
                    [
                        'rep_admin_id' => $selected_admin_id,
                        'semester_id' => $selected_semester_id,
                        'counts' => $summary,
                    ],
                    $selected_admin_id
                );
            }

            $conn->commit();
            mysqli_report(MYSQLI_REPORT_OFF);

            $redirect_query = http_build_query([
                'reset_status' => array_sum($summary) > 0 ? 'success' : 'empty',
                'rep_name' => strval($selected_rep['full_name'] ?? ''),
                'semester_name' => strval($selected_semester['semester_name'] ?? ''),
            ]);
            header('Location: manage_reps.php?' . $redirect_query);
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            mysqli_report(MYSQLI_REPORT_OFF);
            $error_msg = book_system_security_debug_enabled()
                ? $e->getMessage()
                : 'The semester records could not be cleared. Nothing was removed.';
        }
    }
}

$selected_semester_label = '';
if ($selected_semester !== null) {
    $selected_semester_label = function_exists('book_system_build_semester_label')
        ? strval(book_system_build_semester_label(
            strval($selected_semester['semester_name'] ?? ''),
            strval($selected_semester['semester_start_date'] ?? '')
        ))
        : trim(strval($selected_semester['semester_name'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Semester Records</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
            color: #111827;
            min-height: 100vh;
            padding: 28px 16px 44px;
        }
        .page-shell {
            width: min(100%, 980px);
            margin: 0 auto;
        }
        .hero,
        .card {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 24px;
            box-shadow: 0 22px 48px rgba(15, 23, 42, 0.10);
        }
        .hero {
            padding: 24px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #0f172a 0%, #312e81 55%, #4f46e5 100%);
            color: #fff;
        }
        .hero-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .hero h1 {
            font-size: clamp(1.7rem, 4vw, 2.3rem);
            line-height: 1.08;
            margin-bottom: 8px;
        }
        .hero p {
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.6;
            max-width: 620px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 16px;
            border: 1px solid transparent;
        }
        .alert-error {
            background: #fff1f2;
            color: #b91c1c;
            border-color: rgba(239, 68, 68, 0.15);
        }
        .card {
            padding: 22px;
            margin-bottom: 18px;
        }
        .card h2 {
            font-size: 1.1rem;
            margin-bottom: 8px;
        }
        .card p {
            color: #64748b;
            line-height: 1.6;
        }
        .form-grid,
        .scope-grid,
        .summary-grid {
            display: grid;
            gap: 14px;
        }
        .form-grid {
            margin-top: 18px;
        }
        .field label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.84rem;
            font-weight: 700;
            color: #334155;
        }
        .field select,
        .field input[type="text"] {
            width: 100%;
            min-height: 48px;
            border-radius: 16px;
            border: 1px solid #dbe5f1;
            background: #fff;
            padding: 0 14px;
            font: inherit;
            color: #111827;
        }
        .scope-grid,
        .summary-grid {
            margin-top: 16px;
        }
        .scope-chip,
        .summary-card {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            background: #f8fbff;
        }
        .scope-label,
        .summary-label {
            display: block;
            font-size: 0.74rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            margin-bottom: 8px;
        }
        .scope-value,
        .summary-value {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
        }
        .summary-value {
            font-size: 1.8rem;
            line-height: 1;
        }
        .warning-box {
            margin-top: 16px;
            padding: 18px;
            border-radius: 18px;
            background: #fff7ed;
            border: 1px solid rgba(249, 115, 22, 0.18);
            color: #9a3412;
        }
        .warning-box strong {
            display: block;
            font-size: 0.92rem;
            margin-bottom: 8px;
        }
        .confirm-box {
            margin-top: 18px;
            display: grid;
            gap: 12px;
        }
        .checkbox-line {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            color: #334155;
        }
        .checkbox-line input {
            margin-top: 3px;
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 16px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-weight: 800;
            font-size: 0.95rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #4f46e5);
            color: #fff;
            box-shadow: 0 16px 30px rgba(37, 99, 235, 0.22);
        }
        .btn-danger {
            background: linear-gradient(135deg, #dc2626, #ef4444);
            color: #fff;
            box-shadow: 0 16px 30px rgba(220, 38, 38, 0.18);
        }
        .btn-muted {
            background: #fff;
            color: #334155;
            border: 1px solid #dbe5f1;
        }
        @media (min-width: 760px) {
            .form-grid,
            .scope-grid,
            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<div class="page-shell">
    <section class="hero">
        <div class="hero-top">
            <div>
                <h1>Clear Semester Records</h1>
                <p>Remove test or transaction history for one rep in one semester only. Class lists, rep accounts, and global books stay intact.</p>
            </div>
            <a href="manage_reps.php" class="back-link">&larr; Back to Manage Reps</a>
        </div>
    </section>

    <?php if ($error_msg !== ''): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Select the reset scope</h2>
        <p>Pick the rep and semester you want to clear. The reset will never run without both values.</p>
        <form method="GET" class="form-grid">
            <div class="field">
                <label for="admin_id">Rep</label>
                <select id="admin_id" name="admin_id" required>
                    <option value="">Select rep</option>
                    <?php foreach ($rep_options as $rep_option): ?>
                        <?php
                        $rep_option_label = trim(strval($rep_option['full_name'] ?? ''));
                        $rep_option_meta = trim(strval($rep_option['class_name'] ?? ''));
                        if ($rep_option_meta !== '') {
                            $rep_option_label .= ' - ' . $rep_option_meta;
                        }
                        ?>
                        <option value="<?php echo intval($rep_option['admin_id'] ?? 0); ?>" <?php echo $selected_admin_id === intval($rep_option['admin_id'] ?? 0) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($rep_option_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="semester_id">Semester</label>
                <select id="semester_id" name="semester_id" required>
                    <option value="">Select semester</option>
                    <?php foreach ($semester_options as $semester_option): ?>
                        <?php
                        $option_label = function_exists('book_system_build_semester_label')
                            ? strval(book_system_build_semester_label(
                                strval($semester_option['semester_name'] ?? ''),
                                strval($semester_option['semester_start_date'] ?? '')
                            ))
                            : trim(strval($semester_option['semester_name'] ?? ''));
                        if (intval($semester_option['is_active'] ?? 0) === 1) {
                            $option_label .= ' (Active)';
                        }
                        ?>
                        <option value="<?php echo intval($semester_option['semester_id'] ?? 0); ?>" <?php echo $selected_semester_id === intval($semester_option['semester_id'] ?? 0) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($option_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actions" style="grid-column: 1 / -1; margin-top: 4px;">
                <button type="submit" class="btn btn-primary">Preview Records</button>
            </div>
        </form>
    </section>

    <?php if ($has_reset_scope): ?>
    <section class="card">
        <h2>Reset preview</h2>
        <p>Review the exact scope before you clear anything.</p>

        <div class="scope-grid">
            <div class="scope-chip">
                <span class="scope-label">Selected Rep</span>
                <div class="scope-value"><?php echo htmlspecialchars(strval($selected_rep['full_name'] ?? '')); ?></div>
            </div>
            <div class="scope-chip">
                <span class="scope-label">Selected Semester</span>
                <div class="scope-value"><?php echo htmlspecialchars($selected_semester_label !== '' ? $selected_semester_label : strval($selected_semester['semester_name'] ?? '')); ?></div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <span class="summary-label">Requests</span>
                <div class="summary-value"><?php echo number_format(intval($summary['requests'] ?? 0)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-label">Request Items</span>
                <div class="summary-value"><?php echo number_format(intval($summary['request_items'] ?? 0)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-label">Payment Records</span>
                <div class="summary-value"><?php echo number_format(intval($summary['payment_records'] ?? 0)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-label">Balance Returns</span>
                <div class="summary-value"><?php echo number_format(intval($summary['balance_returns'] ?? 0)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-label">Books Received</span>
                <div class="summary-value"><?php echo number_format(intval($summary['books_received'] ?? 0)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-label">Lecturer Payments</span>
                <div class="summary-value"><?php echo number_format(intval($summary['lecturer_payments'] ?? 0)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-label">Notifications</span>
                <div class="summary-value"><?php echo number_format(intval($summary['notifications'] ?? 0)); ?></div>
            </div>
            <div class="summary-card">
                <span class="summary-label">Export Batches</span>
                <div class="summary-value"><?php echo number_format(intval($summary['export_batches'] ?? 0)); ?></div>
            </div>
        </div>

        <div class="warning-box">
            <strong>This action cannot be undone.</strong>
            Only semester-scoped transaction records for this rep will be cleared. Rep accounts, uploaded class lists, students in class lists, and global books will remain untouched.
        </div>

        <form method="POST" class="confirm-box">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="clear_semester_records">
            <input type="hidden" name="admin_id" value="<?php echo intval($selected_admin_id); ?>">
            <input type="hidden" name="semester_id" value="<?php echo intval($selected_semester_id); ?>">
            <div class="field">
                <label for="confirm_keyword">Type RESET to continue</label>
                <input type="text" id="confirm_keyword" name="confirm_keyword" placeholder="RESET" autocomplete="off" required>
            </div>
            <label class="checkbox-line">
                <input type="checkbox" name="confirm_scope" value="1" required>
                <span>I understand this will permanently clear semester records for this rep only.</span>
            </label>
            <div class="actions">
                <button type="submit" class="btn btn-danger">Clear Semester Records</button>
                <a href="manage_reps.php" class="btn btn-muted">Cancel</a>
            </div>
        </form>
    </section>
    <?php endif; ?>
</div>
</body>
</html>
