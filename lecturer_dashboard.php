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

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: lecturer_dashboard.php?msg=csrf_invalid');
        exit;
    }
    session_destroy();
    header('Location: lecturer_login.php');
    exit;
}

$lecturer_id = intval($_SESSION['lecturer_id'] ?? 0);
$lecturer_name = $_SESSION['lecturer_full_name'] ?? $_SESSION['lecturer_username'] ?? 'Lecturer';
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$csrf_token = csrf_get_token();
$success_msg = '';
$error_msg = '';

// Ensure lecturer exists & active
$stmt = $conn->prepare("SELECT lecturer_id, full_name, teaching_level, is_active FROM lecturers WHERE lecturer_id = ? LIMIT 1");
$lecturer_row = null;
if ($stmt) {
    $stmt->bind_param('i', $lecturer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $lecturer_row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
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

function lecturer_dashboard_rep_matches_level(mysqli $conn, int $rep_admin_id, string $level_regex): bool {
    if ($rep_admin_id <= 0 || $level_regex === '') {
        return true;
    }

    $stmt = $conn->prepare("SELECT 1 FROM admins WHERE admin_id = ? AND role = 'rep' AND is_active = 1 AND COALESCE(class_name, '') REGEXP ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('is', $rep_admin_id, $level_regex);
    $stmt->execute();
    $res = $stmt->get_result();
    $is_match = ($res && $res->num_rows === 1);
    $stmt->close();

    return $is_match;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_material'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $material_title = substr(trim(strval($_POST['material_title'] ?? '')), 0, 100);
        $course_code = substr(trim(strval($_POST['course_code'] ?? '')), 0, 20);
        $course_code_key = function_exists('book_system_normalize_course_code')
            ? book_system_normalize_course_code($course_code)
            : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $course_code));

        if ($material_title === '' || $course_code_key === '') {
            $error_msg = 'Enter both the material name and course code.';
        } else {
            $stmt = $conn->prepare("INSERT INTO lecturer_materials (lecturer_id, material_title, course_code, course_code_key)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE material_title = VALUES(material_title), course_code = VALUES(course_code)");
            if ($stmt) {
                $stmt->bind_param('isss', $lecturer_id, $material_title, $course_code, $course_code_key);
                $stmt->execute();
                $success_msg = 'Course material added.';
            } else {
                $error_msg = 'Database error. Please try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_material'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $material_id = intval($_POST['material_id'] ?? 0);
        if ($material_id <= 0) {
            $error_msg = 'Invalid request.';
        } else {
            $cnt = $conn->prepare("SELECT COUNT(*) AS c
                FROM lecturer_distributions ld
                JOIN books b ON b.book_id = ld.book_id
                JOIN lecturer_materials lm ON lm.lecturer_id = ld.lecturer_id AND lm.course_code_key = COALESCE(b.course_code_key, '')
                WHERE lm.material_id = ? AND ld.lecturer_id = ? AND ld.semester_id = ?");
            $has_rows = 0;
            if ($cnt) {
                $cnt->bind_param('iii', $material_id, $lecturer_id, $semester_id);
                $cnt->execute();
                $cres = $cnt->get_result();
                if ($cres && $cres->num_rows === 1) {
                    $has_rows = intval($cres->fetch_assoc()['c'] ?? 0);
                }
            }

            if ($has_rows > 0) {
                $error_msg = 'You cannot remove this material because it already has distribution entries for this semester.';
            } else {
                $del = $conn->prepare("DELETE FROM lecturer_materials WHERE lecturer_id = ? AND material_id = ?");
                if ($del) {
                    $del->bind_param('ii', $lecturer_id, $material_id);
                    $del->execute();
                    $success_msg = 'Course material removed.';
                } else {
                    $error_msg = 'Database error. Please try again.';
                }
            }
        }
    }
}

// Record distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_distribution'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $book_id = intval($_POST['book_id'] ?? 0);
        $rep_admin_id = intval($_POST['rep_admin_id'] ?? 0);
        $copies_given = intval($_POST['copies_given'] ?? 0);
        $given_date = trim(strval($_POST['given_date'] ?? ''));
        $notes = trim(strval($_POST['notes'] ?? ''));

        if ($book_id <= 0 || $copies_given === 0 || $given_date === '') {
            $error_msg = 'Please fill in all required fields.';
        } else {
            // Validate book is assigned to this lecturer
            $ok = false;
            $chk = $conn->prepare("SELECT 1
                FROM books b
                WHERE b.book_id = ?
                  AND (
                    EXISTS (SELECT 1 FROM lecturer_books lb WHERE lb.lecturer_id = ? AND lb.book_id = b.book_id)
                    OR EXISTS (SELECT 1 FROM lecturer_materials lm WHERE lm.lecturer_id = ? AND lm.course_code_key <> '' AND lm.course_code_key = COALESCE(b.course_code_key, ''))
                  )
                LIMIT 1");
            if ($chk) {
                $chk->bind_param('iii', $book_id, $lecturer_id, $lecturer_id);
                $chk->execute();
                $cres = $chk->get_result();
                $ok = ($cres && $cres->num_rows === 1);
            }

            if (!$ok) {
                $error_msg = 'This book is not assigned to your account.';
            } elseif (!lecturer_dashboard_rep_matches_level($conn, $rep_admin_id, $lecturer_level_regex)) {
                $error_msg = 'You can only assign books to reps that match your teaching level.';
            } else {
                $ins = $conn->prepare("INSERT INTO lecturer_distributions (lecturer_id, book_id, rep_admin_id, copies_given, given_date, notes, semester_id) VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?)");
                if ($ins) {
                    $ins->bind_param('iiiissi', $lecturer_id, $book_id, $rep_admin_id, $copies_given, $given_date, $notes, $semester_id);
                    if ($ins->execute()) {
                        $success_msg = 'Distribution recorded successfully.';
                    } else {
                        $error_msg = 'Failed to record distribution.';
                    }
                } else {
                    $error_msg = 'Database error. Please try again.';
                }
            }
        }
    }
}

// Update distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_distribution'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $distribution_id = intval($_POST['distribution_id'] ?? 0);
        $book_id = intval($_POST['book_id'] ?? 0);
        $rep_admin_id = intval($_POST['rep_admin_id'] ?? 0);
        $copies_given = intval($_POST['copies_given'] ?? 0);
        $given_date = trim(strval($_POST['given_date'] ?? ''));
        $notes = trim(strval($_POST['notes'] ?? ''));

        if ($distribution_id <= 0 || $book_id <= 0 || $copies_given === 0 || $given_date === '') {
            $error_msg = 'Please fill in all required fields.';
        } else {
            $chk = $conn->prepare("SELECT 1 FROM lecturer_distributions WHERE distribution_id = ? AND lecturer_id = ? AND book_id = ? LIMIT 1");
            $ok = false;
            if ($chk) {
                $chk->bind_param('iii', $distribution_id, $lecturer_id, $book_id);
                $chk->execute();
                $cres = $chk->get_result();
                $ok = ($cres && $cres->num_rows === 1);
            }
            if (!$ok) {
                $error_msg = 'Unauthorized action.';
            } elseif (!lecturer_dashboard_rep_matches_level($conn, $rep_admin_id, $lecturer_level_regex)) {
                $error_msg = 'You can only assign books to reps that match your teaching level.';
            } else {
                $upd = $conn->prepare("UPDATE lecturer_distributions
                    SET rep_admin_id = NULLIF(?, 0), copies_given = ?, given_date = ?, notes = ?
                    WHERE distribution_id = ? AND lecturer_id = ?");
                if ($upd) {
                    $upd->bind_param('iissii', $rep_admin_id, $copies_given, $given_date, $notes, $distribution_id, $lecturer_id);
                    if ($upd->execute()) {
                        $success_msg = 'Entry updated.';
                    } else {
                        $error_msg = 'Failed to update entry.';
                    }
                } else {
                    $error_msg = 'Database error. Please try again.';
                }
            }
        }
    }
}

// Delete distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_distribution'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $distribution_id = intval($_POST['distribution_id'] ?? 0);
        $book_id = intval($_POST['book_id'] ?? 0);
        if ($distribution_id <= 0 || $book_id <= 0) {
            $error_msg = 'Invalid request.';
        } else {
            $del = $conn->prepare("DELETE FROM lecturer_distributions WHERE distribution_id = ? AND lecturer_id = ? AND book_id = ?");
            if ($del) {
                $del->bind_param('iii', $distribution_id, $lecturer_id, $book_id);
                $del->execute();
                if ($del->affected_rows === 1) {
                    $success_msg = 'Entry deleted.';
                } else {
                    $error_msg = 'Delete failed.';
                }
            } else {
                $error_msg = 'Database error. Please try again.';
            }
        }
    }
}

// Fetch assigned books + totals
$assigned_books_list = [];
$assigned_book_ids = [];
$registered_materials = [];
$stmt = $conn->prepare("SELECT material_id, material_title, course_code, course_code_key
    FROM lecturer_materials
    WHERE lecturer_id = ?
    ORDER BY material_title ASC, course_code ASC");
if ($stmt) {
    $stmt->bind_param('i', $lecturer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $registered_materials[] = $row;
        }
    }
}

$stmt = $conn->prepare("SELECT DISTINCT b.book_id, b.book_title, b.price, b.course_code
    FROM books b
    LEFT JOIN lecturer_books lb
        ON lb.book_id = b.book_id
       AND lb.lecturer_id = ?
    LEFT JOIN lecturer_materials lm
        ON lm.lecturer_id = ?
       AND lm.course_code_key <> ''
       AND lm.course_code_key = COALESCE(b.course_code_key, '')
    WHERE lb.lecturer_id IS NOT NULL OR lm.material_id IS NOT NULL
    ORDER BY b.book_title ASC");
if ($stmt) {
    $stmt->bind_param('ii', $lecturer_id, $lecturer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $assigned_books_list[] = $row;
            $assigned_book_ids[intval($row['book_id'])] = true;
        }
    }
}

$selected_book_id = intval($_GET['book_id'] ?? 0);
$selected_book_title = '';
if ($selected_book_id > 0) {
    $stmt = $conn->prepare("SELECT b.book_title
        FROM books b
        WHERE b.book_id = ?
          AND (
            EXISTS (SELECT 1 FROM lecturer_books lb WHERE lb.lecturer_id = ? AND lb.book_id = b.book_id)
            OR EXISTS (SELECT 1 FROM lecturer_materials lm WHERE lm.lecturer_id = ? AND lm.course_code_key <> '' AND lm.course_code_key = COALESCE(b.course_code_key, ''))
          )
        LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('iii', $selected_book_id, $lecturer_id, $lecturer_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $selected_book_title = strval($res->fetch_assoc()['book_title'] ?? '');
        } else {
            $selected_book_id = 0;
        }
    } else {
        $selected_book_id = 0;
    }
}

if (isset($_GET['export_collected']) && $selected_book_id > 0) {
    header('Content-Type: text/csv');
    $safe_title = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $selected_book_title !== '' ? $selected_book_title : ('book_' . $selected_book_id));
    header('Content-Disposition: attachment; filename=' . $safe_title . '_collected_students.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student Name', 'Index Number', 'Phone', 'Course Rep', 'Class', 'Collected At']);

    $stmt = $conn->prepare("SELECT
            s.full_name,
            s.index_number,
            s.phone,
            COALESCE(a.full_name, 'Unknown Rep') AS rep_name,
            COALESCE(a.class_name, '') AS rep_class,
            COALESCE(ri.received_at, r.created_at) AS collected_at
        FROM request_items ri
        JOIN requests r ON r.request_id = ri.request_id
        JOIN students s ON s.student_id = r.student_id
        LEFT JOIN admins a ON a.admin_id = r.admin_id
        WHERE ri.book_id = ? AND r.semester_id = ? AND ri.is_collected = 1
        ORDER BY COALESCE(ri.received_at, r.created_at) DESC, s.full_name ASC");
    if ($stmt) {
        $stmt->bind_param('ii', $selected_book_id, $semester_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            fputcsv($output, [
                $row['full_name'],
                $row['index_number'],
                $row['phone'],
                $row['rep_name'],
                $row['rep_class'],
                $row['collected_at'],
            ]);
        }
        $stmt->close();
    }

    fclose($output);
    exit;
}

$book_totals = [];
$stmt = $conn->prepare("SELECT book_id, COALESCE(SUM(copies_given), 0) AS total
    FROM lecturer_distributions
    WHERE lecturer_id = ? AND semester_id = ?
    GROUP BY book_id");
if ($stmt) {
    $stmt->bind_param('ii', $lecturer_id, $semester_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $book_totals[intval($r['book_id'])] = intval($r['total'] ?? 0);
        }
    }
}

$rep_totals = null;
$recent_distributions = null;
$collected_students = null;
$collected_students_count = 0;
if ($selected_book_id > 0) {
    $repTotalsSql = "SELECT a.admin_id, a.full_name, a.class_name, COALESCE(SUM(ld.copies_given), 0) AS copies
        FROM lecturer_distributions ld
        LEFT JOIN admins a ON a.admin_id = ld.rep_admin_id
        WHERE ld.lecturer_id = ? AND ld.book_id = ? AND ld.semester_id = ?";
    if ($lecturer_level_regex !== '') {
        $repTotalsSql .= " AND COALESCE(a.class_name, '') REGEXP ?";
    }
    $repTotalsSql .= "
        GROUP BY a.admin_id, a.full_name, a.class_name
        ORDER BY copies DESC, a.full_name ASC";
    $stmt = $conn->prepare($repTotalsSql);
    if ($stmt) {
        if ($lecturer_level_regex !== '') {
            $stmt->bind_param('iiis', $lecturer_id, $selected_book_id, $semester_id, $lecturer_level_regex);
        } else {
            $stmt->bind_param('iii', $lecturer_id, $selected_book_id, $semester_id);
        }
        $stmt->execute();
        $rep_totals = $stmt->get_result();
    }

    $recentDistributionsSql = "SELECT ld.distribution_id, ld.rep_admin_id, ld.copies_given, ld.given_date, ld.notes,
            a.full_name AS rep_name, a.class_name
        FROM lecturer_distributions ld
        LEFT JOIN admins a ON a.admin_id = ld.rep_admin_id
        WHERE ld.lecturer_id = ? AND ld.book_id = ? AND ld.semester_id = ?";
    if ($lecturer_level_regex !== '') {
        $recentDistributionsSql .= " AND (ld.rep_admin_id IS NULL OR COALESCE(a.class_name, '') REGEXP ?)";
    }
    $recentDistributionsSql .= "
        ORDER BY ld.given_date DESC, ld.distribution_id DESC
        LIMIT 30";
    $stmt = $conn->prepare($recentDistributionsSql);
    if ($stmt) {
        if ($lecturer_level_regex !== '') {
            $stmt->bind_param('iiis', $lecturer_id, $selected_book_id, $semester_id, $lecturer_level_regex);
        } else {
            $stmt->bind_param('iii', $lecturer_id, $selected_book_id, $semester_id);
        }
        $stmt->execute();
        $recent_distributions = $stmt->get_result();
    }

    $stmt = $conn->prepare("SELECT
            s.full_name,
            s.index_number,
            s.phone,
            COALESCE(a.full_name, 'Unknown Rep') AS rep_name,
            COALESCE(a.class_name, 'â€”') AS rep_class,
            COALESCE(ri.received_at, r.created_at) AS collected_at
        FROM request_items ri
        JOIN requests r ON r.request_id = ri.request_id
        JOIN students s ON s.student_id = r.student_id
        LEFT JOIN admins a ON a.admin_id = r.admin_id
        WHERE ri.book_id = ? AND r.semester_id = ? AND ri.is_collected = 1
        ORDER BY COALESCE(ri.received_at, r.created_at) DESC, s.full_name ASC
        LIMIT 300");
    if ($stmt) {
        $stmt->bind_param('ii', $selected_book_id, $semester_id);
        $stmt->execute();
        $collected_students = $stmt->get_result();
        if ($collected_students) {
            $collected_students_count = $collected_students->num_rows;
        }
    }
}

// Reps dropdown (for recording)
$reps = null;
$reps_list = [];
$repsSql = "SELECT admin_id, full_name, class_name FROM admins WHERE role = 'rep' AND is_active = 1";
if ($lecturer_level_regex !== '') {
    $repsSql .= " AND COALESCE(class_name, '') REGEXP ?";
}
$repsSql .= " ORDER BY full_name ASC";
$reps_stmt = $conn->prepare($repsSql);
if ($reps_stmt) {
    if ($lecturer_level_regex !== '') {
        $reps_stmt->bind_param('s', $lecturer_level_regex);
    }
    $reps_stmt->execute();
    $reps = $reps_stmt->get_result();
    if ($reps) {
        while ($r = $reps->fetch_assoc()) {
            $reps_list[] = $r;
        }
    }
}

$rep_distribution_rows = [];
$rep_distribution_totals = [];
$overall_copies_given = 0;

$repDistributionSql = "SELECT
        a.admin_id,
        a.full_name,
        a.class_name,
        COALESCE(SUM(ld.copies_given), 0) AS total_copies
    FROM admins a
    LEFT JOIN lecturer_distributions ld
       ON ld.rep_admin_id = a.admin_id
       AND ld.lecturer_id = ?
       AND ld.semester_id = ?
    WHERE a.role = 'rep' AND a.is_active = 1";
if ($lecturer_level_regex !== '') {
    $repDistributionSql .= " AND COALESCE(a.class_name, '') REGEXP ?";
}
$repDistributionSql .= "
    GROUP BY a.admin_id, a.full_name, a.class_name
    ORDER BY total_copies DESC, a.full_name ASC";
$repDistributionStmt = $conn->prepare($repDistributionSql);
if ($repDistributionStmt) {
    if ($lecturer_level_regex !== '') {
        $repDistributionStmt->bind_param('iis', $lecturer_id, $semester_id, $lecturer_level_regex);
    } else {
        $repDistributionStmt->bind_param('ii', $lecturer_id, $semester_id);
    }
    $repDistributionStmt->execute();
    $repDistributionRes = $repDistributionStmt->get_result();
    if ($repDistributionRes) {
        while ($row = $repDistributionRes->fetch_assoc()) {
            $rep_distribution_rows[] = $row;
            $rep_distribution_totals[intval($row['admin_id'])] = intval($row['total_copies'] ?? 0);
            $overall_copies_given += intval($row['total_copies'] ?? 0);
        }
    }
    $repDistributionStmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard</title>
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

        .grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 20px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }

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

        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }

        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-weight: 700; color: #555; margin-bottom: 8px; font-size: 13px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
        }
        .form-group textarea { min-height: 70px; resize: vertical; }

        .btn-primary {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
        }
        .btn-primary:hover { opacity: 0.95; }

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
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }

        .pill {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-weight: 800;
            font-size: 12px;
            border: 1px solid rgba(55,48,163,0.15);
        }
        .pill-green { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }
        .pill-orange { background:#fff7ed; color:#c2410c; border-color:#fdba74; }
        .pill-purple { background:#faf5ff; color:#7c3aed; border-color:#d8b4fe; }
        .pill-slate { background:#f1f5f9; color:#334155; border-color:#cbd5e1; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
        }
        .stat-box .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 800;
            margin-bottom: 6px;
        }
        .stat-box .value {
            font-size: 22px;
            font-weight: 800;
            color: #111827;
        }
        .muted {
            color: #6b7280;
            font-size: 13px;
        }
        .rep-list-table td:last-child,
        .rep-list-table th:last-child {
            text-align: right;
        }
        .rep-list-table td {
            vertical-align: middle;
        }
        .rep-list-table td:first-child strong {
            display: block;
            color: #111827;
        }
        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 560px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>Lecturer Portal</h1>
            <div class="subtitle">Welcome, <?php echo htmlspecialchars($lecturer_name); ?><?php echo $lecturer_teaching_level !== '' ? ' | Level ' . htmlspecialchars($lecturer_teaching_level) : ''; ?></div>
        </div>
        <div style="display:flex; gap: 10px; flex-wrap: wrap;">
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="logout" value="1" class="btn">Logout</button>
            </form>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="grid">
        <div>
            <div class="stats-grid">
                <div class="stat-box" style="background:#eff6ff; border-color:#bfdbfe;">
                    <div class="label">Assigned Books</div>
                    <div class="value" style="color:#1d4ed8;"><?php echo number_format(count($assigned_books_list)); ?></div>
                </div>
                <div class="stat-box" style="background:#ecfdf5; border-color:#a7f3d0;">
                    <div class="label">Total Copies Given</div>
                    <div class="value" style="color:#047857;"><?php echo number_format($overall_copies_given); ?></div>
                </div>
                <div class="stat-box" style="background:#fff7ed; border-color:#fdba74;">
                    <div class="label">Active Reps</div>
                    <div class="value" style="color:#c2410c;"><?php echo number_format(count($reps_list)); ?></div>
                </div>
                <div class="stat-box" style="background:#faf5ff; border-color:#d8b4fe;">
                    <div class="label">Reps Taking Books</div>
                    <div class="value" style="color:#7c3aed;"><?php echo number_format(count(array_filter($rep_distribution_totals, function ($value) { return intval($value) > 0; }))); ?></div>
                </div>
            </div>

            <div class="card">
                <h2>Assigned Books (This Semester)</h2>
                <div style="overflow:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Book</th>
                            <th>Copies Given</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($assigned_books_list) > 0): ?>
                            <?php foreach ($assigned_books_list as $b): ?>
                                <?php
                                    $bid = intval($b['book_id']);
                                    $given = intval($book_totals[$bid] ?? 0);
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($b['book_title']); ?></strong></td>
                                    <td><span class="pill"><?php echo number_format($given); ?></span></td>
                                    <td>
                                        <a class="btn" style="background:#111827; border-color:#111827;" href="?book_id=<?php echo $bid; ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="color:#666;">No books have been assigned to your account yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h2>Rep Overview</h2>
                <div class="stats-grid" style="margin-bottom:0;">
                    <div class="stat-box" style="background:#eff6ff; border-color:#bfdbfe;">
                        <div class="label">Total Reps Listed</div>
                        <div class="value" style="color:#1d4ed8;"><?php echo number_format(count($rep_distribution_rows)); ?></div>
                    </div>
                    <div class="stat-box" style="background:#ecfdf5; border-color:#a7f3d0;">
                        <div class="label">Copies Shared</div>
                        <div class="value" style="color:#047857;"><?php echo number_format($overall_copies_given); ?></div>
                    </div>
                    <div class="stat-box" style="background:#fff7ed; border-color:#fdba74;">
                        <div class="label">Reps With Stock</div>
                        <div class="value" style="color:#c2410c;"><?php echo number_format(count(array_filter($rep_distribution_totals, function ($value) { return intval($value) > 0; }))); ?></div>
                    </div>
                    <div class="stat-box" style="background:#faf5ff; border-color:#d8b4fe;">
                        <div class="label">Average Per Rep</div>
                        <div class="value" style="color:#7c3aed;"><?php echo number_format(count($rep_distribution_rows) > 0 ? ($overall_copies_given / count($rep_distribution_rows)) : 0, 1); ?></div>
                    </div>
                </div>
            </div>

            <?php if (false): ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                    <h2 style="margin-bottom:0;">Rep Activity for My Materials</h2>
                    <?php if ($selected_rep_id > 0 && $selected_rep_info): ?>
                        <a class="btn" style="background:#111827; border-color:#111827;" href="?rep_id=<?php echo intval($selected_rep_id); ?>&export_rep_activity=1">Download CSV</a>
                    <?php endif; ?>
                </div>

                <?php if ($selected_rep_info): ?>
                    <div class="muted" style="margin-bottom:14px;">
                        Viewing material activity for <strong><?php echo htmlspecialchars(strval($selected_rep_info['full_name'] ?? '')); ?></strong>
                        <?php if (!empty($selected_rep_info['class_name'])): ?>
                            (<?php echo htmlspecialchars(strval($selected_rep_info['class_name'])); ?>)
                        <?php endif; ?>.
                        This section shows only books assigned to your lecturer account.
                    </div>

                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="label">Students</div>
                            <div class="value"><?php echo number_format($selected_rep_metrics['students']); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="label">Copies Ordered</div>
                            <div class="value"><?php echo number_format($selected_rep_metrics['copies_ordered']); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="label">Copies Collected</div>
                            <div class="value"><?php echo number_format($selected_rep_metrics['copies_collected']); ?></div>
                        </div>
                        <div class="stat-box">
                            <div class="label">Ordered Value</div>
                            <div class="value">GH&#8373; <?php echo number_format($selected_rep_metrics['ordered_value'], 2); ?></div>
                        </div>
                    </div>

                    <div class="card" style="background:#f8fafc; border:1px solid #e2e8f0; box-shadow:none; padding:18px; margin-bottom:16px;">
                        <div class="label" style="font-size:11px; text-transform:uppercase; letter-spacing:0.04em; color:#64748b; font-weight:800; margin-bottom:6px;">Estimated Settled Value</div>
                        <div style="font-size:24px; font-weight:800; color:#111827;">GH&#8373; <?php echo number_format($selected_rep_metrics['settled_value'], 2); ?></div>
                        <div class="muted" style="margin-top:8px;">This is estimated from each request's recorded payment and credit ratio across the rep's orders that include your materials.</div>
                    </div>

                    <h2>Book-by-Book Activity</h2>
                    <div style="overflow:auto; margin-bottom:16px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Copies Ordered</th>
                                <th>Copies Collected</th>
                                <th>Ordered Value</th>
                                <th>Estimated Settled</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rep_book_activity && $rep_book_activity->num_rows > 0): ?>
                                <?php while ($rep_book = $rep_book_activity->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($rep_book['book_title']); ?></strong></td>
                                        <td><?php echo number_format(intval($rep_book['copies_ordered'] ?? 0)); ?></td>
                                        <td><?php echo number_format(intval($rep_book['copies_collected'] ?? 0)); ?></td>
                                        <td>GH&#8373; <?php echo number_format(floatval($rep_book['ordered_value'] ?? 0), 2); ?></td>
                                        <td>GH&#8373; <?php echo number_format(floatval($rep_book['settled_value'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="color:#666;">This rep has not recorded any purchases for your assigned materials yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <h2>Students Who Purchased My Materials</h2>
                    <div class="muted" style="margin-bottom:12px;"><?php echo number_format($rep_students_activity_count); ?> student-material record(s) found for this rep in the active semester.</div>
                    <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Index Number</th>
                                <th>Phone</th>
                                <th>Book</th>
                                <th>Payment</th>
                                <th>Collected</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rep_students_activity && $rep_students_activity->num_rows > 0): ?>
                                <?php while ($rep_student = $rep_students_activity->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($rep_student['full_name']); ?></strong>
                                            <div class="muted"><?php echo htmlspecialchars(date('M d, Y H:i', strtotime(strval($rep_student['activity_date'])))); ?></div>
                                        </td>
                                        <td><?php echo htmlspecialchars($rep_student['index_number']); ?></td>
                                        <td><?php echo htmlspecialchars($rep_student['phone'] ?: 'â€”'); ?></td>
                                        <td><?php echo htmlspecialchars($rep_student['book_title']); ?></td>
                                        <td>
                                            <strong><?php echo strtoupper(htmlspecialchars(strval($rep_student['payment_status']))); ?></strong>
                                            <div class="muted">GH&#8373; <?php echo number_format(floatval($rep_student['settled_item_value'] ?? 0), 2); ?> of GH&#8373; <?php echo number_format(floatval($rep_student['item_value'] ?? 0), 2); ?></div>
                                        </td>
                                        <td><?php echo intval($rep_student['is_collected'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="color:#666;">No student purchases for your materials were found for this rep.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="muted">Select any rep from the list on the right to see purchases, students, quantities, and payment progress for your assigned books.</div>
                <?php endif; ?>
            </div>

            <?php endif; ?>

            <?php if ($selected_book_id > 0): ?>
                <div class="card">
                    <h2>Distribution Summary for <?php echo htmlspecialchars($selected_book_title); ?></h2>

                    <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Course Rep</th>
                                <th>Class</th>
                                <th>Copies Given</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rep_totals && $rep_totals->num_rows > 0): ?>
                                <?php while ($r = $rep_totals->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['full_name'] ?: 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars($r['class_name'] ?: 'â€”'); ?></td>
                                        <td><strong><?php echo number_format(intval($r['copies'] ?? 0)); ?></strong></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="color:#666;">No distributions recorded yet for this book.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <div style="height: 16px;"></div>

                    <h2 style="border-bottom:none; padding-bottom:0; margin-bottom:10px;">Recent Entries</h2>
                    <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Rep</th>
                                <th>Copies</th>
                                <th>Notes</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_distributions && $recent_distributions->num_rows > 0): ?>
                                <?php while ($d = $recent_distributions->fetch_assoc()): ?>
                                    <?php $d_rep_id = intval($d['rep_admin_id'] ?? 0); ?>
                                    <tr>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="distribution_id" value="<?php echo intval($d['distribution_id']); ?>">
                                            <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                                            <td>
                                                <input type="date" name="given_date" value="<?php echo htmlspecialchars($d['given_date']); ?>" required>
                                            </td>
                                            <td>
                                                <select name="rep_admin_id">
                                                    <option value="0">-- Unknown --</option>
                                                    <?php foreach ($reps_list as $rr): ?>
                                                        <?php $rid = intval($rr['admin_id']); ?>
                                                        <option value="<?php echo $rid; ?>" <?php echo ($d_rep_id === $rid) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars(($rr['full_name'] ?? '') !== '' ? $rr['full_name'] : ($rr['class_name'] ?? 'Rep')); ?>
                                                            <?php if (!empty($rr['class_name'])): ?> (<?php echo htmlspecialchars($rr['class_name']); ?>)<?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" name="copies_given" step="1" value="<?php echo intval($d['copies_given'] ?? 0); ?>" required>
                                            </td>
                                            <td>
                                                <input type="text" name="notes" value="<?php echo htmlspecialchars($d['notes'] ?? ''); ?>">
                                            </td>
                                            <td>
                                                <button type="submit" name="update_distribution" value="1" class="btn" style="background:#111827; border-color:#111827;">Update</button>
                                                <button type="submit" name="delete_distribution" value="1" class="btn" style="background:#dc3545; border-color:#dc3545;" onclick="return confirm('Delete this entry?');">Delete</button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="color:#666;">No entries yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <?php if (false): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                        <h2 style="margin-bottom:0;">Students Who Collected This Book</h2>
                        <a class="btn" style="background:#111827; border-color:#111827;" href="?book_id=<?php echo intval($selected_book_id); ?>&export_collected=1">Download CSV</a>
                    </div>
                    <div style="color:#666; font-size:13px; margin-bottom:14px;">
                        This list updates automatically when reps mark this book as collected for a student.
                        <strong><?php echo number_format($collected_students_count); ?></strong> collected record(s) found for the active semester.
                    </div>

                    <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Index Number</th>
                                <th>Phone</th>
                                <th>Course Rep</th>
                                <th>Class</th>
                                <th>Collected At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($collected_students && $collected_students->num_rows > 0): ?>
                                <?php while ($student_row = $collected_students->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($student_row['full_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student_row['index_number']); ?></td>
                                        <td><?php echo htmlspecialchars($student_row['phone'] ?: 'â€”'); ?></td>
                                        <td><?php echo htmlspecialchars($student_row['rep_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student_row['rep_class']); ?></td>
                                        <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime(strval($student_row['collected_at'])))); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6" style="color:#666;">No students have been marked as collected for this book yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <h2>Course Reps</h2>
                <div style="overflow:auto;">
                <table class="rep-list-table">
                    <thead>
                        <tr>
                            <th>Rep Name</th>
                            <th>Class</th>
                            <th>Copies Taken</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($rep_distribution_rows) > 0): ?>
                            <?php foreach ($rep_distribution_rows as $rep): ?>
                                <?php $rep_id = intval($rep['admin_id']); ?>
                                <?php $rep_total = intval($rep_distribution_totals[$rep_id] ?? 0); ?>
                                <?php $pill_class = 'pill-slate'; ?>
                                <?php if ($rep_total >= 20): ?>
                                    <?php $pill_class = 'pill-purple'; ?>
                                <?php elseif ($rep_total >= 10): ?>
                                    <?php $pill_class = 'pill-orange'; ?>
                                <?php elseif ($rep_total > 0): ?>
                                    <?php $pill_class = 'pill-green'; ?>
                                <?php endif; ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars(($rep['full_name'] ?? '') !== '' ? $rep['full_name'] : 'Rep'); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($rep['class_name'] ?? '-'); ?></td>
                                    <td><span class="pill <?php echo $pill_class; ?>"><?php echo number_format($rep_total); ?></span></td>
                                    <td>
                                        <a class="btn" style="background:#111827; border-color:#111827;" href="lecturer_rep_view.php?rep_id=<?php echo $rep_id; ?>">View Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="color:#666;">No active reps found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h2>My Course Materials</h2>

                <form method="POST" style="margin-bottom: 14px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="add_material" value="1">

                    <div class="form-group">
                        <label>Material Name</label>
                        <input type="text" name="material_title" placeholder="e.g. Data Communication" required>
                    </div>

                    <div class="form-group">
                        <label>Course Code</label>
                        <input type="text" name="course_code" placeholder="e.g. EDC 211" required>
                    </div>

                    <button type="submit" class="btn-primary">Register Material</button>
                </form>

                <div style="overflow:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Course Code</th>
                            <th>Matched Books</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($registered_materials) > 0): ?>
                            <?php foreach ($registered_materials as $material): ?>
                                <?php
                                    $matched_count = 0;
                                    foreach ($assigned_books_list as $matched_book) {
                                        $matched_code_key = function_exists('book_system_normalize_course_code')
                                            ? book_system_normalize_course_code($matched_book['course_code'] ?? '')
                                            : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', strval($matched_book['course_code'] ?? '')));
                                        if ($matched_code_key === strval($material['course_code_key'] ?? '')) {
                                            $matched_count++;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($material['material_title']); ?></td>
                                    <td><?php echo htmlspecialchars($material['course_code']); ?></td>
                                    <td><?php echo number_format($matched_count); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this material?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="remove_material" value="1">
                                            <input type="hidden" name="material_id" value="<?php echo intval($material['material_id']); ?>">
                                            <button type="submit" class="btn" style="background:#dc3545; border-color:#dc3545;">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" style="color:#666;">No materials registered yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h2>Record Books Given to Course Rep</h2>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="record_distribution" value="1">

                    <div class="form-group">
                        <label>Book *</label>
                        <select name="book_id" required>
                            <option value="">-- Select assigned book --</option>
                            <?php foreach ($assigned_books_list as $b): ?>
                                <?php $bid = intval($b['book_id']); ?>
                                <option value="<?php echo $bid; ?>" <?php echo ($selected_book_id === $bid) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['book_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Course Rep (Optional)</label>
                        <select name="rep_admin_id">
                            <option value="0">-- Unknown / Not selected --</option>
                            <?php foreach ($reps_list as $r): ?>
                                <option value="<?php echo intval($r['admin_id']); ?>">
                                    <?php echo htmlspecialchars(($r['full_name'] ?? '') !== '' ? $r['full_name'] : ($r['class_name'] ?? 'Rep')); ?>
                                    <?php if (!empty($r['class_name'])): ?> (<?php echo htmlspecialchars($r['class_name']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Copies Given *</label>
                        <input type="number" name="copies_given" step="1" required placeholder="e.g. 50 (use -50 to correct)">
                    </div>

                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="given_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" placeholder="e.g. First batch for Data Com"></textarea>
                    </div>

                    <button type="submit" class="btn-primary">Record Distribution</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>

