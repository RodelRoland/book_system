<?php
global $conn;
include 'db.php';

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
                    minlength="10"
                    maxlength="10"
                    required
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
document.getElementById('index_number').addEventListener('blur', function() {
    var indexNum = this.value;
    var nameInput = document.getElementById('full_name');

    if (indexNum.length > 0) {
        // We use the same helper file we created earlier
        fetch('get_student_name.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'index_number=' + encodeURIComponent(indexNum)
        })
        .then(response => response.text())
        .then(data => {
            // This cleans up the response and puts it in the name box
            nameInput.value = data.trim();
            
            if(data.trim() === "Student Not Found") {
                nameInput.style.color = "#d63031"; // Red for error
            } else {
                nameInput.style.color = "#2d3436"; // Normal color
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
    }
});
</script>

</body>

<script>
document.querySelector('input[name="index_number"]').addEventListener('blur', function() {
    const indexNumber = this.value;
    if (indexNumber.length < 3) return; // Don't check empty or too short values

    fetch('check_student_books.php?index=' + indexNumber)
        .then(response => response.json())
        .then(ownedBooks => {
            // Re-enable all first to reset the form
            document.querySelectorAll('input[name="books[]"]').forEach(checkbox => {
                checkbox.disabled = false;
                checkbox.parentElement.style.opacity = "1";
                checkbox.parentElement.title = "";
            });

            // Disable books the student already owns
            ownedBooks.forEach(bookId => {
                const checkbox = document.querySelector(`input[name="books[]"][value="${bookId}"]`);
                if (checkbox) {
                    checkbox.disabled = true;
                    checkbox.checked = false; // Uncheck it if they tried to select it before typing index
                    checkbox.parentElement.style.opacity = "0.5";
                    checkbox.parentElement.style.textDecoration = "line-through";
                    checkbox.parentElement.title = "You have already requested this book.";
                }
            });
        });
});
</script>

</html>