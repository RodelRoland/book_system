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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Maintenance</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 600px; margin: 0 auto; }
        
        .page-header {
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
        .page-header h1 { font-size: 22px; font-weight: 600; }
        .page-header .subtitle { opacity: 0.9; margin-top: 3px; font-size: 13px; }
        .back-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            border: 1px solid rgba(255,255,255,0.3);
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 16px;
            color: #333;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card p { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 15px; }
        .card ul { margin: 10px 0 15px 20px; color: #666; font-size: 14px; }
        .card ul li { margin-bottom: 5px; }
        
        .success-msg {
            background: #d4edda;
            color: #155724;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #28a745;
        }
        
        .btn {
            width: 100%;
            padding: 14px 20px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-warning {
            background: #ffc107;
            color: #333;
        }
        .btn-warning:hover { background: #e0a800; }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-danger:hover { background: #c82333; }
        
        .danger-zone {
            border: 2px solid #dc3545;
            background: #fff5f5;
        }
        .danger-zone h2 { color: #dc3545; }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>⚙️ System Maintenance</h1>
            <p class="subtitle">Reset and cleanup options</p>
        </div>
        <a href="admin.php" class="back-btn">← Back</a>
    </div>
    
    <?php if ($message !== ""): ?>
        <div class="success-msg"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <div class="card">
        <h2>🔄 Reset Requests</h2>
        <p>
            This will remove <strong>all book requests</strong> and <strong>request items</strong>.
            Books, prices, availability, and students will remain intact.
        </p>
        <form method="post" onsubmit="return confirm('Are you sure you want to clear ALL requests? This cannot be undone.');">
            <button type="submit" name="clear_requests" class="btn btn-warning">
                Clear All Requests
            </button>
        </form>
    </div>
    
    <div class="card danger-zone">
        <h2>⚠️ Danger Zone</h2>
        <p>This will completely reset the system:</p>
        <ul>
            <li>All students</li>
            <li>All requests</li>
            <li>All request items</li>
        </ul>
        <p><strong>This action cannot be undone.</strong></p>
        <form method="post" onsubmit="return confirm('THIS WILL DELETE ALL STUDENTS AND REQUESTS. Are you absolutely sure?');">
            <button type="submit" name="clear_students" class="btn btn-danger">
                Clear Students & Reset System
            </button>
        </form>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>
