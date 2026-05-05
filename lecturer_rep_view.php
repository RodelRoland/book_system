<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_level', 'VARCHAR(10) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'books', 'course_code', 'VARCHAR(20) NULL AFTER book_title');
        book_system_setup_ensure_column($conn, 'books', 'course_code_key', 'VARCHAR(20) NULL AFTER course_code');
    }
}
$conn->query("CREATE TABLE IF NOT EXISTS lecturer_materials (
    material_id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NOT NULL,
    material_title VARCHAR(100) NOT NULL,
    course_code VARCHAR(20) NOT NULL,
    course_code_key VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lecturer_material_code (lecturer_id, course_code_key)
)");

if (!isset($_SESSION['lecturer_logged_in']) || intval($_SESSION['lecturer_logged_in']) !== 1) {
    header('Location: lecturer_login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: lecturer_rep_view.php?rep_id=' . intval($_GET['rep_id'] ?? 0) . '&msg=csrf_invalid');
        exit;
    }

    session_destroy();
    header('Location: lecturer_login.php');
    exit;
}

$lecturer_id = intval($_SESSION['lecturer_id'] ?? 0);
$lecturer_name = $_SESSION['lecturer_full_name'] ?? $_SESSION['lecturer_username'] ?? 'Lecturer';
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$semester_name = isset($ACTIVE_SEMESTER_NAME) ? strval($ACTIVE_SEMESTER_NAME) : 'Active Semester';
$semester_label = isset($ACTIVE_SEMESTER_LABEL) ? strval($ACTIVE_SEMESTER_LABEL) : $semester_name;
$csrf_token = csrf_get_token();

$error_msg = '';
$rep_id = intval($_GET['rep_id'] ?? 0);
$export_csv = isset($_GET['export']) && intval($_GET['export']) === 1;

$lecturer_stmt = $conn->prepare("SELECT lecturer_id, full_name, teaching_level, is_active FROM lecturers WHERE lecturer_id = ? LIMIT 1");
$lecturer_row = null;
if ($lecturer_stmt) {
    $lecturer_stmt->bind_param('i', $lecturer_id);
    $lecturer_stmt->execute();
    $lecturer_result = $lecturer_stmt->get_result();
    $lecturer_row = ($lecturer_result && $lecturer_result->num_rows === 1) ? $lecturer_result->fetch_assoc() : null;
    $lecturer_stmt->close();
}

if (!$lecturer_row || intval($lecturer_row['is_active'] ?? 0) !== 1) {
    session_destroy();
    header('Location: lecturer_login.php');
    exit;
}

$lecturer_teaching_level = function_exists('book_system_normalize_teaching_level')
    ? book_system_normalize_teaching_level($lecturer_row['teaching_level'] ?? ($_SESSION['lecturer_teaching_level'] ?? ''))
    : preg_replace('/[^0-9]/', '', strval($lecturer_row['teaching_level'] ?? ($_SESSION['lecturer_teaching_level'] ?? '')));
$lecturer_level_regex = function_exists('book_system_build_level_regex')
    ? book_system_build_level_regex($lecturer_teaching_level)
    : ($lecturer_teaching_level !== '' ? '(^|[^0-9])' . $lecturer_teaching_level . '([^0-9]|$)' : '');

$rep_row = null;
if ($rep_id <= 0) {
    $error_msg = 'No rep was selected.';
} else {
    $repSql = "SELECT admin_id, full_name, class_name FROM admins WHERE admin_id = ? AND role = 'rep' AND is_active = 1";
    if ($lecturer_level_regex !== '') {
        $repSql .= " AND COALESCE(class_name, '') REGEXP ?";
    }
    $repSql .= " LIMIT 1";
    $rep_stmt = $conn->prepare($repSql);
    if ($rep_stmt) {
        if ($lecturer_level_regex !== '') {
            $rep_stmt->bind_param('is', $rep_id, $lecturer_level_regex);
        } else {
            $rep_stmt->bind_param('i', $rep_id);
        }
        $rep_stmt->execute();
        $rep_result = $rep_stmt->get_result();
        $rep_row = ($rep_result && $rep_result->num_rows === 1) ? $rep_result->fetch_assoc() : null;
        $rep_stmt->close();
    }

    if (!$rep_row) {
        $error_msg = 'The selected rep could not be found for your teaching level.';
    }
}

$rep_total_copies = 0;
$students_served = 0;
$books_collected_total = 0;
$distribution_by_book = [];
$book_breakdown = [];
$student_rows = [];

if ($error_msg === '') {
    $total_stmt = $conn->prepare("SELECT COALESCE(SUM(copies_given), 0) AS total_copies
        FROM lecturer_distributions
        WHERE lecturer_id = ? AND rep_admin_id = ? AND semester_id = ?");
    if ($total_stmt) {
        $total_stmt->bind_param('iii', $lecturer_id, $rep_id, $semester_id);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        if ($total_result && $total_result->num_rows === 1) {
            $rep_total_copies = intval($total_result->fetch_assoc()['total_copies'] ?? 0);
        }
        $total_stmt->close();
    }

    $distribution_stmt = $conn->prepare("SELECT
            ld.book_id,
            b.book_title,
            COALESCE(SUM(ld.copies_given), 0) AS copies_given
        FROM lecturer_distributions ld
        JOIN books b ON b.book_id = ld.book_id
        WHERE ld.lecturer_id = ? AND ld.rep_admin_id = ? AND ld.semester_id = ?
        GROUP BY ld.book_id, b.book_title
        ORDER BY copies_given DESC, b.book_title ASC");
    if ($distribution_stmt) {
        $distribution_stmt->bind_param('iii', $lecturer_id, $rep_id, $semester_id);
        $distribution_stmt->execute();
        $distribution_result = $distribution_stmt->get_result();
        while ($distribution_result && ($row = $distribution_result->fetch_assoc())) {
            $book_id = intval($row['book_id']);
            $distribution_by_book[$book_id] = [
                'book_title' => strval($row['book_title'] ?? ''),
                'copies_given' => intval($row['copies_given'] ?? 0),
                'books_collected' => 0,
            ];
        }
        $distribution_stmt->close();
    }

    $book_stmt = $conn->prepare("SELECT
            b.book_id,
            b.book_title,
            COUNT(*) AS books_collected
        FROM books b
        JOIN request_items ri ON ri.book_id = b.book_id AND ri.is_collected = 1
        JOIN requests r ON r.request_id = ri.request_id AND r.admin_id = ? AND r.semester_id = ?
        WHERE (
            EXISTS (SELECT 1 FROM lecturer_books lb WHERE lb.lecturer_id = ? AND lb.book_id = b.book_id)
            OR EXISTS (SELECT 1 FROM lecturer_materials lm WHERE lm.lecturer_id = ? AND lm.course_code_key <> '' AND lm.course_code_key = COALESCE(b.course_code_key, ''))
        )
        GROUP BY b.book_id, b.book_title
        ORDER BY books_collected DESC, b.book_title ASC");
    if ($book_stmt) {
        $book_stmt->bind_param('iiii', $rep_id, $semester_id, $lecturer_id, $lecturer_id);
        $book_stmt->execute();
        $book_result = $book_stmt->get_result();
        while ($book_result && ($row = $book_result->fetch_assoc())) {
            $book_id = intval($row['book_id']);
            if (!isset($distribution_by_book[$book_id])) {
                $distribution_by_book[$book_id] = [
                    'book_title' => strval($row['book_title'] ?? ''),
                    'copies_given' => 0,
                    'books_collected' => 0,
                ];
            }
            $distribution_by_book[$book_id]['books_collected'] = intval($row['books_collected'] ?? 0);
        }
        $book_stmt->close();
    }

    $student_stmt = $conn->prepare("SELECT
            s.student_id,
            s.full_name,
            s.index_number,
            COUNT(*) AS books_collected,
            GROUP_CONCAT(DISTINCT b.book_title ORDER BY b.book_title SEPARATOR ', ') AS books_list,
            MAX(COALESCE(ri.received_at, r.created_at)) AS last_collected_at
        FROM books b
        JOIN request_items ri ON ri.book_id = b.book_id AND ri.is_collected = 1
        JOIN requests r ON r.request_id = ri.request_id AND r.admin_id = ? AND r.semester_id = ?
        JOIN students s ON s.student_id = r.student_id
        WHERE (
            EXISTS (SELECT 1 FROM lecturer_books lb WHERE lb.lecturer_id = ? AND lb.book_id = b.book_id)
            OR EXISTS (SELECT 1 FROM lecturer_materials lm WHERE lm.lecturer_id = ? AND lm.course_code_key <> '' AND lm.course_code_key = COALESCE(b.course_code_key, ''))
        )
        GROUP BY s.student_id, s.full_name, s.index_number
        ORDER BY last_collected_at DESC, s.full_name ASC");
    if ($student_stmt) {
        $student_stmt->bind_param('iiii', $rep_id, $semester_id, $lecturer_id, $lecturer_id);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        while ($student_result && ($row = $student_result->fetch_assoc())) {
            $student_rows[] = $row;
            $students_served++;
            $books_collected_total += intval($row['books_collected'] ?? 0);
        }
        $student_stmt->close();
    }

    $book_breakdown = array_values($distribution_by_book);
    usort($book_breakdown, function ($left, $right) {
        $left_given = intval($left['copies_given'] ?? 0);
        $right_given = intval($right['copies_given'] ?? 0);
        if ($left_given === $right_given) {
            return strcmp(strval($left['book_title'] ?? ''), strval($right['book_title'] ?? ''));
        }
        return $right_given <=> $left_given;
    });

    if ($export_csv) {
        header('Content-Type: text/csv');
        $safe_rep = preg_replace('/[^a-zA-Z0-9_-]+/', '_', strval($rep_row['full_name'] ?? 'rep_' . $rep_id));
        header('Content-Disposition: attachment; filename=' . $safe_rep . '_lecturer_view.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, ['Student Name', 'Index Number', 'Books Collected', 'Book Titles', 'Last Collected At']);
        foreach ($student_rows as $row) {
            fputcsv($output, [
                $row['full_name'] ?? '',
                $row['index_number'] ?? '',
                intval($row['books_collected'] ?? 0),
                $row['books_list'] ?? '',
                $row['last_collected_at'] ?? '',
            ]);
        }
        fclose($output);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Rep View</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .page-container { max-width: 1100px; margin: 0 auto; }
        .page-header {
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(17, 24, 39, 0.28);
            gap: 12px;
            flex-wrap: wrap;
        }
        .page-header h1 { font-size: 22px; font-weight: 800; }
        .page-header .subtitle { opacity: 0.9; margin-top: 6px; font-size: 13px; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; }
        .btn {
            background: rgba(255,255,255,0.14);
            color: white;
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.25);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:hover { background: rgba(255,255,255,0.22); }
        .btn-dark {
            background: #111827;
            border-color: #111827;
            color: white;
        }
        .btn-dark:hover { background:#1f2937; }
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 14px;
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }
        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 20px;
        }
        .grid > div {
            display: flex;
            flex-direction: column;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        .stat-box {
            border-radius: 14px;
            padding: 16px;
            border: 1px solid #e2e8f0;
        }
        .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .value {
            font-size: 22px;
            font-weight: 800;
            color: #111827;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 16px;
            color: #111827;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        .muted {
            color: #6b7280;
            font-size: 13px;
        }
        .table-wrap { overflow: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            font-weight: 800;
            border-bottom: 2px solid #e9ecef;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            vertical-align: top;
        }
        .pill {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 12px;
            border: 1px solid transparent;
        }
        .pill-blue { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .pill-green { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
        .pill-orange { background:#fff7ed; color:#c2410c; border-color:#fdba74; }
        @media (max-width: 900px) {
            .grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            body { padding: 20px 12px; }
            .page-header { padding: 20px; }
            .stats-grid { grid-template-columns: 1fr; }
            th, td { white-space: nowrap; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <div>
            <h1>Rep Material View</h1>
            <div class="subtitle">
                Lecturer: <?php echo htmlspecialchars($lecturer_name); ?>
                <?php if ($lecturer_teaching_level !== ''): ?>
                    | Level <?php echo htmlspecialchars($lecturer_teaching_level); ?>
                <?php endif; ?>
                <?php if ($rep_row): ?>
                    | Rep: <?php echo htmlspecialchars(strval($rep_row['full_name'] ?? 'Rep')); ?>
                    <?php if (!empty($rep_row['class_name'])): ?>
                        (<?php echo htmlspecialchars(strval($rep_row['class_name'])); ?>)
                    <?php endif; ?>
                <?php endif; ?>
            </div>
                    <div class="subtitle"><?php echo htmlspecialchars($semester_label); ?></div>
        </div>
        <div class="actions">
            <a class="btn" href="lecturer_dashboard.php">Back to Dashboard</a>
            <?php if ($error_msg === ''): ?>
                <a class="btn" href="lecturer_rep_view.php?rep_id=<?php echo $rep_id; ?>&export=1">Download CSV</a>
            <?php endif; ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="logout" value="1" class="btn">Logout</button>
            </form>
        </div>
    </div>

    <?php if ($error_msg !== ''): ?>
        <div class="alert"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php else: ?>
        <div class="stats-grid">
            <div class="stat-box" style="background:#eff6ff; border-color:#bfdbfe;">
                <div class="label">Copies Given To Rep</div>
                <div class="value" style="color:#1d4ed8;"><?php echo number_format($rep_total_copies); ?></div>
            </div>
            <div class="stat-box" style="background:#ecfdf5; border-color:#a7f3d0;">
                <div class="label">Students Served</div>
                <div class="value" style="color:#047857;"><?php echo number_format($students_served); ?></div>
            </div>
            <div class="stat-box" style="background:#fff7ed; border-color:#fdba74;">
                <div class="label">Books Collected</div>
                <div class="value" style="color:#c2410c;"><?php echo number_format($books_collected_total); ?></div>
            </div>
            <div class="stat-box" style="background:#faf5ff; border-color:#d8b4fe;">
                <div class="label">Titles Involved</div>
                <div class="value" style="color:#7c3aed;"><?php echo number_format(count($book_breakdown)); ?></div>
            </div>
        </div>

        <div class="grid">
            <div>
                <div class="card">
                    <h2>Student Records</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Index Number</th>
                                    <th>Books</th>
                                    <th>Titles</th>
                                    <th>Last Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($student_rows) > 0): ?>
                                    <?php foreach ($student_rows as $row): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars(strval($row['full_name'] ?? '')); ?></strong></td>
                                            <td><?php echo htmlspecialchars(strval($row['index_number'] ?? '')); ?></td>
                                            <td><span class="pill pill-green"><?php echo number_format(intval($row['books_collected'] ?? 0)); ?></span></td>
                                            <td><?php echo htmlspecialchars(strval($row['books_list'] ?? '')); ?></td>
                                            <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime(strval($row['last_collected_at'])))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="muted">No students have collected this lecturer's materials through the selected rep yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div>
                <div class="card">
                    <h2>Material Breakdown</h2>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Book</th>
                                    <th>Given To Rep</th>
                                    <th>Collected By Students</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($book_breakdown) > 0): ?>
                                    <?php foreach ($book_breakdown as $book_row): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars(strval($book_row['book_title'] ?? '')); ?></strong></td>
                                            <td><span class="pill pill-blue"><?php echo number_format(intval($book_row['copies_given'] ?? 0)); ?></span></td>
                                            <td><span class="pill pill-orange"><?php echo number_format(intval($book_row['books_collected'] ?? 0)); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="muted">No lecturer-related activity has been recorded for this rep yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>

