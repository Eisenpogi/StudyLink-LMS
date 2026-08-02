<?php
include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');
include('../../includes/academic_term.php');
include('../../includes/module_workflow.php');

require_post('/studyLink/faculty/modules.php');
require_csrf('/studyLink/faculty/modules.php');

$faculty_id = studylink_faculty_id($conn, intval($_SESSION['user_id']));
$module_id = intval($_POST['module_id'] ?? 0);
$selection = explode(':', (string) ($_POST['existing_resource'] ?? ''), 2);
$resource_type = $selection[0] ?? '';
$resource_id = intval($selection[1] ?? 0);
$module = studylink_faculty_module($conn, $module_id, $faculty_id);

if (!$module || $resource_type !== 'quiz' || $resource_id <= 0) {
    studylink_module_redirect($module_id, 'error', 'Select a valid existing quiz.');
}

$table = 'quizzes';
$id_column = 'quiz_id';
$legacy_column = 'legacy_quiz_id';
$stmt = mysqli_prepare($conn, '
    SELECT src.id, src.title, src.instructions, src.class_assignment_id,
           cmr.id AS module_resource_id, cmr.module_item_id, cmi.module_id AS old_module_id,
           cmi.item_type AS old_item_type
    FROM ' . $table . ' src
    INNER JOIN class_assignments ca ON ca.id = src.class_assignment_id AND ca.faculty_id = ?
    INNER JOIN course_module_classes target_class ON target_class.class_assignment_id = ca.id AND target_class.module_id = ?
    LEFT JOIN course_module_resources cmr ON cmr.' . $id_column . ' = src.id
    LEFT JOIN course_module_items cmi ON cmi.id = cmr.module_item_id
    WHERE src.id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($stmt, 'iii', $faculty_id, $module_id, $resource_id);
mysqli_stmt_execute($stmt);
$source = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$source || !studylink_class_is_writable($conn, intval($source['class_assignment_id']))) {
    studylink_module_redirect($module_id, 'error', 'This coursework is unavailable or belongs to a read-only Academic Term.');
}
if (intval($source['old_module_id']) === $module_id) {
    studylink_module_redirect($module_id, 'success', 'This coursework is already inside the module.');
}

mysqli_begin_transaction($conn);
try {
    $stmt = mysqli_prepare($conn, 'SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM course_module_items WHERE module_id = ? FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'i', $module_id);
    mysqli_stmt_execute($stmt);
    $order_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $display_order = intval($order_row['next_order'] ?? 1);
    mysqli_stmt_close($stmt);

    $item_type = (($source['old_item_type'] ?? '') === 'exam' ? 'exam' : 'quiz');
    $legacy_id = empty($source['module_item_id']) ? $resource_id : null;
    $source_title = (string) $source['title'];
    $source_instructions = (string) ($source['instructions'] ?? '');
    $stmt = mysqli_prepare($conn, 'INSERT INTO course_module_items (module_id, item_type, title, instructions, ' . $legacy_column . ', display_order) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'isssii', $module_id, $item_type, $source_title, $source_instructions, $legacy_id, $display_order);
    if (!mysqli_stmt_execute($stmt)) throw new Exception('module item');
    $new_item_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if (!empty($source['module_resource_id'])) {
        $stmt = mysqli_prepare($conn, 'UPDATE course_module_resources SET module_item_id = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'ii', $new_item_id, $source['module_resource_id']);
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO course_module_resources (module_item_id, class_assignment_id, ' . $id_column . ') VALUES (?, ?, ?)');
        $class_id = intval($source['class_assignment_id']);
        mysqli_stmt_bind_param($stmt, 'iii', $new_item_id, $class_id, $resource_id);
    }
    if (!mysqli_stmt_execute($stmt)) throw new Exception('module resource');
    mysqli_stmt_close($stmt);

    if (!empty($source['module_item_id'])) {
        $old_item_id = intval($source['module_item_id']);
        $stmt = mysqli_prepare($conn, 'DELETE cmi FROM course_module_items cmi LEFT JOIN course_module_resources cmr ON cmr.module_item_id = cmi.id WHERE cmi.id = ? AND cmr.id IS NULL');
        mysqli_stmt_bind_param($stmt, 'i', $old_item_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    mysqli_commit($conn);
    studylink_module_redirect($module_id, 'success', ucfirst($resource_type) . ' moved into this module. Existing records were preserved.');
} catch (Throwable $error) {
    mysqli_rollback($conn);
    studylink_module_redirect($module_id, 'error', 'The coursework could not be moved. No records were changed.');
}
