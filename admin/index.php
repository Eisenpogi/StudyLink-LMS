<?php

if (session_status() === PHP_SESSION_NONE) {
    session_name('STUDYLINK_ADMIN_SESSID');
    session_start();
}

if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: /studyLink/admin/dashboard.php');
    exit();
}

$error_messages = [
    'required' => 'Please enter your username and password.',
    'invalid_credentials' => 'Invalid username or password.',
    'login_required' => 'Please sign in to continue.',
    'session_expired' => 'Your session expired. Please sign in again.',
    'system' => 'Unable to sign in right now. Please try again.'
];

$error_code = $_GET['error'] ?? '';
$error_message = $error_messages[$error_code] ?? '';
$page_title = 'Admin Login';
$portal = 'admin';
$role_label = 'Admin';
$username_label = 'Admin Username';
$username_placeholder = 'Enter admin username';
$username_icon = 'person-gear';

require '../includes/login-template.php';
