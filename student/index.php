<?php

if (session_status() === PHP_SESSION_NONE) {
    session_name('STUDYLINK_STUDENT_SESSID');
    session_start();
}

if (($_SESSION['role'] ?? '') === 'student') {
    header('Location: /studyLink/student/dashboard.php');
    exit();
}

$error_messages = [
    'required' => 'Please enter your student number and password.',
    'invalid_credentials' => 'Invalid student number or password.',
    'login_required' => 'Please sign in to continue.',
    'session_expired' => 'Your session expired. Please sign in again.',
    'system' => 'Unable to sign in right now. Please try again.'
];

$error_code = $_GET['error'] ?? '';
$error_message = $error_messages[$error_code] ?? '';
$page_title = 'Student Login';
$portal = 'student';
$role_label = 'Student';
$username_label = 'Student Number';
$username_placeholder = 'e.g. S00-0000';
$username_icon = 'person-vcard';

require '../includes/login-template.php';
