<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/ui_icons.php';

$user_id = intval($_SESSION['user_id']);
$file_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$file_type = $_GET['type'] ?? '';
$allowed_types = ['material', 'assignment', 'submission'];

if ($file_id <= 0 || !in_array($file_type, $allowed_types, true)) {
    http_response_code(400);
    exit('Invalid file request.');
}

if ($file_type === 'material') {
    $statement = mysqli_prepare($conn, '
        SELECT fdi.file_path,
               COALESCE(NULLIF(fdi.original_filename, ""), lm.file_name) AS file_name,
               fdi.mime_type, fdi.file_size, lm.title,
               sub.subject_code, sub.subject_name
        FROM learning_materials lm
        LEFT JOIN faculty_drive_items fdi
          ON fdi.id = lm.faculty_drive_item_id
        INNER JOIN class_assignments ca
          ON ca.id = lm.class_assignment_id
        INNER JOIN subjects sub
          ON sub.id = ca.subject_id
        INNER JOIN students s
          ON s.section_id = ca.section_id AND s.user_id = ?
        WHERE lm.id = ? AND fdi.item_type = "file"
        LIMIT 1
    ');
} elseif ($file_type === 'assignment') {
    $statement = mysqli_prepare($conn, '
        SELECT fdi.file_path, fdi.original_filename AS file_name,
               fdi.mime_type, fdi.file_size, a.title,
               sub.subject_code, sub.subject_name
        FROM assignments a
        INNER JOIN faculty_drive_items fdi
          ON fdi.id = a.faculty_drive_item_id
        INNER JOIN class_assignments ca
          ON ca.id = a.class_assignment_id
        INNER JOIN subjects sub
          ON sub.id = ca.subject_id
        INNER JOIN students s
          ON s.section_id = ca.section_id AND s.user_id = ?
        WHERE a.id = ? AND fdi.item_type = "file"
        LIMIT 1
    ');
} else {
    $statement = mysqli_prepare($conn, '
        SELECT asm.file_path, asm.file_name, asm.mime_type, asm.file_size,
               a.title, sub.subject_code, sub.subject_name
        FROM assignment_submissions asm
        INNER JOIN students s
          ON s.id = asm.student_id AND s.user_id = ?
        INNER JOIN assignments a
          ON a.id = asm.assignment_id
        INNER JOIN class_assignments ca
          ON ca.id = a.class_assignment_id
             AND ca.section_id = s.section_id
        INNER JOIN subjects sub
          ON sub.id = ca.subject_id
        WHERE asm.id = ?
        LIMIT 1
    ');
}

mysqli_stmt_bind_param($statement, 'ii', $user_id, $file_id);
mysqli_stmt_execute($statement);
$file = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$file || empty($file['file_path'])) {
    http_response_code(403);
    exit('File not found or access denied.');
}

$project_root = realpath(__DIR__ . '/..');

function preview_normalize_path($path)
{
    $path = rawurldecode((string) $path);
    $url_path = parse_url($path, PHP_URL_PATH);
    if (is_string($url_path) && $url_path !== '') {
        $path = $url_path;
    }
    return str_replace('\\', '/', trim($path));
}

function preview_path_is_inside($path, $root)
{
    if (!$path || !$root) return false;
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || strpos($path, $root . '/') === 0;
}

function preview_resolve_physical_path($stored_path, $project_root, $allowed_relatives)
{
    if (!$project_root) return null;

    $normalized = preview_normalize_path($stored_path);
    $relative = ltrim($normalized, '/');
    $project_name = basename($project_root);

    if (stripos($relative, $project_name . '/') === 0) {
        $relative = substr($relative, strlen($project_name) + 1);
    } elseif (stripos($relative, 'studyLink/') === 0) {
        $relative = substr($relative, strlen('studyLink/'));
    }

    $uploads_position = stripos($relative, 'assets/uploads/');
    if ($uploads_position !== false) {
        $relative = substr($relative, $uploads_position);
    }

    $candidates = [$project_root . '/' . $relative];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/' . ltrim($normalized, '/');
    }
    if (preg_match('/^[A-Za-z]:\//', $normalized) || strpos($normalized, '/') === 0) {
        $candidates[] = $normalized;
    }

    $allowed_roots = [];
    foreach ($allowed_relatives as $allowed_relative) {
        $allowed_root = realpath($project_root . '/' . $allowed_relative);
        if ($allowed_root) $allowed_roots[] = $allowed_root;
    }

    foreach (array_unique($candidates) as $candidate) {
        $resolved = realpath($candidate);
        if (!$resolved || !is_file($resolved)) continue;
        foreach ($allowed_roots as $allowed_root) {
            if (preview_path_is_inside($resolved, $allowed_root)) return $resolved;
        }
    }

    return null;
}

$allowed_root_relatives = $file_type === 'submission'
    ? ['assets/uploads/submissions']
    : [
        'assets/uploads/faculty_drive',
        'assets/uploads/materials',
        'assets/uploads/assignments'
    ];
$physical_path = preview_resolve_physical_path(
    $file['file_path'],
    $project_root,
    $allowed_root_relatives
);

if (!$physical_path) {
    http_response_code(404);
    exit('The file record exists, but the uploaded file is missing from this StudyLink installation. Please ask the faculty member to re-upload the attachment.');
}

$file_name = str_replace(["\r", "\n", '"'], '', basename($file['file_name'] ?: 'download'));
$extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
$mime_type = trim($file['mime_type'] ?? '') ?: 'application/octet-stream';
$previewable_mimes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'text/plain'
];
$is_previewable = in_array($mime_type, $previewable_mimes, true);
$is_image = strpos($mime_type, 'image/') === 0;
$is_pdf = $mime_type === 'application/pdf';

function stream_student_file($physical_path, $mime_type, $file_name, $inline)
{
    $size = filesize($physical_path);
    $start = 0;
    $end = $size - 1;
    $status = 200;

    if (!empty($_SERVER['HTTP_RANGE']) &&
        preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
        if ($matches[1] !== '') {
            $start = intval($matches[1]);
        }
        if ($matches[2] !== '') {
            $end = min(intval($matches[2]), $end);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }

    http_response_code($status);
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $file_name . '"');
    header('Accept-Ranges: bytes');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (($end - $start) + 1));
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $handle = fopen($physical_path, 'rb');
    if (!$handle) {
        http_response_code(500);
        exit('Unable to open the file.');
    }
    fseek($handle, $start);
    $remaining = ($end - $start) + 1;
    while (!feof($handle) && $remaining > 0) {
        $chunk = fread($handle, min(8192, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
    exit;
}

if (isset($_GET['download']) && $file_type === 'submission') {
    http_response_code(403);
    exit('Submitted files are available for preview only.');
}

if (isset($_GET['download'])) {
    stream_student_file($physical_path, $mime_type, $file_name, false);
}

if (isset($_GET['stream'])) {
    if (!$is_previewable) {
        http_response_code(415);
        exit('Inline preview is not available for this file type.');
    }
    stream_student_file($physical_path, $mime_type, $file_name, true);
}

function preview_file_size($bytes)
{
    $bytes = intval($bytes);
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

$stream_url = 'preview_file.php?type=' . urlencode($file_type) . '&id=' . $file_id . '&stream=1';
$download_url = 'preview_file.php?type=' . urlencode($file_type) . '&id=' . $file_id . '&download=1';
$back_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/studyLink/student/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($file_name); ?> - StudyLink Preview</title>
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
</head>
<body class="file-preview-page">
<header class="file-preview-topbar">
    <div class="file-preview-identity">
        <a class="file-preview-back" href="<?php echo htmlspecialchars($back_url); ?>" aria-label="Back">
            <?php echo study_icon('arrow-left'); ?>
        </a>
        <div class="file-preview-type"><?php echo htmlspecialchars(strtoupper($extension ?: 'FILE')); ?></div>
        <div>
            <strong><?php echo htmlspecialchars($file_name); ?></strong>
            <small><?php echo htmlspecialchars($file['subject_code']); ?> · <?php echo preview_file_size($file['file_size'] ?? filesize($physical_path)); ?></small>
        </div>
    </div>
    <?php if ($file_type !== 'submission'): ?>
        <a class="button file-preview-download" href="<?php echo htmlspecialchars($download_url); ?>">
            <?php echo study_icon('download'); ?> Download
        </a>
    <?php endif; ?>
</header>
<main class="file-preview-stage">
    <?php if ($is_pdf): ?>
        <iframe class="file-preview-frame" src="<?php echo htmlspecialchars($stream_url); ?>#toolbar=1&navpanes=0" title="PDF preview"></iframe>
    <?php elseif ($is_image): ?>
        <img class="file-preview-image" src="<?php echo htmlspecialchars($stream_url); ?>" alt="<?php echo htmlspecialchars($file_name); ?>">
    <?php elseif ($mime_type === 'text/plain'): ?>
        <iframe class="file-preview-frame text-frame" src="<?php echo htmlspecialchars($stream_url); ?>" title="Text file preview"></iframe>
    <?php else: ?>
        <section class="file-preview-unavailable">
            <div class="file-preview-large-type"><?php echo htmlspecialchars(strtoupper($extension ?: 'FILE')); ?></div>
            <h1><?php echo htmlspecialchars($file['title'] ?: $file_name); ?></h1>
            <?php if ($file_type === 'submission'): ?>
                <p>The file was uploaded successfully, but this file type does not support an in-browser preview. Its filename and upload record are still shown above for verification.</p>
            <?php else: ?>
                <p>This file type cannot be displayed directly in the browser. Download it to open it in the appropriate application.</p>
                <a class="button" href="<?php echo htmlspecialchars($download_url); ?>">
                    <?php echo study_icon('download'); ?> Download <?php echo htmlspecialchars(strtoupper($extension ?: 'file')); ?>
                </a>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
