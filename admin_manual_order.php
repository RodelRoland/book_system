<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

$admin_id = intval($_SESSION['admin_id'] ?? 0);
$current_admin_role = $_SESSION['admin_role'] ?? 'rep';
$is_super_admin = ($current_admin_role === 'super_admin');

$csrf_token = csrf_get_token();

// Fetch available books
$books_res = $conn->query("SELECT * FROM books WHERE availability = 'available'");

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $index_number = trim($_POST['index_number'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $selected_books = isset($_POST['books']) ? $_POST['books'] : [];
        $cash_received = floatval($_POST['cash_received'] ?? 0);

        if ($index_number === '' || $full_name === '') {
            $error = 'Index number and student name are required.';
        } elseif (empty($selected_books)) {
            $error = 'Please select at least one book.';
        } else {
            $duplicate_titles = [];
            foreach ($selected_books as $book_id) {
                $book_id = intval($book_id);
                if ($book_id <= 0) {
                    continue;
                }

                if ($is_super_admin) {
                    $dup_stmt = $conn->prepare("SELECT b.book_title FROM request_items ri JOIN requests r ON ri.request_id = r.request_id JOIN students s ON r.student_id = s.student_id JOIN books b ON ri.book_id = b.book_id WHERE r.semester_id = ? AND s.index_number = ? AND ri.book_id = ? LIMIT 1");
                    $dup_stmt->bind_param('isi', $semester_id, $index_number, $book_id);
                } else {
                    $dup_stmt = $conn->prepare("SELECT b.book_title FROM request_items ri JOIN requests r ON ri.request_id = r.request_id JOIN students s ON r.student_id = s.student_id JOIN books b ON ri.book_id = b.book_id WHERE r.semester_id = ? AND r.admin_id = ? AND s.index_number = ? AND ri.book_id = ? LIMIT 1");
                    $dup_stmt->bind_param('iisi', $semester_id, $admin_id, $index_number, $book_id);
                }

                if ($dup_stmt) {
                    $dup_stmt->execute();
                    $dup_res = $dup_stmt->get_result();
                    if ($dup_res && $dup_res->num_rows > 0) {
                        $row = $dup_res->fetch_assoc();
                        $duplicate_titles[] = $row['book_title'];
                    }
                }
            }

            if (!empty($duplicate_titles)) {
                $error = 'Duplicate Request: Student has already received: ' . implode(', ', $duplicate_titles);
            } else {
                $student_id = 0;
                $existing_credit = 0;

                $sstmt = $conn->prepare("SELECT student_id, credit_balance, admin_id FROM students WHERE index_number = ? LIMIT 1");
                $sstmt->bind_param('s', $index_number);
                $sstmt->execute();
                $sres = $sstmt->get_result();

                if ($sres && $sres->num_rows === 1) {
                    $srow = $sres->fetch_assoc();
                    $student_id = intval($srow['student_id']);
                    $existing_credit = floatval($srow['credit_balance']);
                    $student_admin_id = intval($srow['admin_id'] ?? 0);

                    if (!$is_super_admin && $student_admin_id > 0 && $student_admin_id !== $admin_id) {
                        $error = 'Student record is assigned to a different rep. Please contact the administrator.';
                    } else {
                        if (!$is_super_admin && $student_admin_id <= 0) {
                            $claim = $conn->prepare("UPDATE students SET admin_id = ? WHERE student_id = ? AND (admin_id IS NULL OR admin_id = 0)");
                            $claim->bind_param('ii', $admin_id, $student_id);
                            $claim->execute();
                        }

                        if ($is_super_admin) {
                            $up = $conn->prepare("UPDATE students SET full_name = ?, phone = ? WHERE student_id = ?");
                            $up->bind_param('ssi', $full_name, $phone, $student_id);
                            $up->execute();
                        } else {
                            $up = $conn->prepare("UPDATE students SET full_name = ?, phone = ? WHERE student_id = ? AND admin_id = ?");
                            $up->bind_param('ssii', $full_name, $phone, $student_id, $admin_id);
                            $up->execute();
                        }
                    }
                } else {
                    $ins = $conn->prepare("INSERT INTO students (index_number, full_name, phone, credit_balance, admin_id) VALUES (?, ?, ?, 0, ?)");
                    $ins->bind_param('sssi', $index_number, $full_name, $phone, $admin_id);
                    if ($ins->execute()) {
                        $student_id = intval($conn->insert_id);
                    } else {
                        $error = 'Database Error: ' . $conn->error;
                    }
                }

                if (!isset($error) && $student_id > 0) {
                    $total_amount = 0.0;
                    $book_prices = [];
                    $price_date = date('Y-m-d');
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

                        $book_prices[$book_id] = $unit_price;
                        $total_amount += $unit_price;
                    }

                    $total_payment = $existing_credit + $cash_received;

                    if ($total_payment >= $total_amount) {
                        $credit_used = max(0, $total_amount - $cash_received);
                        if ($credit_used > $existing_credit) {
                            $credit_used = $existing_credit;
                        }
                        $amount_paid = $cash_received;
                        $new_credit = $total_payment - $total_amount;
                        $payment_status = 'paid';
                    } else {
                        $credit_used = $existing_credit;
                        $amount_paid = $cash_received;
                        $new_credit = 0;
                        $payment_status = 'unpaid';
                    }

                    if ($is_super_admin) {
                        $cstmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ?");
                        if ($cstmt) {
                            $cstmt->bind_param('di', $new_credit, $student_id);
                            $cstmt->execute();
                        }
                    } else {
                        $cstmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ? AND admin_id = ?");
                        if ($cstmt) {
                            $cstmt->bind_param('dii', $new_credit, $student_id, $admin_id);
                            $cstmt->execute();
                        }
                    }

                    $semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
                    $req_stmt = $conn->prepare("INSERT INTO requests (student_id, total_amount, amount_paid, credit_used, payment_status, created_at, semester_id, admin_id) VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)");
                    $req_stmt->bind_param('idddsii', $student_id, $total_amount, $amount_paid, $credit_used, $payment_status, $semester_id, $admin_id);

                    if ($req_stmt->execute()) {
                        $request_id = intval($conn->insert_id);
                        $ri_stmt = $conn->prepare("INSERT INTO request_items (request_id, book_id, unit_price) VALUES (?, ?, ?)");
                        foreach ($selected_books as $book_id) {
                            $book_id = intval($book_id);
                            if ($book_id <= 0) {
                                continue;
                            }
                            if (!isset($book_prices[$book_id])) {
                                continue;
                            }
                            $unit_price = floatval($book_prices[$book_id]);
                            $ri_stmt->bind_param('iid', $request_id, $book_id, $unit_price);
                            $ri_stmt->execute();
                        }

                        $msg = 'manual_success';
                        if ($new_credit > 0) {
                            $msg .= '&credit=' . $new_credit;
                        }
                        header("Location: view_request.php?msg=$msg");
                        exit;
                    } else {
                        $error = 'Database Error: ' . $conn->error;
                    }
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
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
            
            <div id="credit_info" style="display: none; background: #d4edda; border: 2px solid #28a745; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                <strong style="color: #155724;"> Student has existing balance!</strong>
                <div style="font-size: 24px; color: #28a745; font-weight: bold; margin-top: 5px;">
                    GH₵ <span id="existing_credit">0.00</span>
                </div>
                <small style="color: #155724;">This will be automatically applied to the order.</small>
            </div>
            
            <div class="summary-card">
                <div class="summary-row">
                    <span class="summary-label">Total Amount</span>
                    <span class="summary-value">GH₵ <span id="display_total">0.00</span></span>
                </div>
                <div id="balance_applied_row" class="summary-row" style="display: none; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 10px;">
                    <span class="summary-label">Balance Applied</span>
                    <span style="font-size: 18px; font-weight: 600;">- GH₵ <span id="balance_applied">0.00</span></span>
                </div>
                <div id="amount_due_row" class="summary-row" style="display: none; border-top: 1px solid rgba(255,255,255,0.3); padding-top: 10px;">
                    <span class="summary-label">Amount Due</span>
                    <span style="font-size: 22px; font-weight: 700;">GH₵ <span id="amount_due">0.00</span></span>
                </div>
                <div class="cash-input-group">
                    <label>Cash Received</label>
                    <input type="number" step="0.01" name="cash_received" id="cash_received" class="cash-input" placeholder="0.00" required>
                </div>
            </div>
            
            <button type="submit" class="btn-submit"> Complete Transaction</button>
        </form>
    </div>
</div>

<script>
const indexInput = document.getElementById('index_number');
const nameInput = document.getElementById('full_name');
const phoneInput = document.getElementById('phone');
let lookupTimeout = null;

let studentCredit = 0;
const creditInfo = document.getElementById('credit_info');
const existingCreditEl = document.getElementById('existing_credit');
const balanceAppliedRow = document.getElementById('balance_applied_row');
const balanceAppliedEl = document.getElementById('balance_applied');
const amountDueRow = document.getElementById('amount_due_row');
const amountDueEl = document.getElementById('amount_due');

function updateCreditDisplay(credit) {
    studentCredit = credit;
    if (credit > 0) {
        existingCreditEl.textContent = credit.toFixed(2);
        creditInfo.style.display = 'block';
    } else {
        creditInfo.style.display = 'none';
    }
    calculateTotal();
}

// Real-time lookup as user types (triggers after 3+ characters)
indexInput.addEventListener('input', function() {
    const index = this.value.trim();
    
    // Clear previous timeout
    if (lookupTimeout) clearTimeout(lookupTimeout);
    
    // Need at least 3 characters to search
    if (index.length < 3) {
        nameInput.value = '';
        nameInput.classList.remove('valid');
        nameInput.placeholder = 'Auto-filled from index';
        updateCreditDisplay(0);
        return;
    }
    
    // Debounce: wait 300ms after user stops typing
    lookupTimeout = setTimeout(() => {
        // First, try class_students table (rep's uploaded roster)
        fetch('ajax_student_lookup.php?index=' + encodeURIComponent(index))
        .then(response => response.json())
        .then(data => {
            if (data.success && data.student_name) {
                nameInput.value = data.student_name;
                nameInput.classList.add('valid');
                nameInput.readOnly = false;
                // Auto-fill full index number if partial match
                if (data.full_index && data.full_index !== index) {
                    indexInput.value = data.full_index;
                }
                // Check for credit in students table with full index
                const fullIdx = data.full_index || index;
                fetch('get_student_credit.php?index=' + encodeURIComponent(fullIdx))
                    .then(r => r.json())
                    .then(cd => updateCreditDisplay(cd.found ? cd.credit_balance : 0));
                // Check owned books
                checkOwnedBooks(fullIdx);
            } else {
                // Fall back to existing students table
                return fetch('get_student_credit.php?index=' + encodeURIComponent(index))
                    .then(response => response.json())
                    .then(data2 => {
                        if (data2.found) {
                            nameInput.value = data2.full_name;
                            nameInput.classList.add('valid');
                            if (data2.phone) phoneInput.value = data2.phone;
                            // Auto-fill full index number if partial match
                            if (data2.full_index && data2.full_index !== index) {
                                indexInput.value = data2.full_index;
                            }
                            updateCreditDisplay(data2.credit_balance || 0);
                            // Check owned books
                            checkOwnedBooks(data2.full_index || index);
                        } else {
                            nameInput.value = '';
                            nameInput.classList.remove('valid');
                            nameInput.readOnly = false;
                            nameInput.placeholder = 'Enter student name manually';
                            updateCreditDisplay(0);
                        }
                    });
            }
        });
    }, 300);
});

const checkboxes = document.querySelectorAll('.book-checkbox');
const displayTotal = document.getElementById('display_total');
const cashInput = document.getElementById('cash_received');

let userEditedCash = false;

function calculateTotal() {
    let total = 0;
    checkboxes.forEach(cb => {
        if (cb.checked) total += parseFloat(cb.getAttribute('data-price'));
    });
    displayTotal.innerText = total.toFixed(2);
    
    // Show balance application if student has credit
    if (studentCredit > 0 && total > 0) {
        const creditToApply = Math.min(studentCredit, total);
        const amountDue = Math.max(0, total - studentCredit);
        
        balanceAppliedEl.textContent = creditToApply.toFixed(2);
        amountDueEl.textContent = amountDue.toFixed(2);
        balanceAppliedRow.style.display = 'flex';
        amountDueRow.style.display = 'flex';
        
        // Only auto-fill cash if user hasn't manually edited it
        if (!userEditedCash) {
            cashInput.value = amountDue.toFixed(2);
        }
    } else {
        balanceAppliedRow.style.display = 'none';
        amountDueRow.style.display = 'none';
        // Only auto-fill cash if user hasn't manually edited it
        if (!userEditedCash) {
            cashInput.value = total.toFixed(2);
        }
    }
}

// Track when user manually edits the cash field
cashInput.addEventListener('input', function() {
    userEditedCash = true;
    
    // Quick-fill feature: if cash equals total of all available books, auto-select all
    const enteredAmount = parseFloat(this.value) || 0;
    const allBooksTotal = getAllAvailableBooksTotal();
    
    if (enteredAmount > 0 && Math.abs(enteredAmount - allBooksTotal) < 0.01) {
        // Select all enabled (non-owned) books
        checkboxes.forEach(cb => {
            if (!cb.disabled) {
                cb.checked = true;
            }
        });
        calculateTotal();
    }
});

// Calculate total price of all available (enabled) books
function getAllAvailableBooksTotal() {
    let total = 0;
    checkboxes.forEach(cb => {
        if (!cb.disabled) {
            total += parseFloat(cb.getAttribute('data-price')) || 0;
        }
    });
    return total;
}

checkboxes.forEach(cb => cb.addEventListener('change', calculateTotal));

// Function to check and disable already owned books
function checkOwnedBooks(indexNumber) {
    if (!indexNumber || indexNumber.length < 3) return;
    
    fetch('check_student_books.php?index=' + encodeURIComponent(indexNumber))
        .then(response => response.json())
        .then(ownedBooks => {
            // Re-enable all first to reset
            checkboxes.forEach(checkbox => {
                checkbox.disabled = false;
                checkbox.checked = false;
                checkbox.parentElement.style.opacity = "1";
                checkbox.parentElement.style.textDecoration = "";
                checkbox.parentElement.title = "";
                // Remove any existing "already owned" badge
                const badge = checkbox.parentElement.querySelector('.owned-badge');
                if (badge) badge.remove();
            });
            
            // Disable books the student already owns
            ownedBooks.forEach(bookId => {
                const checkbox = document.querySelector(`.book-checkbox[value="${bookId}"]`);
                if (checkbox) {
                    checkbox.disabled = true;
                    checkbox.checked = false;
                    checkbox.parentElement.style.opacity = "0.5";
                    checkbox.parentElement.style.textDecoration = "line-through";
                    checkbox.parentElement.title = "Student already has this book";
                    // Add "Already Owned" badge
                    const badge = document.createElement('span');
                    badge.className = 'owned-badge';
                    badge.style.cssText = 'background:#dc3545;color:white;font-size:10px;padding:2px 6px;border-radius:4px;margin-left:8px;';
                    badge.textContent = 'Already Owned';
                    checkbox.parentElement.appendChild(badge);
                }
            });
            
            calculateTotal();
        });
}
</script>

<?php include 'footer.php'; ?>

</body>
</html>