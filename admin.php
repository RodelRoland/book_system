<?php
session_start();
require_once 'db.php';

// 1. Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username']; 
    $password = $_POST['password']; 

    if ($username === 'Roland' && $password === 'Rodel1234') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = 'Roland'; 

        header("Location: admin.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Panel</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Modern Dashboard Styling */
        .dashboard-btn {
            display: block;
            width: 100%;
            padding: 15px;
            margin: 10px 0;
            background: #007bff;
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        .logout-link { color: #dc3545; display: block; margin-top: 20px; text-align: center; font-weight: bold; }
        
        /* Stats Cards Container */
        .stats-container {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            flex: 1;
            background: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            text-align: left;
        }
        .stat-card h3 { margin: 0; color: #888; font-size: 12px; text-transform: uppercase; }
        .stat-card p { margin: 5px 0 0 0; font-size: 22px; font-weight: bold; }
        .revenue-text { color: #28a745; }
        .pending-text { color: #dc3545; }
    </style>
</head>
<body>

<div class="container">

    <?php if (!isset($_SESSION['admin_logged_in'])): ?>
        
        <h2>Admin Login</h2>
        <?php if ($error !== '') { echo "<p style='color:red;'>$error</p>"; } ?>

        <form method="post">
            <label>Username</label>
            <input type="text" name="username" required>

            <label>Password</label>
            <input type="password" name="password" required>

            <br><br>
            <button type="submit" class="primary-btn">Log In</button>
        </form>

    <?php else: ?>

        <h2>Welcome, <?php echo $_SESSION['admin_username']; ?></h2>
        
        <?php
            // Fetch Total Revenue
            $rev_q = "SELECT SUM(total_amount) AS total FROM requests WHERE payment_status = 'paid'";
            $rev_res = $conn->query($rev_q);
            $rev_data = $rev_res->fetch_assoc();
            $total_revenue = $rev_data['total'] ?? 0;

            // Fetch Pending Count
            $pen_q = "SELECT COUNT(*) AS count FROM requests WHERE payment_status = 'unpaid'";
            $pen_res = $conn->query($pen_q);
            $pen_data = $pen_res->fetch_assoc();
            $total_pending = $pen_data['count'] ?? 0;
        ?>

        <div class="stats-container">
            <div class="stat-card" style="border-left: 5px solid #28a745;">
                <h3>Total Revenue</h3>
                <p class="revenue-text">GH₵ <?php echo number_format($total_revenue, 2); ?></p>
            </div>
            <div class="stat-card" style="border-left: 5px solid #dc3545;">
                <h3>Unpaid Requests</h3>
                <p class="pending-text"><?php echo $total_pending; ?> Students</p>
            </div>
        </div>
        <p>What would you like to do today?</p>
        <hr>

        <a href="manage_books.php" class="dashboard-btn">📚 Manage Books & Prices</a>
        <a href="view_request.php" class="dashboard-btn" style="background: #28a745;"> 📩 View User Requests</a>
        <a href="maintenance.php" class="dashboard-btn" style="background: #6c757d;">⚙️ System Maintenance (Reset)</a>
        <a href="admin_manual_order.php" class="dashboard-btn" style="background: #b19bdb;">➕ Record Direct Cash Payment</a>
        
        <a href="?logout=1" class="logout-link">Logout</a>

    <?php endif; ?>

</div>

</body>
</html>