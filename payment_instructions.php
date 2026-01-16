<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

if (!isset($_GET['request_id'])) {
    die("Invalid payment request.");
}

$request_id = intval($_GET['request_id']);

$query = $conn->query("
    SELECT r.total_amount, s.index_number
    FROM requests r
    JOIN students s ON r.student_id = s.student_id
    WHERE r.request_id = $request_id
");

if (!$query || $query->num_rows === 0) {
    die("Payment record not found.");
}

$data = $query->fetch_assoc();

$subtotal = $data['total_amount'];
$momo_charge = $subtotal * 0.01;
$final_amount = $subtotal + $momo_charge;
?>

<!DOCTYPE html>
<html>
<head>
    <title>Payment Instructions</title>
</head>
<body>

<h2>Mobile Money Payment Instructions</h2>

<p><strong>Subtotal:</strong> GH₵ <?php echo number_format($subtotal, 2); ?></p>
<p><strong>MoMo Charge:</strong> GH₵ <?php echo number_format($momo_charge, 2); ?></p>
<p><strong>Total Amount to Pay:</strong> GH₵ <?php echo number_format($final_amount, 2); ?></p>

<hr>

<p>
<strong>Account Name:</strong> Roland Kitsi<br>
<strong>MoMo Number:</strong> 0549090433<br>
<strong>Reference (Index Number):</strong> <?php echo htmlspecialchars($data['index_number']); ?>
</p>

<p>
Please make the payment and wait for confirmation from the course representative.
</p>

<p>
Thank you.
</p>

</body>
</html>
