<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_level', 'VARCHAR(10) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_levels', 'TEXT NULL AFTER teaching_level');
        book_system_setup_ensure_column($conn, 'lecturers', 'course_codes', 'TEXT NULL AFTER teaching_levels');
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
$semester_label = isset($ACTIVE_SEMESTER_LABEL) ? strval($ACTIVE_SEMESTER_LABEL) : (isset($ACTIVE_SEMESTER_NAME) ? strval($ACTIVE_SEMESTER_NAME) : 'Active Semester');

$csrf_token = csrf_get_token();
$success_msg = '';
$error_msg = '';

// Ensure lecturer exists & active
$stmt = $conn->prepare("SELECT lecturer_id, full_name, teaching_level, teaching_levels, course_codes, is_active FROM lecturers WHERE lecturer_id = ? LIMIT 1");
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
$lecturer_teaching_levels = [];
$lecturer_teaching_levels_raw = json_decode(strval($lecturer_row['teaching_levels'] ?? ''), true);
if (is_array($lecturer_teaching_levels_raw)) {
    foreach ($lecturer_teaching_levels_raw as $level_value) {
        $normalized_level = function_exists('book_system_normalize_teaching_level')
            ? book_system_normalize_teaching_level(strval($level_value))
            : preg_replace('/[^0-9]/', '', strval($level_value));
        if ($normalized_level !== '' && !in_array($normalized_level, $lecturer_teaching_levels, true)) {
            $lecturer_teaching_levels[] = $normalized_level;
        }
    }
}
if (!$lecturer_teaching_levels && $lecturer_teaching_level !== '') {
    $lecturer_teaching_levels[] = $lecturer_teaching_level;
}

function lecturer_dashboard_level_regex(string $level): string {
    if ($level === '') {
        return '';
    }

    return function_exists('book_system_build_level_regex')
        ? strval(book_system_build_level_regex($level))
        : '(^|[^0-9])' . preg_quote($level, '/') . '([^0-9]|$)';
}

function lecturer_dashboard_class_matches_any_level(string $class_name, array $level_regexes): bool {
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

function lecturer_dashboard_rep_matches_levels(mysqli $conn, int $rep_admin_id, array $level_regexes): bool {
    if ($rep_admin_id <= 0 || !$level_regexes) {
        return true;
    }

    $stmt = $conn->prepare("SELECT 1 FROM admins WHERE admin_id = ? AND role = 'rep' AND is_active = 1 AND COALESCE(class_name, '') REGEXP ? LIMIT 1");
    if ($stmt) {
        foreach ($level_regexes as $regex) {
            $stmt->bind_param('is', $rep_admin_id, $regex);
            $stmt->execute();
            $res = $stmt->get_result();
            $is_match = ($res && $res->num_rows === 1);
            if ($is_match) {
                $stmt->close();
                return true;
            }
        }
        $stmt->close();
    }

    return false;
}

$lecturer_assignment_levels = $lecturer_teaching_levels;
$lecturer_level_regexes = [];
foreach ($lecturer_assignment_levels as $assignment_level) {
    $regex = lecturer_dashboard_level_regex($assignment_level);
    if ($regex !== '') {
        $lecturer_level_regexes[$assignment_level] = $regex;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_material'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $material_title = substr(trim(strval($_POST['material_title'] ?? '')), 0, 100);
        $course_code = substr(trim(strval($_POST['course_code'] ?? '')), 0, 20);
        $academic_level = function_exists('book_system_normalize_teaching_level')
            ? book_system_normalize_teaching_level($_POST['academic_level'] ?? '')
            : preg_replace('/[^0-9]/', '', strval($_POST['academic_level'] ?? ''));
        $course_code_key = function_exists('book_system_normalize_course_code')
            ? book_system_normalize_course_code($course_code)
            : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $course_code));

        if ($material_title === '' || $course_code_key === '' || $academic_level === '') {
            $error_msg = 'Enter the course title, course code, and academic level.';
        } else {
            $stmt = $conn->prepare("INSERT INTO lecturer_materials (lecturer_id, material_title, course_code, course_code_key, academic_level)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE material_title = VALUES(material_title), course_code = VALUES(course_code), academic_level = VALUES(academic_level)");
            if ($stmt) {
                $stmt->bind_param('issss', $lecturer_id, $material_title, $course_code, $course_code_key, $academic_level);
                $stmt->execute();
                $success_msg = 'Teaching assignment added.';
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
            } elseif (!lecturer_dashboard_rep_matches_levels($conn, $rep_admin_id, $lecturer_level_regexes ?? [])) {
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
            } elseif (!lecturer_dashboard_rep_matches_levels($conn, $rep_admin_id, $lecturer_level_regexes ?? [])) {
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
$assignment_summaries = [];
$selected_assignment_id = intval($_POST['selected_assignment_id'] ?? ($_GET['assignment_id'] ?? 0));
$selected_assignment = null;
$rep_id = intval($_GET['rep_id'] ?? 0);

$stmt = $conn->prepare("SELECT material_id, material_title, course_code, course_code_key, academic_level
    FROM lecturer_materials
    WHERE lecturer_id = ?
    ORDER BY academic_level ASC, material_title ASC, course_code ASC");
if ($stmt) {
    $stmt->bind_param('i', $lecturer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['academic_level'] = function_exists('book_system_normalize_teaching_level')
                ? book_system_normalize_teaching_level($row['academic_level'] ?? '')
                : preg_replace('/[^0-9]/', '', strval($row['academic_level'] ?? ''));
            if ($row['academic_level'] === '') {
                $row['academic_level'] = $lecturer_teaching_level;
            }
            $registered_materials[] = $row;
            if ($selected_assignment_id > 0 && intval($row['material_id']) === $selected_assignment_id) {
                $selected_assignment = $row;
            }
        }
    }
}

$assignment_levels = [];
foreach ($registered_materials as $material_row) {
    $assignment_level = strval($material_row['academic_level'] ?? '');
    if ($assignment_level !== '') {
        $assignment_levels[$assignment_level] = true;
    }
}
if (!$assignment_levels && $lecturer_teaching_level !== '') {
    $assignment_levels[$lecturer_teaching_level] = true;
}
$lecturer_assignment_levels = array_keys($assignment_levels);
$lecturer_level_regexes = [];
foreach ($lecturer_assignment_levels as $assignment_level) {
    $regex = lecturer_dashboard_level_regex($assignment_level);
    if ($regex !== '') {
        $lecturer_level_regexes[$assignment_level] = $regex;
    }
}
$lecturer_levels_label = $lecturer_assignment_levels ? implode(', ', array_map(static function ($level) {
    return 'Level ' . $level;
}, $lecturer_assignment_levels)) : 'All levels';

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

$matched_books_by_code = [];
foreach ($assigned_books_list as $assigned_book) {
    $code_key = function_exists('book_system_normalize_course_code')
        ? book_system_normalize_course_code($assigned_book['course_code'] ?? '')
        : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', strval($assigned_book['course_code'] ?? '')));
    if ($code_key === '') {
        continue;
    }
    if (!isset($matched_books_by_code[$code_key])) {
        $matched_books_by_code[$code_key] = [];
    }
    $matched_books_by_code[$code_key][] = $assigned_book;
}

$assignment_book_ids = [];
foreach ($registered_materials as $material_row) {
    $material_id = intval($material_row['material_id']);
    $course_code_key = strval($material_row['course_code_key'] ?? '');
    $matched_books = $matched_books_by_code[$course_code_key] ?? [];
    $matched_count = count($matched_books);
    $book_ids = array_map(static function ($book_row) {
        return intval($book_row['book_id'] ?? 0);
    }, $matched_books);
    $assignment_book_ids[$material_id] = array_values(array_filter($book_ids));
    $assignment_summaries[$material_id] = [
        'material_id' => $material_id,
        'material_title' => strval($material_row['material_title'] ?? ''),
        'course_code' => strval($material_row['course_code'] ?? ''),
        'course_code_key' => $course_code_key,
        'academic_level' => strval($material_row['academic_level'] ?? ''),
        'matched_books_count' => $matched_count,
        'matched_book_ids' => $assignment_book_ids[$material_id],
    ];
}

$selected_assignment_book_ids = [];
if ($selected_assignment) {
    $selected_assignment_book_ids = $assignment_book_ids[intval($selected_assignment['material_id'])] ?? [];
} else {
    $selected_assignment_id = 0;
}

$active_level_regexes = $lecturer_level_regexes;
if ($selected_assignment) {
    $selected_level = strval($selected_assignment['academic_level'] ?? '');
    $selected_level_regex = lecturer_dashboard_level_regex($selected_level);
    $active_level_regexes = $selected_level_regex !== '' ? [$selected_level => $selected_level_regex] : [];
}
$active_levels_label = $selected_assignment
    ? ('Level ' . strval($selected_assignment['academic_level'] ?? ''))
    : $lecturer_levels_label;

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
            if ($selected_assignment && !in_array($selected_book_id, $selected_assignment_book_ids, true)) {
                $selected_book_id = 0;
                $selected_book_title = '';
            }
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
            r.admin_id AS rep_admin_id,
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
            $rep_class = strval($row['rep_class'] ?? '');
            if (!lecturer_dashboard_class_matches_any_level($rep_class, $active_level_regexes)) {
                continue;
            }
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

$rep_totals = [];
$recent_distributions = [];
$collected_students = [];
$collected_students_count = 0;
if ($selected_book_id > 0) {
    $repTotalsSql = "SELECT a.admin_id, a.full_name, a.class_name, COALESCE(SUM(ld.copies_given), 0) AS copies
        FROM lecturer_distributions ld
        LEFT JOIN admins a ON a.admin_id = ld.rep_admin_id
        WHERE ld.lecturer_id = ? AND ld.book_id = ? AND ld.semester_id = ?
        GROUP BY a.admin_id, a.full_name, a.class_name
        ORDER BY copies DESC, a.full_name ASC";
    $stmt = $conn->prepare($repTotalsSql);
    if ($stmt) {
        $stmt->bind_param('iii', $lecturer_id, $selected_book_id, $semester_id);
        $stmt->execute();
        $rep_totals_res = $stmt->get_result();
        while ($rep_totals_res && ($rep_total_row = $rep_totals_res->fetch_assoc())) {
            $rep_class_name = strval($rep_total_row['class_name'] ?? '');
            if (!lecturer_dashboard_class_matches_any_level($rep_class_name, $active_level_regexes)) {
                continue;
            }
            $rep_totals[] = $rep_total_row;
        }
        $stmt->close();
    }

    $recentDistributionsSql = "SELECT ld.distribution_id, ld.rep_admin_id, ld.copies_given, ld.given_date, ld.notes,
            a.full_name AS rep_name, a.class_name
        FROM lecturer_distributions ld
        LEFT JOIN admins a ON a.admin_id = ld.rep_admin_id
        WHERE ld.lecturer_id = ? AND ld.book_id = ? AND ld.semester_id = ?
        ORDER BY ld.given_date DESC, ld.distribution_id DESC
        LIMIT 30";
    $stmt = $conn->prepare($recentDistributionsSql);
    if ($stmt) {
        $stmt->bind_param('iii', $lecturer_id, $selected_book_id, $semester_id);
        $stmt->execute();
        $recent_distributions_res = $stmt->get_result();
        while ($recent_distributions_res && ($recent_distribution_row = $recent_distributions_res->fetch_assoc())) {
            $rep_class_name = strval($recent_distribution_row['class_name'] ?? '');
            if (intval($recent_distribution_row['rep_admin_id'] ?? 0) > 0 && !lecturer_dashboard_class_matches_any_level($rep_class_name, $active_level_regexes)) {
                continue;
            }
            $recent_distributions[] = $recent_distribution_row;
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT
            s.full_name,
            s.index_number,
            s.phone,
            r.admin_id AS rep_admin_id,
            COALESCE(a.full_name, 'Unknown Rep') AS rep_name,
            COALESCE(a.class_name, '-') AS rep_class,
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
        $collected_students_res = $stmt->get_result();
        while ($collected_students_res && ($collected_student_row = $collected_students_res->fetch_assoc())) {
            $rep_class_name = strval($collected_student_row['rep_class'] ?? '');
            if (!lecturer_dashboard_class_matches_any_level($rep_class_name, $active_level_regexes)) {
                continue;
            }
            $collected_students[] = $collected_student_row;
        }
        $collected_students_count = count($collected_students);
        $stmt->close();
    }
}

// Reps dropdown (for recording)
$reps = null;
$reps_list = [];
$repsSql = "SELECT admin_id, full_name, class_name FROM admins WHERE role = 'rep' AND is_active = 1 ORDER BY full_name ASC";
$reps_stmt = $conn->prepare($repsSql);
if ($reps_stmt) {
    $reps_stmt->execute();
    $reps = $reps_stmt->get_result();
    if ($reps) {
        while ($r = $reps->fetch_assoc()) {
            $rep_class_name = strval($r['class_name'] ?? '');
            if (lecturer_dashboard_class_matches_any_level($rep_class_name, $active_level_regexes)) {
                $reps_list[] = $r;
            }
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
    WHERE a.role = 'rep' AND a.is_active = 1
    GROUP BY a.admin_id, a.full_name, a.class_name
    ORDER BY total_copies DESC, a.full_name ASC";
$repDistributionStmt = $conn->prepare($repDistributionSql);
if ($repDistributionStmt) {
    $repDistributionStmt->bind_param('ii', $lecturer_id, $semester_id);
    $repDistributionStmt->execute();
    $repDistributionRes = $repDistributionStmt->get_result();
    if ($repDistributionRes) {
        while ($row = $repDistributionRes->fetch_assoc()) {
            $rep_class_name = strval($row['class_name'] ?? '');
            if (!lecturer_dashboard_class_matches_any_level($rep_class_name, $active_level_regexes)) {
                continue;
            }
            $rep_distribution_rows[] = $row;
            $rep_distribution_totals[intval($row['admin_id'])] = intval($row['total_copies'] ?? 0);
            $overall_copies_given += intval($row['total_copies'] ?? 0);
        }
    }
    $repDistributionStmt->close();
}

$reps_with_stock = count(array_filter($rep_distribution_totals, function ($value) {
    return intval($value) > 0;
}));
$average_per_rep = count($rep_distribution_rows) > 0 ? ($overall_copies_given / count($rep_distribution_rows)) : 0;

foreach ($assignment_summaries as $assignment_id => $assignment_summary) {
    $matched_book_ids = array_values(array_filter(array_map('intval', $assignment_summary['matched_book_ids'] ?? [])));
    $distributed_copies = 0;
    foreach ($matched_book_ids as $matched_book_id) {
        $distributed_copies += intval($book_totals[$matched_book_id] ?? 0);
    }

    $reps_involved = 0;
    if ($matched_book_ids) {
        $placeholders = implode(',', array_fill(0, count($matched_book_ids), '?'));
        $assignment_sql = "SELECT a.admin_id, a.class_name, COALESCE(SUM(ld.copies_given), 0) AS total_copies
            FROM lecturer_distributions ld
            JOIN admins a ON a.admin_id = ld.rep_admin_id
            WHERE ld.lecturer_id = ? AND ld.semester_id = ? AND ld.book_id IN ($placeholders)
            GROUP BY a.admin_id, a.class_name";
        $assignment_stmt = $conn->prepare($assignment_sql);
        if ($assignment_stmt) {
            $types = 'ii' . str_repeat('i', count($matched_book_ids));
            $params = array_merge([$lecturer_id, $semester_id], $matched_book_ids);
            $assignment_stmt->bind_param($types, ...$params);
            $assignment_stmt->execute();
            $assignment_res = $assignment_stmt->get_result();
            $assignment_level_regex = lecturer_dashboard_level_regex(strval($assignment_summary['academic_level'] ?? ''));
            while ($assignment_res && ($assignment_row = $assignment_res->fetch_assoc())) {
                $rep_class_name = strval($assignment_row['class_name'] ?? '');
                if ($assignment_level_regex === '' || @preg_match('/' . $assignment_level_regex . '/i', $rep_class_name)) {
                    if (intval($assignment_row['total_copies'] ?? 0) > 0) {
                        $reps_involved++;
                    }
                }
            }
            $assignment_stmt->close();
        }
    }

    $assignment_summaries[$assignment_id]['distributed_copies'] = $distributed_copies;
    $assignment_summaries[$assignment_id]['reps_involved'] = $reps_involved;
}

$reconciliation_rows = [];
$book_meta_map = [];
$lecturer_reference_map = [];
$rep_received_map = [];
$rep_payment_map = [];
$reconciliation_assignment_scope = $selected_assignment
    ? [intval($selected_assignment['material_id']) => $assignment_summaries[intval($selected_assignment['material_id'])] ?? []]
    : $assignment_summaries;
$reconciliation_book_ids = [];
foreach ($reconciliation_assignment_scope as $assignment_summary) {
    foreach (array_map('intval', $assignment_summary['matched_book_ids'] ?? []) as $scope_book_id) {
        if ($scope_book_id > 0) {
            $reconciliation_book_ids[$scope_book_id] = true;
        }
    }
}
$reconciliation_book_ids = array_keys($reconciliation_book_ids);
if ($reconciliation_book_ids) {
    $placeholders = implode(',', array_fill(0, count($reconciliation_book_ids), '?'));

    $book_meta_map = [];
    $book_meta_stmt = $conn->prepare("SELECT book_id, book_title, course_code FROM books WHERE book_id IN ($placeholders)");
    if ($book_meta_stmt) {
        $book_meta_stmt->bind_param(str_repeat('i', count($reconciliation_book_ids)), ...$reconciliation_book_ids);
        $book_meta_stmt->execute();
        $book_meta_res = $book_meta_stmt->get_result();
        while ($book_meta_res && ($book_meta_row = $book_meta_res->fetch_assoc())) {
            $book_meta_map[intval($book_meta_row['book_id'] ?? 0)] = $book_meta_row;
        }
        $book_meta_stmt->close();
    }

    $lecturer_reference_map = [];
    $lecturer_dist_stmt = $conn->prepare("SELECT rep_admin_id, book_id, SUM(copies_given) AS lecturer_copies, MAX(given_date) AS last_given_date
        FROM lecturer_distributions
        WHERE lecturer_id = ? AND semester_id = ? AND rep_admin_id IS NOT NULL AND book_id IN ($placeholders)
        GROUP BY rep_admin_id, book_id");
    if ($lecturer_dist_stmt) {
        $types = 'ii' . str_repeat('i', count($reconciliation_book_ids));
        $params = array_merge([$lecturer_id, $semester_id], $reconciliation_book_ids);
        $lecturer_dist_stmt->bind_param($types, ...$params);
        $lecturer_dist_stmt->execute();
        $lecturer_dist_res = $lecturer_dist_stmt->get_result();
        while ($lecturer_dist_res && ($lecturer_dist_row = $lecturer_dist_res->fetch_assoc())) {
            $book_id = intval($lecturer_dist_row['book_id'] ?? 0);
            $rep_admin_id = intval($lecturer_dist_row['rep_admin_id'] ?? 0);
            if ($book_id > 0 && $rep_admin_id > 0) {
                $lecturer_reference_map[$book_id][$rep_admin_id] = [
                    'lecturer_copies' => intval($lecturer_dist_row['lecturer_copies'] ?? 0),
                    'last_given_date' => strval($lecturer_dist_row['last_given_date'] ?? ''),
                ];
            }
        }
        $lecturer_dist_stmt->close();
    }

    $rep_received_map = [];
    $rep_received_stmt = $conn->prepare("SELECT admin_id, book_id, SUM(copies_received) AS rep_copies, MAX(receive_date) AS last_receive_date
        FROM books_received
        WHERE semester_id = ? AND book_id IN ($placeholders)
        GROUP BY admin_id, book_id");
    if ($rep_received_stmt) {
        $types = 'i' . str_repeat('i', count($reconciliation_book_ids));
        $params = array_merge([$semester_id], $reconciliation_book_ids);
        $rep_received_stmt->bind_param($types, ...$params);
        $rep_received_stmt->execute();
        $rep_received_res = $rep_received_stmt->get_result();
        while ($rep_received_res && ($rep_received_row = $rep_received_res->fetch_assoc())) {
            $book_id = intval($rep_received_row['book_id'] ?? 0);
            $rep_admin_id = intval($rep_received_row['admin_id'] ?? 0);
            if ($book_id > 0 && $rep_admin_id > 0) {
                $rep_received_map[$book_id][$rep_admin_id] = [
                    'rep_copies' => intval($rep_received_row['rep_copies'] ?? 0),
                    'last_receive_date' => strval($rep_received_row['last_receive_date'] ?? ''),
                ];
            }
        }
        $rep_received_stmt->close();
    }

    $rep_payment_map = [];
    $rep_payment_stmt = $conn->prepare("SELECT admin_id, book_id, SUM(amount_paid) AS amount_paid_total, MAX(payment_date) AS last_payment_date
        FROM lecturer_payments
        WHERE semester_id = ? AND book_id IN ($placeholders)
        GROUP BY admin_id, book_id");
    if ($rep_payment_stmt) {
        $types = 'i' . str_repeat('i', count($reconciliation_book_ids));
        $params = array_merge([$semester_id], $reconciliation_book_ids);
        $rep_payment_stmt->bind_param($types, ...$params);
        $rep_payment_stmt->execute();
        $rep_payment_res = $rep_payment_stmt->get_result();
        while ($rep_payment_res && ($rep_payment_row = $rep_payment_res->fetch_assoc())) {
            $book_id = intval($rep_payment_row['book_id'] ?? 0);
            $rep_admin_id = intval($rep_payment_row['admin_id'] ?? 0);
            if ($book_id > 0 && $rep_admin_id > 0) {
                $rep_payment_map[$book_id][$rep_admin_id] = [
                    'amount_paid_total' => floatval($rep_payment_row['amount_paid_total'] ?? 0),
                    'last_payment_date' => strval($rep_payment_row['last_payment_date'] ?? ''),
                ];
            }
        }
        $rep_payment_stmt->close();
    }

    $rep_ids = [];
    foreach ([$lecturer_reference_map, $rep_received_map, $rep_payment_map] as $source_map) {
        foreach ($source_map as $book_rows) {
            foreach (array_keys($book_rows) as $rep_admin_id) {
                $rep_admin_id = intval($rep_admin_id);
                if ($rep_admin_id > 0) {
                    $rep_ids[$rep_admin_id] = true;
                }
            }
        }
    }

    $rep_identity_map = [];
    if ($rep_ids) {
        $rep_id_list = array_keys($rep_ids);
        $rep_placeholders = implode(',', array_fill(0, count($rep_id_list), '?'));
        $rep_identity_stmt = $conn->prepare("SELECT admin_id, full_name, class_name FROM admins WHERE admin_id IN ($rep_placeholders)");
        if ($rep_identity_stmt) {
            $rep_identity_stmt->bind_param(str_repeat('i', count($rep_id_list)), ...$rep_id_list);
            $rep_identity_stmt->execute();
            $rep_identity_res = $rep_identity_stmt->get_result();
            while ($rep_identity_res && ($rep_identity_row = $rep_identity_res->fetch_assoc())) {
                $rep_identity_map[intval($rep_identity_row['admin_id'] ?? 0)] = $rep_identity_row;
            }
            $rep_identity_stmt->close();
        }
    }

    foreach ($reconciliation_assignment_scope as $assignment_summary) {
        $assignment_level = strval($assignment_summary['academic_level'] ?? '');
        $assignment_level_regex = lecturer_dashboard_level_regex($assignment_level);
        foreach (array_map('intval', $assignment_summary['matched_book_ids'] ?? []) as $book_id) {
            if ($book_id <= 0) {
                continue;
            }
            $rep_key_set = [];
            foreach ([array_keys($lecturer_reference_map[$book_id] ?? []), array_keys($rep_received_map[$book_id] ?? []), array_keys($rep_payment_map[$book_id] ?? [])] as $rep_key_group) {
                foreach ($rep_key_group as $rep_key) {
                    $rep_key = intval($rep_key);
                    if ($rep_key > 0) {
                        $rep_key_set[$rep_key] = true;
                    }
                }
            }
            foreach (array_keys($rep_key_set) as $rep_admin_id) {
                $rep_admin_id = intval($rep_admin_id);
                $rep_identity = $rep_identity_map[$rep_admin_id] ?? null;
                $rep_class = strval($rep_identity['class_name'] ?? '');
                if ($assignment_level_regex !== '' && !@preg_match('/' . $assignment_level_regex . '/i', $rep_class)) {
                    continue;
                }

                $lecturer_reference = $lecturer_reference_map[$book_id][$rep_admin_id] ?? ['lecturer_copies' => 0, 'last_given_date' => ''];
                $rep_received = $rep_received_map[$book_id][$rep_admin_id] ?? ['rep_copies' => 0, 'last_receive_date' => ''];
                $rep_payment = $rep_payment_map[$book_id][$rep_admin_id] ?? ['amount_paid_total' => 0.0, 'last_payment_date' => ''];

                $lecturer_copies = intval($lecturer_reference['lecturer_copies'] ?? 0);
                $rep_copies = intval($rep_received['rep_copies'] ?? 0);
                $amount_paid_total = floatval($rep_payment['amount_paid_total'] ?? 0);

                if ($lecturer_copies > 0 && $rep_copies === $lecturer_copies) {
                    $status_label = 'Matched';
                    $status_class = 'pill-green';
                    $status_message = 'Rep record matches the lecturer reference entry.';
                } elseif ($lecturer_copies > 0) {
                    $status_label = 'Mismatch';
                    $status_class = 'pill-orange';
                    $status_message = 'Record mismatch: lecturer entry and rep entry do not match. Please review quantity or payment details.';
                } elseif ($rep_copies > 0 || $amount_paid_total > 0) {
                    $status_label = 'Awaiting Lecturer Confirmation';
                    $status_class = 'pill-yellow';
                    $status_message = 'Rep activity was recorded, but there is no lecturer reference entry yet.';
                } else {
                    $status_label = 'No Lecturer Record';
                    $status_class = 'pill-slate';
                    $status_message = 'No lecturer-side reference record is available for this item yet.';
                }

                $record_date = strval($rep_received['last_receive_date'] ?? '');
                if ($record_date === '') {
                    $record_date = strval($rep_payment['last_payment_date'] ?? '');
                }
                if ($record_date === '') {
                    $record_date = strval($lecturer_reference['last_given_date'] ?? '');
                }

                $reconciliation_rows[] = [
                    'rep_name' => strval($rep_identity['full_name'] ?? 'Unknown Rep'),
                    'rep_class' => $rep_class,
                    'course_code' => strval($assignment_summary['course_code'] ?? ($book_meta_map[$book_id]['course_code'] ?? '')),
                    'material_title' => strval($book_meta_map[$book_id]['book_title'] ?? $assignment_summary['material_title'] ?? 'Course Material'),
                    'rep_copies' => $rep_copies,
                    'lecturer_copies' => $lecturer_copies,
                    'amount_paid' => $amount_paid_total,
                    'record_date' => $record_date,
                    'status_label' => $status_label,
                    'status_class' => $status_class,
                    'status_message' => $status_message,
                ];
            }
        }
    }

    usort($reconciliation_rows, static function (array $left, array $right): int {
        $dateCompare = strcmp(strval($right['record_date'] ?? ''), strval($left['record_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return strcmp(strval($left['rep_name'] ?? ''), strval($right['rep_name'] ?? ''));
    });
}

$dashboard_tab = strval($_POST['dashboard_tab'] ?? ($_GET['tab'] ?? ''));
$dashboard_subview = strval($_GET['subview'] ?? '');

$book_details_map = [];
$all_matched_book_ids = [];
foreach ($assigned_books_list as $assigned_book_row) {
    $book_id = intval($assigned_book_row['book_id'] ?? 0);
    if ($book_id <= 0) {
        continue;
    }
    $book_details_map[$book_id] = [
        'book_title' => strval($assigned_book_row['book_title'] ?? ''),
        'price' => floatval($assigned_book_row['price'] ?? 0),
        'course_code' => strval($assigned_book_row['course_code'] ?? ''),
    ];
    $all_matched_book_ids[$book_id] = true;
}
$all_matched_book_ids = array_values(array_keys($all_matched_book_ids));

$request_activity_rows = [];
$course_request_stats = [];
$top_requested_books = [];
$home_total_requests = 0;
$home_total_books_collected = 0;

if ($all_matched_book_ids) {
    $placeholders = implode(',', array_fill(0, count($all_matched_book_ids), '?'));
    $request_sql = "SELECT
            ri.book_id,
            ri.is_collected,
            r.request_id,
            r.student_id,
            COALESCE(a.admin_id, 0) AS rep_admin_id,
            COALESCE(a.class_name, '') AS rep_class
        FROM request_items ri
        JOIN requests r ON r.request_id = ri.request_id
        LEFT JOIN admins a ON a.admin_id = r.admin_id
        WHERE r.semester_id = ? AND ri.is_cancelled = 0 AND ri.book_id IN ($placeholders)";
    $request_stmt = $conn->prepare($request_sql);
    if ($request_stmt) {
        $types = 'i' . str_repeat('i', count($all_matched_book_ids));
        $params = array_merge([$semester_id], $all_matched_book_ids);
        $request_stmt->bind_param($types, ...$params);
        $request_stmt->execute();
        $request_result = $request_stmt->get_result();
        while ($request_result && ($request_row = $request_result->fetch_assoc())) {
            $request_activity_rows[] = $request_row;
        }
        $request_stmt->close();
    }
}

$course_request_tracking = [];
foreach ($assignment_summaries as $course_assignment_id => $course_assignment) {
    $course_request_tracking[$course_assignment_id] = [
        'request_ids' => [],
        'student_ids' => [],
        'request_copies' => 0,
        'books_collected' => 0,
        'matched_book_ids' => array_values(array_filter(array_map('intval', $course_assignment['matched_book_ids'] ?? []))),
        'level_regex' => lecturer_dashboard_level_regex(strval($course_assignment['academic_level'] ?? '')),
    ];
}

foreach ($request_activity_rows as $request_row) {
    $book_id = intval($request_row['book_id'] ?? 0);
    $rep_class = strval($request_row['rep_class'] ?? '');
    $request_id = intval($request_row['request_id'] ?? 0);
    $student_id = intval($request_row['student_id'] ?? 0);
    $is_collected = intval($request_row['is_collected'] ?? 0) === 1;

    $home_total_requests++;
    if ($is_collected) {
        $home_total_books_collected++;
    }

    if (!isset($top_requested_books[$book_id])) {
        $top_requested_books[$book_id] = [
            'book_id' => $book_id,
            'book_title' => strval($book_details_map[$book_id]['book_title'] ?? ('Book ' . $book_id)),
            'requests' => 0,
            'collected' => 0,
        ];
    }
    $top_requested_books[$book_id]['requests']++;
    if ($is_collected) {
        $top_requested_books[$book_id]['collected']++;
    }

    foreach ($course_request_tracking as $course_assignment_id => &$course_tracker) {
        if (!in_array($book_id, $course_tracker['matched_book_ids'], true)) {
            continue;
        }
        $level_regex = strval($course_tracker['level_regex'] ?? '');
        if ($rep_class !== '' && $level_regex !== '' && !@preg_match('/' . $level_regex . '/i', $rep_class)) {
            continue;
        }

        $course_tracker['request_copies']++;
        if ($request_id > 0) {
            $course_tracker['request_ids'][$request_id] = true;
        }
        if ($student_id > 0) {
            $course_tracker['student_ids'][$student_id] = true;
        }
        if ($is_collected) {
            $course_tracker['books_collected']++;
        }
    }
    unset($course_tracker);
}

$course_cards = [];
$level_cards = [];
$level_request_totals = [];

foreach ($assignment_summaries as $course_assignment_id => $course_assignment) {
    $course_tracker = $course_request_tracking[$course_assignment_id] ?? [
        'request_ids' => [],
        'student_ids' => [],
        'request_copies' => 0,
        'books_collected' => 0,
    ];
    $level_value = strval($course_assignment['academic_level'] ?? '');
    $reps_count = intval($course_assignment['reps_involved'] ?? 0);
    $request_copies = intval($course_tracker['request_copies'] ?? 0);
    $student_total = count($course_tracker['student_ids'] ?? []);

    $course_cards[] = [
        'material_id' => intval($course_assignment['material_id'] ?? 0),
        'material_title' => strval($course_assignment['material_title'] ?? ''),
        'course_code' => strval($course_assignment['course_code'] ?? ''),
        'level' => $level_value,
        'students' => $student_total,
        'requests' => $request_copies,
        'books_collected' => intval($course_tracker['books_collected'] ?? 0),
        'reps' => $reps_count,
        'matched_books_count' => intval($course_assignment['matched_books_count'] ?? 0),
        'matched_book_ids' => array_values(array_filter(array_map('intval', $course_assignment['matched_book_ids'] ?? []))),
    ];

    if (!isset($level_cards[$level_value])) {
        $level_cards[$level_value] = [
            'level' => $level_value,
            'courses' => 0,
            'reps' => 0,
            'requests' => 0,
        ];
    }
    $level_cards[$level_value]['courses']++;
    $level_cards[$level_value]['reps'] += $reps_count;
    $level_cards[$level_value]['requests'] += $request_copies;
}

usort($course_cards, static function (array $left, array $right): int {
    $levelCompare = strcmp(strval($left['level'] ?? ''), strval($right['level'] ?? ''));
    if ($levelCompare !== 0) {
        return $levelCompare;
    }
    return strcmp(strval($left['course_code'] ?? ''), strval($right['course_code'] ?? ''));
});

$level_cards = array_values($level_cards);
usort($level_cards, static function (array $left, array $right): int {
    return strcmp(strval($left['level'] ?? ''), strval($right['level'] ?? ''));
});

$payment_rows = [];
$home_total_paid = 0.0;
if ($all_matched_book_ids) {
    $placeholders = implode(',', array_fill(0, count($all_matched_book_ids), '?'));
    $payment_sql = "SELECT
            lp.payment_id,
            lp.admin_id,
            lp.book_id,
            lp.amount_paid,
            lp.payment_date,
            COALESCE(a.full_name, 'Unknown Rep') AS rep_name,
            COALESCE(a.class_name, '') AS rep_class
        FROM lecturer_payments lp
        LEFT JOIN admins a ON a.admin_id = lp.admin_id
        WHERE lp.semester_id = ? AND lp.book_id IN ($placeholders)
        ORDER BY lp.payment_date DESC, lp.payment_id DESC
        LIMIT 200";
    $payment_stmt = $conn->prepare($payment_sql);
    if ($payment_stmt) {
        $types = 'i' . str_repeat('i', count($all_matched_book_ids));
        $params = array_merge([$semester_id], $all_matched_book_ids);
        $payment_stmt->bind_param($types, ...$params);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->get_result();
        while ($payment_result && ($payment_row = $payment_result->fetch_assoc())) {
            $rep_class = strval($payment_row['rep_class'] ?? '');
            if ($rep_class !== '' && !lecturer_dashboard_class_matches_any_level($rep_class, $active_level_regexes)) {
                continue;
            }
            $payment_rows[] = $payment_row;
            $home_total_paid += floatval($payment_row['amount_paid'] ?? 0);
        }
        $payment_stmt->close();
    }
}

$home_total_outstanding = 0.0;
$rep_cards = [];
foreach ($rep_distribution_rows as $rep_distribution_row) {
    $rep_admin_id = intval($rep_distribution_row['admin_id'] ?? 0);
    $rep_collected_total = 0.0;
    $rep_outstanding_total = 0.0;
    $rep_books_total = 0;

    foreach ($all_matched_book_ids as $book_id) {
        $price = floatval($book_details_map[$book_id]['price'] ?? 0);
        $rep_copies = intval($rep_received_map[$book_id][$rep_admin_id]['rep_copies'] ?? 0);
        $rep_paid_total = floatval($rep_payment_map[$book_id][$rep_admin_id]['amount_paid_total'] ?? 0);
        if ($rep_copies <= 0 && $rep_paid_total <= 0) {
            continue;
        }
        $rep_books_total += $rep_copies;
        $rep_collected_total += $rep_paid_total;
        $rep_outstanding_total += max(($rep_copies * $price) - $rep_paid_total, 0);
    }

    $home_total_outstanding += $rep_outstanding_total;
    $rep_health_percent = ($rep_collected_total + $rep_outstanding_total) > 0
        ? intval(round(($rep_collected_total / ($rep_collected_total + $rep_outstanding_total)) * 100))
        : 0;

    $rep_cards[] = [
        'admin_id' => $rep_admin_id,
        'full_name' => strval($rep_distribution_row['full_name'] ?? 'Rep'),
        'class_name' => strval($rep_distribution_row['class_name'] ?? ''),
        'collected' => $rep_collected_total,
        'outstanding' => $rep_outstanding_total,
        'books' => $rep_books_total,
        'requests' => intval($rep_distribution_totals[$rep_admin_id] ?? 0),
        'health' => max(0, min(100, $rep_health_percent)),
        'status' => $rep_outstanding_total > 0 ? 'Attention' : 'Active',
    ];
}

usort($rep_cards, static function (array $left, array $right): int {
    $outstandingCompare = $right['outstanding'] <=> $left['outstanding'];
    if ($outstandingCompare !== 0) {
        return $outstandingCompare;
    }
    return strcmp(strval($left['full_name'] ?? ''), strval($right['full_name'] ?? ''));
});

$selected_rep_card = null;
if ($rep_id > 0) {
    foreach ($rep_cards as $rep_card) {
        if (intval($rep_card['admin_id']) === $rep_id) {
            $selected_rep_card = $rep_card;
            break;
        }
    }
}

$top_requested_rows = array_values($top_requested_books);
usort($top_requested_rows, static function (array $left, array $right): int {
    $requestCompare = intval($right['requests'] ?? 0) <=> intval($left['requests'] ?? 0);
    if ($requestCompare !== 0) {
        return $requestCompare;
    }
    return strcmp(strval($left['book_title'] ?? ''), strval($right['book_title'] ?? ''));
});
$top_requested_rows = array_slice($top_requested_rows, 0, 6);

$selected_course_summary = null;
if ($selected_assignment_id > 0) {
    foreach ($course_cards as $course_card) {
        if (intval($course_card['material_id']) === $selected_assignment_id) {
            $selected_course_summary = $course_card;
            break;
        }
    }
}

$recent_home_payments = array_slice($payment_rows, 0, 4);

$safe_lecturer_initials = '';
foreach (preg_split('/\s+/', trim($lecturer_name)) as $name_part) {
    if ($name_part !== '') {
        $safe_lecturer_initials .= strtoupper(substr($name_part, 0, 1));
    }
}
$safe_lecturer_initials = substr($safe_lecturer_initials, 0, 2);

require __DIR__ . '/lecturer_dashboard_shell.php';
exit;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --page-bg: #eef3f8;
            --surface: rgba(255, 255, 255, 0.96);
            --surface-strong: #ffffff;
            --ink: #10213a;
            --muted: #5f6f86;
            --line: #dbe5f0;
            --shadow: 0 24px 56px rgba(15, 23, 42, 0.08);
            --hero-start: #173b72;
            --hero-end: #5a6ac9;
            --blue-soft: #e9f2ff;
            --blue-ink: #245ec6;
            --green-soft: #eaf8f0;
            --green-ink: #1d8c58;
            --amber-soft: #fff3df;
            --amber-ink: #bf6d0f;
            --violet-soft: #f3ecff;
            --violet-ink: #7053c7;
        }
        html {
            scroll-behavior: smooth;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "SF Pro Text", "SF Pro Display", "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background:
                radial-gradient(circle at top right, rgba(90, 106, 201, 0.16), transparent 24%),
                linear-gradient(180deg, #f8fbff 0%, var(--page-bg) 100%);
            min-height: 100vh;
            padding: 30px 20px 44px;
            color: var(--ink);
        }
        .page-container {
            max-width: 1380px;
            margin: 0 auto;
        }
        .page-header {
            position: relative;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(247, 250, 255, 0.96) 100%);
            color: var(--ink);
            padding: 18px 18px;
            border-radius: 22px;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            flex-wrap: wrap;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.07);
            border: 1px solid rgba(219, 229, 240, 0.98);
        }
        .page-header::after {
            content: "";
            position: absolute;
            right: -52px;
            top: -56px;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.12), rgba(37, 99, 235, 0));
            pointer-events: none;
        }
        .header-copy {
            min-width: 0;
            display: grid;
            gap: 7px;
            position: relative;
            z-index: 1;
        }
        .header-kicker {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #2563eb;
        }
        .page-header h1 {
            font-size: clamp(26px, 4vw, 34px);
            line-height: 1.02;
            font-weight: 900;
            letter-spacing: -0.03em;
            margin-bottom: 0;
        }
        .header-identity {
            display: inline-flex;
            width: fit-content;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.08);
            color: #1d4ed8;
            font-size: 13px;
            font-weight: 800;
        }
        .page-header .subtitle {
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
            max-width: 620px;
        }
        .hero-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 4px;
        }
        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid #dbe4f0;
            color: #334155;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.02em;
        }
        .header-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        .page-header .btn {
            border: 1px solid #dbe4f0;
            background: rgba(255, 255, 255, 0.9);
            color: #10213a;
            padding: 10px 14px;
            border-radius: 14px;
            box-shadow: none;
            font-size: 13px;
            font-weight: 800;
        }
        .page-header .btn:hover {
            background: #eef2ff;
            border-color: #c7d2fe;
            color: #1d4ed8;
            transform: translateY(-1px);
        }
        .workspace-nav {
            position: sticky;
            top: 12px;
            z-index: 15;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-bottom: 18px;
            padding: 10px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(219, 229, 240, 0.95);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.07);
            backdrop-filter: blur(12px);
        }
        .workspace-link {
            appearance: none;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 10px 12px;
            border-radius: 14px;
            text-decoration: none;
            text-align: center;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            background: #f9fbff;
            border: 1px solid #dbe4f0;
            cursor: pointer;
            transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
        }
        .workspace-link:hover {
            color: #1d4ed8;
            background: #eef2ff;
            border-color: #c7d2fe;
        }
        .workspace-link.is-active {
            color: #ffffff;
            background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
            border-color: transparent;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.18);
        }
        .focus-stage {
            display: grid;
            gap: 20px;
            margin-bottom: 18px;
        }
        .workspace-dock {
            display: none;
        }
        .focus-panel {
            display: none;
            animation: lecturerPanelFade 0.18s ease;
        }
        .focus-panel.is-active {
            display: block;
        }
        @keyframes lecturerPanelFade {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .btn {
            appearance: none;
            border: 1px solid rgba(255, 255, 255, 0.24);
            background: rgba(255, 255, 255, 0.12);
            color: white;
            padding: 11px 18px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: transform 0.18s ease, background 0.18s ease, border-color 0.18s ease;
        }
        .btn:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.32);
            transform: translateY(-1px);
        }
        .btn-dark {
            background: #17253d;
            border-color: #17253d;
            color: white;
        }
        .btn-dark:hover {
            background: #20314f;
            border-color: #20314f;
        }
        .grid {
            display: grid;
            grid-template-columns: minmax(0, 1.28fr) minmax(340px, 0.82fr);
            gap: 22px;
            align-items: start;
        }
        .column-stack {
            display: flex;
            flex-direction: column;
            gap: 22px;
            min-width: 0;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }
        .metric-card {
            padding: 16px 16px 18px;
            border-radius: 20px;
            border: 1px solid var(--line);
            background: var(--surface);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.05);
        }
        .metric-card .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
            margin-bottom: 8px;
            color: var(--muted);
        }
        .metric-card .value {
            font-size: 28px;
            line-height: 1;
            letter-spacing: -0.04em;
            font-weight: 800;
            color: var(--ink);
            margin-bottom: 6px;
        }
        .metric-card .hint {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.4;
        }
        .tone-blue { background: linear-gradient(180deg, #f8fbff 0%, var(--blue-soft) 100%); }
        .tone-blue .value { color: var(--blue-ink); }
        .tone-green { background: linear-gradient(180deg, #fbfffd 0%, var(--green-soft) 100%); }
        .tone-green .value { color: var(--green-ink); }
        .tone-amber { background: linear-gradient(180deg, #fffdf8 0%, var(--amber-soft) 100%); }
        .tone-amber .value { color: var(--amber-ink); }
        .tone-violet { background: linear-gradient(180deg, #fcfbff 0%, var(--violet-soft) 100%); }
        .tone-violet .value { color: var(--violet-ink); }
        .card {
            background: var(--surface-strong);
            border: 1px solid rgba(219, 229, 240, 0.95);
            border-radius: 22px;
            padding: 20px;
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.06);
        }
        .card[id],
        .stats-grid[id] {
            scroll-margin-top: 96px;
        }
        .card h2 {
            font-size: 18px;
            line-height: 1.2;
            color: var(--ink);
            margin-bottom: 6px;
            letter-spacing: -0.03em;
        }
        .section-note,
        .muted {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.55;
        }
        .section-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .section-row h2 {
            margin-bottom: 0;
            letter-spacing: -0.03em;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            border: 1px solid transparent;
        }
        .alert-success {
            background: #ecfdf3;
            color: #0f6c46;
            border-color: #bce9ce;
        }
        .alert-error {
            background: #fff0f1;
            color: #b42318;
            border-color: #fecaca;
        }
        .table-wrap {
            overflow-x: auto;
            margin-top: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 100%;
        }
        thead th {
            padding: 13px 14px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7b92;
            font-weight: 800;
            border-bottom: 1px solid #dfe7f0;
            background: #f9fbfe;
            white-space: nowrap;
        }
        td {
            padding: 15px 14px;
            border-bottom: 1px solid #ebf0f6;
            font-size: 14px;
            color: #243449;
            vertical-align: middle;
        }
        tbody tr:hover {
            background: #fbfdff;
        }
        .table-title {
            font-weight: 700;
            color: var(--ink);
        }
        .empty-row {
            color: var(--muted);
            padding: 18px 14px;
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
        .pill-green { background: #ebfbf1; color: #16794d; border-color: #bee6cc; }
        .pill-orange { background: #fff4e7; color: #b96414; border-color: #ffd2a5; }
        .pill-yellow { background: #fff8db; color: #9a6700; border-color: #f8ddb0; }
        .pill-purple { background: #f4efff; color: #7053c7; border-color: #ddcffd; }
        .pill-slate { background: #f2f5f9; color: #4a5d74; border-color: #d7e0ea; }
        .reconcile-card {
            display: grid;
            gap: 14px;
        }
        .reconcile-row {
            border: 1px solid #e5ecf5;
            border-radius: 18px;
            padding: 14px;
            background: #fbfdff;
            display: grid;
            gap: 10px;
        }
        .reconcile-header {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }
        .reconcile-header strong {
            display: block;
            color: var(--ink);
        }
        .reconcile-header .muted {
            margin-top: 3px;
        }
        .reconcile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
        .reconcile-item {
            border: 1px solid #edf2f7;
            background: #ffffff;
            border-radius: 14px;
            padding: 10px 12px;
        }
        .reconcile-item span {
            display: block;
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 4px;
        }
        .reconcile-item strong {
            color: var(--ink);
            font-size: 13px;
        }
        .reconcile-note {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
        }
        .rep-list-table td:last-child,
        .rep-list-table th:last-child {
            text-align: right;
        }
        .rep-list-table td:first-child strong {
            display: block;
            color: var(--ink);
        }
        .form-group {
            margin-bottom: 14px;
        }
        .form-group label {
            display: block;
            font-weight: 700;
            color: #30435d;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d7e0ea;
            border-radius: 14px;
            font-size: 14px;
            color: var(--ink);
            background: #fbfdff;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #7b8ef2;
            box-shadow: 0 0 0 4px rgba(123, 142, 242, 0.14);
            background: #ffffff;
        }
        .form-group textarea {
            min-height: 88px;
            resize: vertical;
        }
        .btn-primary {
            width: 100%;
            padding: 14px 16px;
            background: linear-gradient(135deg, #183968 0%, #4b62c4 100%);
            color: white;
            border: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 16px 32px rgba(75, 98, 196, 0.22);
        }
        .btn-primary:hover {
            filter: brightness(1.02);
        }
        .summary-card {
            background: linear-gradient(180deg, #f9fbff 0%, #f2f7ff 100%);
            border: 1px solid #d9e4fb;
        }
        .summary-card .value {
            margin-bottom: 6px;
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
            max-width: 720px;
        }
        .assignment-table tr.is-active {
            background: linear-gradient(90deg, rgba(92, 111, 214, 0.08) 0%, rgba(92, 111, 214, 0.02) 100%);
        }
        .assignment-table tr.is-active td {
            border-bottom-color: #dce7fb;
        }
        .assignment-name {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .level-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef3ff;
            color: #4f5ecf;
            border: 1px solid #d7defe;
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }
        .scope-note {
            margin-top: -6px;
            margin-bottom: 8px;
        }
        @media (max-width: 1160px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 840px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 640px) {
            body {
                padding: 20px 12px 92px;
            }
            .page-header {
                padding: 15px 14px;
                border-radius: 20px;
                align-items: flex-start;
            }
            .page-header h1 {
                font-size: 25px;
                margin-bottom: 0;
            }
            .header-identity { font-size: 12px; padding: 6px 10px; }
            .page-header .subtitle { font-size: 12px; line-height: 1.45; }
            .hero-chip { font-size: 10px; padding: 6px 9px; }
            .workspace-nav {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                padding: 10px;
                top: 8px;
            }
            .workspace-link {
                min-height: 42px;
                font-size: 12px;
                padding: 9px 10px;
            }
            .workspace-nav {
                display: none;
            }
            .workspace-dock {
                position: fixed;
                left: 10px;
                right: 10px;
                bottom: 12px;
                z-index: 50;
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: 8px;
                padding: 10px 10px calc(10px + env(safe-area-inset-bottom, 0px));
                border-radius: 22px;
                background: rgba(255, 255, 255, 0.94);
                border: 1px solid rgba(219, 229, 240, 0.98);
                box-shadow: 0 20px 40px rgba(15, 23, 42, 0.18);
                backdrop-filter: blur(14px);
            }
            .workspace-dock .workspace-link {
                min-height: 50px;
                padding: 8px 6px;
                font-size: 11px;
                line-height: 1.15;
                border-radius: 16px;
            }
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .card {
                padding: 18px;
                border-radius: 20px;
            }
            thead th,
            td {
                white-space: nowrap;
            }
            .reconcile-grid {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 380px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .workspace-dock {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div class="header-copy">
            <div class="header-kicker">ClassBookHub Lecturer</div>
            <h1>Manage your materials</h1>
            <div class="header-identity"><?php echo htmlspecialchars($lecturer_name); ?></div>
            <div class="subtitle">Stay on top of assignments, handovers, and reconciliation for the active semester without the clutter.</div>
            <div class="hero-meta">
                <span class="hero-chip"><?php echo htmlspecialchars($active_levels_label); ?></span>
                <span class="hero-chip"><?php echo htmlspecialchars($semester_label); ?></span>
            </div>
        </div>
        <div class="header-actions">
            <a href="common_request_portal.php" class="btn">Portal</a>
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

    <nav class="workspace-nav" aria-label="Lecturer workspace">
        <button type="button" class="workspace-link" data-view-target="overview">Overview</button>
        <button type="button" class="workspace-link" data-view-target="books">Books</button>
        <button type="button" class="workspace-link" data-view-target="assignments">Assignments</button>
        <button type="button" class="workspace-link" data-view-target="reconcile">Reconcile</button>
        <button type="button" class="workspace-link" data-view-target="reps">Course Reps</button>
    </nav>
    <div id="lecturerFocusStage" class="focus-stage"></div>
    <div id="lecturerPanelStorage" hidden></div>

    <div class="grid">
        <div class="column-stack">
            <div class="stats-grid" id="lecturerOverview">
                <div class="metric-card tone-blue">
                    <div class="label">Assigned Books</div>
                    <div class="value"><?php echo number_format(count($assigned_books_list)); ?></div>
                    <div class="hint">All course titles currently linked to your lecturer account.</div>
                </div>
                <div class="metric-card tone-green">
                    <div class="label">Total Copies Given</div>
                    <div class="value"><?php echo number_format($overall_copies_given); ?></div>
                    <div class="hint">Combined quantity distributed to reps this semester.</div>
                </div>
                <div class="metric-card tone-amber">
                    <div class="label">Active Reps</div>
                    <div class="value"><?php echo number_format(count($reps_list)); ?></div>
                    <div class="hint">Reps available within your current teaching scope.</div>
                </div>
                <div class="metric-card tone-violet">
                    <div class="label">Reps Taking Books</div>
                    <div class="value"><?php echo number_format($reps_with_stock); ?></div>
                    <div class="hint">Only reps that already have stock in circulation.</div>
                </div>
            </div>

            <div class="card" id="lecturerBooksCard">
                <h2>Assigned Books (This Semester)</h2>
                <div class="section-note">Choose any assigned book to review its distribution entries and recent handovers.</div>
                <div class="table-wrap">
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
                                    <td><strong class="table-title"><?php echo htmlspecialchars($b['book_title']); ?></strong></td>
                                    <td><span class="pill"><?php echo number_format($given); ?></span></td>
                                    <td>
                                        <a class="btn btn-dark" href="?book_id=<?php echo $bid; ?><?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="empty-row">No books have been assigned to your account yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card summary-card">
                <h2>Rep Overview</h2>
                <div class="section-note" style="margin-bottom:16px;">A quick picture of how your materials are currently spread across the reps in your active teaching scope.</div>
                <div class="stats-grid" style="margin-bottom:0;">
                    <div class="metric-card tone-blue">
                        <div class="label">Total Reps Listed</div>
                        <div class="value"><?php echo number_format(count($rep_distribution_rows)); ?></div>
                    </div>
                    <div class="metric-card tone-green">
                        <div class="label">Copies Shared</div>
                        <div class="value"><?php echo number_format($overall_copies_given); ?></div>
                    </div>
                    <div class="metric-card tone-amber">
                        <div class="label">Reps With Stock</div>
                        <div class="value"><?php echo number_format($reps_with_stock); ?></div>
                    </div>
                    <div class="metric-card tone-violet">
                        <div class="label">Average Per Rep</div>
                        <div class="value"><?php echo number_format($average_per_rep, 1); ?></div>
                    </div>
                </div>
            </div>

            <div class="card" id="lecturerAssignmentsCard">
                <div class="section-row">
                    <h2>My Teaching Assignments</h2>
                    <?php if ($selected_assignment): ?>
                        <a class="btn btn-dark" href="lecturer_dashboard.php">Clear Focus</a>
                    <?php endif; ?>
                </div>
                <div class="section-note">Each assignment combines a course, its code, and the level you teach so you can track the right reps and the right books together.</div>
                <?php if ($selected_assignment): ?>
                    <div class="focus-banner">
                        <div>
                            <strong><?php echo htmlspecialchars(strval($selected_assignment['material_title'] ?? '')); ?></strong>
                            <div class="muted">
                                You are currently tracking <strong><?php echo htmlspecialchars(strval($selected_assignment['course_code'] ?? '')); ?></strong>
                                for <strong>Level <?php echo htmlspecialchars(strval($selected_assignment['academic_level'] ?? '')); ?></strong>.
                                The rep list, book filters, and detail screens are now focused on this assignment only.
                            </div>
                        </div>
                        <span class="level-badge">Focused Assignment</span>
                    </div>
                <?php endif; ?>
                <div class="table-wrap">
                    <table class="assignment-table">
                        <thead>
                            <tr>
                                <th>Assignment</th>
                                <th>Level</th>
                                <th>Matched Books</th>
                                <th>Copies Shared</th>
                                <th>Reps Involved</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($assignment_summaries) > 0): ?>
                                <?php foreach ($assignment_summaries as $assignment_summary): ?>
                                    <?php $assignment_id = intval($assignment_summary['material_id']); ?>
                                    <tr class="<?php echo ($selected_assignment_id === $assignment_id) ? 'is-active' : ''; ?>">
                                        <td>
                                            <div class="assignment-name">
                                                <strong class="table-title"><?php echo htmlspecialchars(strval($assignment_summary['material_title'] ?? '')); ?></strong>
                                                <div class="muted"><?php echo htmlspecialchars(strval($assignment_summary['course_code'] ?? '')); ?></div>
                                            </div>
                                        </td>
                                        <td><span class="level-badge"><?php echo htmlspecialchars('Level ' . strval($assignment_summary['academic_level'] ?? '')); ?></span></td>
                                        <td><span class="pill pill-slate"><?php echo number_format(intval($assignment_summary['matched_books_count'] ?? 0)); ?></span></td>
                                        <td><span class="pill pill-blue"><?php echo number_format(intval($assignment_summary['distributed_copies'] ?? 0)); ?></span></td>
                                        <td><span class="pill pill-green"><?php echo number_format(intval($assignment_summary['reps_involved'] ?? 0)); ?></span></td>
                                        <td>
                                            <a class="btn btn-dark" href="?assignment_id=<?php echo $assignment_id; ?>"><?php echo ($selected_assignment_id === $assignment_id) ? 'Tracking' : 'Track'; ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6" class="empty-row">No teaching assignments registered yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
                <div class="card" id="lecturerDistributionCard">
                    <h2>Distribution Summary for <?php echo htmlspecialchars($selected_book_title); ?></h2>

                    <div class="section-note">Use this view to confirm how many copies of the selected book each rep received during the active semester.</div>
                    <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Course Rep</th>
                                <th>Class</th>
                                <th>Copies Given</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($rep_totals) > 0): ?>
                                <?php foreach ($rep_totals as $r): ?>
                                    <tr>
                                        <td><strong class="table-title"><?php echo htmlspecialchars($r['full_name'] ?: 'Unknown'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($r['class_name'] ?: '-'); ?></td>
                                        <td><strong><?php echo number_format(intval($r['copies'] ?? 0)); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="empty-row">No distributions recorded yet for this book.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="section-row" style="margin-top:20px;">
                        <h2>Recent Entries</h2>
                    </div>
                    <div class="table-wrap">
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
                            <?php if (count($recent_distributions) > 0): ?>
                                <?php foreach ($recent_distributions as $d): ?>
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
                                                <button type="submit" name="update_distribution" value="1" class="btn btn-dark">Update</button>
                                                <button type="submit" name="delete_distribution" value="1" class="btn" style="background:#c7364f; border-color:#c7364f;" onclick="return confirm('Delete this entry?');">Delete</button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="empty-row">No entries yet.</td></tr>
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

        <div class="column-stack">
            <div class="card" id="lecturerReconciliationCard">
                <h2>Rep Record Reconciliation</h2>
                <div class="section-note">Compare rep records against lecturer reference entries for the current scope. Reps can keep working even when there is no lecturer entry yet, but any mismatch is highlighted for review.</div>
                <div class="muted scope-note">Current scope: <?php echo htmlspecialchars($selected_assignment ? (strval($selected_assignment['material_title'] ?? '') . ' • Level ' . strval($selected_assignment['academic_level'] ?? '')) : $active_levels_label); ?></div>
                <?php if ($reconciliation_rows): ?>
                    <div class="reconcile-card">
                        <?php foreach ($reconciliation_rows as $reconciliation_row): ?>
                            <div class="reconcile-row">
                                <div class="reconcile-header">
                                    <div>
                                        <strong><?php echo htmlspecialchars(strval($reconciliation_row['rep_name'] ?? 'Unknown Rep')); ?></strong>
                                        <div class="muted"><?php echo htmlspecialchars(strval($reconciliation_row['rep_class'] ?? '-')); ?> • <?php echo htmlspecialchars(strval($reconciliation_row['course_code'] ?? '')); ?></div>
                                    </div>
                                    <span class="pill <?php echo htmlspecialchars(strval($reconciliation_row['status_class'] ?? 'pill-slate')); ?>"><?php echo htmlspecialchars(strval($reconciliation_row['status_label'] ?? 'No Lecturer Record')); ?></span>
                                </div>
                                <div class="reconcile-grid">
                                    <div class="reconcile-item">
                                        <span>Book Title</span>
                                        <strong><?php echo htmlspecialchars(strval($reconciliation_row['material_title'] ?? '')); ?></strong>
                                    </div>
                                    <div class="reconcile-item">
                                        <span>Quantity Recorded By Rep</span>
                                        <strong><?php echo number_format(intval($reconciliation_row['rep_copies'] ?? 0)); ?></strong>
                                    </div>
                                    <div class="reconcile-item">
                                        <span>Lecturer Reference Quantity</span>
                                        <strong><?php echo number_format(intval($reconciliation_row['lecturer_copies'] ?? 0)); ?></strong>
                                    </div>
                                    <div class="reconcile-item">
                                        <span>Amount Paid By Rep</span>
                                        <strong>GH&#8373; <?php echo number_format(floatval($reconciliation_row['amount_paid'] ?? 0), 2); ?></strong>
                                    </div>
                                    <div class="reconcile-item">
                                        <span>Date Recorded</span>
                                        <strong><?php echo htmlspecialchars(strval($reconciliation_row['record_date'] ?? '') !== '' ? date('M d, Y', strtotime(strval($reconciliation_row['record_date']))) : 'Not yet recorded'); ?></strong>
                                    </div>
                                </div>
                                <div class="reconcile-note"><?php echo htmlspecialchars(strval($reconciliation_row['status_message'] ?? '')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="muted">No reconciliation records are available yet for the current scope.</div>
                <?php endif; ?>
            </div>

            <div class="card" id="lecturerRepsCard">
                <h2>Course Reps</h2>
                <div class="section-note">Review rep-by-rep stock movement and open a dedicated material view for any rep.</div>
                <div class="muted scope-note">Current scope: <?php echo htmlspecialchars($selected_assignment ? (strval($selected_assignment['material_title'] ?? '') . ' • Level ' . strval($selected_assignment['academic_level'] ?? '')) : $active_levels_label); ?></div>
                <div class="table-wrap">
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
                                        <a class="btn btn-dark" href="lecturer_rep_view.php?rep_id=<?php echo $rep_id; ?><?php echo $selected_assignment ? '&assignment_id=' . intval($selected_assignment['material_id']) : ''; ?>">View Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="empty-row">No active reps found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h2>Register Teaching Assignments</h2>
                <div class="section-note" style="margin-bottom:14px;">Register the materials you teach so the system can match them with books and reports correctly.</div>

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

                    <div class="form-group">
                        <label>Academic Level</label>
                        <input type="text" name="academic_level" placeholder="e.g. 100" inputmode="numeric" required>
                    </div>

                    <button type="submit" class="btn-primary">Add Teaching Assignment</button>
                </form>

                <div style="overflow:auto;">
                <table>
                    <thead>
                            <tr>
                                <th>Material</th>
                                <th>Course Code</th>
                                <th>Level</th>
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
                                    <td><?php echo htmlspecialchars('Level ' . strval($material['academic_level'] ?? '')); ?></td>
                                    <td><?php echo number_format($matched_count); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this material?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="remove_material" value="1">
                                            <input type="hidden" name="material_id" value="<?php echo intval($material['material_id']); ?>">
                                            <button type="submit" class="btn" style="background:#c7364f; border-color:#c7364f;">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="empty-row">No materials registered yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h2>Record Books Given to Course Rep</h2>
                <div class="section-note" style="margin-bottom:14px;">Log every handover to a rep so your distribution history stays accurate and easy to audit later.</div>
                <div class="muted scope-note">Book choices below are filtered<?php echo $selected_assignment ? ' to the active assignment' : ' to all assignments in your teaching scope'; ?>.</div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="record_distribution" value="1">

                    <div class="form-group">
                        <label>Book *</label>
                        <select name="book_id" required>
                            <option value="">-- Select assigned book --</option>
                            <?php foreach ($assigned_books_list as $b): ?>
                                <?php $bid = intval($b['book_id']); ?>
                                <?php if ($selected_assignment && !in_array($bid, $selected_assignment_book_ids, true)) { continue; } ?>
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

<nav class="workspace-dock" aria-label="Lecturer workspace quick switcher">
    <button type="button" class="workspace-link" data-view-target="overview">Overview</button>
    <button type="button" class="workspace-link" data-view-target="books">Books</button>
    <button type="button" class="workspace-link" data-view-target="assignments">Assign</button>
    <button type="button" class="workspace-link" data-view-target="reconcile">Audit</button>
    <button type="button" class="workspace-link" data-view-target="reps">Reps</button>
</nav>

<script>
(function () {
    const stage = document.getElementById('lecturerFocusStage');
    const storage = document.getElementById('lecturerPanelStorage');
    const sourceGrid = document.querySelector('.grid');
    const controls = Array.from(document.querySelectorAll('[data-view-target]'));

    if (!stage || !storage || !sourceGrid || !controls.length) {
        return;
    }

    const findCardByHeading = (headingText) => {
        const headings = Array.from(document.querySelectorAll('.card h2'));
        const match = headings.find((heading) => heading.textContent.trim() === headingText);
        return match ? match.closest('.card') : null;
    };

    const panelMap = {
        overview: [
            document.getElementById('lecturerOverview'),
            document.querySelector('.summary-card')
        ].filter(Boolean),
        books: [
            document.getElementById('lecturerBooksCard'),
            document.getElementById('lecturerDistributionCard')
        ].filter(Boolean),
        assignments: [
            document.getElementById('lecturerAssignmentsCard'),
            findCardByHeading('Register Teaching Assignments'),
            findCardByHeading('Record Books Given to Course Rep')
        ].filter(Boolean),
        reconcile: [
            document.getElementById('lecturerReconciliationCard')
        ].filter(Boolean),
        reps: [
            document.getElementById('lecturerRepsCard')
        ].filter(Boolean)
    };

    const knownViews = Object.keys(panelMap);
    const allPanels = Array.from(new Set(knownViews.flatMap((view) => panelMap[view])));
    if (!allPanels.length) {
        return;
    }

    const storageKey = 'classbookhub.lecturerDashboardView';

    function resolveInitialView() {
        if (<?php echo $selected_book_id > 0 ? 'true' : 'false'; ?>) {
            return 'books';
        }
        if (<?php echo $selected_assignment_id > 0 ? 'true' : 'false'; ?>) {
            return 'assignments';
        }
        const storedView = window.localStorage ? window.localStorage.getItem(storageKey) : '';
        if (storedView && knownViews.includes(storedView)) {
            return storedView;
        }
        return 'overview';
    }

    function setActiveView(view) {
        const activeView = knownViews.includes(view) ? view : 'overview';

        allPanels.forEach((panel) => {
            storage.appendChild(panel);
        });

        (panelMap[activeView] || []).forEach((panel) => {
            stage.appendChild(panel);
        });

        controls.forEach((control) => {
            const isActive = control.dataset.viewTarget === activeView;
            control.classList.toggle('is-active', isActive);
            control.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        sourceGrid.style.display = 'none';
        stage.hidden = false;

        if (window.localStorage) {
            window.localStorage.setItem(storageKey, activeView);
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    controls.forEach((control) => {
        control.addEventListener('click', () => {
            setActiveView(control.dataset.viewTarget || 'overview');
        });
    });

    setActiveView(resolveInitialView());
})();
</script>

<?php include 'footer.php'; ?>

</body>
</html>

