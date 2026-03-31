<?php
session_start();
require_once 'db.php';
require_once 'setup_tasks.php';

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$messages = [];
$error_msg = '';
$success_msg = '';
$csrf_token = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_setup'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        try {
            $messages = book_system_run_setup_tasks($conn);
            $success_msg = 'Setup tasks completed successfully.';
        } catch (Throwable $e) {
            $error_msg = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Setup</title>
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
            padding: 26px 30px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .header h1 { font-size: 24px; font-weight: 700; }
        .header .subtitle { opacity: 0.92; margin-top: 6px; font-size: 14px; }
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
            flex-shrink: 0;
        }
        .back-btn {
            background: rgba(255,255,255,0.18);
            color: white;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.28); }
        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 20px;
        }
        .card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .section-title {
            font-size: 18px;
            color: #333;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .run-btn {
            padding: 14px 22px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }
        .run-btn:hover { opacity: 0.92; }
        .helper-text {
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 18px;
        }
        .feature-list, .message-list {
            display: grid;
            gap: 10px;
        }
        .feature-item, .message-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            color: #475569;
            font-size: 14px;
        }
        .note {
            margin-top: 18px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #fff7ed;
            color: #9a3412;
            border-left: 4px solid #f59e0b;
        }
        .status-card {
            display: grid;
            gap: 14px;
        }
        .status-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
        }
        .status-box .label {
            font-size: 12px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 8px;
            font-weight: 700;
        }
        .status-box .value {
            font-size: 20px;
            font-weight: 800;
            color: #1f2937;
        }
        @media (max-width: 900px) {
            .header { flex-direction: column; align-items: flex-start; }
            .grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <div class="title-row">
                <span class="title-icon">&#128736;</span>
                <div>
                    <h1>System Setup</h1>
                    <p class="subtitle">Run one-time database setup and migration tasks manually.</p>
                </div>
            </div>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back to Dashboard</a>
    </div>

    <div class="grid">
        <div class="card">
            <div class="section-title">Run Setup Tasks</div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <p class="helper-text">
                This runs the one-time database setup work that used to happen automatically inside <code>db.php</code>.
                Use it after a restore, migration, or deployment when the database structure needs to be refreshed safely.
            </p>

            <form method="POST" style="margin-bottom: 20px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <button type="submit" name="run_setup" value="1" class="run-btn">Run Setup Tasks</button>
            </form>

            <div class="section-title">What This Covers</div>
            <div class="feature-list">
                <div class="feature-item">Core tables and required columns</div>
                <div class="feature-item">Semester and app metadata setup</div>
                <div class="feature-item">Pricing history and request-item backfills</div>
                <div class="feature-item">Lecturer, balance-return, and audit log tables</div>
                <div class="feature-item">Performance indexes for the current system flow</div>
            </div>

            <div class="note">
                Normal page loads no longer run these tasks automatically. That keeps daily usage safer and makes setup actions explicit.
            </div>
        </div>

        <div class="card">
            <div class="section-title">Latest Run</div>
            <div class="status-card">
                <div class="status-box">
                    <div class="label">Status</div>
                    <div class="value"><?php echo $success_msg ? 'Completed' : ($error_msg ? 'Failed' : 'Ready'); ?></div>
                </div>
                <div class="status-box">
                    <div class="label">Steps Reported</div>
                    <div class="value"><?php echo count($messages); ?></div>
                </div>
            </div>

            <?php if (!empty($messages)): ?>
                <div class="message-list" style="margin-top: 18px;">
                    <?php foreach ($messages as $message): ?>
                        <div class="message-item"><?php echo htmlspecialchars($message); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="message-item" style="margin-top: 18px;">No setup run has been recorded on this page yet in the current session.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
