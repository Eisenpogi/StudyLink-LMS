<?php

if (!function_exists('studylink_ensure_academic_terms')) {
    function studylink_ensure_academic_terms($conn)
    {
        static $ready = false;

        if ($ready) {
            return true;
        }

        $table_sql = "
            CREATE TABLE IF NOT EXISTS academic_terms (
                id INT NOT NULL AUTO_INCREMENT,
                academic_year_id INT NOT NULL,
                semester_id INT NOT NULL,
                status ENUM('available', 'current', 'archived') NOT NULL DEFAULT 'available',
                first_activated_at DATETIME NULL,
                last_activated_at DATETIME NULL,
                archived_at DATETIME NULL,
                activated_by INT NULL,
                archived_by INT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY academic_terms_unique_pair (academic_year_id, semester_id),
                KEY academic_terms_status_index (status),
                CONSTRAINT academic_terms_year_fk
                    FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
                CONSTRAINT academic_terms_semester_fk
                    FOREIGN KEY (semester_id) REFERENCES semesters (id),
                CONSTRAINT academic_terms_activated_by_fk
                    FOREIGN KEY (activated_by) REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT academic_terms_archived_by_fk
                    FOREIGN KEY (archived_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        if (!mysqli_query($conn, $table_sql)) {
            error_log('Academic term registry setup failed: ' . mysqli_error($conn));
            return false;
        }

        $seed_classes = "
            INSERT IGNORE INTO academic_terms (academic_year_id, semester_id, status)
            SELECT DISTINCT academic_year_id, semester_id, 'available'
            FROM class_assignments
        ";
        if (!mysqli_query($conn, $seed_classes)) {
            error_log('Academic term class-pair seed failed: ' . mysqli_error($conn));
            return false;
        }

        $current_count_result = mysqli_query(
            $conn,
            "SELECT COUNT(*) AS total FROM academic_terms WHERE status = 'current'"
        );
        $current_count = $current_count_result
            ? intval(mysqli_fetch_assoc($current_count_result)['total'] ?? 0)
            : 0;

        if ($current_count === 0) {
            $legacy_current = "
                INSERT INTO academic_terms (
                    academic_year_id,
                    semester_id,
                    status,
                    first_activated_at,
                    last_activated_at
                )
                SELECT ay.id, sem.id, 'current', NOW(), NOW()
                FROM academic_years ay
                CROSS JOIN semesters sem
                WHERE ay.status = 'active' AND sem.status = 'active'
                ORDER BY ay.id DESC, sem.id DESC
                LIMIT 1
                ON DUPLICATE KEY UPDATE
                    status = 'current',
                    first_activated_at = COALESCE(first_activated_at, NOW()),
                    last_activated_at = NOW(),
                    archived_at = NULL,
                    archived_by = NULL
            ";
            if (!mysqli_query($conn, $legacy_current)) {
                error_log('Legacy current-term migration failed: ' . mysqli_error($conn));
                return false;
            }
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('studylink_current_term')) {
    function studylink_current_term($conn)
    {
        if (!studylink_ensure_academic_terms($conn)) {
            return null;
        }

        $result = mysqli_query(
            $conn,
            "SELECT
                ay.id AS academic_year_id,
                ay.school_year,
                sem.id AS semester_id,
                sem.semester_name,
                term.id AS academic_term_id,
                term.status AS term_status,
                term.first_activated_at,
                term.last_activated_at
             FROM academic_terms term
             INNER JOIN academic_years ay ON ay.id = term.academic_year_id
             INNER JOIN semesters sem ON sem.id = term.semester_id
             WHERE term.status = 'current'
             ORDER BY term.last_activated_at DESC, term.id DESC
             LIMIT 1"
        );

        if (!$result) {
            error_log('Current academic term lookup failed: ' . mysqli_error($conn));
            return null;
        }

        $term = mysqli_fetch_assoc($result);
        return $term ?: null;
    }
}

if (!function_exists('studylink_activate_academic_term')) {
    function studylink_activate_academic_term($conn, $academic_year_id, $semester_id, $actor_user_id = null)
    {
        $academic_year_id = intval($academic_year_id);
        $semester_id = intval($semester_id);
        $actor_user_id = intval($actor_user_id);

        if ($academic_year_id <= 0 || $semester_id <= 0) {
            throw new InvalidArgumentException('Select both an academic year and a semester.');
        }

        if (!studylink_ensure_academic_terms($conn)) {
            throw new RuntimeException('Unable to initialize the academic term registry.');
        }

        mysqli_begin_transaction($conn);

        try {
            $lock_years = mysqli_query($conn, 'SELECT id FROM academic_years FOR UPDATE');
            $lock_semesters = mysqli_query($conn, 'SELECT id FROM semesters FOR UPDATE');
            $lock_terms = mysqli_query($conn, 'SELECT id FROM academic_terms FOR UPDATE');
            if (!$lock_years || !$lock_semesters || !$lock_terms) {
                throw new RuntimeException('Unable to lock academic term records.');
            }

            $statement = mysqli_prepare(
                $conn,
                'SELECT ay.school_year, sem.semester_name
                 FROM academic_years ay
                 CROSS JOIN semesters sem
                 WHERE ay.id = ? AND sem.id = ?
                 LIMIT 1'
            );
            if (!$statement) {
                throw new RuntimeException('Unable to validate the selected academic term.');
            }

            mysqli_stmt_bind_param($statement, 'ii', $academic_year_id, $semester_id);
            if (!mysqli_stmt_execute($statement)) {
                throw new RuntimeException(mysqli_stmt_error($statement));
            }

            $term = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
            mysqli_stmt_close($statement);
            if (!$term) {
                throw new RuntimeException('The selected academic year or semester no longer exists.');
            }

            $status_statement = mysqli_prepare(
                $conn,
                'SELECT status FROM academic_terms
                 WHERE academic_year_id = ? AND semester_id = ?
                 LIMIT 1'
            );
            if (!$status_statement) {
                throw new RuntimeException('Unable to inspect the selected academic term.');
            }
            mysqli_stmt_bind_param($status_statement, 'ii', $academic_year_id, $semester_id);
            mysqli_stmt_execute($status_statement);
            $status_row = mysqli_fetch_assoc(mysqli_stmt_get_result($status_statement));
            mysqli_stmt_close($status_statement);

            if (($status_row['status'] ?? '') === 'archived') {
                throw new RuntimeException('Restore the archived term before setting it as current.');
            }

            if (!mysqli_query(
                $conn,
                "UPDATE academic_terms
                 SET status = 'available'
                 WHERE status = 'current'"
            )) {
                throw new RuntimeException('Unable to close the previous current term.');
            }

            $term_statement = mysqli_prepare(
                $conn,
                "INSERT INTO academic_terms (
                    academic_year_id,
                    semester_id,
                    status,
                    first_activated_at,
                    last_activated_at,
                    activated_by,
                    archived_at,
                    archived_by
                 )
                 VALUES (?, ?, 'current', NOW(), NOW(), NULLIF(?, 0), NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    status = 'current',
                    first_activated_at = COALESCE(first_activated_at, NOW()),
                    last_activated_at = NOW(),
                    activated_by = NULLIF(VALUES(activated_by), 0),
                    archived_at = NULL,
                    archived_by = NULL"
            );
            if (!$term_statement) {
                throw new RuntimeException('Unable to prepare the current-term record.');
            }
            mysqli_stmt_bind_param(
                $term_statement,
                'iii',
                $academic_year_id,
                $semester_id,
                $actor_user_id
            );
            if (!mysqli_stmt_execute($term_statement)) {
                throw new RuntimeException(mysqli_stmt_error($term_statement));
            }
            mysqli_stmt_close($term_statement);

            if (!mysqli_query($conn, "UPDATE academic_years SET status = 'inactive' WHERE status = 'active'")) {
                throw new RuntimeException('Unable to close the previous academic year.');
            }
            if (!mysqli_query($conn, "UPDATE semesters SET status = 'inactive' WHERE status = 'active'")) {
                throw new RuntimeException('Unable to close the previous semester.');
            }

            $year_statement = mysqli_prepare(
                $conn,
                "UPDATE academic_years SET status = 'active' WHERE id = ?"
            );
            $semester_statement = mysqli_prepare(
                $conn,
                "UPDATE semesters SET status = 'active' WHERE id = ?"
            );
            if (!$year_statement || !$semester_statement) {
                throw new RuntimeException('Unable to prepare the academic term update.');
            }

            mysqli_stmt_bind_param($year_statement, 'i', $academic_year_id);
            mysqli_stmt_bind_param($semester_statement, 'i', $semester_id);
            $year_saved = mysqli_stmt_execute($year_statement);
            $semester_saved = mysqli_stmt_execute($semester_statement);
            mysqli_stmt_close($year_statement);
            mysqli_stmt_close($semester_statement);

            if (!$year_saved || !$semester_saved) {
                throw new RuntimeException('Unable to save the selected academic term.');
            }

            $verify_statement = mysqli_prepare(
                $conn,
                "SELECT
                    (SELECT COUNT(*) FROM academic_years WHERE status = 'active') AS active_year_count,
                    (SELECT COUNT(*) FROM semesters WHERE status = 'active') AS active_semester_count,
                    (SELECT COUNT(*) FROM academic_terms WHERE status = 'current') AS current_term_count,
                    EXISTS(
                        SELECT 1 FROM academic_years
                        WHERE id = ? AND status = 'active'
                    ) AS selected_year_active,
                    EXISTS(
                        SELECT 1 FROM semesters
                        WHERE id = ? AND status = 'active'
                    ) AS selected_semester_active,
                    EXISTS(
                        SELECT 1 FROM academic_terms
                        WHERE academic_year_id = ?
                          AND semester_id = ?
                          AND status = 'current'
                    ) AS selected_term_current,
                    (
                        SELECT COUNT(*)
                        FROM class_assignments
                        WHERE academic_year_id = ? AND semester_id = ?
                    ) AS class_count"
            );
            if (!$verify_statement) {
                throw new RuntimeException('Unable to verify the academic term update.');
            }

            mysqli_stmt_bind_param(
                $verify_statement,
                'iiiiii',
                $academic_year_id,
                $semester_id,
                $academic_year_id,
                $semester_id,
                $academic_year_id,
                $semester_id
            );
            if (!mysqli_stmt_execute($verify_statement)) {
                throw new RuntimeException(mysqli_stmt_error($verify_statement));
            }

            $verification = mysqli_fetch_assoc(mysqli_stmt_get_result($verify_statement));
            mysqli_stmt_close($verify_statement);

            if (
                intval($verification['active_year_count'] ?? 0) !== 1 ||
                intval($verification['active_semester_count'] ?? 0) !== 1 ||
                intval($verification['current_term_count'] ?? 0) !== 1 ||
                intval($verification['selected_year_active'] ?? 0) !== 1 ||
                intval($verification['selected_semester_active'] ?? 0) !== 1 ||
                intval($verification['selected_term_current'] ?? 0) !== 1
            ) {
                throw new RuntimeException('Academic term verification failed.');
            }

            mysqli_commit($conn);

            return [
                'academic_year_id' => $academic_year_id,
                'semester_id' => $semester_id,
                'school_year' => $term['school_year'],
                'semester_name' => $term['semester_name'],
                'status' => 'current',
                'class_count' => intval($verification['class_count'] ?? 0)
            ];
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            throw $exception;
        }
    }
}

if (!function_exists('studylink_archive_academic_term')) {
    function studylink_archive_academic_term($conn, $academic_year_id, $semester_id, $actor_user_id = null)
    {
        $academic_year_id = intval($academic_year_id);
        $semester_id = intval($semester_id);
        $actor_user_id = intval($actor_user_id);

        if ($academic_year_id <= 0 || $semester_id <= 0) {
            throw new InvalidArgumentException('Invalid academic term.');
        }
        if (!studylink_ensure_academic_terms($conn)) {
            throw new RuntimeException('Unable to initialize the academic term registry.');
        }

        mysqli_begin_transaction($conn);
        try {
            $lock = mysqli_prepare(
                $conn,
                'SELECT term.status, ay.school_year, sem.semester_name
                 FROM academic_terms term
                 INNER JOIN academic_years ay ON ay.id = term.academic_year_id
                 INNER JOIN semesters sem ON sem.id = term.semester_id
                 WHERE term.academic_year_id = ? AND term.semester_id = ?
                 FOR UPDATE'
            );
            if (!$lock) {
                throw new RuntimeException('Unable to lock the academic term.');
            }
            mysqli_stmt_bind_param($lock, 'ii', $academic_year_id, $semester_id);
            mysqli_stmt_execute($lock);
            $term = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);

            if (!$term) {
                throw new RuntimeException('The academic term does not exist.');
            }
            if ($term['status'] === 'current') {
                throw new RuntimeException('Set another current term before archiving this term.');
            }

            $update = mysqli_prepare(
                $conn,
                "UPDATE academic_terms
                 SET status = 'archived', archived_at = NOW(), archived_by = NULLIF(?, 0)
                 WHERE academic_year_id = ? AND semester_id = ?"
            );
            if (!$update) {
                throw new RuntimeException('Unable to prepare the archive action.');
            }
            mysqli_stmt_bind_param(
                $update,
                'iii',
                $actor_user_id,
                $academic_year_id,
                $semester_id
            );
            if (!mysqli_stmt_execute($update)) {
                throw new RuntimeException(mysqli_stmt_error($update));
            }
            mysqli_stmt_close($update);
            mysqli_commit($conn);

            return $term;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            throw $exception;
        }
    }
}

if (!function_exists('studylink_restore_academic_term')) {
    function studylink_restore_academic_term($conn, $academic_year_id, $semester_id)
    {
        $academic_year_id = intval($academic_year_id);
        $semester_id = intval($semester_id);

        if (!studylink_ensure_academic_terms($conn)) {
            throw new RuntimeException('Unable to initialize the academic term registry.');
        }

        $statement = mysqli_prepare(
            $conn,
            "UPDATE academic_terms
             SET status = 'available', archived_at = NULL, archived_by = NULL
             WHERE academic_year_id = ? AND semester_id = ? AND status = 'archived'"
        );
        if (!$statement) {
            throw new RuntimeException('Unable to prepare the restore action.');
        }
        mysqli_stmt_bind_param($statement, 'ii', $academic_year_id, $semester_id);
        if (!mysqli_stmt_execute($statement)) {
            throw new RuntimeException(mysqli_stmt_error($statement));
        }
        $changed = mysqli_stmt_affected_rows($statement);
        mysqli_stmt_close($statement);

        if ($changed !== 1) {
            throw new RuntimeException('The archived academic term was not found.');
        }
    }
}

if (!function_exists('studylink_academic_term_records')) {
    function studylink_academic_term_records($conn)
    {
        if (!studylink_ensure_academic_terms($conn)) {
            return [];
        }

        $result = mysqli_query(
            $conn,
            "SELECT
                term.id,
                term.academic_year_id,
                term.semester_id,
                term.status,
                term.first_activated_at,
                term.last_activated_at,
                term.archived_at,
                ay.school_year,
                sem.semester_name,
                COUNT(ca.id) AS class_count
             FROM academic_terms term
             INNER JOIN academic_years ay ON ay.id = term.academic_year_id
             INNER JOIN semesters sem ON sem.id = term.semester_id
             LEFT JOIN class_assignments ca
               ON ca.academic_year_id = term.academic_year_id
              AND ca.semester_id = term.semester_id
             GROUP BY
                term.id,
                term.academic_year_id,
                term.semester_id,
                term.status,
                term.first_activated_at,
                term.last_activated_at,
                term.archived_at,
                ay.school_year,
                sem.semester_name
             ORDER BY
                FIELD(term.status, 'current', 'available', 'archived'),
                ay.school_year DESC,
                sem.id DESC"
        );

        return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('studylink_class_term')) {
    function studylink_class_term($conn, $class_assignment_id)
    {
        $class_assignment_id = intval($class_assignment_id);
        if ($class_assignment_id <= 0 || !studylink_ensure_academic_terms($conn)) {
            return null;
        }

        $statement = mysqli_prepare(
            $conn,
            "SELECT
                ca.id AS class_assignment_id,
                ca.academic_year_id,
                ca.semester_id,
                ay.school_year,
                sem.semester_name,
                COALESCE(term.status, 'available') AS term_status
             FROM class_assignments ca
             INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
             INNER JOIN semesters sem ON sem.id = ca.semester_id
             LEFT JOIN academic_terms term
               ON term.academic_year_id = ca.academic_year_id
              AND term.semester_id = ca.semester_id
             WHERE ca.id = ?
             LIMIT 1"
        );
        if (!$statement) {
            return null;
        }
        mysqli_stmt_bind_param($statement, 'i', $class_assignment_id);
        mysqli_stmt_execute($statement);
        $term = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return $term ?: null;
    }
}

if (!function_exists('studylink_class_is_writable')) {
    function studylink_class_is_writable($conn, $class_assignment_id)
    {
        $term = studylink_class_term($conn, $class_assignment_id);
        return $term && $term['term_status'] === 'current';
    }
}

if (!function_exists('studylink_assignment_class_id')) {
    function studylink_assignment_class_id($conn, $assignment_id)
    {
        $statement = mysqli_prepare(
            $conn,
            'SELECT class_assignment_id FROM assignments WHERE id = ? LIMIT 1'
        );
        if (!$statement) {
            return 0;
        }
        $assignment_id = intval($assignment_id);
        mysqli_stmt_bind_param($statement, 'i', $assignment_id);
        mysqli_stmt_execute($statement);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return intval($row['class_assignment_id'] ?? 0);
    }
}

if (!function_exists('studylink_quiz_class_id')) {
    function studylink_quiz_class_id($conn, $quiz_id)
    {
        $statement = mysqli_prepare(
            $conn,
            'SELECT class_assignment_id FROM quizzes WHERE id = ? LIMIT 1'
        );
        if (!$statement) {
            return 0;
        }
        $quiz_id = intval($quiz_id);
        mysqli_stmt_bind_param($statement, 'i', $quiz_id);
        mysqli_stmt_execute($statement);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return intval($row['class_assignment_id'] ?? 0);
    }
}

if (!function_exists('studylink_attempt_class_id')) {
    function studylink_attempt_class_id($conn, $attempt_id)
    {
        $statement = mysqli_prepare(
            $conn,
            'SELECT q.class_assignment_id
             FROM quiz_attempts qa
             INNER JOIN quizzes q ON q.id = qa.quiz_id
             WHERE qa.id = ?
             LIMIT 1'
        );
        if (!$statement) {
            return 0;
        }
        $attempt_id = intval($attempt_id);
        mysqli_stmt_bind_param($statement, 'i', $attempt_id);
        mysqli_stmt_execute($statement);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return intval($row['class_assignment_id'] ?? 0);
    }
}
