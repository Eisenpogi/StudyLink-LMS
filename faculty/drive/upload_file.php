<?php

include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');

require_post('index.php');
require_csrf('index.php');

/*
|--------------------------------------------------------------------------
| Kunin ang faculty ID
|--------------------------------------------------------------------------
*/

$user_id = intval($_SESSION['user_id']);

$faculty_query = mysqli_query($conn, "
    SELECT id
    FROM faculty
    WHERE user_id = '$user_id'
    LIMIT 1
");

$faculty = mysqli_fetch_assoc($faculty_query);

if (!$faculty) {
    die("Faculty record not found.");
}

$faculty_id = intval($faculty['id']);

/*
|--------------------------------------------------------------------------
| Kunin at i-validate ang folder
|--------------------------------------------------------------------------
*/

$folder_id = null;

if (isset($_POST['folder_id']) && $_POST['folder_id'] !== '') {
    $folder_id = intval($_POST['folder_id']);
}

$redirect_url = 'index.php';

if ($folder_id !== null) {
    $redirect_url .= '?folder=' . $folder_id;
}

$separator = strpos($redirect_url, '?') !== false ? '&' : '?';

if ($folder_id !== null) {

    $folder_query = mysqli_query($conn, "
        SELECT id
        FROM faculty_drive_folders
        WHERE id = '$folder_id'
        AND faculty_id = '$faculty_id'
        LIMIT 1
    ");

    if (mysqli_num_rows($folder_query) === 0) {
        header("Location: index.php?error=invalid_parent");
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| Validate uploaded file
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['drive_file']) ||
    $_FILES['drive_file']['error'] === UPLOAD_ERR_NO_FILE
) {
    header("Location: {$redirect_url}{$separator}error=no_file");
    exit();
}

$file = $_FILES['drive_file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    header("Location: {$redirect_url}{$separator}error=upload_failed");
    exit();
}

$original_filename = basename($file['name']);
$file_size = intval($file['size']);
$temp_path = $file['tmp_name'];

/*
|--------------------------------------------------------------------------
| Maximum size per file: 100 MB
|--------------------------------------------------------------------------
*/

$maximum_file_size = 100 * 1024 * 1024;

if ($file_size <= 0 || $file_size > $maximum_file_size) {
    header("Location: {$redirect_url}{$separator}error=file_too_large");
    exit();
}

/*
|--------------------------------------------------------------------------
| Allowed extensions
|--------------------------------------------------------------------------
*/

$allowed_extensions = [
    'pdf',
    'doc',
    'docx',
    'xls',
    'xlsx',
    'csv',
    'ppt',
    'pptx',
    'txt',
    'rtf',
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    'zip',
    'rar',
    '7z'
];

$extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));

if (!in_array($extension, $allowed_extensions)) {
    header("Location: {$redirect_url}{$separator}error=invalid_type");
    exit();
}

/*
|--------------------------------------------------------------------------
| Detect MIME type
|--------------------------------------------------------------------------
*/

$finfo = finfo_open(FILEINFO_MIME_TYPE);

if (!$finfo) {
    header("Location: {$redirect_url}{$separator}error=upload_failed");
    exit();
}

$mime_type = finfo_file($finfo, $temp_path);
finfo_close($finfo);

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

if (!in_array($mime_type, $allowed_mime_types)) {
    header("Location: {$redirect_url}{$separator}error=invalid_type");
    exit();
}

/*
|--------------------------------------------------------------------------
| Check 1 GB storage limit
|--------------------------------------------------------------------------
*/

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
    header("Location: {$redirect_url}{$separator}error=storage_limit");
    exit();
}

/*
|--------------------------------------------------------------------------
| Gumawa ng secure filename
|--------------------------------------------------------------------------
*/

try {
    $random_name = bin2hex(random_bytes(16));
} catch (Exception $e) {
    $random_name = uniqid('file_', true);
}

$stored_filename = $random_name . '.' . $extension;

/*
|--------------------------------------------------------------------------
| Gumawa ng faculty upload directory
|--------------------------------------------------------------------------
*/

$upload_directory = '../../assets/uploads/faculty_drive/' . $faculty_id . '/';

if (!is_dir($upload_directory)) {

    if (!mkdir($upload_directory, 0775, true)) {
        header("Location: {$redirect_url}{$separator}error=folder_failed");
        exit();
    }
}

$destination_path = $upload_directory . $stored_filename;

/*
|--------------------------------------------------------------------------
| Ilipat ang uploaded file
|--------------------------------------------------------------------------
*/

if (!move_uploaded_file($temp_path, $destination_path)) {
    header("Location: {$redirect_url}{$separator}error=upload_failed");
    exit();
}

/*
|--------------------------------------------------------------------------
| Public path na ise-save sa database
|--------------------------------------------------------------------------
*/

$public_file_path =
    '/studyLink/assets/uploads/faculty_drive/' .
    $faculty_id .
    '/' .
    $stored_filename;

/*
|--------------------------------------------------------------------------
| Escape database values
|--------------------------------------------------------------------------
*/

$escaped_item_name = mysqli_real_escape_string($conn, $original_filename);
$escaped_original_filename = mysqli_real_escape_string($conn, $original_filename);
$escaped_file_path = mysqli_real_escape_string($conn, $public_file_path);
$escaped_mime_type = mysqli_real_escape_string($conn, $mime_type);

/*
|--------------------------------------------------------------------------
| Insert drive item
|--------------------------------------------------------------------------
*/

if ($folder_id === null) {

    $insert_query = mysqli_query($conn, "
        INSERT INTO faculty_drive_items (
            faculty_id,
            folder_id,
            item_type,
            reference_id,
            item_name,
            file_path,
            original_filename,
            mime_type,
            file_size
        ) VALUES (
            '$faculty_id',
            NULL,
            'file',
            NULL,
            '$escaped_item_name',
            '$escaped_file_path',
            '$escaped_original_filename',
            '$escaped_mime_type',
            '$file_size'
        )
    ");

} else {

    $insert_query = mysqli_query($conn, "
        INSERT INTO faculty_drive_items (
            faculty_id,
            folder_id,
            item_type,
            reference_id,
            item_name,
            file_path,
            original_filename,
            mime_type,
            file_size
        ) VALUES (
            '$faculty_id',
            '$folder_id',
            'file',
            NULL,
            '$escaped_item_name',
            '$escaped_file_path',
            '$escaped_original_filename',
            '$escaped_mime_type',
            '$file_size'
        )
    ");
}

/*
|--------------------------------------------------------------------------
| Kapag hindi na-save sa database, burahin ang physical file
|--------------------------------------------------------------------------
*/

if (!$insert_query) {

    if (file_exists($destination_path)) {
        unlink($destination_path);
    }

    header("Location: {$redirect_url}{$separator}error=database");
    exit();
}

header("Location: {$redirect_url}{$separator}uploaded=1");
exit();
