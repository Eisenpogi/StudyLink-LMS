<?php

if (session_status() === PHP_SESSION_NONE) {
    session_name('STUDYLINK_FACULTY_SESSID');
    session_start();
}

if (($_SESSION['role'] ?? '') === 'faculty') {
    header('Location: /studyLink/faculty/dashboard.php');
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
$page_title = 'Faculty Login';
$portal = 'faculty';
$role_label = 'Faculty';
$username_label = 'Faculty Username';
$username_placeholder = 'Enter faculty username';
$username_icon = 'person-badge';

require '../includes/login-template.php';
