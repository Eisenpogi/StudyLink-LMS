<?php
include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');

require_post('index.php');
require_csrf('index.php');

function folder_upload_redirect($folder_id, $query)
{
    $url = 'index.php';
    if ($folder_id !== null) {
        $url .= '?folder=' . intval($folder_id);
        $url .= '&' . ltrim($query, '?&');
    } else {
        $url .= '?' . ltrim($query, '?&');
    }
    header('Location: ' . $url);
    exit();
}

function clean_folder_segment($segment, $maximum_length)
{
    $segment = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $segment));
    if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > $maximum_length) {
        return '';
    }
    return $segment;
}

function uploaded_folder_files($files, $relative_paths)
{
    $normalized = [];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $normalized;
    }
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => intval($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => intval($files['size'][$index] ?? 0),
            'relative_path' => $relative_paths[$index] ?? ($files['full_path'][$index] ?? $name)
        ];
    }
    return $normalized;
}

$user_id = intval($_SESSION['user_id']);
$faculty_statement = mysqli_prepare($conn, 'SELECT id FROM faculty WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($faculty_statement, 'i', $user_id);
mysqli_stmt_execute($faculty_statement);
$faculty = mysqli_fetch_assoc(mysqli_stmt_get_result($faculty_statement));
mysqli_stmt_close($faculty_statement);
if (!$faculty) {
    http_response_code(403);
    exit('Faculty record not found.');
}
$faculty_id = intval($faculty['id']);

$folder_id = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? intval($_POST['folder_id']) : null;
if ($folder_id !== null) {
    $parent_statement = mysqli_prepare($conn, 'SELECT id FROM faculty_drive_folders WHERE id = ? AND faculty_id = ? LIMIT 1');
    mysqli_stmt_bind_param($parent_statement, 'ii', $folder_id, $faculty_id);
    mysqli_stmt_execute($parent_statement);
    $valid_parent = mysqli_fetch_assoc(mysqli_stmt_get_result($parent_statement));
    mysqli_stmt_close($parent_statement);
    if (!$valid_parent) {
        folder_upload_redirect(null, 'error=invalid_parent');
    }
}

$files = uploaded_folder_files($_FILES['folder_files'] ?? [], $_POST['relative_paths'] ?? []);
if (!$files) {
    folder_upload_redirect($folder_id, 'error=no_file');
}
if (count($files) > 200) {
    folder_upload_redirect($folder_id, 'error=too_many_files');
}

$allowed_extensions = ['pdf','doc','docx','xls','xlsx','csv','ppt','pptx','txt','rtf','jpg','jpeg','png','gif','webp','zip','rar','7z'];
$allowed_mime_types = [
    'application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','text/csv','text/plain',
    'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/rtf','text/rtf','image/jpeg','image/png','image/gif','image/webp',
    'application/zip','application/x-zip-compressed','application/x-rar-compressed','application/vnd.rar',
    'application/x-7z-compressed','application/octet-stream'
];
$maximum_file_size = 100 * 1024 * 1024;
$validated = [];
$incoming_size = 0;
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if (!$finfo) {
    folder_upload_redirect($folder_id, 'error=upload_failed');
}

foreach ($files as $file) {
    if ($file['error'] !== UPLOAD_ERR_OK || $file['tmp_name'] === '' || !is_uploaded_file($file['tmp_name'])) {
        finfo_close($finfo);
        folder_upload_redirect($folder_id, 'error=upload_failed');
    }
    if ($file['size'] <= 0 || $file['size'] > $maximum_file_size) {
        finfo_close($finfo);
        folder_upload_redirect($folder_id, 'error=file_too_large');
    }

    $relative_path = str_replace('\\', '/', trim((string) $file['relative_path']));
    $raw_segments = array_values(array_filter(explode('/', $relative_path), 'strlen'));
    if (count($raw_segments) < 2) {
        finfo_close($finfo);
        folder_upload_redirect($folder_id, 'error=invalid_folder');
    }

    $file_name = clean_folder_segment(array_pop($raw_segments), 255);
    $folders = [];
    foreach ($raw_segments as $segment) {
        $clean = clean_folder_segment($segment, 150);
        if ($clean === '') {
            finfo_close($finfo);
            folder_upload_redirect($folder_id, 'error=invalid_folder');
        }
        $folders[] = $clean;
    }
    if ($file_name === '') {
        finfo_close($finfo);
        folder_upload_redirect($folder_id, 'error=invalid_folder');
    }

    $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $mime_type = finfo_file($finfo, $file['tmp_name']) ?: '';
    if (!in_array($extension, $allowed_extensions, true) || !in_array($mime_type, $allowed_mime_types, true)) {
        finfo_close($finfo);
        folder_upload_redirect($folder_id, 'error=invalid_type');
    }

    $incoming_size += $file['size'];
    $validated[] = [
        'tmp_name' => $file['tmp_name'],
        'size' => $file['size'],
        'name' => $file_name,
        'extension' => $extension,
        'mime_type' => $mime_type,
        'folders' => $folders
    ];
}
finfo_close($finfo);

$storage_statement = mysqli_prepare($conn, 'SELECT COALESCE(SUM(file_size), 0) AS used_storage FROM faculty_drive_items WHERE faculty_id = ? AND item_type = "file"');
mysqli_stmt_bind_param($storage_statement, 'i', $faculty_id);
mysqli_stmt_execute($storage_statement);
$storage = mysqli_fetch_assoc(mysqli_stmt_get_result($storage_statement));
mysqli_stmt_close($storage_statement);
if (intval($storage['used_storage'] ?? 0) + $incoming_size > 1024 * 1024 * 1024) {
    folder_upload_redirect($folder_id, 'error=storage_limit');
}

$upload_directory = '../../assets/uploads/faculty_drive/' . $faculty_id . '/';
if (!is_dir($upload_directory) && !mkdir($upload_directory, 0775, true)) {
    folder_upload_redirect($folder_id, 'error=folder_failed');
}

$moved_files = [];
$folder_cache = [];
mysqli_begin_transaction($conn);
try {
    $find_folder = mysqli_prepare($conn, '
        SELECT id FROM faculty_drive_folders
        WHERE faculty_id = ? AND folder_name = ? AND ((parent_id IS NULL AND ? IS NULL) OR parent_id = ?)
        LIMIT 1
    ');
    $insert_folder = mysqli_prepare($conn, 'INSERT INTO faculty_drive_folders (faculty_id, parent_id, folder_name) VALUES (?, ?, ?)');
    $insert_file = mysqli_prepare($conn, '
        INSERT INTO faculty_drive_items
            (faculty_id, folder_id, item_type, storage_scope, reference_id, item_name, file_path, original_filename, mime_type, file_size)
        VALUES (?, ?, "file", "library", NULL, ?, ?, ?, ?, ?)
    ');

    foreach ($validated as $file) {
        $current_parent = $folder_id;
        foreach ($file['folders'] as $folder_name) {
            $cache_key = ($current_parent === null ? 'root' : $current_parent) . '|' . strtolower($folder_name);
            if (isset($folder_cache[$cache_key])) {
                $current_parent = $folder_cache[$cache_key];
                continue;
            }

            mysqli_stmt_bind_param($find_folder, 'isii', $faculty_id, $folder_name, $current_parent, $current_parent);
            mysqli_stmt_execute($find_folder);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($find_folder));
            if ($existing) {
                $current_parent = intval($existing['id']);
            } else {
                mysqli_stmt_bind_param($insert_folder, 'iis', $faculty_id, $current_parent, $folder_name);
                if (!mysqli_stmt_execute($insert_folder)) {
                    throw new RuntimeException('Unable to create folder.');
                }
                $current_parent = intval(mysqli_insert_id($conn));
            }
            $folder_cache[$cache_key] = $current_parent;
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable $exception) {
            $token = uniqid('file_', true);
        }
        $stored_filename = $token . '.' . $file['extension'];
        $destination = $upload_directory . $stored_filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to move uploaded file.');
        }
        $moved_files[] = $destination;
        $public_path = '/studyLink/assets/uploads/faculty_drive/' . $faculty_id . '/' . $stored_filename;
        $item_name = $file['name'];
        $original_filename = $file['name'];
        $mime_type = $file['mime_type'];
        $file_size = $file['size'];

        mysqli_stmt_bind_param(
            $insert_file,
            'iissssi',
            $faculty_id,
            $current_parent,
            $item_name,
            $public_path,
            $original_filename,
            $mime_type,
            $file_size
        );
        if (!mysqli_stmt_execute($insert_file)) {
            throw new RuntimeException('Unable to save uploaded file.');
        }
    }

    mysqli_stmt_close($find_folder);
    mysqli_stmt_close($insert_folder);
    mysqli_stmt_close($insert_file);
    mysqli_commit($conn);
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    foreach ($moved_files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    folder_upload_redirect($folder_id, 'error=upload_failed');
}

folder_upload_redirect($folder_id, 'folder_uploaded=1&count=' . count($validated));
