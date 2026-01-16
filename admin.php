<?php
session_start();
require_once 'db.php';

/* Protect admin page */
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

/* Actions */
if (isset($_GET['mark_paid'])) {
    $conn->query("UPDATE requests SET payment_status='paid' WHERE request_id=".(int)$_GET['mark_paid']);
}
if (isset($_GET['give_out'])) {
    $conn->query("UPDATE request_items SET status='given_out' WHERE request_item_id=".(int)$_GET['give_out']);
}

/* Filters */
$search = $_GET['search'] ?? '';
$payment = $_GET['payment'] ?? 'all';
$collection = $_GET['collection'] ?? 'all';

/* ================= DASHBOARD ================= */
$dashboard = $conn->query("
    SELECT 
        b.book_title,
        b.price,
        COUNT(ri.request_item_id) total_requested,
        SUM(ri.status='given_out') total_sold
    FROM books b
    LEFT JOIN request_items ri ON b.book_id = ri.book_id
    GROUP BY b.book_id
");

/* Books */
$books = [];
$res = $conn->query("SELECT book_id, book_title FROM books");
while ($b = $res->fetch_assoc()) $books[] = $b;

/* ================= REQUEST QUERY WITH FILTERS ================= */
$sql = "
SELECT 
    r.request_id,
    r.total_amount,
    r.payment_status,
    s.index_number,
    s.full_name,
    COUNT(ri.request_item_id) total_items,
    SUM(ri.status='given_out') given_items
FROM requests r
JOIN students s ON r.student_id = s.student_id
JOIN request_items ri ON r.request_id = ri.request_id
WHERE 1=1
";

if ($search !== '') {
    $safe = $conn->real_escape_string($search);
    $sql .= " AND (s.index_number LIKE '%$safe%' OR s.full_name LIKE '%$safe%')";
}
if ($payment !== 'all') {
    $sql .= " AND r.payment_status='$payment'";
}

$sql .= " GROUP BY r.request_id ";

if ($collection === 'completed') {
    $sql .= " HAVING total_items = given_items ";
} elseif ($collection === 'pending') {
    $sql .= " HAVING total_items > given_items ";
}

$requests = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin – Book Distribution</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
    

            <h2>Admin Dashboard</h2>

            <p>
                <a href="export_excel.php">Export to Excel</a> |
                <a href="manage_books.php">Manage Books</a> |
                <a href="maintenance.php">System Maintenance</a> |
                <a href="logout.php">Logout</a>


            </p>

            <!-- ================= FILTER BAR ================= -->

            <form method="get">
                Search:
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>">

                Payment:
                <select name="payment">
                    <option value="all">All</option>
                    <option value="paid" <?php if($payment==='paid') echo 'selected'; ?>>Paid</option>
                    <option value="unpaid" <?php if($payment==='unpaid') echo 'selected'; ?>>Unpaid</option>
                </select>

                Collection:
                <select name="collection">
                    <option value="all">All</option>
                    <option value="completed" <?php if($collection==='completed') echo 'selected'; ?>>Completed</option>
                    <option value="pending" <?php if($collection==='pending') echo 'selected'; ?>>Pending</option>
                </select>

                <button type="submit">Apply</button>
            </form>

            <br>

            <!-- ================= DASHBOARD ================= -->

            <h3>Book Sales Summary</h3>
            <table border="1" cellpadding="6">
            <tr>
                <th>Book</th>
                <th>Requested</th>
                <th>Sold</th>
            </tr>
            <?php while($d=$dashboard->fetch_assoc()){ ?>
            <tr>
                <td><?php echo $d['book_title']; ?></td>
                <td><?php echo $d['total_requested']; ?></td>
                <td><?php echo $d['total_sold']; ?></td>
            </tr>
            <?php } ?>
            </table>

            <br>

            <!-- ================= REQUEST TABLE ================= -->

            <a id="requests"></a>
            <h3>Student Requests</h3>

            <table border="1" cellpadding="6">
            <tr>
                <th>#</th>
                <th>Index</th>
                <th>Name</th>
                <?php foreach($books as $b){ echo "<th>{$b['book_title']}</th><th>Given</th>"; } ?>
                <th>Total</th>
                <th>Payment</th>
            </tr>

            <?php $n=1; while($r=$requests->fetch_assoc()){ ?>
            <tr>
                <td><?php echo $n++; ?></td>
                <td><?php echo $r['index_number']; ?></td>
                <td><?php echo $r['full_name']; ?></td>

            <?php
            $items=[];
            $res=$conn->query("SELECT * FROM request_items WHERE request_id={$r['request_id']}");
            while($i=$res->fetch_assoc()) $items[$i['book_id']]=$i;

            foreach($books as $b){
                if(isset($items[$b['book_id']])){
                    echo "<td>✔</td><td>";
                    if($items[$b['book_id']]['status']=='given_out') echo "✓";
                    elseif($r['payment_status']=='paid')
                        echo "<a href='admin.php?give_out={$items[$b['book_id']]['request_item_id']}#requests'>Give</a>";
                    else echo "Pay";
                    echo "</td>";
                } else {
                    echo "<td>—</td><td>—</td>";
                }
            }
            ?>

                <td><?php echo number_format($r['total_amount'],2); ?></td>
                <td>
                    <?php if($r['payment_status']=='unpaid'){ ?>
                        <a href="admin.php?mark_paid=<?php echo $r['request_id']; ?>#requests">Mark Paid</a>
                    <?php } else echo "PAID"; ?>
                </td>
            </tr>
            <?php } ?>
            </table>

    </div>
    </body>
</html>
