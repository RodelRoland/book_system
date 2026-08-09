<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: login.php');
    exit;
}

$current_admin_name = $_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Super Admin';
$csrf_token = csrf_get_token();
$success_msg = '';
$error_msg = '';

if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'semesters', 'semester_start_date', 'DATE NULL AFTER semester_name');
    }
}

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
                if (function_exists('book_system_bump_portal_lookup_cache_version')) {
                    book_system_bump_portal_lookup_cache_version($conn);
                }
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
        $semester_start_date = trim(strval($_POST['semester_start_date'] ?? ''));
        $date_object = DateTime::createFromFormat('Y-m-d', $semester_start_date);
        if ($semester_name === '') {
            $error_msg = 'Enter a semester name before continuing.';
        } elseif (!$date_object || $date_object->format('Y-m-d') !== $semester_start_date) {
            $error_msg = 'Choose a valid semester start date before continuing.';
        } else {
            $conn->query("UPDATE semesters SET is_active = 0");
            $stmt = $conn->prepare("INSERT INTO semesters (semester_name, semester_start_date, is_active) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE semester_start_date = VALUES(semester_start_date), is_active = 1");
            if ($stmt) {
                $stmt->bind_param('ss', $semester_name, $semester_start_date);
                $stmt->execute();
                if (function_exists('book_system_bump_portal_lookup_cache_version')) {
                    book_system_bump_portal_lookup_cache_version($conn);
                }
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
                $carry_forward_summary = function_exists('book_system_run_balance_carry_forward')
                    ? book_system_run_balance_carry_forward($conn, $new_semester_id)
                    : ['carried_count' => 0, 'carried_total' => 0.0];
                if (function_exists('book_system_audit_log')) {
                    book_system_audit_log($conn, 'create_semester', 'semester', $new_semester_id, [
                        'semester_name' => $semester_name,
                        'semester_start_date' => $semester_start_date,
                        'carried_balance_count' => intval($carry_forward_summary['carried_count'] ?? 0),
                        'carried_balance_total' => floatval($carry_forward_summary['carried_total'] ?? 0),
                        'source' => 'super_admin_home',
                    ]);
                    if (floatval($carry_forward_summary['carried_total'] ?? 0) > 0) {
                        book_system_audit_log($conn, 'carry_forward_balance', 'semester', $new_semester_id, [
                            'target_semester_id' => $new_semester_id,
                            'carried_balance_count' => intval($carry_forward_summary['carried_count'] ?? 0),
                            'carried_balance_total' => floatval($carry_forward_summary['carried_total'] ?? 0),
                            'source' => 'super_admin_home',
                        ]);
                    }
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
$active_semester_start_date = '';
$active_semester_meta_stmt = $conn->prepare("SELECT semester_start_date FROM semesters WHERE is_active = 1 ORDER BY semester_id DESC LIMIT 1");
if ($active_semester_meta_stmt) {
    $active_semester_meta_stmt->execute();
    $active_semester_meta_result = $active_semester_meta_stmt->get_result();
    if ($active_semester_meta_result && $active_semester_meta_result->num_rows === 1) {
        $active_semester_start_date = strval($active_semester_meta_result->fetch_assoc()['semester_start_date'] ?? '');
    }
    $active_semester_meta_stmt->close();
}
$active_semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$semesters = [];
$semesters_result = $conn->query("SELECT semester_id, semester_name, semester_start_date, is_active, created_at FROM semesters ORDER BY semester_id DESC");
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
        @import url('https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap');
        :root {
            --surface: rgba(255, 255, 255, 0.94);
            --border: rgba(145, 164, 212, 0.22);
            --text: #10213e;
            --muted: #5f6f92;
            --shadow: 0 22px 50px rgba(30, 47, 110, 0.12);
            --shadow-soft: 0 12px 28px rgba(30, 47, 110, 0.08);
            --primary: #486cf1;
            --violet: #7457e6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Manrope', 'Segoe UI', sans-serif;
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(72, 108, 241, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(116, 87, 230, 0.16), transparent 24%),
                linear-gradient(180deg, #f7f9ff 0%, #eef3ff 100%);
            color: var(--text);
            padding: 18px 14px 42px;
        }
        .page-container { width: min(1120px, 100%); margin: 0 auto; }
        .dashboard-header, .panel, .stat-card, .alert {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 28px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
        }
        .dashboard-header {
            padding: 24px 20px;
            margin-bottom: 18px;
            display: grid;
            gap: 16px;
        }
        .hero-label {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            min-height: 34px;
            padding: 0 14px;
            border-radius: 999px;
            background: rgba(72, 108, 241, 0.08);
            border: 1px solid rgba(72, 108, 241, 0.12);
            color: var(--primary);
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .dashboard-header h1 { font-size: clamp(1.9rem, 6vw, 2.7rem); letter-spacing: -0.04em; }
        .dashboard-header .subtitle { color: var(--muted); font-size: 0.96rem; line-height: 1.6; }
        .hero-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .hero-chip {
            display: inline-flex;
            align-items: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.82);
            border: 1px solid rgba(145, 164, 212, 0.24);
            color: var(--text);
            font-size: 0.86rem;
            font-weight: 700;
            box-shadow: var(--shadow-soft);
        }
        .header-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            min-height: 52px;
            padding: 0 18px;
            border-radius: 18px;
            font: inherit;
            font-size: 0.95rem;
            font-weight: 800;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.2s ease;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn-light {
            background: rgba(72, 108, 241, 0.08);
            color: var(--text);
            border: 1px solid rgba(72, 108, 241, 0.14);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--violet) 100%);
            color: white;
            border: none;
            box-shadow: 0 14px 26px rgba(72, 108, 241, 0.22);
        }
        .btn-dark {
            background: #16243f;
            color: white;
            border: none;
        }
        .alert {
            padding: 14px 16px;
            margin-bottom: 16px;
            font-size: 0.92rem;
        }
        .alert-success { color: #166534; background: #ecfdf5; border-color: #bbf7d0; }
        .alert-error { color: #be123c; background: #fff1f2; border-color: #fecdd3; }
        .stats-grid, .content-grid, .record-list { display: grid; gap: 14px; }
        .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 18px; }
        .stat-card {
            padding: 18px 16px;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 4px;
            background: var(--primary);
        }
        .stat-card.green::before { background: #18a870; }
        .stat-card.blue::before { background: #0f97ad; }
        .stat-card.yellow::before { background: #c87d16; }
        .stat-card.purple::before { background: var(--violet); }
        .stat-card .label {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .stat-card .value { font-size: clamp(1.3rem, 5vw, 2rem); font-weight: 800; letter-spacing: -0.04em; }
        .content-grid { grid-template-columns: 1fr; }
        .panel { padding: 22px 18px; }
        .panel-kicker {
            color: var(--muted);
            font-size: 0.92rem;
            line-height: 1.6;
            margin-bottom: 18px;
        }
        .panel h2 { font-size: 1.12rem; margin-bottom: 18px; }
        .record-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 18px;
            border-radius: 22px;
            background: #fff;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-soft);
            flex-wrap: wrap;
        }
        .record-item .name { font-weight: 800; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .record-item .meta { color: var(--muted); font-size: 0.88rem; margin-top: 6px; line-height: 1.55; }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .badge-active { background: #ecfdf5; color: #166534; }
        .empty-state, .active-record {
            padding: 16px 18px;
            border-radius: 18px;
            background: rgba(241, 245, 255, 0.72);
            border: 1px dashed rgba(145, 164, 212, 0.34);
            color: var(--muted);
        }
        .active-record { display: inline-flex; font-weight: 700; margin-bottom: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.82rem;
            font-weight: 800;
            color: var(--muted);
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .form-group input {
            width: 100%;
            min-height: 52px;
            padding: 0 16px;
            border: 1px solid rgba(145, 164, 212, 0.32);
            border-radius: 18px;
            font: inherit;
            font-weight: 700;
            background: #fff;
        }
        .form-group input:focus {
            outline: none;
            border-color: rgba(72, 108, 241, 0.55);
            box-shadow: 0 0 0 4px rgba(72, 108, 241, 0.12);
        }
        .inline-form { margin: 0; }
        .panel-footer { margin-top: 18px; }
        @media (min-width: 760px) {
            .dashboard-header {
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: start;
            }
            .stats-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .content-grid { grid-template-columns: 1.25fr 0.9fr; }
        }
    </style>
</head>
<body>
<div class="page-container">
        <div class="dashboard-header">
            <div>
                <div class="hero-label">Records Home</div>
                <h1>Welcome, <?php echo htmlspecialchars($current_admin_name); ?></h1>
                <p class="subtitle">
                    <?php echo $active_semester_name !== '' ? htmlspecialchars($active_semester_name) : 'No active semester'; ?>
                    <?php if ($active_semester_start_date !== ''): ?>
                        <?php echo ' • Starts ' . htmlspecialchars(date('M d, Y', strtotime($active_semester_start_date))); ?>
                    <?php endif; ?>
                </p>
                <div class="hero-chips" style="margin-top: 12px;">
                    <span class="hero-chip">Semester control center</span>
                    <span class="hero-chip">Archived records stay safe</span>
                </div>
        </div>
        <div class="header-actions">
            <a href="common_request_portal.php" class="btn btn-light">Portal</a>
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
            <p class="panel-kicker">Open any saved semester record and make it the active workspace without affecting older archived data.</p>
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
                                <div class="meta">
                                    Starts: <?php echo !empty($semester['semester_start_date']) ? htmlspecialchars(date('M d, Y', strtotime(strval($semester['semester_start_date'])))) : 'Not set'; ?>
                                    &nbsp;&middot;&nbsp;
                                    Created: <?php echo htmlspecialchars(date('M d, Y', strtotime(strval($semester['created_at'] ?? 'now')))); ?>
                                </div>
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
            <p class="panel-kicker">Create a fresh active semester so reps can upload new class lists, add books again, and continue in a clean workspace.</p>
            <div class="active-record">Current Record: <?php echo htmlspecialchars($active_semester_name !== '' ? $active_semester_name : 'None'); ?></div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label for="semester_name">New Semester Name</label>
                    <input type="text" id="semester_name" name="semester_name" placeholder="e.g. 2026/2027 First Semester" required>
                </div>
                <div class="form-group">
                    <label for="semester_start_date">Semester Start Date</label>
                    <input type="date" id="semester_start_date" name="semester_start_date" required>
                </div>
                <div class="panel-footer">
                    <button type="submit" name="create_semester" value="1" class="btn btn-primary">Create and Start New Record</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php if (file_exists(__DIR__ . '/notifications_widget.php')) { require __DIR__ . '/notifications_widget.php'; } ?>
</body>
</html>

