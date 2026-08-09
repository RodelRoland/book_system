<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_run_setup_tasks')) {
        book_system_run_setup_tasks($conn);
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;
$dashboard_url = (($access_context['session_role'] ?? '') === 'super_admin'
    && empty($access_context['is_workspace_mode'])
    && empty($access_context['is_own_rep_mode']))
    ? 'admin.php'
    : 'rep_dashboard.php';
$rep_bottom_nav_active = '';

$success_msg = '';
$error_msg = '';
$imported_count = 0;
$skipped_count = 0;
$excel_dependency_ready = false;
$excel_dependency_message = '';
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}

$csrf_token = csrf_get_token();

function upload_class_normalized_index(string $indexNumber): string
{
    return function_exists('book_system_normalize_index_number')
        ? book_system_normalize_index_number($indexNumber)
        : strtoupper(preg_replace('/[^A-Z0-9]+/', '', trim($indexNumber)));
}

$autoload_path = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

if (class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    $excel_dependency_ready = true;
} else {
    $excel_dependency_message = 'Excel import requires PhpSpreadsheet. Install it in your local book_system folder with: composer require phpoffice/phpspreadsheet';
}

// Handle Excel upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_excel'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } elseif (!$excel_dependency_ready) {
        $error_msg = $excel_dependency_message;
    } elseif (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['excel_file']['tmp_name'];
        $original_name = strval($_FILES['excel_file']['name'] ?? '');
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['xlsx', 'xls'];

        if (!in_array($extension, $allowed_extensions, true)) {
            $error_msg = 'Please upload a valid Excel file (.xlsx or .xls).';
        } else {
            try {
                $skip_header = isset($_POST['skip_header']);
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
                if (method_exists($reader, 'setReadDataOnly')) {
                    $reader->setReadDataOnly(true);
                }
                $spreadsheet = $reader->load($file);
                $worksheet = $spreadsheet->getSheet(0);
                $highest_row = intval($worksheet->getHighestDataRow());
                $start_row = $skip_header ? 2 : 1;

                if ($highest_row < $start_row) {
                    $error_msg = 'The Excel sheet does not contain any student rows to import.';
                } else {
                    $seen_index_numbers = [];
                    $has_valid_rows = false;
                    $stmt = $conn->prepare("INSERT INTO class_students (admin_id, semester_id, index_number, normalized_index_number, student_name) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE student_name = VALUES(student_name), normalized_index_number = VALUES(normalized_index_number)");

                    if ($stmt) {
                        for ($row_number = $start_row; $row_number <= $highest_row; $row_number++) {
                            $index_number = trim(strval($worksheet->getCell('A' . $row_number)->getFormattedValue()));
                            $student_name = trim(strval($worksheet->getCell('B' . $row_number)->getFormattedValue()));

                            if ($index_number === '' && $student_name === '') {
                                $skipped_count++;
                                continue;
                            }

                            $duplicate_key = strtolower($index_number);
                            if (isset($seen_index_numbers[$duplicate_key])) {
                                $skipped_count++;
                                continue;
                            }

                            $seen_index_numbers[$duplicate_key] = true;

                            if ($index_number !== '' && $student_name !== '') {
                                $has_valid_rows = true;
                                $normalized_index_number = upload_class_normalized_index($index_number);
                                $stmt->bind_param("iisss", $current_admin_id, $semester_id, $index_number, $normalized_index_number, $student_name);
                                if ($stmt->execute()) {
                                    $imported_count++;
                                } else {
                                    $skipped_count++;
                                }
                            } else {
                                $skipped_count++;
                            }
                        }

                        if ($imported_count > 0) {
                            if (function_exists('book_system_audit_log')) {
                                book_system_audit_log($conn, 'upload_class_excel', 'class_students', $current_admin_id, [
                                    'imported_count' => $imported_count,
                                    'skipped_count' => $skipped_count,
                                ], $current_admin_id);
                            }
                            $success_msg = "Successfully imported/updated $imported_count students.";
                            if ($skipped_count > 0) {
                                $success_msg .= " Skipped $skipped_count invalid rows.";
                            }
                            if ($imported_count > 0 && function_exists('book_system_bump_portal_lookup_cache_version')) {
                                book_system_bump_portal_lookup_cache_version($conn);
                            }
                        } elseif (!$has_valid_rows) {
                            $error_msg = 'No valid records found in the Excel file.';
                        } else {
                            $error_msg = 'No students were imported from the Excel file.';
                        }
                    } else {
                        $error_msg = 'Could not prepare the class import process.';
                    }
                }
            } catch (Throwable $e) {
                $error_msg = 'We could not read that Excel file. Please make sure it is a valid .xlsx or .xls file with Column A = index_number and Column B = student_name.';
                if (function_exists('book_system_security_debug_enabled') && book_system_security_debug_enabled()) {
                    $error_msg .= ' Details: ' . $e->getMessage();
                }
            }
        }
    } else {
        $upload_error = intval($_FILES['excel_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        $error_msg = $upload_error === UPLOAD_ERR_NO_FILE
            ? 'Please select a valid Excel file to upload.'
            : 'The Excel file could not be uploaded. Please try again with a valid .xlsx or .xls file.';
    }
}

// Handle manual add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $index_number = trim($_POST['index_number'] ?? '');
    $student_name = trim($_POST['student_name'] ?? '');
    
    if ($index_number !== '' && $student_name !== '') {
        $stmt = $conn->prepare("INSERT INTO class_students (admin_id, semester_id, index_number, normalized_index_number, student_name) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE student_name = VALUES(student_name), normalized_index_number = VALUES(normalized_index_number)");
        $normalized_index_number = upload_class_normalized_index($index_number);
        $stmt->bind_param("iisss", $current_admin_id, $semester_id, $index_number, $normalized_index_number, $student_name);
        if ($stmt->execute()) {
            if (function_exists('book_system_bump_portal_lookup_cache_version')) {
                book_system_bump_portal_lookup_cache_version($conn);
            }
            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'add_class_student', 'class_students', intval($stmt->insert_id), [
                    'index_number' => $index_number,
                    'student_name' => $student_name,
                ], $current_admin_id);
            }
            $success_msg = "Student added/updated successfully.";
        } else {
            $error_msg = "Error adding student: " . $conn->error;
        }
    } else {
        $error_msg = "Please fill in both index number and student name.";
    }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $id = intval($_POST['student_id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM class_students WHERE id = ? AND admin_id = ? AND semester_id = ?");
        $stmt->bind_param("iii", $id, $current_admin_id, $semester_id);
        if ($stmt->execute()) {
            if (function_exists('book_system_bump_portal_lookup_cache_version')) {
                book_system_bump_portal_lookup_cache_version($conn);
            }
            if (function_exists('book_system_audit_log')) {
                book_system_audit_log($conn, 'delete_class_student', 'class_students', $id, [], $current_admin_id);
            }
            $success_msg = "Student removed from class list.";
        } else {
            $error_msg = "Error removing student.";
        }
    }
    }
}

// Handle clear all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_all'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $stmt = $conn->prepare("DELETE FROM class_students WHERE admin_id = ? AND semester_id = ?");
    $stmt->bind_param("ii", $current_admin_id, $semester_id);
    if ($stmt->execute()) {
        if (function_exists('book_system_bump_portal_lookup_cache_version')) {
            book_system_bump_portal_lookup_cache_version($conn);
        }
        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'clear_class_students', 'class_students', $current_admin_id, [], $current_admin_id);
        }
        $success_msg = "All students cleared from your class list.";
    } else {
        $error_msg = "Error clearing class list.";
    }
    }
}

// Fetch current class students
$search = trim($_GET['search'] ?? '');
$students_result = null;
$total_students = 0;

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("SELECT * FROM class_students WHERE admin_id = ? AND semester_id = ? AND (index_number LIKE ? OR student_name LIKE ?) ORDER BY COALESCE(NULLIF(normalized_index_number, ''), index_number) ASC, student_name ASC LIMIT 500");
    if ($stmt) {
        $stmt->bind_param('iiss', $current_admin_id, $semester_id, $like, $like);
        $stmt->execute();
        $students_result = $stmt->get_result();
    }
} else {
    $stmt = $conn->prepare("SELECT * FROM class_students WHERE admin_id = ? AND semester_id = ? ORDER BY COALESCE(NULLIF(normalized_index_number, ''), index_number) ASC, student_name ASC LIMIT 500");
    if ($stmt) {
        $stmt->bind_param('ii', $current_admin_id, $semester_id);
        $stmt->execute();
        $students_result = $stmt->get_result();
    }
}

$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM class_students WHERE admin_id = ? AND semester_id = ?");
if ($cnt) {
    $cnt->bind_param('ii', $current_admin_id, $semester_id);
    $cnt->execute();
    $cres = $cnt->get_result();
    if ($cres && $cres->num_rows === 1) {
        $total_students = intval($cres->fetch_assoc()['c'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Class Data</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .title-row {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .title-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.28);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18);
        }
        .header h1 { font-size: 24px; }
        .header .subtitle { opacity: 0.9; font-size: 14px; margin-top: 5px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .upload-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 700px) { .upload-section { grid-template-columns: 1fr; } }
        
        .upload-box {
            border: 2px dashed #ddd;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
        }
        .upload-box:hover { border-color: #667eea; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-weight: 600; color: #555; margin-bottom: 8px; font-size: 14px; }
        .form-group input[type="text"],
        .form-group input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
        }
        .form-group input:focus { outline: none; border-color: #667eea; }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-primary:hover { opacity: 0.9; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .stats-row {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-badge {
            background: #e3f2fd;
            color: #1976d2;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .search-form input { flex: 1; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #555; font-size: 13px; }
        tr:hover { background: #f8f9fa; }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #555;
            margin-bottom: 15px;
        }
        .checkbox-label input { width: 18px; height: 18px; }
        
        .excel-format {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            font-size: 13px;
            color: #666;
            margin-top: 15px;
        }
        .excel-format code { background: #e9ecef; padding: 2px 6px; border-radius: 4px; }
        .helper-note {
            margin-top: 12px;
            text-align: left;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <div class="title-row">
                <span class="title-icon">&#128203;</span>
                <div>
                    <h1>Upload Class Data</h1>
                    <p class="subtitle">Import your class roster to enable auto-fill for student names</p>
                </div>
            </div>
        </div>
        <a href="<?= htmlspecialchars($dashboard_url) ?>" class="back-btn">&larr; Back to Dashboard</a>
    </div>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h2>Import Students</h2>
        <div class="upload-section">
            <div class="upload-box">
                <h3 style="margin-bottom: 15px; color: #333;">&#128194; Upload Excel File</h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </div>
                    <label class="checkbox-label">
                        <input type="checkbox" name="skip_header" checked>
                        Skip first row (header)
                    </label>
                    <button type="submit" name="upload_excel" class="btn btn-primary">Upload Excel</button>
                </form>
                <div class="excel-format">
                    <strong>Excel Format:</strong><br>
                    <code>Column A = index_number</code><br>
                    <code>Column B = student_name</code><br>
                    Example: <code>PS/CSC/21/0001 | Roland Kitsi</code>
                </div>
                <?php if (!$excel_dependency_ready): ?>
                    <div class="helper-note"><?php echo htmlspecialchars($excel_dependency_message); ?></div>
                <?php endif; ?>
            </div>
            
            <div class="upload-box">
                <h3 style="margin-bottom: 15px; color: #333;">&#10133; Add Single Student</h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <label>Index Number</label>
                        <input type="text" name="index_number" placeholder="e.g., PS/CSC/21/0001" required>
                    </div>
                    <div class="form-group">
                        <label>Student Name</label>
                        <input type="text" name="student_name" placeholder="e.g., Roland Kitsi" required>
                    </div>
                    <button type="submit" name="add_student" class="btn btn-success">Add Student</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>Your Class Roster</h2>
        
        <div class="stats-row">
            <span class="stat-badge">&#128202; Total Students: <?php echo $total_students; ?></span>
            <?php if ($total_students > 0): ?>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to clear ALL students from your class list?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="clear_all" class="btn btn-danger btn-sm">&#128465; Clear All</button>
            </form>
            <?php endif; ?>
        </div>
        
        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="Search by index number or name..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search): ?>
                <a href="upload_class.php" class="btn" style="background: #6c757d; color: white;">Clear</a>
            <?php endif; ?>
        </form>
        
        <?php if ($students_result && $students_result->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Index Number</th>
                    <th>Student Name</th>
                    <th style="width: 100px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($student = $students_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($student['index_number']); ?></td>
                    <td><?php echo htmlspecialchars($student['student_name']); ?></td>
                    <td>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Remove this student?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                            <button type="submit" name="delete_student" class="btn btn-danger btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="text-align: center; color: #888; padding: 40px;">
            <?php echo $search ? 'No students found matching your search.' : 'No students in your class list yet. Upload an Excel file or add students manually above.'; ?>
        </p>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/rep_bottom_nav.php'; ?>
<?php include 'footer.php'; ?>
</body>
</html>


