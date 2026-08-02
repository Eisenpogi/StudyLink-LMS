<?php

include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');
include('../../includes/academic_term.php');

require_post('../classes.php');

$class_assignment_id = intval($_POST['class_assignment_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$attachment_source = $_POST['attachment_source'] ?? 'upload';
$user_id = intval($_SESSION['user_id']);

$redirect = "../class_view.php?id={$class_assignment_id}&tab=materials";
require_csrf($redirect);

if ($class_assignment_id <= 0 || $title === '') {
    header("Location: {$redirect}&error=required");
    exit();
}

$faculty_query = mysqli_query($conn, "
    SELECT f.id
    FROM faculty f
    INNER JOIN class_assignments ca ON ca.faculty_id = f.id
    WHERE f.user_id = '$user_id'
      AND ca.id = '$class_assignment_id'
    LIMIT 1
");

$faculty = mysqli_fetch_assoc($faculty_query);

if (!$faculty) {
    header('Location: ../classes.php');
    exit();
}

if (!studylink_class_is_writable($conn, $class_assignment_id)) {
    redirect_with_flash(
        $redirect,
        'error',
        'This Academic Term is read-only. Materials can only be added to the current term.'
    );
}

$faculty_id = intval($faculty['id']);
$escaped_title = mysqli_real_escape_string($conn, $title);
$escaped_description = mysqli_real_escape_string($conn, $description);

if ($attachment_source === 'drive') {
    $drive_item_id = intval($_POST['faculty_drive_item_id'] ?? 0);

    $item_query = mysqli_query($conn, "
        SELECT id, original_filename, item_name
        FROM faculty_drive_items
        WHERE id = '$drive_item_id'
          AND faculty_id = '$faculty_id'
          AND item_type = 'file'
          AND storage_scope = 'library'
          AND file_path IS NOT NULL
        LIMIT 1
    ");

    $item = mysqli_fetch_assoc($item_query);

    if (!$item) {
        header("Location: {$redirect}&error=invalid_drive_file");
        exit();
    }

    $legacy_file_name = $item['original_filename'] ?: $item['item_name'];
    $escaped_file_name = mysqli_real_escape_string($conn, $legacy_file_name);

    $insert = mysqli_query($conn, "
        INSERT INTO learning_materials
        (class_assignment_id, faculty_drive_item_id, title, description, file_name)
        VALUES
        ('$class_assignment_id', '$drive_item_id', '$escaped_title', '$escaped_description', '$escaped_file_name')
    ");

    header("Location: {$redirect}&" . ($insert ? 'success=created' : 'error=database'));
    exit();
}

if (
    !isset($_FILES['material_file']) ||
    $_FILES['material_file']['error'] === UPLOAD_ERR_NO_FILE
) {
    header("Location: {$redirect}&error=no_file");
    exit();
}

$file = $_FILES['material_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    header("Location: {$redirect}&error=upload_failed");
    exit();
}

$original_filename = basename($file['name']);
$file_size = intval($file['size']);
$temp_path = $file['tmp_name'];
$maximum_file_size = 100 * 1024 * 1024;

if ($file_size <= 0 || $file_size > $maximum_file_size) {
    header("Location: {$redirect}&error=file_too_large");
    exit();
}

$allowed_extensions = [
    'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
    'txt', 'rtf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'rar', '7z'
];

$extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));

if (!in_array($extension, $allowed_extensions, true)) {
    header("Location: {$redirect}&error=invalid_type");
    exit();
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = $finfo ? finfo_file($finfo, $temp_path) : false;

if ($finfo) {
    finfo_close($finfo);
}

$allowed_mime_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv',
    'text/plain',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/rtf',
    'text/rtf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/zip',
    'application/x-zip-compressed',
    'application/x-rar-compressed',
    'application/vnd.rar',
    'application/x-7z-compressed',
    'application/octet-stream'
];

if (!$mime_type || !in_array($mime_type, $allowed_mime_types, true)) {
    header("Location: {$redirect}&error=invalid_type");
    exit();
}

$storage_query = mysqli_query($conn, "
    SELECT COALESCE(SUM(file_size), 0) AS used_storage
    FROM faculty_drive_items
    WHERE faculty_id = '$faculty_id'
      AND item_type = 'file'
");

$storage_data = mysqli_fetch_assoc($storage_query);
$used_storage = intval($storage_data['used_storage']);
$storage_limit = 1024 * 1024 * 1024;

if (($used_storage + $file_size) > $storage_limit) {
    header("Location: {$redirect}&error=storage_limit");
    exit();
}

try {
    $random_name = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $random_name = uniqid('file_', true);
}

$stored_filename = $random_name . '.' . $extension;
$upload_directory = '../../assets/uploads/faculty_drive/' . $faculty_id . '/';

if (!is_dir($upload_directory) && !mkdir($upload_directory, 0775, true)) {
    header("Location: {$redirect}&error=folder_failed");
    exit();
}

$destination_path = $upload_directory . $stored_filename;
$public_file_path = '/studyLink/assets/uploads/faculty_drive/' . $faculty_id . '/' . $stored_filename;

if (!move_uploaded_file($temp_path, $destination_path)) {
    header("Location: {$redirect}&error=upload_failed");
    exit();
}

$escaped_original_filename = mysqli_real_escape_string($conn, $original_filename);
$escaped_file_path = mysqli_real_escape_string($conn, $public_file_path);
$escaped_mime_type = mysqli_real_escape_string($conn, $mime_type);

mysqli_begin_transaction($conn);

$drive_insert = mysqli_query($conn, "
    INSERT INTO faculty_drive_items
    (faculty_id, folder_id, item_type, storage_scope, reference_id, item_name, file_path, original_filename, mime_type, file_size)
    VALUES
    ('$faculty_id', NULL, 'file', 'module', NULL, '$escaped_original_filename', '$escaped_file_path',
     '$escaped_original_filename', '$escaped_mime_type', '$file_size')
");

$drive_item_id = mysqli_insert_id($conn);

$material_insert = $drive_insert && mysqli_query($conn, "
    INSERT INTO learning_materials
    (class_assignment_id, faculty_drive_item_id, title, description, file_name)
    VALUES
    ('$class_assignment_id', '$drive_item_id', '$escaped_title', '$escaped_description', '$escaped_original_filename')
");

if (!$drive_insert || !$material_insert) {
    mysqli_rollback($conn);

    if (file_exists($destination_path)) {
        unlink($destination_path);
    }

    header("Location: {$redirect}&error=database");
    exit();
}

mysqli_commit($conn);

header("Location: {$redirect}&success=created");
exit();
