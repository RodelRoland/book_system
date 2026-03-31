<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: login.php');
    exit;
}

$current_admin_name = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Super Admin';
$csrf_token = csrf_get_token();
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: super_admin_home.php?msg=csrf_invalid');
        exit;
    }

    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_semester'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $semester_id = intval($_POST['semester_id'] ?? 0);
        if ($semester_id <= 0) {
            $error_msg = 'Please select a valid semester record.';
        } else {
            $conn->query("UPDATE semesters SET is_active = 0");
            $stmt = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE semester_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $semester_id);
                $stmt->execute();
                if (function_exists('book_system_audit_log')) {
                    book_system_audit_log($conn, 'set_active_semester', 'semester', $semester_id, [
                        'source' => 'super_admin_home',
                    ]);
                }
            }
            header('Location: admin.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_semester'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $semester_name = substr(trim(strval($_POST['semester_name'] ?? '')), 0, 30);
        if ($semester_name === '') {
            $error_msg = 'Enter a semester name before continuing.';
        } else {
            $conn->query("UPDATE semesters SET is_active = 0");
            $stmt = $conn->prepare("INSERT INTO semesters (semester_name, is_active) VALUES (?, 1) ON DUPLICATE KEY UPDATE is_active = 1");
            if ($stmt) {
                $stmt->bind_param('s', $semester_name);
                $stmt->execute();
                $new_semester_id = intval($conn->insert_id);
                if ($new_semester_id <= 0) {
                    $lookup = $conn->prepare("SELECT semester_id FROM semesters WHERE semester_name = ? LIMIT 1");
                    if ($lookup) {
                        $lookup->bind_param('s', $semester_name);
                        $lookup->execute();
                        $lookup_result = $lookup->get_result();
                        if ($lookup_result && $lookup_result->num_rows === 1) {
                            $new_semester_id = intval($lookup_result->fetch_assoc()['semester_id'] ?? 0);
                        }
                    }
                }
                if (function_exists('book_system_audit_log')) {
                    book_system_audit_log($conn, 'create_semester', 'semester', $new_semester_id, [
                        'semester_name' => $semester_name,
                        'source' => 'super_admin_home',
                    ]);
                }
            }
            header('Location: admin.php');
            exit;
        }
    }
}

$active_semester_name = function_exists('book_system_get_active_semester_name')
    ? book_system_get_active_semester_name($conn)
    : '';
$active_semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$semesters = [];
$semesters_result = $conn->query("SELECT semester_id, semester_name, is_active, created_at FROM semesters ORDER BY semester_id DESC");
if ($semesters_result) {
    while ($row = $semesters_result->fetch_assoc()) {
        $semesters[] = $row;
    }
}

$stats = [
    'semesters' => count($semesters),
    'requests' => 0,
    'reps' => 0,
    'lecturers' => 0,
];

$count_result = $conn->query("SELECT COUNT(*) AS c FROM requests WHERE semester_id = " . intval($active_semester_id));
if ($count_result && $count_result->num_rows === 1) {
    $stats['requests'] = intval($count_result->fetch_assoc()['c'] ?? 0);
}

$count_result = $conn->query("SELECT COUNT(*) AS c FROM admins WHERE role = 'rep' AND is_active = 1");
if ($count_result && $count_result->num_rows === 1) {
    $stats['reps'] = intval($count_result->fetch_assoc()['c'] ?? 0);
}

$count_result = $conn->query("SELECT COUNT(*) AS c FROM lecturers WHERE is_active = 1");
if ($count_result && $count_result->num_rows === 1) {
    $stats['lecturers'] = intval($count_result->fetch_assoc()['c'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Records Home</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
            color: #333;
        }
        .page-container { max-width: 1000px; margin: 0 auto; }
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .dashboard-header h1 {
            font-size: 28px;
            font-weight: 600;
        }
        .dashboard-header .subtitle {
            opacity: 0.9;
            margin-top: 5px;
            font-size: 14px;
        }
        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
        }
        .btn {
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.3s;
        }
        .btn-light {
            background: rgba(255,255,255,0.18);
            color: white;
            border: 1px solid rgba(255,255,255,0.28);
        }
        .btn-light:hover { background: rgba(255,255,255,0.28); }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .btn-dark {
            background: #111827;
            color: white;
            border: none;
        }
        .btn-dark:hover { background: #1f2937; }
        .alert {
            padding: 15px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left-color: #f44336; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
        }
        .stat-card.green::before { background: #28a745; }
        .stat-card.blue::before { background: #17a2b8; }
        .stat-card.yellow::before { background: #ffc107; }
        .stat-card.purple::before { background: #6f42c1; }
        .stat-card .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .stat-card .value {
            font-size: 28px;
            font-weight: 700;
        }
        .stat-card.green .value { color: #28a745; }
        .stat-card.blue .value { color: #17a2b8; }
        .stat-card.yellow .value { color: #d4a500; }
        .stat-card.purple .value { color: #6f42c1; }
        .content-grid {
            display: grid;
            grid-template-columns: 1.4fr 0.9fr;
            gap: 24px;
        }
        .panel {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .panel h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 22px;
        }
        .record-list {
            display: grid;
            gap: 14px;
        }
        .record-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 18px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            flex-wrap: wrap;
        }
        .record-item .name {
            font-weight: 700;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .record-item .meta {
            color: #6b7280;
            font-size: 13px;
            margin-top: 4px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .badge-active {
            background: #dcfce7;
            color: #166534;
        }
        .empty-state {
            padding: 22px 18px;
            border-radius: 12px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            color: #6b7280;
            text-align: center;
        }
        .active-record {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            color: #475569;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 18px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 700;
            color: #4b5563;
        }
        .form-group input {
            width: 100%;
            padding: 13px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            background: #f8fafc;
            transition: all 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
        }
        .inline-form { margin: 0; }
        .panel-footer {
            margin-top: 18px;
        }
        @media (max-width: 960px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .content-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .stats-grid { grid-template-columns: 1fr; }
            .dashboard-header,
            .panel { padding: 24px; }
            .dashboard-header h1 { font-size: 24px; }
            .header-actions {
                width: 100%;
                justify-content: stretch;
            }
            .header-actions .btn,
            .header-actions .inline-form,
            .header-actions .inline-form button {
                width: 100%;
            }
            .record-item .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="dashboard-header">
        <div>
            <h1>Welcome, <?php echo htmlspecialchars($current_admin_name); ?></h1>
            <p class="subtitle"><?php echo $active_semester_name !== '' ? htmlspecialchars($active_semester_name) : 'No active semester'; ?></p>
        </div>
        <div class="header-actions">
            <a href="admin.php" class="btn btn-light">Open Dashboard</a>
            <form method="POST" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="logout" value="1" class="btn btn-light">Logout</button>
            </form>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card purple">
            <div class="label">Active Semester</div>
            <div class="value"><?php echo htmlspecialchars($active_semester_name !== '' ? $active_semester_name : 'None'); ?></div>
        </div>
        <div class="stat-card blue">
            <div class="label">Total Semesters</div>
            <div class="value"><?php echo number_format($stats['semesters']); ?></div>
        </div>
        <div class="stat-card yellow">
            <div class="label">Requests in Active Record</div>
            <div class="value"><?php echo number_format($stats['requests']); ?></div>
        </div>
        <div class="stat-card green">
            <div class="label">Active Reps / Lecturers</div>
            <div class="value"><?php echo number_format($stats['reps']); ?> / <?php echo number_format($stats['lecturers']); ?></div>
        </div>
    </div>

    <div class="content-grid">
        <div class="panel">
            <h2>Continue With Existing Record</h2>
            <div class="record-list">
                <?php if (!empty($semesters)): ?>
                    <?php foreach ($semesters as $semester): ?>
                        <?php $semester_id = intval($semester['semester_id'] ?? 0); ?>
                        <div class="record-item">
                            <div>
                                <div class="name">
                                    <?php echo htmlspecialchars(strval($semester['semester_name'] ?? 'Semester')); ?>
                                    <?php if (intval($semester['is_active'] ?? 0) === 1): ?>
                                        <span class="badge badge-active">Active</span>
                                    <?php endif; ?>
                                </div>
                                <div class="meta">Created: <?php echo htmlspecialchars(date('M d, Y', strtotime(strval($semester['created_at'] ?? 'now')))); ?></div>
                            </div>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="semester_id" value="<?php echo $semester_id; ?>">
                                <button type="submit" name="open_semester" value="1" class="btn btn-dark">Use This Record</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No semester records available yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <h2>Start a New Semester Record</h2>
            <div class="active-record">Current Record: <?php echo htmlspecialchars($active_semester_name !== '' ? $active_semester_name : 'None'); ?></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="semester_name">New Semester Name</label>
                    <input type="text" id="semester_name" name="semester_name" placeholder="e.g. 2026/2027 First Semester" required>
                </div>
                <div class="panel-footer">
                    <button type="submit" name="create_semester" value="1" class="btn btn-primary">Create and Start New Record</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
