<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
require_once __DIR__ . '/setup_tasks.php';

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

if (function_exists('book_system_run_setup_tasks')) {
    book_system_run_setup_tasks($conn);
}

$success_msg = '';
$error_msg = '';
$csrf_token = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $mode = trim(strval($_POST['system_access_mode'] ?? 'premium_active'));
        $premium_start_date = trim(strval($_POST['premium_start_date'] ?? ''));
        $notice_message = trim(strval($_POST['premium_notice_message'] ?? ''));
        $valid_modes = ['free', 'premium_scheduled', 'premium_active'];

        if (!in_array($mode, $valid_modes, true)) {
            $error_msg = 'Choose a valid access mode.';
        } elseif ($mode === 'premium_scheduled') {
            $date_object = DateTime::createFromFormat('Y-m-d', $premium_start_date);
            if (!$date_object || $date_object->format('Y-m-d') !== $premium_start_date) {
                $error_msg = 'Choose a valid premium start date.';
            } elseif ($premium_start_date <= date('Y-m-d')) {
                $error_msg = 'Scheduled premium start date must be in the future.';
            }
        }

        if ($error_msg === '') {
            if ($mode === 'free') {
                $premium_start_date = '';
                $notice_message = '';
            } elseif ($mode === 'premium_active') {
                $premium_start_date = '';
            }

            $saved = true;
            $saved = $saved && book_system_set_app_meta($conn, 'system_access_mode', $mode);
            $saved = $saved && book_system_set_app_meta($conn, 'premium_start_date', $premium_start_date);
            $saved = $saved && book_system_set_app_meta($conn, 'premium_notice_message', $notice_message);
            if ($saved && $mode !== 'premium_active' && function_exists('book_system_reset_all_rep_trials')) {
                $saved = book_system_reset_all_rep_trials($conn);
            }

            if ($saved) {
                $success_msg = 'Access mode updated successfully.';
                if (function_exists('book_system_audit_log')) {
                    book_system_audit_log($conn, 'update_access_mode', 'system', 0, [
                        'system_access_mode' => $mode,
                        'premium_start_date' => $premium_start_date,
                        'premium_notice_message' => $notice_message,
                    ]);
                }
            } else {
                $error_msg = 'Could not save the access mode settings.';
            }
        }
    }
}

$access_mode = function_exists('book_system_get_access_mode_config')
    ? book_system_get_access_mode_config($conn)
    : ['raw_mode' => 'premium_active', 'effective_mode' => 'premium_active', 'premium_start_date' => '', 'premium_notice_message' => ''];
$current_mode = strval($access_mode['raw_mode'] ?? 'premium_active');
$effective_mode = strval($access_mode['effective_mode'] ?? $current_mode);
$premium_start_date = strval($access_mode['premium_start_date'] ?? '');
$premium_notice_message = strval($access_mode['premium_notice_message'] ?? '');
$rollout_notice = function_exists('book_system_get_rep_rollout_notice')
    ? book_system_get_rep_rollout_notice($conn)
    : ['show' => false];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Mode</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%); min-height: 100vh; padding: 30px 20px; }
        .container { max-width: 980px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 26px 30px; border-radius: 16px; margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; gap: 16px; box-shadow: 0 10px 30px rgba(102,126,234,0.3); }
        .header h1 { font-size: 24px; font-weight: 700; }
        .header .subtitle { opacity: .92; margin-top: 6px; font-size: 14px; }
        .back-btn { background: rgba(255,255,255,0.18); color: white; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 700; border: 1px solid rgba(255,255,255,0.3); }
        .grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 20px; }
        .card { background: white; border-radius: 16px; padding: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        .section-title { font-size: 18px; color: #333; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 2px solid #f0f0f0; }
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 18px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #f8d7da; color: #721c24; border-left: 4px solid #dc3545; }
        .mode-list { display: grid; gap: 14px; margin-bottom: 18px; }
        .mode-item { display: flex; gap: 12px; align-items: flex-start; padding: 16px; border: 1px solid #e2e8f0; border-radius: 14px; background: #f8fafc; }
        .mode-item input { margin-top: 3px; }
        .mode-item strong { display: block; color: #1e293b; margin-bottom: 4px; }
        .mode-item span { display: block; color: #64748b; font-size: 13px; line-height: 1.6; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 700; color: #475569; font-size: 14px; }
        .form-input, .form-textarea { width: 100%; padding: 13px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 14px; }
        .form-textarea { min-height: 120px; resize: vertical; }
        .btn { border: none; border-radius: 10px; padding: 12px 18px; font-weight: 700; cursor: pointer; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .status-box { display: grid; gap: 12px; }
        .status-row { padding: 14px 16px; border-radius: 14px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .status-row .label { color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; font-weight: 800; margin-bottom: 6px; }
        .status-row .value { color: #1f2937; font-weight: 800; }
        .notice-preview { margin-top: 18px; padding: 16px 18px; border-radius: 16px; background: linear-gradient(135deg, #fff8e1 0%, #fff3cd 100%); border: 1px solid rgba(217,119,6,0.22); color: #7c4a03; }
        .notice-preview strong { display: block; margin-bottom: 6px; }
        @media (max-width: 880px) { .grid { grid-template-columns: 1fr; } .header { flex-direction: column; align-items: flex-start; } }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Access Mode</h1>
            <p class="subtitle">Control whether the platform is free for reps or running under premium enforcement.</p>
        </div>
        <a href="admin.php" class="back-btn">&larr; Back</a>
    </div>

    <?php if ($success_msg): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div><?php endif; ?>
    <?php if ($error_msg): ?><div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div><?php endif; ?>

    <div class="grid">
        <div class="card">
            <div class="section-title">Set Platform Access Mode</div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="mode-list">
                    <label class="mode-item">
                        <input type="radio" name="system_access_mode" value="free" <?php echo $current_mode === 'free' ? 'checked' : ''; ?>>
                        <div>
                            <strong>Free mode</strong>
                            <span>All reps can use the system without trial expiry or subscription blocking.</span>
                        </div>
                    </label>
                    <label class="mode-item">
                        <input type="radio" name="system_access_mode" value="premium_scheduled" <?php echo $current_mode === 'premium_scheduled' ? 'checked' : ''; ?>>
                        <div>
                            <strong>Premium scheduled</strong>
                            <span>Reps continue using the free version for now, but they see a notice about the premium start date.</span>
                        </div>
                    </label>
                    <label class="mode-item">
                        <input type="radio" name="system_access_mode" value="premium_active" <?php echo $current_mode === 'premium_active' ? 'checked' : ''; ?>>
                        <div>
                            <strong>Premium active</strong>
                            <span>Trial and subscription enforcement are active immediately.</span>
                        </div>
                    </label>
                </div>

                <div class="form-group">
                    <label>Premium Start Date</label>
                    <input type="date" name="premium_start_date" class="form-input" value="<?php echo htmlspecialchars($premium_start_date); ?>">
                </div>

                <div class="form-group">
                    <label>Rep Notice Message</label>
                    <textarea name="premium_notice_message" class="form-textarea" placeholder="Optional custom message shown to reps while premium mode is scheduled."><?php echo htmlspecialchars($premium_notice_message); ?></textarea>
                </div>

                <button type="submit" class="btn">Save Access Mode</button>
            </form>
        </div>

        <div class="card">
            <div class="section-title">Current Status</div>
            <div class="status-box">
                <div class="status-row">
                    <div class="label">Configured Mode</div>
                    <div class="value"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($current_mode))); ?></div>
                </div>
                <div class="status-row">
                    <div class="label">Effective Mode</div>
                    <div class="value"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($effective_mode))); ?></div>
                </div>
                <div class="status-row">
                    <div class="label">Premium Start Date</div>
                    <div class="value"><?php echo $premium_start_date !== '' ? htmlspecialchars(book_system_format_semester_start_date($premium_start_date)) : 'Not scheduled'; ?></div>
                </div>
            </div>

            <?php if (!empty($rollout_notice['show'])): ?>
                <div class="notice-preview">
                    <strong>Rep notice preview</strong>
                    <div><?php echo htmlspecialchars(strval($rollout_notice['message'] ?? '')); ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
