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
| Kunin ang submitted values
|--------------------------------------------------------------------------
*/

$folder_name = trim($_POST['folder_name'] ?? '');
$parent_id = null;

if (isset($_POST['parent_id']) && $_POST['parent_id'] !== '') {
    $parent_id = intval($_POST['parent_id']);
}

/*
|--------------------------------------------------------------------------
| Gumawa ng redirect URL
|--------------------------------------------------------------------------
*/

$redirect_url = 'index.php';

if ($parent_id !== null) {
    $redirect_url .= '?folder=' . $parent_id;
}

$separator = strpos($redirect_url, '?') !== false ? '&' : '?';

/*
|--------------------------------------------------------------------------
| Validate folder name
|--------------------------------------------------------------------------
*/

if ($folder_name === '') {
    header("Location: {$redirect_url}{$separator}error=empty_name");
    exit();
}

/*
|--------------------------------------------------------------------------
| Validate parent folder
|--------------------------------------------------------------------------
*/

if ($parent_id !== null) {

    $parent_query = mysqli_query($conn, "
        SELECT id
        FROM faculty_drive_folders
        WHERE id = '$parent_id'
        AND faculty_id = '$faculty_id'
        LIMIT 1
    ");

    if (mysqli_num_rows($parent_query) === 0) {
        header("Location: index.php?error=invalid_parent");
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| Escape folder name
|--------------------------------------------------------------------------
*/

$escaped_folder_name = mysqli_real_escape_string($conn, $folder_name);

/*
|--------------------------------------------------------------------------
| Check duplicate folder name
|--------------------------------------------------------------------------
*/

if ($parent_id === null) {

    $duplicate_query = mysqli_query($conn, "
        SELECT id
        FROM faculty_drive_folders
        WHERE faculty_id = '$faculty_id'
        AND parent_id IS NULL
        AND folder_name = '$escaped_folder_name'
        LIMIT 1
    ");

} else {

    $duplicate_query = mysqli_query($conn, "
        SELECT id
        FROM faculty_drive_folders
        WHERE faculty_id = '$faculty_id'
        AND parent_id = '$parent_id'
        AND folder_name = '$escaped_folder_name'
        LIMIT 1
    ");
}

if (mysqli_num_rows($duplicate_query) > 0) {
    header("Location: {$redirect_url}{$separator}error=duplicate");
    exit();
}

/*
|--------------------------------------------------------------------------
| Insert folder
|--------------------------------------------------------------------------
*/

if ($parent_id === null) {

    $insert_query = mysqli_query($conn, "
        INSERT INTO faculty_drive_folders (
            faculty_id,
            parent_id,
            folder_name
        ) VALUES (
            '$faculty_id',
            NULL,
            '$escaped_folder_name'
        )
    ");

} else {

    $insert_query = mysqli_query($conn, "
        INSERT INTO faculty_drive_folders (
            faculty_id,
            parent_id,
            folder_name
        ) VALUES (
            '$faculty_id',
            '$parent_id',
            '$escaped_folder_name'
        )
    ");
}

/*
|--------------------------------------------------------------------------
| Check insert result
|--------------------------------------------------------------------------
*/

if (!$insert_query) {
    header("Location: {$redirect_url}{$separator}error=database");
    exit();
}

header("Location: {$redirect_url}{$separator}created=1");
exit();
