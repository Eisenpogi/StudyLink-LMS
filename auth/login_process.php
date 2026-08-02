<?php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /studyLink/index.php');
    exit();
}

include '../config/database.php';

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$portal = $_POST['portal'] ?? '';
$allowed_portals = ['admin', 'faculty', 'student'];

if (!in_array($portal, $allowed_portals, true)) {
    header('Location: /studyLink/index.php?error=invalid_portal');
    exit();
}

$login_page = '/studyLink/' . $portal . '/index.php';
if ($username === '' || $password === '') {
    header('Location: ' . $login_page . '?error=required');
    exit();
}

$statement = mysqli_prepare($conn, 'SELECT id, fullname, username, password, role FROM users WHERE username = ? AND status = ? LIMIT 1');
if (!$statement) {
    error_log('StudyLink login prepare failed: ' . mysqli_error($conn));
    header('Location: ' . $login_page . '?error=system');
    exit();
}

$active_status = 'active';
mysqli_stmt_bind_param($statement, 'ss', $username, $active_status);
mysqli_stmt_execute($statement);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

$password_valid = false;
$legacy_password = false;
if ($user) {
    $stored_password = (string) $user['password'];
    $password_info = password_get_info($stored_password);
    if (!empty($password_info['algo'])) {
        $password_valid = password_verify($password, $stored_password);
    } else {
        $password_valid = hash_equals($stored_password, $password);
        $legacy_password = $password_valid;
    }
}

if (!$user || !$password_valid || $user['role'] !== $portal) {
    header('Location: ' . $login_page . '?error=invalid_credentials');
    exit();
}

if ($legacy_password || password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
    $new_password_hash = password_hash($password, PASSWORD_DEFAULT);
    $update_statement = mysqli_prepare($conn, 'UPDATE users SET password = ? WHERE id = ?');
    if ($update_statement) {
        $user_id = intval($user['id']);
        mysqli_stmt_bind_param($update_statement, 'si', $new_password_hash, $user_id);
        mysqli_stmt_execute($update_statement);
        mysqli_stmt_close($update_statement);
    } else {
        error_log('StudyLink password migration prepare failed: ' . mysqli_error($conn));
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $session_names = [
        'admin' => 'STUDYLINK_ADMIN_SESSID',
        'faculty' => 'STUDYLINK_FACULTY_SESSID',
        'student' => 'STUDYLINK_STUDENT_SESSID'
    ];
    ini_set('session.gc_maxlifetime', '43200');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name($session_names[$portal]);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

session_regenerate_id(true);
$_SESSION['user_id'] = intval($user['id']);
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role'] = $user['role'];
$_SESSION['last_activity'] = time();

$destinations = [
    'admin' => '/studyLink/admin/dashboard.php',
    'faculty' => '/studyLink/faculty/dashboard.php',
    'student' => '/studyLink/student/dashboard.php'
];
header('Location: ' . $destinations[$portal]);
exit();
