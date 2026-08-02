<?php
include('../auth/auth.php');
require_role('faculty');
include('../config/database.php');
include('../includes/faculty_ui.php');
include('../includes/academic_term.php');

require_post('/studyLink/faculty/classes.php');
$class_id = intval($_POST['class_id'] ?? 0);
$redirect = '/studyLink/faculty/class_view.php?id=' . $class_id;
require_csrf($redirect);

if (!faculty_cover_column_ready($conn) || !faculty_color_column_ready($conn)) {
    redirect_to($redirect . '&cover=failed');
}

$stmt = mysqli_prepare($conn, '
    SELECT ca.cover_image, ca.cover_color
    FROM class_assignments ca
    INNER JOIN faculty f ON f.id = ca.faculty_id
    WHERE ca.id = ? AND f.user_id = ?
    LIMIT 1
');
$user_id = intval($_SESSION['user_id']);
mysqli_stmt_bind_param($stmt, 'ii', $class_id, $user_id);
mysqli_stmt_execute($stmt);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$class) {
    http_response_code(403);
    exit('Class not found or unauthorized access.');
}

if (!studylink_class_is_writable($conn, $class_id)) {
    set_flash('error', 'This Academic Term is read-only. Course appearance can only be changed in the current term.');
    redirect_to($redirect);
}

function remove_course_cover_file($path)
{
    $path = ltrim(str_replace('\\', '/', (string) $path), '/');
    if ($path === '' || strpos($path, 'assets/uploads/course_covers/') !== 0 || strpos($path, '..') !== false) {
        return;
    }
    $absolute = dirname(__DIR__) . '/' . $path;
    if (is_file($absolute)) {
        unlink($absolute);
    }
}

$action = $_POST['action'] ?? '';

if ($action === 'save_color') {
    $color = faculty_sanitize_course_color($_POST['cover_color'] ?? '');
    if ($color === '') {
        redirect_to($redirect . '&cover=failed&appearance=1');
    }

    $stmt = mysqli_prepare($conn, 'UPDATE class_assignments SET cover_color = ? WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'si', $color, $class_id);
    $saved = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    redirect_to($redirect . '&cover=' . ($saved ? 'color_saved' : 'failed'));
}

if ($action === 'remove_image' || isset($_POST['remove_cover'])) {
    remove_course_cover_file($class['cover_image']);
    $stmt = mysqli_prepare($conn, 'UPDATE class_assignments SET cover_image = NULL WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $class_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    redirect_to($redirect . '&cover=removed');
}

if ($action !== 'save_image' && !isset($_FILES['cover_image'])) {
    redirect_to($redirect . '&cover=failed&appearance=1');
}

if (!isset($_FILES['cover_image']) || $_FILES['cover_image']['error'] === UPLOAD_ERR_NO_FILE) {
    redirect_to($redirect . '&cover=missing&appearance=1');
}

$file = $_FILES['cover_image'];
if ($file['error'] !== UPLOAD_ERR_OK || intval($file['size']) <= 0) {
    redirect_to($redirect . '&cover=failed&appearance=1');
}
if (intval($file['size']) > 3 * 1024 * 1024) {
    redirect_to($redirect . '&cover=large&appearance=1');
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
if ($finfo) {
    finfo_close($finfo);
}
$allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($extension, $allowed_extensions, true) || !in_array($mime, $allowed_mimes, true)) {
    redirect_to($redirect . '&cover=type&appearance=1');
}

$directory = dirname(__DIR__) . '/assets/uploads/course_covers/';
if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
    redirect_to($redirect . '&cover=failed&appearance=1');
}

try {
    $token = bin2hex(random_bytes(12));
} catch (Exception $exception) {
    $token = uniqid('cover_', true);
}
$filename = 'class_' . $class_id . '_' . $token . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
$destination = $directory . $filename;
if (!move_uploaded_file($file['tmp_name'], $destination)) {
    redirect_to($redirect . '&cover=failed&appearance=1');
}

$relative_path = 'assets/uploads/course_covers/' . $filename;
$stmt = mysqli_prepare($conn, 'UPDATE class_assignments SET cover_image = ? WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'si', $relative_path, $class_id);
$saved = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

if (!$saved) {
    @unlink($destination);
    redirect_to($redirect . '&cover=failed&appearance=1');
}

remove_course_cover_file($class['cover_image']);
redirect_to($redirect . '&cover=saved');
