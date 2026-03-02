<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$current_admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

$success_msg = '';
$error_msg = '';
$imported_count = 0;
$skipped_count = 0;

$csrf_token = csrf_get_token();

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_csv'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        
        if ($handle) {
            // Skip header row if checkbox is checked
            $skip_header = isset($_POST['skip_header']);
            if ($skip_header) {
                fgetcsv($handle);
            }
            
            $stmt = $conn->prepare("INSERT INTO class_students (admin_id, index_number, student_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE student_name = VALUES(student_name)");
            
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) >= 2) {
                    $index_number = trim($row[0]);
                    $student_name = trim($row[1]);
                    
                    if ($index_number !== '' && $student_name !== '') {
                        $stmt->bind_param("iss", $current_admin_id, $index_number, $student_name);
                        if ($stmt->execute()) {
                            $imported_count++;
                        } else {
                            $skipped_count++;
                        }
                    } else {
                        $skipped_count++;
                    }
                } else {
                    $skipped_count++;
                }
            }
            fclose($handle);
            
            if ($imported_count > 0) {
                $success_msg = "Successfully imported/updated $imported_count students.";
                if ($skipped_count > 0) {
                    $success_msg .= " Skipped $skipped_count invalid rows.";
                }
            } else {
                $error_msg = "No valid records found in the CSV file.";
            }
        } else {
            $error_msg = "Could not open the uploaded file.";
        }
    } else {
        $error_msg = "Please select a valid CSV file to upload.";
    }
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
        $stmt = $conn->prepare("INSERT INTO class_students (admin_id, index_number, student_name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE student_name = VALUES(student_name)");
        $stmt->bind_param("iss", $current_admin_id, $index_number, $student_name);
        if ($stmt->execute()) {
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
        $stmt = $conn->prepare("DELETE FROM class_students WHERE id = ? AND admin_id = ?");
        $stmt->bind_param("ii", $id, $current_admin_id);
        if ($stmt->execute()) {
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
    $stmt = $conn->prepare("DELETE FROM class_students WHERE admin_id = ?");
    $stmt->bind_param("i", $current_admin_id);
    if ($stmt->execute()) {
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
    $stmt = $conn->prepare("SELECT * FROM class_students WHERE admin_id = ? AND (index_number LIKE ? OR student_name LIKE ?) ORDER BY student_name ASC LIMIT 500");
    if ($stmt) {
        $stmt->bind_param('iss', $current_admin_id, $like, $like);
        $stmt->execute();
        $students_result = $stmt->get_result();
    }
} else {
    $stmt = $conn->prepare("SELECT * FROM class_students WHERE admin_id = ? ORDER BY student_name ASC LIMIT 500");
    if ($stmt) {
        $stmt->bind_param('i', $current_admin_id);
        $stmt->execute();
        $students_result = $stmt->get_result();
    }
}

$cnt = $conn->prepare("SELECT COUNT(*) AS c FROM class_students WHERE admin_id = ?");
if ($cnt) {
    $cnt->bind_param('i', $current_admin_id);
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
        
        .csv-format {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            font-size: 13px;
            color: #666;
            margin-top: 15px;
        }
        .csv-format code { background: #e9ecef; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>📋 Upload Class Data</h1>
            <p class="subtitle">Import your class roster to enable auto-fill for student names</p>
        </div>
        <a href="admin.php" class="back-btn">← Back to Dashboard</a>
    </div>input type="hiden" name="csrf_token" value="<?php echo htmlspecalchars($csrf_token); ?>">
                    <di
    
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
                <h3 style="margin-bottom: 15px; color: #333;">📁 Upload CSV File</h3>
                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <input type="file" name="csv_file" accept=".csv,.txt" required>
                    </div>
                    <label class="">
                    <input type="hidden" name=ccsrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>"heckbox-label">
                        <input type="checkbox" name="skip_header" checked>
                        Skip first row (header)
                    </label>
                    <button type="submit" name="upload_csv" class="btn btn-primary">Upload CSV</button>
                </form>
                <div class="csv-format">
                    <strong>CSV Format:</strong><br>
                    <code>index_number, student_name</code><br>
                    Example: <code>PS/CSC/21/0001, John Doe</code>
                </div>
            </div>
            
            <div class="upload-box">
                <h3 style="margin-bottom: 15px; color: #333;">➕ Add Single Student</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>Index Number</label>
                        <input type="text" name="index_number" placeholder="e.g., PS/CSC/21/0001" required>
                    </div>
                    <div class="form-group">
                        <label>Student Name</label>);">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?>
                        <input type="text" name="student_name" placeholder="e.g., John Doe" required>
                    </div>
                    <button type="submit" name="add_student" class="btn btn-success">Add Student</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h2>Your Class Roster</h2>
        
        <div class="stats-row">
            <span class="stat-badge">📊 Total Students: <?php echo $total_students; ?></span>
            <?php if ($total_students > 0): ?>
            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to clear ALL students from your class list?');">
                <button type="submit" name="clear_all" class="btn btn-danger btn-sm">🗑️ Clear All</button>
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
        
        <?php if ($students_result && $students_result->num_rows > 0): ?>);">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token ?>
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
            <?php echo $search ? 'No students found matching your search.' : 'No students in your class list yet. Upload a CSV or add students manually above.'; ?>
        </p>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
