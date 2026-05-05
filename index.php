<?php
global $conn;
include 'db.php';

// Get rep context from a direct username link or the common portal's rep_id routing
$rep_id = 0;
$rep_info = null;
$rep_username_param_supplied = array_key_exists('rep', $_GET);
$rep_id_param_supplied = array_key_exists('rep_id', $_GET);
$rep_param_supplied = $rep_username_param_supplied || $rep_id_param_supplied;
$invalid_rep_link = false;

if ($rep_id_param_supplied) {
    $requested_rep_id = intval($_GET['rep_id'] ?? 0);
    if ($requested_rep_id > 0) {
        $rep_stmt = $conn->prepare("SELECT admin_id, full_name, class_name FROM admins WHERE admin_id = ? AND is_active = 1 LIMIT 1");
        if ($rep_stmt) {
            $rep_stmt->bind_param('i', $requested_rep_id);
            $rep_stmt->execute();
            $rep_query = $rep_stmt->get_result();
            if ($rep_query && $rep_query->num_rows > 0) {
                $rep_info = $rep_query->fetch_assoc();
                $rep_id = intval($rep_info['admin_id']);
            }
            $rep_stmt->close();
        }
    }
}

if ($rep_id <= 0 && $rep_username_param_supplied) {
    $rep_username = substr(trim(strval($_GET['rep'] ?? '')), 0, 50);
    if ($rep_username !== '') {
        $rep_stmt = $conn->prepare("SELECT admin_id, full_name, class_name FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
        if ($rep_stmt) {
            $rep_stmt->bind_param('s', $rep_username);
            $rep_stmt->execute();
            $rep_query = $rep_stmt->get_result();
            if ($rep_query && $rep_query->num_rows > 0) {
                $rep_info = $rep_query->fetch_assoc();
                $rep_id = intval($rep_info['admin_id']);
            }
            $rep_stmt->close();
        }
    }
}

if ($rep_param_supplied && $rep_id <= 0) {
    $invalid_rep_link = true;
}

// Fallback to super admin
if ($rep_id <= 0 && !$rep_param_supplied) {
    $default_rep = $conn->query("SELECT admin_id, full_name, class_name FROM admins WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
    if ($default_rep && $default_rep->num_rows > 0) {
        $rep_info = $default_rep->fetch_assoc();
        $rep_id = intval($rep_info['admin_id']);
    }
}

/* Fetch ONLY available books for the selected rep (with caching for performance) */
$books_array = [];
if (function_exists('get_cached_books')) {
    $cached_books = get_cached_books($conn, true, $rep_id > 0 ? $rep_id : null);
    if (is_array($cached_books)) {
        $books_array = $cached_books;
    }
}
if (empty($books_array) && !function_exists('get_cached_books')) {
    $books_array = [];
    if ($rep_id > 0) {
        $books_stmt = $conn->prepare("SELECT book_id, book_title, price FROM books WHERE availability = 'available' AND (admin_id = ? OR admin_id IS NULL) ORDER BY book_title ASC");
        if ($books_stmt) {
            $books_stmt->bind_param('i', $rep_id);
            $books_stmt->execute();
            $books_result = $books_stmt->get_result();
            if ($books_result) {
                while ($row = $books_result->fetch_assoc()) {
                    $books_array[] = $row;
                }
            }
            $books_stmt->close();
        }
    } else {
        $books_result = $conn->query("SELECT book_id, book_title, price FROM books WHERE availability = 'available' ORDER BY book_title ASC");
        if ($books_result) {
            while ($row = $books_result->fetch_assoc()) {
                $books_array[] = $row;
            }
        }
    }
}

$csrf_token = csrf_get_token();
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

    <?php if ($invalid_rep_link): ?>
        <div style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:14px 16px; border-radius:12px; margin-bottom:18px;">
            This rep link is invalid or no longer active. Please contact your class rep for the correct order link.
        </div>
    <?php elseif ($rep_info): ?>
        <div style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:14px 16px; border-radius:12px; margin-bottom:18px;">
            Orders from this page will be assigned to <?php echo htmlspecialchars($rep_info['full_name'] ?? 'the selected rep'); ?><?php echo !empty($rep_info['class_name']) ? ' (' . htmlspecialchars($rep_info['class_name']) . ')' : ''; ?>.
        </div>
    <?php endif; ?>

    <?php if (!$invalid_rep_link): ?>
    <form method="post" action="submit_request.php" id="requestForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <!-- â”€â”€ STEP 1 â”€â”€ Personal Information â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
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
                    GH&#8373; <span id="credit_amount">0.00</span>
                </div>
                <small style="color: #155724;">This will be automatically applied to your next order.</small>
            </div>

            <div id="request_history_panel" style="display:none; background:#f8fafc; border:1px solid #cbd5e1; padding:14px; border-radius:10px; margin:15px 0;">
                <strong style="color:#1e293b; display:block; margin-bottom:10px;">Previous Requests</strong>
                <div id="request_history_list" style="display:grid; gap:8px;"></div>
            </div>

            <button type="button" id="btnToStep2" class="primary-btn">
                Proceed
            </button>

            <p class="step-info">Next: Select course materials</p>
        </div>

        <!-- â”€â”€ STEP 2 â”€â”€ Book Selection & Payment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <div id="step2" class="form-step">

            <div class="step-header">
                <span class="step-number">2</span>
                <h3>Select Course Materials</h3>
            </div>

            <button type="button" id="btnBack" class="secondary-btn">
                &larr; Back
            </button>

            <?php if (empty($books_array)) { ?>
                <div class="empty-state">
                    No books are currently available.
                </div>
            <?php } else { ?>
                <?php foreach ($books_array as $row) { ?>
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
                             - GH&#8373; <?php echo number_format($row['price'], 2); ?>
                        </span>
                    </label>
                <?php } ?>
            <?php } ?>

            <hr class="totals-divider">

            <div class="total-line">
                <span>Subtotal:</span>
                <strong>GH&#8373; <span id="subtotal">0.00</span></strong>
            </div>

            <div class="total-line">
                <span>MoMo Charge (1%):</span>
                <strong>GH&#8373; <span id="momo_charge">0.00</span></strong>
            </div>

            <div class="final-total">
                Total to Pay: GH&#8373; <span id="final_total">0.00</span>
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
    <?php endif; ?>

</div>

<script>
    // â”€â”€ CALCULATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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

    // â”€â”€ STEP NAVIGATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
    var historyPanel = document.getElementById('request_history_panel');
    var historyList = document.getElementById('request_history_list');
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
            historyPanel.style.display = 'none';
            historyList.innerHTML = '';
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
                    }
                    
                    // Always check owned books when student is found
                    checkOwnedBooks(data.full_index || indexNum);
                    loadStudentRequestHistory(data.full_index || indexNum);
                    
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
                    historyPanel.style.display = 'none';
                    historyList.innerHTML = '';
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
function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderStudentRequestHistory(historyRows) {
    var historyPanel = document.getElementById('request_history_panel');
    var historyList = document.getElementById('request_history_list');

    if (!historyPanel || !historyList) {
        return;
    }

    historyList.innerHTML = '';
    if (!Array.isArray(historyRows) || historyRows.length === 0) {
        historyPanel.style.display = 'none';
        return;
    }

    historyRows.forEach(function(row) {
        var isCollected = parseInt(row.is_collected || 0, 10) === 1;
        var isCancelled = parseInt(row.is_cancelled || 0, 10) === 1;
        var statusColor = isCancelled ? '#4b5563' : (isCollected ? '#166534' : '#92400e');
        var statusBg = isCancelled ? '#e5e7eb' : (isCollected ? '#dcfce7' : '#fef3c7');
        var paymentText = String(row.payment_status || '').toUpperCase();
        var statusText = isCancelled ? 'Refunded' : (isCollected ? 'Collected' : 'Not Collected');
        var refundBits = [];
        if (parseFloat(row.cash_refunded_amount || 0) > 0) {
            refundBits.push('Cash GH₵ ' + parseFloat(row.cash_refunded_amount || 0).toFixed(2));
        }
        if (parseFloat(row.credit_refunded_amount || 0) > 0) {
            refundBits.push('Credit GH₵ ' + parseFloat(row.credit_refunded_amount || 0).toFixed(2));
        }
        var requestedAt = row.created_at ? new Date(row.created_at.replace(' ', 'T')) : null;
        var dateText = requestedAt && !isNaN(requestedAt.getTime())
            ? requestedAt.toLocaleDateString()
            : '';

        var item = document.createElement('div');
        item.style.border = '1px solid #e2e8f0';
        item.style.borderRadius = '10px';
        item.style.padding = '10px 12px';
        item.style.background = 'white';
        item.innerHTML =
            '<div style="font-weight:700; color:#0f172a;">' + escapeHtml(row.book_title) + '</div>' +
            '<div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:6px;">' +
                '<span style="padding:4px 10px; border-radius:999px; background:' + statusBg + '; color:' + statusColor + '; font-size:12px; font-weight:700;">' + escapeHtml(statusText) + '</span>' +
                '<span style="padding:4px 10px; border-radius:999px; background:#e0f2fe; color:#075985; font-size:12px; font-weight:700;">' + escapeHtml(paymentText) + '</span>' +
                (refundBits.length ? '<span style="padding:4px 10px; border-radius:999px; background:#f3f4f6; color:#374151; font-size:12px; font-weight:700;">' + escapeHtml(refundBits.join(' | ')) + '</span>' : '') +
                (dateText ? '<span style="padding:4px 10px; border-radius:999px; background:#f1f5f9; color:#475569; font-size:12px; font-weight:700;">' + escapeHtml(dateText) + '</span>' : '') +
            '</div>';
        historyList.appendChild(item);
    });

    historyPanel.style.display = 'block';
}

function loadStudentRequestHistory(indexNumber) {
    if (!/^\d{10}$/.test(indexNumber)) {
        renderStudentRequestHistory([]);
        return;
    }

    fetch('get_student_request_history.php?index=' + encodeURIComponent(indexNumber) + '&rep_id=' + <?php echo intval($rep_id); ?>)
        .then(response => response.json())
        .then(data => {
            renderStudentRequestHistory(Array.isArray(data) ? data : []);
        })
        .catch(function() {
            renderStudentRequestHistory([]);
        });
}

// Function to check owned books (called after index is filled)
function checkOwnedBooks(indexNumber) {
    if (indexNumber.length < 3) return;
     
    fetch('check_student_books.php?index=' + encodeURIComponent(indexNumber) + '&rep_id=' + <?php echo intval($rep_id); ?>)
        .then(response => response.json())
        .then(ownedBooks => {
            // Re-enable all first to reset the form
            document.querySelectorAll('.book-check').forEach(cb => {
                cb.disabled = false;
                cb.parentElement.classList.remove('book-owned');
                cb.parentElement.style.opacity = "1";
                cb.parentElement.style.textDecoration = "none";
                cb.parentElement.title = "";
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
    loadStudentRequestHistory(this.value);
});
</script>
    
<?php include 'footer.php'; ?>
    
</body>
</html>


