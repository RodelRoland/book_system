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
$current_admin_class = trim(strval($access_context['effective_class_name'] ?? ''));
$is_super_admin = false;
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$rep_bottom_nav_active = 'requests';

function request_debtors_build_rows(
    mysqli $conn,
    int $bookId,
    int $semesterId,
    int $adminId,
    bool $isSuperAdmin
): array {
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
            COALESCE(b.book_title, '') AS book_title,
            COALESCE(b.course_code, '') AS course_code,
            s.full_name,
            s.index_number,
            s.phone,
            COALESCE(a.class_name, '') AS class_name,
            COALESCE(a.academic_level, '') AS academic_level
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
          )" : '') . "
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
    $amountPaid = 0.0;

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $requestId = intval($row['request_id'] ?? 0);
            if ($requestId !== $activeRequestId) {
                $activeRequestId = $requestId;
                $amountPaid = floatval($row['amount_paid'] ?? 0);
                $remainingCoveredAmount = max(0.0, $amountPaid + floatval($row['credit_used'] ?? 0));
                $requestOutstanding = max(
                    0.0,
                    floatval($row['total_amount'] ?? 0)
                    - floatval($row['credit_used'] ?? 0)
                    - $amountPaid
                );
            }

            $rowBookId = intval($row['book_id'] ?? 0);
            $itemPrice = max(0.0, floatval($row['item_price'] ?? 0));
            $coveredForItem = min($remainingCoveredAmount, $itemPrice);
            $itemFullyCovered = ($coveredForItem + 0.009) >= $itemPrice;

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
                    'selected_book_owed' => max(0.0, $itemPrice - $coveredForItem),
                    'request_outstanding' => $requestOutstanding,
                    'payment_status' => ($amountPaid <= 0.009 ? 'unpaid' : 'partial'),
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
        $byBook = strcasecmp(strval($left['book_label'] ?? ''), strval($right['book_label'] ?? ''));
        if ($byBook !== 0) {
            return $byBook;
        }
        return strcasecmp(strval($left['index_number'] ?? ''), strval($right['index_number'] ?? ''));
    });

    return $rows;
}

$mode = ($_GET['mode'] ?? 'all') === 'book' ? 'book' : 'all';
$book_id = max(0, intval($_GET['book_id'] ?? 0));
$download = isset($_GET['download']) && $_GET['download'] === '1';
$book_options = [];
$book_titles = [];
$rows = [];
$selected_book_title = '';
$total_book_owed = 0.0;
$total_request_outstanding = 0.0;
$unpaid_count = 0;
$partial_count = 0;

$books_sql = "
    SELECT DISTINCT b.book_id, b.book_title, COALESCE(b.course_code, '') AS course_code
    FROM books b
    JOIN request_items ri ON ri.book_id = b.book_id
    JOIN requests r ON r.request_id = ri.request_id
    WHERE r.semester_id = ?
      " . ($is_super_admin ? '' : 'AND r.admin_id = ?') . "
      AND COALESCE(ri.is_cancelled, 0) = 0
    ORDER BY b.book_title ASC
";
$books_stmt = $conn->prepare($books_sql);
if ($books_stmt) {
    if ($is_super_admin) {
        $books_stmt->bind_param('i', $semester_id);
    } else {
        $books_stmt->bind_param('ii', $semester_id, $current_admin_id);
    }
    $books_stmt->execute();
    $books_result = $books_stmt->get_result();
    if ($books_result) {
        while ($book_row = $books_result->fetch_assoc()) {
            $option_id = intval($book_row['book_id'] ?? 0);
            $label = trim(strval($book_row['course_code'] ?? '') . ' ' . strval($book_row['book_title'] ?? ''));
            $book_options[] = $book_row;
            $book_titles[$option_id] = $label !== '' ? $label : ('Book #' . $option_id);
        }
    }
    $books_stmt->close();
}

if ($mode === 'book') {
    if ($book_id > 0 && isset($book_titles[$book_id])) {
        $selected_book_title = $book_titles[$book_id];
        $rows = request_debtors_build_rows($conn, $book_id, $semester_id, $current_admin_id, $is_super_admin);
    } else {
        $book_id = 0;
    }
} else {
    $rows = request_debtors_build_rows($conn, 0, $semester_id, $current_admin_id, $is_super_admin);
}

$seen_requests = [];
foreach ($rows as $row) {
    $total_book_owed += floatval($row['selected_book_owed'] ?? 0);
    $requestId = intval($row['request_id'] ?? 0);
    if (!isset($seen_requests[$requestId])) {
        $seen_requests[$requestId] = true;
        $total_request_outstanding += floatval($row['request_outstanding'] ?? 0);
    }
    if (($row['payment_status'] ?? '') === 'unpaid') {
        $unpaid_count++;
    } else {
        $partial_count++;
    }
}

if ($download && !empty($rows)) {
    $filename_label = $mode === 'book'
        ? ($selected_book_title !== '' ? $selected_book_title : ('book_' . $book_id))
        : 'all_books';
    $safe_title = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $filename_label);
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=debtors_' . $safe_title . '_' . date('Y-m-d') . '.xls');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo "<table border=\"1\">";
    echo "<tr><th>Student Name</th><th>Index Number</th><th>Phone</th><th>Class</th><th>Academic Level</th><th>Requested Date</th><th>Book</th><th>Payment Status</th><th>Amount Owed For Book</th><th>Total Request Outstanding</th></tr>";
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars(strval($row['full_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(strval($row['index_number'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(strval($row['phone'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(strval($row['class_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(strval($row['academic_level'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(strval($row['created_at'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(strval($row['book_label'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars(strtoupper(strval($row['payment_status'] ?? 'partial'))) . '</td>';
        echo '<td>' . htmlspecialchars(number_format(floatval($row['selected_book_owed'] ?? 0), 2)) . '</td>';
        echo '<td>' . htmlspecialchars(number_format(floatval($row['request_outstanding'] ?? 0), 2)) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit;
}

$download_href = '';
if (!empty($rows)) {
    $download_query = ['mode' => $mode, 'download' => '1'];
    if ($mode === 'book' && $book_id > 0) {
        $download_query['book_id'] = $book_id;
    }
    $download_href = 'request_debtors.php?' . http_build_query($download_query);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Debtors - ClassBookHub</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --bg: #f5f7fb;
            --card: #ffffff;
            --line: #dbe4f0;
            --text: #183153;
            --muted: #6a7b95;
            --primary: #2d67f6;
            --primary-soft: rgba(45, 103, 246, 0.12);
            --danger: #d64545;
            --danger-soft: rgba(214, 69, 69, 0.12);
            --success: #1a9b5c;
            --warning: #b7791f;
            --shadow: 0 24px 60px rgba(14, 35, 79, 0.08);
            --radius: 24px;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: linear-gradient(180deg, #eef3fb 0%, #f8fbff 100%);
            color: var(--text);
        }
        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px 16px 120px;
        }
        .topbar, .panel, .table-shell {
            background: var(--card);
            border: 1px solid rgba(219, 228, 240, 0.85);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .topbar {
            padding: 22px;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 18px;
        }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 700;
            font-size: 0.78rem;
            letter-spacing: 0.02em;
        }
        h1 {
            margin: 12px 0 6px;
            font-size: clamp(1.55rem, 2.6vw, 2.2rem);
        }
        .subtitle {
            margin: 0;
            color: var(--muted);
            max-width: 640px;
            line-height: 1.5;
        }
        .topbar-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            border: 0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 14px;
            padding: 12px 16px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-secondary { background: #edf2fb; color: var(--text); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-outline { background: transparent; border: 1px solid var(--line); color: var(--text); }
        .panel {
            padding: 22px;
            margin-bottom: 18px;
        }
        .mode-grid, .stats-grid {
            display: grid;
            gap: 14px;
        }
        .mode-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .stats-grid { grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-top: 18px; }
        .mode-card, .stat-card {
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            background: #fbfdff;
        }
        .mode-card.active { border-color: rgba(45, 103, 246, 0.4); background: rgba(45, 103, 246, 0.06); }
        .mode-card label { display: flex; gap: 12px; cursor: pointer; }
        .mode-card input { margin-top: 4px; }
        .mode-card strong, .stat-card strong { display: block; font-size: 1.02rem; margin-bottom: 4px; }
        .mode-card p, .stat-card span, .helper-text { margin: 0; color: var(--muted); line-height: 1.45; }
        .form-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: minmax(0, 1fr);
            margin-top: 18px;
        }
        .form-row {
            display: grid;
            gap: 14px;
            grid-template-columns: minmax(0, 1fr);
        }
        label { display: block; font-weight: 700; margin-bottom: 8px; }
        select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 13px 14px;
            background: #fff;
            color: var(--text);
            font: inherit;
        }
        .actions-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .table-shell {
            overflow: hidden;
        }
        .table-head {
            padding: 18px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #edf2f7;
            vertical-align: top;
        }
        th {
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--muted);
            background: #fbfdff;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
        }
        .pill-unpaid { background: var(--danger-soft); color: var(--danger); }
        .pill-partial { background: rgba(183, 121, 31, 0.14); color: var(--warning); }
        .empty-state {
            padding: 28px 20px;
            color: var(--muted);
        }
        @media (min-width: 760px) {
            .form-row {
                grid-template-columns: 1fr auto auto;
                align-items: end;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <section class="topbar">
            <div>
                <span class="eyebrow"><i class="bi bi-hourglass-split"></i> Request Debtors</span>
                <h1>Download unpaid and partial balances</h1>
                <p class="subtitle">
                    Load all outstanding debtors or narrow the list to one book, then export only when there are actual debtors to download.
                    <?php if ($current_admin_class !== ''): ?>
                        Workspace: <?php echo htmlspecialchars($current_admin_class); ?>.
                    <?php endif; ?>
                </p>
            </div>
            <div class="topbar-actions">
                <a href="view_request.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Requests</a>
                <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="btn btn-outline"><i class="bi bi-grid"></i> Dashboard</a>
            </div>
        </section>

        <section class="panel">
            <form method="GET">
                <div class="mode-grid">
                    <div class="mode-card<?php echo $mode === 'all' ? ' active' : ''; ?>">
                        <label>
                            <input type="radio" name="mode" value="all" <?php echo $mode === 'all' ? 'checked' : ''; ?>>
                            <div>
                                <strong>Load All Debtors</strong>
                                <p>See every unpaid or partial debtor across all requested books.</p>
                            </div>
                        </label>
                    </div>
                    <div class="mode-card<?php echo $mode === 'book' ? ' active' : ''; ?>">
                        <label>
                            <input type="radio" name="mode" value="book" <?php echo $mode === 'book' ? 'checked' : ''; ?>>
                            <div>
                                <strong>Filter by Book</strong>
                                <p>Load only students who still owe for one selected book.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="form-row">
                        <div>
                            <label for="bookId">Choose book / course</label>
                            <select id="bookId" name="book_id" <?php echo $mode === 'book' ? '' : 'disabled'; ?>>
                                <option value="">Select a book</option>
                                <?php foreach ($book_options as $book_option): ?>
                                    <?php $option_id = intval($book_option['book_id'] ?? 0); ?>
                                    <option value="<?php echo $option_id; ?>" <?php echo $book_id === $option_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($book_titles[$option_id] ?? ('Book #' . $option_id)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="helper-text">Switch to “Filter by Book” to enable this selector.</p>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Load Debtors</button>
                        <?php if ($download_href !== ''): ?>
                            <a href="<?php echo htmlspecialchars($download_href); ?>" class="btn btn-success"><i class="bi bi-download"></i> Download Excel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="stats-grid">
                <div class="stat-card">
                    <span>Current Scope</span>
                    <strong><?php echo htmlspecialchars($mode === 'book' && $selected_book_title !== '' ? $selected_book_title : 'All Books'); ?></strong>
                </div>
                <div class="stat-card">
                    <span>Debtor Rows</span>
                    <strong><?php echo number_format(count($rows)); ?></strong>
                </div>
                <div class="stat-card">
                    <span>Unpaid</span>
                    <strong><?php echo number_format($unpaid_count); ?></strong>
                </div>
                <div class="stat-card">
                    <span>Partial</span>
                    <strong><?php echo number_format($partial_count); ?></strong>
                </div>
                <div class="stat-card">
                    <span>Total Owed For Loaded Rows</span>
                    <strong>GH&#8373; <?php echo number_format($total_book_owed, 2); ?></strong>
                </div>
                <div class="stat-card">
                    <span>Unique Request Outstanding</span>
                    <strong>GH&#8373; <?php echo number_format($total_request_outstanding, 2); ?></strong>
                </div>
            </div>
        </section>

        <section class="table-shell">
            <div class="table-head">
                <div>
                    <strong>Debtors Preview</strong>
                    <p class="helper-text">
                        <?php if ($mode === 'book' && $book_id <= 0): ?>
                            Choose a book and load debtors to see results.
                        <?php elseif (empty($rows)): ?>
                            No debtors found for the current scope right now.
                        <?php else: ?>
                            Showing the current debtor rows. Export appears only when there is something to download.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if (!empty($rows)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Book</th>
                            <th>Status</th>
                            <th>Owes For Book</th>
                            <th>Request Outstanding</th>
                            <th>Requested Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars(strval($row['full_name'] ?? 'Student')); ?></strong><br>
                                    <span class="helper-text">
                                        <?php echo htmlspecialchars(strval($row['index_number'] ?? '')); ?>
                                        <?php if (!empty($row['class_name'])): ?>
                                            • <?php echo htmlspecialchars(strval($row['class_name'])); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($row['academic_level'])): ?>
                                            • Level <?php echo htmlspecialchars(strval($row['academic_level'])); ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(strval($row['book_label'] ?? '')); ?></td>
                                <td>
                                    <span class="pill <?php echo ($row['payment_status'] ?? '') === 'unpaid' ? 'pill-unpaid' : 'pill-partial'; ?>">
                                        <?php echo strtoupper(strval($row['payment_status'] ?? 'partial')); ?>
                                    </span>
                                </td>
                                <td>GH&#8373; <?php echo number_format(floatval($row['selected_book_owed'] ?? 0), 2); ?></td>
                                <td>GH&#8373; <?php echo number_format(floatval($row['request_outstanding'] ?? 0), 2); ?></td>
                                <td><?php echo htmlspecialchars(strval($row['created_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">No debtors are available for the current selection.</div>
            <?php endif; ?>
        </section>
    </div>

    <script>
        (function () {
            const modeInputs = document.querySelectorAll('input[name="mode"]');
            const bookSelect = document.getElementById('bookId');
            function syncMode() {
                const selected = document.querySelector('input[name="mode"]:checked');
                const isBookMode = selected && selected.value === 'book';
                bookSelect.disabled = !isBookMode;
                if (!isBookMode) {
                    bookSelect.value = '';
                }
            }
            modeInputs.forEach((input) => input.addEventListener('change', syncMode));
            syncMode();
        })();
    </script>

    <?php include 'rep_bottom_nav.php'; ?>
</body>
</html>
