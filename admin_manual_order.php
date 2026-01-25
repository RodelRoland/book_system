<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin.php');
    exit;
}

// Fetch available books
$books_res = $conn->query("SELECT * FROM books WHERE availability = 'available'");

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
                          WHERE s.index_number = '$index_number' AND ri.book_id = '$book_id'";
            
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
            $conn->query("INSERT INTO students (index_number, full_name, phone) 
                          VALUES ('$index_number', '$full_name', '$phone') 
                          ON DUPLICATE KEY UPDATE full_name='$full_name', phone='$phone'");
            
            $student_id_res = $conn->query("SELECT student_id FROM students WHERE index_number = '$index_number'");
            $student_id = $student_id_res->fetch_assoc()['student_id'];

            $ids = implode(',', array_map('intval', $selected_books));
            $price_res = $conn->query("SELECT SUM(price) as total FROM books WHERE book_id IN ($ids)");
            $total_amount = $price_res->fetch_assoc()['total'];

            $sql_request = "INSERT INTO requests (student_id, total_amount, amount_paid, payment_status, created_at) 
                            VALUES ('$student_id', '$total_amount', '$cash_received', 'paid', NOW())";
            
            if ($conn->query($sql_request)) {
                $request_id = $conn->insert_id;
                foreach ($selected_books as $book_id) {
                    $book_id = intval($book_id);
                    $conn->query("INSERT INTO request_items (request_id, book_id) VALUES ('$request_id', '$book_id')");
                }
                header("Location: view_request.php?msg=manual_success");
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
<html>
<head>
    <title>Manual Order Entry</title>
    <link rel="stylesheet" href="style.css">
    <style>
            body { font-family: 'Segoe UI', sans-serif; background-color: #f0f2f5; padding: 20px; }
        .container { max-width: 500px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .back-link { display: inline-block; margin-bottom: 15px; text-decoration: none; color: #007bff; font-weight: 600; font-size: 14px; }

        /* Fixed section: added display block and increased margin */
        .input-field { 
            width: 100%; 
            padding: 12px; 
            margin-bottom: 20px; 
            display: block; 
            border: 1px solid #ccc; 
            border-radius: 8px; 
            box-sizing: border-box; 
        }

        h4 { margin: 15px 0 8px 0; font-size: 16px; color: #333; }
        .book-container { max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px; border-radius: 8px; background: #fafafa; margin-bottom: 15px; }
        .book-item { padding: 5px 0; font-size: 14px; border-bottom: 1px solid #f0f0f0; }
        .summary-box { background: #e8f4fd; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .cash-input { width: 100%; padding: 12px; border: 2px solid #28a745; border-radius: 8px; font-size: 18px; font-weight: bold; box-sizing: border-box; }
        .primary-btn { width: 100%; padding: 14px; background: #28a745; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .error-box { background: #ffe3e3; color: #d63031; padding: 12px; border: 1px solid #ff0000; border-radius: 8px; margin-bottom: 15px; font-size: 14px; }
    </style>
</head>
<body>
<div class="container">
    <a href="admin.php" class="back-link">← Back to Dashboard</a>
    <h2 style="margin-top: 0; color: #006064;">Manual Book Issue</h2>

    <?php if (isset($error)): ?>
        <div class="error-box"><strong>Error:</strong><br><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="post" id="orderForm">
        <input type="text" id="index_number" name="index_number" placeholder="Enter Index Number" required>

        <input type="text" id="full_name" name="full_name" placeholder="Student Name" readonly>
        
        <input type="text" name="phone" id="phone" class="input-field" placeholder="Phone Number (Optional)">
        
        <h4>Select Books:</h4>
        <div class="book-container">
            <?php if ($books_res && $books_res->num_rows > 0): ?>
                <?php while($b = $books_res->fetch_assoc()): ?>
                    <div class="book-item">
                        <label style="cursor: pointer;">
                            <input type="checkbox" name="books[]" class="book-checkbox" 
                                   data-price="<?php echo $b['price']; ?>" 
                                   value="<?php echo $b['book_id']; ?>"> 
                            <?php echo htmlspecialchars($b['book_title']); ?> 
                            <span style="color:#666;">(GH₵<?php echo number_format($b['price'], 2); ?>)</span>
                        </label>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>

        <div class="summary-box">
            <div style="display:flex; justify-content: space-between; font-weight: bold; margin-bottom: 10px;">
                <span>Total Amount:</span>
                <span>GH₵ <span id="display_total">0.00</span></span>
            </div>
            <label style="font-size: 14px; font-weight: bold; display: block; margin-bottom: 5px;">Cash Received (GH₵):</label>
            <input type="number" step="0.01" name="cash_received" id="cash_received" class="cash-input" placeholder="0.00" required>
        </div>
        
        <button type="submit" class="primary-btn">Complete Transaction</button>
    </form>
</div>

<script>
// 1. Logic to fetch student name by Index Number
document.getElementById('index_number').addEventListener('blur', function() {
    const index = this.value;
    if (index.length > 2) {
        fetch('get_student_details.php?index=' + index)
            .then(response => response.json())
            .then(data => {
                if (data) {
                    document.getElementById('full_name').value = data.full_name;
                    document.getElementById('phone').value = data.phone ? data.phone : "";
                    document.getElementById('index_number').style.borderColor = "#28a745";
                } else {
                    // Reset if index not found to allow new entry
                    document.getElementById('index_number').style.borderColor = "#ccc";
                }
            });
    }
});

// 2. Logic to calculate Total Amount based on selected books
const checkboxes = document.querySelectorAll('.book-checkbox');
const displayTotal = document.getElementById('display_total');
const cashInput = document.getElementById('cash_received');

function calculateTotal() {
    let total = 0;
    checkboxes.forEach(cb => {
        if (cb.checked) {
            total += parseFloat(cb.getAttribute('data-price'));
        }
    });
    displayTotal.innerText = total.toFixed(2);
    // Auto-fill cash received with total for faster processing
    cashInput.value = total.toFixed(2);
}

checkboxes.forEach(cb => {
    cb.addEventListener('change', calculateTotal);
});
</script>

<script>
document.getElementById('index_number').addEventListener('blur', function() {
    var indexNum = this.value;
    var nameInput = document.getElementById('full_name');

    if (indexNum.length > 0) {
        // Use Fetch API to get name from the database
        fetch('get_student_name.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'index_number=' + encodeURIComponent(indexNum)
        })
        .then(response => response.text())
        .then(data => {
            nameInput.value = data;
            
            // Optional: Change text color if student is not found
            if(data === "Student Not Found") {
                nameInput.style.color = "red";
            } else {
                nameInput.style.color = "green";
            }
        });
    }
});
</script>

</body>
</html>