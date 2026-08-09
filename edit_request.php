<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}
include 'db.php';

$raw_return_url = trim((string)($_GET['return_url'] ?? $_POST['return_url'] ?? ''));
$return_url = 'view_request.php';
if ($raw_return_url !== '' && !preg_match('/^\w+:\/\//', $raw_return_url)) {
    $normalized_return_url = ltrim($raw_return_url, '/');
    if (stripos($normalized_return_url, 'view_request.php') === 0) {
        $return_url = $normalized_return_url;
    }
}

if (!isset($_GET['id'])) { die("Request ID missing."); }

$access_context = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$session_role = strval($_SESSION['admin_role'] ?? 'rep');
if (!$access_context) {
    header('Location: ' . ($session_role === 'super_admin' ? 'manage_reps.php?msg=rep_private' : 'login.php'));
    exit;
}
$current_admin_id = intval($access_context['effective_admin_id'] ?? 0);
$current_admin_role = 'rep';
$is_super_admin = false;

$csrf_token = csrf_get_token();

$request_id = intval($_GET['id']);
if ($request_id <= 0) {
    header('Location: view_request.php?msg=invalid_request');
    exit;
}

// 1. Get current request and student details
if ($is_super_admin) {
    $req_stmt = $conn->prepare("SELECT r.*, s.full_name FROM requests r JOIN students s ON r.student_id = s.student_id WHERE r.request_id = ? LIMIT 1");
    $req_stmt->bind_param('i', $request_id);
} else {
    $req_stmt = $conn->prepare("SELECT r.*, s.full_name FROM requests r JOIN students s ON r.student_id = s.student_id WHERE r.request_id = ? AND r.admin_id = ? LIMIT 1");
    $req_stmt->bind_param('ii', $request_id, $current_admin_id);
}

$req_stmt->execute();
$req_query = $req_stmt->get_result();
$request = ($req_query && $req_query->num_rows === 1) ? $req_query->fetch_assoc() : null;
if (!$request) {
    header('Location: view_request.php?msg=unauthorized');
    exit;
}

// 2. Get currently selected book IDs for this request
$current_books = [];
$current_collected_by_book = [];
$items_stmt = $conn->prepare("SELECT book_id FROM request_items WHERE request_id = ?");
$items_stmt->bind_param('i', $request_id);
$items_stmt->execute();
$items_query = $items_stmt->get_result();

if ($items_query) {
    while($item = $items_query->fetch_assoc()){
        $current_books[] = $item['book_id'];
    }
}

$items_stmt = $conn->prepare("SELECT book_id, is_collected FROM request_items WHERE request_id = ?");
$items_stmt->bind_param('i', $request_id);
$items_stmt->execute();
$items_query = $items_stmt->get_result();
if ($items_query) {
    while ($item = $items_query->fetch_assoc()) {
        $current_collected_by_book[intval($item['book_id'])] = intval($item['is_collected']);
    }
}

// 3. Handle the Update Logic
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        header('Location: edit_request.php?id=' . $request_id . '&return_url=' . urlencode($return_url) . '&msg=csrf_invalid');
        exit;
    }
    $selected_books = isset($_POST['books']) ? $_POST['books'] : [];
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);
    $price_date = date('Y-m-d', strtotime($request['created_at'] ?? 'now'));
    
    // Delete old items for this request
    $del_stmt = $conn->prepare("DELETE FROM request_items WHERE request_id = ?");
    $del_stmt->bind_param('i', $request_id);
    $del_stmt->execute();
    
    $new_total = 0;
    foreach ($selected_books as $book_id) {
        $book_id = intval($book_id);
        if ($book_id <= 0) {
            continue;
        }

        $unit_price = null;
        $hpstmt = $conn->prepare("SELECT new_price FROM book_price_history WHERE book_id = ? AND effective_date IS NOT NULL AND effective_date <= ? ORDER BY effective_date DESC, history_id DESC LIMIT 1");
        if ($hpstmt) {
            $hpstmt->bind_param('is', $book_id, $price_date);
            $hpstmt->execute();
            $hpres = $hpstmt->get_result();
            if ($hpres && $hpres->num_rows === 1) {
                $unit_price = floatval($hpres->fetch_assoc()['new_price']);
            }
        }

        if ($unit_price === null) {
            $hnstmt = $conn->prepare("SELECT old_price FROM book_price_history WHERE book_id = ? AND effective_date IS NOT NULL AND effective_date > ? ORDER BY effective_date ASC, history_id ASC LIMIT 1");
            if ($hnstmt) {
                $hnstmt->bind_param('is', $book_id, $price_date);
                $hnstmt->execute();
                $hnres = $hnstmt->get_result();
                if ($hnres && $hnres->num_rows === 1) {
                    $unit_price = floatval($hnres->fetch_assoc()['old_price']);
                }
            }
        }

        if ($unit_price === null) {
            $pstmt = $conn->prepare("SELECT price FROM books WHERE book_id = ? LIMIT 1");
            $pstmt->bind_param('i', $book_id);
            $pstmt->execute();
            $pres = $pstmt->get_result();
            if ($pres && $pres->num_rows === 1) {
                $unit_price = floatval($pres->fetch_assoc()['price']);
            }
        }

        if ($unit_price === null) {
            continue;
        }

        $new_total += $unit_price;
        
        $is_collected = isset($current_collected_by_book[$book_id]) ? intval($current_collected_by_book[$book_id]) : 0;
        $ins_stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id, unit_price, is_collected) VALUES (?, ?, ?, ?)");
        $ins_stmt->bind_param('iidi', $request_id, $book_id, $unit_price, $is_collected);
        $ins_stmt->execute();
    }
    
    $credit_used = floatval($request['credit_used'] ?? 0);
    $status = (($amount_paid + $credit_used) >= $new_total) ? 'paid' : 'unpaid';
    
    // Update the main request table
    if ($is_super_admin) {
        $upd_stmt = $conn->prepare("UPDATE requests SET total_amount = ?, amount_paid = ?, payment_status = ? WHERE request_id = ?");
        $upd_stmt->bind_param('ddsi', $new_total, $amount_paid, $status, $request_id);
    } else {
        $upd_stmt = $conn->prepare("UPDATE requests SET total_amount = ?, amount_paid = ?, payment_status = ? WHERE request_id = ? AND admin_id = ?");
        $upd_stmt->bind_param('ddsii', $new_total, $amount_paid, $status, $request_id, $current_admin_id);
    }
    $upd_stmt->execute();
    
    $return_parts = parse_url($return_url);
    $return_path = strval($return_parts['path'] ?? 'view_request.php');
    if ($return_path === '') {
        $return_path = 'view_request.php';
    }
    $return_query = [];
    if (!empty($return_parts['query'])) {
        parse_str($return_parts['query'], $return_query);
    }
    $return_query['msg'] = 'updated';
    $redirect_target = $return_path . '?' . http_build_query($return_query);
    if (!empty($return_parts['fragment'])) {
        $redirect_target .= '#' . $return_parts['fragment'];
    }

    header('Location: ' . $redirect_target);
    exit();
}

// 4. Get books available to this request owner
if ($is_super_admin) {
    $all_books = $conn->query("SELECT * FROM books ORDER BY book_title ASC");
} else {
    $all_books_stmt = $conn->prepare("SELECT * FROM books WHERE admin_id = ? OR admin_id IS NULL ORDER BY book_title ASC");
    $all_books = false;
    if ($all_books_stmt) {
        $all_books_stmt->bind_param('i', $current_admin_id);
        $all_books_stmt->execute();
        $all_books = $all_books_stmt->get_result();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Request</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 500px; margin: 0 auto; }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 16px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        .page-header h1 { font-size: 20px; font-weight: 600; }
        .page-header .subtitle { opacity: 0.9; margin-top: 3px; font-size: 13px; }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .books-list {
            max-height: 250px;
            overflow-y: auto;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .book-item {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        .book-item:hover { background: #f8f9fa; }
        .book-item:last-child { border-bottom: none; }
        .book-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 12px;
            cursor: pointer;
        }
        .book-item .title { flex: 1; font-weight: 500; color: #333; font-size: 14px; }
        .book-item .price { color: #667eea; font-weight: 600; font-size: 13px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .form-input:focus { outline: none; border-color: #667eea; }
        
        .btn-save {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .btn-cancel {
            display: block;
            width: 100%;
            text-align: center;
            margin-top: 15px;
            color: #888;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 6px 0;
        }
        .btn-cancel:hover { color: #333; }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <h1>&#9998; Edit Request</h1>
        <p class="subtitle"><?php echo htmlspecialchars($request['full_name']); ?></p>
    </div>
    
    <div class="card">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($return_url); ?>">
            <div class="section-title">Select Books</div>
            <div class="books-list">
                <?php while($book = $all_books->fetch_assoc()): ?>
                    <label class="book-item">
                        <input type="checkbox" name="books[]" class="book-checkbox" 
                               value="<?php echo $book['book_id']; ?>" 
                               data-price="<?php echo $book['price']; ?>"
                            <?php echo in_array($book['book_id'], $current_books) ? 'checked' : ''; ?>>
                        <span class="title"><?php echo htmlspecialchars($book['book_title']); ?></span>
                        <span class="price">GH&#8373; <?php echo number_format($book['price'], 2); ?></span>
                    </label>
                <?php endwhile; ?>
            </div>

            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 14px; opacity: 0.9;">Total Amount</span>
                <span style="font-size: 24px; font-weight: 700;">GH&#8373; <span id="display_total"><?php echo number_format($request['total_amount'], 2); ?></span></span>
            </div>

            <div class="form-group">
                <label>Amount Paid (GH&#8373;)</label>
                <input type="number" step="0.01" name="amount_paid" id="amount_paid" class="form-input" value="<?php echo $request['amount_paid']; ?>">
            </div>

            <button type="submit" class="btn-save">Update Request</button>
            <button type="button" class="btn-cancel" data-return-url="<?php echo htmlspecialchars($return_url); ?>" onclick="goBackToRequests(this)">Cancel</button>
        </form>
    </div>
</div>

<script>
const checkboxes = document.querySelectorAll('.book-checkbox');
const displayTotal = document.getElementById('display_total');
const amountPaid = document.getElementById('amount_paid');

function calculateTotal() {
    let total = 0;
    checkboxes.forEach(cb => {
        if (cb.checked) total += parseFloat(cb.getAttribute('data-price'));
    });
    displayTotal.innerText = total.toFixed(2);
}

checkboxes.forEach(cb => cb.addEventListener('change', calculateTotal));

// Calculate on page load
calculateTotal();

function goBackToRequests(button) {
    const fallbackUrl = button?.dataset?.returnUrl || 'view_request.php';
    const referrer = document.referrer || '';

    if (referrer.indexOf('view_request.php') !== -1 && window.history.length > 1) {
        let fallbackTriggered = false;
        const fallbackTimer = window.setTimeout(() => {
            fallbackTriggered = true;
            window.location.href = fallbackUrl;
        }, 700);

        window.addEventListener('pageshow', function onPageShow() {
            if (fallbackTriggered) {
                return;
            }

            window.clearTimeout(fallbackTimer);
            window.removeEventListener('pageshow', onPageShow);
        });

        window.history.back();
        return;
    }

    window.location.href = fallbackUrl;
}
</script>

<?php include 'footer.php'; ?>

</body>
</html>

