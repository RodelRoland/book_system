<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'db.php';

mysqli_report(MYSQLI_REPORT_OFF);

$db_name = $db_name ?? 'book_distribution_system';
$db_user = $db_user ?? 'root';
$db_pass = $db_pass ?? '';

$portRes = $conn->query("SELECT @@port AS port");
$db_port = 3306;
if ($portRes && $portRes->num_rows === 1) {
    $db_port = intval($portRes->fetch_assoc()['port'] ?? 3306);
}

$conn->query("CREATE TABLE IF NOT EXISTS system_state (
    id TINYINT NOT NULL,
    last_db_change_at DATETIME NULL,
    last_backup_at DATETIME NULL,
    last_backup_file VARCHAR(255) NULL,
    backup_in_progress TINYINT(1) NOT NULL DEFAULT 0,
    backup_started_at DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("INSERT INTO system_state (id, last_db_change_at, last_backup_at, last_backup_file, backup_in_progress, backup_started_at)
             VALUES (1, NOW(), NULL, NULL, 0, NULL)
             ON DUPLICATE KEY UPDATE id = VALUES(id)");

$tables = [
    'admins',
    'semesters',
    'students',
    'books',
    'requests',
    'request_items',
    'lecturer_payments',
    'books_received',
    'class_students',
    'balance_returns',
];

function ensure_trigger(mysqli $conn, string $triggerName, string $timing, string $table): void {
    $dbRes = $conn->query("SELECT DATABASE() AS db");
    if (!$dbRes || $dbRes->num_rows !== 1) {
        return;
    }
    $db = $dbRes->fetch_assoc()['db'] ?? '';
    if ($db === '') {
        return;
    }

    $stmt = $conn->prepare("SELECT 1 FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND TRIGGER_NAME = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $db, $triggerName);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        return;
    }

    $sql = "CREATE TRIGGER `{$triggerName}` {$timing} ON `{$table}` FOR EACH ROW "
         . "UPDATE system_state SET last_db_change_at = NOW() WHERE id = 1";
    @$conn->query($sql);
}

foreach ($tables as $t) {
    ensure_trigger($conn, "trg_{$t}_ai", 'AFTER INSERT', $t);
    ensure_trigger($conn, "trg_{$t}_au", 'AFTER UPDATE', $t);
    ensure_trigger($conn, "trg_{$t}_ad", 'AFTER DELETE', $t);
}

$stateRes = $conn->query("SELECT last_db_change_at, last_backup_at, backup_in_progress, backup_started_at FROM system_state WHERE id = 1 LIMIT 1");
if (!$stateRes || $stateRes->num_rows !== 1) {
    fwrite(STDERR, "Failed to read system_state.\n");
    exit(1);
}

$state = $stateRes->fetch_assoc();
$lastChange = $state['last_db_change_at'] ? strtotime($state['last_db_change_at']) : null;
$lastBackup = $state['last_backup_at'] ? strtotime($state['last_backup_at']) : null;
$inProgress = intval($state['backup_in_progress'] ?? 0) === 1;
$startedAt = $state['backup_started_at'] ? strtotime($state['backup_started_at']) : null;

$now = time();

if ($inProgress && $startedAt && ($now - $startedAt) > (15 * 60)) {
    $conn->query("UPDATE system_state SET backup_in_progress = 0, backup_started_at = NULL WHERE id = 1");
    $inProgress = false;
}

if ($lastChange === null) {
    echo "No change timestamp set. Skipping.\n";
    exit(0);
}

if ($lastBackup !== null) {
    if ($lastChange <= $lastBackup) {
        echo "No new changes since last backup. Skipping.\n";
        exit(0);
    }
}

$conn->query("UPDATE system_state SET backup_in_progress = 1, backup_started_at = NOW() WHERE id = 1 AND backup_in_progress = 0");
if ($conn->affected_rows !== 1) {
    echo "Backup already in progress. Skipping.\n";
    exit(0);
}

$backupDir = 'C:\\xampp\\mysql_backups\\' . $db_name;
if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
        $conn->query("UPDATE system_state SET backup_in_progress = 0, backup_started_at = NULL WHERE id = 1");
        fwrite(STDERR, "Failed to create backup directory: {$backupDir}\n");
        exit(1);
    }
}

$stamp = date('Ymd_His');
$backupFile = $backupDir . DIRECTORY_SEPARATOR . $db_name . '_' . $stamp . '.sql';

$mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
if (!file_exists($mysqldump)) {
    $conn->query("UPDATE system_state SET backup_in_progress = 0, backup_started_at = NULL WHERE id = 1");
    fwrite(STDERR, "mysqldump not found at {$mysqldump}\n");
    exit(1);
}

$cmd = [
    $mysqldump,
    '--host=127.0.0.1',
    '--port=' . strval($db_port),
    '--user=' . $db_user,
    '--single-transaction',
    '--routines',
    '--events',
    '--triggers',
    '--databases',
    $db_name,
    '--result-file=' . $backupFile,
];

if ($db_pass !== '') {
    $cmd[] = '--password=' . $db_pass;
}

$cmdline = implode(' ', array_map('escapeshellarg', $cmd));

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open($cmdline, $descriptors, $pipes);
if (!is_resource($proc)) {
    $conn->query("UPDATE system_state SET backup_in_progress = 0, backup_started_at = NULL WHERE id = 1");
    fwrite(STDERR, "Failed to start mysqldump process.\n");
    exit(1);
}

fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

if ($exitCode !== 0 || !file_exists($backupFile) || filesize($backupFile) === 0) {
    $conn->query("UPDATE system_state SET backup_in_progress = 0, backup_started_at = NULL WHERE id = 1");
    if (file_exists($backupFile)) {
        @unlink($backupFile);
    }
    fwrite(STDERR, "mysqldump failed (code {$exitCode}).\n{$err}\n{$out}\n");
    exit(1);
}

// Also create an Excel-friendly onboarding snapshot for rep signups
$onboardingFile = $backupDir . DIRECTORY_SEPARATOR . 'rep_onboarding_backup_' . $stamp . '.csv';
try {
    $fp = @fopen($onboardingFile, 'w');
    if ($fp) {
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'Signup ID',
            'Username',
            'Full Name',
            'Class Name',
            'Status',
            'Requested At',
            'Decision At',
            'Approved/Rejected By',
            'Rep Account Created',
            'Rep Is Active',
            'Requires Password Reset',
            'First Time Code Status',
            'Can Login',
            'Next Step',
            'First Time Code',
            'First Time Code Expires',
            'MoMo Number',
            'Bank Name',
            'Account Name',
            'Account Number'
        ]);

        $sql = "SELECT 
                    rs.signup_id,
                    rs.username,
                    rs.full_name,
                    rs.class_name,
                    rs.status,
                    rs.created_at,
                    rs.approved_at,
                    approver.username AS approved_by_username,
                    rep.admin_id AS rep_admin_id,
                    rep.is_active AS rep_is_active,
                    rep.requires_password_reset AS rep_requires_password_reset,
                    rep.first_time_code AS rep_first_time_code,
                    rep.first_time_code_expires AS rep_first_time_code_expires,
                    rs.momo_number,
                    rs.bank_name,
                    rs.account_name,
                    rs.account_number
                FROM rep_signup_requests rs
                LEFT JOIN admins approver ON approver.admin_id = rs.approved_by_admin_id
                LEFT JOIN admins rep ON rep.admin_id = rs.created_admin_id
                ORDER BY rs.created_at DESC, rs.signup_id DESC";

        $res = @$conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $repCreated = intval($row['rep_admin_id'] ?? 0) > 0 ? 'YES' : 'NO';
                $repActive = ($repCreated === 'YES') ? (intval($row['rep_is_active'] ?? 0) === 1 ? 'YES' : 'NO') : '';
                $repReset = ($repCreated === 'YES') ? (intval($row['rep_requires_password_reset'] ?? 0) === 1 ? 'YES' : 'NO') : '';

                $codeStatus = '';
                $canLogin = '';
                $nextStep = '';
                $expiresStr = strval($row['rep_first_time_code_expires'] ?? '');
                $expiresTs = $expiresStr !== '' ? strtotime($expiresStr) : 0;

                if ($repCreated === 'YES') {
                    if (intval($row['rep_is_active'] ?? 0) !== 1) {
                        $canLogin = 'NO';
                        $nextStep = 'ACCOUNT INACTIVE';
                    } elseif (intval($row['rep_requires_password_reset'] ?? 0) === 1) {
                        $canLogin = 'NO';
                        $codeStatus = ($expiresTs > 0 && $expiresTs < time()) ? 'EXPIRED' : 'ACTIVE';
                        $nextStep = ($codeStatus === 'EXPIRED') ? 'REGENERATE CODE' : 'SEND CODE / SET PASSWORD';
                    } else {
                        $canLogin = 'YES';
                        $codeStatus = 'COMPLETED';
                        $nextStep = 'LOGIN';
                    }
                } else {
                    $st = strval($row['status'] ?? '');
                    if ($st === 'pending') {
                        $nextStep = 'WAIT FOR APPROVAL';
                    } elseif ($st === 'rejected') {
                        $nextStep = 'REJECTED';
                    } elseif ($st === 'approved') {
                        $nextStep = 'CHECK ACCOUNT';
                    }
                }

                $code = '';
                if ($repCreated === 'YES' && intval($row['rep_requires_password_reset'] ?? 0) === 1) {
                    $code = strval($row['rep_first_time_code'] ?? '');
                }

                fputcsv($fp, [
                    $row['signup_id'] ?? '',
                    $row['username'] ?? '',
                    $row['full_name'] ?? '',
                    $row['class_name'] ?? '',
                    $row['status'] ?? '',
                    $row['created_at'] ?? '',
                    $row['approved_at'] ?? '',
                    $row['approved_by_username'] ?? '',
                    $repCreated,
                    $repActive,
                    $repReset,
                    $codeStatus,
                    $canLogin,
                    $nextStep,
                    $code,
                    $row['rep_first_time_code_expires'] ?? '',
                    $row['momo_number'] ?? '',
                    $row['bank_name'] ?? '',
                    $row['account_name'] ?? '',
                    $row['account_number'] ?? ''
                ]);
            }
        }

        fclose($fp);
    }
} catch (Throwable $e) {
    // Ignore onboarding export failures so mysqldump backup still succeeds
}

$backupFileSql = $conn->real_escape_string($backupFile);
$conn->query("UPDATE system_state
             SET last_backup_at = NOW(),
                 last_backup_file = '{$backupFileSql}',
                 backup_in_progress = 0,
                 backup_started_at = NULL
             WHERE id = 1");

echo "Backup created: {$backupFile}\n";
