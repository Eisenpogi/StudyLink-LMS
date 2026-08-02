<?php

function studylink_modules_ready($conn)
{
    static $ready = null;
    if ($ready === null) {
        $required = [
            'course_modules',
            'course_module_classes',
            'course_module_items',
            'course_module_resources'
        ];
        $ready = true;
        foreach ($required as $table) {
            $result = mysqli_query($conn, "SHOW TABLES LIKE '" . $table . "'");
            if (!$result || mysqli_num_rows($result) === 0) {
                $ready = false;
                break;
            }
        }
    }
    return $ready;
}

function studylink_sync_legacy_coursework($conn, $faculty_id = 0, $section_id = 0)
{
    if (!studylink_modules_ready($conn)) return false;

    $faculty_id = intval($faculty_id);
    $section_id = intval($section_id);
    $filters = [];
    if ($faculty_id > 0) $filters[] = 'ca.faculty_id = ' . $faculty_id;
    if ($section_id > 0) $filters[] = 'ca.section_id = ' . $section_id;
    $filter_sql = $filters ? ' AND ' . implode(' AND ', $filters) : '';

    $classes = mysqli_query($conn, '
        SELECT DISTINCT ca.id, ca.faculty_id
        FROM class_assignments ca
        WHERE EXISTS (
                SELECT 1 FROM quizzes q
                LEFT JOIN course_module_resources cmr ON cmr.quiz_id = q.id
                WHERE q.class_assignment_id = ca.id AND cmr.id IS NULL
            )' . $filter_sql
    );
    if (!$classes) return false;

    mysqli_begin_transaction($conn);
    try {
        while ($class = mysqli_fetch_assoc($classes)) {
            $class_id = intval($class['id']);
            $owner_id = intval($class['faculty_id']);
            $legacy_title = 'Existing Coursework · Class ' . $class_id;

            $stmt = mysqli_prepare($conn, '
                SELECT cm.id
                FROM course_modules cm
                INNER JOIN course_module_classes cmc ON cmc.module_id = cm.id
                WHERE cmc.class_assignment_id = ? AND cm.faculty_id = ? AND cm.title = ?
                LIMIT 1
            ');
            mysqli_stmt_bind_param($stmt, 'iis', $class_id, $owner_id, $legacy_title);
            mysqli_stmt_execute($stmt);
            $module_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);

            if ($module_row) {
                $module_id = intval($module_row['id']);
                $stmt = mysqli_prepare($conn, 'UPDATE course_modules SET status = "published" WHERE id = ?');
                mysqli_stmt_bind_param($stmt, 'i', $module_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                $description = 'Quizzes created before the Modules update.';
                $stmt = mysqli_prepare($conn, 'INSERT INTO course_modules (faculty_id, title, description, status) VALUES (?, ?, ?, "published")');
                mysqli_stmt_bind_param($stmt, 'iss', $owner_id, $legacy_title, $description);
                if (!mysqli_stmt_execute($stmt)) throw new Exception('legacy module');
                $module_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, 'INSERT INTO course_module_classes (module_id, class_assignment_id, class_order) VALUES (?, ?, 1)');
                mysqli_stmt_bind_param($stmt, 'ii', $module_id, $class_id);
                if (!mysqli_stmt_execute($stmt)) throw new Exception('legacy module class');
                mysqli_stmt_close($stmt);
            }

            $next_order = 1;
            $stmt = mysqli_prepare($conn, 'SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order FROM course_module_items WHERE module_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $module_id);
            mysqli_stmt_execute($stmt);
            $order_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            $next_order = intval($order_row['next_order'] ?? 1);
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, '
                SELECT q.id, q.title, q.instructions
                FROM quizzes q
                LEFT JOIN course_module_resources cmr ON cmr.quiz_id = q.id
                WHERE q.class_assignment_id = ? AND cmr.id IS NULL
                ORDER BY q.id
            ');
            mysqli_stmt_bind_param($stmt, 'i', $class_id);
            mysqli_stmt_execute($stmt);
            $legacy_quizzes = mysqli_stmt_get_result($stmt);
            while ($quiz = mysqli_fetch_assoc($legacy_quizzes)) {
                $quiz_id = intval($quiz['id']);
                $item_type = 'quiz';
                $item_title = (string) $quiz['title'];
                $item_instructions = (string) ($quiz['instructions'] ?? '');
                $item_stmt = mysqli_prepare($conn, 'INSERT INTO course_module_items (module_id, item_type, title, instructions, legacy_quiz_id, display_order) VALUES (?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($item_stmt, 'isssii', $module_id, $item_type, $item_title, $item_instructions, $quiz_id, $next_order);
                if (!mysqli_stmt_execute($item_stmt)) throw new Exception('legacy quiz item');
                $item_id = mysqli_insert_id($conn);
                mysqli_stmt_close($item_stmt);

                $resource_stmt = mysqli_prepare($conn, 'INSERT INTO course_module_resources (module_item_id, class_assignment_id, quiz_id) VALUES (?, ?, ?)');
                mysqli_stmt_bind_param($resource_stmt, 'iii', $item_id, $class_id, $quiz_id);
                if (!mysqli_stmt_execute($resource_stmt)) throw new Exception('legacy quiz resource');
                mysqli_stmt_close($resource_stmt);
                $next_order++;
            }
            mysqli_stmt_close($stmt);
        }
        mysqli_commit($conn);
        return true;
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        return false;
    }
}

function studylink_faculty_id($conn, $user_id)
{
    $stmt = mysqli_prepare($conn, 'SELECT id FROM faculty WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? intval($row['id']) : 0;
}

function studylink_faculty_module($conn, $module_id, $faculty_id)
{
    $stmt = mysqli_prepare($conn, '
        SELECT cm.*,
               (SELECT COUNT(*) FROM course_module_classes cmc WHERE cmc.module_id = cm.id) AS class_count,
               (SELECT COUNT(*) FROM course_module_items cmi WHERE cmi.module_id = cm.id) AS item_count
        FROM course_modules cm
        WHERE cm.id = ? AND cm.faculty_id = ?
        LIMIT 1
    ');
    mysqli_stmt_bind_param($stmt, 'ii', $module_id, $faculty_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function studylink_module_class_ids($conn, $module_id)
{
    $ids = [];
    $stmt = mysqli_prepare($conn, 'SELECT class_assignment_id FROM course_module_classes WHERE module_id = ? ORDER BY class_order, id');
    mysqli_stmt_bind_param($stmt, 'i', $module_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $ids[] = intval($row['class_assignment_id']);
    mysqli_stmt_close($stmt);
    return $ids;
}

function studylink_student_module_access($conn, $module_id, $class_id, $student_id)
{
    $stmt = mysqli_prepare($conn, '
        SELECT cm.*
        FROM course_modules cm
        INNER JOIN course_module_classes cmc ON cmc.module_id = cm.id
        INNER JOIN class_assignments ca ON ca.id = cmc.class_assignment_id
        INNER JOIN students s ON s.section_id = ca.section_id
        WHERE cm.id = ? AND ca.id = ? AND s.id = ? AND cm.status = "published"
        LIMIT 1
    ');
    mysqli_stmt_bind_param($stmt, 'iii', $module_id, $class_id, $student_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ?: null;
}

function studylink_student_quiz_module_context($conn, $module_id, $class_id, $student_id, $quiz_id)
{
    if ($module_id <= 0 || $class_id <= 0 || $student_id <= 0 || $quiz_id <= 0 || !studylink_modules_ready($conn)) {
        return false;
    }
    $stmt = mysqli_prepare($conn, '
        SELECT cm.id
        FROM course_modules cm
        INNER JOIN course_module_classes cmc ON cmc.module_id = cm.id AND cmc.class_assignment_id = ?
        INNER JOIN class_assignments ca ON ca.id = cmc.class_assignment_id
        INNER JOIN students s ON s.section_id = ca.section_id AND s.id = ?
        INNER JOIN course_module_items cmi ON cmi.module_id = cm.id
        INNER JOIN course_module_resources cmr ON cmr.module_item_id = cmi.id
            AND cmr.class_assignment_id = ca.id AND cmr.quiz_id = ?
        WHERE cm.id = ? AND cm.status = "published"
        LIMIT 1
    ');
    mysqli_stmt_bind_param($stmt, 'iiii', $class_id, $student_id, $quiz_id, $module_id);
    mysqli_stmt_execute($stmt);
    $valid = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $valid;
}

function studylink_student_quiz_module_lookup($conn, $student_id, $quiz_id)
{
    if ($student_id <= 0 || $quiz_id <= 0 || !studylink_modules_ready($conn)) return null;
    $stmt = mysqli_prepare($conn, '
        SELECT cm.id AS module_id, ca.id AS class_id
        FROM course_module_resources cmr
        INNER JOIN course_module_items cmi ON cmi.id = cmr.module_item_id
        INNER JOIN course_modules cm ON cm.id = cmi.module_id AND cm.status = "published"
        INNER JOIN class_assignments ca ON ca.id = cmr.class_assignment_id
        INNER JOIN students s ON s.section_id = ca.section_id AND s.id = ?
        WHERE cmr.quiz_id = ?
        LIMIT 1
    ');
    mysqli_stmt_bind_param($stmt, 'ii', $student_id, $quiz_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $row ? ['module_id' => intval($row['module_id']), 'class_id' => intval($row['class_id'])] : null;
}

function studylink_publish_module_quiz_group($conn, $source_quiz_id)
{
    if (!studylink_modules_ready($conn)) return 0;
    $source_quiz_id = intval($source_quiz_id);
    $stmt = mysqli_prepare($conn, '
        SELECT q.*, cmr.module_item_id
        FROM quizzes q
        INNER JOIN course_module_resources cmr ON cmr.quiz_id = q.id
        INNER JOIN course_module_items cmi ON cmi.id = cmr.module_item_id
        WHERE q.id = ? AND cmi.item_type IN ("quiz", "exam")
        LIMIT 1
    ');
    mysqli_stmt_bind_param($stmt, 'i', $source_quiz_id);
    mysqli_stmt_execute($stmt);
    $source = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$source) return 0;

    $stmt = mysqli_prepare($conn, '
        SELECT q.id, (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id) AS attempt_count
        FROM course_module_resources cmr
        INNER JOIN quizzes q ON q.id = cmr.quiz_id
        WHERE cmr.module_item_id = ? AND q.id <> ?
    ');
    mysqli_stmt_bind_param($stmt, 'ii', $source['module_item_id'], $source_quiz_id);
    mysqli_stmt_execute($stmt);
    $siblings = [];
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        if (intval($row['attempt_count']) > 0) {
            mysqli_stmt_close($stmt);
            return -1;
        }
        $siblings[] = intval($row['id']);
    }
    mysqli_stmt_close($stmt);
    if (!$siblings) return 0;

    $question_stmt = mysqli_prepare($conn, 'SELECT id, question_text, question_type, points, correct_answer, order_no FROM quiz_questions WHERE quiz_id = ? ORDER BY order_no, id');
    mysqli_stmt_bind_param($question_stmt, 'i', $source_quiz_id);
    mysqli_stmt_execute($question_stmt);
    $questions = [];
    $question_result = mysqli_stmt_get_result($question_stmt);
    while ($question = mysqli_fetch_assoc($question_result)) {
        $choice_stmt = mysqli_prepare($conn, 'SELECT choice_text, choice_order, is_correct FROM quiz_choices WHERE question_id = ? ORDER BY choice_order, id');
        mysqli_stmt_bind_param($choice_stmt, 'i', $question['id']);
        mysqli_stmt_execute($choice_stmt);
        $question['choices'] = mysqli_fetch_all(mysqli_stmt_get_result($choice_stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($choice_stmt);
        $questions[] = $question;
    }
    mysqli_stmt_close($question_stmt);

    mysqli_begin_transaction($conn);
    try {
        foreach ($siblings as $sibling_id) {
            $source_title = (string) $source['title'];
            $source_instructions = (string) ($source['instructions'] ?? '');
            $source_time_limit = intval($source['time_limit']);
            $source_available_from = $source['available_from'];
            $source_available_until = $source['available_until'];
            $source_passing_score = intval($source['passing_score']);
            $source_max_attempts = intval($source['max_attempts']);
            $source_shuffle = intval($source['shuffle_questions']);
            $stmt = mysqli_prepare($conn, '
                UPDATE quizzes SET title = ?, instructions = ?, time_limit = ?, available_from = ?,
                    available_until = ?, passing_score = ?, max_attempts = ?, shuffle_questions = ?, status = "published"
                WHERE id = ?
            ');
            mysqli_stmt_bind_param(
                $stmt,
                'ssissiiii',
                $source_title,
                $source_instructions,
                $source_time_limit,
                $source_available_from,
                $source_available_until,
                $source_passing_score,
                $source_max_attempts,
                $source_shuffle,
                $sibling_id
            );
            if (!mysqli_stmt_execute($stmt)) throw new Exception('quiz settings');
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, 'DELETE qc FROM quiz_choices qc INNER JOIN quiz_questions qq ON qq.id = qc.question_id WHERE qq.quiz_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $sibling_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $stmt = mysqli_prepare($conn, 'DELETE FROM quiz_questions WHERE quiz_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $sibling_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            foreach ($questions as $question) {
                $question_text = (string) $question['question_text'];
                $question_type = (string) $question['question_type'];
                $question_points = floatval($question['points']);
                $question_answer = (string) ($question['correct_answer'] ?? '');
                $question_order = intval($question['order_no']);
                $stmt = mysqli_prepare($conn, 'INSERT INTO quiz_questions (quiz_id, question_text, question_type, points, correct_answer, order_no) VALUES (?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'issdsi', $sibling_id, $question_text, $question_type, $question_points, $question_answer, $question_order);
                if (!mysqli_stmt_execute($stmt)) throw new Exception('quiz question');
                $new_question_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);
                foreach ($question['choices'] as $choice) {
                    $choice_text = (string) $choice['choice_text'];
                    $choice_order = intval($choice['choice_order']);
                    $choice_correct = intval($choice['is_correct']);
                    $stmt = mysqli_prepare($conn, 'INSERT INTO quiz_choices (question_id, choice_text, choice_order, is_correct) VALUES (?, ?, ?, ?)');
                    mysqli_stmt_bind_param($stmt, 'isii', $new_question_id, $choice_text, $choice_order, $choice_correct);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception('quiz choice');
                    mysqli_stmt_close($stmt);
                }
            }
        }
        mysqli_commit($conn);
        return count($siblings);
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        return -1;
    }
}

function studylink_module_redirect($module_id, $type, $message)
{
    redirect_with_flash('/studyLink/faculty/module_view.php?id=' . intval($module_id), $type, $message);
}
