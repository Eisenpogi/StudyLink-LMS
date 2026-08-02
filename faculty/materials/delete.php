<?php

include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');
include('../../includes/academic_term.php');
include('../drive/file_helpers.php');

require_post('../classes.php');
require_csrf('../classes.php');

$material_id = intval($_POST['id'] ?? 0);
$user_id = intval($_SESSION['user_id']);

$material_query = mysqli_query($conn, "
    SELECT lm.id, lm.class_assignment_id, lm.faculty_drive_item_id,
           lm.file_name, f.id AS faculty_id
    FROM learning_materials lm
    INNER JOIN class_assignments ca ON ca.id = lm.class_assignment_id
    INNER JOIN faculty f ON f.id = ca.faculty_id
    WHERE lm.id = '$material_id'
      AND f.user_id = '$user_id'
    LIMIT 1
");

$material = mysqli_fetch_assoc($material_query);

if (!$material) {
    header('Location: ../classes.php');
    exit();
}

$class_assignment_id = intval($material['class_assignment_id']);
$faculty_id = intval($material['faculty_id']);

if (!studylink_class_is_writable($conn, $class_assignment_id)) {
    redirect_with_flash(
        "../class_view.php?id={$class_assignment_id}&tab=materials",
        'error',
        'This Academic Term is read-only. Existing materials are preserved and cannot be deleted.'
    );
}

mysqli_begin_transaction($conn);

$deleted = mysqli_query($conn, "
    DELETE FROM learning_materials
    WHERE id = '$material_id'
");

if (!$deleted) {
    mysqli_rollback($conn);

    header(
        "Location: ../class_view.php?id={$class_assignment_id}" .
        "&tab=materials&error=database"
    );
    exit();
}

if (!empty($material['faculty_drive_item_id'])) {
    $cleaned = cleanupModuleDriveItem(
        $conn,
        intval($material['faculty_drive_item_id']),
        $faculty_id
    );

    if (!$cleaned) {
        mysqli_rollback($conn);

        header(
            "Location: ../class_view.php?id={$class_assignment_id}" .
            "&tab=materials&error=delete_failed"
        );
        exit();
    }
} elseif (!empty($material['file_name'])) {
    $legacy_path = '../../assets/uploads/materials/' . basename($material['file_name']);

    if (file_exists($legacy_path) && !unlink($legacy_path)) {
        mysqli_rollback($conn);

        header(
            "Location: ../class_view.php?id={$class_assignment_id}" .
            "&tab=materials&error=delete_failed"
        );
        exit();
    }
}

mysqli_commit($conn);

header(
    "Location: ../class_view.php?id={$class_assignment_id}&tab=materials&" .
    "success=deleted"
);
exit();
