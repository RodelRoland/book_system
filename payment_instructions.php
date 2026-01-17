<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

if (!isset($_GET['request_id'])) {
    die("Invalid payment request.");
}

$request_id = intval($_GET['request_id']);

$query = $conn->query("
    SELECT r.total_amount, s.index_number, s.full_name
    FROM requests r
    JOIN students s ON r.student_id = s.student_id
    WHERE r.request_id = $request_id
");

if (!$query || $query->num_rows === 0) {
    die("Payment record not found.");
}

$data = $query->fetch_assoc();

$subtotal    = (float) $data['total_amount'];
$momo_charge = $subtotal * 0.01;
$final_amount = $subtotal + $momo_charge;
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
            <div class="total-line">
                <span>MoMo Charge (1%)</span>
                <strong>GH₵ <?php echo number_format($momo_charge, 2); ?></strong>
            </div>
            <div class="final-total">
                Total to Pay
                <div class="final-amount">GH₵ <?php echo number_format($final_amount, 2); ?></div>
            </div>
        </div>

        <hr class="totals-divider">

        <div class="payment-details">
            <h4>Pay To:</h4>
            <div class="detail-row">
                <span class="label">Account Name</span>
                <span class="value">Roland Kitsi</span>
            </div>
            <div class="detail-row">
                <span class="label">MoMo Number</span>
                <span class="value">0549090433</span>
            </div>
            <div class="detail-row">
                <span class="label">Reference (Index Number)</span>
                <span class="value highlight"><?php echo htmlspecialchars($data['index_number']); ?></span>
            </div>
        </div>

        <div class="instruction-box">
            <p><strong>Important:</strong></p>
            <p>Please make the exact payment using Mobile Money and use your Index Number as the reference.</p>
            <p>After payment, wait for confirmation from the course representative. You will be notified once your books are ready for collection.</p>
        </div>

        <p class="thank-you">Thank you for your purchase!</p>

    </div>

</div>

<script>
document.querySelector('.cls-btn').addEventListener('click', function() {
    window.location.href = 'index.php';
});
</script>

</body>
</html>