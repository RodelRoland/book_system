<?php
global $conn;
include 'db.php';
require_once __DIR__ . '/app_helpers.php';

$hasDirectRequestContext = (
    isset($_GET['rep_id']) ||
    isset($_GET['rep']) ||
    isset($_GET['student_index'])
);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$hasDirectRequestContext) {
    header('Location: common_request_portal.php', true, 302);
    exit;
}

function portal_request_pop_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    $flash = $_SESSION['portal_request_flash'] ?? null;
    unset($_SESSION['portal_request_flash']);

    return is_array($flash) ? $flash : null;
}

function request_rep_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'CB';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'CB';
}

function request_rep_build_avatar_source(?string $path): array
{
    $path = trim((string) $path);
    if ($path === '') {
        return ['src' => '', 'optimized' => false];
    }

    if (preg_match('#^https?://#i', $path)) {
        return ['src' => $path, 'optimized' => false];
    }

    $normalized = ltrim(str_replace(['\\', '../'], ['/', ''], $path), '/');
    $sourcePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (!is_file($sourcePath)) {
        return ['src' => $path, 'optimized' => false];
    }

    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $cacheDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'request_avatars';
    $cacheRelativeRoot = 'cache/request_avatars';

    if (!is_dir($cacheDirectory)) {
        @mkdir($cacheDirectory, 0775, true);
    }

    $thumbBasename = 'rep_' . md5($sourcePath . '|' . filemtime($sourcePath)) . '.jpg';
    $thumbPath = $cacheDirectory . DIRECTORY_SEPARATOR . $thumbBasename;
    $thumbRelative = $cacheRelativeRoot . '/' . $thumbBasename;

    if (!is_file($thumbPath) && extension_loaded('gd')) {
        $image = null;
        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            $image = @imagecreatefromjpeg($sourcePath);
        } elseif ($extension === 'png') {
            $image = @imagecreatefrompng($sourcePath);
        } elseif ($extension === 'gif') {
            $image = @imagecreatefromgif($sourcePath);
        } elseif ($extension === 'webp' && function_exists('imagecreatefromwebp')) {
            $image = @imagecreatefromwebp($sourcePath);
        }

        if ($image) {
            $sourceWidth = imagesx($image);
            $sourceHeight = imagesy($image);
            $targetSize = 160;
            $cropSize = min($sourceWidth, $sourceHeight);
            $srcX = (int) floor(($sourceWidth - $cropSize) / 2);
            $srcY = (int) floor(($sourceHeight - $cropSize) / 2);

            $thumb = imagecreatetruecolor($targetSize, $targetSize);
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 245, 248, 255));
            imagecopyresampled(
                $thumb,
                $image,
                0,
                0,
                $srcX,
                $srcY,
                $targetSize,
                $targetSize,
                $cropSize,
                $cropSize
            );
            @imagejpeg($thumb, $thumbPath, 78);
            imagedestroy($thumb);
            imagedestroy($image);
        }
    }

    if (is_file($thumbPath)) {
        return ['src' => $thumbRelative, 'optimized' => true];
    }

    return ['src' => $path, 'optimized' => false];
}

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
        $rep_stmt = $conn->prepare("SELECT admin_id, full_name, public_display_name, profile_photo_path, class_name FROM admins WHERE admin_id = ? AND is_active = 1 LIMIT 1");
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
        $rep_stmt = $conn->prepare("SELECT admin_id, full_name, public_display_name, profile_photo_path, class_name FROM admins WHERE username = ? AND is_active = 1 LIMIT 1");
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
    $default_rep = $conn->query("SELECT admin_id, full_name, public_display_name, profile_photo_path, class_name FROM admins WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
    if ($default_rep && $default_rep->num_rows > 0) {
        $rep_info = $default_rep->fetch_assoc();
        $rep_id = intval($rep_info['admin_id']);
    }
}

$requestPaymentSettings = ($rep_id > 0 && function_exists('book_system_get_admin_payment_settings'))
    ? book_system_get_admin_payment_settings($conn, $rep_id)
    : ['effective_method' => 'manual_momo', 'has_paystack_keys' => false];
$studentFacingPaystackFeeRate = function_exists('book_system_paystack_fee_rate')
    ? floatval(book_system_paystack_fee_rate($conn))
    : 0.0195;
$studentFacingPaystackEnabled = (
    strval($requestPaymentSettings['effective_method'] ?? 'manual_momo') === 'paystack'
    && !empty($requestPaymentSettings['has_paystack_keys'])
);

/* Fetch ONLY available books for the selected rep (with caching for performance) */
$books_array = [];
$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}
if (function_exists('get_cached_books')) {
    $cached_books = get_cached_books($conn, true, $rep_id > 0 ? $rep_id : null, false, $semester_id > 0 ? $semester_id : null);
    if (is_array($cached_books)) {
        $books_array = $cached_books;
    }
}
if (empty($books_array) && !function_exists('get_cached_books')) {
    $books_array = [];
    if ($rep_id > 0) {
        $books_stmt = $conn->prepare("SELECT book_id, book_title, price
            FROM books
            WHERE availability = 'available'
              AND admin_id = ?
              AND semester_id = ?
            ORDER BY book_title ASC");
        if ($books_stmt) {
            $books_stmt->bind_param('ii', $rep_id, $semester_id);
            $books_stmt->execute();
            $books_result = $books_stmt->get_result();
            if ($books_result) {
                while ($row = $books_result->fetch_assoc()) {
                    $books_array[] = $row;
                }
            }
            $books_stmt->close();
        }
    }
}

$csrf_token = csrf_get_token();
$submission_token = function_exists('book_system_issue_submission_token')
    ? book_system_issue_submission_token('portal_request')
    : '';
$portal_flash_notice = portal_request_pop_flash();
$repDisplayName = trim((string) ($rep_info['public_display_name'] ?? ''));
if ($repDisplayName === '') {
    $repDisplayName = trim((string) ($rep_info['full_name'] ?? ''));
}
$repAvatar = request_rep_build_avatar_source($rep_info['profile_photo_path'] ?? '');
$repAvatarInitials = request_rep_initials($repDisplayName !== '' ? $repDisplayName : 'ClassBookHub');
$verifiedStudentIndex = '';
$verifiedStudentName = '';
$skipPersonalStep = false;
$serverFilteredOwnedBookIds = [];

if ($rep_id > 0) {
    $requestedStudentIndex = trim((string) ($_GET['student_index'] ?? ''));
    if ($requestedStudentIndex !== '') {
        $normalized_index_sql = function_exists('book_system_normalized_index_sql')
            ? book_system_normalized_index_sql('index_number')
            : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";
        $normalized_requested_student_index = function_exists('book_system_normalize_index_number')
            ? book_system_normalize_index_number($requestedStudentIndex)
            : strtoupper(trim($requestedStudentIndex));
        $studentStmt = $conn->prepare("SELECT index_number, student_name
            FROM class_students
            WHERE admin_id = ?
              AND semester_id = ?
              AND $normalized_index_sql = ?
            LIMIT 1");
        if ($studentStmt) {
            $studentStmt->bind_param('iis', $rep_id, $semester_id, $normalized_requested_student_index);
            $studentStmt->execute();
            $studentResult = $studentStmt->get_result();
            if ($studentResult && $studentResult->num_rows > 0) {
                $studentRow = $studentResult->fetch_assoc();
                $verifiedStudentIndex = trim((string) ($studentRow['index_number'] ?? ''));
                $verifiedStudentName = trim((string) ($studentRow['student_name'] ?? ''));
                $skipPersonalStep = ($verifiedStudentIndex !== '' && $verifiedStudentName !== '');
            }
            $studentStmt->close();
        }
    }
}

if ($skipPersonalStep && $rep_id > 0 && $semester_id > 0 && !empty($books_array)) {
    $normalized_student_index = function_exists('book_system_normalize_index_number')
        ? book_system_normalize_index_number($verifiedStudentIndex)
        : strtoupper(preg_replace('/[^A-Z0-9]+/', '', $verifiedStudentIndex));

    if (preg_match('/^[A-Z0-9]{10}$/', $normalized_student_index)) {
        $student_index_expr = function_exists('book_system_normalized_index_sql')
            ? book_system_normalized_index_sql('s.index_number')
            : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(s.index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";

        $owned_stmt = $conn->prepare("
            SELECT DISTINCT ri.book_id
            FROM request_items ri
            JOIN requests r ON ri.request_id = r.request_id
            JOIN students s ON r.student_id = s.student_id
            WHERE $student_index_expr = ?
              AND r.semester_id = ?
              AND r.admin_id = ?
              AND COALESCE(ri.is_cancelled, 0) = 0
        ");

        if ($owned_stmt) {
            $owned_stmt->bind_param('sii', $normalized_student_index, $semester_id, $rep_id);
            $owned_stmt->execute();
            $owned_result = $owned_stmt->get_result();
            if ($owned_result) {
                while ($owned_row = $owned_result->fetch_assoc()) {
                    $owned_book_id = intval($owned_row['book_id'] ?? 0);
                    if ($owned_book_id > 0) {
                        $serverFilteredOwnedBookIds[$owned_book_id] = true;
                    }
                }
            }
            $owned_stmt->close();
        }

        if (!empty($serverFilteredOwnedBookIds)) {
            $books_array = array_values(array_filter($books_array, static function ($row) use ($serverFilteredOwnedBookIds) {
                $book_id = intval($row['book_id'] ?? 0);
                return $book_id > 0 && !isset($serverFilteredOwnedBookIds[$book_id]);
            }));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Course Material Request</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ClassBookHub">
    <link rel="icon" type="image/png" sizes="1254x1254" href="assets/images/logo/classbookhub-icon.png">
    <link rel="apple-touch-icon" href="assets/images/logo/classbookhub-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="style.css">
    <style>
        .verified-student-card {
            display: grid;
            gap: 14px;
            margin: 0 0 18px;
            padding: 18px 20px;
            border-radius: 18px;
            background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%);
            border: 1px solid rgba(78, 116, 255, 0.16);
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        .verified-student-label {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.12);
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        .verified-student-grid {
            display: grid;
            gap: 12px;
        }

        .verified-student-item {
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid rgba(148, 163, 184, 0.22);
        }

        .verified-student-item span {
            display: block;
            margin-bottom: 6px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .verified-student-item strong {
            color: #0f172a;
            font-size: 16px;
            line-height: 1.35;
        }

        .verified-student-note {
            margin: 0;
            color: #475569;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (min-width: 640px) {
            .verified-student-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .portal-info-box {
            margin: 0 0 18px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            color: #1d4ed8;
            font-size: 14px;
            line-height: 1.5;
        }
    </style>
</head>
<body>

<div class="container">

    <h2>Course Material Request</h2>

    <?php if ($portal_flash_notice && !empty($portal_flash_notice['message']) && strval($portal_flash_notice['type'] ?? '') === 'info'): ?>
        <div class="portal-info-box"><?php echo htmlspecialchars(strval($portal_flash_notice['message'] ?? '')); ?></div>
    <?php endif; ?>

    <?php if ($invalid_rep_link): ?>
        <div style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:14px 16px; border-radius:12px; margin-bottom:18px;">
            This rep link is invalid or no longer active. Please contact your class rep for the correct order link.
        </div>
    <?php elseif ($rep_info): ?>
        <section class="rep-assignment-card">
            <div class="rep-avatar-shell">
                <?php if (($repAvatar['src'] ?? '') !== ''): ?>
                    <img
                        src="<?php echo htmlspecialchars($repAvatar['src']); ?>"
                        alt="<?php echo htmlspecialchars($repDisplayName !== '' ? $repDisplayName : 'Representative'); ?>"
                        class="rep-avatar-image"
                        width="72"
                        height="72"
                        loading="eager"
                        decoding="async"
                        fetchpriority="high"
                    >
                <?php else: ?>
                    <div class="rep-avatar-fallback"><?php echo htmlspecialchars($repAvatarInitials); ?></div>
                <?php endif; ?>
            </div>
            <div class="rep-assignment-copy">
                <span class="rep-assignment-label">Representative Ready</span>
                <strong><?php echo htmlspecialchars($repDisplayName !== '' ? $repDisplayName : 'the selected rep'); ?></strong>
                <p>Orders from this page will be assigned to this representative<?php echo !empty($rep_info['class_name']) ? ' for ' . htmlspecialchars($rep_info['class_name']) : ''; ?>.</p>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$invalid_rep_link): ?>
    <form method="post" action="submit_request.php" id="requestForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="submission_token" value="<?php echo htmlspecialchars($submission_token); ?>">
        <input type="hidden" name="rep_id" value="<?php echo $rep_id; ?>">

        <?php if ($skipPersonalStep): ?>
            <input type="hidden" id="index_number" name="index_number" value="<?php echo htmlspecialchars($verifiedStudentIndex); ?>">
            <input type="hidden" id="full_name" name="full_name" value="<?php echo htmlspecialchars($verifiedStudentName); ?>">
            <input type="hidden" id="phone" name="phone" value="">

            <section class="verified-student-card">
                <span class="verified-student-label">Student Confirmed</span>
                <div class="verified-student-grid">
                    <div class="verified-student-item">
                        <span>Student Name</span>
                        <strong><?php echo htmlspecialchars($verifiedStudentName); ?></strong>
                    </div>
                    <div class="verified-student-item">
                        <span>Index Number</span>
                        <strong><?php echo htmlspecialchars($verifiedStudentIndex); ?></strong>
                    </div>
                </div>
                <p class="verified-student-note">Your details have already been confirmed. You can go straight to selecting your books below.</p>
            </section>
        <?php endif; ?>

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

        <!-- â”€â”€ STEP 1 â”€â”€ Personal Information â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <?php if (!$skipPersonalStep): ?>
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

            <button type="button" id="btnToStep2" class="primary-btn">
                <span class="button-spinner" aria-hidden="true"></span>
                <span class="button-label">Proceed</span>
            </button>

            <p class="step-info">Next: Select course materials</p>
        </div>
        <?php endif; ?>

        <!-- â”€â”€ STEP 2 â”€â”€ Book Selection & Payment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
        <div id="step2" class="form-step<?php echo $skipPersonalStep ? ' active' : ''; ?>">

            <div class="step-header">
                <span class="step-number"><?php echo $skipPersonalStep ? '1' : '2'; ?></span>
                <h3>Select Course Materials</h3>
            </div>

            <?php if (!$skipPersonalStep): ?>
                <button type="button" id="btnBack" class="secondary-btn">
                    &larr; Back
                </button>
            <?php endif; ?>

            <?php if (empty($books_array)) { ?>
                <div class="empty-state">
                    <?php echo ($skipPersonalStep && !empty($serverFilteredOwnedBookIds))
                        ? 'You have already requested all currently available books.'
                        : 'No books are currently available.'; ?>
                </div>
            <?php } else { ?>
                <?php if ($skipPersonalStep && !empty($serverFilteredOwnedBookIds)) { ?>
                    <div style="margin: 0 0 14px; padding: 10px 14px; border-radius: 12px; background: #eef6ff; border: 1px solid #cfe2ff; color: #1e3a8a; font-size: 13px; line-height: 1.5;">
                        <strong>Books you already requested are hidden.</strong>
                    </div>
                <?php } ?>
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
                <span>Books Selected:</span>
                <strong><span id="selected_count">0</span></strong>
            </div>

            <div class="final-total">
                Total Amount to Pay: GH&#8373; <span id="final_total">0.00</span>
            </div>
            <p class="step-info" style="margin-top:10px;">This amount includes payment processing charges.</p>

            <!-- Hidden fields -->
            <input type="hidden" name="total_amount" id="total_amount" value="0.00">
            <input type="hidden" name="payable_amount" id="payable_amount" value="0.00">

            <button type="submit" class="primary-btn">
                Proceed to Payment
            </button>

            <p class="step-info">Make sure at least one item is selected</p>
        </div>

    </form>

    <div class="request-nav-links">
        <a href="common_request_portal.php" class="portal-nav-button secondary-nav-button">Back to Home</a>
        <a href="common_request_portal.php#offersPanel" class="portal-nav-button offers-nav-button">View Offers</a>
    </div>
    <?php endif; ?>

</div>

<script>
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    const paystackCheckoutEnabled = <?php echo $studentFacingPaystackEnabled ? 'true' : 'false'; ?>;
    const paystackFeeRate = <?php echo json_encode($studentFacingPaystackFeeRate); ?>;
    let studentCreditBalance = 0;

    function roundMoney(value) {
        return Math.round(Math.max(0, Number(value) || 0) * 100) / 100;
    }

    function roundMoneyUp(value) {
        return Math.ceil(Math.max(0, Number(value) || 0) * 100) / 100;
    }

    function resolveFinalPayable(subtotal) {
        const safeSubtotal = roundMoney(subtotal);
        const creditUsed = roundMoney(Math.min(studentCreditBalance, safeSubtotal));
        const balanceDue = roundMoney(Math.max(0, safeSubtotal - creditUsed));

        if (balanceDue <= 0) {
            return { finalAmount: 0, balanceDue: balanceDue };
        }

        const handlingCharge = roundMoney(balanceDue * 0.01);
        const intendedAmount = roundMoney(balanceDue + handlingCharge);

        if (!paystackCheckoutEnabled) {
            return { finalAmount: intendedAmount, balanceDue: balanceDue };
        }

        let chargeAmount = roundMoneyUp(intendedAmount / (1 - paystackFeeRate));
        let gatewayFee = roundMoney(chargeAmount * paystackFeeRate);
        let settlement = roundMoney(Math.max(0, chargeAmount - gatewayFee));

        while (settlement + 0.0001 < intendedAmount) {
            chargeAmount = roundMoneyUp(chargeAmount + 0.01);
            gatewayFee = roundMoney(chargeAmount * paystackFeeRate);
            settlement = roundMoney(Math.max(0, chargeAmount - gatewayFee));
        }

        return { finalAmount: chargeAmount, balanceDue: balanceDue };
    }

    // â”€â”€ CALCULATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    function calculateTotal() {
        let subtotal = 0;
        let selectedCount = 0;
        document.querySelectorAll(".book-check").forEach(book => {
            if (book.checked) {
                subtotal += parseFloat(book.dataset.price || 0);
                selectedCount += 1;
            }
        });

        const paymentPreview = resolveFinalPayable(subtotal);
        const finalAmount = paymentPreview.finalAmount;

        document.getElementById("selected_count").textContent = String(selectedCount);
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
    const indexField = document.getElementById("index_number");
    const skipPersonalStep = <?php echo $skipPersonalStep ? 'true' : 'false'; ?>;
    const proceedButtonLabel = btnToStep2 ? (btnToStep2.querySelector('.button-label')?.textContent || 'Proceed') : 'Proceed';

    function focusIndexField() {
        if (!indexField) {
            return;
        }
        window.requestAnimationFrame(() => {
            indexField.focus({ preventScroll: true });
        });
    }

    function setProceedLoading(isLoading) {
        if (!btnToStep2) {
            return;
        }
        const label = btnToStep2.querySelector('.button-label');
        btnToStep2.disabled = isLoading;
        btnToStep2.classList.toggle('is-loading', isLoading);
        btnToStep2.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        if (label) {
            label.textContent = isLoading ? 'Loading next step...' : proceedButtonLabel;
        }
    }

    function normalizeIndexValue(value) {
        return String(value || '').toUpperCase().replace(/[^A-Z0-9]+/g, '');
    }

    if (!skipPersonalStep) {
        focusIndexField();
    } else {
        calculateTotal();
    }

    if (btnToStep2 && step1 && step2) {
        btnToStep2.addEventListener("click", () => {
            const indexEl = document.getElementById("index_number");
            const nameEl = document.getElementById("full_name");
            const phoneEl = document.getElementById("phone");

            if (!indexEl || !nameEl || !phoneEl) {
                return;
            }

            if (!indexEl.checkValidity() || !nameEl.checkValidity() || !phoneEl.checkValidity()) {
                form.reportValidity();
                return;
            }

            const normalizedIndex = normalizeIndexValue(indexEl.value);
            if (normalizedIndex.length !== 10) {
                alert("Index number must resolve to exactly 10 characters.");
                return;
            }

            setProceedLoading(true);
            step1.classList.add("is-transitioning");

            window.setTimeout(() => {
                step1.classList.remove("active", "is-transitioning");
                step2.classList.add("active");
                calculateTotal();
                setProceedLoading(false);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }, 380);
        });
    }

    if (btnBack && step1 && step2) {
        btnBack.addEventListener("click", () => {
            step2.classList.remove("active");
            step1.classList.add("active");
            focusIndexField();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // Prevent submit if no books selected
    form.addEventListener("submit", (e) => {
        const anyChecked = document.querySelector(".book-check:checked");
        if (!anyChecked) {
            e.preventDefault();
            alert("Please select at least one course material.");
            return;
        }

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            if (submitButton.disabled) {
                e.preventDefault();
                return;
            }
            submitButton.disabled = true;
            submitButton.textContent = 'Processing...';
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
    var autoVerifiedStudent = <?php echo $skipPersonalStep ? 'true' : 'false'; ?>;

    if (!indexInput || !nameInput || !phoneInput) {
        return;
    }

    if (autoVerifiedStudent) {
        var verifiedIndex = indexInput.value.trim();
        if (verifiedIndex !== '') {
            checkOwnedBooks(verifiedIndex);
            loadStudentRequestHistory(verifiedIndex);
            var repId = <?php echo $rep_id; ?>;
            fetch('get_student_credit.php?index=' + encodeURIComponent(verifiedIndex) + '&rep_id=' + repId)
                .then(response => response.json())
                .then(data => {
                    if (data.found && data.credit_balance > 0) {
                        studentCreditBalance = parseFloat(data.credit_balance || 0);
                        creditAmount.textContent = data.credit_balance.toFixed(2);
                        creditInfo.style.display = 'block';
                    } else {
                        studentCreditBalance = 0;
                        creditInfo.style.display = 'none';
                    }
                    calculateTotal();
                })
                .catch(() => {
                    studentCreditBalance = 0;
                    creditInfo.style.display = 'none';
                    calculateTotal();
                });
        }
        return;
    }

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
                        studentCreditBalance = parseFloat(data.credit_balance || 0);
                        creditAmount.textContent = data.credit_balance.toFixed(2);
                        creditInfo.style.display = 'block';
                    } else {
                        studentCreditBalance = 0;
                        creditInfo.style.display = 'none';
                    }
                    calculateTotal();
                } else {
                    nameInput.value = '';
                    nameInput.style.color = "#2d3436";
                    studentCreditBalance = 0;
                    creditInfo.style.display = 'none';
                    historyPanel.style.display = 'none';
                    historyList.innerHTML = '';
                    calculateTotal();
                }
            })
            .catch(error => {
                studentCreditBalance = 0;
                calculateTotal();
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
    var normalizedIndex = normalizeIndexValue(indexNumber);
    if (normalizedIndex.length !== 10) {
        renderStudentRequestHistory([]);
        return;
    }

    fetch('get_student_request_history.php?index=' + encodeURIComponent(normalizedIndex) + '&rep_id=' + <?php echo intval($rep_id); ?>)
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
    var normalizedIndex = normalizeIndexValue(indexNumber);
    if (normalizedIndex.length < 3) return;
     
    fetch('check_student_books.php?index=' + encodeURIComponent(normalizedIndex) + '&rep_id=' + <?php echo intval($rep_id); ?>)
        .then(response => response.json())
        .then(ownedBooks => {
            // Re-enable all first to reset the form
            document.querySelectorAll('.book-check').forEach(cb => {
                cb.disabled = false;
                cb.checked = false;
                cb.parentElement.classList.remove('book-owned');
                cb.parentElement.style.opacity = "1";
                cb.parentElement.style.textDecoration = "none";
                cb.parentElement.title = "";
                cb.parentElement.style.display = "";
            });
            
            // Hide books the student already owns from the public request flow.
            ownedBooks.forEach(bookId => {
                const checkbox = document.querySelector(`input[name="books[]"][value="${bookId}"]`);
                if (checkbox) {
                    checkbox.disabled = true;
                    checkbox.parentElement.style.display = "none";
                    checkbox.parentElement.title = "You have already requested this book.";
                }
            });

            calculateTotal();
        });
}

// Also check on blur in case user typed full index directly
var requestIndexInput = document.querySelector('input[name="index_number"]');
if (requestIndexInput && requestIndexInput.type !== 'hidden') {
    requestIndexInput.addEventListener('blur', function() {
        checkOwnedBooks(this.value);
        loadStudentRequestHistory(this.value);
    });
}
</script>
    
<?php include 'footer.php'; ?>
    
</body>
</html>


