<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // Disable display_errors for production readiness

include 'db.php';

if (!isset($_GET['request_id'])) {
    die("Invalid payment request.");
}

$request_id = intval($_GET['request_id']);

$stmt = $conn->prepare("SELECT r.total_amount, r.amount_paid, r.credit_used, r.payment_status, r.admin_id,
           s.index_number, s.full_name, s.credit_balance
    FROM requests r
    JOIN students s ON r.student_id = s.student_id
    WHERE r.request_id = ?");
$query = null;
if ($stmt) {
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $query = $stmt->get_result();
}

if (!$query || $query->num_rows === 0) {
    die("Payment record not found.");
}

$data = $query->fetch_assoc();

// Fetch rep's payment details
$rep_admin_id = intval($data['admin_id'] ?? 0);
$rep_info = null;

if ($rep_admin_id > 0) {
    $rep_stmt = $conn->prepare("SELECT full_name, momo_number, account_name, account_number, bank_name FROM admins WHERE admin_id = ? LIMIT 1");
    if ($rep_stmt) {
        $rep_stmt->bind_param('i', $rep_admin_id);
        $rep_stmt->execute();
        $rep_query = $rep_stmt->get_result();
        if ($rep_query && $rep_query->num_rows > 0) {
            $rep_info = $rep_query->fetch_assoc();
        }
    }
}

// Fallback to super admin if no rep info
if (!$rep_info || empty($rep_info['momo_number'])) {
    $fallback_stmt = $conn->prepare("SELECT full_name, momo_number, account_name, account_number, bank_name FROM admins WHERE role = 'super_admin' AND is_active = 1 ORDER BY admin_id ASC LIMIT 1");
    if ($fallback_stmt) {
        $fallback_stmt->execute();
        $fallback = $fallback_stmt->get_result();
        if ($fallback && $fallback->num_rows > 0) {
            $rep_info = $fallback->fetch_assoc();
        }
    }
}

// Final fallback defaults
$rep_name = $rep_info['full_name'] ?? 'Course Representative';
$momo_number = $rep_info['momo_number'] ?? '';
$account_name = $rep_info['account_name'] ?? $rep_name;
$account_number = $rep_info['account_number'] ?? '';
$bank_name = $rep_info['bank_name'] ?? '';

$subtotal      = (float) $data['total_amount'];
$credit_used   = (float) ($data['credit_used'] ?? 0);
$remaining     = $subtotal - $credit_used;
$momo_charge   = $remaining * 0.01;
$final_amount  = $remaining + $momo_charge;
$is_paid       = $data['payment_status'] === 'paid';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Instructions</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="container">
    <div class="cls-btn">Close</div>

    <h2>Payment Instructions</h2>

    <div class="payment-card">

        <div class="step-header">
            <span class="step-number">3</span>
            <h3>Mobile Money (MoMo)</h3>
        </div>

        <div class="amount-summary">
            <div class="total-line">
                <span>Subtotal</span>
                <strong>GH₵ <?php echo number_format($subtotal, 2); ?></strong>
            </div>
            <?php if ($credit_used > 0): ?>
            <div class="total-line" style="color: #28a745;">
                <span>Credit Applied</span>
                <strong>- GH₵ <?php echo number_format($credit_used, 2); ?></strong>
            </div>
            <?php endif; ?>
            <?php if (!$is_paid): ?>
            <div class="total-line">
                <span>Amount Due</span>
                <strong>GH₵ <?php echo number_format($remaining, 2); ?></strong>
            </div>
            <div class="total-line">
                <span>MoMo Charge (1%)</span>
                <strong>GH₵ <?php echo number_format($momo_charge, 2); ?></strong>
            </div>
            <div class="final-total">
                Total to Pay
                <div class="final-amount">GH₵ <?php echo number_format($final_amount, 2); ?></div>
            </div>
            <?php else: ?>
            <div class="final-total" style="background: #d4edda; color: #155724;">
                <strong>FULLY PAID</strong>
                <div style="font-size: 14px;">Your credit covered the entire amount!</div>
            </div>
            <?php endif; ?>
        </div>

        <hr class="totals-divider">

        <?php if (!$is_paid): ?>
        <div class="payment-details">
            <h4>Pay To:</h4>
            <?php if (!empty($momo_number)): ?>
            <div class="detail-row">
                <span class="label">MoMo Name</span>
                <span class="value"><?php echo htmlspecialchars($account_name ?: $rep_name); ?></span>
            </div>
            <div class="detail-row">
                <span class="label">MoMo Number</span>
                <span class="value"><?php echo htmlspecialchars($momo_number); ?></span>
            </div>
            <?php endif; ?>
            <?php if (!empty($account_number) && !empty($bank_name)): ?>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ddd;">
                <h4 style="margin-bottom: 10px;">Or Bank Transfer:</h4>
                <div class="detail-row">
                    <span class="label">Bank</span>
                    <span class="value"><?php echo htmlspecialchars($bank_name); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Account Name</span>
                    <span class="value"><?php echo htmlspecialchars($account_name ?: $rep_name); ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Account Number</span>
                    <span class="value"><?php echo htmlspecialchars($account_number); ?></span>
                </div>
            </div>
            <?php endif; ?>
            <div class="detail-row" style="margin-top: 15px;">
                <span class="label">Reference (Index Number)</span>
                <span class="value highlight"><?php echo htmlspecialchars($data['index_number']); ?></span>
            </div>
        </div>

        <div class="instruction-box">
            <p><strong>Important:</strong></p>
            <p>Please make the exact payment using Mobile Money or Bank Transfer and use your Index Number as the reference.</p>
            <p>After payment, wait for confirmation from the course representative. You will be notified once your books are ready for collection.</p>
        </div>
        <?php else: ?>
        <div class="instruction-box" style="background: #d4edda; border-color: #28a745;">
            <p><strong>No Payment Required!</strong></p>
            <p>Your existing credit balance has covered the full cost of this order.</p>
            <p>Please collect your books from the course representative.</p>
        </div>
        <?php endif; ?>

        <p class="thank-you">Thank you for your purchase!</p>

    </div>

</div>

<script>
document.querySelector('.cls-btn').addEventListener('click', function() {
    window.location.href = 'index.php';
});
</script>

<?php include 'footer.php'; ?>

</body>
</html>