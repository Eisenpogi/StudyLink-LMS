<?php

include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');

require_post('index.php');

$folder_id = null;

if (isset($_POST['folder_id']) && $_POST['folder_id'] !== '') {
    $folder_id = intval($_POST['folder_id']);
}

$redirect_url = 'index.php';

if ($folder_id !== null) {
    $redirect_url .= '?folder=' . $folder_id;
}

$separator = strpos($redirect_url, '?') !== false ? '&' : '?';

require_csrf($redirect_url);

$item_id = intval($_POST['item_id'] ?? 0);
$user_id = intval($_SESSION['user_id']);

if ($item_id <= 0) {
    header("Location: {$redirect_url}{$separator}error=invalid_request");
    exit();
}

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

$item_query = mysqli_query($conn, "
    SELECT id, folder_id, file_path
    FROM faculty_drive_items
    WHERE id = '$item_id'
    AND faculty_id = '$faculty_id'
    AND item_type = 'file'
    AND storage_scope = 'library'
    LIMIT 1
");

$item = mysqli_fetch_assoc($item_query);

if (!$item) {
    header("Location: {$redirect_url}{$separator}error=file_not_found");
    exit();
}

$reference_query = mysqli_query($conn, "
    SELECT
        (
            SELECT COUNT(*)
            FROM learning_materials
            WHERE faculty_drive_item_id = '$item_id'
        ) +
        (
            SELECT COUNT(*)
            FROM assignments
            WHERE faculty_drive_item_id = '$item_id'
        ) AS reference_count
");

$reference_data = mysqli_fetch_assoc($reference_query);

if (intval($reference_data['reference_count'] ?? 0) > 0) {
    header("Location: {$redirect_url}{$separator}error=file_in_use");
    exit();
}

$actual_folder_id = $item['folder_id'] !== null
    ? intval($item['folder_id'])
    : null;

if ($actual_folder_id !== $folder_id) {
    $redirect_url = 'index.php';

    if ($actual_folder_id !== null) {
        $redirect_url .= '?folder=' . $actual_folder_id;
    }

    $separator = strpos($redirect_url, '?') !== false ? '&' : '?';
}

$relative_path = ltrim($item['file_path'], '/');
$project_root = realpath(__DIR__ . '/../../');
$physical_path = $project_root . DIRECTORY_SEPARATOR . str_replace(
    '/',
    DIRECTORY_SEPARATOR,
    preg_replace('#^studyLink/#i', '', $relative_path)
);

mysqli_begin_transaction($conn);

$delete_query = mysqli_query($conn, "
    DELETE FROM faculty_drive_items
    WHERE id = '$item_id'
    AND faculty_id = '$faculty_id'
    AND item_type = 'file'
");

if (!$delete_query) {
    mysqli_rollback($conn);

    header("Location: {$redirect_url}{$separator}error=delete_failed");
    exit();
}

if (
    file_exists($physical_path) &&
    is_file($physical_path) &&
    !unlink($physical_path)
) {
    mysqli_rollback($conn);

    header("Location: {$redirect_url}{$separator}error=delete_failed");
    exit();
}

mysqli_commit($conn);

header("Location: {$redirect_url}{$separator}deleted=1");
exit();
