<?php
global $conn;
include 'db.php';

// Get rep ID from URL parameter (allows each rep to share their unique link)
$rep_id = 0;
$rep_info = null;
if (isset($_GET['rep'])) {
    $rep_username = $conn->real_escape_string($_GET['rep']);
    $rep_query = $conn->query("SELECT admin_id, full_name, class_name FROM admins WHERE username = '$rep_username' AND is_active = 1");
    if ($rep_query && $rep_query->num_rows > 0) {
        $rep_info = $rep_query->fetch_assoc();
        $rep_id = intval($rep_info['admin_id']);
    }
}

// Fallback to super admin
if ($rep_id <= 0) {
    $default_rep = $conn->query("SELECT admin_id, full_name, class_name FROM admins WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
    if ($default_rep && $default_rep->num_rows > 0) {
        $rep_info = $default_rep->fetch_assoc();
        $rep_id = intval($rep_info['admin_id']);
    }
}

/* Fetch ONLY available books */
$books = $conn->query("
    SELECT book_id, book_title, price
    FROM books
    WHERE availability = 'available'
    ORDER BY book_title ASC
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Course Material Request</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="container">

    <h2>Course Material Request</h2>

    <form method="post" action="submit_request.php" id="requestForm">

        <!-- ── STEP 1 ── Personal Information ─────────────────────────────── -->
        <div id="step1" class="form-step active">

            <div class="step-header">
                <span class="step-number">1</span>
                <h3>Personal Information</h3>
            </div>

            <label for="index_number">Index Number <span class="required">*</span></label>
            <input
                    type="text"
                    id="index_number"
                    name="index_number"
                    minlength="3"
                    maxlength="10"
                    required
                    placeholder="Enter full index or last 3 digits"
            >

            <label for="full_name">Full Name <span class="required">*</span></label>
            <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    required
            >

            <label for="phone">Phone Number </label>
            <input
                    type="tel"
                    id="phone"
                    name="phone"
            >

            <div id="credit_info" style="display: none; background: #d4edda; border: 1px solid #28a745; padding: 12px; border-radius: 8px; margin: 15px 0;">
                <strong style="color: #155724;">You have a credit balance!</strong>
                <div style="font-size: 20px; color: #28a745; font-weight: bold; margin-top: 5px;">
                    GH₵ <span id="credit_amount">0.00</span>
                </div>
                <small style="color: #155724;">This will be automatically applied to your next order.</small>
            </div>

            <button type="button" id="btnToStep2" class="primary-btn">
                Proceed
            </button>

            <p class="step-info">Next: Select course materials</p>
        </div>

        <!-- ── STEP 2 ── Book Selection & Payment ───────────────────────────── -->
        <div id="step2" class="form-step">

            <div class="step-header">
                <span class="step-number">2</span>
                <h3>Select Course Materials</h3>
            </div>

            <button type="button" id="btnBack" class="secondary-btn">
                ← Back
            </button>

            <?php if ($books->num_rows === 0) { ?>
                <div class="empty-state">
                    No books are currently available.
                </div>
            <?php } else { ?>
                <?php while ($row = $books->fetch_assoc()) { ?>
                    <label class="book-item">
                        <input
                                type="checkbox"
                                class="book-check"
                                name="books[]"
                                value="<?php echo $row['book_id']; ?>"
                                data-price="<?php echo $row['price']; ?>"
                        >
                        <span class="book-title">
                            <?php echo htmlspecialchars($row['book_title']); ?>
                            – GH₵ <?php echo number_format($row['price'], 2); ?>
                        </span>
                    </label>
                <?php } ?>
            <?php } ?>

            <hr class="totals-divider">

            <div class="total-line">
                <span>Subtotal:</span>
                <strong>GH₵ <span id="subtotal">0.00</span></strong>
            </div>

            <div class="total-line">
                <span>MoMo Charge (1%):</span>
                <strong>GH₵ <span id="momo_charge">0.00</span></strong>
            </div>

            <div class="final-total">
                Total to Pay: GH₵ <span id="final_total">0.00</span>
            </div>

            <!-- Hidden fields -->
            <input type="hidden" name="total_amount" id="total_amount" value="0.00">
            <input type="hidden" name="payable_amount" id="payable_amount" value="0.00">
            <input type="hidden" name="rep_id" value="<?php echo $rep_id; ?>">

            <button type="submit" class="primary-btn">
                Proceed to Payment
            </button>

            <p class="step-info">Make sure at least one item is selected</p>
        </div>

    </form>

</div>

<script>
    // ── CALCULATION ────────────────────────────────────────────────
    function calculateTotal() {
        let subtotal = 0;
        document.querySelectorAll(".book-check").forEach(book => {
            if (book.checked) {
                subtotal += parseFloat(book.dataset.price || 0);
            }
        });

        const momoCharge = subtotal * 0.01;
        const finalAmount = subtotal + momoCharge;

        document.getElementById("subtotal").textContent = subtotal.toFixed(2);
        document.getElementById("momo_charge").textContent = momoCharge.toFixed(2);
        document.getElementById("final_total").textContent = finalAmount.toFixed(2);

        document.getElementById("total_amount").value = subtotal.toFixed(2);
        document.getElementById("payable_amount").value = finalAmount.toFixed(2);
    }

    // Attach listeners
    document.querySelectorAll(".book-check").forEach(book => {
        book.addEventListener("change", calculateTotal);
    });

    // ── STEP NAVIGATION ─────────────────────────────────────────────
    const step1 = document.getElementById("step1");
    const step2 = document.getElementById("step2");
    const btnToStep2 = document.getElementById("btnToStep2");
    const btnBack = document.getElementById("btnBack");
    const form = document.getElementById("requestForm");

    btnToStep2.addEventListener("click", () => {
        // Simple client-side validation
        const indexEl = document.getElementById("index_number");
        const nameEl = document.getElementById("full_name");
        const phoneEl = document.getElementById("phone");

        if (!indexEl.checkValidity() || !nameEl.checkValidity() || !phoneEl.checkValidity()) {
            form.reportValidity();
            return;
        }

        // Optional: stricter index number check
        if (!/^\d{10}$/.test(indexEl.value)) {
            alert("Index number must be exactly 10 digits.");
            return;
        }

        step1.classList.remove("active");
        step2.classList.add("active");
        calculateTotal(); // refresh totals (in case someone comes back)
    });

    btnBack.addEventListener("click", () => {
        step2.classList.remove("active");
        step1.classList.add("active");
    });

    // Prevent submit if no books selected
    form.addEventListener("submit", (e) => {
        const anyChecked = document.querySelector(".book-check:checked");
        if (!anyChecked) {
            e.preventDefault();
            alert("Please select at least one course material.");
        }
    });
</script>

<script>
(function() {
    var indexInput = document.getElementById('index_number');
    var nameInput = document.getElementById('full_name');
    var phoneInput = document.getElementById('phone');
    var creditInfo = document.getElementById('credit_info');
    var creditAmount = document.getElementById('credit_amount');
    var lookupTimeout = null;

    // Real-time lookup as user types (triggers after 3+ characters)
    indexInput.addEventListener('input', function() {
        var indexNum = this.value.trim();
        
        // Clear previous timeout
        if (lookupTimeout) clearTimeout(lookupTimeout);
        
        // Need at least 3 characters to search
        if (indexNum.length < 3) {
            nameInput.value = '';
            creditInfo.style.display = 'none';
            return;
        }
        
        // Debounce: wait 300ms after user stops typing
        lookupTimeout = setTimeout(function() {
            var repId = <?php echo $rep_id; ?>;
            fetch('get_student_credit.php?index=' + encodeURIComponent(indexNum) + '&rep_id=' + repId)
            .then(response => response.json())
            .then(data => {
                if (data.found) {
                    nameInput.value = data.full_name;
                    nameInput.style.color = "#2d3436";
                    if (data.phone) {
                        phoneInput.value = data.phone;
                    }
                    
                    // Auto-fill full index number if partial match
                    if (data.full_index && data.full_index !== indexNum) {
                        indexInput.value = data.full_index;
                        // Trigger book ownership check with full index
                        checkOwnedBooks(data.full_index);
                    }
                    
                    // Show credit balance if available
                    if (data.credit_balance > 0) {
                        creditAmount.textContent = data.credit_balance.toFixed(2);
                        creditInfo.style.display = 'block';
                    } else {
                        creditInfo.style.display = 'none';
                    }
                } else {
                    nameInput.value = '';
                    nameInput.style.color = "#2d3436";
                    creditInfo.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }, 300);
    });
})();
</script>
    
<script>
// Function to check owned books (called after index is filled)
function checkOwnedBooks(indexNumber) {
    if (indexNumber.length < 3) return;
     
    fetch('check_student_books.php?index=' + indexNumber)
        .then(response => response.json())
        .then(ownedBooks => {
            // Re-enable all first to reset the form
            document.querySelectorAll('input[name="books[]"]').forEach(checkbox => {
                checkbox.disabled = false;
                checkbox.parentElement.style.opacity = "1";
                checkbox.parentElement.style.textDecoration = "";
                checkbox.parentElement.title = "";
            });
            
            // Disable books the student already owns
            ownedBooks.forEach(bookId => {
                const checkbox = document.querySelector(`input[name="books[]"][value="${bookId}"]`);
                if (checkbox) {
                    checkbox.disabled = true;
                    checkbox.checked = false;
                    checkbox.parentElement.style.opacity = "0.5";
                    checkbox.parentElement.style.textDecoration = "line-through";
                    checkbox.parentElement.title = "You have already requested this book.";
                }
            });
        });
}

// Also check on blur in case user typed full index directly
document.querySelector('input[name="index_number"]').addEventListener('blur', function() {
    checkOwnedBooks(this.value);
});
</script>
    
<?php include 'footer.php'; ?>
    
</body>
</html>