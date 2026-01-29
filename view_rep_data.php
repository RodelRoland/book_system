<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$current_admin_role = $_SESSION['admin_role'] ?? 'rep';

// Only super admin can access this page
if ($current_admin_role !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
$success_msg = '';
$error_msg = '';
$viewing_rep = null;

// Handle access code submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enter_code'])) {
    $access_code = strtoupper(trim($_POST['access_code'] ?? ''));
    
    if ($access_code !== '') {
        $stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, access_code_expires FROM admins WHERE access_code = ? AND role = 'rep' AND is_active = 1");
        $stmt->bind_param("s", $access_code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $expires = strtotime($row['access_code_expires']);
            if ($expires > time()) {
                $_SESSION['viewing_rep_id'] = $row['admin_id'];
                $_SESSION['viewing_rep_name'] = $row['full_name'];
                $_SESSION['viewing_rep_class'] = $row['class_name'];
                $success_msg = "Access granted! Now viewing data for " . $row['full_name'];
            } else {
                $error_msg = "This access code has expired.";
            }
        } else {
            $error_msg = "Invalid access code.";
        }
    } else {
        $error_msg = "Please enter an access code.";
    }
}

// Handle stop viewing
if (isset($_GET['stop_viewing'])) {
    unset($_SESSION['viewing_rep_id']);
    unset($_SESSION['viewing_rep_name']);
    unset($_SESSION['viewing_rep_class']);
    header('Location: view_rep_data.php');
    exit;
}

// Check if currently viewing a rep
$viewing_rep_id = $_SESSION['viewing_rep_id'] ?? null;
$viewing_rep_name = $_SESSION['viewing_rep_name'] ?? null;
$viewing_rep_class = $_SESSION['viewing_rep_class'] ?? null;

// Fetch all reps with their summary stats (no access code needed for summaries)
$reps_sql = "
    SELECT 
        a.admin_id,
        a.username,
        a.full_name,
        a.class_name,
        a.is_active,
        a.access_code IS NOT NULL AND a.access_code_expires > NOW() AS has_valid_code,
        (SELECT COUNT(*) FROM requests r WHERE r.admin_id = a.admin_id AND r.semester_id = $semester_id) as request_count,
        (SELECT COALESCE(SUM(total_amount), 0) FROM requests r WHERE r.admin_id = a.admin_id AND r.payment_status = 'paid' AND r.semester_id = $semester_id) as total_collected,
        (SELECT COALESCE(SUM(amount_paid), 0) FROM lecturer_payments lp WHERE lp.admin_id = a.admin_id AND lp.semester_id = $semester_id) as paid_to_lecturers
    FROM admins a
    WHERE a.role = 'rep'
    ORDER BY a.full_name ASC
";
$reps_result = $conn->query($reps_sql);

// If viewing a specific rep, fetch their detailed data
$rep_requests = null;
$rep_payments = null;
if ($viewing_rep_id) {
    // Verify the rep still has a valid access code
    $stmt = $conn->prepare("SELECT access_code, access_code_expires FROM admins WHERE admin_id = ?");
    $stmt->bind_param("i", $viewing_rep_id);
    $stmt->execute();
    $check = $stmt->get_result()->fetch_assoc();
    
    if (!$check['access_code'] || strtotime($check['access_code_expires']) < time()) {
        unset($_SESSION['viewing_rep_id']);
        unset($_SESSION['viewing_rep_name']);
        unset($_SESSION['viewing_rep_class']);
        $viewing_rep_id = null;
        $error_msg = "Access code has expired. Please request a new code from the rep.";
    } else {
        // Fetch recent requests
        $stmt = $conn->prepare("
            SELECT r.*, s.full_name as student_name, s.index_number
            FROM requests r
            JOIN students s ON r.student_id = s.student_id
            WHERE r.admin_id = ? AND r.semester_id = ?
            ORDER BY r.created_at DESC
            LIMIT 20
        ");
        $stmt->bind_param("ii", $viewing_rep_id, $semester_id);
        $stmt->execute();
        $rep_requests = $stmt->get_result();
        
        // Fetch recent payments
        $stmt = $conn->prepare("
            SELECT lp.*, b.book_title
            FROM lecturer_payments lp
            JOIN books b ON lp.book_id = b.book_id
            WHERE lp.admin_id = ? AND lp.semester_id = ?
            ORDER BY lp.payment_date DESC
            LIMIT 20
        ");
        $stmt->bind_param("ii", $viewing_rep_id, $semester_id);
        $stmt->execute();
        $rep_payments = $stmt->get_result();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Rep Data</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 1100px; margin: 0 auto; }
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
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        .viewing-banner {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .viewing-banner .info { font-size: 16px; }
        .viewing-banner .info strong { font-size: 18px; }
        .viewing-banner .stop-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .code-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        .code-form .form-group { flex: 1; margin: 0; }
        .code-form label { display: block; font-weight: 600; color: #555; margin-bottom: 8px; font-size: 14px; }
        .code-form input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 18px;
            font-family: 'Courier New', monospace;
            letter-spacing: 4px;
            text-transform: uppercase;
            text-align: center;
        }
        .code-form input:focus { outline: none; border-color: #667eea; }
        .code-form .btn {
            padding: 14px 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .reps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }
        .rep-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            border: 2px solid transparent;
            transition: all 0.3s;
        }
        .rep-card:hover { border-color: #667eea; }
        .rep-card.inactive { opacity: 0.6; }
        .rep-card .name { font-size: 16px; font-weight: 600; color: #333; }
        .rep-card .class { font-size: 13px; color: #666; margin-top: 3px; }
        .rep-card .stats { display: flex; gap: 15px; margin-top: 15px; }
        .rep-card .stat { text-align: center; flex: 1; }
        .rep-card .stat .value { font-size: 18px; font-weight: 700; color: #667eea; }
        .rep-card .stat .label { font-size: 11px; color: #888; text-transform: uppercase; }
        .rep-card .code-status {
            margin-top: 15px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            text-align: center;
        }
        .rep-card .code-status.active { background: #d4edda; color: #155724; }
        .rep-card .code-status.inactive { background: #f8d7da; color: #721c24; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #555; font-size: 13px; }
        tr:hover { background: #f8f9fa; }
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab {
            padding: 12px 24px;
            background: #f0f0f0;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            border: none;
            font-size: 14px;
        }
        .tab.active { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>👁️ View Rep Data</h1>
            <p class="subtitle">Enter access code to view detailed rep information</p>
        </div>
        <a href="admin.php" class="back-btn">← Back</a>
    </div>
    
    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    
    <?php if ($viewing_rep_id): ?>
        <div class="viewing-banner">
            <div class="info">
                Currently viewing: <strong><?php echo htmlspecialchars($viewing_rep_name); ?></strong>
                <?php if ($viewing_rep_class): ?> (<?php echo htmlspecialchars($viewing_rep_class); ?>)<?php endif; ?>
            </div>
            <a href="?stop_viewing=1" class="stop-btn">✕ Stop Viewing</a>
        </div>
        
        <div class="card">
            <div class="tabs">
                <button class="tab active" onclick="showTab('requests')">📩 Requests</button>
                <button class="tab" onclick="showTab('payments')">💰 Lecturer Payments</button>
            </div>
            
            <div id="requests" class="tab-content active">
                <?php if ($rep_requests && $rep_requests->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Student</th>
                            <th>Index</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($r = $rep_requests->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($r['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($r['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['index_number']); ?></td>
                            <td>GH₵ <?php echo number_format($r['total_amount'], 2); ?></td>
                            <td>
                                <span style="padding: 4px 10px; border-radius: 12px; font-size: 12px; background: <?php echo $r['payment_status'] === 'paid' ? '#d4edda' : '#fff3cd'; ?>; color: <?php echo $r['payment_status'] === 'paid' ? '#155724' : '#856404'; ?>;">
                                    <?php echo ucfirst($r['payment_status']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="text-align: center; color: #888; padding: 40px;">No requests found for this rep.</p>
                <?php endif; ?>
            </div>
            
            <div id="payments" class="tab-content">
                <?php if ($rep_payments && $rep_payments->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Book</th>
                            <th>Copies</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($p = $rep_payments->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($p['payment_date'])); ?></td>
                            <td><?php echo htmlspecialchars($p['book_title']); ?></td>
                            <td><?php echo $p['copies_paid']; ?></td>
                            <td>GH₵ <?php echo number_format($p['amount_paid'], 2); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p style="text-align: center; color: #888; padding: 40px;">No lecturer payments found for this rep.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
        </script>
    <?php else: ?>
        <div class="card">
            <h2>Enter Access Code</h2>
            <form method="POST" class="code-form">
                <div class="form-group">
                    <label>Access Code (from Rep)</label>
                    <input type="text" name="access_code" placeholder="XXXXXXXX" maxlength="8" required>
                </div>
                <button type="submit" name="enter_code" class="btn">🔓 Access Data</button>
            </form>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <h2>All Reps Overview</h2>
        <p style="color: #666; font-size: 14px; margin-bottom: 20px;">Summary statistics are visible without access code. Detailed data requires a valid code from the rep.</p>
        
        <?php if ($reps_result && $reps_result->num_rows > 0): ?>
        <div class="reps-grid">
            <?php while ($rep = $reps_result->fetch_assoc()): ?>
            <div class="rep-card <?php echo $rep['is_active'] ? '' : 'inactive'; ?>">
                <div class="name"><?php echo htmlspecialchars($rep['full_name']); ?></div>
                <div class="class"><?php echo htmlspecialchars($rep['class_name'] ?: 'No class assigned'); ?></div>
                <div class="stats">
                    <div class="stat">
                        <div class="value"><?php echo $rep['request_count']; ?></div>
                        <div class="label">Requests</div>
                    </div>
                    <div class="stat">
                        <div class="value">GH₵<?php echo number_format($rep['total_collected'], 0); ?></div>
                        <div class="label">Collected</div>
                    </div>
                    <div class="stat">
                        <div class="value">GH₵<?php echo number_format($rep['total_collected'] - $rep['paid_to_lecturers'], 0); ?></div>
                        <div class="label">Balance</div>
                    </div>
                </div>
                <div class="code-status <?php echo $rep['has_valid_code'] ? 'active' : 'inactive'; ?>">
                    <?php echo $rep['has_valid_code'] ? '🔓 Access code active' : '🔒 No active access code'; ?>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <p style="text-align: center; color: #888; padding: 40px;">No reps found. Create rep accounts in Manage Reps.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
