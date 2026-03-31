<?php

function book_system_get_active_semester_id(mysqli $conn): int {
    $res = $conn->query("SELECT semester_id FROM semesters WHERE is_active = 1 ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        return intval($res->fetch_assoc()['semester_id'] ?? 0);
    }

    $res = $conn->query("SELECT semester_id FROM semesters ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        return intval($res->fetch_assoc()['semester_id'] ?? 0);
    }

    return 0;
}

function book_system_get_active_semester_name(mysqli $conn): string {
    $res = $conn->query("SELECT semester_name FROM semesters WHERE is_active = 1 ORDER BY semester_id DESC LIMIT 1");
    if ($res && $res->num_rows === 1) {
        return strval($res->fetch_assoc()['semester_name'] ?? '');
    }

    return '';
}

function book_system_json_encode(array $data): string {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : '{}';
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
        @session_start();
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

function book_system_get_dashboard_metrics(mysqli $conn, int $semesterId, ?int $adminId = null): array {
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
            WHERE r.semester_id = ?" . ($adminId !== null && $adminId > 0 ? " AND r.admin_id = ?" : '') . " AND ri.is_collected = 1",
        'pending_items' => "SELECT COUNT(*) AS value
            FROM request_items ri
            JOIN requests r ON r.request_id = ri.request_id
            WHERE r.semester_id = ?" . ($adminId !== null && $adminId > 0 ? " AND r.admin_id = ?" : '') . " AND (ri.is_collected = 0 OR ri.is_collected IS NULL)",
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

    $metrics['available_balance'] = floatval($metrics['cash_collected']) - floatval($metrics['lecturer_paid']) - floatval($metrics['refundable_cash']);

    $lowStockSql = "SELECT COUNT(*) AS value FROM books WHERE availability = 'available' AND stock_quantity > 0 AND stock_quantity <= 5";
    $res = $conn->query($lowStockSql);
    if ($res && $res->num_rows === 1) {
        $metrics['low_stock_books'] = intval($res->fetch_assoc()['value'] ?? 0);
    }

    return $metrics;
}

function book_system_get_semester_chart_data(mysqli $conn, int $limit = 6, ?int $adminId = null): array {
    $limit = max(1, min(12, $limit));
    $semesters = [];

    $stmt = $conn->prepare("SELECT semester_id, semester_name FROM semesters ORDER BY semester_id DESC LIMIT ?");
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
                    COALESCE(SUM(CASE WHEN ri.is_collected = 1 THEN 1 ELSE 0 END), 0) AS collected_items,
                    COALESCE(SUM(CASE WHEN ri.is_collected = 0 OR ri.is_collected IS NULL THEN 1 ELSE 0 END), 0) AS pending_items
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
}

function book_system_get_dashboard_alerts(mysqli $conn, int $semesterId, ?int $adminId = null): array {
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
}

function book_system_fetch_recent_activity(mysqli $conn, int $limit = 20, ?int $viewerAdminId = null, bool $isSuperAdmin = false, ?string $actorRole = null): array {
    $limit = max(1, min(100, $limit));

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
