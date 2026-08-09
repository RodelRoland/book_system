<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
require_once 'db.php';

if (function_exists('book_system_send_security_headers')) {
    book_system_send_security_headers();
}

mysqli_report(MYSQLI_REPORT_OFF);

function ajax_student_lookup_respond(array $payload, int $statusCode = 200): void
{
    if (ob_get_length() !== false) {
        ob_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    $message = 'Lookup is unavailable right now. Please try again.';
    if (function_exists('book_system_security_debug_enabled') && book_system_security_debug_enabled()) {
        $message = $e->getMessage();
    }
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'server_error',
        'message' => $message,
    ], 500);
});

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if (!isset($_SESSION['admin_logged_in'])) {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'not_authenticated',
        'message' => 'Not authenticated.',
    ], 401);
}

$accessContext = function_exists('book_system_get_effective_rep_access_context')
    ? book_system_get_effective_rep_access_context($conn)
    : null;
$currentAdminId = intval($accessContext['effective_admin_id'] ?? ($_SESSION['admin_id'] ?? 0));
$semesterId = isset($ACTIVE_SEMESTER_ID) ? intval($ACTIVE_SEMESTER_ID) : 0;
if ($semesterId <= 0 && function_exists('book_system_get_active_semester_id')) {
    $semesterId = book_system_get_active_semester_id($conn);
}

$rawQuery = trim(strval($_GET['index_number'] ?? $_GET['index'] ?? $_GET['q'] ?? ''));
$digitsOnly = preg_replace('/\D+/', '', $rawQuery);

if ($rawQuery === '' || !is_string($digitsOnly) || $digitsOnly === '') {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'missing_index',
        'message' => 'No index number provided.',
    ], 422);
}

if ($currentAdminId <= 0) {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'no_rep_context',
        'message' => 'Rep context not available.',
    ], 403);
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS total_students FROM class_students WHERE admin_id = ? AND semester_id = ?");
if (!$countStmt) {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'query_error',
        'message' => 'Lookup is unavailable right now.',
    ], 500);
}
$countStmt->bind_param('ii', $currentAdminId, $semesterId);
$countStmt->execute();
$countStmt->bind_result($classCount);
$countStmt->fetch();
$countStmt->close();
$classCount = intval($classCount ?? 0);

if ($classCount <= 0) {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'no_class_list',
        'message' => 'Your class list has not been uploaded yet. Please upload your class list before using manual request.',
    ]);
}

if (strlen($digitsOnly) < 3) {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'too_short',
        'message' => 'Enter at least the last 3 digits of the index number.',
    ]);
}

$likePattern = '%' . $digitsOnly;
$normalized_index_sql = function_exists('book_system_normalized_index_sql')
    ? book_system_normalized_index_sql('index_number')
    : "REPLACE(REPLACE(REPLACE(REPLACE(UPPER(TRIM(index_number)), '/', ''), ' ', ''), '-', ''), '.', '')";
$lookupSql = "SELECT student_name, index_number
    FROM class_students
    WHERE admin_id = ?
      AND semester_id = ?
      AND $normalized_index_sql LIKE ?
    ORDER BY index_number ASC, student_name ASC
    LIMIT 6";

$lookupStmt = $conn->prepare($lookupSql);
if (!$lookupStmt) {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'query_error',
        'message' => 'Lookup is unavailable right now.',
    ], 500);
}

$lookupStmt->bind_param('iis', $currentAdminId, $semesterId, $likePattern);
$lookupStmt->execute();
$lookupResult = $lookupStmt->get_result();

$matches = [];
while ($lookupResult && ($row = $lookupResult->fetch_assoc())) {
    $matches[] = [
        'student_name' => trim(strval($row['student_name'] ?? '')),
        'full_index' => trim(strval($row['index_number'] ?? '')),
    ];
}
$lookupStmt->close();

if (count($matches) === 1) {
    ajax_student_lookup_respond([
        'success' => true,
        'status' => 'found',
        'student_name' => $matches[0]['student_name'],
        'full_index' => $matches[0]['full_index'],
        'match_count' => 1,
    ]);
}

if (count($matches) > 1) {
    ajax_student_lookup_respond([
        'success' => false,
        'status' => 'multiple',
        'message' => 'Multiple matches found. Please select the correct student.',
        'matches' => $matches,
        'match_count' => count($matches),
    ]);
}

ajax_student_lookup_respond([
    'success' => false,
    'status' => 'not_found',
    'message' => 'No student found with these index digits in your uploaded class list.',
]);
