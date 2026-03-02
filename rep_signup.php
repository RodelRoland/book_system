<?php
session_start();
require_once 'db.php';

$success_msg = '';
$error_msg = '';

$super_admin = null;
$super = $conn->query("SELECT admin_id, full_name, momo_number, bank_name, account_name, account_number FROM admins WHERE role = 'super_admin' AND is_active = 1 ORDER BY admin_id ASC LIMIT 1");
if ($super && $super->num_rows === 1) {
    $super_admin = $super->fetch_assoc();
}

$fallback_momo_number = '0549090433';
$fallback_account_name = 'Roland Kitsi';
$pay_to_full_name = $super_admin ? (strval($super_admin['full_name'] ?? '') !== '' ? $super_admin['full_name'] : $fallback_account_name) : $fallback_account_name;
$pay_to_momo_number = $super_admin ? (strval($super_admin['momo_number'] ?? '') !== '' ? $super_admin['momo_number'] : $fallback_momo_number) : $fallback_momo_number;
$pay_to_account_name = $super_admin ? (strval($super_admin['account_name'] ?? '') !== '' ? $super_admin['account_name'] : $fallback_account_name) : $fallback_account_name;

$csrf_token = csrf_get_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
    $username = substr(trim($_POST['username'] ?? ''), 0, 50);
    $full_name = substr(trim($_POST['full_name'] ?? ''), 0, 30);
    $class_name = substr(trim($_POST['class_name'] ?? ''), 0, 30);

    if ($username === '' || $full_name === '') {
        $error_msg = 'Username and full name are required.';
    } else {
        $check = $conn->prepare("SELECT admin_id FROM admins WHERE username = ? LIMIT 1");
        $check->bind_param('s', $username);
        $check->execute();
        $exists_admin = $check->get_result()->num_rows > 0;

        $check2 = $conn->prepare("SELECT signup_id FROM rep_signup_requests WHERE username = ? LIMIT 1");
        $check2->bind_param('s', $username);
        $check2->execute();
        $exists_signup = $check2->get_result()->num_rows > 0;

        if ($exists_admin) {
            $error_msg = "Username already exists. Please choose a different username.";
        } elseif ($exists_signup) {
            $error_msg = "A signup request with this username already exists. Please wait for approval or contact the super admin.";
        } else {
            $stmt = $conn->prepare("INSERT INTO rep_signup_requests (username, full_name, class_name, status) VALUES (?, ?, ?, 'pending')");
            $stmt->bind_param('sss', $username, $full_name, $class_name);
            if ($stmt->execute()) {
                $success_msg = "Signup request submitted successfully. Please make payment to the super admin and wait for approval.";
            } else {
                $error_msg = "Failed to submit signup request. Please try again.";
            }
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rep Sign Up</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: radial-gradient(1200px 700px at 15% 10%, rgba(102,126,234,0.18), transparent 55%),
                        radial-gradient(900px 600px at 90% 25%, rgba(118,75,162,0.18), transparent 55%),
                        linear-gradient(135deg, #f6f7fb 0%, #eef1f6 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        .container { max-width: 1040px; margin: 0 auto; }
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
            position: relative;
            overflow: hidden;
        }
        .header:before {
            content: '';
            position: absolute;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(255,255,255,0.12);
            top: -120px;
            right: -90px;
        }
        .header:after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 26px;
            background: rgba(255,255,255,0.10);
            bottom: -140px;
            left: -70px;
            transform: rotate(18deg);
        }
        .header h1 { font-size: 22px; font-weight: 700; }
        .header .subtitle { opacity: 0.9; margin-top: 5px; font-size: 13px; }
        .header .subtitle strong { color: #fff; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
            position: relative;
            z-index: 1;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        .card {
            background: white;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 18px;
        }
        .grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 880px) {
            .grid { grid-template-columns: 1fr; }
            .container { max-width: 700px; }
        }
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
            border-left: 4px solid;
        }
        .alert-success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left-color: #f44336; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-weight: 700; color: #555; margin-bottom: 8px; font-size: 14px; }
        input {
            width: 100%;
            padding: 13px 14px;
            border: 2px solid #e8e8e8;
            border-radius: 10px;
            font-size: 15px;
            background: #f8f9fa;
        }
        input:focus { outline: none; border-color: #667eea; background: white; }
        .btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-weight: 800;
            cursor: pointer;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-size: 15px;
            letter-spacing: 0.3px;
        }
        .btn:hover { opacity: 0.95; }
        .note { color: #666; font-size: 14px; line-height: 1.6; }
        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(255,255,255,0.18);
            border: 1px solid rgba(255,255,255,0.25);
            font-weight: 800;
            font-size: 12px;
            position: relative;
            z-index: 1;
        }
        .hero-title { position: relative; z-index: 1; }
        .muted { color: #7a7a7a; font-size: 13px; line-height: 1.5; }
        .steps {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }
        .step {
            display: flex;
            gap: 12px;
            padding: 12px;
            border-radius: 14px;
            background: #f8f9ff;
            border: 1px solid rgba(102,126,234,0.18);
        }
        .step .num {
            width: 28px;
            height: 28px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-weight: 900;
            color: #3f51b5;
            background: rgba(102,126,234,0.18);
            flex-shrink: 0;
        }
        .step .title { font-weight: 900; color: #2f2f2f; font-size: 13px; margin-bottom: 2px; }
        .step .desc { color: #666; font-size: 12.5px; line-height: 1.45; }
        .pay-hero {
            border-radius: 18px;
            padding: 18px;
            background: linear-gradient(135deg, rgba(34,197,94,0.12), rgba(102,126,234,0.10));
            border: 1px solid rgba(34,197,94,0.25);
            margin-top: 12px;
        }
        .pay-hero h4 { font-size: 14px; font-weight: 900; margin-bottom: 10px; color: #1f2937; }
        .pay-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255,255,255,0.75);
            border: 1px solid rgba(0,0,0,0.05);
            margin-bottom: 10px;
        }
        .pay-row .label { color: #6b7280; font-size: 12px; font-weight: 800; }
        .pay-row .value { color: #111827; font-weight: 950; font-size: 14px; text-align: right; }
        .copy-btn {
            margin-top: 6px;
            width: 100%;
            border: 1px solid rgba(0,0,0,0.08);
            background: #111827;
            color: white;
            padding: 10px 12px;
            border-radius: 12px;
            font-weight: 900;
            cursor: pointer;
        }
        .copy-btn:hover { opacity: 0.95; }
        .small-link {
            display: inline-block;
            margin-top: 10px;
            color: #667eea;
            text-decoration: none;
            font-weight: 900;
        }
        .small-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <div class="kicker">🚀 Rep Onboarding</div>
            <div class="hero-title" style="margin-top: 10px;">
                <h1>Become a Class Rep</h1>
                <p class="subtitle">Submit your request and pay via <strong>MoMo</strong> to get your <strong>4-digit first-time code</strong>.</p>
            </div>
        </div>
        <a href="login.php" class="back-btn">← Back</a>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="grid">
        <div>
            <div class="card">
                <h3 style="margin-bottom: 6px; font-weight: 900;">Create your Rep Signup Request</h3>
                <div class="muted" style="margin-bottom: 16px;">Choose a username you will remember. This will be used during login and password setup.</div>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" required placeholder="e.g. rep_john">
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required placeholder="e.g. John Doe">
                    </div>
                    <div class="form-group">
                        <label>Class Name</label>
                        <input type="text" name="class_name" placeholder="e.g. HND 1 A">
                    </div>
                    <button type="submit" class="btn">Submit Request</button>
                    <div class="muted" style="margin-top: 10px;">Tip: After payment, wait for super admin approval. You’ll receive a 4-digit code to set your password.</div>
                </form>
            </div>

            <div class="card">
                <h3 style="margin-bottom: 10px; font-weight: 900;">How it works</h3>
                <div class="steps">
                    <div class="step">
                        <div class="num">1</div>
                        <div>
                            <div class="title">Submit your signup request</div>
                            <div class="desc">Fill the form with your username and name.</div>
                        </div>
                    </div>
                    <div class="step">
                        <div class="num">2</div>
                        <div>
                            <div class="title">Pay via MoMo</div>
                            <div class="desc">Make payment to the MoMo number shown on this page.</div>
                        </div>
                    </div>
                    <div class="step">
                        <div class="num">3</div>
                        <div>
                            <div class="title">Get your first-time code</div>
                            <div class="desc">After confirmation, you’ll receive a 4-digit code to set your password.</div>
                        </div>
                    </div>
                </div>
                <a class="small-link" href="rep_first_time_reset.php">Go to First-Time Code Reset →</a>
            </div>
        </div>

        <div>
            <div class="card" style="border: 1px solid rgba(34,197,94,0.25);">
                <h3 style="margin-bottom: 6px; font-weight: 950;">Payment Details (MoMo)</h3>
                <div class="muted">Use these details to complete payment. Once approved, you’ll be able to set your password and access the system.</div>

                <div class="pay-hero">
                    <h4>Pay To</h4>
                    <div class="pay-row">
                        <div>
                            <div class="label">Account Name</div>
                        </div>
                        <div class="value"><?php echo htmlspecialchars($pay_to_account_name); ?></div>
                    </div>
                    <div class="pay-row" style="margin-bottom: 6px;">
                        <div>
                            <div class="label">MoMo Number</div>
                        </div>
                        <div class="value" id="momoNumber"><?php echo htmlspecialchars($pay_to_momo_number); ?></div>
                    </div>
                    <div class="muted" style="margin-top: 6px;">If asked for reference, use your <strong>username</strong>.</div>
                    <button type="button" class="copy-btn" onclick="copyMomo()">Copy MoMo Number</button>
                </div>

                <div class="muted" style="margin-top: 12px;">
                    Approved already? Set your password here:
                    <a href="rep_first_time_reset.php" style="color:#667eea; text-decoration:none; font-weight:900;">First-Time Code Reset</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyMomo() {
    var el = document.getElementById('momoNumber');
    var text = el ? (el.textContent || '').trim() : '';
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}
</script>

<?php include 'footer.php'; ?>
</body>
</html>
