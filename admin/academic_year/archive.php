<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/academic_term.php';

require_post('index.php');
require_csrf('index.php');

$academic_year_id = intval($_POST['academic_year_id'] ?? 0);
$semester_id = intval($_POST['semester_id'] ?? 0);
$action = trim($_POST['term_action'] ?? '');

try {
    if ($action === 'archive') {
        $term = studylink_archive_academic_term(
            $conn,
            $academic_year_id,
            $semester_id,
            intval($_SESSION['user_id'] ?? 0)
        );
        redirect_with_flash(
            'index.php#term-history',
            'success',
            $term['semester_name'] . ', AY ' . $term['school_year'] .
            ' is now archived and read-only for Faculty and Student.'
        );
    }

    if ($action === 'restore') {
        studylink_restore_academic_term($conn, $academic_year_id, $semester_id);
        redirect_with_flash(
            'index.php#term-history',
            'success',
            'The academic term is available again. Set it as current to allow academic changes.'
        );
    }

    throw new RuntimeException('Invalid academic term action.');
} catch (Throwable $exception) {
    error_log('Academic term archive action failed: ' . $exception->getMessage());
    redirect_with_flash(
        'index.php#term-history',
        'error',
        $exception->getMessage()
    );
}
