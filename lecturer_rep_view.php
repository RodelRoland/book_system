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
        book_system_setup_ensure_column($conn, 'lecturer_materials', 'academic_level', "VARCHAR(10) NOT NULL DEFAULT '' AFTER course_code_key");
    }
}
$conn->query("CREATE TABLE IF NOT EXISTS lecturer_materials (
    material_id INT AUTO_INCREMENT PRIMARY KEY,
    lecturer_id INT NOT NULL,
    material_title VARCHAR(100) NOT NULL,
    course_code VARCHAR(20) NOT NULL,
    course_code_key VARCHAR(20) NOT NULL,
    academic_level VARCHAR(10) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lecturer_material_code (lecturer_id, course_code_key, academic_level)
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

function lecturer_rep_view_level_regex(string $level): string {
    if ($level === '') {
        return '';
    }
    return function_exists('book_system_build_level_regex')
        ? strval(book_system_build_level_regex($level))
        : '(^|[^0-9])' . preg_quote($level, '/') . '([^0-9]|$)';
}

function lecturer_rep_view_class_matches_any_level(string $class_name, array $level_regexes): bool {
    if (!$level_regexes) {
        return true;
    }
    foreach ($level_regexes as $regex) {
        if ($regex !== '' && @preg_match('/' . $regex . '/i', $class_name)) {
            return true;
        }
    }
    return false;
}

$assignment_levels = [];
$assignment_level_regexes = [];
$selected_assignment_id = intval($_GET['assignment_id'] ?? 0);
$selected_assignment = null;
$assignment_book_ids = [];

$assignment_stmt = $conn->prepare("SELECT material_id, material_title, course_code, course_code_key, academic_level
    FROM lecturer_materials
    WHERE lecturer_id = ?
    ORDER BY academic_level ASC, material_title ASC");
if ($assignment_stmt) {
    $assignment_stmt->bind_param('i', $lecturer_id);
    $assignment_stmt->execute();
    $assignment_result = $assignment_stmt->get_result();
    while ($assignment_result && ($assignment_row = $assignment_result->fetch_assoc())) {
        $assignment_row['academic_level'] = function_exists('book_system_normalize_teaching_level')
            ? book_system_normalize_teaching_level($assignment_row['academic_level'] ?? '')
            : preg_replace('/[^0-9]/', '', strval($assignment_row['academic_level'] ?? ''));
        if ($assignment_row['academic_level'] === '') {
            $assignment_row['academic_level'] = $lecturer_teaching_level;
        }
        if ($assignment_row['academic_level'] !== '') {
            $assignment_levels[$assignment_row['academic_level']] = true;
        }
        if ($selected_assignment_id > 0 && intval($assignment_row['material_id']) === $selected_assignment_id) {
            $selected_assignment = $assignment_row;
        }
    }
    $assignment_stmt->close();
}
if (!$assignment_levels && $lecturer_teaching_level !== '') {
    $assignment_levels[$lecturer_teaching_level] = true;
}
foreach (array_keys($assignment_levels) as $assignment_level) {
    $regex = lecturer_rep_view_level_regex($assignment_level);
    if ($regex !== '') {
        $assignment_level_regexes[$assignment_level] = $regex;
    }
}
if ($selected_assignment && strval($selected_assignment['course_code_key'] ?? '') !== '') {
    $book_id_stmt = $conn->prepare("SELECT book_id FROM books WHERE COALESCE(course_code_key, '') = ? ORDER BY book_title ASC");
    if ($book_id_stmt) {
        $course_code_key = strval($selected_assignment['course_code_key']);
        $book_id_stmt->bind_param('s', $course_code_key);
        $book_id_stmt->execute();
        $book_id_result = $book_id_stmt->get_result();
        while ($book_id_result && ($book_id_row = $book_id_result->fetch_assoc())) {
            $assignment_book_ids[] = intval($book_id_row['book_id'] ?? 0);
        }
        $book_id_stmt->close();
    }
}
$active_level_regexes = $assignment_level_regexes;
if ($selected_assignment) {
    $selected_level = strval($selected_assignment['academic_level'] ?? '');
    $selected_regex = lecturer_rep_view_level_regex($selected_level);
    $active_level_regexes = $selected_regex !== '' ? [$selected_level => $selected_regex] : [];
}

$rep_row = null;
if ($rep_id <= 0) {
    $error_msg = 'No rep was selected.';
} else {
    $repSql = "SELECT admin_id, full_name, class_name FROM admins WHERE admin_id = ? AND role = 'rep' AND is_active = 1 LIMIT 1";
    $rep_stmt = $conn->prepare($repSql);
    if ($rep_stmt) {
        $rep_stmt->bind_param('i', $rep_id);
        $rep_stmt->execute();
        $rep_result = $rep_stmt->get_result();
        $rep_row = ($rep_result && $rep_result->num_rows === 1) ? $rep_result->fetch_assoc() : null;
        $rep_stmt->close();
    }

    if (!$rep_row || !lecturer_rep_view_class_matches_any_level(strval($rep_row['class_name'] ?? ''), $active_level_regexes)) {
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
    if ($selected_assignment && !$assignment_book_ids) {
        $book_breakdown = [];
        $student_rows = [];
    } else {
    $total_sql = "SELECT COALESCE(SUM(ld.copies_given), 0) AS total_copies
        FROM lecturer_distributions ld";
    if ($selected_assignment && $assignment_book_ids) {
        $total_sql .= " WHERE ld.lecturer_id = ? AND ld.rep_admin_id = ? AND ld.semester_id = ? AND ld.book_id IN (" . implode(',', array_fill(0, count($assignment_book_ids), '?')) . ")";
    } else {
        $total_sql .= " WHERE ld.lecturer_id = ? AND ld.rep_admin_id = ? AND ld.semester_id = ?";
    }
    $total_stmt = $conn->prepare($total_sql);
    if ($total_stmt) {
        if ($selected_assignment && $assignment_book_ids) {
            $types = 'iii' . str_repeat('i', count($assignment_book_ids));
            $params = array_merge([$lecturer_id, $rep_id, $semester_id], $assignment_book_ids);
            $total_stmt->bind_param($types, ...$params);
        } else {
            $total_stmt->bind_param('iii', $lecturer_id, $rep_id, $semester_id);
        }
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        if ($total_result && $total_result->num_rows === 1) {
            $rep_total_copies = intval($total_result->fetch_assoc()['total_copies'] ?? 0);
        }
        $total_stmt->close();
    }

    $distribution_sql = "SELECT
            ld.book_id,
            b.book_title,
            COALESCE(SUM(ld.copies_given), 0) AS copies_given
        FROM lecturer_distributions ld
        JOIN books b ON b.book_id = ld.book_id
        WHERE ld.lecturer_id = ? AND ld.rep_admin_id = ? AND ld.semester_id = ?";
    if ($selected_assignment && $assignment_book_ids) {
        $distribution_sql .= " AND ld.book_id IN (" . implode(',', array_fill(0, count($assignment_book_ids), '?')) . ")";
    }
    $distribution_sql .= "
        GROUP BY ld.book_id, b.book_title
        ORDER BY copies_given DESC, b.book_title ASC";
    $distribution_stmt = $conn->prepare($distribution_sql);
    if ($distribution_stmt) {
        if ($selected_assignment && $assignment_book_ids) {
            $types = 'iii' . str_repeat('i', count($assignment_book_ids));
            $params = array_merge([$lecturer_id, $rep_id, $semester_id], $assignment_book_ids);
            $distribution_stmt->bind_param($types, ...$params);
        } else {
            $distribution_stmt->bind_param('iii', $lecturer_id, $rep_id, $semester_id);
        }
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

    $book_sql = "SELECT
            b.book_id,
            b.book_title,
            COUNT(*) AS books_collected
        FROM books b
        JOIN request_items ri ON ri.book_id = b.book_id AND ri.is_collected = 1
        JOIN requests r ON r.request_id = ri.request_id AND r.admin_id = ? AND r.semester_id = ?
        WHERE (
            EXISTS (SELECT 1 FROM lecturer_books lb WHERE lb.lecturer_id = ? AND lb.book_id = b.book_id)
            OR EXISTS (SELECT 1 FROM lecturer_materials lm WHERE lm.lecturer_id = ? AND lm.course_code_key <> '' AND lm.course_code_key = COALESCE(b.course_code_key, ''))
        )";
    if ($selected_assignment && strval($selected_assignment['course_code_key'] ?? '') !== '') {
        $book_sql .= " AND COALESCE(b.course_code_key, '') = ?";
    }
    $book_sql .= "
        GROUP BY b.book_id, b.book_title
        ORDER BY books_collected DESC, b.book_title ASC";
    $book_stmt = $conn->prepare($book_sql);
    if ($book_stmt) {
        if ($selected_assignment && strval($selected_assignment['course_code_key'] ?? '') !== '') {
            $course_code_key = strval($selected_assignment['course_code_key']);
            $book_stmt->bind_param('iiiis', $rep_id, $semester_id, $lecturer_id, $lecturer_id, $course_code_key);
        } else {
            $book_stmt->bind_param('iiii', $rep_id, $semester_id, $lecturer_id, $lecturer_id);
        }
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

    $student_sql = "SELECT
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
        )";
    if ($selected_assignment && strval($selected_assignment['course_code_key'] ?? '') !== '') {
        $student_sql .= " AND COALESCE(b.course_code_key, '') = ?";
    }
    $student_sql .= "
        GROUP BY s.student_id, s.full_name, s.index_number
        ORDER BY last_collected_at DESC, s.full_name ASC";
    $student_stmt = $conn->prepare($student_sql);
    if ($student_stmt) {
        if ($selected_assignment && strval($selected_assignment['course_code_key'] ?? '') !== '') {
            $course_code_key = strval($selected_assignment['course_code_key']);
            $student_stmt->bind_param('iiiis', $rep_id, $semester_id, $lecturer_id, $lecturer_id, $course_code_key);
        } else {
            $student_stmt->bind_param('iiii', $rep_id, $semester_id, $lecturer_id, $lecturer_id);
        }
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
        :root {
            --page-bg: #eef3f8;
            --surface: rgba(255, 255, 255, 0.97);
            --ink: #10213a;
            --muted: #5f6f86;
            --line: #dbe5f0;
            --hero-start: #173b72;
            --hero-end: #5a6ac9;
            --shadow: 0 24px 56px rgba(15, 23, 42, 0.08);
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(90, 106, 201, 0.15), transparent 22%),
                linear-gradient(180deg, #f8fbff 0%, var(--page-bg) 100%);
            min-height: 100vh;
            padding: 30px 20px 40px;
            color: var(--ink);
        }
        .page-container { max-width: 1320px; margin: 0 auto; }
        .page-header {
            background: linear-gradient(135deg, var(--hero-start) 0%, var(--hero-end) 100%);
            color: white;
            padding: 28px 32px;
            border-radius: 28px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            box-shadow: 0 26px 60px rgba(23, 59, 114, 0.28);
            gap: 16px;
            flex-wrap: wrap;
            overflow: hidden;
            position: relative;
        }
        .page-header::after {
            content: "";
            position: absolute;
            top: -80px;
            right: -36px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }
        .header-copy,
        .actions {
            position: relative;
            z-index: 1;
        }
        .page-header h1 {
            font-size: 30px;
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: -0.04em;
            margin-bottom: 10px;
        }
        .page-header .subtitle {
            color: rgba(241, 245, 249, 0.94);
            margin-top: 6px;
            font-size: 14px;
            line-height: 1.6;
            max-width: 760px;
        }
        .hero-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }
        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #f8fbff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .btn {
            appearance: none;
            background: rgba(255,255,255,0.14);
            color: white;
            padding: 11px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.24);
            transition: transform 0.18s ease, background 0.18s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            font-size: 13px;
        }
        .btn:hover { background: rgba(255,255,255,0.22); transform: translateY(-1px); }
        .btn-dark {
            background: #17253d;
            border-color: #17253d;
            color: white;
        }
        .btn-dark:hover { background:#20314f; }
        .alert {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            background: #fff0f1;
            color: #b42318;
            border: 1px solid #fecaca;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .stat-box {
            border-radius: 22px;
            padding: 18px;
            border: 1px solid var(--line);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.06);
        }
        .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            font-weight: 800;
            margin-bottom: 8px;
        }
        .value {
            font-size: 30px;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -0.04em;
            color: var(--ink);
            margin-bottom: 6px;
        }
        .stat-hint {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }
        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.22fr) minmax(320px, 0.78fr);
            gap: 22px;
            align-items: start;
        }
        .grid > div {
            display: flex;
            flex-direction: column;
            gap: 22px;
            min-width: 0;
        }
        .card {
            background: var(--surface);
            border-radius: 26px;
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(219, 229, 240, 0.95);
        }
        .focus-banner {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            flex-wrap: wrap;
            padding: 18px 20px;
            border-radius: 20px;
            background: linear-gradient(135deg, #f4f7ff 0%, #edf8ff 100%);
            border: 1px solid #d6e5ff;
            margin-bottom: 18px;
        }
        .focus-banner strong {
            display: block;
            color: var(--ink);
            font-size: 17px;
            margin-bottom: 5px;
            letter-spacing: -0.02em;
        }
        .focus-banner .muted {
            max-width: 760px;
        }
        .card h2 {
            font-size: 19px;
            color: var(--ink);
            margin-bottom: 10px;
            letter-spacing: -0.03em;
        }
        .muted,
        .section-note {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }
        .table-wrap { overflow: auto; margin-top: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f9fbfe;
            padding: 13px 14px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7b92;
            font-weight: 800;
            border-bottom: 1px solid #dfe7f0;
            white-space: nowrap;
        }
        td {
            padding: 15px 14px;
            border-bottom: 1px solid #ebf0f6;
            font-size: 14px;
            vertical-align: top;
            color: #243449;
        }
        tbody tr:hover { background: #fbfdff; }
        .table-title {
            font-weight: 700;
            color: var(--ink);
        }
        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 46px;
            padding: 7px 12px;
            border-radius: 999px;
            font-weight: 800;
            font-size: 12px;
            border: 1px solid transparent;
        }
        .pill-blue { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .pill-green { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
        .pill-orange { background:#fff7ed; color:#c2410c; border-color:#fdba74; }
        @media (max-width: 980px) {
            .grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            body { padding: 20px 12px 28px; }
            .page-header { padding: 24px 20px; border-radius: 22px; }
            .page-header h1 { font-size: 26px; }
            .stats-grid { grid-template-columns: 1fr; }
            .card { padding: 18px; border-radius: 20px; }
            th, td { white-space: nowrap; }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="page-header">
        <div class="header-copy">
            <h1>Rep Material View</h1>
            <div class="subtitle">A focused view of one rep’s activity for the books and materials attached to your lecturer account.</div>
            <div class="hero-meta">
                <span class="hero-chip"><?php echo htmlspecialchars($lecturer_name); ?></span>
                <?php if ($selected_assignment): ?>
                    <span class="hero-chip">Level <?php echo htmlspecialchars(strval($selected_assignment['academic_level'] ?? '')); ?></span>
                <?php elseif ($lecturer_teaching_level !== ''): ?>
                    <span class="hero-chip">Level <?php echo htmlspecialchars($lecturer_teaching_level); ?></span>
                <?php endif; ?>
                <?php if ($rep_row): ?>
                    <span class="hero-chip"><?php echo htmlspecialchars(strval($rep_row['full_name'] ?? 'Rep')); ?><?php if (!empty($rep_row['class_name'])): ?> • <?php echo htmlspecialchars(strval($rep_row['class_name'])); ?><?php endif; ?></span>
                <?php endif; ?>
                <?php if ($selected_assignment): ?>
                    <span class="hero-chip"><?php echo htmlspecialchars(strval($selected_assignment['material_title'] ?? '')); ?> • <?php echo htmlspecialchars(strval($selected_assignment['course_code'] ?? '')); ?></span>
                <?php endif; ?>
                <span class="hero-chip"><?php echo htmlspecialchars($semester_label); ?></span>
            </div>
        </div>
        <div class="actions">
            <a class="btn" href="lecturer_dashboard.php<?php echo $selected_assignment ? '?assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>">Back to Dashboard</a>
            <?php if ($error_msg === ''): ?>
                <a class="btn" href="lecturer_rep_view.php?rep_id=<?php echo $rep_id; ?><?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>&export=1">Download CSV</a>
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
        <?php if ($selected_assignment): ?>
            <div class="focus-banner">
                <div>
                    <strong><?php echo htmlspecialchars(strval($selected_assignment['material_title'] ?? '')); ?></strong>
                    <div class="muted">
                        This rep view is currently locked to <strong><?php echo htmlspecialchars(strval($selected_assignment['course_code'] ?? '')); ?></strong>
                        for <strong>Level <?php echo htmlspecialchars(strval($selected_assignment['academic_level'] ?? '')); ?></strong>,
                        so all student and book records below belong only to that teaching assignment.
                    </div>
                </div>
                <a class="btn btn-dark" href="lecturer_rep_view.php?rep_id=<?php echo $rep_id; ?>">View Full Rep Scope</a>
            </div>
        <?php endif; ?>
        <div class="stats-grid">
            <div class="stat-box" style="background:#eff6ff; border-color:#bfdbfe;">
                <div class="label">Copies Given To Rep</div>
                <div class="value" style="color:#1d4ed8;"><?php echo number_format($rep_total_copies); ?></div>
                <div class="stat-hint">Total quantity you have released to this rep.</div>
            </div>
            <div class="stat-box" style="background:#ecfdf5; border-color:#a7f3d0;">
                <div class="label">Students Served</div>
                <div class="value" style="color:#047857;"><?php echo number_format($students_served); ?></div>
                <div class="stat-hint">Students reached through this rep for your materials.</div>
            </div>
            <div class="stat-box" style="background:#fff7ed; border-color:#fdba74;">
                <div class="label">Books Collected</div>
                <div class="value" style="color:#c2410c;"><?php echo number_format($books_collected_total); ?></div>
                <div class="stat-hint">Total units collected by students from this rep.</div>
            </div>
            <div class="stat-box" style="background:#faf5ff; border-color:#d8b4fe;">
                <div class="label">Titles Involved</div>
                <div class="value" style="color:#7c3aed;"><?php echo number_format(count($book_breakdown)); ?></div>
                <div class="stat-hint">Distinct titles currently tied to this rep view.</div>
            </div>
        </div>

        <div class="grid">
            <div>
                <div class="card">
                    <h2>Student Records</h2>
                    <div class="section-note">Each row shows the student, the count of your titles collected, and the latest collection time recorded under this rep<?php echo $selected_assignment ? ' for the selected assignment' : ''; ?>.</div>
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
                                            <td><strong class="table-title"><?php echo htmlspecialchars(strval($row['full_name'] ?? '')); ?></strong></td>
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
                    <div class="section-note">Compare what you issued to the rep against what students have already collected<?php echo $selected_assignment ? ' for this assignment' : ''; ?>.</div>
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
                                            <td><strong class="table-title"><?php echo htmlspecialchars(strval($book_row['book_title'] ?? '')); ?></strong></td>
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

