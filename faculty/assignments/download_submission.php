<?php

include '../../auth/auth.php';
require_role('faculty');
include '../../config/database.php';

$submission_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = intval($_SESSION['user_id']);

$statement = mysqli_prepare($conn, '
    SELECT asm.file_path, asm.file_name, asm.mime_type
    FROM assignment_submissions asm
    INNER JOIN assignments a ON a.id = asm.assignment_id
    INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
    INNER JOIN faculty f ON f.id = ca.faculty_id
    WHERE asm.id = ? AND f.user_id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($statement, 'ii', $submission_id, $user_id);
mysqli_stmt_execute($statement);
$submission = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$submission) {
    http_response_code(403);
    exit('Submission not found or access denied.');
}

$project_root = realpath(__DIR__ . '/../..');
$submission_root = realpath($project_root . '/assets/uploads/submissions');
$physical_path = realpath($project_root . '/' . ltrim($submission['file_path'], '/'));

if (!$physical_path || !$submission_root ||
    strpos($physical_path, $submission_root . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($physical_path)) {
    http_response_code(404);
    exit('Submission file not found.');
}

$download_name = str_replace(["\r", "\n", '"'], '', basename($submission['file_name']));
header('Content-Type: ' . ($submission['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($physical_path));
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('X-Content-Type-Options: nosniff');
readfile($physical_path);
exit;
