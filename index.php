<?php
include 'db.php';

/* Fetch ONLY available books (inventory integrity enforced) */
$books = $conn->query("
    SELECT book_id, book_title, price
    FROM books
    WHERE availability = 'available'
    ORDER BY book_title ASC
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Course Material Request</title>
    <link rel="stylesheet" href="style.css">

    <script>
        function calculateTotal() {
            let subtotal = 0;

            document.querySelectorAll(".book-check").forEach(book => {
                if (book.checked) {
                    subtotal += parseFloat(book.dataset.price);
                }
            });

            let momoCharge = subtotal * 0.01;
            let finalAmount = subtotal + momoCharge;

            document.getElementById("subtotal").innerText = subtotal.toFixed(2);
            document.getElementById("momo_charge").innerText = momoCharge.toFixed(2);
            document.getElementById("final_total").innerText = finalAmount.toFixed(2);

            document.getElementById("total_amount").value = subtotal.toFixed(2);
            document.getElementById("payable_amount").value = finalAmount.toFixed(2);
        }
    </script>
</head>

<body>
    <div class="container">  

        <h2>Course Material Request Form</h2>

        <form method="post" action="submit_request.php">

            <label><strong>Index Number</strong></label><br>
            <input type="text"
                name="index_number"
                minlength="10"
                maxlength="10"
                required>
            <br><br>

            <label><strong>Full Name</strong></label><br>
            <input type="text" name="full_name" required>
            <br><br>

            <label><strong>Phone Number</strong></label><br>
            <input type="text" name="phone" required>
            <br><br>

            <hr>

            <h3>Select Course Materials</h3>

            <?php if ($books->num_rows === 0) { ?>
                <p><em>No books are currently available.</em></p>
            <?php } else { ?>
                <?php while ($row = $books->fetch_assoc()) { ?>
                    <label>
                        <input type="checkbox"
                            class="book-check"
                            name="books[]"
                            value="<?php echo $row['book_id']; ?>"
                            data-price="<?php echo $row['price']; ?>"
                            onclick="calculateTotal()">
                        <?php echo $row['book_title']; ?> –
                        GH₵ <?php echo number_format($row['price'], 2); ?>
                    </label>
                    <br>
                <?php } ?>
            <?php } ?>

            <hr>

            <p><strong>Subtotal:</strong> GH₵ <span id="subtotal">0.00</span></p>
            <p><strong>MoMo Charge:</strong> GH₵ <span id="momo_charge">0.00</span></p>
            <p><strong>Total Amount to Pay:</strong> GH₵ <span id="final_total">0.00</span></p>

            <!-- Hidden fields -->
            <input type="hidden" name="total_amount" id="total_amount">
            <input type="hidden" name="payable_amount" id="payable_amount">

            <br>
            <button type="submit">Proceed to Payment</button>

        </form>
    </div>
</body>
</html>
