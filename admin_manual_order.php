<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

// Fetch available books
$books_res = $conn->query("SELECT * FROM books WHERE availability = 'available'");

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $index_number = $conn->real_escape_string($_POST['index_number']);
    $phone = isset($_POST['phone']) ? $conn->real_escape_string($_POST['phone']) : '';
    $selected_books = isset($_POST['books']) ? $_POST['books'] : [];
    $cash_received = floatval($_POST['cash_received']);

    if (!empty($selected_books)) {
        $duplicate_titles = [];
        foreach ($selected_books as $book_id) {
            $book_id = intval($book_id);
            $check_sql = "SELECT b.book_title 
                          FROM request_items ri
                          JOIN requests r ON ri.request_id = r.request_id
                          JOIN students s ON r.student_id = s.student_id
                          JOIN books b ON ri.book_id = b.book_id
                          WHERE r.semester_id = $semester_id AND s.index_number = '$index_number' AND ri.book_id = '$book_id'";
            
            $check_res = $conn->query($check_sql);
            if ($check_res && $check_res->num_rows > 0) {
                $row = $check_res->fetch_assoc();
                $duplicate_titles[] = $row['book_title'];
            }
        }

        if (!empty($duplicate_titles)) {
            $error = "Duplicate Request: Student has already received: " . implode(", ", $duplicate_titles);
        } else {
            // Save/Update student details
            $conn->query("INSERT INTO students (index_number, full_name, phone, credit_balance) 
                          VALUES ('$index_number', '$full_name', '$phone', 0) 
                          ON DUPLICATE KEY UPDATE full_name='$full_name', phone='$phone'");
            
            $student_id_res = $conn->query("SELECT student_id, credit_balance FROM students WHERE index_number = '$index_number'");
            $student_data = $student_id_res->fetch_assoc();
            $student_id = $student_data['student_id'];
            $existing_credit = floatval($student_data['credit_balance']);

            $ids = implode(',', array_map('intval', $selected_books));
            $price_res = $conn->query("SELECT SUM(price) as total FROM books WHERE book_id IN ($ids)");
            $total_amount = floatval($price_res->fetch_assoc()['total']);

            // Calculate payment: existing credit + cash received
            $total_payment = $existing_credit + $cash_received;
            
            if ($total_payment >= $total_amount) {
                // Overpayment goes to credit balance
                $new_credit = $total_payment - $total_amount;
                $amount_paid = $total_amount;
            } else {
                // Underpayment (shouldn't happen for manual orders, but handle it)
                $new_credit = 0;
                $amount_paid = $total_payment;
            }
            
            // Update student's credit balance
            $conn->query("UPDATE students SET credit_balance = $new_credit WHERE student_id = $student_id");

            $semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
            $admin_id = intval($_SESSION['admin_id'] ?? 0);
            $sql_request = "INSERT INTO requests (student_id, total_amount, amount_paid, payment_status, created_at, semester_id, admin_id) 
                            VALUES ('$student_id', '$total_amount', '$amount_paid', 'paid', NOW(), '$semester_id', '$admin_id')";
            
            if ($conn->query($sql_request)) {
                $request_id = $conn->insert_id;
                foreach ($selected_books as $book_id) {
                    $book_id = intval($book_id);
                    $conn->query("INSERT INTO request_items (request_id, book_id) VALUES ('$request_id', '$book_id')");
                }
                
                // Show success message with credit info if applicable
                $msg = "manual_success";
                if ($new_credit > 0) {
                    $msg .= "&credit=" . $new_credit;
                }
                header("Location: view_request.php?msg=$msg");
                exit;
            } else {
                $error = "Database Error: " . $conn->error;
            }
        }
    } else {
        $error = "Please select at least one book.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manual Order</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }
        
        .page-container { max-width: 550px; margin: 0 auto; }
        
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
            font-size: 14px;
        }
        .back-btn:hover { background: rgba(255,255,255,0.3); }
        
        .card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .error-box {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #f44336;
        }
        
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
            font-size: 15px;
            transition: all 0.3s;
        }
        .form-input:focus { outline: none; border-color: #667eea; }
        .form-input.valid { border-color: #28a745; background: #f8fff8; }
        .form-input.readonly { background: #f8f9fa; color: #333; }
        
        .section-title {
            font-size: 14px;
            font-weight: 700;
            color: #333;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .books-list {
            max-height: 220px;
            overflow-y: auto;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .book-item {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        .book-item:hover { background: #f8f9fa; }
        .book-item:last-child { border-bottom: none; }
        .book-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }
        .book-item .title { flex: 1; font-weight: 500; color: #333; }
        .book-item .price { color: #667eea; font-weight: 700; }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .summary-row:last-child { margin-bottom: 0; }
        .summary-label { font-size: 14px; opacity: 0.9; }
        .summary-value { font-size: 28px; font-weight: 700; }
        
        .cash-input-group { margin-top: 15px; }
        .cash-input-group label { color: rgba(255,255,255,0.9); margin-bottom: 8px; display: block; font-weight: 600; }
        .cash-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 10px;
            font-size: 20px;
            font-weight: 700;
            background: rgba(255,255,255,0.15);
            color: white;
            text-align: center;
        }
        .cash-input::placeholder { color: rgba(255,255,255,0.5); }
        .cash-input:focus { outline: none; border-color: white; background: rgba(255,255,255,0.25); }
        
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn-submit:hover { background: #218838; transform: translateY(-2px); box-shadow: 0 5px 20px rgba(40,167,69,0.3); }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>➕ Manual Order</h1>
            <p class="subtitle">Record cash payment & issue books</p>
        </div>
        <a href="admin.php" class="back-btn">← Back</a>
    </div>
    
    <div class="card">
        <?php if (isset($error)): ?>
            <div class="error-box"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="post" id="orderForm">
            <div class="form-group">
                <label>Index Number</label>
                <input type="text" id="index_number" name="index_number" class="form-input" placeholder="Enter student index number" required>
            </div>
            
            <div class="form-group">
                <label>Student Name</label>
                <input type="text" id="full_name" name="full_name" class="form-input readonly" placeholder="Auto-filled from index" readonly>
            </div>
            
            <div class="form-group">
                <label>Phone (Optional)</label>
                <input type="text" id="phone" name="phone" class="form-input" placeholder="Enter phone number">
            </div>
            
            <div class="section-title">Select Books</div>
            <div class="books-list">
                <?php if ($books_res && $books_res->num_rows > 0): ?>
                    <?php while($b = $books_res->fetch_assoc()): ?>
                        <label class="book-item">
                            <input type="checkbox" name="books[]" class="book-checkbox" 
                                   data-price="<?php echo $b['price']; ?>" 
                                   value="<?php echo $b['book_id']; ?>">
                            <span class="title"><?php echo htmlspecialchars($b['book_title']); ?></span>
                            <span class="price">GH₵ <?php echo number_format($b['price'], 2); ?></span>
                        </label>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="padding: 20px; text-align: center; color: #888;">No books available</div>
                <?php endif; ?>
            </div>
            
            <div class="summary-card">
                <div class="summary-row">
                    <span class="summary-label">Total Amount</span>
                    <span class="summary-value">GH₵ <span id="display_total">0.00</span></span>
                </div>
                <div class="cash-input-group">
                    <label>Cash Received</label>
                    <input type="number" step="0.01" name="cash_received" id="cash_received" class="cash-input" placeholder="0.00" required>
                </div>
            </div>
            
            <button type="submit" class="btn-submit">✓ Complete Transaction</button>
        </form>
    </div>
</div>

<script>
document.getElementById('index_number').addEventListener('blur', function() {
    const index = this.value;
    const nameInput = document.getElementById('full_name');
    const phoneInput = document.getElementById('phone');
    
    if (index.length > 2) {
        // First, try class_students table (rep's uploaded roster)
        fetch('ajax_student_lookup.php?index=' + encodeURIComponent(index))
        .then(response => response.json())
        .then(data => {
            if (data.success && data.student_name) {
                nameInput.value = data.student_name;
                nameInput.classList.add('valid');
                nameInput.readOnly = false;
            } else {
                // Fall back to existing students table
                return fetch('get_student_credit.php?index=' + encodeURIComponent(index))
                    .then(response => response.json())
                    .then(data2 => {
                        if (data2.found) {
                            nameInput.value = data2.full_name;
                            nameInput.classList.add('valid');
                            if (data2.phone) phoneInput.value = data2.phone;
                        } else {
                            nameInput.value = '';
                            nameInput.classList.remove('valid');
                            nameInput.readOnly = false;
                            nameInput.placeholder = 'Enter student name manually';
                        }
                    });
            }
        });
    }
});

const checkboxes = document.querySelectorAll('.book-checkbox');
const displayTotal = document.getElementById('display_total');
const cashInput = document.getElementById('cash_received');

function calculateTotal() {
    let total = 0;
    checkboxes.forEach(cb => {
        if (cb.checked) total += parseFloat(cb.getAttribute('data-price'));
    });
    displayTotal.innerText = total.toFixed(2);
    cashInput.value = total.toFixed(2);
}

checkboxes.forEach(cb => cb.addEventListener('change', calculateTotal));
</script>

<?php include 'footer.php'; ?>

</body>
</html>