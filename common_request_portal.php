<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
global $conn;
require_once 'db.php';
if (file_exists(__DIR__ . '/setup_tasks.php')) {
    require_once __DIR__ . '/setup_tasks.php';
    if (function_exists('book_system_run_setup_tasks')) {
        book_system_run_setup_tasks($conn);
    }
    if (function_exists('book_system_setup_ensure_column')) {
        book_system_setup_ensure_column($conn, 'admins', 'public_display_name', 'VARCHAR(50) NULL AFTER full_name');
        book_system_setup_ensure_column($conn, 'admins', 'profile_photo_path', 'VARCHAR(255) NULL AFTER public_display_name');
        book_system_setup_ensure_column($conn, 'admins', 'department_id', 'INT NULL AFTER program_name');
    }
}

function portal_log_lookup_debug(string $stage, array $context = []): void
{
    if (!portal_lookup_debug_enabled()) {
        return;
    }

    $payload = ['stage' => $stage];
    foreach ($context as $key => $value) {
        if (is_bool($value) || is_numeric($value) || $value === null || is_array($value)) {
            $payload[$key] = $value;
        } else {
            $payload[$key] = strval($value);
        }
    }

    error_log('[ClassBookHub][portal_lookup] ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function portal_lookup_debug_enabled(): bool
{
    static $enabled = null;
    if ($enabled !== null) {
        return $enabled;
    }

    $enabled = false;

    if (defined('BOOK_SYSTEM_PORTAL_LOOKUP_DEBUG') && BOOK_SYSTEM_PORTAL_LOOKUP_DEBUG) {
        $enabled = true;
    }

    if (!$enabled && isset($_GET['portal_debug']) && strval($_GET['portal_debug']) === '1') {
        $enabled = true;
    }

    return $enabled;
}

function portal_avatar_initials(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'RP';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'RP';
}

function portal_normalize_index(string $value): string
{
    if (function_exists('book_system_normalize_index_number')) {
        return book_system_normalize_index_number($value);
    }

    return strtoupper(trim($value));
}

function portal_normalized_index_sql(string $column): string
{
    if (function_exists('book_system_normalized_index_sql')) {
        return book_system_normalized_index_sql($column);
    }

    return "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM($column)), '/', ''), ' ', ''), '-', ''), '.', '')";
}

function portal_build_rep_payload(array $row): array
{
    $fullName = trim(strval($row['full_name'] ?? ''));
    $displayName = trim(strval($row['public_display_name'] ?? ''));
    if ($displayName === '') {
        $displayName = $fullName;
    }

    $departmentName = trim(strval($row['department_name'] ?? ''));
    $className = trim(strval($row['class_name'] ?? ''));
    $academicLevel = trim(strval($row['academic_level'] ?? ''));

    return [
        'admin_id' => intval($row['admin_id'] ?? 0),
        'department_id' => intval($row['department_id'] ?? 0),
        'department_name' => $departmentName,
        'department_code' => trim(strval($row['department_code'] ?? '')),
        'class_name' => $className,
        'academic_level' => $academicLevel,
        'full_name' => $fullName,
        'display_name' => $displayName,
        'profile_photo_path' => trim(strval($row['profile_photo_path'] ?? '')),
        'initials' => portal_avatar_initials($displayName !== '' ? $displayName : $fullName),
        'status' => 'Available',
        'request_url' => 'index.php?rep_id=' . urlencode(strval(intval($row['admin_id'] ?? 0))),
        'class_label' => $className !== '' ? $className : 'Class route',
        'search_label' => trim($className . ' ' . $departmentName . ' ' . $academicLevel . ' ' . $displayName),
    ];
}

function portal_lookup_cache_key(int $semesterId, string $normalizedIndex, int $version): string
{
    return 'portal_lookup_result_v2_' . $version . '_s' . $semesterId . '_i' . $normalizedIndex;
}

function portal_books_cache_key(int $semesterId, int $adminId, int $version): string
{
    return 'portal_available_books_v1_' . $version . '_s' . $semesterId . '_a' . $adminId;
}

function portal_fetch_public_ads(mysqli $conn): array
{
    if (function_exists('cache_get')) {
        $cachedAds = cache_get('public_portal_ads', 120);
        if (is_array($cachedAds)) {
            return $cachedAds;
        }
    }

    $rows = [];
    $adsResult = @$conn->query("SELECT ad_id, title, description, owner_name, owner_contact, link_url, badge_text, image_path, view_count, click_count
        FROM portal_ads
        WHERE is_active = 1
        ORDER BY display_order ASC, created_at DESC
        LIMIT 12");
    if ($adsResult) {
        while ($adRow = $adsResult->fetch_assoc()) {
            $rows[] = $adRow;
        }
    }

    if (function_exists('cache_set')) {
        cache_set('public_portal_ads', $rows, 120);
    }

    return $rows;
}

function portal_mark_ads_viewed(mysqli $conn, array $portalAds): void
{
    if (empty($portalAds)) {
        return;
    }

    $todayKey = date('Y-m-d');
    $seenAds = isset($_SESSION['portal_ad_views']) && is_array($_SESSION['portal_ad_views']) ? $_SESSION['portal_ad_views'] : [];
    $adsToMarkViewed = [];
    foreach ($portalAds as $portalAd) {
        $adId = intval($portalAd['ad_id'] ?? 0);
        if ($adId <= 0) {
            continue;
        }
        if (($seenAds[$adId] ?? '') !== $todayKey) {
            $adsToMarkViewed[] = $adId;
            $seenAds[$adId] = $todayKey;
        }
    }

    $_SESSION['portal_ad_views'] = $seenAds;
    if (empty($adsToMarkViewed)) {
        return;
    }

    $adsToMarkViewed = array_values(array_unique(array_map('intval', $adsToMarkViewed)));
    $idList = implode(',', $adsToMarkViewed);
    if ($idList !== '') {
        $conn->query("UPDATE portal_ads SET view_count = view_count + 1, last_viewed_at = NOW() WHERE ad_id IN ($idList)");
    }
}

function portal_render_ads_markup(array $portalAds, bool $showReturnBar = false, string $returnHref = 'rep_dashboard.php'): string
{
    ob_start();
    if ($showReturnBar): ?>
        <div class="offers-return-bar">
            <a href="<?php echo htmlspecialchars($returnHref); ?>" class="offers-return-button">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>Return to Dashboard</span>
            </a>
        </div>
    <?php endif;

    if (!empty($portalAds)): ?>
        <div class="offers-grid">
            <?php foreach ($portalAds as $portalAd): ?>
                <?php
                $adTitle = trim(strval($portalAd['title'] ?? ''));
                $adDescription = trim(strval($portalAd['description'] ?? ''));
                $adBadge = trim(strval($portalAd['badge_text'] ?? ''));
                $adOwner = trim(strval($portalAd['owner_name'] ?? ''));
                $adImage = trim(strval($portalAd['image_path'] ?? ''));
                $adLink = 'portal_ad_redirect.php?ad_id=' . intval($portalAd['ad_id'] ?? 0);
                ?>
                <article class="offer-ad">
                    <a href="<?php echo htmlspecialchars($adLink); ?>" target="_blank" rel="noopener noreferrer">
                        <?php if ($adImage !== ''): ?>
                            <img src="<?php echo htmlspecialchars($adImage); ?>" alt="<?php echo htmlspecialchars($adTitle !== '' ? $adTitle : 'Advertisement'); ?>" class="offer-image" loading="lazy">
                        <?php endif; ?>
                        <div class="offer-body">
                            <?php if ($adBadge !== ''): ?><span class="offer-badge"><?php echo htmlspecialchars($adBadge); ?></span><?php endif; ?>
                            <h3><?php echo htmlspecialchars($adTitle !== '' ? $adTitle : 'Featured Offer'); ?></h3>
                            <?php if ($adDescription !== ''): ?><p><?php echo htmlspecialchars($adDescription); ?></p><?php endif; ?>
                            <div class="offer-footer">
                                <span><?php echo htmlspecialchars($adOwner !== '' ? $adOwner : 'View offer'); ?></span>
                                <span>&rarr;</span>
                            </div>
                        </div>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="offers-marquee" aria-label="Advertising contact notice">
            <div class="offers-marquee-track">
                <span><strong>Want your advert displayed here?</strong> Reach out to the admin on 054-909-0433.</span>
                <span><strong>Want your advert displayed here?</strong> Reach out to the admin on 054-909-0433.</span>
            </div>
        </div>
    <?php else: ?>
        <div class="offers-empty">
            <div class="empty-icon">&#128227;</div>
            <strong>Advertise here</strong>
            <p>If you have a product or service to promote, this space is available for your advert. Reach out to the admin on 054-909-0433.</p>
        </div>
    <?php endif;

    return trim(ob_get_clean());
}

function portal_fetch_available_books_for_rep(mysqli $conn, int $adminId, int $semesterId): array
{
    if ($adminId <= 0 || $semesterId <= 0) {
        return [];
    }

    $cacheVersion = function_exists('book_system_portal_lookup_cache_version')
        ? book_system_portal_lookup_cache_version($conn)
        : 1;
    $cacheKey = portal_books_cache_key($semesterId, $adminId, $cacheVersion);

    if (function_exists('cache_get')) {
        $cachedBooks = cache_get($cacheKey, 180);
        if (is_array($cachedBooks)) {
            return $cachedBooks;
        }
    }

    $books = [];
    $stmt = $conn->prepare("SELECT
            book_id,
            book_title,
            course_code,
            price,
            availability
        FROM books
        WHERE admin_id = ?
          AND semester_id = ?
          AND availability = 'available'
        ORDER BY book_title ASC, book_id ASC");
    if ($stmt) {
        $stmt->bind_param('ii', $adminId, $semesterId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $books[] = [
                    'book_id' => intval($row['book_id'] ?? 0),
                    'book_title' => trim(strval($row['book_title'] ?? '')),
                    'course_code' => trim(strval($row['course_code'] ?? '')),
                    'lecturer_name' => '',
                    'price' => round(floatval($row['price'] ?? 0), 2),
                    'availability_label' => 'Available',
                ];
            }
        }
        $stmt->close();
    }

    if (function_exists('cache_set')) {
        cache_set($cacheKey, $books, 180);
    }

    return $books;
}

function portal_fetch_owned_book_ids_for_student(mysqli $conn, string $indexNumber, int $adminId, int $semesterId): array
{
    if ($adminId <= 0 || $semesterId <= 0) {
        return [];
    }

    $normalizedIndex = portal_normalize_index($indexNumber);
    if (!preg_match('/^[A-Z0-9]{10}$/', $normalizedIndex)) {
        return [];
    }

    $studentIndexExpr = portal_normalized_index_sql('s.index_number');
    $stmt = $conn->prepare("
        SELECT DISTINCT ri.book_id
        FROM request_items ri
        JOIN requests r ON ri.request_id = r.request_id
        JOIN students s ON r.student_id = s.student_id
        WHERE $studentIndexExpr = ?
          AND r.semester_id = ?
          AND r.admin_id = ?
          AND COALESCE(ri.is_cancelled, 0) = 0
    ");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('sii', $normalizedIndex, $semesterId, $adminId);
    $stmt->execute();
    $result = $stmt->get_result();

    $ownedBookIds = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $bookId = intval($row['book_id'] ?? 0);
            if ($bookId > 0) {
                $ownedBookIds[$bookId] = true;
            }
        }
    }
    $stmt->close();

    return $ownedBookIds;
}

function portal_lookup_available_books_by_index(mysqli $conn, string $indexNumber): array
{
    $repLookup = portal_lookup_rep_by_index($conn, $indexNumber);
    if (empty($repLookup['success']) || !is_array($repLookup['rep'] ?? null)) {
        return [
            'success' => false,
            'message' => strval($repLookup['message'] ?? "We couldn't find your class rep. Please check your index number or contact your class rep."),
        ];
    }

    $activeSemesterId = function_exists('book_system_get_active_semester_id')
        ? book_system_get_active_semester_id($conn)
        : 0;
    if ($activeSemesterId <= 0) {
        return [
            'success' => false,
            'message' => 'Requests are not available right now. Please try again later.',
        ];
    }

    $rep = $repLookup['rep'];
    $books = portal_fetch_available_books_for_rep($conn, intval($rep['admin_id'] ?? 0), $activeSemesterId);
    $ownedBookIds = portal_fetch_owned_book_ids_for_student($conn, $indexNumber, intval($rep['admin_id'] ?? 0), $activeSemesterId);
    $hiddenOwnedCount = 0;
    if (!empty($ownedBookIds) && !empty($books)) {
        $originalCount = count($books);
        $books = array_values(array_filter($books, static function (array $bookRow) use ($ownedBookIds): bool {
            $bookId = intval($bookRow['book_id'] ?? 0);
            return $bookId > 0 && !isset($ownedBookIds[$bookId]);
        }));
        $hiddenOwnedCount = max(0, $originalCount - count($books));
    }

    portal_log_lookup_debug('available_books_lookup', [
        'normalized_index' => portal_normalize_index($indexNumber),
        'active_semester_id' => $activeSemesterId,
        'admin_id' => intval($rep['admin_id'] ?? 0),
        'book_count' => count($books),
        'hidden_owned_count' => $hiddenOwnedCount,
    ]);

    return [
        'success' => true,
        'rep' => $rep,
        'books' => $books,
        'hidden_owned_count' => $hiddenOwnedCount,
        'all_books_already_requested' => ($hiddenOwnedCount > 0 && empty($books)),
    ];
}

function portal_class_students_has_normalized_column(mysqli $conn): bool
{
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }

    $dbRes = $conn->query("SELECT DATABASE() AS db_name");
    $dbName = ($dbRes && $dbRes->num_rows === 1) ? strval($dbRes->fetch_assoc()['db_name'] ?? '') : '';
    if ($dbName === '') {
        $hasColumn = false;
        return $hasColumn;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = 'class_students'
          AND COLUMN_NAME = 'normalized_index_number'");
    if (!$stmt) {
        $hasColumn = false;
        return $hasColumn;
    }

    $stmt->bind_param('s', $dbName);
    $stmt->execute();
    $res = $stmt->get_result();
    $hasColumn = ($res && $res->num_rows === 1 && intval($res->fetch_assoc()['c'] ?? 0) > 0);
    $stmt->close();

    return $hasColumn;
}

function portal_class_students_normalized_expr(mysqli $conn, string $column): string
{
    $normalizedSql = portal_normalized_index_sql($column);
    if (!portal_class_students_has_normalized_column($conn)) {
        return $normalizedSql;
    }

    return "COALESCE(cs.normalized_index_number, " . $normalizedSql . ")";
}

function portal_lookup_rep_by_index(mysqli $conn, string $indexNumber): array
{
    $normalized = portal_normalize_index($indexNumber);
    if ($normalized === '') {
        return [
            'success' => false,
            'reason' => 'missing_index',
            'message' => "We couldn't verify that index number. Please check it and try again.",
        ];
    }

    $activeSemesterId = function_exists('book_system_get_active_semester_id')
        ? book_system_get_active_semester_id($conn)
        : 0;
    if ($activeSemesterId <= 0) {
        portal_log_lookup_debug('no_active_semester', [
            'entered_index' => $indexNumber,
            'normalized_index' => $normalized,
        ]);
        return [
            'success' => false,
            'reason' => 'no_active_semester',
            'message' => "Requests are not available right now. Please try again later.",
        ];
    }

    $cacheVersion = function_exists('book_system_portal_lookup_cache_version')
        ? book_system_portal_lookup_cache_version($conn)
        : 1;
    $lookupCacheKey = portal_lookup_cache_key($activeSemesterId, $normalized, $cacheVersion);
    if (function_exists('cache_get')) {
        $cachedResult = cache_get($lookupCacheKey, 600);
        if (is_array($cachedResult) && array_key_exists('success', $cachedResult)) {
            portal_log_lookup_debug('lookup_cache_hit', [
                'entered_index' => $indexNumber,
                'normalized_index' => $normalized,
                'active_semester_id' => $activeSemesterId,
                'cached_success' => !empty($cachedResult['success']),
                'cached_reason' => strval($cachedResult['reason'] ?? ''),
            ]);
            return $cachedResult;
        }
    }

    portal_log_lookup_debug('lookup_started', [
        'entered_index' => $indexNumber,
        'normalized_index' => $normalized,
        'active_semester_id' => $activeSemesterId,
    ]);

    $beforeSemesterMatches = 0;
    $afterSemesterMatches = 0;
    $nullSemesterMatches = 0;
    $filteredRepMatches = 0;
    $repStatusRows = [];

    $normalizedIndexExpr = portal_class_students_normalized_expr($conn, 'cs.index_number');
    if (portal_lookup_debug_enabled()) {
        $countSql = "SELECT
                COUNT(*) AS before_semester_matches,
                SUM(CASE WHEN cs.semester_id = ? THEN 1 ELSE 0 END) AS after_semester_matches,
                SUM(CASE WHEN cs.semester_id IS NULL THEN 1 ELSE 0 END) AS null_semester_matches
            FROM class_students cs
            WHERE " . $normalizedIndexExpr . " = ?";
        $countStmt = $conn->prepare($countSql);
        if ($countStmt) {
            $countStmt->bind_param('is', $activeSemesterId, $normalized);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            if ($countResult && $countResult->num_rows === 1) {
                $countRow = $countResult->fetch_assoc();
                $beforeSemesterMatches = intval($countRow['before_semester_matches'] ?? 0);
                $afterSemesterMatches = intval($countRow['after_semester_matches'] ?? 0);
                $nullSemesterMatches = intval($countRow['null_semester_matches'] ?? 0);
            }
            $countStmt->close();
        }

        $statusSql = "SELECT
                cs.admin_id,
                cs.semester_id,
                a.is_active,
                a.role,
                a.approved_at,
                COALESCE(a.show_on_public_portal, 1) AS show_on_public_portal,
                COALESCE(TRIM(a.class_name), '') AS class_name,
                COALESCE(d.is_active, 0) AS department_is_active,
                COALESCE(TRIM(d.department_name), '') AS department_name
            FROM class_students cs
            INNER JOIN admins a ON a.admin_id = cs.admin_id
            LEFT JOIN departments d ON d.department_id = a.department_id
            WHERE " . $normalizedIndexExpr . " = ?
            ORDER BY cs.id ASC
            LIMIT 5";
        $statusStmt = $conn->prepare($statusSql);
        if ($statusStmt) {
            $statusStmt->bind_param('s', $normalized);
            $statusStmt->execute();
            $statusResult = $statusStmt->get_result();
            if ($statusResult) {
                while ($statusRow = $statusResult->fetch_assoc()) {
                    $repStatusRows[] = [
                        'admin_id' => intval($statusRow['admin_id'] ?? 0),
                        'semester_id' => isset($statusRow['semester_id']) ? intval($statusRow['semester_id']) : null,
                        'role' => strval($statusRow['role'] ?? ''),
                        'is_active' => intval($statusRow['is_active'] ?? 0),
                        'is_approved' => !empty($statusRow['approved_at']) || strval($statusRow['role'] ?? '') === 'super_admin',
                        'show_on_public_portal' => intval($statusRow['show_on_public_portal'] ?? 0),
                        'class_name_present' => trim(strval($statusRow['class_name'] ?? '')) !== '',
                        'department_is_active' => intval($statusRow['department_is_active'] ?? 0),
                        'department_name_present' => trim(strval($statusRow['department_name'] ?? '')) !== '',
                    ];
                }
            }
            $statusStmt->close();
        }
    }

    $stmt = $conn->prepare("SELECT
            cs.index_number,
            cs.student_name,
            a.admin_id,
            a.full_name,
            a.public_display_name,
            a.profile_photo_path,
            a.class_name,
            a.academic_level,
            a.department_id,
            d.department_name,
            d.department_code
        FROM class_students cs
        INNER JOIN admins a ON a.admin_id = cs.admin_id
        LEFT JOIN departments d ON d.department_id = a.department_id
        WHERE " . $normalizedIndexExpr . " = ?
          AND cs.semester_id = ?
          AND a.role IN ('rep', 'super_admin')
          AND a.is_active = 1
          AND (a.role = 'super_admin' OR a.approved_at IS NOT NULL)
        ORDER BY cs.id ASC
        LIMIT 1");
    if (!$stmt) {
        portal_log_lookup_debug('lookup_prepare_failed', [
            'normalized_index' => $normalized,
            'active_semester_id' => $activeSemesterId,
            'db_error' => $conn->error,
        ]);
        return [
            'success' => false,
            'reason' => 'lookup_unavailable',
            'message' => "We couldn't check your class rep right now. Please try again shortly.",
        ];
    }

    $stmt->bind_param('si', $normalized, $activeSemesterId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result && $result->num_rows === 1) ? $result->fetch_assoc() : null;
    $filteredRepMatches = ($result ? intval($result->num_rows ?? 0) : 0);
    $stmt->close();

    if (!is_array($row)) {
        portal_log_lookup_debug('lookup_not_found', [
            'entered_index' => $indexNumber,
            'normalized_index' => $normalized,
            'active_semester_id' => $activeSemesterId,
            'before_semester_matches' => $beforeSemesterMatches,
            'after_semester_matches' => $afterSemesterMatches,
            'null_semester_matches' => $nullSemesterMatches,
            'filtered_rep_matches' => $filteredRepMatches,
            'rep_status_rows' => $repStatusRows,
        ]);

        $message = "We couldn't find your class rep. Please check your index number or contact your class rep.";
        $reason = 'rep_not_found';
        if ($beforeSemesterMatches > 0 && $afterSemesterMatches === 0) {
            $message = 'Your class rep has not uploaded the class list for the active semester yet.';
            $reason = ($nullSemesterMatches > 0) ? 'null_semester_class_list' : 'old_semester_class_list';
        } elseif ($afterSemesterMatches > 0 && $filteredRepMatches === 0) {
            $message = 'Your class rep is not available on the portal yet. Please contact your class rep.';
            $reason = 'rep_not_publicly_available';
        }

        $response = [
            'success' => false,
            'reason' => $reason,
            'message' => $message,
        ];
        if (function_exists('cache_set')) {
            cache_set($lookupCacheKey, $response, 120);
        }

        return $response;
    }

    $payload = portal_build_rep_payload($row);
    $payload['matched_index_number'] = trim(strval($row['index_number'] ?? $normalized));
    $payload['student_name'] = trim(strval($row['student_name'] ?? ''));
    portal_log_lookup_debug('lookup_found', [
        'entered_index' => $indexNumber,
        'normalized_index' => $normalized,
        'active_semester_id' => $activeSemesterId,
        'before_semester_matches' => $beforeSemesterMatches,
        'after_semester_matches' => $afterSemesterMatches,
        'filtered_rep_matches' => 1,
        'admin_id' => intval($payload['admin_id'] ?? 0),
    ]);

    $response = [
        'success' => true,
        'reason' => 'matched',
        'rep' => $payload,
    ];
    if (function_exists('cache_set')) {
        cache_set($lookupCacheKey, $response, 600);
    }

    return $response;
}

if (($_GET['lookup'] ?? '') === 'index') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $indexNumber = strval($_GET['index_number'] ?? '');
    $result = portal_lookup_rep_by_index($conn, $indexNumber);
    if (!empty($result['success'])) {
        echo json_encode([
            'success' => true,
            'match_type' => 'index',
            'rep' => $result['rep'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => strval($result['message'] ?? "We couldn't find your class rep. Please check your index number or contact your class rep."),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['lookup'] ?? '') === 'books') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $indexNumber = strval($_GET['index_number'] ?? '');
    $result = portal_lookup_available_books_by_index($conn, $indexNumber);
    if (!empty($result['success'])) {
        echo json_encode([
            'success' => true,
            'rep' => $result['rep'],
            'books' => $result['books'],
            'hidden_owned_count' => intval($result['hidden_owned_count'] ?? 0),
            'all_books_already_requested' => !empty($result['all_books_already_requested']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            'success' => false,
            'message' => strval($result['message'] ?? "We couldn't find your class rep. Please check your index number or contact your class rep."),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_GET['lookup'] ?? '') === 'ads') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    $portalAds = portal_fetch_public_ads($conn);
    portal_mark_ads_viewed($conn, $portalAds);

    echo json_encode([
        'success' => true,
        'html' => portal_render_ads_markup($portalAds, false, 'rep_dashboard.php'),
        'count' => count($portalAds),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$portalCacheVersion = function_exists('book_system_portal_lookup_cache_version')
    ? book_system_portal_lookup_cache_version($conn)
    : 1;

$portal_rep_rows = function_exists('cache_get')
    ? cache_get('public_portal_reps_mobile_v2_v' . $portalCacheVersion, 180, function () use ($conn) {
        $rows = [];
        $stmt = $conn->prepare("SELECT
                a.admin_id,
                a.full_name,
                a.public_display_name,
                a.profile_photo_path,
                a.class_name,
                a.academic_level,
                a.department_id,
                d.department_name,
                d.department_code
            FROM admins a
            LEFT JOIN departments d ON d.department_id = a.department_id
            WHERE a.role IN ('rep', 'super_admin')
              AND a.is_active = 1
              AND (a.role = 'super_admin' OR a.approved_at IS NOT NULL)
            ORDER BY a.class_name ASC, COALESCE(d.department_name, '') ASC");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rows[] = $row;
                }
            }
            $stmt->close();
        }
        return $rows;
    })
    : [];

$classRoutes = [];
$classRoutesByAdminId = [];
foreach ($portal_rep_rows as $row) {
    $payload = portal_build_rep_payload($row);
    if ($payload['admin_id'] <= 0) {
        continue;
    }
    $payload['route_label'] = $payload['class_name'] !== '' ? $payload['class_name'] : 'Class route';
    if ($payload['department_name'] !== '') {
        $payload['route_label'] .= ' • ' . $payload['department_name'];
    }
    if ($payload['academic_level'] !== '') {
        $payload['route_label'] .= ' • Level ' . $payload['academic_level'];
    }
    $classRoutes[] = $payload;
    $classRoutesByAdminId[intval($payload['admin_id'])] = $payload;
}

$activeSemesterIdForMap = function_exists('book_system_get_active_semester_id')
    ? intval(book_system_get_active_semester_id($conn))
    : 0;
$portalHasNormalizedIndexColumn = portal_class_students_has_normalized_column($conn);

$preloadedIndexMap = function_exists('cache_get')
    ? cache_get('public_portal_index_map_v2_v' . $portalCacheVersion . '_' . $activeSemesterIdForMap, 180, function () use ($conn, $classRoutesByAdminId, $portalHasNormalizedIndexColumn) {
        $map = [];
        if (empty($classRoutesByAdminId)) {
            return $map;
        }

        $activeSemesterId = function_exists('book_system_get_active_semester_id')
            ? book_system_get_active_semester_id($conn)
            : 0;
        if ($activeSemesterId <= 0) {
            return $map;
        }

        $normalizedSelect = $portalHasNormalizedIndexColumn
            ? 'cs.normalized_index_number'
            : "'' AS normalized_index_number";

        $stmt = $conn->prepare("SELECT
                cs.index_number,
                " . $normalizedSelect . ",
                cs.student_name,
                cs.admin_id
            FROM class_students cs
            INNER JOIN admins a ON a.admin_id = cs.admin_id
            LEFT JOIN departments d ON d.department_id = a.department_id
            WHERE a.role IN ('rep', 'super_admin')
              AND cs.semester_id = ?
              AND a.is_active = 1
              AND (a.role = 'super_admin' OR a.approved_at IS NOT NULL)
            ORDER BY cs.id ASC");
        if (!$stmt) {
            return $map;
        }

        $stmt->bind_param('i', $activeSemesterId);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $adminId = intval($row['admin_id'] ?? 0);
                if ($adminId <= 0 || !isset($classRoutesByAdminId[$adminId])) {
                    continue;
                }

                $normalizedIndex = trim(strval($row['normalized_index_number'] ?? ''));
                if ($normalizedIndex === '') {
                    $normalizedIndex = portal_normalize_index(strval($row['index_number'] ?? ''));
                }
                if ($normalizedIndex === '' || isset($map[$normalizedIndex])) {
                    continue;
                }

                $map[$normalizedIndex] = [
                    'admin_id' => $adminId,
                    'student_name' => trim(strval($row['student_name'] ?? '')),
                    'matched_index_number' => trim(strval($row['index_number'] ?? $normalizedIndex)),
                ];
            }
        }
        $stmt->close();

        return $map;
    })
    : [];

$portalView = strtolower(trim(strval($_GET['view'] ?? '')));
$portalSource = strtolower(trim(strval($_GET['source'] ?? '')));
$isRepOffersView = in_array($portalView, ['offers', 'ads'], true) && $portalSource === 'rep';

$portal_ads = [];
$portal_ads_loaded = false;
if ($isRepOffersView) {
    $portal_ads = portal_fetch_public_ads($conn);
    portal_mark_ads_viewed($conn, $portal_ads);
    $portal_ads_loaded = true;
}

$heroImageRelativePath = 'assets/images/portal-hero.jpg';
$heroImageAbsolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $heroImageRelativePath);
$heroImageVersion = is_file($heroImageAbsolutePath) ? intval(@filemtime($heroImageAbsolutePath)) : 0;
$portalScriptBasePath = str_replace('\\', '/', dirname(strval($_SERVER['SCRIPT_NAME'] ?? '')));
$portalScriptBasePath = rtrim($portalScriptBasePath, '/.');
$heroImagePathWithVersion = $heroImageRelativePath . ($heroImageVersion > 0 ? '?v=' . $heroImageVersion : '');
$heroImageUrl = ($portalScriptBasePath !== '' ? $portalScriptBasePath . '/' : '') . $heroImagePathWithVersion;
$heroImageFallbackUrl = $heroImagePathWithVersion;
$repOffersReturnHref = 'rep_dashboard.php';
$portalActiveSemesterName = isset($ACTIVE_SEMESTER_NAME)
    ? trim(strval($ACTIVE_SEMESTER_NAME))
    : (isset($ACTIVE_SEMESTER_LABEL) ? trim(strval($ACTIVE_SEMESTER_LABEL)) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="ClassBookHub">
    <link rel="icon" type="image/png" sizes="1254x1254" href="assets/images/logo/classbookhub-icon.png">
    <link rel="apple-touch-icon" href="assets/images/logo/classbookhub-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <title>ClassBookHub | Common Request Portal</title>
    <link rel="preload" as="image" href="<?php echo htmlspecialchars($heroImageUrl); ?>">
    <style>
        :root {
            --bg: #f3f7fd;
            --surface: rgba(255, 255, 255, 0.98);
            --surface-soft: #fbfdff;
            --line: rgba(148, 163, 184, 0.20);
            --text: #10203a;
            --muted: #66768f;
            --primary-a: #3f8cff;
            --primary-b: #1556df;
            --success: #0f8a69;
            --danger: #d1495b;
            --shadow-xl: 0 30px 70px rgba(15, 23, 42, 0.12);
            --shadow-lg: 0 18px 42px rgba(15, 23, 42, 0.10);
            --radius-xl: 30px;
            --radius-lg: 22px;
            --radius-md: 16px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(63, 140, 255, 0.12), transparent 26%),
                linear-gradient(180deg, #fafdff 0%, var(--bg) 52%, #ebf2fb 100%);
            overflow-x: hidden;
        }
        .portal-shell {
            width: min(960px, calc(100vw - 20px));
            margin: 0 auto;
            padding: 10px 0 34px;
        }
        .hero {
            position: relative;
            min-height: clamp(320px, 50vw, 410px);
            border-radius: 34px;
            overflow: hidden;
            background:
                linear-gradient(180deg, rgba(6, 18, 41, 0.18) 0%, rgba(6, 18, 41, 0.48) 46%, rgba(6, 18, 41, 0.80) 100%),
                url('<?php echo htmlspecialchars($heroImageUrl); ?>') center center / cover no-repeat;
            box-shadow: var(--shadow-xl);
            padding: clamp(14px, 2.2vw, 22px) clamp(14px, 2.2vw, 22px) clamp(74px, 9vw, 96px);
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            align-items: center;
            text-align: center;
        }
        .staff-login-link {
            position: absolute;
            top: clamp(16px, 2.4vw, 24px);
            right: clamp(16px, 2.4vw, 22px);
            z-index: 2;
            color: rgba(255,255,255,0.96);
            font-size: 15px;
            font-weight: 700;
            letter-spacing: -0.01em;
            text-decoration: none;
            text-shadow: 0 10px 22px rgba(0,0,0,0.22);
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .staff-login-link:hover {
            opacity: 0.86;
            transform: translateY(-1px);
        }
        .brand-mark {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-top: 6px;
            padding: 11px 16px;
            border-radius: 999px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.18);
            backdrop-filter: blur(12px);
            color: #fff;
            box-shadow: 0 16px 34px rgba(0,0,0,0.18);
        }
        .brand-mark .logo-tile {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            background: linear-gradient(135deg, #4ea2ff 0%, #1b59df 100%);
            display: grid;
            place-items: center;
            font-size: 19px;
            font-weight: 900;
            letter-spacing: 0.04em;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18);
        }
        .brand-mark span {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0.01em;
        }
        .hero-copy {
            margin-top: clamp(18px, 4vw, 32px);
            width: min(680px, 100%);
            color: #fff;
        }
        .hero-copy h1 {
            font-size: clamp(32px, 6vw, 64px);
            line-height: 1.02;
            margin-bottom: 12px;
            letter-spacing: -0.04em;
            text-shadow: 0 12px 24px rgba(0,0,0,0.16);
        }
        .hero-copy p {
            font-size: clamp(16px, 3.4vw, 24px);
            line-height: 1.45;
            color: rgba(255,255,255,0.92);
            text-shadow: 0 10px 22px rgba(0,0,0,0.16);
        }
        .content-stack {
            width: min(100%, calc(100vw - 20px));
            margin: -52px auto 0;
            position: relative;
            z-index: 2;
            display: grid;
            gap: 18px;
        }
        .floating-card,
        .offers-card,
        .offers-panel {
            background: var(--surface);
            border: 1px solid rgba(255,255,255,0.72);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            backdrop-filter: blur(12px);
        }
        .floating-card {
            padding: 22px 18px 20px;
        }
        .search-block {
            display: grid;
            gap: 16px;
        }
        .action-switcher {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 6px;
        }
        .action-switch {
            border: 1px solid #dbe6f4;
            background: #f8fbff;
            color: #294268;
            border-radius: 18px;
            padding: 14px 14px;
            font-size: 15px;
            font-weight: 800;
            text-align: left;
            cursor: pointer;
            display: grid;
            gap: 6px;
            transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .action-switch span {
            color: #6b7d98;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.5;
        }
        .action-switch.is-active {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-color: rgba(37, 99, 235, 0.24);
            color: #0f2345;
            box-shadow: 0 14px 30px rgba(21, 86, 223, 0.10);
            transform: translateY(-1px);
        }
        .portal-panel {
            display: none;
        }
        .portal-panel.is-active {
            display: grid;
            gap: 16px;
        }
        .search-section-title {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: 0.01em;
            color: #0f2345;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .search-section-title .section-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(63, 140, 255, 0.14) 0%, rgba(21, 86, 223, 0.10) 100%);
            color: var(--primary-b);
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .field-shell {
            position: relative;
        }
        .field-input {
            width: 100%;
            border: 1px solid #dbe6f4;
            background: #fff;
            color: var(--text);
            border-radius: 18px;
            padding: 18px 18px;
            font-size: 17px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.7);
        }
        .field-input:focus {
            outline: none;
            border-color: #80aef9;
            box-shadow: 0 0 0 5px rgba(63, 140, 255, 0.14);
            transform: translateY(-1px);
        }
        .field-hint {
            margin-top: 8px;
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
        }
        .find-button {
            width: 100%;
            border: none;
            border-radius: 20px;
            padding: 18px 20px;
            font-size: 18px;
            font-weight: 800;
            color: #fff;
            background: linear-gradient(135deg, var(--primary-a) 0%, var(--primary-b) 100%);
            box-shadow: 0 18px 36px rgba(21, 86, 223, 0.28);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
        }
        .find-button .button-spinner {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.36);
            border-top-color: #ffffff;
            display: none;
            animation: portal-spin 0.8s linear infinite;
            flex-shrink: 0;
        }
        .find-button.is-loading {
            transform: translateY(-1px);
            box-shadow: 0 20px 40px rgba(21, 86, 223, 0.24);
        }
        .find-button.is-loading .button-spinner {
            display: inline-block;
        }
        .find-button.is-loading .button-icon {
            display: none;
        }
        .find-button:hover { transform: translateY(-1px); box-shadow: 0 20px 40px rgba(21, 86, 223, 0.32); }
        .find-button:active { transform: translateY(0); }
        .find-button:disabled { opacity: 0.72; cursor: progress; }
        @keyframes portal-spin {
            to { transform: rotate(360deg); }
        }
        .result-shell {
            margin-top: 18px;
        }
        .placeholder-card,
        .result-card,
        .error-card {
            border-radius: 24px;
            border: 1px solid #e1e8f5;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            padding: 22px 18px;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
        }
        .placeholder-card {
            text-align: center;
            display: grid;
            gap: 12px;
            justify-items: center;
            color: #5f718f;
        }
        .placeholder-icon,
        .error-icon {
            width: 72px;
            height: 72px;
            border-radius: 24px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, rgba(63, 140, 255, 0.12) 0%, rgba(17, 147, 111, 0.08) 100%);
            color: var(--primary-b);
            font-size: 32px;
        }
        .placeholder-card strong,
        .error-card strong,
        .result-card h3 {
            font-size: 24px;
            color: #10203a;
            line-height: 1.2;
        }
        .placeholder-card p,
        .error-card p {
            font-size: 15px;
            line-height: 1.7;
            max-width: 520px;
        }
        .error-card {
            display: grid;
            gap: 12px;
            justify-items: center;
            text-align: center;
            color: #7f2a37;
            border-color: rgba(209, 73, 91, 0.18);
            background: linear-gradient(180deg, #fff9fa 0%, #fff4f6 100%);
        }
        .error-icon {
            background: linear-gradient(135deg, rgba(209, 73, 91, 0.10) 0%, rgba(255, 213, 219, 0.18) 100%);
            color: var(--danger);
        }
        .result-card {
            display: grid;
            gap: 18px;
        }
        .result-card.is-highlighted {
            animation: portal-result-glow 1.2s ease;
        }
        .result-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            padding: 8px 12px;
            border-radius: 999px;
            background: #eef5ff;
            color: var(--primary-b);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .rep-summary {
            display: grid;
            justify-items: center;
            text-align: center;
            gap: 12px;
        }
        .rep-avatar {
            width: 90px;
            height: 90px;
            border-radius: 28px;
            overflow: hidden;
            background: linear-gradient(135deg, #eff5ff 0%, #dbeafe 100%);
            color: var(--primary-b);
            display: grid;
            place-items: center;
            font-size: 28px;
            font-weight: 900;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.8);
        }
        .rep-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .rep-summary h3 { margin-bottom: 4px; }
        .rep-summary p {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
            max-width: 420px;
        }
        .rep-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .rep-detail {
            border-radius: 18px;
            background: #f8fbff;
            border: 1px solid #e5edf9;
            padding: 14px 15px;
            display: grid;
            gap: 4px;
        }
        .rep-detail span {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7d98;
        }
        .rep-detail strong {
            font-size: 15px;
            color: #10203a;
            line-height: 1.45;
        }
        .summary-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .status-overview-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);
            gap: 12px;
        }
        .status-balance-card {
            border-radius: 20px;
            background: linear-gradient(135deg, #f8fbff 0%, #eef5ff 100%);
            border: 1px solid #dfe9f8;
            padding: 18px 16px;
            display: grid;
            gap: 8px;
        }
        .status-balance-card span {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7d98;
        }
        .status-balance-card strong {
            font-size: 27px;
            line-height: 1.05;
            color: #10203a;
        }
        .status-balance-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 12px;
            font-size: 13px;
            color: #54657f;
        }
        .status-balance-meta b {
            color: #10203a;
        }
        .status-primary-stat {
            border-radius: 20px;
            background: #ffffff;
            border: 1px solid #e5edf9;
            padding: 16px 15px;
            display: grid;
            align-content: space-between;
            gap: 10px;
            min-height: 100%;
        }
        .status-primary-stat span {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7d98;
        }
        .status-primary-stat strong {
            font-size: 20px;
            color: #10203a;
            line-height: 1.1;
        }
        .summary-stat-card {
            border-radius: 18px;
            background: #f8fbff;
            border: 1px solid #e5edf9;
            padding: 14px 14px;
            display: grid;
            gap: 5px;
        }
        .summary-stat-card span {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6b7d98;
        }
        .summary-stat-card strong {
            font-size: 20px;
            color: #10203a;
            line-height: 1.1;
        }
        .status-results {
            display: grid;
            gap: 14px;
            margin-top: 18px;
        }
        .status-student-card,
        .status-book-card {
            border-radius: 24px;
            border: 1px solid #e1e8f5;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            padding: 20px 18px;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
        }
        .status-student-card {
            display: grid;
            gap: 14px;
        }
        .status-student-header {
            display: grid;
            gap: 8px;
        }
        .status-student-header h3 {
            font-size: 24px;
            color: #10203a;
            line-height: 1.2;
        }
        .status-student-header p,
        .status-student-meta {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }
        .status-student-meta strong {
            color: #10203a;
        }
        .status-keyline {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }
        .status-book-card {
            display: grid;
            gap: 12px;
        }
        .status-section-title {
            font-size: 17px;
            font-weight: 800;
            color: #10203a;
            letter-spacing: 0.01em;
        }
        .status-section-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .status-section-title-row span {
            font-size: 13px;
            font-weight: 700;
            color: #6b7d98;
        }
        .status-book-topline {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .status-book-card h4 {
            font-size: 19px;
            color: #10203a;
            line-height: 1.35;
        }
        .status-book-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .status-book-meta .rep-detail {
            padding: 13px 14px;
        }
        .status-badge-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .status-pill.paid { background: rgba(16, 185, 129, 0.12); color: #047857; }
        .status-pill.unpaid { background: rgba(249, 115, 22, 0.12); color: #c2410c; }
        .status-pill.partial { background: rgba(245, 158, 11, 0.14); color: #b45309; }
        .status-pill.collected { background: rgba(37, 99, 235, 0.12); color: #1d4ed8; }
        .status-pill.pending { background: rgba(148, 163, 184, 0.18); color: #475569; }
        .status-pill.cancelled { background: rgba(209, 73, 91, 0.12); color: #be123c; }
        .status-inline-note {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }
        .status-inline-note.compact {
            margin-top: -4px;
        }
        .resume-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            border: 1px solid rgba(21, 86, 223, 0.18);
            background: #eff6ff;
            color: #1d4ed8;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        @keyframes portal-result-glow {
            0% {
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.20);
                transform: translateY(8px);
            }
            45% {
                box-shadow: 0 0 0 10px rgba(37, 99, 235, 0.06);
                transform: translateY(0);
            }
            100% {
                box-shadow: 0 22px 48px rgba(15, 23, 42, 0.08);
                transform: translateY(0);
            }
        }
        .rep-detail .status-pill {
            display: inline-flex;
            width: fit-content;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(15, 138, 105, 0.10);
            color: var(--success);
            font-size: 12px;
            font-weight: 800;
        }
        .continue-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            border-radius: 18px;
            padding: 16px 18px;
            text-decoration: none;
            color: #fff;
            font-size: 16px;
            font-weight: 800;
            background: linear-gradient(135deg, #11936f 0%, #0c6b52 100%);
            box-shadow: 0 16px 34px rgba(15, 138, 105, 0.24);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .continue-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 18px 38px rgba(15, 138, 105, 0.28);
        }
        .portal-actions {
            display: grid;
            gap: 12px;
        }
        .portal-actions-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .portal-secondary-button {
            width: 100%;
            min-height: 56px;
            padding: 0 16px;
            border-radius: 18px;
            border: 1px solid #dbe6f4;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            color: #12315f;
            font-size: 15px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
            text-decoration: none;
        }
        .portal-secondary-button:hover {
            transform: translateY(-1px);
            border-color: #b8cdf4;
            box-shadow: 0 16px 28px rgba(15, 23, 42, 0.08);
        }
        .books-results {
            display: grid;
            gap: 16px;
        }
        .books-results-header {
            display: grid;
            gap: 8px;
        }
        .books-results-header h3 {
            font-size: 24px;
            color: #10203a;
            line-height: 1.2;
        }
        .books-results-header p {
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }
        .books-list {
            display: grid;
            gap: 14px;
        }
        .book-card {
            border-radius: 22px;
            border: 1px solid #e1e8f5;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            padding: 18px 16px;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.05);
            display: grid;
            gap: 14px;
        }
        .book-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .book-card-head h4 {
            font-size: 19px;
            color: #10203a;
            line-height: 1.35;
        }
        .book-card-price {
            font-size: 20px;
            font-weight: 900;
            color: #10203a;
            white-space: nowrap;
        }
        .book-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .book-card-actions {
            display: grid;
            gap: 10px;
        }
        .book-empty-state {
            border-radius: 22px;
            border: 1px dashed #d6e2f5;
            background: #f8fbff;
            padding: 18px 16px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }
        .catalog-shell {
            display: grid;
            gap: 18px;
        }
        .catalog-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .catalog-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .catalog-brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 14px;
            background: linear-gradient(135deg, #4f7dff 0%, #5b4fe9 100%);
            color: #fff;
            display: grid;
            place-items: center;
            box-shadow: 0 12px 22px rgba(79, 125, 255, 0.24);
            font-size: 18px;
        }
        .catalog-brand span {
            font-size: 18px;
            font-weight: 900;
            color: #23448f;
            letter-spacing: -0.03em;
        }
        .catalog-back-button {
            min-height: 46px;
            padding: 0 16px;
            border-radius: 14px;
            border: 1px solid #dfe6f6;
            background: #fff;
            color: #37519a;
            font-size: 14px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
        }
        .catalog-hero {
            border-radius: 30px;
            border: 1px solid #e7edfa;
            background:
                radial-gradient(circle at top right, rgba(91, 79, 233, 0.08), transparent 28%),
                linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            padding: 18px 16px;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
            display: grid;
            gap: 12px;
        }
        .catalog-hero-main {
            display: grid;
            gap: 12px;
        }
        .catalog-hero-title {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 12px;
            align-items: center;
        }
        .catalog-hero-icon {
            width: 56px;
            height: 56px;
            border-radius: 20px;
            background: linear-gradient(135deg, #4f7dff 0%, #5b4fe9 100%);
            color: #fff;
            display: grid;
            place-items: center;
            box-shadow: 0 18px 32px rgba(91, 79, 233, 0.22);
            font-size: 26px;
            flex-shrink: 0;
        }
        .catalog-hero-copy h3 {
            font-size: clamp(22px, 4vw, 34px);
            line-height: 1.08;
            letter-spacing: -0.04em;
            color: #10203a;
            margin-bottom: 4px;
            white-space: nowrap;
        }
        .catalog-hero-copy p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
            max-width: 620px;
        }
        .catalog-search {
            position: relative;
        }
        .catalog-search svg {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #93a2bf;
        }
        .catalog-search-input {
            width: 100%;
            min-height: 58px;
            padding: 0 18px 0 48px;
            border-radius: 18px;
            border: 1px solid #e1e8f5;
            background: #fff;
            color: #10203a;
            font-size: 15px;
            font-weight: 600;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
        }
        .catalog-search-input:focus {
            outline: none;
            border-color: #9cbcff;
            box-shadow: 0 0 0 4px rgba(79, 125, 255, 0.12);
        }
        .catalog-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }
        .catalog-card {
            border-radius: 24px;
            border: 1px solid #e4ebf7;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            padding: 18px 16px;
            box-shadow: 0 18px 36px rgba(15, 23, 42, 0.06);
            display: grid;
            gap: 16px;
        }
        .catalog-card-main {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 14px;
            align-items: start;
        }
        .catalog-book-icon {
            width: 62px;
            height: 62px;
            border-radius: 20px;
            display: grid;
            place-items: center;
            font-size: 28px;
            font-weight: 900;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72);
        }
        .catalog-card-copy {
            display: grid;
            gap: 6px;
            min-width: 0;
        }
        .catalog-card-copy h4 {
            font-size: 19px;
            line-height: 1.28;
            color: #10203a;
            letter-spacing: -0.02em;
        }
        .catalog-course-code {
            color: #4f46e5;
            font-size: 14px;
            font-weight: 800;
        }
        .catalog-lecturer {
            color: #71819c;
            font-size: 14px;
            line-height: 1.45;
        }
        .catalog-price-block {
            display: grid;
            gap: 2px;
            text-align: right;
        }
        .catalog-price-label {
            color: #5b6eff;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .catalog-price-value {
            color: #4f46e5;
            font-size: 20px;
            font-weight: 900;
            line-height: 1.05;
        }
        .catalog-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .catalog-availability {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #ecfdf3;
            color: #15803d;
            font-size: 13px;
            font-weight: 800;
        }
        .catalog-availability-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #22c55e;
            flex-shrink: 0;
        }
        .catalog-request-button {
            min-height: 42px;
            padding: 0 16px;
            border-radius: 14px;
            background: linear-gradient(135deg, #4f46e5 0%, #5b5df0 100%);
            color: #fff;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 14px 28px rgba(79, 70, 229, 0.2);
        }
        .catalog-request-button:hover {
            transform: translateY(-1px);
        }
        .catalog-info-card {
            border-radius: 20px;
            border: 1px solid #dfe8fb;
            background: linear-gradient(180deg, #eff5ff 0%, #eef4ff 100%);
            padding: 18px 16px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 12px;
            color: #50617d;
        }
        .catalog-info-icon {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: #dbeafe;
            color: #1d4ed8;
            display: grid;
            place-items: center;
            font-weight: 900;
        }
        .catalog-info-card strong {
            display: block;
            color: #23448f;
            margin-bottom: 4px;
        }
        .catalog-empty-search {
            border-radius: 22px;
            border: 1px dashed #d9e4f8;
            background: #ffffff;
            padding: 24px 18px;
            color: #627590;
            font-size: 14px;
            line-height: 1.7;
            text-align: center;
            display: none;
        }
        .catalog-empty-search.is-visible {
            display: block;
        }
        .offers-card {
            padding: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .offers-card:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
        }
        .public-link-row {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 4px;
        }
        .public-link-row a {
            color: #1d4ed8;
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.74);
            border: 1px solid rgba(191, 219, 254, 0.72);
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.04);
        }
        .offers-return-bar {
            display: flex;
            justify-content: flex-start;
            margin-bottom: 14px;
        }
        .offers-return-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 15px;
            border-radius: 999px;
            border: 1px solid #dbe6f4;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            color: var(--primary-b);
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
        }
        .offers-return-button svg {
            width: 16px;
            height: 16px;
        }
        .offers-copy {
            display: grid;
            gap: 6px;
        }
        .offers-copy h2 {
            font-size: 22px;
            color: #10203a;
        }
        .offers-copy p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .offers-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(63, 140, 255, 0.10) 0%, rgba(21, 86, 223, 0.06) 100%);
            color: var(--primary-b);
            display: grid;
            place-items: center;
            font-size: 26px;
            flex-shrink: 0;
        }
        .offers-arrow {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: #eef4ff;
            color: var(--primary-b);
            display: grid;
            place-items: center;
            flex-shrink: 0;
            transition: transform 0.18s ease;
        }
        .offers-card[aria-expanded="true"] .offers-arrow {
            transform: rotate(90deg);
        }
        .offers-panel {
            padding: 18px;
            display: none;
        }
        .offers-panel.is-open {
            display: grid;
            gap: 16px;
        }
        .offers-panel.is-highlighted {
            animation: portal-ads-glow 1.2s ease;
        }
        .offers-grid {
            display: grid;
            gap: 14px;
        }
        .offer-ad {
            border-radius: 22px;
            overflow: hidden;
            background: #fff;
            border: 1px solid #e4ebf7;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.06);
        }
        .offer-ad a {
            display: block;
            color: inherit;
            text-decoration: none;
        }
        .offer-image {
            width: 100%;
            aspect-ratio: 16 / 9;
            object-fit: cover;
            display: block;
            background: #e2e8f0;
        }
        .offer-body {
            padding: 16px;
        }
        .offer-badge {
            display: inline-flex;
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef4ff;
            color: var(--primary-b);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .offer-body h3 {
            font-size: 18px;
            color: #10203a;
            margin-bottom: 8px;
        }
        .offer-body p {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .offer-footer {
            margin-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 13px;
            color: var(--primary-b);
            font-weight: 700;
        }
        .offers-empty {
            border-radius: 24px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
            border: 1px solid #e2e8f4;
            padding: 22px;
            text-align: center;
            color: var(--muted);
            display: grid;
            gap: 10px;
            justify-items: center;
        }
        .offers-marquee {
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(63, 140, 255, 0.10) 0%, rgba(21, 86, 223, 0.08) 100%);
            border: 1px solid rgba(63, 140, 255, 0.14);
            overflow: hidden;
            padding: 12px 0;
        }
        .offers-marquee-track {
            display: inline-flex;
            align-items: center;
            gap: 32px;
            white-space: nowrap;
            color: var(--primary-b);
            font-size: 14px;
            font-weight: 700;
            padding-left: 100%;
            animation: portal-marquee 22s linear infinite;
        }
        .offers-marquee:hover .offers-marquee-track {
            animation-play-state: paused;
        }
        .offers-marquee strong {
            color: #10203a;
        }
        @keyframes portal-ads-glow {
            0% {
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.18);
                transform: translateY(8px);
            }
            45% {
                box-shadow: 0 0 0 10px rgba(37, 99, 235, 0.06);
                transform: translateY(0);
            }
            100% {
                box-shadow: 0 22px 48px rgba(15, 23, 42, 0.08);
                transform: translateY(0);
            }
        }
        @keyframes portal-marquee {
            0% { transform: translateX(0); }
            100% { transform: translateX(-100%); }
        }
        .offers-empty .empty-icon {
            width: 62px;
            height: 62px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(63, 140, 255, 0.10) 0%, rgba(17, 147, 111, 0.08) 100%);
            color: var(--primary-b);
            display: grid;
            place-items: center;
            font-size: 30px;
        }
        .footer-note {
            text-align: center;
            color: #7a869d;
            font-size: 13px;
            line-height: 1.6;
            padding: 4px 12px 0;
        }
        @media (min-width: 760px) {
            .portal-shell {
                width: min(1040px, calc(100vw - 34px));
                padding-bottom: 56px;
            }
            .hero {
                min-height: 430px;
                padding: 24px 24px 118px;
            }
            .hero-copy {
                margin-top: 38px;
            }
            .content-stack {
                width: min(780px, calc(100% - 44px));
                margin-left: auto;
                margin-right: auto;
                margin-top: -72px;
            }
            .floating-card {
                padding: 28px;
            }
            .catalog-hero {
                padding: 28px;
            }
            .offers-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (min-width: 1120px) {
            .portal-shell {
                width: min(1120px, calc(100vw - 42px));
            }
            .hero {
                min-height: 450px;
                padding-bottom: 124px;
            }
            .content-stack {
                width: min(820px, calc(100% - 56px));
                margin-top: -76px;
            }
        }
        @media (max-width: 640px) {
            .portal-shell {
                width: calc(100vw - 14px);
                padding-top: 6px;
            }
            .hero {
                min-height: 278px;
                border-radius: 28px;
                padding: 12px 12px 54px;
            }
            .staff-login-link {
                top: 14px;
                right: 14px;
                font-size: 14px;
            }
            .brand-mark {
                margin-top: 2px;
                padding: 9px 12px;
            }
            .brand-mark .logo-tile {
                width: 40px;
                height: 40px;
                border-radius: 14px;
                font-size: 17px;
            }
            .brand-mark span {
                font-size: 16px;
            }
            .hero-copy {
                margin-top: 14px;
                width: min(520px, 100%);
            }
            .hero-copy h1 {
                font-size: clamp(28px, 8vw, 40px);
                margin-bottom: 8px;
            }
            .hero-copy p {
                font-size: 14px;
                line-height: 1.35;
            }
            .content-stack {
                width: calc(100vw - 14px);
                margin-top: -32px;
                gap: 12px;
            }
            .floating-card,
            .offers-card,
            .offers-panel {
                border-radius: 26px;
            }
            .floating-card {
                padding: 16px 14px 16px;
            }
            .search-block {
                gap: 12px;
            }
            .action-switcher {
                gap: 8px;
                margin-bottom: 2px;
            }
            .action-switch {
                padding: 10px 10px;
                border-radius: 16px;
                gap: 4px;
            }
            .action-switch strong {
                font-size: 13px;
                line-height: 1.25;
            }
            .action-switch span {
                font-size: 11px;
                line-height: 1.3;
            }
            .portal-panel.is-active {
                gap: 12px;
            }
            .search-section-title {
                font-size: 14px;
                gap: 8px;
                margin-bottom: 6px;
            }
            .search-section-title .section-icon {
                width: 34px;
                height: 34px;
                border-radius: 12px;
            }
            .field-input {
                border-radius: 16px;
                padding: 14px 14px;
                font-size: 16px;
            }
            .field-hint {
                margin-top: 6px;
                font-size: 12px;
                line-height: 1.4;
            }
            .find-button {
                border-radius: 18px;
                padding: 14px 16px;
                font-size: 16px;
                gap: 10px;
            }
            .result-shell {
                margin-top: 12px;
            }
            .action-switcher,
            .summary-stats-grid,
            .status-overview-grid,
            .status-book-meta {
                grid-template-columns: 1fr;
            }
            .portal-actions-grid,
            .book-card-grid {
                grid-template-columns: 1fr;
            }
            .catalog-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
            }
            .catalog-card {
                border-radius: 18px;
                padding: 12px 10px;
                gap: 10px;
            }
            .rep-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .catalog-topbar {
                align-items: stretch;
            }
            .catalog-hero-title {
                grid-template-columns: auto minmax(0, 1fr);
                justify-items: stretch;
                align-items: center;
            }
            .rep-avatar {
                width: 76px;
                height: 76px;
            }
            .offers-card {
                align-items: flex-start;
            }
            .catalog-card-main {
                grid-template-columns: auto 1fr;
                gap: 10px;
            }
            .catalog-book-icon {
                width: 42px;
                height: 42px;
                border-radius: 14px;
                font-size: 20px;
            }
            .catalog-card-copy {
                gap: 4px;
            }
            .catalog-card-copy h4 {
                font-size: 14px;
                line-height: 1.22;
            }
            .catalog-course-code {
                font-size: 11px;
            }
            .catalog-lecturer {
                font-size: 11px;
                line-height: 1.3;
            }
            .catalog-price-block {
                grid-column: 2;
                text-align: left;
                gap: 0;
            }
            .catalog-price-label {
                font-size: 10px;
            }
            .catalog-price-value {
                font-size: 16px;
            }
            .catalog-card-footer {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }
            .catalog-availability {
                width: fit-content;
                padding: 6px 9px;
                font-size: 11px;
                gap: 6px;
            }
            .catalog-availability-dot {
                width: 7px;
                height: 7px;
            }
            .catalog-request-button,
            .catalog-back-button {
                width: 100%;
            }
            .catalog-request-button {
                min-height: 36px;
                padding: 0 10px;
                border-radius: 12px;
                font-size: 11px;
                gap: 6px;
            }
            .catalog-search-input {
                min-height: 50px;
                padding: 0 14px 0 42px;
                font-size: 14px;
            }
            .catalog-search-icon {
                left: 14px;
            }
            .catalog-info-card {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 14px 12px;
            }
            .catalog-hero {
                padding: 14px 12px;
            }
            .catalog-hero-main {
                gap: 10px;
            }
            .catalog-hero-icon {
                width: 42px;
                height: 42px;
                border-radius: 14px;
                font-size: 20px;
            }
            .catalog-hero-copy h3 {
                font-size: 18px;
                margin-bottom: 2px;
            }
            .catalog-hero-copy p {
                font-size: 12px;
                line-height: 1.35;
            }
        }
        @media (max-width: 390px) {
            .hero {
                min-height: 262px;
                padding-bottom: 48px;
            }
            .hero-copy h1 {
                font-size: 26px;
            }
            .hero-copy p {
                font-size: 13px;
            }
            .content-stack {
                margin-top: -28px;
            }
            .public-link-row {
                gap: 8px;
            }
            .public-link-row a {
                font-size: 12px;
                padding: 7px 10px;
            }
            .action-switch {
                padding: 9px 9px;
            }
            .action-switch strong {
                font-size: 12px;
            }
            .action-switch span {
                font-size: 10.5px;
            }
            .field-input {
                padding: 13px 13px;
                font-size: 15px;
            }
            .find-button {
                padding: 13px 14px;
                font-size: 15px;
            }
            .catalog-grid {
                gap: 8px;
            }
            .catalog-card {
                padding: 10px 8px;
                gap: 8px;
            }
            .catalog-book-icon {
                width: 38px;
                height: 38px;
                border-radius: 12px;
                font-size: 18px;
            }
            .catalog-card-copy h4 {
                font-size: 13px;
            }
            .catalog-course-code,
            .catalog-lecturer {
                font-size: 10px;
            }
            .catalog-price-value {
                font-size: 14px;
            }
            .catalog-request-button {
                min-height: 34px;
                font-size: 10.5px;
                padding: 0 8px;
            }
            .catalog-hero-copy h3 {
                font-size: 16px;
            }
            .catalog-hero-copy p {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
<div class="portal-shell">
    <section class="hero" data-hero-banner data-hero-primary="<?php echo htmlspecialchars($heroImageUrl); ?>" data-hero-fallback="<?php echo htmlspecialchars($heroImageFallbackUrl); ?>" aria-label="ClassBookHub welcome section">
        <a href="login.php" class="staff-login-link">Staff Login</a>
        <div class="brand-mark">
            <div class="logo-tile">CB</div>
            <span>ClassBookHub</span>
        </div>
        <div class="hero-copy">
            <h1>Welcome!</h1>
            <p>Find your representative and request your books easily.</p>
        </div>
    </section>

    <div class="content-stack">
        <section class="floating-card" aria-labelledby="find-rep-title">
            <div class="search-block">
                <div class="action-switcher" role="tablist" aria-label="Student portal actions">
                    <button type="button" id="requestBooksTab" class="action-switch is-active" role="tab" aria-selected="true" aria-controls="requestBooksPanel">
                        <strong>Request Books</strong>
                        <span>Find your class rep and continue to request books.</span>
                    </button>
                    <button type="button" id="checkStatusTab" class="action-switch" role="tab" aria-selected="false" aria-controls="checkStatusPanel">
                        <strong>Check My Book Status</strong>
                        <span>See the books you requested and which ones you have collected.</span>
                    </button>
                </div>

                <div id="requestBooksPanel" class="portal-panel is-active" role="tabpanel" aria-labelledby="requestBooksTab">
                    <div>
                        <div class="search-section-title">
                            <span class="section-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M6 19a6 6 0 1 1 12 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                                    <circle cx="12" cy="8" r="4" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                            </span>
                            <span id="find-rep-title">Enter Index Number</span>
                        </div>
                        <div class="field-shell">
                            <input type="text" id="indexSearch" class="field-input" placeholder="e.g. 5230100552" inputmode="text" autocomplete="off" aria-describedby="indexHelp">
                        </div>
                        <div id="indexHelp" class="field-hint">Enter your index number exactly as it appears on your class list. Your class rep can only be found after the class list has been uploaded.</div>
                    </div>

                    <button type="button" id="findRepButton" class="find-button">
                        <span class="button-spinner" aria-hidden="true"></span>
                        <svg class="button-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="2"/>
                            <path d="M16 16 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span class="button-label">Find Rep</span>
                    </button>
                </div>

                <div id="checkStatusPanel" class="portal-panel" role="tabpanel" aria-labelledby="checkStatusTab">
                    <div>
                        <div class="search-section-title">
                            <span class="section-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.8"/>
                                </svg>
                            </span>
                            <span>Check My Book Status</span>
                        </div>
                        <div class="field-shell">
                            <input type="text" id="statusIndexSearch" class="field-input" placeholder="e.g. 5230100552" inputmode="text" autocomplete="off" aria-describedby="statusHelp">
                        </div>
                        <div id="statusHelp" class="field-hint">Enter your full index number to see your requested books, payment status, and collection status.</div>
                    </div>

                    <button type="button" id="checkStatusButton" class="find-button">
                        <span class="button-spinner" aria-hidden="true"></span>
                        <svg class="button-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="2"/>
                            <path d="M16 16 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span class="button-label">Check Status</span>
                    </button>

                    <button type="button" id="resumeStatusButton" class="resume-pill" hidden>Continue with saved index</button>
                </div>
            </div>

            <div class="result-shell" id="resultShell">
                <div id="resultContainer" class="placeholder-card">
                    <div class="placeholder-icon">
                        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M5 19a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                            <circle cx="12" cy="9" r="4" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                    </div>
                    <strong>Find your class representative to begin requesting books.</strong>
                    <p>Enter your index number to continue to your class rep in seconds.</p>
                </div>
            </div>
        </section>

        <button type="button" id="offersToggle" class="offers-card" aria-expanded="<?php echo $isRepOffersView ? 'true' : 'false'; ?>" aria-controls="offersPanel">
            <div class="offers-icon">&#127991;</div>
            <div class="offers-copy">
                <h2>View Ads</h2>
                <p>Check out exclusive deals and advertisements.</p>
            </div>
            <div class="offers-arrow">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="m9 6 6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
        </button>

        <section id="offersPanel" class="offers-panel<?php echo $isRepOffersView ? ' is-open' : ''; ?>" aria-label="Ads and advertisements">
            <div id="offersPanelContent">
                <?php if ($portal_ads_loaded): ?>
                    <?php echo portal_render_ads_markup($portal_ads, $isRepOffersView, $repOffersReturnHref); ?>
                <?php else: ?>
                    <div class="offers-empty">
                        <div class="empty-icon">&#128227;</div>
                        <strong>Tap to load adverts</strong>
                        <p>Open this section when you want to view current promotions and announcements.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <div class="footer-note">Open page &rarr; Request books or check your book status &rarr; Enter your index number &rarr; Continue instantly.</div>
        <div class="public-link-row" aria-label="Public information links">
            <a href="common_request_portal.php">Home / Request Portal</a>
            <a href="about.php">About Us</a>
            <a href="contact.php">Contact Us</a>
        </div>
    </div>
</div>

<script>
const els = {
    requestBooksTab: document.getElementById('requestBooksTab'),
    checkStatusTab: document.getElementById('checkStatusTab'),
    requestBooksPanel: document.getElementById('requestBooksPanel'),
    checkStatusPanel: document.getElementById('checkStatusPanel'),
    indexSearch: document.getElementById('indexSearch'),
    statusIndexSearch: document.getElementById('statusIndexSearch'),
    resultShell: document.getElementById('resultShell'),
    resultContainer: document.getElementById('resultContainer'),
    findRepButton: document.getElementById('findRepButton'),
    checkStatusButton: document.getElementById('checkStatusButton'),
    resumeStatusButton: document.getElementById('resumeStatusButton'),
    offersToggle: document.getElementById('offersToggle'),
    offersPanel: document.getElementById('offersPanel'),
    offersPanelContent: document.getElementById('offersPanelContent')
};

const opensOffersOnLoad = <?php echo $isRepOffersView ? 'true' : 'false'; ?>;
const activeSemesterLabel = <?php echo json_encode($portalActiveSemesterName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

const indexLookupCache = new Map();
const bookLookupCache = new Map();
let adsPayloadLoaded = <?php echo $portal_ads_loaded ? 'true' : 'false'; ?>;
let indexLookupController = null;
let booksLookupController = null;
let statusLookupController = null;
let adsLookupController = null;
const defaultFindButtonLabel = els.findRepButton ? (els.findRepButton.querySelector('.button-label')?.textContent || 'Find Rep') : 'Find Rep';
const defaultStatusButtonLabel = els.checkStatusButton ? (els.checkStatusButton.querySelector('.button-label')?.textContent || 'Check Status') : 'Check Status';
const lastStatusIndexStorageKey = 'cbh_last_status_index';
let activePortalMode = 'request';
let lastRepRoute = null;

let lastRepOptions = null;
let lastStatusSignature = '';

function ensureHeroImageVisible() {
    const hero = document.querySelector('[data-hero-banner]');
    if (!hero) {
        return;
    }

    const candidates = [
        hero.getAttribute('data-hero-primary') || '',
        hero.getAttribute('data-hero-fallback') || ''
    ].filter(Boolean);

    const overlay = 'linear-gradient(180deg, rgba(6, 18, 41, 0.18) 0%, rgba(6, 18, 41, 0.48) 46%, rgba(6, 18, 41, 0.80) 100%)';
    const tryLoad = (index) => {
        if (index >= candidates.length) {
            return;
        }

        const image = new Image();
        image.onload = () => {
            hero.style.backgroundImage = `${overlay}, url("${candidates[index]}")`;
        };
        image.onerror = () => {
            tryLoad(index + 1);
        };
        image.src = candidates[index];
    };

    tryLoad(0);
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function normalizeText(value) {
    return String(value || '').trim().toLowerCase();
}

function normalizeIndexValue(value) {
    return String(value || '').trim().toUpperCase();
}

function buildStatusSignature(payload) {
    try {
        return JSON.stringify(payload || {});
    } catch (error) {
        return '';
    }
}

function createManagedController(key) {
    if (key === 'index') {
        if (indexLookupController) {
            indexLookupController.abort();
        }
        indexLookupController = new AbortController();
        return indexLookupController;
    }
    if (key === 'books') {
        if (booksLookupController) {
            booksLookupController.abort();
        }
        booksLookupController = new AbortController();
        return booksLookupController;
    }
    if (key === 'status') {
        if (statusLookupController) {
            statusLookupController.abort();
        }
        statusLookupController = new AbortController();
        return statusLookupController;
    }
    if (key === 'ads') {
        if (adsLookupController) {
            adsLookupController.abort();
        }
        adsLookupController = new AbortController();
        return adsLookupController;
    }
    return null;
}

function isAbortError(error) {
    return !!(error && (error.name === 'AbortError' || String(error.message || '').toLowerCase().includes('abort')));
}

function setFindButtonLoading(isLoading) {
    if (!els.findRepButton) {
        return;
    }

    const buttonLabel = els.findRepButton.querySelector('.button-label');
    els.findRepButton.disabled = isLoading;
    els.findRepButton.classList.toggle('is-loading', isLoading);
    els.findRepButton.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    if (buttonLabel) {
        buttonLabel.textContent = isLoading ? 'Finding Rep...' : defaultFindButtonLabel;
    }
}

function setStatusButtonLoading(isLoading) {
    if (!els.checkStatusButton) {
        return;
    }

    const buttonLabel = els.checkStatusButton.querySelector('.button-label');
    els.checkStatusButton.disabled = isLoading;
    els.checkStatusButton.classList.toggle('is-loading', isLoading);
    els.checkStatusButton.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    if (buttonLabel) {
        buttonLabel.textContent = isLoading ? 'Checking Status...' : defaultStatusButtonLabel;
    }
}

function setPortalMode(mode) {
    activePortalMode = mode === 'status' ? 'status' : 'request';
    const requestActive = activePortalMode === 'request';
    const statusActive = activePortalMode === 'status';

    if (els.requestBooksTab) {
        els.requestBooksTab.classList.toggle('is-active', requestActive);
        els.requestBooksTab.setAttribute('aria-selected', requestActive ? 'true' : 'false');
    }
    if (els.checkStatusTab) {
        els.checkStatusTab.classList.toggle('is-active', statusActive);
        els.checkStatusTab.setAttribute('aria-selected', statusActive ? 'true' : 'false');
    }
    if (els.requestBooksPanel) {
        els.requestBooksPanel.classList.toggle('is-active', requestActive);
    }
    if (els.checkStatusPanel) {
        els.checkStatusPanel.classList.toggle('is-active', statusActive);
    }
    if (statusActive && els.statusIndexSearch) {
        window.setTimeout(() => els.statusIndexSearch.focus(), 80);
    }
}

function renderPlaceholder() {
    els.resultContainer.className = 'placeholder-card';
    els.resultContainer.innerHTML = `
        <div class="placeholder-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M5 19a7 7 0 0 1 14 0" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                <circle cx="12" cy="9" r="4" stroke="currentColor" stroke-width="1.8"/>
            </svg>
        </div>
        <strong>Find your class representative to begin requesting books.</strong>
        <p>Enter your index number to find your class representative and continue in seconds.</p>
    `;
}

function renderStatusPlaceholder() {
    els.resultContainer.className = 'placeholder-card';
    els.resultContainer.innerHTML = `
        <div class="placeholder-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 6v6l4 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.8"/>
            </svg>
        </div>
        <strong>Check the books you have already requested.</strong>
        <p>Enter your index number to see payment and collection status for each requested book.</p>
    `;
}

function renderError(message) {
    els.resultContainer.className = 'error-card';
    els.resultContainer.innerHTML = `
        <div class="error-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>
                <path d="M12 8.5v4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                <circle cx="12" cy="16.8" r="1" fill="currentColor"/>
            </svg>
        </div>
        <strong>Representative not found</strong>
        <p>${escapeHtml(message || "We couldn't find your class rep. Please check your index number or contact your class rep.")}</p>
    `;
    queueScrollToResultCard();
}

function renderStatusError(message, suggestion = '') {
    els.resultContainer.className = 'error-card';
    els.resultContainer.innerHTML = `
        <div class="error-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>
                <path d="M12 8.5v4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                <circle cx="12" cy="16.8" r="1" fill="currentColor"/>
            </svg>
        </div>
        <strong>No request record found</strong>
        <p>${escapeHtml(message || 'No request record found for this index number.')}</p>
        ${suggestion ? `<p>${escapeHtml(suggestion)}</p>` : ''}
    `;
    queueScrollToResultCard();
}

function renderLoading() {
    els.resultContainer.className = 'placeholder-card';
    els.resultContainer.innerHTML = `
        <div class="placeholder-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3a9 9 0 1 1-9 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </div>
        <strong>Finding your representative...</strong>
        <p>Please hold on for a moment.</p>
    `;
    queueScrollToResultCard();
}

function renderStatusLoading() {
    els.resultContainer.className = 'placeholder-card';
    els.resultContainer.innerHTML = `
        <div class="placeholder-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3a9 9 0 1 1-9 9" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </div>
        <strong>Checking your book status...</strong>
        <p>Please hold on while we fetch the latest information.</p>
    `;
    queueScrollToResultCard();
}

function scrollToResultCard() {
    if (!els.resultContainer) {
        return;
    }

    const scrollTarget = els.resultShell || els.resultContainer;
    const resultRect = scrollTarget.getBoundingClientRect();
    const pageTop = window.pageYOffset || document.documentElement.scrollTop || 0;
    const targetTop = Math.max(0, pageTop + resultRect.top - 24);

    window.scrollTo({
        top: targetTop,
        behavior: 'smooth'
    });

    els.resultContainer.classList.remove('is-highlighted');
    window.requestAnimationFrame(() => {
        els.resultContainer.classList.add('is-highlighted');
    });
    window.setTimeout(() => {
        els.resultContainer.classList.remove('is-highlighted');
    }, 1400);
}

function queueScrollToResultCard() {
    window.requestAnimationFrame(() => {
        scrollToResultCard();
        window.setTimeout(scrollToResultCard, 140);
    });
}

function scrollToAdsPanel() {
    if (!els.offersPanel) {
        return;
    }

    const panelRect = els.offersPanel.getBoundingClientRect();
    const pageTop = window.pageYOffset || document.documentElement.scrollTop || 0;
    const targetTop = Math.max(0, pageTop + panelRect.top - 24);

    window.scrollTo({
        top: targetTop,
        behavior: 'smooth'
    });

    els.offersPanel.classList.remove('is-highlighted');
    window.requestAnimationFrame(() => {
        els.offersPanel.classList.add('is-highlighted');
    });
    window.setTimeout(() => {
        els.offersPanel.classList.remove('is-highlighted');
    }, 1400);
}

function buildContinueUrl(route, options = {}) {
    const continueUrl = new URL(route.request_url, window.location.href);
    if (options.index_number) {
        continueUrl.searchParams.set('student_index', options.index_number);
    }
    if (options.student_name) {
        continueUrl.searchParams.set('student_name', options.student_name);
    }
    return continueUrl;
}

function buildPortalActionButtons(route, options = {}) {
    const continueUrl = buildContinueUrl(route, options);
    const actionIndex = options.index_number || route.matched_index_number || '';

    return `
        <div class="portal-actions">
            <a class="continue-button" href="${escapeHtml(continueUrl.toString())}">
                <span>Continue to Request Books</span>
                <span>&rarr;</span>
            </a>
            <div class="portal-actions-grid">
                <button type="button" class="portal-secondary-button" data-portal-action="view-books" data-index="${escapeHtml(actionIndex)}">
                    View Available Books
                </button>
                <button type="button" class="portal-secondary-button" data-portal-action="check-status" data-index="${escapeHtml(actionIndex)}">
                    Check My Book Status
                </button>
            </div>
        </div>
    `;
}

function renderAvailableBooksLoading() {
    els.resultContainer.className = 'placeholder-card';
    els.resultContainer.innerHTML = `
        <div class="placeholder-icon">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M4.5 6.5A2.5 2.5 0 0 1 7 4h10a2.5 2.5 0 0 1 2.5 2.5v11A2.5 2.5 0 0 1 17 20H7a2.5 2.5 0 0 1-2.5-2.5v-11Z" stroke="currentColor" stroke-width="1.8"/>
                <path d="M8 8h8M8 12h8M8 16h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </div>
        <strong>Loading available books...</strong>
        <p>Please wait while we fetch the books for your class.</p>
    `;
    queueScrollToResultCard();
}

function renderRepResult(route, options = {}) {
    if (!route) {
        renderError("We couldn't find your class rep. Please check your index number or contact your class rep.");
        return;
    }

    lastRepRoute = route;
    lastRepOptions = {
        student_name: options.student_name || '',
        index_number: options.index_number || route.matched_index_number || ''
    };

    const avatar = route.profile_photo_path
        ? `<img src="${escapeHtml(route.profile_photo_path)}" alt="${escapeHtml(route.display_name || route.full_name)}">`
        : escapeHtml(route.initials || 'RP');
    const matchedIndex = options.index_number
        ? `
            <div class="rep-detail">
                <span>Matched Index</span>
                <strong>${escapeHtml(options.index_number)}</strong>
            </div>
        `
        : '';

    els.resultContainer.className = 'result-card';
    els.resultContainer.innerHTML = `
        <div class="result-label">Representative Found</div>
        <div class="rep-summary">
            <div class="rep-avatar">${avatar}</div>
            <div>
                <h3>${escapeHtml(route.display_name || route.full_name)}</h3>
                <p>Continue to view or buy the available books for your class.</p>
            </div>
        </div>
        <div class="rep-grid">
            <div class="rep-detail">
                <span>Class</span>
                <strong>${escapeHtml(route.class_name || 'Class route')}</strong>
            </div>
            <div class="rep-detail">
                <span>Department</span>
                <strong>${escapeHtml(route.department_name || 'Not set')}</strong>
            </div>
            <div class="rep-detail">
                <span>Status</span>
                <strong class="status-pill">${escapeHtml(route.status || 'Available')}</strong>
            </div>
            ${matchedIndex}
        </div>
        ${buildPortalActionButtons(route, options)}
    `;

    queueScrollToResultCard();
}

function renderAvailableBooksResult(route, books, options = {}) {
    if (!route) {
        renderError("We couldn't find your class rep. Please check your index number or contact your class rep.");
        return;
    }

    lastRepRoute = route;
    lastRepOptions = {
        student_name: options.student_name || '',
        index_number: options.index_number || route.matched_index_number || ''
    };

    const continueUrl = buildContinueUrl(route, options);
    const hiddenOwnedCount = Number(options.hidden_owned_count || 0);
    const allBooksAlreadyRequested = !!options.all_books_already_requested;
    const iconThemes = [
        { bg: 'linear-gradient(135deg, #eef2ff 0%, #dbeafe 100%)', color: '#3457e8' },
        { bg: 'linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%)', color: '#e24b6a' },
        { bg: 'linear-gradient(135deg, #ecfdf5 0%, #dcfce7 100%)', color: '#0f9d58' },
        { bg: 'linear-gradient(135deg, #fefce8 0%, #fef3c7 100%)', color: '#d29a0b' },
        { bg: 'linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%)', color: '#8b5cf6' }
    ];
    const bookCards = Array.isArray(books) && books.length
        ? books.map((book) => {
            const theme = iconThemes[book.book_id % iconThemes.length];
            const courseCode = String(book.course_code || '').trim();
            const lecturerName = String(book.lecturer_name || '').trim();
            return `
                <article class="catalog-card" data-book-card data-search-text="${escapeHtml(`${book.book_title || ''} ${courseCode} ${lecturerName}`.toLowerCase())}">
                    <div class="catalog-card-main">
                        <div class="catalog-book-icon" style="background:${theme.bg}; color:${theme.color};">
                            &#128218;
                        </div>
                        <div class="catalog-card-copy">
                            <h4>${escapeHtml(book.book_title || 'Book')}</h4>
                            ${courseCode !== '' ? `<div class="catalog-course-code">${escapeHtml(courseCode)}</div>` : ''}
                            ${lecturerName !== '' ? `<div class="catalog-lecturer">&#128100; ${escapeHtml(lecturerName)}</div>` : ''}
                        </div>
                        <div class="catalog-price-block">
                            <span class="catalog-price-label">GHS</span>
                            <strong class="catalog-price-value">${escapeHtml(Number(book.price || 0).toFixed(2))}</strong>
                        </div>
                    </div>
                    <div class="catalog-card-footer">
                        <div class="catalog-availability">
                            <span class="catalog-availability-dot" aria-hidden="true"></span>
                            <span>${escapeHtml(book.availability_label || 'Available')}</span>
                        </div>
                        <a class="catalog-request-button" href="${escapeHtml(continueUrl.toString())}">
                            <span>Buy This Book</span>
                            <span>&#8250;</span>
                        </a>
                    </div>
                </article>
            `;
        }).join('')
        : `
            <div class="book-empty-state">
                ${allBooksAlreadyRequested
                    ? 'You have already requested all currently available books for your class.'
                    : 'No books have been added for your class yet. Please check again later or contact your class rep.'}
            </div>
        `;

    els.resultContainer.className = 'result-card';
    els.resultContainer.innerHTML = `
        <section class="catalog-shell">
            <div class="catalog-topbar">
                <div class="catalog-brand">
                    <div class="catalog-brand-mark">&#128218;</div>
                    <span>ClassBookHub</span>
                </div>
                <button type="button" class="catalog-back-button" data-portal-action="back-options">&#8592; Back to Portal Options</button>
            </div>
            <section class="catalog-hero">
                <div class="catalog-hero-main">
                    <div class="catalog-hero-title">
                        <div class="catalog-hero-icon">&#128214;</div>
                        <div class="catalog-hero-copy">
                            <h3>Available Materials</h3>
                            <p>Browse the books and course materials currently available.</p>
                        </div>
                    </div>
                </div>
            </section>
            ${hiddenOwnedCount > 0 ? `
                <div class="catalog-empty-search" style="display:block; margin-bottom:14px; background:rgba(37,99,235,0.08); color:#1d4ed8; border-color:rgba(37,99,235,0.14);">
                    ${escapeHtml(hiddenOwnedCount === 1
                        ? '1 book you already requested has been hidden from this list.'
                        : `${hiddenOwnedCount} books you already requested have been hidden from this list.`)}
                </div>
            ` : ''}
            <div class="catalog-search">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="11" cy="11" r="6.5" stroke="currentColor" stroke-width="2"/>
                    <path d="M16 16 21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <input type="text" class="catalog-search-input" data-book-search placeholder="Search books by title, course code or lecturer..." autocomplete="off">
            </div>
            <div class="catalog-empty-search" data-book-search-empty>No matching books found.</div>
            <div class="catalog-grid" data-book-grid>${bookCards}</div>
            <div class="catalog-info-card">
                <div class="catalog-info-icon">i</div>
                <div>
                    <strong>Can’t find a book you’re looking for?</strong>
                    <div>Some books may be added later by your class representative.</div>
                </div>
            </div>
        </section>
    `;

    const searchInput = els.resultContainer.querySelector('[data-book-search]');
    const emptySearchState = els.resultContainer.querySelector('[data-book-search-empty]');
    const catalogCards = Array.from(els.resultContainer.querySelectorAll('[data-book-card]'));
    if (searchInput && emptySearchState && catalogCards.length) {
        searchInput.addEventListener('input', () => {
            const query = searchInput.value.trim().toLowerCase();
            let visibleCount = 0;
            catalogCards.forEach((card) => {
                const haystack = String(card.getAttribute('data-search-text') || '');
                const isVisible = query === '' || haystack.includes(query);
                card.hidden = !isVisible;
                if (isVisible) {
                    visibleCount += 1;
                }
            });
            emptySearchState.classList.toggle('is-visible', visibleCount === 0);
        });
    }

    queueScrollToResultCard();
}

function formatMoney(amount) {
    const numeric = Number(amount || 0);
    return `GHS ${numeric.toFixed(2)}`;
}

function formatStatusDate(dateString) {
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
        return 'Date unavailable';
    }
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    }).format(date);
}

function buildPortalRequestUrl(path, params = {}) {
    const url = new URL(path, window.location.href);
    const currentParams = new URLSearchParams(window.location.search);
    const challengeToken = currentParams.get('i');
    if (challengeToken) {
        url.searchParams.set('i', challengeToken);
    }

    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            url.searchParams.set(key, String(value));
        }
    });

    return url.toString();
}

async function searchAvailableBooks(indexNumber) {
    const normalizedIndex = normalizeIndexValue(indexNumber);
    if (normalizedIndex === '') {
        return {
            success: false,
            message: 'Enter your index number to continue.'
        };
    }

    if (bookLookupCache.has(normalizedIndex)) {
        return bookLookupCache.get(normalizedIndex);
    }

    const controller = createManagedController('books');
    const response = await fetch(buildPortalRequestUrl('common_request_portal.php', {
        lookup: 'books',
        index_number: normalizedIndex,
        _rt: Date.now()
    }), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-store',
        signal: controller ? controller.signal : undefined
    });

    const payload = await response.json();
    bookLookupCache.set(normalizedIndex, payload);
    return payload;
}

function buildPaymentPill(status) {
    const normalized = String(status || 'unpaid').toLowerCase();
    const tone = normalized === 'paid' ? 'paid' : (normalized === 'partial' ? 'partial' : 'unpaid');
    const label = normalized === 'paid' ? 'Paid' : (normalized === 'partial' ? 'Partial' : 'Unpaid');
    return `<span class="status-pill ${tone}">${escapeHtml(label)}</span>`;
}

function buildCollectionPill(status) {
    const normalized = String(status || 'pending').toLowerCase();
    if (normalized === 'collected') {
        return '<span class="status-pill collected">Collected</span>';
    }
    if (normalized === 'cancelled') {
        return '<span class="status-pill cancelled">Cancelled</span>';
    }
    return '<span class="status-pill pending">Pending</span>';
}

function renderStatusResult(payload) {
    lastStatusSignature = buildStatusSignature(payload);
    const student = payload && payload.student ? payload.student : {};
    const summary = payload && payload.summary ? payload.summary : {};
    const items = Array.isArray(payload && payload.items) ? payload.items : [];
    const uniquePaymentStates = Array.from(new Set(items.map((item) => String(item.payment_status || 'unpaid').toLowerCase())));
    const paymentTone = uniquePaymentStates.length > 1
        ? 'partial'
        : (uniquePaymentStates[0] === 'paid' ? 'paid' : 'unpaid');
    const requestedCount = Number(summary.books_requested || 0);
    const collectedCount = Number(summary.books_collected || 0);
    const pendingCount = Number(summary.pending_collection || 0);
    const totalPaid = Number(summary.total_paid || 0);
    const outstandingBalance = Number(summary.outstanding_balance || 0);
    const paymentLabel = paymentTone === 'paid'
        ? 'Fully Paid'
        : (paymentTone === 'partial' ? 'Partially Paid' : 'Awaiting Payment');
    const collectionLabel = requestedCount > 0
        ? `${collectedCount} of ${requestedCount} collected`
        : 'No books requested';
    const identityLineParts = [
        student.index_number ? escapeHtml(student.index_number) : '',
        student.class_name ? escapeHtml(student.class_name) : '',
    ].filter(Boolean);
    const statusMetaLine = student.rep_name
        ? `<div class="status-student-meta">Representative: <strong>${escapeHtml(student.rep_name)}</strong></div>`
        : '';

    const summaryCards = `
        <div class="status-overview-grid">
            <div class="status-balance-card">
                <span>Outstanding Balance</span>
                <strong>${escapeHtml(formatMoney(outstandingBalance))}</strong>
                <div class="status-balance-meta">
                    <div>Paid <b>${escapeHtml(formatMoney(totalPaid))}</b></div>
                    <div>${collectionLabel}</div>
                </div>
            </div>
            <div class="status-primary-stat">
                <span>Payment Status</span>
                <strong>${escapeHtml(paymentLabel)}</strong>
                <div class="status-badge-row">
                    ${buildPaymentPill(paymentTone)}
                </div>
            </div>
        </div>
        <div class="summary-stats-grid">
            <div class="summary-stat-card">
                <span>Books Requested</span>
                <strong>${requestedCount}</strong>
            </div>
            <div class="summary-stat-card">
                <span>Books Collected</span>
                <strong>${collectedCount}</strong>
            </div>
            <div class="summary-stat-card">
                <span>Pending Collection</span>
                <strong>${pendingCount}</strong>
            </div>
        </div>
    `;

    const itemCards = items.map((item) => {
        return `
            <article class="status-book-card">
                <div class="status-book-topline">
                    <h4>${escapeHtml(item.book_title || 'Requested Book')}</h4>
                    <div class="status-badge-row">
                        ${buildCollectionPill(item.collection_status)}
                    </div>
                </div>
                <div class="status-book-meta">
                    <div class="rep-detail">
                        <span>Amount</span>
                        <strong>${escapeHtml(formatMoney(item.amount || 0))}</strong>
                    </div>
                    <div class="rep-detail">
                        <span>Request Date</span>
                        <strong>${escapeHtml(item.request_date_display || formatStatusDate(item.request_date || ''))}</strong>
                    </div>
                </div>
                ${(Number(item.cash_refunded_amount || 0) > 0 || Number(item.credit_refunded_amount || 0) > 0)
                    ? `<div class="status-inline-note compact">Refunded: ${escapeHtml(formatMoney((item.cash_refunded_amount || 0) + (item.credit_refunded_amount || 0)))}</div>`
                    : ''
                }
            </article>
        `;
    }).join('');

    els.resultContainer.className = 'result-card';
    els.resultContainer.innerHTML = `
        <section class="status-results">
            <article class="status-student-card">
                <div class="result-label">Request Status</div>
                <div class="status-student-header">
                    <h3>${escapeHtml(student.full_name || 'Student')}</h3>
                    <p>${escapeHtml(student.index_number || '')}${student.rep_name ? ` â€¢ ${escapeHtml(student.rep_name)}` : ''}</p>
                </div>
                <div class="status-keyline">
                    ${buildPaymentPill(paymentTone)}
                    <div class="status-inline-note">${collectionLabel}</div>
                </div>
                ${summaryCards}
            </article>
            <div class="status-section-title-row">
                <div class="status-section-title">Requested Books</div>
                <span>${requestedCount} item${requestedCount === 1 ? '' : 's'}</span>
            </div>
            ${itemCards}
        </section>
    `;

    const statusHeader = els.resultContainer.querySelector('.status-student-header');
    const statusHeaderMeta = statusHeader ? statusHeader.querySelector('p') : null;
    if (statusHeaderMeta) {
        statusHeaderMeta.textContent = identityLineParts.join(' • ');
    }
    if (statusHeader && statusMetaLine) {
        statusHeader.insertAdjacentHTML('beforeend', statusMetaLine);
    }

    queueScrollToResultCard();
}

async function searchByIndex(indexNumber) {
    const normalizedIndex = normalizeIndexValue(indexNumber);
    if (normalizedIndex === '') {
        return {
            success: false,
            message: 'Enter your index number to continue.'
        };
    }

    if (indexLookupCache.has(normalizedIndex)) {
        return indexLookupCache.get(normalizedIndex);
    }

    const lookupUrl = buildPortalRequestUrl('common_request_portal.php', {
        lookup: 'index',
        index_number: normalizedIndex,
        _rt: Date.now()
    });
    const controller = createManagedController('index');
    const response = await fetch(lookupUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-store',
        signal: controller ? controller.signal : undefined
    });
    const payload = await response.json();
    indexLookupCache.set(normalizedIndex, payload);
    return payload;
}

async function searchRequestStatus(indexNumber) {
    const normalizedIndex = normalizeIndexValue(indexNumber);
    if (normalizedIndex === '') {
        return {
            success: false,
            message: 'Enter your index number to continue.'
        };
    }

    const controller = createManagedController('status');
    const response = await fetch(buildPortalRequestUrl('ajax_request_status.php', {
        index: normalizedIndex
    }), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-store',
        signal: controller ? controller.signal : undefined
    });
    return response.json();
}

async function ensureAdsLoaded() {
    if (adsPayloadLoaded || !els.offersPanelContent) {
        return;
    }

    els.offersPanelContent.innerHTML = `
        <div class="offers-empty">
            <div class="empty-icon">&#128227;</div>
            <strong>Loading adverts...</strong>
            <p>Please wait while we fetch current promotions and announcements.</p>
        </div>
    `;

    const controller = createManagedController('ads');
    const response = await fetch(buildPortalRequestUrl('common_request_portal.php', {
        lookup: 'ads',
        _rt: Date.now()
    }), {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        cache: 'no-store',
        signal: controller ? controller.signal : undefined
    });
    const payload = await response.json();
    if (!payload || !payload.success) {
        throw new Error(payload && payload.message ? payload.message : 'Unable to load adverts.');
    }

    els.offersPanelContent.innerHTML = payload.html || '';
    adsPayloadLoaded = true;
}

async function handleFindRep() {
    const indexNumber = els.indexSearch.value.trim();
    if (indexNumber === '') {
        renderError('Enter your index number to continue.');
        return;
    }

    setFindButtonLoading(true);
    renderLoading();
    try {
        const payload = await searchByIndex(indexNumber);
        if (payload && payload.success && payload.rep) {
            renderRepResult(payload.rep, {
                student_name: payload.rep.student_name || '',
                index_number: payload.rep.matched_index_number || indexNumber
            });
        } else {
            renderError(payload && payload.message ? payload.message : "We couldn't find your class rep. Please check your index number or contact your class rep.");
        }
    } catch (error) {
        if (isAbortError(error)) {
            return;
        }
        renderError('We could not complete the search right now. Please try again in a moment.');
    } finally {
        setFindButtonLoading(false);
    }
}

async function handleViewAvailableBooks(indexNumber) {
    const normalizedIndex = normalizeIndexValue(indexNumber);
    if (normalizedIndex === '') {
        renderError('Enter your index number to continue.');
        return;
    }

    renderAvailableBooksLoading();
    try {
        const payload = await searchAvailableBooks(normalizedIndex);
        if (payload && payload.success && payload.rep) {
            renderAvailableBooksResult(payload.rep, payload.books || [], {
                student_name: payload.rep.student_name || '',
                index_number: payload.rep.matched_index_number || normalizedIndex,
                hidden_owned_count: Number(payload.hidden_owned_count || 0),
                all_books_already_requested: !!payload.all_books_already_requested
            });
        } else {
            renderError(payload && payload.message ? payload.message : 'We could not load available books right now.');
        }
    } catch (error) {
        if (isAbortError(error)) {
            return;
        }
        renderError('We could not load available books right now. Please try again in a moment.');
    }
}

async function handleCheckStatus() {
    const indexNumber = els.statusIndexSearch.value.trim();
    if (indexNumber === '') {
        renderStatusError('Enter your index number to continue.');
        return;
    }

    setStatusButtonLoading(true);
    renderStatusLoading();
    try {
        const payload = await searchRequestStatus(indexNumber);
        if (payload && payload.success) {
            try {
                window.localStorage.setItem(lastStatusIndexStorageKey, normalizeIndexValue(indexNumber));
            } catch (error) {}
            renderStatusResult(payload);
        } else {
            renderStatusError(
                payload && payload.message ? payload.message : 'No request record found for this index number.',
                payload && payload.suggestion ? payload.suggestion : 'Please check the index number or request books first.'
            );
        }
    } catch (error) {
        if (isAbortError(error)) {
            return;
        }
        renderStatusError('We could not check your request status right now.', 'Please try again in a moment.');
    } finally {
        setStatusButtonLoading(false);
    }
}

async function refreshStatusIfChanged() {
    if (activePortalMode !== 'status' || !els.statusIndexSearch) {
        return;
    }

    const indexNumber = els.statusIndexSearch.value.trim();
    if (indexNumber === '' || lastStatusSignature === '') {
        return;
    }

    try {
        const payload = await searchRequestStatus(indexNumber);
        if (!payload || !payload.success) {
            return;
        }
        const nextSignature = buildStatusSignature(payload);
        if (nextSignature !== '' && nextSignature !== lastStatusSignature) {
            renderStatusResult(payload);
        }
    } catch (error) {
        if (isAbortError(error)) {
            return;
        }
    }
}

els.findRepButton.addEventListener('click', handleFindRep);
if (els.resultContainer) {
    els.resultContainer.addEventListener('click', (event) => {
        const actionButton = event.target.closest('[data-portal-action]');
        if (!actionButton) {
            return;
        }

        const action = actionButton.getAttribute('data-portal-action') || '';
        const indexNumber = actionButton.getAttribute('data-index') || '';
        if (action === 'view-books') {
            event.preventDefault();
            handleViewAvailableBooks(indexNumber);
            return;
        }
        if (action === 'back-options') {
            event.preventDefault();
            if (lastRepRoute) {
                renderRepResult(lastRepRoute, lastRepOptions || {});
            } else {
                renderPlaceholder();
            }
            return;
        }
        if (action === 'check-status') {
            event.preventDefault();
            if (els.statusIndexSearch) {
                els.statusIndexSearch.value = indexNumber;
            }
            setPortalMode('status');
            handleCheckStatus();
        }
    });
}
els.indexSearch.addEventListener('input', () => {
    if (els.indexSearch.value.trim() === '' && activePortalMode === 'request') {
        renderPlaceholder();
    }
});
els.indexSearch.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        handleFindRep();
    }
});

if (els.requestBooksTab) {
    els.requestBooksTab.addEventListener('click', () => {
        setPortalMode('request');
        if (els.indexSearch && els.indexSearch.value.trim() === '') {
            renderPlaceholder();
        }
    });
}

if (els.checkStatusTab) {
    els.checkStatusTab.addEventListener('click', () => {
        setPortalMode('status');
        if (els.statusIndexSearch && els.statusIndexSearch.value.trim() === '') {
            renderStatusPlaceholder();
        }
    });
}

if (els.checkStatusButton) {
    els.checkStatusButton.addEventListener('click', handleCheckStatus);
}

if (els.statusIndexSearch) {
    els.statusIndexSearch.addEventListener('input', () => {
        if (els.statusIndexSearch.value.trim() === '' && activePortalMode === 'status') {
            renderStatusPlaceholder();
        }
    });
    els.statusIndexSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            handleCheckStatus();
        }
    });
}

if (els.resumeStatusButton && els.statusIndexSearch) {
    let savedIndex = '';
    try {
        savedIndex = normalizeIndexValue(window.localStorage.getItem(lastStatusIndexStorageKey) || '');
    } catch (error) {
        savedIndex = '';
    }
    if (savedIndex) {
        els.resumeStatusButton.hidden = false;
        els.resumeStatusButton.textContent = `Continue with ${savedIndex}`;
        els.resumeStatusButton.addEventListener('click', () => {
            els.statusIndexSearch.value = savedIndex;
            setPortalMode('status');
            handleCheckStatus();
        });
    }
}

(function () {
    let refreshInFlight = false;
    let lastRefreshAt = 0;

    function checkForStatusChanges() {
        const now = Date.now();
        if (document.visibilityState !== 'visible') {
            return;
        }
        if (refreshInFlight || (now - lastRefreshAt) < 10000) {
            return;
        }

        refreshInFlight = true;
        lastRefreshAt = now;
        Promise.resolve(refreshStatusIfChanged())
            .finally(() => {
                refreshInFlight = false;
            });
    }

    window.setInterval(checkForStatusChanges, 15000);
    window.addEventListener('focus', checkForStatusChanges);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            checkForStatusChanges();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            checkForStatusChanges();
        }
    });
})();

els.offersToggle.addEventListener('click', async () => {
    const expanded = els.offersToggle.getAttribute('aria-expanded') === 'true';
    els.offersToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    els.offersPanel.classList.toggle('is-open', !expanded);
    if (!expanded) {
        try {
            await ensureAdsLoaded();
        } catch (error) {
            if (!isAbortError(error) && els.offersPanelContent) {
                els.offersPanelContent.innerHTML = `
                    <div class="offers-empty">
                        <div class="empty-icon">&#128227;</div>
                        <strong>Unable to load adverts</strong>
                        <p>Please try opening this section again in a moment.</p>
                    </div>
                `;
            }
        }
        window.setTimeout(scrollToAdsPanel, 80);
    }
});

if (opensOffersOnLoad && els.offersPanel && els.offersToggle) {
    els.offersToggle.setAttribute('aria-expanded', 'true');
    els.offersPanel.classList.add('is-open');
    window.requestAnimationFrame(() => {
        scrollToAdsPanel();
    });
}

ensureHeroImageVisible();

if (activePortalMode === 'request') {
    renderPlaceholder();
}
</script>
</body>
</html>

