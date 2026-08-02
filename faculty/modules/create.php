<?php
include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');
include('../../includes/academic_term.php');
include('../../includes/module_workflow.php');

require_post('/studyLink/faculty/modules.php');
require_csrf('/studyLink/faculty/modules.php');

$faculty_id = studylink_faculty_id($conn, intval($_SESSION['user_id']));
$title = trim((string) ($_POST['title'] ?? ''));
$description = trim((string) ($_POST['description'] ?? ''));
$class_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['class_ids'] ?? []))));

if (!studylink_modules_ready($conn)) redirect_with_flash('/studyLink/faculty/modules.php', 'error', 'Import sql/module_workflow.sql first.');
if ($faculty_id <= 0 || $title === '' || !$class_ids) redirect_with_flash('/studyLink/faculty/modules.php', 'error', 'Enter a module name and select at least one class.');

$stmt = mysqli_prepare($conn, "
    SELECT ca.id
    FROM class_assignments ca
    INNER JOIN academic_terms term ON term.academic_year_id = ca.academic_year_id AND term.semester_id = ca.semester_id
    WHERE ca.faculty_id = ? AND term.status = 'current'
");
mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$owned = [];
while ($row = mysqli_fetch_assoc($result)) {
    $owned[] = intval($row['id']);
}
mysqli_stmt_close($stmt);
foreach ($class_ids as $class_id) {
    if (!in_array($class_id, $owned, true)) {
        redirect_with_flash('/studyLink/faculty/modules.php', 'error', 'One or more selected classes are not writable.');
    }
}

mysqli_begin_transaction($conn);
try {
    $stmt = mysqli_prepare($conn, 'INSERT INTO course_modules (faculty_id, title, description) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'iss', $faculty_id, $title, $description);
    if (!mysqli_stmt_execute($stmt)) throw new Exception('module');
    $module_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, 'INSERT INTO course_module_classes (module_id, class_assignment_id, class_order) VALUES (?, ?, ?)');
    foreach ($class_ids as $index => $class_id) {
        $order = $index + 1;
        mysqli_stmt_bind_param($stmt, 'iii', $module_id, $class_id, $order);
        if (!mysqli_stmt_execute($stmt)) throw new Exception('class');
    }
    mysqli_stmt_close($stmt);
    mysqli_commit($conn);
    redirect_with_flash('/studyLink/faculty/module_view.php?id=' . $module_id, 'success', 'Module created. Add its learning activities and assessments.');
} catch (Throwable $error) {
    mysqli_rollback($conn);
    redirect_with_flash('/studyLink/faculty/modules.php', 'error', 'The module could not be created.');
}
