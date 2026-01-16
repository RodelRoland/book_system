<?php
session_start();
require_once 'db.php';

/* Protect admin page */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

/* Add new book */
if (isset($_POST['add_book'])) {
    $title = $conn->real_escape_string($_POST['book_title']);
    $price = floatval($_POST['price']);

    $conn->query("
        INSERT INTO books (book_title, price, availability)
        VALUES ('$title', $price, 'available')
    ");
}

/* Update book (price or availability) */
if (isset($_POST['update_book'])) {
    $book_id = intval($_POST['book_id']);
    $price = floatval($_POST['price']);
    $availability = $_POST['availability'] === 'out_of_stock'
        ? 'out_of_stock'
        : 'available';

    $conn->query("
        UPDATE books
        SET price = $price,
            availability = '$availability'
        WHERE book_id = $book_id
    ");
}

/* Fetch all books */
$books = $conn->query("SELECT * FROM books ORDER BY book_title ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Books</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<h2>Book Management</h2>

<p>
    <a href="admin.php">← Back to Admin Dashboard</a> |
    <a href="logout.php">Logout</a>
</p>

<hr>

<!-- ================= ADD NEW BOOK ================= -->

<h3>Add New Book</h3>

<form method="post">
    <label>Book Title:</label><br>
    <input type="text" name="book_title" required><br><br>

    <label>Price (GH₵):</label><br>
    <input type="number" step="0.01" name="price" required><br><br>

    <button type="submit" name="add_book">Add Book</button>
</form>

<hr>

<!-- ================= BOOK LIST ================= -->

<h3>Existing Books</h3>

<table border="1" cellpadding="8" cellspacing="0" width="100%">
<tr>
    <th>#</th>
    <th>Book Title</th>
    <th>Price (GH₵)</th>
    <th>Availability</th>
    <th>Action</th>
</tr>

<?php $i = 1; while ($row = $books->fetch_assoc()) { ?>
<tr>
    <form method="post">
        <td><?php echo $i++; ?></td>

        <td><?php echo htmlspecialchars($row['book_title']); ?></td>

        <td>
            <input type="number"
                   step="0.01"
                   name="price"
                   value="<?php echo number_format($row['price'], 2); ?>">
        </td>

        <td>
            <select name="availability">
                <option value="available"
                    <?php if ($row['availability'] === 'available') echo 'selected'; ?>>
                    Available
                </option>
                <option value="out_of_stock"
                    <?php if ($row['availability'] === 'out_of_stock') echo 'selected'; ?>>
                    Out of Stock
                </option>
            </select>
        </td>

        <td>
            <input type="hidden" name="book_id" value="<?php echo $row['book_id']; ?>">
            <button type="submit" name="update_book">Update</button>
        </td>
    </form>
</tr>
<?php } ?>

</table>

</body>
</html>
