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
$item_type = (string) ($_POST['item_type'] ?? '');
$title = trim((string) ($_POST['title'] ?? ''));
$instructions = trim((string) ($_POST['instructions'] ?? ''));
$class_ids = array_values(array_unique(array_filter(array_map('intval', $_POST['class_ids'] ?? []))));
$module = studylink_faculty_module($conn, $module_id, $faculty_id);
$allowed_types = ['activity', 'quiz', 'exam'];

if (!$module || !in_array($item_type, $allowed_types, true) || $title === '' || !$class_ids) {
    studylink_module_redirect($module_id, 'error', 'Complete the required fields and select at least one class.');
}

$module_class_ids = studylink_module_class_ids($conn, $module_id);
foreach ($class_ids as $class_id) {
    if (!in_array($class_id, $module_class_ids, true) || !studylink_class_is_writable($conn, $class_id)) {
        studylink_module_redirect($module_id, 'error', 'One or more selected classes are invalid or read-only.');
    }
}

$faculty_drive_item_id = intval($_POST['faculty_drive_item_id'] ?? 0);
if ($item_type === 'activity' && $faculty_drive_item_id > 0) {
    $stmt = mysqli_prepare($conn, 'SELECT id FROM faculty_drive_items WHERE id = ? AND faculty_id = ? AND item_type = "file" AND storage_scope = "library" AND file_path IS NOT NULL LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'ii', $faculty_drive_item_id, $faculty_id);
    mysqli_stmt_execute($stmt);
    $valid_file = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$valid_file) studylink_module_redirect($module_id, 'error', 'The selected Faculty Drive file is invalid.');
}

$due_date = null;
$available_from = null;
$available_until = null;
$time_limit = max(0, intval($_POST['time_limit'] ?? 0));
if ($item_type === 'activity') {
    $timestamp = strtotime((string) ($_POST['due_date'] ?? ''));
    if ($timestamp === false) studylink_module_redirect($module_id, 'error', 'Enter a valid activity due date.');
    $due_date = date('Y-m-d H:i:s', $timestamp);
} else {
    $from_input = trim((string) ($_POST['available_from'] ?? ''));
    $until_input = trim((string) ($_POST['available_until'] ?? ''));
    if ($from_input !== '') {
        $timestamp = strtotime($from_input);
        if ($timestamp === false) studylink_module_redirect($module_id, 'error', 'The opening date is invalid.');
        $available_from = date('Y-m-d H:i:s', $timestamp);
    }
    if ($until_input !== '') {
        $timestamp = strtotime($until_input);
        if ($timestamp === false) studylink_module_redirect($module_id, 'error', 'The deadline is invalid.');
        $available_until = date('Y-m-d H:i:s', $timestamp);
    }
    if ($available_from && $available_until && strtotime($available_from) >= strtotime($available_until)) {
        studylink_module_redirect($module_id, 'error', 'The deadline must be later than the opening date.');
    }
}

mysqli_begin_transaction($conn);
try {
    $stmt = mysqli_prepare($conn, 'SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM course_module_items WHERE module_id = ? FOR UPDATE');
    mysqli_stmt_bind_param($stmt, 'i', $module_id);
    mysqli_stmt_execute($stmt);
    $order_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $display_order = intval($order_row['next_order']);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, 'INSERT INTO course_module_items (module_id, item_type, title, instructions, display_order) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'isssi', $module_id, $item_type, $title, $instructions, $display_order);
    if (!mysqli_stmt_execute($stmt)) throw new Exception('item');
    $item_id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    foreach ($class_ids as $class_id) {
        if ($item_type === 'activity') {
            $drive_id = $faculty_drive_item_id > 0 ? $faculty_drive_item_id : null;
            $stmt = mysqli_prepare($conn, 'INSERT INTO assignments (class_assignment_id, faculty_drive_item_id, title, instructions, due_date) VALUES (?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'iisss', $class_id, $drive_id, $title, $instructions, $due_date);
            if (!mysqli_stmt_execute($stmt)) throw new Exception('assignment');
            $assignment_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, 'INSERT INTO course_module_resources (module_item_id, class_assignment_id, assignment_id) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'iii', $item_id, $class_id, $assignment_id);
        } else {
            $stmt = mysqli_prepare($conn, 'INSERT INTO quizzes (class_assignment_id, title, instructions, time_limit, available_from, available_until, status) VALUES (?, ?, ?, ?, ?, ?, "draft")');
            mysqli_stmt_bind_param($stmt, 'ississ', $class_id, $title, $instructions, $time_limit, $available_from, $available_until);
            if (!mysqli_stmt_execute($stmt)) throw new Exception('quiz');
            $quiz_id = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, 'INSERT INTO course_module_resources (module_item_id, class_assignment_id, quiz_id) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'iii', $item_id, $class_id, $quiz_id);
        }
        if (!mysqli_stmt_execute($stmt)) throw new Exception('resource');
        mysqli_stmt_close($stmt);
    }

    mysqli_commit($conn);
    $message = ucfirst($item_type) . ' created for ' . count($class_ids) . ' class' . (count($class_ids) === 1 ? '.' : 'es.');
    studylink_module_redirect($module_id, 'success', $message);
} catch (Throwable $error) {
    mysqli_rollback($conn);
    studylink_module_redirect($module_id, 'error', 'The module item could not be created. No partial records were saved.');
}
