<?php
include '../auth/auth.php';
require_role('admin');
require_post('/studyLink/admin/dashboard.php#access-control');
require_csrf('/studyLink/admin/dashboard.php#access-control');
require_once '../includes/login_appearance.php';

$redirect = '/studyLink/admin/dashboard.php#access-control';
$action = $_POST['action'] ?? 'upload';

if ($action === 'remove') {
    foreach (studylink_login_visual_files() as $file) {
        if (!@unlink($file)) {
            redirect_with_flash($redirect, 'error', 'The current login image could not be removed.');
        }
    }

    redirect_with_flash($redirect, 'success', 'Login image removed. The navy fallback color is now active.');
}

if ($action !== 'upload' || !isset($_FILES['login_visual'])) {
    redirect_with_flash($redirect, 'error', 'Please choose an image to upload.');
}

$upload = $_FILES['login_visual'];
if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $message = ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE ||
        ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_FORM_SIZE
        ? 'The image is larger than the server upload limit.'
        : 'The image upload did not complete. Please try again.';
    redirect_with_flash($redirect, 'error', $message);
}

if (($upload['size'] ?? 0) <= 0 || $upload['size'] > 5 * 1024 * 1024) {
    redirect_with_flash($redirect, 'error', 'Use a JPG, PNG, or WebP image up to 5 MB.');
}

$image_info = @getimagesize($upload['tmp_name']);
$allowed_types = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_WEBP => 'webp'
];

if (!$image_info || !isset($allowed_types[$image_info[2]])) {
    redirect_with_flash($redirect, 'error', 'The selected file is not a supported JPG, PNG, or WebP image.');
}

$directory = studylink_login_visual_directory();
if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
    redirect_with_flash($redirect, 'error', 'The login image folder could not be created.');
}

$extension = $allowed_types[$image_info[2]];
$staged_path = $directory . '/login-visual-upload-' . bin2hex(random_bytes(5)) . '.' . $extension;
$final_path = $directory . '/login-visual.' . $extension;

if (!move_uploaded_file($upload['tmp_name'], $staged_path)) {
    redirect_with_flash($redirect, 'error', 'The server could not save the uploaded image.');
}

foreach (studylink_login_visual_files() as $file) {
    if (!@unlink($file)) {
        @unlink($staged_path);
        redirect_with_flash($redirect, 'error', 'The previous login image could not be replaced.');
    }
}

if (!@rename($staged_path, $final_path)) {
    @unlink($staged_path);
    redirect_with_flash($redirect, 'error', 'The uploaded image could not be activated.');
}

redirect_with_flash($redirect, 'success', 'Login image updated for the Admin, Faculty, and Student portals.');
