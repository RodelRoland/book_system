<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (function_exists('book_system_sync_connection_timezone')) {
    book_system_sync_connection_timezone($conn);
}

function portal_request_set_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION['portal_request_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function portal_request_log_stage(string $stage, array $context = []): void
{
    $payload = ['stage' => $stage];
    foreach ($context as $key => $value) {
        if (is_array($value)) {
            $payload[$key] = $value;
        } elseif (is_bool($value)) {
            $payload[$key] = $value;
        } elseif ($value === null) {
            $payload[$key] = null;
        } else {
            $payload[$key] = strval($value);
        }
    }

    error_log('[ClassBookHub][submit_request] ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

portal_request_log_stage('request_received', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    portal_request_log_stage('request_method_rejected');
    header('Location: index.php');
    exit;
}

if (!csrf_validate($_POST['csrf_token'] ?? null)) {
    portal_request_log_stage('csrf_failed');
    die('Invalid request. Please refresh and try again.');
}

$submission_scope = 'portal_request';
$submission_token = trim(strval($_POST['submission_token'] ?? ''));
$submission_token_present = ($submission_token !== '');
$submission_token_valid = function_exists('book_system_submission_token_exists')
    ? book_system_submission_token_exists($submission_scope, $submission_token)
    : true;
$transaction_started = false;
$submission_consumed = false;
$shared_submission_lock_acquired = false;

$index_number = trim(strval($_POST['index_number'] ?? ''));
$full_name = trim(strval($_POST['full_name'] ?? ''));
$phone = trim(strval($_POST['phone'] ?? ''));
$rep_id = intval($_POST['rep_id'] ?? 0);
$selected_book_ids = array_values(array_unique(array_filter(array_map('intval', is_array($_POST['books'] ?? null) ? $_POST['books'] : []), static function ($book_id) {
    return $book_id > 0;
})));

portal_request_log_stage('tokens_checked', [
    'submission_token_present' => $submission_token_present,
    'submission_token_valid' => $submission_token_valid,
]);
portal_request_log_stage('request_payload_received', [
    'rep_id' => $rep_id,
    'index_number' => $index_number,
    'selected_book_ids' => $selected_book_ids,
]);

if (empty($selected_book_ids)) {
    portal_request_log_stage('selected_books_missing');
    die('Please select at least one valid course material.');
}

$semester_id = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semester_id <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semester_id = book_system_get_active_semester_id($conn);
}
$credit_balance = 0.0;
$credit_used = 0.0;
$request_id = 0;

$normalized_index_input = function_exists('book_system_normalize_index_number')
    ? book_system_normalize_index_number($index_number)
    : strtoupper(trim($index_number));
$normalized_index_sql = function_exists('book_system_normalized_index_sql')
    ? book_system_normalized_index_sql('index_number')
    : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";

portal_request_log_stage('index_normalized', [
    'normalized_index_input' => $normalized_index_input,
    'semester_id' => $semester_id,
]);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    if ($rep_id <= 0) {
        $default_rep = $conn->query("SELECT admin_id FROM admins WHERE role = 'super_admin' AND is_active = 1 LIMIT 1");
        if ($default_rep && $default_rep->num_rows > 0) {
            $rep_id = intval($default_rep->fetch_assoc()['admin_id'] ?? 0);
        }
    }

    if ($rep_id <= 0) {
        throw new RuntimeException('No active rep could be assigned to this request.');
    }

    $request_fingerprint = hash('sha256', implode('|', [
        'portal_request',
        strval($rep_id),
        strval($semester_id),
        $normalized_index_input,
        implode(',', $selected_book_ids),
    ]));

    $shared_recent_result = function_exists('book_system_get_shared_submission_result')
        ? book_system_get_shared_submission_result($submission_scope, $request_fingerprint)
        : null;
    if (is_array($shared_recent_result) && !empty($shared_recent_result['redirect'])) {
        portal_request_log_stage('shared_submission_replay_redirect', [
            'redirect' => $shared_recent_result['redirect'] ?? '',
            'request_id' => $shared_recent_result['request_id'] ?? 0,
        ]);
        header('Location: ' . strval($shared_recent_result['redirect']), true, 303);
        exit;
    }

    if (function_exists('book_system_acquire_submission_processing_lock')) {
        $shared_submission_lock_acquired = book_system_acquire_submission_processing_lock($submission_scope, $request_fingerprint);
        if (!$shared_submission_lock_acquired) {
            $shared_pending_result = function_exists('book_system_get_shared_submission_result')
                ? book_system_get_shared_submission_result($submission_scope, $request_fingerprint)
                : null;
            if (is_array($shared_pending_result) && !empty($shared_pending_result['redirect'])) {
                header('Location: ' . strval($shared_pending_result['redirect']), true, 303);
                exit;
            }
            throw new RuntimeException('This request is already being processed. Please wait a moment.');
        }
    }

    if (!$submission_token_valid) {
        portal_request_log_stage('submission_token_invalid', [
            'rep_id' => $rep_id,
            'request_fingerprint' => $request_fingerprint,
        ]);
        $recent_result = function_exists('book_system_get_recent_submission_result')
            ? book_system_get_recent_submission_result($submission_scope, $request_fingerprint)
            : null;
        if (is_array($recent_result) && !empty($recent_result['redirect'])) {
            portal_request_log_stage('submission_replay_redirect', [
                'request_id' => $recent_result['request_id'] ?? 0,
                'redirect' => $recent_result['redirect'] ?? '',
            ]);
            header('Location: ' . strval($recent_result['redirect']));
            exit;
        }
        if ($submission_token === '') {
            throw new RuntimeException('Your request form expired. Please go back and try again.');
        }
        throw new RuntimeException('This request was already submitted. Please wait for the payment page to load or refresh the form before trying again.');
    }

    if ($semester_id <= 0) {
        throw new RuntimeException('There is no active semester for requests right now.');
    }

    $paymentSettings = function_exists('book_system_get_admin_payment_settings')
        ? book_system_get_admin_payment_settings($conn, $rep_id)
        : ['effective_method' => 'manual_momo'];
    $effectivePaymentMethod = strval($paymentSettings['effective_method'] ?? 'manual_momo');

    portal_request_log_stage('payment_method_resolved', [
        'rep_id' => $rep_id,
        'effective_method' => $effectivePaymentMethod,
    ]);

    portal_request_log_stage('student_lookup_start', [
        'rep_id' => $rep_id,
        'normalized_index_input' => $normalized_index_input,
    ]);

    $roster_name = '';
    $roster_index = '';
    $roster_stmt = $conn->prepare("SELECT student_name, index_number
        FROM class_students
        WHERE admin_id = ?
          AND semester_id = ?
          AND $normalized_index_sql = ?
        LIMIT 1");
    if ($roster_stmt) {
        $roster_stmt->bind_param('iis', $rep_id, $semester_id, $normalized_index_input);
        $roster_stmt->execute();
        $roster_result = $roster_stmt->get_result();
        if ($roster_result && $roster_result->num_rows > 0) {
            $roster_row = $roster_result->fetch_assoc();
            $roster_name = trim(strval($roster_row['student_name'] ?? ''));
            $roster_index = trim(strval($roster_row['index_number'] ?? ''));
        }
        $roster_stmt->close();
    }

    portal_request_log_stage('student_lookup_result', [
        'roster_found' => ($roster_name !== '' && $roster_index !== ''),
        'roster_index' => $roster_index,
    ]);

    if ($roster_name === '' || $roster_index === '') {
        throw new RuntimeException("We couldn't verify your class for this representative. Please use the correct request link or contact your class rep.");
    }

    $index_number = $roster_index;
    if ($full_name === '') {
        $full_name = $roster_name;
    }

    $conn->begin_transaction();
    $transaction_started = true;
    portal_request_log_stage('transaction_started', [
        'rep_id' => $rep_id,
        'student_index' => $index_number,
    ]);

    $student_stmt = $conn->prepare("SELECT student_id, credit_balance, admin_id FROM students WHERE index_number = ? LIMIT 1 FOR UPDATE");
    $student_stmt->bind_param('s', $index_number);
    $student_stmt->execute();
    $student_result = $student_stmt->get_result();

    if ($student_result && $student_result->num_rows > 0) {
        $student = $student_result->fetch_assoc();
        $student_id = intval($student['student_id'] ?? 0);
        $credit_balance = floatval($student['credit_balance'] ?? 0);
        $student_admin_id = intval($student['admin_id'] ?? 0);
        $resolved_name = $roster_name !== '' ? $roster_name : $full_name;

        if ($student_admin_id <= 0) {
            $claim = $conn->prepare("UPDATE students SET admin_id = ? WHERE student_id = ? AND (admin_id IS NULL OR admin_id = 0)");
            $claim->bind_param('ii', $rep_id, $student_id);
            $claim->execute();
            $claim->close();
        }

        $student_update = $conn->prepare("UPDATE students SET full_name = ?, phone = ? WHERE student_id = ?");
        if ($student_update) {
            $student_update->bind_param('ssi', $resolved_name, $phone, $student_id);
            $student_update->execute();
            $student_update->close();
        }
    } else {
        $resolved_name = $roster_name !== '' ? $roster_name : $full_name;
        $insert_student_stmt = $conn->prepare("INSERT INTO students (full_name, index_number, phone, credit_balance, admin_id) VALUES (?, ?, ?, 0, ?)");
        $insert_student_stmt->bind_param('sssi', $resolved_name, $index_number, $phone, $rep_id);
        $insert_student_stmt->execute();
        $student_id = intval($conn->insert_id);
        $insert_student_stmt->close();
    }
    $student_stmt->close();

    $book_placeholders = implode(',', array_fill(0, count($selected_book_ids), '?'));

    $duplicate_sql = "SELECT DISTINCT
            r.request_id,
            r.payment_status,
            COALESCE(r.payment_gateway, '') AS payment_gateway,
            ri.book_id,
            b.book_title
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.request_id
        JOIN books b ON ri.book_id = b.book_id
        WHERE r.student_id = ?
          AND r.semester_id = ?
          AND r.admin_id = ?
          AND COALESCE(ri.is_cancelled, 0) = 0
          AND ri.book_id IN ($book_placeholders)";
    $duplicate_stmt = $conn->prepare($duplicate_sql);
    $duplicate_types = 'iii' . str_repeat('i', count($selected_book_ids));
    $duplicate_params = array_merge([$student_id, $semester_id, $rep_id], $selected_book_ids);
    $duplicate_stmt->bind_param($duplicate_types, ...$duplicate_params);
    $duplicate_stmt->execute();
    $duplicate_result = $duplicate_stmt->get_result();
    $duplicate_titles = [];
    $duplicate_request_books = [];
    $duplicate_request_meta = [];
    if ($duplicate_result) {
        while ($duplicate_row = $duplicate_result->fetch_assoc()) {
            $duplicate_titles[] = strval($duplicate_row['book_title'] ?? '');
            $duplicate_request_id = intval($duplicate_row['request_id'] ?? 0);
            $duplicate_book_id = intval($duplicate_row['book_id'] ?? 0);
            if ($duplicate_request_id > 0 && $duplicate_book_id > 0) {
                if (!isset($duplicate_request_books[$duplicate_request_id])) {
                    $duplicate_request_books[$duplicate_request_id] = [];
                }
                $duplicate_request_books[$duplicate_request_id][] = $duplicate_book_id;
                if (!isset($duplicate_request_meta[$duplicate_request_id])) {
                    $duplicate_request_meta[$duplicate_request_id] = [
                        'payment_status' => strtolower(trim(strval($duplicate_row['payment_status'] ?? 'unpaid'))),
                        'payment_gateway' => strtolower(trim(strval($duplicate_row['payment_gateway'] ?? ''))),
                    ];
                }
            }
        }
    }
    $duplicate_stmt->close();

    if (!empty($duplicate_titles)) {
        $resume_request_id = 0;

        if ($effectivePaymentMethod === 'paystack' && !empty($duplicate_request_books)) {
            $candidate_request_ids = array_values(array_filter(array_map('intval', array_keys($duplicate_request_books))));
            $selected_book_ids_sorted = $selected_book_ids;
            sort($selected_book_ids_sorted);

            if (!empty($candidate_request_ids)) {
                $candidate_placeholders = implode(',', array_fill(0, count($candidate_request_ids), '?'));
                $candidate_count_sql = "SELECT request_id, COUNT(*) AS active_item_count
                    FROM request_items
                    WHERE request_id IN ($candidate_placeholders)
                      AND COALESCE(is_cancelled, 0) = 0
                    GROUP BY request_id";
                $candidate_count_stmt = $conn->prepare($candidate_count_sql);
                $candidate_counts = [];
                if ($candidate_count_stmt) {
                    $candidate_count_types = str_repeat('i', count($candidate_request_ids));
                    $candidate_count_stmt->bind_param($candidate_count_types, ...$candidate_request_ids);
                    $candidate_count_stmt->execute();
                    $candidate_count_result = $candidate_count_stmt->get_result();
                    if ($candidate_count_result) {
                        while ($candidate_count_row = $candidate_count_result->fetch_assoc()) {
                            $candidate_counts[intval($candidate_count_row['request_id'] ?? 0)] = intval($candidate_count_row['active_item_count'] ?? 0);
                        }
                    }
                    $candidate_count_stmt->close();
                }

                foreach ($candidate_request_ids as $candidate_request_id) {
                    $candidate_status = strval($duplicate_request_meta[$candidate_request_id]['payment_status'] ?? 'unpaid');
                    if ($candidate_status === 'paid') {
                        continue;
                    }

                    $candidate_books = array_values(array_unique(array_map('intval', $duplicate_request_books[$candidate_request_id] ?? [])));
                    sort($candidate_books);
                    $candidate_count = intval($candidate_counts[$candidate_request_id] ?? 0);

                    if ($candidate_count === count($selected_book_ids_sorted) && $candidate_books === $selected_book_ids_sorted) {
                        $resume_request_id = $candidate_request_id;
                        break;
                    }
                }
            }
        }

        if ($resume_request_id > 0) {
            $resume_redirect_url = "payment_instructions.php?request_id=$resume_request_id&resume=1";

            portal_request_log_stage('existing_paystack_request_resumed', [
                'request_id' => $resume_request_id,
                'rep_id' => $rep_id,
                'student_id' => $student_id,
                'selected_book_ids' => $selected_book_ids,
                'redirect' => $resume_redirect_url,
            ]);

            if (function_exists('book_system_remember_submission_result')) {
                book_system_remember_submission_result($submission_scope, $request_fingerprint, [
                    'request_id' => $resume_request_id,
                    'redirect' => $resume_redirect_url,
                ]);
            }
            if (function_exists('book_system_remember_shared_submission_result')) {
                book_system_remember_shared_submission_result($submission_scope, $request_fingerprint, [
                    'request_id' => $resume_request_id,
                    'redirect' => $resume_redirect_url,
                ]);
            }

            if (function_exists('book_system_consume_submission_token')) {
                $submission_consumed = book_system_consume_submission_token($submission_scope, $submission_token);
                portal_request_log_stage('submission_token_consumed_for_resume', [
                    'submission_consumed' => $submission_consumed,
                    'request_id' => $resume_request_id,
                ]);
            }

            $conn->rollback();
            $transaction_started = false;
            if ($shared_submission_lock_acquired && function_exists('book_system_release_submission_processing_lock')) {
                book_system_release_submission_processing_lock($submission_scope, $request_fingerprint);
                $shared_submission_lock_acquired = false;
            }
            header('Location: ' . $resume_redirect_url, true, 303);
            exit;
        }

        portal_request_log_stage('duplicate_books_detected', [
            'duplicate_titles' => $duplicate_titles,
        ]);
        throw new RuntimeException("Duplicate Request: You have already requested: " . htmlspecialchars(implode(', ', $duplicate_titles)));
    }

    portal_request_log_stage('selected_books_validated', [
        'book_count' => count($selected_book_ids),
        'student_id' => $student_id,
    ]);

    $price_date = date('Y-m-d');
    $books_sql = "SELECT
            b.book_id,
            COALESCE(
                (
                    SELECT bph.new_price
                    FROM book_price_history bph
                    WHERE bph.book_id = b.book_id
                      AND bph.effective_date IS NOT NULL
                      AND bph.effective_date <= ?
                    ORDER BY bph.effective_date DESC, bph.history_id DESC
                    LIMIT 1
                ),
                (
                    SELECT bph.old_price
                    FROM book_price_history bph
                    WHERE bph.book_id = b.book_id
                      AND bph.effective_date IS NOT NULL
                      AND bph.effective_date > ?
                    ORDER BY bph.effective_date ASC, bph.history_id ASC
                    LIMIT 1
                ),
                b.price
            ) AS unit_price
        FROM books b
        WHERE b.book_id IN ($book_placeholders)
          AND b.admin_id = ?
          AND b.semester_id = ?";
    $books_stmt = $conn->prepare($books_sql);
    $books_types = 'ss' . str_repeat('i', count($selected_book_ids)) . 'ii';
    $books_params = array_merge([$price_date, $price_date], $selected_book_ids, [$rep_id, $semester_id]);
    $books_stmt->bind_param($books_types, ...$books_params);
    $books_stmt->execute();
    $books_result = $books_stmt->get_result();

    $book_prices = [];
    $total_amount = 0.0;
    if ($books_result) {
        while ($book_row = $books_result->fetch_assoc()) {
            $book_id = intval($book_row['book_id'] ?? 0);
            $unit_price = round(floatval($book_row['unit_price'] ?? 0), 2);
            if ($book_id > 0) {
                $book_prices[$book_id] = $unit_price;
                $total_amount += $unit_price;
            }
        }
    }
    $books_stmt->close();

    portal_request_log_stage('book_prices_resolved', [
        'resolved_book_count' => count($book_prices),
        'total_amount' => number_format($total_amount, 2, '.', ''),
    ]);

    if (empty($book_prices)) {
        throw new RuntimeException('No valid books were selected for this rep.');
    }

    if ($total_amount <= 0) {
        throw new RuntimeException('The selected books did not produce a valid total amount.');
    }

    if ($effectivePaymentMethod === 'paystack') {
        $plannedCreditUsed = round(min(max($credit_balance, 0), $total_amount), 2);
        $checkoutPreview = [
            'total_amount' => $total_amount,
            'credit_used' => $plannedCreditUsed,
            'amount_paid' => 0,
            'payment_status' => 'unpaid',
        ];
        $checkoutBreakdown = function_exists('book_system_get_request_payment_breakdown')
            ? book_system_get_request_payment_breakdown($checkoutPreview, $paymentSettings, $conn)
            : [
                'total_payable' => $total_amount,
                'balance_due' => $total_amount - $plannedCreditUsed,
                'handling_charge' => 0,
                'processing_fee' => 0,
            ];

        portal_request_log_stage('paystack_checkout_preview', [
            'credit_used' => number_format($plannedCreditUsed, 2, '.', ''),
            'total_payable' => number_format(floatval($checkoutBreakdown['total_payable'] ?? 0), 2, '.', ''),
            'expected_settlement' => number_format(floatval($checkoutBreakdown['expected_settlement'] ?? 0), 2, '.', ''),
        ]);

        if (floatval($checkoutBreakdown['total_payable'] ?? 0) > 0) {
            $checkoutRedirectUrl = '';
            $existingCheckout = function_exists('book_system_find_paystack_checkout_by_fingerprint')
                ? book_system_find_paystack_checkout_by_fingerprint($request_fingerprint, function_exists('book_system_paystack_checkout_ttl') ? book_system_paystack_checkout_ttl() : 1800)
                : null;

            if (is_array($existingCheckout)) {
                $existingCheckoutRepId = intval($existingCheckout['rep_id'] ?? 0);
                $existingCheckoutToken = trim(strval($existingCheckout['checkout_token'] ?? ''));
                $existingCheckoutSettings = ($existingCheckoutRepId > 0 && function_exists('book_system_get_admin_payment_settings'))
                    ? book_system_get_admin_payment_settings($conn, $existingCheckoutRepId)
                    : ['effective_method' => 'manual_momo'];
                $existingCheckoutMethod = strval($existingCheckoutSettings['effective_method'] ?? 'manual_momo');

                if ($existingCheckoutMethod !== 'paystack' && $existingCheckoutToken !== '') {
                    if (function_exists('book_system_remove_paystack_checkout')) {
                        book_system_remove_paystack_checkout($existingCheckoutToken);
                    }
                    $existingCheckout = null;
                }
            }

            if (is_array($existingCheckout)) {
                $existingRequestId = intval($existingCheckout['request_id'] ?? 0);
                if ($existingRequestId > 0) {
                    $checkoutRedirectUrl = 'payment_instructions.php?request_id=' . $existingRequestId;
                } else {
                    $existingCheckoutToken = trim(strval($existingCheckout['checkout_token'] ?? ''));
                    if ($existingCheckoutToken !== '') {
                        $checkoutRedirectUrl = 'payment_instructions.php?checkout_token=' . urlencode($existingCheckoutToken) . '&resume_checkout=1';
                    }
                }
            }

            if ($checkoutRedirectUrl === '') {
                $checkoutPayload = [
                    'fingerprint' => $request_fingerprint,
                    'rep_id' => $rep_id,
                    'semester_id' => $semester_id,
                    'index_number' => $index_number,
                    'full_name' => $full_name,
                    'phone' => $phone,
                    'selected_book_ids' => $selected_book_ids,
                    'book_prices' => $book_prices,
                    'total_amount' => round($total_amount, 2),
                    'credit_used' => $plannedCreditUsed,
                    'request_balance' => round(floatval($checkoutBreakdown['balance_due'] ?? 0), 2),
                    'handling_charge' => round(floatval($checkoutBreakdown['handling_charge'] ?? 0), 2),
                    'processing_fee' => round(floatval($checkoutBreakdown['processing_fee'] ?? ($checkoutBreakdown['paystack_fee'] ?? 0)), 2),
                    'expected_settlement' => round(floatval($checkoutBreakdown['expected_settlement'] ?? 0), 2),
                    'total_payable' => round(floatval($checkoutBreakdown['total_payable'] ?? 0), 2),
                    'payment_method' => 'paystack',
                ];
                $checkoutToken = function_exists('book_system_store_paystack_checkout')
                    ? book_system_store_paystack_checkout($checkoutPayload, function_exists('book_system_paystack_checkout_ttl') ? book_system_paystack_checkout_ttl() : 1800)
                    : null;

                if (!is_string($checkoutToken) || trim($checkoutToken) === '') {
                    throw new RuntimeException('We could not start your Paystack checkout right now. Please try again.');
                }

                $checkoutRedirectUrl = 'payment_instructions.php?checkout_token=' . urlencode($checkoutToken);
            }

            if (function_exists('book_system_remember_submission_result')) {
                book_system_remember_submission_result($submission_scope, $request_fingerprint, [
                    'request_id' => 0,
                    'redirect' => $checkoutRedirectUrl,
                ]);
            }
            if (function_exists('book_system_remember_shared_submission_result')) {
                book_system_remember_shared_submission_result($submission_scope, $request_fingerprint, [
                    'request_id' => 0,
                    'redirect' => $checkoutRedirectUrl,
                ]);
            }

            if (function_exists('book_system_consume_submission_token')) {
                $submission_consumed = book_system_consume_submission_token($submission_scope, $submission_token);
                if (!$submission_consumed) {
                    throw new RuntimeException('The request form expired before it could be finalized. Please refresh and try again.');
                }
            } else {
                $submission_consumed = true;
            }

            portal_request_log_stage('paystack_checkout_redirect', [
                'redirect' => $checkoutRedirectUrl,
                'submission_consumed' => $submission_consumed,
            ]);

            if ($transaction_started) {
                $conn->rollback();
                $transaction_started = false;
            }
            if ($shared_submission_lock_acquired && function_exists('book_system_release_submission_processing_lock')) {
                book_system_release_submission_processing_lock($submission_scope, $request_fingerprint);
                $shared_submission_lock_acquired = false;
            }

            header('Location: ' . $checkoutRedirectUrl, true, 303);
            exit;
        }
    }

    if ($credit_balance > 0) {
        if ($credit_balance >= $total_amount) {
            $credit_used = $total_amount;
            $new_balance = $credit_balance - $total_amount;
            $payment_status = 'paid';
        } else {
            $credit_used = $credit_balance;
            $new_balance = 0;
            $payment_status = 'unpaid';
        }

        $balance_stmt = $conn->prepare("UPDATE students SET credit_balance = ? WHERE student_id = ?");
        $balance_stmt->bind_param('di', $new_balance, $student_id);
        $balance_stmt->execute();
        $balance_stmt->close();
    } else {
        $payment_status = 'unpaid';
    }

    $amount_paid = 0.00;
    portal_request_log_stage('request_insert_start', [
        'student_id' => $student_id,
        'payment_status' => $payment_status,
        'credit_used' => number_format($credit_used, 2, '.', ''),
    ]);
    $request_stmt = $conn->prepare("INSERT INTO requests (student_id, total_amount, amount_paid, credit_used, payment_status, semester_id, admin_id, rep_viewed_at) VALUES (?, ?, ?, ?, ?, ?, ?, NULL)");
    $request_stmt->bind_param('idddsii', $student_id, $total_amount, $amount_paid, $credit_used, $payment_status, $semester_id, $rep_id);
    $request_stmt->execute();
    $request_id = intval($conn->insert_id);
    $request_stmt->close();
    portal_request_log_stage('request_inserted', [
        'request_id' => $request_id,
    ]);

    $insert_values = [];
    $insert_types = '';
    $insert_params = [];
    foreach ($selected_book_ids as $book_id) {
        if (!isset($book_prices[$book_id])) {
            continue;
        }
        $insert_values[] = '(?, ?, ?)';
        $insert_types .= 'iid';
        $insert_params[] = $request_id;
        $insert_params[] = $book_id;
        $insert_params[] = floatval($book_prices[$book_id]);
    }

    if (empty($insert_values)) {
        throw new RuntimeException('No valid books were available to save.');
    }

    portal_request_log_stage('request_items_insert_start', [
        'item_count' => count($insert_values),
    ]);
    $items_sql = "INSERT INTO request_items (request_id, book_id, unit_price) VALUES " . implode(', ', $insert_values);
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param($insert_types, ...$insert_params);
    $items_stmt->execute();
    $items_stmt->close();
    portal_request_log_stage('request_items_inserted', [
        'request_id' => $request_id,
    ]);

    if (function_exists('book_system_create_notification')) {
        $studentLabel = trim($full_name) !== '' ? trim($full_name) : $index_number;
        $bookCount = count($selected_book_ids);
        $notificationTitle = 'New student request received';
        $notificationMessage = $studentLabel . ' submitted a request'
            . ($bookCount > 0 ? ' for ' . $bookCount . ' book' . ($bookCount === 1 ? '' : 's') : '')
            . '.';
        if ($index_number !== '') {
            $notificationMessage .= ' Index: ' . $index_number . '.';
        }
        book_system_create_notification(
            $conn,
            $rep_id,
            null,
            'student_request',
            $notificationTitle,
            $notificationMessage,
            $request_id
        );
    }

    $redirect_url = "payment_instructions.php?request_id=$request_id";
    if (function_exists('book_system_remember_submission_result')) {
        book_system_remember_submission_result($submission_scope, $request_fingerprint, [
            'request_id' => $request_id,
            'redirect' => $redirect_url,
        ]);
    }
    if (function_exists('book_system_remember_shared_submission_result')) {
        book_system_remember_shared_submission_result($submission_scope, $request_fingerprint, [
            'request_id' => $request_id,
            'redirect' => $redirect_url,
        ]);
    }

    if (function_exists('book_system_consume_submission_token')) {
        $submission_consumed = book_system_consume_submission_token($submission_scope, $submission_token);
        if (!$submission_consumed) {
            throw new RuntimeException('The request form expired before it could be finalized. Please refresh and try again.');
        }
    } else {
        $submission_consumed = true;
    }
    portal_request_log_stage('submission_token_consumed', [
        'submission_consumed' => $submission_consumed,
    ]);

    $conn->commit();
    $transaction_started = false;
    if ($shared_submission_lock_acquired && function_exists('book_system_release_submission_processing_lock')) {
        book_system_release_submission_processing_lock($submission_scope, $request_fingerprint);
        $shared_submission_lock_acquired = false;
    }
    portal_request_log_stage('transaction_committed', [
        'request_id' => $request_id,
    ]);
} catch (Throwable $e) {
    if ($transaction_started) {
        $conn->rollback();
        $transaction_started = false;
    }
    portal_request_log_stage('exception_thrown', [
        'message' => $e->getMessage(),
        'rep_id' => $rep_id,
        'index_number' => $index_number,
        'selected_book_ids' => $selected_book_ids,
        'submission_token_present' => $submission_token_present,
        'submission_token_valid' => $submission_token_valid,
        'submission_consumed' => $submission_consumed,
        'request_fingerprint' => $request_fingerprint ?? '',
    ]);
    if (book_system_security_debug_enabled()) {
        die($e->getMessage());
    }
    if ($e instanceof RuntimeException && in_array($e->getMessage(), [
        'Your request form expired. Please go back and try again.',
        'This request was already submitted. Please wait for the payment page to load or refresh the form before trying again.',
        'The request form expired before it could be finalized. Please refresh and try again.',
        'This request is already being processed. Please wait a moment.',
    ], true)) {
        $redirect_url = 'index.php' . ($rep_id > 0 ? '?rep_id=' . urlencode(strval($rep_id)) : '');
        portal_request_set_flash(
            'info',
            $e->getMessage() === 'This request was already submitted. Please wait for the payment page to load or refresh the form before trying again.'
                ? 'That request was already processed. If you still need to continue, use the refreshed form.'
                : ($e->getMessage() === 'This request is already being processed. Please wait a moment.'
                    ? 'That same request is already being processed in another session. Please wait a moment and try again.'
                    : 'The request page was refreshed for safety. Please review your details and submit again.')
        );
        header('Location: ' . $redirect_url);
        exit;
    }
    if (
        $e instanceof RuntimeException && (
            in_array($e->getMessage(), [
                "We couldn't verify your class for this representative. Please use the correct request link or contact your class rep.",
                'No active rep could be assigned to this request.',
                'There is no active semester for requests right now.',
                'No valid books were selected for this rep.',
                'No valid books were available to save.',
                'The selected books did not produce a valid total amount.',
            ], true)
            || str_starts_with($e->getMessage(), 'Duplicate Request:')
        )
    ) {
        die($e->getMessage());
    }

    if ($shared_submission_lock_acquired && function_exists('book_system_release_submission_processing_lock')) {
        book_system_release_submission_processing_lock($submission_scope, $request_fingerprint ?? '');
    }
    die('Unable to submit the request right now. Please try again.');
} finally {
    mysqli_report(MYSQLI_REPORT_OFF);
}

portal_request_log_stage('redirecting_to_payment', [
    'request_id' => $request_id,
    'redirect' => "payment_instructions.php?request_id=$request_id",
]);
header("Location: payment_instructions.php?request_id=$request_id", true, 303);
exit;

