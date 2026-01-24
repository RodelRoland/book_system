<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = $_POST['password'];
    // Your original logic stands and still works
    if ($password === '1234') { 
        $_SESSION['admin_logged_in'] = true;
        header('Location: /book_system/admin.php');
        exit;
    } else {
        $error = "Incorrect password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; }

        .login-wrapper {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .login-card {
            background: #f4f4f4;
            padding: 50px 35px;
            width: 100%;
            max-width: 380px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            text-align: center;
        }

        .login-card h2 {
            margin: 0 0 35px 0;
            font-size: 32px;
            color: #333;
            font-weight: 600;
        }

        .input-group { margin-bottom: 25px; text-align: left; }
        
        .input-group input {
            width: 100%;
            padding: 12px 5px;
            border: none;
            border-bottom: 2px solid #ccc;
            background: transparent;
            outline: none;
            font-size: 16px;
            color: #333;
            transition: border-color 0.3s;
        }

        .input-group input:focus { border-bottom: 2px solid #764ba2; }

        .login-btn {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(to right, #4facfe 0%, #a29bfe 100%);
            color: white;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 15px;
            box-shadow: 0 4px 15px rgba(118, 75, 162, 0.3);
            transition: opacity 0.3s;
        }

        .login-btn:hover { opacity: 0.9; }

        .error-msg { color: #ff4d4d; font-size: 14px; margin-bottom: 20px; font-weight: 500; }
        .footer-links { margin-top: 30px; font-size: 14px; }
        .footer-links a { text-decoration: none; color: #4facfe; }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">
        <h2>Login</h2>
        
        <?php if (isset($error)): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <input type="text" name="username" placeholder="Username" required>
            </div>
            
            <div class="input-group">
                <input type="password" name="password" placeholder="Password" required>
            </div>
            
            <button type="submit" class="login-btn">Login</button>
        </form>
    </div>
</div>

</body>
</html>