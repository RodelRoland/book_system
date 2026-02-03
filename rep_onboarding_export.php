<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_logged_in']) || ($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin.php');
    exit;
}

$stamp = date('Ymd_His');
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=rep_onboarding_backup_' . $stamp . '.csv');

$output = fopen('php://output', 'w');

// Make Excel interpret UTF-8 correctly
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
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

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
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

        fputcsv($output, [
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

fclose($output);
exit;
