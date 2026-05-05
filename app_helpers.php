<?php

function book_system_get_active_semester_id(mysqli $conn): int {
    $details = book_system_get_active_semester_details($conn);
    return intval($details['semester_id'] ?? 0);
}

function book_system_get_active_semester_details(mysqli $conn): array {
    static $detailsCache = null;
    if (is_array($detailsCache)) {
        return $detailsCache;
    }

    $default = [
        'semester_id' => 0,
        'semester_name' => '',
        'semester_start_date' => '',
    ];

    $res = $conn->query("SELECT semester_id, semester_name, semester_start_date FROM semesters WHERE is_active = 1 ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc() ?: [];
        $detailsCache = [
            'semester_id' => intval($row['semester_id'] ?? 0),
            'semester_name' => strval($row['semester_name'] ?? ''),
            'semester_start_date' => strval($row['semester_start_date'] ?? ''),
        ];
        return $detailsCache;
    }

    $res = $conn->query("SELECT semester_id, semester_name, semester_start_date FROM semesters ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        $row = $res->fetch_assoc() ?: [];
        $detailsCache = [
            'semester_id' => intval($row['semester_id'] ?? 0),
            'semester_name' => strval($row['semester_name'] ?? ''),
            'semester_start_date' => strval($row['semester_start_date'] ?? ''),
        ];
        return $detailsCache;
    }

    $detailsCache = $default;
    return $detailsCache;
}

function book_system_get_active_semester_name(mysqli $conn): string {
    $details = book_system_get_active_semester_details($conn);
    return strval($details['semester_name'] ?? '');
}

function book_system_get_active_semester_start_date(mysqli $conn): string {
    $details = book_system_get_active_semester_details($conn);
    return strval($details['semester_start_date'] ?? '');
}

function book_system_format_semester_start_date(?string $startDate): string {
    $startDate = trim(strval($startDate ?? ''));
    if ($startDate === '') {
        return '';
    }

    $timestamp = strtotime($startDate);
    if ($timestamp === false) {
        return '';
    }

    return date('M d, Y', $timestamp);
}

function book_system_build_semester_label(string $semesterName, ?string $startDate = null): string {
    $semesterName = trim($semesterName);
    if ($semesterName === '') {
        return '';
    }

    $formattedDate = book_system_format_semester_start_date($startDate);
    if ($formattedDate === '') {
        return $semesterName;
    }

    return $semesterName . ' • Starts ' . $formattedDate;
}

function book_system_admin_trial_columns_available(mysqli $conn): bool {
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }

    $checked = true;
    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    $dbName = ($dbRes && $dbRes->num_rows === 1) ? strval($dbRes->fetch_assoc()['db_name'] ?? '') : '';
    if ($dbName === '') {
        return false;
    }

    $required = [
        'trial_started_at',
        'trial_expires_at',
        'subscription_active',
        'subscription_started_at',
        'subscription_expires_at',
    ];

    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $types = str_repeat('s', count($required) + 1);
    $stmt = $conn->prepare("SELECT COUNT(*) AS c
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = 'admins'
          AND COLUMN_NAME IN ($placeholders)");
    if (!$stmt) {
        return false;
    }

    $params = array_merge([$dbName], $required);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = ($res && $res->num_rows === 1) ? intval($res->fetch_assoc()['c'] ?? 0) : 0;
    $stmt->close();

    $available = ($count === count($required));
    return $available;
}

function book_system_trial_expiry_from_start(string $startAt): string {
    $startAt = trim($startAt);
    if ($startAt === '') {
        return '';
    }

    try {
        $timezone = new DateTimeZone('Africa/Accra');
        $start = new DateTime($startAt, $timezone);
        $start->setTime(0, 0, 0);
        $start->modify('+7 days');
        return $start->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return '';
    }
}

function book_system_build_rep_access_status_from_row(array $adminRow): array {
    $timezone = new DateTimeZone('Africa/Accra');
    $now = new DateTime('now', $timezone);

    $role = strval($adminRow['role'] ?? '');
    $approvedAt = trim(strval($adminRow['approved_at'] ?? ''));
    $createdAt = trim(strval($adminRow['created_at'] ?? ''));
    $trialStartedAt = trim(strval($adminRow['trial_started_at'] ?? ''));
    $trialExpiresAt = trim(strval($adminRow['trial_expires_at'] ?? ''));
    $subscriptionActive = intval($adminRow['subscription_active'] ?? 0) === 1;
    $subscriptionStartedAt = trim(strval($adminRow['subscription_started_at'] ?? ''));
    $subscriptionExpiresAt = trim(strval($adminRow['subscription_expires_at'] ?? ''));

    if ($role !== 'rep') {
        return [
            'role' => $role,
            'is_trial_active' => false,
            'is_trial_expiring_soon' => false,
            'is_subscription_active' => true,
            'is_expired' => false,
            'can_access' => true,
            'status_key' => 'unrestricted',
            'trial_started_at' => $trialStartedAt,
            'trial_expires_at' => $trialExpiresAt,
            'subscription_started_at' => $subscriptionStartedAt,
            'subscription_expires_at' => $subscriptionExpiresAt,
            'seconds_left' => null,
            'days_left' => null,
            'reminder_message' => '',
        ];
    }

    if ($trialStartedAt === '') {
        $trialStartedAt = $approvedAt !== '' ? $approvedAt : $createdAt;
    }
    if ($trialExpiresAt === '' && $trialStartedAt !== '') {
        $trialExpiresAt = book_system_trial_expiry_from_start($trialStartedAt);
    }

    $subscriptionStillValid = false;
    if ($subscriptionActive) {
        if ($subscriptionExpiresAt === '') {
            $subscriptionStillValid = true;
        } else {
            try {
                $subscriptionExpiryDate = new DateTime($subscriptionExpiresAt, $timezone);
                $subscriptionStillValid = ($subscriptionExpiryDate > $now);
            } catch (Throwable $e) {
                $subscriptionStillValid = false;
            }
        }
    }

    $secondsLeft = null;
    $daysLeft = null;
    $isExpired = false;
    $isTrialActive = false;
    $isTrialExpiringSoon = false;
    $statusKey = 'trial_unknown';
    $reminderMessage = '';

    if ($subscriptionStillValid) {
        $statusKey = 'subscribed';
    } elseif ($trialExpiresAt !== '') {
        try {
            $trialExpiryDate = new DateTime($trialExpiresAt, $timezone);
            $secondsLeft = $trialExpiryDate->getTimestamp() - $now->getTimestamp();
            $daysLeft = max(0, (int) ceil($secondsLeft / 86400));
            $isExpired = ($secondsLeft <= 0);
            $isTrialActive = !$isExpired;
            $isTrialExpiringSoon = ($secondsLeft > 0 && $secondsLeft <= 172800);
            $statusKey = $isExpired ? 'trial_expired' : ($isTrialExpiringSoon ? 'trial_expiring' : 'trial_active');

            if ($isTrialExpiringSoon && $daysLeft !== null) {
                $reminderMessage = $daysLeft <= 1
                    ? 'Your free trial will expire at 12:00 AM tomorrow.'
                    : 'Your free trial will expire in ' . $daysLeft . ' days.';
            } elseif ($isExpired) {
                $reminderMessage = 'Your 7-day free trial has expired. Please subscribe to continue using your rep workspace.';
            }
        } catch (Throwable $e) {
            $statusKey = 'trial_unknown';
        }
    }

    return [
        'role' => $role,
        'is_trial_active' => $isTrialActive,
        'is_trial_expiring_soon' => $isTrialExpiringSoon,
        'is_subscription_active' => $subscriptionStillValid,
        'is_expired' => $isExpired,
        'can_access' => ($subscriptionStillValid || $isTrialActive),
        'status_key' => $statusKey,
        'trial_started_at' => $trialStartedAt,
        'trial_expires_at' => $trialExpiresAt,
        'subscription_started_at' => $subscriptionStartedAt,
        'subscription_expires_at' => $subscriptionExpiresAt,
        'seconds_left' => $secondsLeft,
        'days_left' => $daysLeft,
        'reminder_message' => $reminderMessage,
    ];
}

function book_system_get_rep_access_status(mysqli $conn, int $adminId): array {
    if ($adminId <= 0) {
        return book_system_build_rep_access_status_from_row([]);
    }

    $columnsAvailable = book_system_admin_trial_columns_available($conn);
    $extraColumns = $columnsAvailable
        ? ", trial_started_at, trial_expires_at, subscription_active, subscription_started_at, subscription_expires_at"
        : '';

    $stmt = $conn->prepare("SELECT admin_id, role, approved_at, created_at{$extraColumns} FROM admins WHERE admin_id = ? LIMIT 1");
    if (!$stmt) {
        return book_system_build_rep_access_status_from_row([]);
    }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : [];
    $stmt->close();

    return book_system_build_rep_access_status_from_row(is_array($row) ? $row : []);
}

function book_system_enforce_rep_subscription_access(mysqli $conn): void {
    if (!isset($_SESSION['admin_logged_in']) || strval($_SESSION['admin_role'] ?? '') !== 'rep') {
        return;
    }

    $currentScript = basename(strval($_SERVER['SCRIPT_NAME'] ?? ''));
    $allowlist = [
        'login.php',
        'logout.php',
        'rep_signup.php',
        'rep_first_time_reset.php',
        'rep_subscription.php',
        'index.php',
        'submit_request.php',
        'check_student_books.php',
        'get_student_request_history.php',
        'get_student_credit.php',
        'common_request_portal.php',
        'portal_ad_redirect.php',
    ];
    if (in_array($currentScript, $allowlist, true)) {
        return;
    }

    $adminId = intval($_SESSION['admin_id'] ?? 0);
    $status = book_system_get_rep_access_status($conn, $adminId);
    if (!empty($status['can_access'])) {
        return;
    }

    header('Location: rep_subscription.php');
    exit;
}

function book_system_get_rep_workspace_context(mysqli $conn, int $sessionAdminId, string $sessionRole): ?array {
    if ($sessionRole === 'super_admin') {
        $repId = intval($_SESSION['super_admin_rep_context_id'] ?? 0);
        if ($repId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access, is_active
            FROM admins
            WHERE admin_id = ? AND role = 'rep'
            LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $repId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row || intval($row['is_active'] ?? 0) !== 1 || intval($row['allow_super_admin_access'] ?? 0) !== 1) {
            unset($_SESSION['super_admin_rep_context_id']);
            return null;
        }

        return $row;
    }

    $stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access, is_active
        FROM admins
        WHERE admin_id = ? AND role = 'rep'
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $sessionAdminId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || intval($row['is_active'] ?? 0) !== 1) {
        return null;
    }

    return $row;
}

function book_system_get_effective_rep_access_context(mysqli $conn): ?array {
    $sessionRole = strval($_SESSION['admin_role'] ?? '');
    $sessionAdminId = intval($_SESSION['admin_id'] ?? 0);
    $repContext = null;

    if ($sessionRole === 'super_admin') {
        $superAdminScope = strval($_SESSION['super_admin_data_scope'] ?? 'own');
        if ($superAdminScope === 'workspace') {
            $repContext = book_system_get_rep_workspace_context($conn, $sessionAdminId, $sessionRole);
        }

        if (!$repContext) {
            $stmt = $conn->prepare("SELECT admin_id, username, full_name, class_name, COALESCE(allow_super_admin_access, 0) AS allow_super_admin_access, 1 AS is_active
                FROM admins
                WHERE admin_id = ?
                LIMIT 1");
            if (!$stmt) {
                return null;
            }
            $stmt->bind_param('i', $sessionAdminId);
            $stmt->execute();
            $res = $stmt->get_result();
            $repContext = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
            $stmt->close();
        }
    } else {
        $repContext = book_system_get_rep_workspace_context($conn, $sessionAdminId, $sessionRole);
    }

    if (!$repContext) {
        return null;
    }

    return [
        'session_role' => $sessionRole,
        'session_admin_id' => $sessionAdminId,
        'session_username' => strval($_SESSION['admin_username'] ?? ''),
        'is_workspace_mode' => ($sessionRole === 'super_admin' && strval($_SESSION['super_admin_data_scope'] ?? 'own') === 'workspace'),
        'effective_admin_id' => intval($repContext['admin_id'] ?? 0),
        'effective_role' => 'rep',
        'effective_username' => strval($repContext['username'] ?? ''),
        'effective_full_name' => strval($repContext['full_name'] ?? ''),
        'effective_class_name' => strval($repContext['class_name'] ?? ''),
        'rep_context' => $repContext,
    ];
}

function book_system_ensure_balance_carry_forward_table(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->query("CREATE TABLE IF NOT EXISTS semester_balance_carry_forwards (
        carry_id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        student_id INT NOT NULL,
        admin_id INT NOT NULL DEFAULT 0,
        source_semester_id INT NOT NULL,
        target_semester_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        carried_by_role VARCHAR(30) NOT NULL DEFAULT 'system',
        notes VARCHAR(255) NULL,
        carried_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_balance_carry_request (request_id),
        INDEX idx_balance_carry_target (target_semester_id, admin_id),
        INDEX idx_balance_carry_student (student_id),
        CONSTRAINT fk_balance_carry_request FOREIGN KEY (request_id) REFERENCES requests(request_id) ON DELETE CASCADE,
        CONSTRAINT fk_balance_carry_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
    )");

    $ensured = true;
}

function book_system_run_balance_carry_forward(mysqli $conn, int $targetSemesterId): array {
    $summary = [
        'carried_count' => 0,
        'carried_total' => 0.0,
        'target_semester_id' => $targetSemesterId,
    ];

    if ($targetSemesterId <= 0) {
        return $summary;
    }

    book_system_ensure_balance_carry_forward_table($conn);

    $sql = "SELECT
                r.request_id,
                r.student_id,
                COALESCE(r.admin_id, 0) AS admin_id,
                COALESCE(r.semester_id, 0) AS source_semester_id,
                GREATEST(
                    GREATEST(COALESCE(r.amount_paid, 0) - GREATEST(COALESCE(r.total_amount, 0) - COALESCE(r.credit_used, 0), 0), 0)
                    - COALESCE(br.refunded_amount, 0)
                    - COALESCE(cf.carried_amount, 0),
                    0
                ) AS carry_amount
            FROM requests r
            LEFT JOIN (
                SELECT request_id, SUM(amount) AS refunded_amount
                FROM balance_returns
                WHERE request_id IS NOT NULL
                GROUP BY request_id
            ) br ON br.request_id = r.request_id
            LEFT JOIN (
                SELECT request_id, SUM(amount) AS carried_amount
                FROM semester_balance_carry_forwards
                GROUP BY request_id
            ) cf ON cf.request_id = r.request_id
            WHERE r.semester_id IS NOT NULL
              AND r.semester_id < ?
            HAVING carry_amount > 0.009
            ORDER BY r.semester_id ASC, r.admin_id ASC, r.student_id ASC, r.request_id ASC";

    $eligible_rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $targetSemesterId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $eligible_rows[] = $row;
            }
        }
        $stmt->close();
    }

    if (empty($eligible_rows)) {
        return $summary;
    }

    $conn->begin_transaction();
    try {
        $insertCarry = $conn->prepare("INSERT INTO semester_balance_carry_forwards (
                request_id,
                student_id,
                admin_id,
                source_semester_id,
                target_semester_id,
                amount,
                carried_by_role,
                notes
            ) VALUES (?, ?, ?, ?, ?, ?, 'system', ?)");
        $updateCredit = $conn->prepare("UPDATE students SET credit_balance = COALESCE(credit_balance, 0) + ? WHERE student_id = ?");

        if (!$insertCarry || !$updateCredit) {
            throw new RuntimeException('Unable to prepare carry-forward statements.');
        }

        foreach ($eligible_rows as $row) {
            $requestId = intval($row['request_id'] ?? 0);
            $studentId = intval($row['student_id'] ?? 0);
            $adminId = intval($row['admin_id'] ?? 0);
            $sourceSemesterId = intval($row['source_semester_id'] ?? 0);
            $amount = round(floatval($row['carry_amount'] ?? 0), 2);

            if ($requestId <= 0 || $studentId <= 0 || $sourceSemesterId <= 0 || $amount <= 0) {
                continue;
            }

            $notes = 'Auto-carried into semester ' . $targetSemesterId;
            $insertCarry->bind_param(
                'iiiiids',
                $requestId,
                $studentId,
                $adminId,
                $sourceSemesterId,
                $targetSemesterId,
                $amount,
                $notes
            );
            $insertCarry->execute();

            $updateCredit->bind_param('di', $amount, $studentId);
            $updateCredit->execute();

            $summary['carried_count']++;
            $summary['carried_total'] += $amount;
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Book System balance carry-forward failed: ' . $e->getMessage());
    }

    return $summary;
}

function book_system_json_encode(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '{}';
}

function book_system_profile_photo_upload_dir(): string {
    return __DIR__ . '/uploads/rep_profiles';
}

function book_system_ensure_profile_photo_upload_dir(): bool {
    $dir = book_system_profile_photo_upload_dir();
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true);
}

function book_system_store_profile_photo_upload(array $file, string $prefix = 'rep'): array {
    $result = [
        'success' => false,
        'path' => '',
        'message' => 'Unable to upload profile picture.',
    ];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $result['message'] = 'Please choose a profile picture to upload.';
        return $result;
    }

    if (!book_system_ensure_profile_photo_upload_dir()) {
        $result['message'] = 'Profile picture folder is not available.';
        return $result;
    }

    $tmpPath = strval($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        $result['message'] = 'Uploaded profile picture could not be verified.';
        return $result;
    }

    $maxBytes = 3 * 1024 * 1024;
    if (intval($file['size'] ?? 0) > $maxBytes) {
        $result['message'] = 'Profile picture must be 3 MB or less.';
        return $result;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = strval(finfo_file($finfo, $tmpPath) ?: '');
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = strval(mime_content_type($tmpPath) ?: '');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        $result['message'] = 'Profile picture must be JPG, PNG, or WEBP.';
        return $result;
    }

    $extension = $allowed[$mime];
    try {
        $filename = $prefix . '_' . bin2hex(random_bytes(10)) . '.' . $extension;
    } catch (Throwable $e) {
        $filename = $prefix . '_' . str_replace('.', '', (string) microtime(true)) . '_' . mt_rand(1000, 9999) . '.' . $extension;
    }

    $targetPath = book_system_profile_photo_upload_dir() . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        $result['message'] = 'Could not save the uploaded profile picture.';
        return $result;
    }

    $result['success'] = true;
    $result['path'] = 'uploads/rep_profiles/' . $filename;
    $result['message'] = '';
    return $result;
}

function book_system_delete_profile_photo(?string $relativePath): void {
    $relativePath = trim(strval($relativePath ?? ''));
    if ($relativePath === '' || strpos($relativePath, 'uploads/rep_profiles/') !== 0) {
        return;
    }

    $absolutePath = __DIR__ . '/' . str_replace(['\\', '..'], ['/', ''], $relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function book_system_audit_log(
    mysqli $conn,
    string $actionType,
    string $entityType,
    int $entityId = 0,
    array $details = [],
    ?int $targetAdminId = null
): void {
    static $auditTableChecked = false;
    static $auditTableAvailable = false;

    if (!$auditTableChecked) {
        $auditTableChecked = true;
        $check = $conn->query("SHOW TABLES LIKE 'audit_logs'");
        $auditTableAvailable = ($check && $check->num_rows === 1);
    }

    if (!$auditTableAvailable) {
        return;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        book_system_secure_session_start();
    }

    $actorAdminId = intval($_SESSION['admin_id'] ?? 0);
    $actorRole = strval($_SESSION['admin_role'] ?? 'guest');
    $actorUsername = strval($_SESSION['admin_username'] ?? '');
    $entityId = max(0, $entityId);
    $targetAdminId = $targetAdminId !== null ? max(0, $targetAdminId) : null;
    $detailsJson = book_system_json_encode($details);

    $stmt = $conn->prepare(
        "INSERT INTO audit_logs (
            actor_admin_id,
            actor_role,
            actor_username,
            action_type,
            entity_type,
            entity_id,
            target_admin_id,
            details_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'issssiss',
        $actorAdminId,
        $actorRole,
        $actorUsername,
        $actionType,
        $entityType,
        $entityId,
        $targetAdminId,
        $detailsJson
    );
    $stmt->execute();
    $stmt->close();
}

function book_system_cancel_request_item(
    mysqli $conn,
    int $itemId,
    ?int $adminId = null,
    bool $isSuperAdmin = false,
    string $reason = ''
): array {
    $response = [
        'success' => false,
        'message' => 'Unable to cancel this item.',
        'cash_refunded' => 0.0,
        'credit_refunded' => 0.0,
        'request_id' => 0,
    ];

    $reason = trim($reason);
    if ($itemId <= 0) {
        $response['message'] = 'Invalid request item.';
        return $response;
    }

    $sql = "SELECT
            ri.item_id,
            ri.request_id,
            ri.book_id,
            ri.is_collected,
            COALESCE(ri.is_cancelled, 0) AS is_cancelled,
            COALESCE(ri.unit_price, b.price, 0) AS unit_price,
            b.book_title,
            r.student_id,
            r.total_amount,
            r.amount_paid,
            COALESCE(r.credit_used, 0) AS credit_used,
            s.credit_balance
        FROM request_items ri
        JOIN requests r ON r.request_id = ri.request_id
        JOIN students s ON s.student_id = r.student_id
        LEFT JOIN books b ON b.book_id = ri.book_id
        WHERE ri.item_id = ?";

    if (!$isSuperAdmin && $adminId !== null && $adminId > 0) {
        $sql .= " AND r.admin_id = ?";
    }

    $sql .= " LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $response['message'] = 'Could not prepare refund lookup.';
        return $response;
    }

    if (!$isSuperAdmin && $adminId !== null && $adminId > 0) {
        $stmt->bind_param('ii', $itemId, $adminId);
    } else {
        $stmt->bind_param('i', $itemId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $item = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$item) {
        $response['message'] = 'This item could not be found.';
        return $response;
    }

    if (intval($item['is_cancelled'] ?? 0) === 1) {
        $response['message'] = 'This item has already been cancelled.';
        return $response;
    }

    if (intval($item['is_collected'] ?? 0) === 1) {
        $response['message'] = 'Collected books cannot be cancelled.';
        return $response;
    }

    $requestId = intval($item['request_id'] ?? 0);
    $studentId = intval($item['student_id'] ?? 0);
    $unitPrice = floatval($item['unit_price'] ?? 0);
    $oldTotal = floatval($item['total_amount'] ?? 0);
    $oldAmountPaid = floatval($item['amount_paid'] ?? 0);
    $oldCreditUsed = floatval($item['credit_used'] ?? 0);
    $bookTitle = strval($item['book_title'] ?? 'Book');

    $newTotal = max(0, $oldTotal - $unitPrice);
    $newCreditUsed = min($oldCreditUsed, $newTotal);
    $creditRefund = max(0, $oldCreditUsed - $newCreditUsed);
    $dueAfterCredit = max(0, $newTotal - $newCreditUsed);
    $cashRefund = max(0, $oldAmountPaid - $dueAfterCredit);
    $newAmountPaid = max(0, $oldAmountPaid - $cashRefund);
    $newStatus = (($newAmountPaid + $newCreditUsed) >= $newTotal) ? 'paid' : 'unpaid';
    $refundNote = $reason !== '' ? $reason : ('Cancelled item refund: ' . $bookTitle);

    $conn->begin_transaction();
    try {
        $updateItem = $conn->prepare("UPDATE request_items
            SET is_cancelled = 1,
                cancelled_at = NOW(),
                cancel_reason = ?,
                cash_refunded_amount = ?,
                credit_refunded_amount = ?,
                received_at = NULL
            WHERE item_id = ?");
        if (!$updateItem) {
            throw new RuntimeException('Could not update item cancellation.');
        }
        $updateItem->bind_param('sddi', $refundNote, $cashRefund, $creditRefund, $itemId);
        if (!$updateItem->execute()) {
            throw new RuntimeException('Failed to save cancellation state.');
        }
        $updateItem->close();

        $updateRequest = $conn->prepare("UPDATE requests
            SET total_amount = ?,
                amount_paid = ?,
                credit_used = ?,
                payment_status = ?
            WHERE request_id = ?");
        if (!$updateRequest) {
            throw new RuntimeException('Could not update request totals.');
        }
        $updateRequest->bind_param('dddsi', $newTotal, $newAmountPaid, $newCreditUsed, $newStatus, $requestId);
        if (!$updateRequest->execute()) {
            throw new RuntimeException('Failed to update request totals.');
        }
        $updateRequest->close();

        if ($creditRefund > 0) {
            $creditStmt = $conn->prepare("UPDATE students SET credit_balance = COALESCE(credit_balance, 0) + ? WHERE student_id = ?");
            if (!$creditStmt) {
                throw new RuntimeException('Could not restore student credit.');
            }
            $creditStmt->bind_param('di', $creditRefund, $studentId);
            if (!$creditStmt->execute()) {
                throw new RuntimeException('Failed to restore student credit.');
            }
            $creditStmt->close();
        }

        if ($cashRefund > 0) {
            $returnStmt = $conn->prepare("INSERT INTO balance_returns (student_id, request_id, amount, notes) VALUES (?, ?, ?, ?)");
            if (!$returnStmt) {
                throw new RuntimeException('Could not record refund.');
            }
            $returnStmt->bind_param('iids', $studentId, $requestId, $cashRefund, $refundNote);
            if (!$returnStmt->execute()) {
                throw new RuntimeException('Failed to record refund.');
            }
            $returnStmt->close();
        }

        $conn->commit();

        if (function_exists('book_system_audit_log')) {
            book_system_audit_log($conn, 'cancel_request_item', 'request_item', $itemId, [
                'request_id' => $requestId,
                'book_title' => $bookTitle,
                'cash_refunded' => $cashRefund,
                'credit_refunded' => $creditRefund,
                'reason' => $reason,
            ]);
        }

        $response['success'] = true;
        $response['message'] = 'Book cancelled and refund recorded.';
        $response['cash_refunded'] = $cashRefund;
        $response['credit_refunded'] = $creditRefund;
        $response['request_id'] = $requestId;
        return $response;
    } catch (Throwable $e) {
        $conn->rollback();
        $response['message'] = $e->getMessage();
        return $response;
    }
}

function book_system_get_dashboard_metrics(mysqli $conn, int $semesterId, ?int $adminId = null): array {
    $cache_key = 'dashboard_metrics_sem_' . $semesterId . '_admin_' . intval($adminId ?? 0);
    $builder = function() use ($conn, $semesterId, $adminId) {
        $adminFilterSql = '';
        $adminFilterTypes = 'i';
        $adminFilterParams = [$semesterId];

        if ($adminId !== null && $adminId > 0) {
            $adminFilterSql = ' AND admin_id = ?';
            $adminFilterTypes .= 'i';
            $adminFilterParams[] = $adminId;
        }

        $metrics = [
            'cash_collected' => 0.0,
            'paid_revenue' => 0.0,
            'credit_used' => 0.0,
            'lecturer_paid' => 0.0,
            'refundable_cash' => 0.0,
            'available_balance' => 0.0,
            'unpaid_requests' => 0,
            'collected_items' => 0,
            'pending_items' => 0,
            'low_stock_books' => 0,
            'overdue_requests' => 0,
        ];

        $queries = [
            'cash_collected' => "SELECT COALESCE(SUM(amount_paid), 0) AS value FROM requests WHERE payment_status = 'paid' AND semester_id = ?" . $adminFilterSql,
            'paid_revenue' => "SELECT COALESCE(SUM(amount_paid), 0) AS value FROM requests WHERE payment_status = 'paid' AND semester_id = ?" . $adminFilterSql,
            'credit_used' => "SELECT COALESCE(SUM(credit_used), 0) AS value FROM requests WHERE semester_id = ?" . $adminFilterSql,
            'lecturer_paid' => "SELECT COALESCE(SUM(amount_paid), 0) AS value FROM lecturer_payments WHERE semester_id = ?" . $adminFilterSql,
            'refundable_cash' => "SELECT COALESCE(SUM(GREATEST(amount_paid - GREATEST(total_amount - COALESCE(credit_used, 0), 0), 0)), 0) AS value
                FROM requests
                WHERE payment_status = 'paid' AND semester_id = ?" . $adminFilterSql,
            'unpaid_requests' => "SELECT COUNT(*) AS value FROM requests WHERE payment_status = 'unpaid' AND semester_id = ?" . $adminFilterSql,
            'collected_items' => "SELECT COUNT(*) AS value
                FROM request_items ri
                JOIN requests r ON r.request_id = ri.request_id
                WHERE r.semester_id = ?" . ($adminId !== null && $adminId > 0 ? " AND r.admin_id = ?" : '') . " AND ri.is_collected = 1 AND COALESCE(ri.is_cancelled, 0) = 0",
            'pending_items' => "SELECT COUNT(*) AS value
                FROM request_items ri
                JOIN requests r ON r.request_id = ri.request_id
                WHERE r.semester_id = ?" . ($adminId !== null && $adminId > 0 ? " AND r.admin_id = ?" : '') . " AND COALESCE(ri.is_cancelled, 0) = 0 AND (ri.is_collected = 0 OR ri.is_collected IS NULL)",
            'overdue_requests' => "SELECT COUNT(*) AS value FROM requests
                WHERE payment_status = 'unpaid' AND semester_id = ?" . $adminFilterSql . " AND created_at < (NOW() - INTERVAL 7 DAY)",
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param($adminFilterTypes, ...$adminFilterParams);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows === 1) {
                $value = $res->fetch_assoc()['value'] ?? 0;
                $metrics[$key] = in_array($key, ['cash_collected', 'paid_revenue', 'credit_used', 'lecturer_paid', 'refundable_cash'], true)
                    ? floatval($value)
                    : intval($value);
            }
            $stmt->close();
        }

        $metrics['available_balance'] = floatval($metrics['cash_collected']) - floatval($metrics['lecturer_paid']);

        $lowStockSql = "SELECT COUNT(*) AS value FROM books WHERE availability = 'available' AND stock_quantity > 0 AND stock_quantity <= 5";
        $res = $conn->query($lowStockSql);
        if ($res && $res->num_rows === 1) {
            $metrics['low_stock_books'] = intval($res->fetch_assoc()['value'] ?? 0);
        }

        return $metrics;
    };

    if (function_exists('cache_get')) {
        $cached = cache_get($cache_key, 30, $builder);
        if (is_array($cached)) {
            return $cached;
        }
    }

    return $builder();
}

function book_system_get_semester_chart_data(mysqli $conn, int $limit = 6, ?int $adminId = null): array {
    $limit = max(1, min(12, $limit));
    $cache_key = 'semester_chart_limit_' . $limit . '_admin_' . intval($adminId ?? 0);
    $builder = function() use ($conn, $limit, $adminId) {
        $semesters = [];

        $stmt = $conn->prepare("SELECT semester_id, semester_name, semester_start_date FROM semesters ORDER BY semester_id DESC LIMIT ?");
        if ($stmt) {
            $stmt->bind_param('i', $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $semesters[] = $row;
                }
            }
            $stmt->close();
        }

        if (empty($semesters)) {
            return [];
        }

        $rows = [];
        foreach (array_reverse($semesters) as $semester) {
            $semesterId = intval($semester['semester_id'] ?? 0);
            $row = [
                'semester_id' => $semesterId,
                'semester_name' => strval($semester['semester_name'] ?? ('Semester ' . $semesterId)),
                'semester_start_date' => strval($semester['semester_start_date'] ?? ''),
                'revenue' => 0.0,
                'unpaid_balance' => 0.0,
                'collected_items' => 0,
                'pending_items' => 0,
            ];

            $sql = "SELECT
                        COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) AS revenue,
                        COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN GREATEST(total_amount - COALESCE(amount_paid, 0) - COALESCE(credit_used, 0), 0) ELSE 0 END), 0) AS unpaid_balance
                    FROM requests
                    WHERE semester_id = ?";
            if ($adminId !== null && $adminId > 0) {
                $sql .= " AND admin_id = ?";
            }
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($adminId !== null && $adminId > 0) {
                    $stmt->bind_param('ii', $semesterId, $adminId);
                } else {
                    $stmt->bind_param('i', $semesterId);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows === 1) {
                    $agg = $res->fetch_assoc();
                    $row['revenue'] = floatval($agg['revenue'] ?? 0);
                    $row['unpaid_balance'] = floatval($agg['unpaid_balance'] ?? 0);
                }
                $stmt->close();
            }

            $sql = "SELECT
                        COALESCE(SUM(CASE WHEN ri.is_collected = 1 AND COALESCE(ri.is_cancelled, 0) = 0 THEN 1 ELSE 0 END), 0) AS collected_items,
                        COALESCE(SUM(CASE WHEN (ri.is_collected = 0 OR ri.is_collected IS NULL) AND COALESCE(ri.is_cancelled, 0) = 0 THEN 1 ELSE 0 END), 0) AS pending_items
                    FROM request_items ri
                    JOIN requests r ON r.request_id = ri.request_id
                    WHERE r.semester_id = ?";
            if ($adminId !== null && $adminId > 0) {
                $sql .= " AND r.admin_id = ?";
            }
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                if ($adminId !== null && $adminId > 0) {
                    $stmt->bind_param('ii', $semesterId, $adminId);
                } else {
                    $stmt->bind_param('i', $semesterId);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $res->num_rows === 1) {
                    $agg = $res->fetch_assoc();
                    $row['collected_items'] = intval($agg['collected_items'] ?? 0);
                    $row['pending_items'] = intval($agg['pending_items'] ?? 0);
                }
                $stmt->close();
            }

            $rows[] = $row;
        }

        return $rows;
    };

    if (function_exists('cache_get')) {
        $cached = cache_get($cache_key, 60, $builder);
        if (is_array($cached)) {
            return $cached;
        }
    }

    return $builder();
}

function book_system_get_dashboard_alerts(mysqli $conn, int $semesterId, ?int $adminId = null): array {
    $cache_key = 'dashboard_alerts_sem_' . $semesterId . '_admin_' . intval($adminId ?? 0);
    $builder = function() use ($conn, $semesterId, $adminId) {
        $alerts = [];
        $metrics = book_system_get_dashboard_metrics($conn, $semesterId, $adminId);

        if ($metrics['low_stock_books'] > 0) {
            $alerts[] = [
                'level' => 'warning',
                'message' => $metrics['low_stock_books'] . ' book(s) are low on stock (5 copies or fewer).',
            ];
        }

        if ($metrics['unpaid_requests'] > 0) {
            $alerts[] = [
                'level' => 'info',
                'message' => $metrics['unpaid_requests'] . ' unpaid request(s) need follow-up.',
            ];
        }

        if ($metrics['overdue_requests'] > 0) {
            $alerts[] = [
                'level' => 'danger',
                'message' => $metrics['overdue_requests'] . ' unpaid request(s) are older than 7 days.',
            ];
        }

        return $alerts;
    };

    if (function_exists('cache_get')) {
        $cached = cache_get($cache_key, 30, $builder);
        if (is_array($cached)) {
            return $cached;
        }
    }

    return $builder();
}

function book_system_fetch_recent_activity(mysqli $conn, int $limit = 20, ?int $viewerAdminId = null, bool $isSuperAdmin = false, ?string $actorRole = null): array {
    $limit = max(1, min(100, $limit));
    $cache_key = 'recent_activity_limit_' . $limit . '_viewer_' . intval($viewerAdminId ?? 0) . '_super_' . ($isSuperAdmin ? '1' : '0') . '_role_' . md5(strval($actorRole ?? ''));
    $builder = function() use ($conn, $limit, $viewerAdminId, $isSuperAdmin, $actorRole) {
        $sql = "SELECT
                    al.*,
                    a.full_name AS actor_full_name,
                    a.class_name AS actor_class_name
                FROM audit_logs al
                LEFT JOIN admins a ON a.admin_id = al.actor_admin_id
                WHERE 1=1";

        $types = '';
        $params = [];

        if (!$isSuperAdmin && $viewerAdminId !== null && $viewerAdminId > 0) {
            $sql .= " AND (al.actor_admin_id = ? OR al.target_admin_id = ?)";
            $types .= 'ii';
            $params[] = $viewerAdminId;
            $params[] = $viewerAdminId;
        }

        if ($actorRole !== null && $actorRole !== '') {
            $sql .= " AND al.actor_role = ?";
            $types .= 's';
            $params[] = $actorRole;
        }

        $sql .= " ORDER BY al.created_at DESC, al.log_id DESC LIMIT ?";
        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        $stmt->close();

        return $rows;
    };

    if (function_exists('cache_get')) {
        $cached = cache_get($cache_key, 20, $builder);
        if (is_array($cached)) {
            return $cached;
        }
    }

    return $builder();
}

function book_system_normalize_teaching_level(?string $level): string {
    $value = preg_replace('/[^0-9]/', '', strval($level ?? ''));
    $allowed = ['100', '200', '300', '400', '500', '600'];
    return in_array($value, $allowed, true) ? $value : '';
}

function book_system_build_level_regex(?string $level): string {
    $normalized = book_system_normalize_teaching_level($level);
    if ($normalized === '') {
        return '';
    }

    return '(^|[^0-9])' . $normalized . '([^0-9]|$)';
}

function book_system_get_teaching_level_options(): array {
    return ['100', '200', '300', '400', '500', '600'];
}

function book_system_normalize_course_code(?string $courseCode): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', strval($courseCode ?? '')));
}

