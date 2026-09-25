<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/course_catalog.php';

require_post('index.php');
require_csrf('index.php');

$id = intval($_POST['id'] ?? 0);
if ($id <= 0 || !ensure_course_catalog_schema($conn)) {
    redirect_with_flash('index.php', 'error', 'Invalid course.');
}

$usage = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM sections WHERE course_id = ?');
mysqli_stmt_bind_param($usage, 'i', $id);
mysqli_stmt_execute($usage);
$section_count = intval(mysqli_fetch_assoc(mysqli_stmt_get_result($usage))['total'] ?? 0);
mysqli_stmt_close($usage);

if ($section_count > 0) {
    redirect_with_flash('index.php', 'error', 'This course is in use. Disable it instead of deleting it.');
}

$statement = mysqli_prepare($conn, 'DELETE FROM courses WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
$deleted = mysqli_stmt_execute($statement);
mysqli_stmt_close($statement);

redirect_with_flash('index.php', $deleted ? 'success' : 'error', $deleted ? 'Unused course deleted.' : 'Unable to delete the course.');

