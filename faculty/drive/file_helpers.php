<?php

function cleanupModuleDriveItem($conn, $drive_item_id, $faculty_id)
{
    $drive_item_id = intval($drive_item_id);
    $faculty_id = intval($faculty_id);

    if ($drive_item_id <= 0 || $faculty_id <= 0) {
        return true;
    }

    $item_query = mysqli_query($conn, "
        SELECT id, file_path
        FROM faculty_drive_items
        WHERE id = '$drive_item_id'
          AND faculty_id = '$faculty_id'
          AND item_type = 'file'
          AND storage_scope = 'module'
        LIMIT 1
    ");

    $item = mysqli_fetch_assoc($item_query);

    if (!$item) {
        return true;
    }

    $reference_query = mysqli_query($conn, "
        SELECT
            (
                SELECT COUNT(*)
                FROM learning_materials
                WHERE faculty_drive_item_id = '$drive_item_id'
            ) +
            (
                SELECT COUNT(*)
                FROM assignments
                WHERE faculty_drive_item_id = '$drive_item_id'
            ) AS reference_count
    ");

    $reference_data = mysqli_fetch_assoc($reference_query);

    if (intval($reference_data['reference_count'] ?? 0) > 0) {
        return true;
    }

    $delete_item = mysqli_query($conn, "
        DELETE FROM faculty_drive_items
        WHERE id = '$drive_item_id'
          AND faculty_id = '$faculty_id'
          AND storage_scope = 'module'
    ");

    if (!$delete_item) {
        return false;
    }

    $relative_path = ltrim($item['file_path'] ?? '', '/');
    $project_root = realpath(__DIR__ . '/../../');
    $upload_root = realpath(
        $project_root . '/assets/uploads/faculty_drive/' . $faculty_id
    );

    if ($relative_path === '' || !$project_root || !$upload_root) {
        return true;
    }

    $relative_path = preg_replace('#^studyLink/#i', '', $relative_path);
    $physical_path = realpath(
        $project_root . DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $relative_path)
    );

    if (
        $physical_path &&
        strpos($physical_path, $upload_root . DIRECTORY_SEPARATOR) === 0 &&
        is_file($physical_path) &&
        !unlink($physical_path)
    ) {
        return false;
    }

    return true;
}
