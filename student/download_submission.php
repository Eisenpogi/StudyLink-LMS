<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';

$submission_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$user_id = intval($_SESSION['user_id']);

$statement = mysqli_prepare($conn, '
    SELECT asm.file_path, asm.file_name, asm.mime_type
    FROM assignment_submissions asm
    INNER JOIN students s ON s.id = asm.student_id
    INNER JOIN assignments a ON a.id = asm.assignment_id
    INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
    WHERE asm.id = ? AND s.user_id = ? AND s.section_id = ca.section_id
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

header('Location: preview_file.php?type=submission&id=' . $submission_id);
exit;
