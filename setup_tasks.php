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

function book_system_setup_mark_meta(mysqli $conn, string $key): void {
    $stmt = $conn->prepare("INSERT INTO app_meta (meta_key, meta_value) VALUES (?, '1') ON DUPLICATE KEY UPDATE meta_value = '1'");
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stmt->close();
    }
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
        book_system_setup_ensure_column($conn, 'requests', 'credit_used', 'DECIMAL(10,2) NOT NULL DEFAULT 0');
        book_system_setup_ensure_column($conn, 'requests', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'books_received', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'lecturer_payments', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'students', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'books', 'admin_id', 'INT NULL');
        book_system_setup_ensure_column($conn, 'admins', 'allow_super_admin_access', 'TINYINT(1) NOT NULL DEFAULT 0');

        $conn->query("CREATE TABLE IF NOT EXISTS admins (
            admin_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(30) NOT NULL,
            class_name VARCHAR(30) NULL,
            role ENUM('super_admin', 'rep') NOT NULL DEFAULT 'rep',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            access_code VARCHAR(4) NULL,
            access_code_expires DATETIME NULL,
            momo_number VARCHAR(10) NULL,
            bank_name VARCHAR(30) NULL,
            account_name VARCHAR(30) NULL,
            account_number VARCHAR(20) NULL,
            first_time_code VARCHAR(4) NULL,
            first_time_code_expires DATETIME NULL,
            requires_password_reset TINYINT(1) NOT NULL DEFAULT 0,
            approved_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS rep_signup_requests (
            signup_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            full_name VARCHAR(30) NOT NULL,
            class_name VARCHAR(30) NULL,
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
            KEY idx_rep_signup_status (status),
            KEY idx_rep_signup_created_at (created_at)
        )");

        $conn->query("CREATE TABLE IF NOT EXISTS class_students (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            index_number VARCHAR(50) NOT NULL,
            student_name VARCHAR(150) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_admin_index (admin_id, index_number)
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
                    WHEN (COALESCE(r.amount_paid, 0) + COALESCE(r.credit_used, 0)) >= COALESCE(x.total_amount_calc, 0)
                        THEN 'paid'
                    ELSE 'unpaid'
                END");
        book_system_setup_mark_meta($conn, $priceHistoryKey);
        $messages[] = 'Ensured price history and request item pricing.';
    }

    $lecturerKey = 'lecturer_portal_2026_03_27';
    if (!book_system_setup_meta_done($conn, $lecturerKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS lecturers (
            lecturer_id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
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

    $lecturerMaterialKey = 'lecturer_materials_2026_03_31';
    if (!book_system_setup_meta_done($conn, $lecturerMaterialKey)) {
        $conn->query("CREATE TABLE IF NOT EXISTS lecturer_materials (
            material_id INT AUTO_INCREMENT PRIMARY KEY,
            lecturer_id INT NOT NULL,
            material_title VARCHAR(100) NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            course_code_key VARCHAR(20) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lecturer_material_code (lecturer_id, course_code_key)
        )");
        $conn->query("UPDATE books
            SET course_code_key = UPPER(REPLACE(REPLACE(REPLACE(IFNULL(course_code, ''), ' ', ''), '-', ''), '_', ''))
            WHERE COALESCE(course_code, '') <> '' AND COALESCE(course_code_key, '') = ''");
        book_system_setup_mark_meta($conn, $lecturerMaterialKey);
        $messages[] = 'Ensured lecturer material course-code matching.';
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

    if (file_exists(__DIR__ . '/cache_helper.php')) {
        require_once __DIR__ . '/cache_helper.php';
        if (function_exists('ensure_db_indexes')) {
            ensure_db_indexes($conn);
            $messages[] = 'Ensured performance indexes.';
        }
    }

    return $messages;
}
