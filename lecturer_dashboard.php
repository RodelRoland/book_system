<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['lecturer_logged_in']) || intval($_SESSION['lecturer_logged_in']) !== 1) {
    header('Location: lecturer_login.php');
    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    if (!csrf_validate($_GET['csrf_token'] ?? null)) {
        header('Location: lecturer_dashboard.php?msg=csrf_invalid');
        exit;
    }
    session_destroy();
    header('Location: lecturer_login.php');
    exit;
}

$lecturer_id = intval($_SESSION['lecturer_id'] ?? 0);
$lecturer_name = $_SESSION['lecturer_full_name'] ?? $_SESSION['lecturer_username'] ?? 'Lecturer';
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;

$csrf_token = csrf_get_token();
$success_msg = '';
$error_msg = '';

// Ensure lecturer exists & active
$stmt = $conn->prepare("SELECT lecturer_id, full_name, is_active FROM lecturers WHERE lecturer_id = ? LIMIT 1");
$lecturer_row = null;
if ($stmt) {
    $stmt->bind_param('i', $lecturer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $lecturer_row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
}
if (!$lecturer_row || intval($lecturer_row['is_active'] ?? 0) !== 1) {
    session_destroy();
    header('Location: lecturer_login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_material'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $book_id = intval($_POST['book_id'] ?? 0);
        if ($book_id <= 0) {
            $error_msg = 'Please select a book.';
        } else {
            $stmt = $conn->prepare("INSERT IGNORE INTO lecturer_books (lecturer_id, book_id) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('ii', $lecturer_id, $book_id);
                $stmt->execute();
                $success_msg = 'Course material added.';
            } else {
                $error_msg = 'Database error. Please try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_material'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $book_id = intval($_POST['book_id'] ?? 0);
        if ($book_id <= 0) {
            $error_msg = 'Invalid request.';
        } else {
            $cnt = $conn->prepare("SELECT COUNT(*) AS c FROM lecturer_distributions WHERE lecturer_id = ? AND book_id = ? AND semester_id = ?");
            $has_rows = 0;
            if ($cnt) {
                $cnt->bind_param('iii', $lecturer_id, $book_id, $semester_id);
                $cnt->execute();
                $cres = $cnt->get_result();
                if ($cres && $cres->num_rows === 1) {
                    $has_rows = intval($cres->fetch_assoc()['c'] ?? 0);
                }
            }

            if ($has_rows > 0) {
                $error_msg = 'You cannot remove this material because it already has distribution entries for this semester.';
            } else {
                $del = $conn->prepare("DELETE FROM lecturer_books WHERE lecturer_id = ? AND book_id = ?");
                if ($del) {
                    $del->bind_param('ii', $lecturer_id, $book_id);
                    $del->execute();
                    $success_msg = 'Course material removed.';
                } else {
                    $error_msg = 'Database error. Please try again.';
                }
            }
        }
    }
}

// Record distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_distribution'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $book_id = intval($_POST['book_id'] ?? 0);
        $rep_admin_id = intval($_POST['rep_admin_id'] ?? 0);
        $copies_given = intval($_POST['copies_given'] ?? 0);
        $given_date = trim(strval($_POST['given_date'] ?? ''));
        $notes = trim(strval($_POST['notes'] ?? ''));

        if ($book_id <= 0 || $copies_given === 0 || $given_date === '') {
            $error_msg = 'Please fill in all required fields.';
        } else {
            // Validate book is assigned to this lecturer
            $ok = false;
            $chk = $conn->prepare("SELECT 1 FROM lecturer_books WHERE lecturer_id = ? AND book_id = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('ii', $lecturer_id, $book_id);
                $chk->execute();
                $cres = $chk->get_result();
                $ok = ($cres && $cres->num_rows === 1);
            }

            if (!$ok) {
                $error_msg = 'This book is not assigned to your account.';
            } else {
                $ins = $conn->prepare("INSERT INTO lecturer_distributions (lecturer_id, book_id, rep_admin_id, copies_given, given_date, notes, semester_id) VALUES (?, ?, NULLIF(?, 0), ?, ?, ?, ?)");
                if ($ins) {
                    $ins->bind_param('iiiissi', $lecturer_id, $book_id, $rep_admin_id, $copies_given, $given_date, $notes, $semester_id);
                    if ($ins->execute()) {
                        $success_msg = 'Distribution recorded successfully.';
                    } else {
                        $error_msg = 'Failed to record distribution.';
                    }
                } else {
                    $error_msg = 'Database error. Please try again.';
                }
            }
        }
    }
}

// Update distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_distribution'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $distribution_id = intval($_POST['distribution_id'] ?? 0);
        $book_id = intval($_POST['book_id'] ?? 0);
        $rep_admin_id = intval($_POST['rep_admin_id'] ?? 0);
        $copies_given = intval($_POST['copies_given'] ?? 0);
        $given_date = trim(strval($_POST['given_date'] ?? ''));
        $notes = trim(strval($_POST['notes'] ?? ''));

        if ($distribution_id <= 0 || $book_id <= 0 || $copies_given === 0 || $given_date === '') {
            $error_msg = 'Please fill in all required fields.';
        } else {
            $chk = $conn->prepare("SELECT 1 FROM lecturer_distributions WHERE distribution_id = ? AND lecturer_id = ? AND book_id = ? LIMIT 1");
            $ok = false;
            if ($chk) {
                $chk->bind_param('iii', $distribution_id, $lecturer_id, $book_id);
                $chk->execute();
                $cres = $chk->get_result();
                $ok = ($cres && $cres->num_rows === 1);
            }
            if (!$ok) {
                $error_msg = 'Unauthorized action.';
            } else {
                $upd = $conn->prepare("UPDATE lecturer_distributions
                    SET rep_admin_id = NULLIF(?, 0), copies_given = ?, given_date = ?, notes = ?
                    WHERE distribution_id = ? AND lecturer_id = ?");
                if ($upd) {
                    $upd->bind_param('iissii', $rep_admin_id, $copies_given, $given_date, $notes, $distribution_id, $lecturer_id);
                    if ($upd->execute()) {
                        $success_msg = 'Entry updated.';
                    } else {
                        $error_msg = 'Failed to update entry.';
                    }
                } else {
                    $error_msg = 'Database error. Please try again.';
                }
            }
        }
    }
}

// Delete distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_distribution'])) {
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error_msg = 'Invalid request. Please refresh and try again.';
    } else {
        $distribution_id = intval($_POST['distribution_id'] ?? 0);
        $book_id = intval($_POST['book_id'] ?? 0);
        if ($distribution_id <= 0 || $book_id <= 0) {
            $error_msg = 'Invalid request.';
        } else {
            $del = $conn->prepare("DELETE FROM lecturer_distributions WHERE distribution_id = ? AND lecturer_id = ? AND book_id = ?");
            if ($del) {
                $del->bind_param('iii', $distribution_id, $lecturer_id, $book_id);
                $del->execute();
                if ($del->affected_rows === 1) {
                    $success_msg = 'Entry deleted.';
                } else {
                    $error_msg = 'Delete failed.';
                }
            } else {
                $error_msg = 'Database error. Please try again.';
            }
        }
    }
}

// Fetch assigned books + totals
$assigned_books_list = [];
$assigned_book_ids = [];
$stmt = $conn->prepare("SELECT b.book_id, b.book_title, b.price
    FROM lecturer_books lb
    JOIN books b ON b.book_id = lb.book_id
    WHERE lb.lecturer_id = ?
    ORDER BY b.book_title ASC");
if ($stmt) {
    $stmt->bind_param('i', $lecturer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $assigned_books_list[] = $row;
            $assigned_book_ids[intval($row['book_id'])] = true;
        }
    }
}

$all_books_list = [];
$res = $conn->query("SELECT book_id, book_title FROM books ORDER BY book_title ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $all_books_list[] = $row;
    }
}

$selected_book_id = intval($_GET['book_id'] ?? 0);
$selected_book_title = '';
if ($selected_book_id > 0) {
    $stmt = $conn->prepare("SELECT b.book_title FROM lecturer_books lb JOIN books b ON b.book_id = lb.book_id WHERE lb.lecturer_id = ? AND lb.book_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $lecturer_id, $selected_book_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows === 1) {
            $selected_book_title = strval($res->fetch_assoc()['book_title'] ?? '');
        } else {
            $selected_book_id = 0;
        }
    } else {
        $selected_book_id = 0;
    }
}

$book_totals = [];
$stmt = $conn->prepare("SELECT book_id, COALESCE(SUM(copies_given), 0) AS total
    FROM lecturer_distributions
    WHERE lecturer_id = ? AND semester_id = ?
    GROUP BY book_id");
if ($stmt) {
    $stmt->bind_param('ii', $lecturer_id, $semester_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $book_totals[intval($r['book_id'])] = intval($r['total'] ?? 0);
        }
    }
}

$rep_totals = null;
$recent_distributions = null;
if ($selected_book_id > 0) {
    $stmt = $conn->prepare("SELECT a.admin_id, a.full_name, a.class_name, COALESCE(SUM(ld.copies_given), 0) AS copies
        FROM lecturer_distributions ld
        LEFT JOIN admins a ON a.admin_id = ld.rep_admin_id
        WHERE ld.lecturer_id = ? AND ld.book_id = ? AND ld.semester_id = ?
        GROUP BY a.admin_id, a.full_name, a.class_name
        ORDER BY copies DESC, a.full_name ASC");
    if ($stmt) {
        $stmt->bind_param('iii', $lecturer_id, $selected_book_id, $semester_id);
        $stmt->execute();
        $rep_totals = $stmt->get_result();
    }

    $stmt = $conn->prepare("SELECT ld.distribution_id, ld.rep_admin_id, ld.copies_given, ld.given_date, ld.notes,
            a.full_name AS rep_name, a.class_name
        FROM lecturer_distributions ld
        LEFT JOIN admins a ON a.admin_id = ld.rep_admin_id
        WHERE ld.lecturer_id = ? AND ld.book_id = ? AND ld.semester_id = ?
        ORDER BY ld.given_date DESC, ld.distribution_id DESC
        LIMIT 30");
    if ($stmt) {
        $stmt->bind_param('iii', $lecturer_id, $selected_book_id, $semester_id);
        $stmt->execute();
        $recent_distributions = $stmt->get_result();
    }
}

// Reps dropdown (for recording)
$reps = null;
$reps_list = [];
$reps_stmt = $conn->prepare("SELECT admin_id, full_name, class_name FROM admins WHERE role = 'rep' AND is_active = 1 ORDER BY full_name ASC");
if ($reps_stmt) {
    $reps_stmt->execute();
    $reps = $reps_stmt->get_result();
    if ($reps) {
        while ($r = $reps->fetch_assoc()) {
            $reps_list[] = $r;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8ec 100%);
            min-height: 100vh;
            padding: 30px 20px;
        }

        .page-container { max-width: 1100px; margin: 0 auto; }

        .page-header {
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(17, 24, 39, 0.28);
            gap: 12px;
            flex-wrap: wrap;
        }
        .page-header h1 { font-size: 22px; font-weight: 800; }
        .page-header .subtitle { opacity: 0.9; margin-top: 6px; font-size: 13px; }

        .btn {
            background: rgba(255,255,255,0.14);
            color: white;
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.25);
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:hover { background: rgba(255,255,255,0.22); }

        .grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 20px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }

        .card {
            background: white;
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .card h2 {
            font-size: 16px;
            color: #111827;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 15px; font-size: 14px; }
        .alert-success { background: #d4edda; color: #155724; border-left: 4px solid #28a745; }
        .alert-error { background: #ffebee; color: #c62828; border-left: 4px solid #f44336; }

        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-weight: 700; color: #555; margin-bottom: 8px; font-size: 13px; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
        }
        .form-group textarea { min-height: 70px; resize: vertical; }

        .btn-primary {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #111827 0%, #374151 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
        }
        .btn-primary:hover { opacity: 0.95; }

        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            font-weight: 800;
            border-bottom: 2px solid #e9ecef;
        }
        td { padding: 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; }

        .pill {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            font-weight: 800;
            font-size: 12px;
            border: 1px solid rgba(55,48,163,0.15);
        }
    </style>
</head>
<body>

<div class="page-container">
    <div class="page-header">
        <div>
            <h1>Lecturer Portal</h1>
            <div class="subtitle">Welcome, <?php echo htmlspecialchars($lecturer_name); ?></div>
        </div>
        <div style="display:flex; gap: 10px; flex-wrap: wrap;">
            <a class="btn" href="?logout=1&csrf_token=<?php echo urlencode($csrf_token); ?>">Logout</a>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="grid">
        <div>
            <div class="card">
                <h2>Assigned Books (This Semester)</h2>
                <div style="overflow:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Book</th>
                            <th>Copies Given</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($assigned_books_list) > 0): ?>
                            <?php foreach ($assigned_books_list as $b): ?>
                                <?php
                                    $bid = intval($b['book_id']);
                                    $given = intval($book_totals[$bid] ?? 0);
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($b['book_title']); ?></strong></td>
                                    <td><span class="pill"><?php echo number_format($given); ?></span></td>
                                    <td>
                                        <a class="btn" style="background:#111827; border-color:#111827;" href="?book_id=<?php echo $bid; ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" style="color:#666;">No books have been assigned to your account yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <?php if ($selected_book_id > 0): ?>
                <div class="card">
                    <h2>Distribution Summary — <?php echo htmlspecialchars($selected_book_title); ?></h2>

                    <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Course Rep</th>
                                <th>Class</th>
                                <th>Copies Given</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rep_totals && $rep_totals->num_rows > 0): ?>
                                <?php while ($r = $rep_totals->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['full_name'] ?: 'Unknown'); ?></td>
                                        <td><?php echo htmlspecialchars($r['class_name'] ?: '—'); ?></td>
                                        <td><strong><?php echo number_format(intval($r['copies'] ?? 0)); ?></strong></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" style="color:#666;">No distributions recorded yet for this book.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <div style="height: 16px;"></div>

                    <h2 style="border-bottom:none; padding-bottom:0; margin-bottom:10px;">Recent Entries</h2>
                    <div style="overflow:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Rep</th>
                                <th>Copies</th>
                                <th>Notes</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_distributions && $recent_distributions->num_rows > 0): ?>
                                <?php while ($d = $recent_distributions->fetch_assoc()): ?>
                                    <?php $d_rep_id = intval($d['rep_admin_id'] ?? 0); ?>
                                    <tr>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="distribution_id" value="<?php echo intval($d['distribution_id']); ?>">
                                            <input type="hidden" name="book_id" value="<?php echo intval($selected_book_id); ?>">
                                            <td>
                                                <input type="date" name="given_date" value="<?php echo htmlspecialchars($d['given_date']); ?>" required>
                                            </td>
                                            <td>
                                                <select name="rep_admin_id">
                                                    <option value="0">-- Unknown --</option>
                                                    <?php foreach ($reps_list as $rr): ?>
                                                        <?php $rid = intval($rr['admin_id']); ?>
                                                        <option value="<?php echo $rid; ?>" <?php echo ($d_rep_id === $rid) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars(($rr['full_name'] ?? '') !== '' ? $rr['full_name'] : ($rr['class_name'] ?? 'Rep')); ?>
                                                            <?php if (!empty($rr['class_name'])): ?> (<?php echo htmlspecialchars($rr['class_name']); ?>)<?php endif; ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" name="copies_given" step="1" value="<?php echo intval($d['copies_given'] ?? 0); ?>" required>
                                            </td>
                                            <td>
                                                <input type="text" name="notes" value="<?php echo htmlspecialchars($d['notes'] ?? ''); ?>">
                                            </td>
                                            <td>
                                                <button type="submit" name="update_distribution" value="1" class="btn" style="background:#111827; border-color:#111827;">Update</button>
                                                <button type="submit" name="delete_distribution" value="1" class="btn" style="background:#dc3545; border-color:#dc3545;" onclick="return confirm('Delete this entry?');">Delete</button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="5" style="color:#666;">No entries yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <h2>My Course Materials</h2>

                <form method="POST" style="margin-bottom: 14px;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="add_material" value="1">

                    <div class="form-group">
                        <label>Add Material</label>
                        <select name="book_id" required>
                            <option value="">-- Select book --</option>
                            <?php foreach ($all_books_list as $ab): ?>
                                <?php $bid = intval($ab['book_id']); ?>
                                <?php if (!isset($assigned_book_ids[$bid])): ?>
                                    <option value="<?php echo $bid; ?>"><?php echo htmlspecialchars($ab['book_title']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-primary">Add</button>
                </form>

                <div style="overflow:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($assigned_books_list) > 0): ?>
                            <?php foreach ($assigned_books_list as $b): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($b['book_title']); ?></td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Remove this material?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="remove_material" value="1">
                                            <input type="hidden" name="book_id" value="<?php echo intval($b['book_id']); ?>">
                                            <button type="submit" class="btn" style="background:#dc3545; border-color:#dc3545;">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="2" style="color:#666;">No materials selected yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="card">
                <h2>Record Books Given to Course Rep</h2>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="record_distribution" value="1">

                    <div class="form-group">
                        <label>Book *</label>
                        <select name="book_id" required>
                            <option value="">-- Select assigned book --</option>
                            <?php foreach ($assigned_books_list as $b): ?>
                                <?php $bid = intval($b['book_id']); ?>
                                <option value="<?php echo $bid; ?>" <?php echo ($selected_book_id === $bid) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['book_title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Course Rep (Optional)</label>
                        <select name="rep_admin_id">
                            <option value="0">-- Unknown / Not selected --</option>
                            <?php foreach ($reps_list as $r): ?>
                                <option value="<?php echo intval($r['admin_id']); ?>">
                                    <?php echo htmlspecialchars(($r['full_name'] ?? '') !== '' ? $r['full_name'] : ($r['class_name'] ?? 'Rep')); ?>
                                    <?php if (!empty($r['class_name'])): ?> (<?php echo htmlspecialchars($r['class_name']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Copies Given *</label>
                        <input type="number" name="copies_given" step="1" required placeholder="e.g. 50 (use -50 to correct)">
                    </div>

                    <div class="form-group">
                        <label>Date *</label>
                        <input type="date" name="given_date" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" placeholder="e.g. First batch for Data Com"></textarea>
                    </div>

                    <button type="submit" class="btn-primary">Record Distribution</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>
