<?php

function book_system_setup_ensure_column(mysqli $conn, string $table, string $column, string $definition): void {
    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    if (!$dbRes || $dbRes->num_rows !== 1) {
        return;
    }

    $dbName = strval($dbRes->fetch_assoc()['db_name'] ?? '');
    if ($dbName === '') {
        return;
    }

    $tableStmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    if (!$tableStmt) {
        return;
    }
    $tableStmt->bind_param('ss', $dbName, $table);
    $tableStmt->execute();
    $tableRes = $tableStmt->get_result();
    if (!$tableRes || $tableRes->num_rows !== 1 || intval($tableRes->fetch_assoc()['c'] ?? 0) < 1) {
        return;
    }

    $colStmt = $conn->prepare("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$colStmt) {
        return;
    }
    $colStmt->bind_param('sss', $dbName, $table, $column);
    $colStmt->execute();
    $colRes = $colStmt->get_result();
    if ($colRes && $colRes->num_rows === 1 && intval($colRes->fetch_assoc()['c'] ?? 0) === 0) {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function book_system_setup_ensure_varchar_length(mysqli $conn, string $table, string $column, int $minimumLength, string $nullSql = 'NOT NULL'): void {
    if ($minimumLength <= 0) {
        return;
    }

    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    if (!$dbRes || $dbRes->num_rows !== 1) {
        return;
    }

    $dbName = strval($dbRes->fetch_assoc()['db_name'] ?? '');
    if ($dbName === '') {
        return;
    }

    $stmt = $conn->prepare("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('sss', $dbName, $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    $columnRow = ($res && $res->num_rows === 1) ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$columnRow) {
        return;
    }

    $dataType = strtolower(trim(strval($columnRow['DATA_TYPE'] ?? '')));
    $currentLength = intval($columnRow['CHARACTER_MAXIMUM_LENGTH'] ?? 0);

    if ($dataType !== 'varchar' || $currentLength >= $minimumLength) {
        return;
    }

    $safeNullSql = strtoupper(trim($nullSql)) === 'NULL' ? 'NULL' : 'NOT NULL';
    $conn->query("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` VARCHAR({$minimumLength}) {$safeNullSql}");
}

function book_system_setup_ensure_admin_role_support(mysqli $conn): void {
    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    if (!$dbRes || $dbRes->num_rows !== 1) {
        return;
    }

    $dbName = strval($dbRes->fetch_assoc()['db_name'] ?? '');
    if ($dbName === '') {
        return;
    }

    $stmt = $conn->prepare("SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = 'admins'
          AND COLUMN_NAME = 'role'
        LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $res = $stmt->get_result();
    $columnType = ($res && $res->num_rows === 1) ? strtolower(strval($res->fetch_assoc()['COLUMN_TYPE'] ?? '')) : '';
    $stmt->close();

    if ($columnType === '') {
        return;
    }

    if (strpos($columnType, "'temporary_admin'") === false) {
        $conn->query("ALTER TABLE `admins` MODIFY COLUMN `role` ENUM('super_admin','rep','temporary_admin') NOT NULL DEFAULT 'rep'");
    }

    $conn->query("UPDATE admins
        SET role = 'temporary_admin'
        WHERE (role = '' OR role IS NULL)
          AND COALESCE(delegated_by_admin_id, 0) > 0
          AND COALESCE(temp_admin_permissions, '') <> ''");
}

function book_system_setup_mark_meta(mysqli $conn, string $key): void {
    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    }
}

function book_system_setup_index_exists(mysqli $conn, string $table, string $indexName): bool {
    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    if (!$dbRes || $dbRes->num_rows !== 1) {
        return false;
    }

    $dbName = strval($dbRes->fetch_assoc()['db_name'] ?? '');
    if ($dbName === '') {
        return false;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sss', $dbName, $table, $indexName);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->num_rows === 1 && intval($res->fetch_assoc()['c'] ?? 0) > 0);
    $stmt->close();
    return $exists;
}

function book_system_setup_meta_done(mysqli $conn, string $key): bool {
    $stmt = $conn->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $done = ($res && $res->num_rows === 1 && strval($res->fetch_assoc()['meta_value'] ?? '') === '1');
    $stmt->close();
    return $done;
}

function book_system_run_setup_tasks(mysqli $conn): array {
    $messages = [];

    $conn->query("CREATE TABLE IF NOT EXISTS semesters (
        semester_id INT AUTO_INCREMENT PRIMARY KEY,
        semester_name VARCHAR(30) NOT NULL,
        semester_start_date DATE NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_semester_name (semester_name)
    )");
    $messages[] = 'Ensured semesters table.';

    $conn->query("CREATE TABLE IF NOT EXISTS app_meta (
        meta_key VARCHAR(64) NOT NULL PRIMARY KEY,
        meta_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $messages[] = 'Ensured app_meta table.';

    $semCheck = $conn->query("SELECT semester_id FROM semesters ORDER BY semester_id DESC LIMIT 1");
    if (!$semCheck || $semCheck->num_rows === 0) {
        $name = 'Default Semester';
        $today = date('Y-m-d');
        $stmt = $conn->prepare("INSERT INTO semesters (semester_name, semester_start_date, is_active) VALUES (?, ?, 1)");
        if ($stmt) {
            $stmt->bind_param('ss', $name, $today);
            $stmt->execute();
            $stmt->close();
            $messages[] = 'Created default semester.';
        }
    } else {
        $activeCheck = $conn->query("SELECT semester_id FROM semesters WHERE is_active = 1 LIMIT 1");
        if (!$activeCheck || $activeCheck->num_rows === 0) {
            $row = $semCheck->fetch_assoc();
            $sid = intval($row['semester_id'] ?? 0);
            if ($sid > 0) {
                $conn->query("UPDATE semesters SET is_active = 0");
                $stmt = $conn->prepare("UPDATE semesters SET is_active = 1 WHERE semester_id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $sid);
                    $stmt->execute();
                    $stmt->close();
                }
                $messages[] = 'Marked an active semester.';
            }
        }
    }

    $migrationsKey = 'setup_core_2026_03_27';
    if (!book_system_setup_meta_done($conn, $migrationsKey)) {
        book_system_setup_ensure_column($conn, 'requests', 'semester_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'books_received', 'semester_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'lecturer_payments', 'semester_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'class_students', 'semester_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'class_students', 'normalized_index_number', 'VARCHAR(50) NULL');
        book_system_setup_ensure_column($conn, 'books', 'semester_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'requests', 'credit_used', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
        book_system_setup_ensure_column($conn, 'requests', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'books_received', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'lecturer_payments', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'students', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'books', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'admins', 'allow_super_admin_access', 'TINYINT(1) NOT NULL DEFAULT 0');

        $conn->query("CREATE TABLE IF NOT EXISTS departments (
            department_id INT AUTO_INCREMENT PRIMARY KEY,
            department_name VARCHAR(120) NOT NULL,
            department_code VARCHAR(20) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_departments_name (department_name),
            UNIQUE KEY uq_departments_code (department_code)
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS admins (
            admin_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(30) NOT NULL,
            class_name VARCHAR(30) NULL,
            department_id INT NULL,
            role ENUM('super_admin', 'rep') NOT NULL DEFAULT 'rep',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            access_code VARCHAR(4) NULL,
            access_code_expires DATETIME NULL,
            momo_number VARCHAR(10) NULL,
            bank_name VARCHAR(30) NULL,
            account_name VARCHAR(30) NULL,
            account_number VARCHAR(20) NULL,
            recovery_email VARCHAR(120) NULL,
            approved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS rep_signup_requests (
            signup_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            full_name VARCHAR(30) NOT NULL,
            signup_password_hash VARCHAR(255) NULL,
            class_name VARCHAR(30) NULL,
            recovery_email VARCHAR(120) NULL,
            department_id INT NULL,
            momo_number VARCHAR(10) NULL,
            bank_name VARCHAR(30) NULL,
            account_name VARCHAR(30) NULL,
            account_number VARCHAR(20) NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at DATETIME NULL,
            approved_by_admin_id INT NULL,
            created_admin_id INT NULL,
            UNIQUE KEY uq_rep_signup_username (username),
            KEY idx_rep_signup_department (department_id),
            KEY idx_rep_signup_status (status),
            KEY idx_rep_signup_created_at (created_at)
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS class_students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            semester_id INT NULL,
            index_number VARCHAR(50) NOT NULL,
            normalized_index_number VARCHAR(50) NULL,
            student_name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_admin_semester_index (admin_id, semester_id, index_number)
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS rep_usage_daily (
            usage_id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            usage_date DATE NOT NULL,
            total_seconds INT NOT NULL DEFAULT 0,
            last_activity_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rep_usage_admin_date (admin_id, usage_date),
            KEY idx_rep_usage_date (usage_date),
            KEY idx_rep_usage_total_seconds (total_seconds)
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
            notification_id INT AUTO_INCREMENT PRIMARY KEY,
            recipient_admin_id INT NULL,
            recipient_role VARCHAR(30) NULL,
            notification_type VARCHAR(50) NOT NULL,
            title VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            related_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notifications_recipient_admin_read (recipient_admin_id, is_read, created_at),
            KEY idx_notifications_recipient_role_read (recipient_role, is_read, created_at),
            KEY idx_notifications_created_at (created_at)
        )");

        $sid = book_system_get_active_semester_id($conn);
        if ($sid > 0) {
            $stmt = $conn->prepare("UPDATE requests SET semester_id = ? WHERE semester_id IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("UPDATE books_received SET semester_id = ? WHERE semester_id IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("UPDATE lecturer_payments SET semester_id = ? WHERE semester_id IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();
            }
            $stmt = $conn->prepare("UPDATE class_students SET semester_id = ? WHERE semester_id IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();
            }
            $conn->query("UPDATE class_students SET normalized_index_number = " . book_system_normalized_index_sql('index_number') . " WHERE normalized_index_number IS NULL OR TRIM(normalized_index_number) = ''");
            $stmt = $conn->prepare("UPDATE books SET semester_id = ? WHERE semester_id IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();
            }
        }

        if (book_system_setup_index_exists($conn, 'class_students', 'uq_admin_index')) {
            $conn->query("ALTER TABLE class_students DROP INDEX uq_admin_index");
        }
        if (!book_system_setup_index_exists($conn, 'class_students', 'uq_admin_semester_index')) {
            $conn->query("ALTER TABLE class_students ADD UNIQUE KEY uq_admin_semester_index (admin_id, semester_id, index_number)");
        }
        if (!book_system_setup_index_exists($conn, 'class_students', 'idx_class_students_admin_semester')) {
            $conn->query("CREATE INDEX idx_class_students_admin_semester ON class_students (admin_id, semester_id)");
        }
        if (!book_system_setup_index_exists($conn, 'class_students', 'idx_class_students_semester_normalized')) {
            $conn->query("CREATE INDEX idx_class_students_semester_normalized ON class_students (semester_id, normalized_index_number)");
        }
        if (!book_system_setup_index_exists($conn, 'books', 'idx_books_semester_admin')) {
            $conn->query("CREATE INDEX idx_books_semester_admin ON books (semester_id, admin_id)");
        }

        $defaultAdminId = 1;
        $stmt = $conn->prepare("UPDATE requests SET admin_id = ? WHERE admin_id IS NULL OR admin_id = 0");
        if ($stmt) {
            $stmt->bind_param('i', $defaultAdminId);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("UPDATE books_received SET admin_id = ? WHERE admin_id IS NULL OR admin_id = 0");
        if ($stmt) {
            $stmt->bind_param('i', $defaultAdminId);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("UPDATE lecturer_payments SET admin_id = ? WHERE admin_id IS NULL OR admin_id = 0");
        if ($stmt) {
            $stmt->bind_param('i', $defaultAdminId);
            $stmt->execute();
            $stmt->close();
        }

        book_system_setup_mark_meta($conn, $migrationsKey);
        $messages[] = 'Applied core setup and backfills.';
    }

    $semesterIsolationKey = 'semester_isolation_2026_05_27';
    if (!book_system_setup_meta_done($conn, $semesterIsolationKey)) {
        book_system_setup_ensure_column($conn, 'class_students', 'semester_id', 'INT NULL AFTER admin_id');
        book_system_setup_ensure_column($conn, 'class_students', 'normalized_index_number', 'VARCHAR(50) NULL AFTER index_number');
        book_system_setup_ensure_column($conn, 'books', 'semester_id', 'INT NULL AFTER admin_id');

        $sid = book_system_get_active_semester_id($conn);
        if ($sid > 0) {
            $stmt = $conn->prepare("UPDATE class_students SET semester_id = ? WHERE semester_id IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();
            }
            $conn->query("UPDATE class_students SET normalized_index_number = " . book_system_normalized_index_sql('index_number') . " WHERE normalized_index_number IS NULL OR TRIM(normalized_index_number) = ''");
            $stmt = $conn->prepare("UPDATE books SET semester_id = ? WHERE semester_id IS NULL");
            if ($stmt) {
                $stmt->bind_param('i', $sid);
                $stmt->execute();
                $stmt->close();
            }
        }

        if (book_system_setup_index_exists($conn, 'class_students', 'uq_admin_index')) {
            $conn->query("ALTER TABLE class_students DROP INDEX uq_admin_index");
        }
        if (!book_system_setup_index_exists($conn, 'class_students', 'uq_admin_semester_index')) {
            $conn->query("ALTER TABLE class_students ADD UNIQUE KEY uq_admin_semester_index (admin_id, semester_id, index_number)");
        }
        if (!book_system_setup_index_exists($conn, 'class_students', 'idx_class_students_admin_semester')) {
            $conn->query("CREATE INDEX idx_class_students_admin_semester ON class_students (admin_id, semester_id)");
        }
        if (!book_system_setup_index_exists($conn, 'class_students', 'idx_class_students_semester_normalized')) {
            $conn->query("CREATE INDEX idx_class_students_semester_normalized ON class_students (semester_id, normalized_index_number)");
        }
        if (!book_system_setup_index_exists($conn, 'books', 'idx_books_semester_admin')) {
            $conn->query("CREATE INDEX idx_books_semester_admin ON books (semester_id, admin_id)");
        }

        book_system_setup_mark_meta($conn, $semesterIsolationKey);
        $messages[] = 'Ensured semester isolation for class lists and books.';
    }

    $normalizedIndexKey = 'class_students_normalized_index_2026_05_27';
    if (!book_system_setup_meta_done($conn, $normalizedIndexKey)) {
        book_system_setup_ensure_column($conn, 'class_students', 'normalized_index_number', 'VARCHAR(50) NULL AFTER index_number');
        $conn->query("UPDATE class_students SET normalized_index_number = " . book_system_normalized_index_sql('index_number') . " WHERE normalized_index_number IS NULL OR TRIM(normalized_index_number) = ''");
        if (!book_system_setup_index_exists($conn, 'class_students', 'idx_class_students_semester_normalized')) {
            $conn->query("CREATE INDEX idx_class_students_semester_normalized ON class_students (semester_id, normalized_index_number)");
        }

        book_system_setup_mark_meta($conn, $normalizedIndexKey);
        $messages[] = 'Ensured normalized class list lookup field.';
    }

    $semesterStartDateKey = 'semester_start_dates_2026_05_02';
    if (!book_system_setup_meta_done($conn, $semesterStartDateKey)) {
        book_system_setup_ensure_column($conn, 'semesters', 'semester_start_date', 'DATE NULL AFTER semester_name');
        $conn->query("UPDATE semesters SET semester_start_date = DATE(created_at) WHERE semester_start_date IS NULL");
        book_system_setup_mark_meta($conn, $semesterStartDateKey);
        $messages[] = 'Ensured semester start dates.';
    }

    $repPublicNameKey = 'rep_public_display_names_2026_05_04';
    if (!book_system_setup_meta_done($conn, $repPublicNameKey)) {
        book_system_setup_ensure_column($conn, 'admins', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        $conn->query("UPDATE admins SET public_display_name = full_name WHERE public_display_name IS NULL OR TRIM(public_display_name) = ''");
        book_system_setup_mark_meta($conn, $repPublicNameKey);
        $messages[] = 'Ensured rep public display names.';
    }

    $repProfilePhotoKey = 'rep_profile_photos_2026_05_04';
    if (!book_system_setup_meta_done($conn, $repProfilePhotoKey)) {
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_mark_meta($conn, $repProfilePhotoKey);
        $messages[] = 'Ensured rep profile photo fields.';
    }

    $repSignupPasswordKey = 'rep_signup_password_hash_2026_05_13';
    if (!book_system_setup_meta_done($conn, $repSignupPasswordKey)) {
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'signup_password_hash', 'VARCHAR(255) NULL AFTER full_name');
        book_system_setup_mark_meta($conn, $repSignupPasswordKey);
        $messages[] = 'Ensured rep signup password storage.';
    }

    $repRecoveryEmailKey = 'rep_recovery_emails_2026_05_13';
    if (!book_system_setup_meta_done($conn, $repRecoveryEmailKey)) {
        book_system_setup_ensure_column($conn, 'admins', 'recovery_email', 'VARCHAR(120) NULL AFTER account_number');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'recovery_email', 'VARCHAR(120) NULL AFTER class_name');
        book_system_setup_mark_meta($conn, $repRecoveryEmailKey);
        $messages[] = 'Ensured rep recovery email fields.';
    }

    $notificationsKey = 'notifications_2026_05_13';
    if (!book_system_setup_meta_done($conn, $notificationsKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS notifications (
            notification_id INT AUTO_INCREMENT PRIMARY KEY,
            recipient_admin_id INT NULL,
            recipient_role VARCHAR(30) NULL,
            notification_type VARCHAR(50) NOT NULL,
            title VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            related_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notifications_recipient_admin_read (recipient_admin_id, is_read, created_at),
            KEY idx_notifications_recipient_role_read (recipient_role, is_read, created_at),
            KEY idx_notifications_created_at (created_at)
        )");
        book_system_setup_mark_meta($conn, $notificationsKey);
        $messages[] = 'Ensured notifications table.';
    }

    $repUsageTrackerKey = 'rep_usage_daily_2026_05_13';
    if (!book_system_setup_meta_done($conn, $repUsageTrackerKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS rep_usage_daily (
            usage_id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            usage_date DATE NOT NULL,
            total_seconds INT NOT NULL DEFAULT 0,
            last_activity_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_rep_usage_admin_date (admin_id, usage_date),
            KEY idx_rep_usage_date (usage_date),
            KEY idx_rep_usage_total_seconds (total_seconds)
        )");
        book_system_setup_mark_meta($conn, $repUsageTrackerKey);
        $messages[] = 'Ensured rep usage tracking table.';
    }

    $temporaryAdminKey = 'temporary_admin_controls_2026_05_06';
    if (!book_system_setup_meta_done($conn, $temporaryAdminKey)) {
        book_system_setup_ensure_column($conn, 'admins', 'temp_admin_permissions', 'TEXT NULL AFTER profile_photo_path');
        book_system_setup_ensure_column($conn, 'admins', 'temp_admin_expires_at', 'DATETIME NULL AFTER temp_admin_permissions');
        book_system_setup_ensure_column($conn, 'admins', 'delegated_by_admin_id', 'INT NULL AFTER temp_admin_expires_at');
        $messages[] = 'Ensured temporary-admin access fields.';
        book_system_setup_mark_meta($conn, $temporaryAdminKey);
    }

    $accessModeKey = 'system_access_mode_2026_05_06';
    if (!book_system_setup_meta_done($conn, $accessModeKey)) {
        $conn->query("INSERT INTO app_meta (meta_key, meta_value) VALUES
            ('system_access_mode', 'premium_active'),
            ('premium_start_date', ''),
            ('premium_notice_message', '')
            ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        $messages[] = 'Ensured global access mode settings.';
        book_system_setup_mark_meta($conn, $accessModeKey);
    }

    $repTrialKey = 'rep_trial_subscription_2026_05_04';
    if (!book_system_setup_meta_done($conn, $repTrialKey)) {
        book_system_setup_ensure_column($conn, 'admins', 'trial_started_at', 'DATETIME NULL AFTER approved_at');
        book_system_setup_ensure_column($conn, 'admins', 'trial_expires_at', 'DATETIME NULL AFTER trial_started_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_active', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER trial_expires_at');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_started_at', 'DATETIME NULL AFTER subscription_active');
        book_system_setup_ensure_column($conn, 'admins', 'subscription_expires_at', 'DATETIME NULL AFTER subscription_started_at');

        $conn->query("UPDATE admins
            SET trial_started_at = COALESCE(trial_started_at, approved_at, created_at),
                trial_expires_at = COALESCE(
                    trial_expires_at,
                    DATE_ADD(DATE(COALESCE(approved_at, created_at)), INTERVAL 7 DAY)
                )
            WHERE role = 'rep'");

        book_system_setup_mark_meta($conn, $repTrialKey);
        $messages[] = 'Ensured rep trial and subscription fields.';
    }

    $balanceReturnsKey = 'balance_returns_2026_03_27';
    if (!book_system_setup_meta_done($conn, $balanceReturnsKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS balance_returns (
            return_id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            request_id INT NULL,
            amount DECIMAL(10,2) NOT NULL,
            return_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            notes VARCHAR(255) NULL,
            INDEX idx_balance_returns_student (student_id),
            INDEX idx_balance_returns_request (request_id)
        )");
        book_system_setup_mark_meta($conn, $balanceReturnsKey);
        $messages[] = 'Ensured balance_returns table.';
    }

    $receivedKey = 'books_received_updates_2026_03_27';
    if (!book_system_setup_meta_done($conn, $receivedKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS books_received (
            receive_id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            copies_received INT NOT NULL,
            receive_date DATE NOT NULL,
            lecturer_name VARCHAR(100) NULL,
            notes VARCHAR(255) NULL,
            semester_id INT NULL,
            admin_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
        )");
        book_system_setup_ensure_column($conn, 'books_received', 'unit_price', 'DECIMAL(10,2) NULL');
        $conn->query("UPDATE books_received br JOIN books b ON br.book_id = b.book_id SET br.unit_price = b.price WHERE br.unit_price IS NULL");
        book_system_setup_mark_meta($conn, $receivedKey);
        $messages[] = 'Ensured books_received structure.';
    }

    $priceHistoryKey = 'price_history_2026_03_27';
    if (!book_system_setup_meta_done($conn, $priceHistoryKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS book_price_history (
            history_id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            old_price DECIMAL(10,2) NOT NULL,
            new_price DECIMAL(10,2) NOT NULL,
            changed_by_admin_id INT NULL,
            notes VARCHAR(255) NULL,
            changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            effective_date DATE NULL,
            applied_at TIMESTAMP NULL,
            INDEX idx_book_price_history_book (book_id, changed_at),
            CONSTRAINT fk_book_price_history_book FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE CASCADE
        )");
        book_system_setup_ensure_column($conn, 'request_items', 'unit_price', 'DECIMAL(10,2) NULL');
        book_system_setup_ensure_column($conn, 'request_items', 'received_at', 'DATETIME NULL');
        $conn->query("UPDATE book_price_history SET effective_date = DATE(changed_at) WHERE effective_date IS NULL");
        $conn->query("UPDATE book_price_history SET applied_at = changed_at WHERE applied_at IS NULL");
        $conn->query("UPDATE request_items ri
            JOIN requests r ON r.request_id = ri.request_id
            SET ri.unit_price = COALESCE(
                (
                    SELECT bph.new_price
                    FROM book_price_history bph
                    WHERE bph.book_id = ri.book_id
                      AND bph.effective_date IS NOT NULL
                      AND bph.effective_date <= DATE(r.created_at)
                    ORDER BY bph.effective_date DESC, bph.history_id DESC
                    LIMIT 1
                ),
                (
                    SELECT bph.old_price
                    FROM book_price_history bph
                    WHERE bph.book_id = ri.book_id
                      AND bph.effective_date IS NOT NULL
                      AND bph.effective_date > DATE(r.created_at)
                    ORDER BY bph.effective_date ASC, bph.history_id ASC
                    LIMIT 1
                ),
                (
                    SELECT b.price FROM books b WHERE b.book_id = ri.book_id LIMIT 1
                )
            )
            WHERE ri.unit_price IS NULL");
        $conn->query("UPDATE requests r
            JOIN (
                SELECT request_id, SUM(COALESCE(unit_price, 0)) AS total_amount_calc
                FROM request_items
                GROUP BY request_id
            ) x ON x.request_id = r.request_id
            SET r.total_amount = x.total_amount_calc,
                r.payment_status = CASE
                    WHEN GREATEST(COALESCE(x.total_amount_calc, 0) - COALESCE(r.credit_used, 0), 0) <= 0
                        THEN 'paid'
                    WHEN COALESCE(r.amount_paid, 0) <= 0
                        THEN 'unpaid'
                    WHEN COALESCE(r.amount_paid, 0) >= GREATEST(COALESCE(x.total_amount_calc, 0) - COALESCE(r.credit_used, 0), 0)
                        THEN 'paid'
                    ELSE 'partial'
                END");
        book_system_setup_mark_meta($conn, $priceHistoryKey);
        $messages[] = 'Ensured price history and request item pricing.';
    }

    $partialPaymentStatusKey = 'request_partial_payments_2026_05_26';
    if (!book_system_setup_meta_done($conn, $partialPaymentStatusKey)) {
        $conn->query("ALTER TABLE requests MODIFY payment_status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'unpaid'");
        $conn->query("UPDATE requests
            SET payment_status = CASE
                WHEN GREATEST(COALESCE(total_amount, 0) - COALESCE(credit_used, 0), 0) <= 0
                    THEN 'paid'
                WHEN COALESCE(amount_paid, 0) <= 0
                    THEN 'unpaid'
                WHEN COALESCE(amount_paid, 0) >= GREATEST(COALESCE(total_amount, 0) - COALESCE(credit_used, 0), 0)
                    THEN 'paid'
                ELSE 'partial'
            END");
        book_system_setup_mark_meta($conn, $partialPaymentStatusKey);
        $messages[] = 'Enabled partial payment request statuses.';
    }

    $lecturerKey = 'lecturer_portal_2026_03_27';
    if (!book_system_setup_meta_done($conn, $lecturerKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS lecturers (
            lecturer_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            phone_number VARCHAR(20) NULL,
            teaching_level VARCHAR(10) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $conn->query("CREATE TABLE IF NOT EXISTS lecturer_books (
            lecturer_id INT NOT NULL,
            book_id INT NOT NULL,
            assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (lecturer_id, book_id)
        )");
        $conn->query("CREATE TABLE IF NOT EXISTS lecturer_distributions (
            distribution_id INT AUTO_INCREMENT PRIMARY KEY,
            lecturer_id INT NOT NULL,
            book_id INT NOT NULL,
            rep_admin_id INT NULL,
            copies_given INT NOT NULL,
            given_date DATE NOT NULL,
            notes VARCHAR(255) NULL,
            semester_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        book_system_setup_mark_meta($conn, $lecturerKey);
        $messages[] = 'Ensured lecturer portal tables.';
    }

    $lecturerLevelKey = 'lecturer_level_2026_03_31';
    if (!book_system_setup_meta_done($conn, $lecturerLevelKey)) {
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_level', 'VARCHAR(10) NULL AFTER full_name');
        book_system_setup_mark_meta($conn, $lecturerLevelKey);
        $messages[] = 'Ensured lecturer teaching level.';
    }

    $lecturerPhoneKey = 'lecturer_phone_numbers_2026_05_13';
    if (!book_system_setup_meta_done($conn, $lecturerPhoneKey)) {
        book_system_setup_ensure_column($conn, 'lecturers', 'phone_number', 'VARCHAR(20) NULL AFTER full_name');
        book_system_setup_mark_meta($conn, $lecturerPhoneKey);
        $messages[] = 'Ensured lecturer contact numbers.';
    }

    $lecturerMultiFieldKey = 'lecturer_multi_fields_2026_05_16';
    if (!book_system_setup_meta_done($conn, $lecturerMultiFieldKey)) {
        book_system_setup_ensure_column($conn, 'lecturers', 'teaching_levels', 'TEXT NULL AFTER teaching_level');
        book_system_setup_ensure_column($conn, 'lecturers', 'course_codes', 'TEXT NULL AFTER teaching_levels');
        $conn->query("UPDATE lecturers
            SET teaching_levels = JSON_ARRAY(teaching_level)
            WHERE COALESCE(teaching_level, '') <> '' AND (teaching_levels IS NULL OR teaching_levels = '')");
        book_system_setup_mark_meta($conn, $lecturerMultiFieldKey);
        $messages[] = 'Ensured lecturer multiple levels and course code fields.';
    }

    $lecturerMaterialKey = 'lecturer_materials_2026_03_31';
    if (!book_system_setup_meta_done($conn, $lecturerMaterialKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS lecturer_materials (
            material_id INT AUTO_INCREMENT PRIMARY KEY,
            lecturer_id INT NOT NULL,
            material_title VARCHAR(100) NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            course_code_key VARCHAR(20) NOT NULL,
            academic_level VARCHAR(10) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lecturer_material_code (lecturer_id, course_code_key, academic_level)
        )");
        $conn->query("UPDATE books
            SET course_code_key = UPPER(REPLACE(REPLACE(REPLACE(IFNULL(course_code, ''), ' ', ''), '-', ''), '_', ''))
            WHERE COALESCE(course_code, '') <> '' AND COALESCE(course_code_key, '') = ''");
        book_system_setup_mark_meta($conn, $lecturerMaterialKey);
        $messages[] = 'Ensured lecturer material course-code matching.';
    }

    $lecturerAssignmentLevelKey = 'lecturer_assignment_levels_2026_05_06';
    if (!book_system_setup_meta_done($conn, $lecturerAssignmentLevelKey)) {
        book_system_setup_ensure_column($conn, 'lecturer_materials', 'academic_level', "VARCHAR(10) NOT NULL DEFAULT '' AFTER course_code_key");
        $conn->query("UPDATE lecturer_materials lm
            JOIN lecturers l ON l.lecturer_id = lm.lecturer_id
            SET lm.academic_level = COALESCE(NULLIF(l.teaching_level, ''), '')
            WHERE COALESCE(lm.academic_level, '') = ''");
        $conn->query("ALTER TABLE lecturer_materials DROP INDEX uq_lecturer_material_code");
        $conn->query("ALTER TABLE lecturer_materials ADD UNIQUE KEY uq_lecturer_material_code (lecturer_id, course_code_key, academic_level)");
        book_system_setup_mark_meta($conn, $lecturerAssignmentLevelKey);
        $messages[] = 'Ensured lecturer assignment levels.';
    }

    $bookCourseCodeKey = 'book_course_codes_2026_03_31';
    if (!book_system_setup_meta_done($conn, $bookCourseCodeKey)) {
        book_system_setup_ensure_column($conn, 'books', 'course_code', 'VARCHAR(20) NULL AFTER book_title');
        book_system_setup_ensure_column($conn, 'books', 'course_code_key', 'VARCHAR(20) NULL AFTER course_code');
        $conn->query("UPDATE books
            SET course_code_key = UPPER(REPLACE(REPLACE(REPLACE(IFNULL(course_code, ''), ' ', ''), '-', ''), '_', ''))
            WHERE COALESCE(course_code, '') <> '' AND COALESCE(course_code_key, '') = ''");
        book_system_setup_mark_meta($conn, $bookCourseCodeKey);
        $messages[] = 'Ensured book course codes.';
    }

    $requestSeenKey = 'request_seen_2026_03_31';
    if (!book_system_setup_meta_done($conn, $requestSeenKey)) {
        book_system_setup_ensure_column($conn, 'requests', 'rep_viewed_at', 'DATETIME NULL AFTER created_at');
        book_system_setup_mark_meta($conn, $requestSeenKey);
        $messages[] = 'Ensured rep request notifications.';
    }

    $refundKey = 'request_item_refunds_2026_04_01';
    if (!book_system_setup_meta_done($conn, $refundKey)) {
        book_system_setup_ensure_column($conn, 'request_items', 'is_cancelled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_collected');
        book_system_setup_ensure_column($conn, 'request_items', 'cancelled_at', 'DATETIME NULL AFTER is_cancelled');
        book_system_setup_ensure_column($conn, 'request_items', 'cancel_reason', 'VARCHAR(255) NULL AFTER cancelled_at');
        book_system_setup_ensure_column($conn, 'request_items', 'cash_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cancel_reason');
        book_system_setup_ensure_column($conn, 'request_items', 'credit_refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER cash_refunded_amount');
        $conn->query("UPDATE request_items SET is_cancelled = 0 WHERE is_cancelled IS NULL");
        $conn->query("UPDATE request_items SET cash_refunded_amount = 0 WHERE cash_refunded_amount IS NULL");
        $conn->query("UPDATE request_items SET credit_refunded_amount = 0 WHERE credit_refunded_amount IS NULL");
        book_system_setup_mark_meta($conn, $refundKey);
        $messages[] = 'Ensured request-item refund tracking.';
    }

    $repPortalKey = 'rep_portal_fields_2026_04_01';
    if (!book_system_setup_meta_done($conn, $repPortalKey)) {
        book_system_setup_ensure_column($conn, 'admins', 'index_number', 'VARCHAR(50) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'academic_level', 'VARCHAR(10) NULL AFTER class_name');
        book_system_setup_ensure_column($conn, 'admins', 'program_name', 'VARCHAR(100) NULL AFTER academic_level');
        book_system_setup_ensure_column($conn, 'admins', 'show_on_public_portal', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER program_name');
        book_system_setup_ensure_column($conn, 'admins', 'allow_super_admin_access', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_public_portal');
        $conn->query("UPDATE admins
            SET academic_level = REGEXP_SUBSTR(COALESCE(class_name, ''), '[0-9]{3}')
            WHERE role = 'rep' AND (academic_level IS NULL OR academic_level = '') AND COALESCE(class_name, '') REGEXP '[0-9]{3}'");
        book_system_setup_mark_meta($conn, $repPortalKey);
        $messages[] = 'Ensured rep portal metadata.';
    }

    $repApprovalBackfillKey = 'rep_approval_backfill_2026_05_28';
    if (!book_system_setup_meta_done($conn, $repApprovalBackfillKey)) {
        $conn->query("UPDATE admins
            SET approved_at = COALESCE(approved_at, created_at, NOW())
            WHERE role = 'rep'
              AND is_active = 1
              AND approved_at IS NULL");
        book_system_setup_mark_meta($conn, $repApprovalBackfillKey);
        $messages[] = 'Backfilled approval timestamps for active reps.';
    }

    $bookCodeSuggestionKey = 'books_course_code_lookup_2026_05_12';
    if (!book_system_setup_meta_done($conn, $bookCodeSuggestionKey)) {
        $conn->query("CREATE INDEX idx_books_course_code_key ON books (course_code_key)");
        book_system_setup_mark_meta($conn, $bookCodeSuggestionKey);
        $messages[] = 'Ensured book course-code lookup index.';
    }

    $departmentRegistryKey = 'department_registry_2026_05_09';
    if (!book_system_setup_meta_done($conn, $departmentRegistryKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS departments (
            department_id INT AUTO_INCREMENT PRIMARY KEY,
            department_name VARCHAR(120) NOT NULL,
            department_code VARCHAR(20) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_departments_name (department_name),
            UNIQUE KEY uq_departments_code (department_code)
        )");
        book_system_setup_ensure_column($conn, 'admins', 'department_id', 'INT NULL AFTER program_name');
        book_system_setup_ensure_column($conn, 'rep_signup_requests', 'department_id', 'INT NULL AFTER class_name');
        $conn->query("CREATE INDEX idx_admins_department ON admins (department_id)");
        $conn->query("CREATE INDEX idx_rep_signup_department ON rep_signup_requests (department_id)");
        book_system_setup_mark_meta($conn, $departmentRegistryKey);
        $messages[] = 'Ensured department registry.';
    }

    $repPaymentKey = 'rep_payment_settings_2026_05_26';
    if (!book_system_setup_meta_done($conn, $repPaymentKey)) {
        book_system_setup_ensure_column($conn, 'admins', 'payment_method', "VARCHAR(20) NOT NULL DEFAULT 'manual_momo' AFTER account_name");
        book_system_setup_ensure_column($conn, 'admins', 'momo_network', 'VARCHAR(30) NULL AFTER account_name');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER momo_network');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_public_key', 'VARCHAR(255) NULL AFTER paystack_enabled');
        book_system_setup_ensure_column($conn, 'admins', 'paystack_secret_key', 'VARCHAR(255) NULL AFTER paystack_public_key');
        book_system_setup_ensure_column($conn, 'requests', 'payment_reference', 'VARCHAR(120) NULL AFTER credit_used');
        book_system_setup_ensure_column($conn, 'requests', 'payment_gateway', 'VARCHAR(30) NULL AFTER payment_reference');
        book_system_setup_ensure_column($conn, 'requests', 'payment_verified_at', 'DATETIME NULL AFTER payment_gateway');
        $conn->query("UPDATE admins SET payment_method = 'manual_momo' WHERE payment_method IS NULL OR TRIM(payment_method) = ''");
        $conn->query("UPDATE admins SET momo_network = NULLIF(TRIM(bank_name), '') WHERE (momo_network IS NULL OR TRIM(momo_network) = '') AND COALESCE(bank_name, '') != ''");
        book_system_setup_mark_meta($conn, $repPaymentKey);
        $messages[] = 'Ensured hybrid rep payment settings.';
    }

    $portalAdsKey = 'portal_ads_2026_04_01';
    if (!book_system_setup_meta_done($conn, $portalAdsKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS portal_ads (
            ad_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(120) NOT NULL,
            description VARCHAR(255) NOT NULL,
            owner_name VARCHAR(120) NULL,
            owner_contact VARCHAR(120) NULL,
            link_url VARCHAR(255) NULL,
            badge_text VARCHAR(50) NULL,
            image_path VARCHAR(255) NULL,
            display_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            view_count INT NOT NULL DEFAULT 0,
            click_count INT NOT NULL DEFAULT 0,
            last_viewed_at DATETIME NULL,
            last_clicked_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_portal_ads_active (is_active, display_order, created_at)
        )");
        book_system_setup_ensure_column($conn, 'portal_ads', 'image_path', 'VARCHAR(255) NULL AFTER badge_text');
        book_system_setup_ensure_column($conn, 'portal_ads', 'view_count', 'INT NOT NULL DEFAULT 0 AFTER is_active');
        book_system_setup_ensure_column($conn, 'portal_ads', 'click_count', 'INT NOT NULL DEFAULT 0 AFTER view_count');
        book_system_setup_ensure_column($conn, 'portal_ads', 'last_viewed_at', 'DATETIME NULL AFTER click_count');
        book_system_setup_ensure_column($conn, 'portal_ads', 'last_clicked_at', 'DATETIME NULL AFTER last_viewed_at');
        $conn->query("ALTER TABLE portal_ads MODIFY owner_name VARCHAR(120) NULL");
        book_system_setup_mark_meta($conn, $portalAdsKey);
        $messages[] = 'Ensured portal adverts table.';
    }

    $loginAttemptsKey = 'login_attempts_2026_05_01';
    if (!book_system_setup_meta_done($conn, $loginAttemptsKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
            attempt_id INT AUTO_INCREMENT PRIMARY KEY,
            login_scope VARCHAR(20) NOT NULL,
            identifier CHAR(64) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_login_attempts_scope_identifier_time (login_scope, identifier, attempted_at),
            INDEX idx_login_attempts_attempted_at (attempted_at)
        )");
        book_system_setup_mark_meta($conn, $loginAttemptsKey);
        $messages[] = 'Ensured login attempt throttling table.';
    }

    $lecturerExportKey = 'lecturer_exports_2026_05_02';
    if (!book_system_setup_meta_done($conn, $lecturerExportKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS lecturer_export_batches (
            batch_id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL DEFAULT 0,
            book_id INT NOT NULL,
            semester_id INT NOT NULL,
            export_mode ENUM('new','all') NOT NULL DEFAULT 'new',
            start_date DATE NULL,
            end_date DATE NULL,
            exported_by_username VARCHAR(50) NULL,
            exported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lecturer_export_batches_scope (admin_id, book_id, semester_id, exported_at),
            INDEX idx_lecturer_export_batches_book (book_id)
        )");
        $conn->query("CREATE TABLE IF NOT EXISTS lecturer_export_batch_items (
            batch_item_id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            student_id INT NOT NULL,
            exported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lecturer_export_batch_student (batch_id, student_id),
            INDEX idx_lecturer_export_batch_items_student (student_id),
            CONSTRAINT fk_lecturer_export_batch_items_batch FOREIGN KEY (batch_id) REFERENCES lecturer_export_batches(batch_id) ON DELETE CASCADE,
            CONSTRAINT fk_lecturer_export_batch_items_student FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE
        )");
        book_system_setup_mark_meta($conn, $lecturerExportKey);
        $messages[] = 'Ensured lecturer export tracking tables.';
    }

    $carryForwardKey = 'semester_balance_carry_forwards_2026_05_02';
    if (!book_system_setup_meta_done($conn, $carryForwardKey)) {
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
        book_system_setup_mark_meta($conn, $carryForwardKey);
        $messages[] = 'Ensured semester balance carry-forward table.';
    }

    $auditKey = 'audit_logs_2026_03_27';
    if (!book_system_setup_meta_done($conn, $auditKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS audit_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            actor_admin_id INT NOT NULL DEFAULT 0,
            actor_role VARCHAR(30) NOT NULL DEFAULT 'guest',
            actor_username VARCHAR(50) NOT NULL DEFAULT '',
            action_type VARCHAR(50) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL DEFAULT 0,
            target_admin_id INT NULL,
            details_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created_at (created_at),
            INDEX idx_audit_actor (actor_admin_id, created_at),
            INDEX idx_audit_target (target_admin_id, created_at),
            INDEX idx_audit_entity (entity_type, entity_id)
        )");
        book_system_setup_mark_meta($conn, $auditKey);
        $messages[] = 'Ensured audit log table.';
    }

    $groupDrawKey = 'group_draw_schema_2026_06_10';
    if (!book_system_setup_meta_done($conn, $groupDrawKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS group_draw_sessions (
            session_id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            semester_id INT NOT NULL,
            session_title VARCHAR(120) NOT NULL,
            session_description VARCHAR(255) NULL,
            preferred_group_size TINYINT UNSIGNED NOT NULL DEFAULT 4,
            status ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
            max_draws_per_participant TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_group_draw_sessions_scope (admin_id, semester_id, status, created_at),
            INDEX idx_group_draw_sessions_semester (semester_id, created_at),
            CONSTRAINT fk_group_draw_sessions_admin FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_sessions_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id) ON DELETE CASCADE
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS group_draw_groups (
            group_id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            admin_id INT NOT NULL,
            semester_id INT NOT NULL,
            group_name VARCHAR(120) NOT NULL,
            capacity INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_group_draw_groups_session_name (session_id, group_name),
            INDEX idx_group_draw_groups_scope (admin_id, semester_id, is_active),
            INDEX idx_group_draw_groups_session_order (session_id, sort_order, group_id),
            CONSTRAINT fk_group_draw_groups_session FOREIGN KEY (session_id) REFERENCES group_draw_sessions(session_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_groups_admin FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_groups_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id) ON DELETE CASCADE
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS group_draw_participants (
            participant_id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            admin_id INT NOT NULL,
            semester_id INT NOT NULL,
            participant_name VARCHAR(150) NOT NULL,
            index_number VARCHAR(50) NOT NULL,
            normalized_index_number VARCHAR(50) NOT NULL,
            draw_status ENUM('not_drawn','drawn') NOT NULL DEFAULT 'not_drawn',
            assigned_group_id INT NULL,
            assigned_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_group_draw_participants_session_index (session_id, normalized_index_number),
            INDEX idx_group_draw_participants_scope (admin_id, semester_id, draw_status),
            INDEX idx_group_draw_participants_session_group (session_id, assigned_group_id),
            INDEX idx_group_draw_participants_session_status (session_id, draw_status),
            CONSTRAINT fk_group_draw_participants_session FOREIGN KEY (session_id) REFERENCES group_draw_sessions(session_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_participants_admin FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_participants_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_participants_group FOREIGN KEY (assigned_group_id) REFERENCES group_draw_groups(group_id) ON DELETE SET NULL
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS group_draw_draws (
            draw_id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            participant_id INT NOT NULL,
            group_id INT NOT NULL,
            admin_id INT NOT NULL,
            semester_id INT NOT NULL,
            drawn_by_admin_id INT NOT NULL DEFAULT 0,
            draw_source VARCHAR(30) NOT NULL DEFAULT 'manual',
            notes VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_group_draw_draws_session_participant (session_id, participant_id),
            INDEX idx_group_draw_draws_scope (admin_id, semester_id, created_at),
            INDEX idx_group_draw_draws_session_group (session_id, group_id, created_at),
            INDEX idx_group_draw_draws_drawn_by (drawn_by_admin_id, created_at),
            CONSTRAINT fk_group_draw_draws_session FOREIGN KEY (session_id) REFERENCES group_draw_sessions(session_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_draws_participant FOREIGN KEY (participant_id) REFERENCES group_draw_participants(participant_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_draws_group FOREIGN KEY (group_id) REFERENCES group_draw_groups(group_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_draws_admin FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_draws_semester FOREIGN KEY (semester_id) REFERENCES semesters(semester_id) ON DELETE CASCADE,
            CONSTRAINT fk_group_draw_draws_actor FOREIGN KEY (drawn_by_admin_id) REFERENCES admins(admin_id) ON DELETE RESTRICT
        )");

        book_system_setup_mark_meta($conn, $groupDrawKey);
        $messages[] = 'Ensured Group Draw tables.';
    }

    $groupDrawPreferredSizeKey = 'group_draw_preferred_size_2026_06_10';
    if (!book_system_setup_meta_done($conn, $groupDrawPreferredSizeKey)) {
        book_system_setup_ensure_column($conn, 'group_draw_sessions', 'preferred_group_size', 'TINYINT UNSIGNED NOT NULL DEFAULT 4 AFTER session_description');
        $conn->query("UPDATE group_draw_sessions
            SET preferred_group_size = 4
            WHERE preferred_group_size IS NULL OR preferred_group_size < 2 OR preferred_group_size > 20");
        book_system_setup_mark_meta($conn, $groupDrawPreferredSizeKey);
        $messages[] = 'Ensured Group Draw preferred group size.';
    }

    if (file_exists(__DIR__ . '/cache_helper.php')) {
        require_once __DIR__ . '/cache_helper.php';
        if (function_exists('ensure_db_indexes')) {
            ensure_db_indexes($conn);
            $messages[] = 'Ensured performance indexes.';
        }
    }

    return $messages;
}
