<?php

include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');
include('../../includes/academic_term.php');
include('../drive/file_helpers.php');

require_post('../classes.php');
require_csrf('../classes.php');

$assignment_id = intval($_POST['id'] ?? 0);
$user_id = intval($_SESSION['user_id']);

$assignment_query = mysqli_query($conn, "
    SELECT
        a.id,
        a.class_assignment_id,
        a.faculty_drive_item_id,
        f.id AS faculty_id
    FROM assignments a
    INNER JOIN class_assignments ca
        ON ca.id = a.class_assignment_id
    INNER JOIN faculty f
        ON f.id = ca.faculty_id
    WHERE a.id = '$assignment_id'
      AND f.user_id = '$user_id'
    LIMIT 1
");

$assignment = mysqli_fetch_assoc($assignment_query);

if (!$assignment) {
    header('Location: ../classes.php');
    exit();
}

$class_assignment_id =
    intval($assignment['class_assignment_id']);

$faculty_id = intval($assignment['faculty_id']);

if (!studylink_class_is_writable($conn, $class_assignment_id)) {
    redirect_with_flash(
        "../class_view.php?id={$class_assignment_id}&tab=assignments",
        'error',
        'This Academic Term is read-only. Existing assignments and submissions are preserved.'
    );
}

mysqli_begin_transaction($conn);

$deleted = mysqli_query($conn, "
    DELETE FROM assignments
    WHERE id = '$assignment_id'
");

if (!$deleted) {
    mysqli_rollback($conn);

    header(
        "Location: ../class_view.php?id={$class_assignment_id}" .
        "&tab=assignments&error=database"
    );
    exit();
}

$cleaned = cleanupModuleDriveItem(
    $conn,
    intval($assignment['faculty_drive_item_id']),
    $faculty_id
);

if (!$cleaned) {
    mysqli_rollback($conn);

    header(
        "Location: ../class_view.php?id={$class_assignment_id}" .
        "&tab=assignments&error=delete_failed"
    );
    exit();
}

mysqli_commit($conn);

header(
    "Location: ../class_view.php?id={$class_assignment_id}" .
    "&tab=assignments&success=deleted"
);

exit();
