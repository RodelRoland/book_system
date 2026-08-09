<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'lecturers', 'phone_number', 'VARCHAR(20) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'admins', 'recovery_email', 'VARCHAR(120) NULL AFTER account_number');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'recovery_email', 'VARCHAR(120) NULL AFTER class_name');
    }
}

$csrf_token = csrf_get_token();
$error_msg = '';
$success_msg = '';
$context = $_SESSION['password_reset_context'] ?? null;
if (!is_array($context)) {
    $context = null;
}

$account_type = trim(strval($_POST['account_type'] ?? $_GET['account_type'] ?? ($context['account_type'] ?? 'admin')));
if (!in_array($account_type, ['admin', 'lecturer'], true)) {
    $account_type = 'admin';
}

function password_reset_scope_name(string $accountType, string $stage): string {
    $accountType = $accountType === 'lecturer' ? 'lect' : 'admin';
    $stage = $stage === 'verify' ? 'verify' : 'send';
    return 'pwrs_' . $accountType . '_' . $stage;
}

$username_value = trim(strval($_POST['username'] ?? ($context['username'] ?? '')));
$recovery_email_value = trim(strval($_POST['recovery_email'] ?? ($context['recovery_email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } elseif (isset($_POST['start_over'])) {
        unset($_SESSION['password_reset_context']);
        $context = null;
        $account_type = 'admin';
        $username_value = '';
        $recovery_email_value = '';
    } elseif (isset($_POST['send_code'])) {
        if ($username_value === '') {
            $error_msg = 'Enter your username to continue.';
        } elseif (book_system_is_login_rate_limited($conn, password_reset_scope_name($account_type, 'send'), $username_value, 3, 15)) {
            $error_msg = 'Too many reset attempts. Please wait a few minutes and try again.';
        } else {
            $lookup = function_exists('book_system_lookup_password_reset_account')
                ? book_system_lookup_password_reset_account($conn, $account_type, $username_value)
                : null;
            if (!$lookup) {
                $error_msg = 'No active account with a usable recovery contact was found for that username.';
                book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
            } else {
                $deliveryMethod = strval($lookup['delivery_method'] ?? ($account_type === 'lecturer' ? 'sms' : 'email'));
                if ($account_type === 'admin') {
                    $normalizedEmail = function_exists('book_system_normalize_recovery_email')
                        ? book_system_normalize_recovery_email($recovery_email_value)
                        : '';
                    $storedEmail = strval($lookup['recovery_email'] ?? '');
                    if ($normalizedEmail === '') {
                        $error_msg = 'Enter the recovery email saved on your admin account.';
                        book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                    } elseif ($storedEmail === '' || !hash_equals($storedEmail, $normalizedEmail)) {
                        $error_msg = 'The recovery email does not match our records for that admin account.';
                        book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                    } elseif (!function_exists('book_system_email_password_reset_enabled') || !book_system_email_password_reset_enabled($conn)) {
                        $error_msg = 'Email password reset is not configured yet. Please contact the administrator.';
                        book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                    } else {
                        $resetCode = function_exists('book_system_generate_email_reset_code')
                            ? book_system_generate_email_reset_code()
                            : str_pad((string) mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
                        $send = function_exists('book_system_send_password_reset_email')
                            ? book_system_send_password_reset_email($conn, $storedEmail, $resetCode, strval($lookup['display_name'] ?? ''))
                            : ['success' => false, 'message' => 'Recovery email helper is unavailable.'];
                        if (!empty($send['success'])) {
                            $_SESSION['password_reset_context'] = [
                                'account_type' => $lookup['account_type'],
                                'user_id' => intval($lookup['user_id'] ?? 0),
                                'username' => strval($lookup['username'] ?? ''),
                                'display_name' => strval($lookup['display_name'] ?? ''),
                                'recovery_email' => $storedEmail,
                                'masked_destination' => strval($lookup['masked_email'] ?? ''),
                                'delivery_method' => 'email',
                                'verification_hash' => password_hash($resetCode, PASSWORD_DEFAULT),
                                'verification_expires_at' => time() + 600,
                                'sent_at' => time(),
                            ];
                            $context = $_SESSION['password_reset_context'];
                            $success_msg = 'A verification code has been sent to ' . strval($context['masked_destination'] ?? 'your recovery email') . '.';
                            $recovery_email_value = $storedEmail;
                            book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                        } else {
                            $error_msg = strval($send['message'] ?? 'Could not send the recovery email.');
                            book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                        }
                    }
                } else {
                    if (!function_exists('book_system_sms_password_reset_enabled') || !book_system_sms_password_reset_enabled($conn)) {
                        $error_msg = 'SMS password reset is not configured yet. Please contact the administrator.';
                        book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                    } else {
                        $send = book_system_send_password_reset_code($conn, strval($lookup['phone_e164'] ?? ''));
                        if (!empty($send['success'])) {
                            $_SESSION['password_reset_context'] = [
                                'account_type' => $lookup['account_type'],
                                'user_id' => intval($lookup['user_id'] ?? 0),
                                'username' => strval($lookup['username'] ?? ''),
                                'display_name' => strval($lookup['display_name'] ?? ''),
                                'phone_e164' => strval($lookup['phone_e164'] ?? ''),
                                'masked_destination' => strval($lookup['masked_phone'] ?? ''),
                                'delivery_method' => $deliveryMethod,
                                'sent_at' => time(),
                            ];
                            $context = $_SESSION['password_reset_context'];
                            $success_msg = 'A verification code has been sent to ' . strval($context['masked_destination'] ?? 'your saved number') . '.';
                            book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                        } else {
                            $error_msg = strval($send['message'] ?? 'Could not send the verification code.');
                            book_system_record_login_attempt($conn, password_reset_scope_name($account_type, 'send'), $username_value, false);
                        }
                    }
                }
            }
        }
    } elseif (isset($_POST['verify_and_reset'])) {
        $context = $_SESSION['password_reset_context'] ?? null;
        if (!is_array($context) || empty($context['username'])) {
            $error_msg = 'Start the password reset process again.';
        } else {
            $verification_code = trim(strval($_POST['verification_code'] ?? ''));
            $new_password = strval($_POST['new_password'] ?? '');
            $confirm_password = strval($_POST['confirm_password'] ?? '');

            if ($verification_code === '' || $new_password === '' || $confirm_password === '') {
                $error_msg = 'Verification code and both password fields are required.';
            } elseif (strlen($new_password) < 6) {
                $error_msg = 'New password must be at least 6 characters.';
            } elseif ($new_password !== $confirm_password) {
                $error_msg = 'New password and confirmation do not match.';
            } elseif (book_system_is_login_rate_limited($conn, password_reset_scope_name(strval($context['account_type'] ?? 'admin'), 'verify'), strval($context['username'] ?? ''), 5, 15)) {
                $error_msg = 'Too many verification attempts. Please request a new code and try again.';
            } else {
                $deliveryMethod = strval($context['delivery_method'] ?? 'sms');
                if ($deliveryMethod === 'email') {
                    $expiresAt = intval($context['verification_expires_at'] ?? 0);
                    $verificationHash = strval($context['verification_hash'] ?? '');
                    if ($verificationHash === '' || $expiresAt <= time()) {
                        $check = ['success' => false, 'message' => 'This reset code has expired. Request a new one and try again.'];
                    } elseif (!password_verify($verification_code, $verificationHash)) {
                        $check = ['success' => false, 'message' => 'The verification code is not valid.'];
                    } else {
                        $check = ['success' => true];
                    }
                } else {
                    $check = book_system_verify_password_reset_code($conn, strval($context['phone_e164'] ?? ''), $verification_code);
                }
                if (empty($check['success'])) {
                    $error_msg = strval($check['message'] ?? 'The verification code could not be confirmed.');
                    book_system_record_login_attempt($conn, password_reset_scope_name(strval($context['account_type'] ?? 'admin'), 'verify'), strval($context['username'] ?? ''), false);
                } else {
                    $updated = function_exists('book_system_apply_password_reset')
                        ? book_system_apply_password_reset($conn, strval($context['account_type'] ?? 'admin'), intval($context['user_id'] ?? 0), $new_password)
                        : false;
                    if ($updated) {
                        if (function_exists('book_system_audit_log')) {
                            book_system_audit_log($conn, 'self_service_password_reset', strval($context['account_type'] ?? 'account'), intval($context['user_id'] ?? 0), [
                                'username' => strval($context['username'] ?? ''),
                                'delivery' => strval($context['delivery_method'] ?? 'unknown'),
                            ]);
                        }
                        book_system_record_login_attempt($conn, password_reset_scope_name(strval($context['account_type'] ?? 'admin'), 'verify'), strval($context['username'] ?? ''), true);
                        unset($_SESSION['password_reset_context']);
                        $context = null;
                        $username_value = '';
                        $recovery_email_value = '';
                        $success_msg = 'Password reset successful. You can sign in now.';
                    } else {
                        $error_msg = 'Password reset could not be completed. Please try again.';
                    }
                }
            }
        }
    }
}

$active_context = is_array($context) ? $context : null;
$login_link = ($account_type === 'lecturer') ? 'lecturer_login.php' : 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 620px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #0f172a 0%, #334155 100%);
            color: white;
            padding: 26px 30px;
            border-radius: 18px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: center;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.25);
        }
        .header h1 { font-size: 24px; font-weight: 800; }
        .header p { margin-top: 6px; opacity: 0.92; font-size: 14px; line-height: 1.6; }
        .back-btn {
            text-decoration: none;
            color: white;
            font-weight: 700;
            padding: 10px 16px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.28);
            background: rgba(255,255,255,0.12);
            white-space: nowrap;
        }
        .card {
            background: white;
            border-radius: 18px;
            padding: 26px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        .steps {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .step {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
        }
        .step strong {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1d4ed8;
            font-size: 13px;
            margin-bottom: 10px;
        }
        .step h3 { font-size: 16px; margin-bottom: 6px; color: #0f172a; }
        .step p { color: #64748b; font-size: 13px; line-height: 1.5; }
        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 14px;
            line-height: 1.6;
        }
        .alert-success { background: #dcfce7; color: #166534; border-left: 4px solid #16a34a; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #dc2626; }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            font-weight: 700;
            color: #334155;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 13px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            background: #f8fafc;
        }
        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #2563eb;
            background: white;
        }
        .btn-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .btn {
            border: none;
            border-radius: 12px;
            padding: 13px 16px;
            font-weight: 800;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
        }
        .btn-secondary {
            background: #eef2ff;
            color: #3730a3;
        }
        .note {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 16px;
            font-size: 13px;
            color: #475569;
            line-height: 1.7;
            margin-bottom: 16px;
        }
        .context-bar {
            background: #eff6ff;
            color: #1d4ed8;
            border: 1px solid #bfdbfe;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.6;
        }
        .footer-links {
            margin-top: 18px;
            font-size: 13px;
            color: #64748b;
        }
        .footer-links a {
            color: #1d4ed8;
            text-decoration: none;
            font-weight: 700;
        }
        @media (max-width: 640px) {
            .header { flex-direction: column; align-items: flex-start; }
            .steps { grid-template-columns: 1fr; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1>Forgot Password</h1>
            <p>Verify your recovery contact, receive a reset code, and set a new password securely.</p>
        </div>
        <a href="<?php echo htmlspecialchars($login_link); ?>" class="back-btn">&larr; Back to Login</a>
    </div>

    <div class="card">
        <div class="steps">
            <div class="step">
                <strong>1</strong>
                <h3>Find your account</h3>
                <p>Choose your account type and enter the username and recovery contact saved on the account.</p>
            </div>
            <div class="step">
                <strong>2</strong>
                <h3>Verify and reset</h3>
                <p>Enter the reset code you receive and set your new password to finish recovery.</p>
            </div>
        </div>

        <?php if ($success_msg !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error_msg !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <?php if ($active_context): ?>
            <div class="context-bar">
                Verification is in progress for <strong><?php echo htmlspecialchars(strval($active_context['username'] ?? '')); ?></strong>.
                The code was sent to <strong><?php echo htmlspecialchars(strval($active_context['masked_destination'] ?? 'your saved recovery contact')); ?></strong>.
            </div>
        <?php else: ?>
            <div class="note">
                Rep and admin accounts reset by confirming the recovery email saved on the account, and the code is sent there.
                Lecturer accounts continue to receive reset codes by SMS.
            </div>
        <?php endif; ?>

        <form method="POST" style="margin-bottom: 18px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="form-group">
                <label>Account Type</label>
                <select name="account_type" class="form-select" <?php echo $active_context ? 'disabled' : ''; ?>>
                    <option value="admin" <?php echo $account_type === 'admin' ? 'selected' : ''; ?>>Rep / Admin</option>
                    <option value="lecturer" <?php echo $account_type === 'lecturer' ? 'selected' : ''; ?>>Lecturer</option>
                </select>
                <?php if ($active_context): ?>
                    <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($account_type); ?>">
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" class="form-input" value="<?php echo htmlspecialchars($username_value); ?>" <?php echo $active_context ? 'readonly' : ''; ?> required>
            </div>
            <?php if ($account_type !== 'lecturer'): ?>
            <div class="form-group">
                <label>Recovery Email</label>
                <input type="email" name="recovery_email" class="form-input" value="<?php echo htmlspecialchars($recovery_email_value); ?>" <?php echo $active_context ? 'readonly' : ''; ?> required>
            </div>
            <?php endif; ?>
            <div class="btn-row">
                <button type="submit" name="send_code" value="1" class="btn btn-primary"><?php echo $active_context ? 'Resend Code' : 'Send Verification Code'; ?></button>
                <?php if ($active_context): ?>
                    <button type="submit" name="start_over" value="1" class="btn btn-secondary">Start Over</button>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($active_context): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-group">
                    <label>Verification Code</label>
                    <input type="text" name="verification_code" class="form-input" maxlength="10" inputmode="numeric" required>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-input" minlength="6" required>
                </div>
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-input" minlength="6" required>
                </div>
                <div class="btn-row">
                    <button type="submit" name="verify_and_reset" value="1" class="btn btn-primary">Verify Code and Reset Password</button>
                </div>
            </form>
        <?php endif; ?>

        <div class="footer-links">
            Need help? Return to <a href="<?php echo htmlspecialchars($login_link); ?>">the login page</a> and contact the administrator if your saved contact number is missing or outdated.
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
</body>
</html>
