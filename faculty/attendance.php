<?php
include('../auth/auth.php');
require_role('faculty');
include('../config/database.php');
include('../includes/faculty_ui.php');
include('../includes/academic_term.php');

require_post('/studyLink/faculty/classes.php');

$class_id = intval($_POST['class_id'] ?? 0);
$attendance_date = trim($_POST['attendance_date'] ?? '');
$redirect = '/studyLink/faculty/class_view.php?id=' . $class_id . '&tab=attendance';
require_csrf($redirect);

$parsed_date = DateTime::createFromFormat('!Y-m-d', $attendance_date);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendance_date) || !$parsed_date || $parsed_date->format('Y-m-d') !== $attendance_date) {
    set_flash('error', 'Select a valid attendance date.');
    redirect_to($redirect);
}

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
if (!$faculty) {
    http_response_code(403);
    exit('Faculty profile not found.');
}
$faculty_id = intval($faculty['id']);

$table_check = mysqli_query($conn, "SHOW TABLES LIKE 'attendance_sessions'");
$record_check = mysqli_query($conn, "SHOW TABLES LIKE 'attendance_records'");
if (!$table_check || mysqli_num_rows($table_check) === 0 || !$record_check || mysqli_num_rows($record_check) === 0) {
    set_flash('error', 'Attendance is not installed yet. Import sql/add_attendance.sql once.');
    redirect_to($redirect);
}

$class_statement = mysqli_prepare($conn, '
    SELECT ca.section_id
    FROM class_assignments ca
    WHERE ca.id = ? AND ca.faculty_id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($class_statement, 'ii', $class_id, $faculty_id);
mysqli_stmt_execute($class_statement);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($class_statement));
mysqli_stmt_close($class_statement);
if (!$class) {
    http_response_code(403);
    exit('Class not found or unauthorized access.');
}

if (!studylink_class_is_writable($conn, $class_id)) {
    set_flash('error', 'This Academic Term is read-only. Attendance can only be updated in the current term.');
    redirect_to($redirect);
}

$section_id = intval($class['section_id']);
$valid_students = [];
$student_statement = mysqli_prepare($conn, 'SELECT id FROM students WHERE section_id = ?');
mysqli_stmt_bind_param($student_statement, 'i', $section_id);
mysqli_stmt_execute($student_statement);
$student_result = mysqli_stmt_get_result($student_statement);
while ($student = mysqli_fetch_assoc($student_result)) {
    $valid_students[intval($student['id'])] = true;
}
mysqli_stmt_close($student_statement);

$submitted_statuses = $_POST['status'] ?? [];
$submitted_remarks = $_POST['remarks'] ?? [];
$allowed_statuses = ['present', 'late', 'absent', 'excused'];
$session_title = trim($_POST['session_title'] ?? 'Class Session');
$session_title = $session_title !== '' ? substr($session_title, 0, 120) : 'Class Session';

mysqli_begin_transaction($conn);
try {
    $session_statement = mysqli_prepare($conn, '
        INSERT INTO attendance_sessions (class_assignment_id, attendance_date, session_title, created_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE session_title = VALUES(session_title), created_by = VALUES(created_by), id = LAST_INSERT_ID(id)
    ');
    mysqli_stmt_bind_param($session_statement, 'issi', $class_id, $attendance_date, $session_title, $faculty_id);
    if (!mysqli_stmt_execute($session_statement)) {
        throw new RuntimeException('Unable to save attendance session.');
    }
    $session_id = mysqli_insert_id($conn);
    mysqli_stmt_close($session_statement);

    if ($session_id <= 0) {
        $find_session = mysqli_prepare($conn, 'SELECT id FROM attendance_sessions WHERE class_assignment_id = ? AND attendance_date = ? LIMIT 1');
        mysqli_stmt_bind_param($find_session, 'is', $class_id, $attendance_date);
        mysqli_stmt_execute($find_session);
        $session_row = mysqli_fetch_assoc(mysqli_stmt_get_result($find_session));
        $session_id = intval($session_row['id'] ?? 0);
        mysqli_stmt_close($find_session);
    }
    if ($session_id <= 0) {
        throw new RuntimeException('Unable to resolve attendance session.');
    }

    $record_statement = mysqli_prepare($conn, '
        INSERT INTO attendance_records (attendance_session_id, student_id, status, remarks)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), marked_at = CURRENT_TIMESTAMP
    ');

    foreach ($submitted_statuses as $student_id_raw => $status_raw) {
        $student_id = intval($student_id_raw);
        $status = strtolower(trim((string) $status_raw));
        if (!isset($valid_students[$student_id]) || !in_array($status, $allowed_statuses, true)) {
            continue;
        }
        $remarks = trim((string) ($submitted_remarks[$student_id] ?? ''));
        $remarks = $remarks === '' ? null : substr($remarks, 0, 255);
        mysqli_stmt_bind_param($record_statement, 'iiss', $session_id, $student_id, $status, $remarks);
        if (!mysqli_stmt_execute($record_statement)) {
            throw new RuntimeException('Unable to save an attendance record.');
        }
    }
    mysqli_stmt_close($record_statement);
    mysqli_commit($conn);
    set_flash('success', 'Attendance saved for ' . date('F j, Y', strtotime($attendance_date)) . '.');
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    set_flash('error', 'Attendance could not be saved. Please try again.');
}

redirect_to($redirect . '&date=' . rawurlencode($attendance_date));
