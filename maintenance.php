<?php
session_start();
require_once 'db.php';

/* Protect admin page */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$message = "";

/* Clear requests & request items */
if (isset($_POST['clear_requests'])) {

    $conn->query("DELETE FROM request_items");
    $conn->query("DELETE FROM requests");

    $message = "All requests and request items have been cleared successfully.";
}

/* Clear students (DANGEROUS) */
if (isset($_POST['clear_students'])) {

    $conn->query("DELETE FROM request_items");
    $conn->query("DELETE FROM requests");
    $conn->query("DELETE FROM students");

    $message = "All students and related data have been cleared successfully.";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>System Maintenance</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<h2>System Maintenance</h2>

<p>
    <a href="admin.php">← Back to Admin Dashboard</a> |
    <a href="logout.php">Logout</a>
</p>

<hr>

<?php if ($message !== "") { ?>
    <p style="color: green;"><strong><?php echo $message; ?></strong></p>
<?php } ?>

<h3>Reset Requests (Safe)</h3>
<p>
    This will remove <strong>all book requests</strong> and <strong>request items</strong>.
    <br>
    Books, prices, availability, and students will remain.
</p>

<form method="post" onsubmit="return confirm('Are you sure you want to clear ALL requests? This cannot be undone.');">
    <button type="submit" name="clear_requests">
        Clear All Requests
    </button>
</form>

<hr>

<h3 style="color: red;">Danger Zone</h3>
<p>
    This will completely reset the system:
    <ul>
        <li>All students</li>
        <li>All requests</li>
        <li>All request items</li>
    </ul>
    <strong>This action cannot be undone.</strong>
</p>

<form method="post"
      onsubmit="return confirm('THIS WILL DELETE ALL STUDENTS AND REQUESTS. Are you absolutely sure?');">
    <button type="submit" name="clear_students" style="color:red;">
        Clear Students & Reset System
    </button>
</form>

</body>
</html>
