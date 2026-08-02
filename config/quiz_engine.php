<?php

if (!function_exists('quiz_normalize_answer')) {
    function quiz_normalize_answer($value)
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    function quiz_split_enumeration($value)
    {
        $items = preg_split('/[\r\n,;|]+/', (string) $value);
        $normalized = [];

        foreach ($items as $item) {
            $item = quiz_normalize_answer($item);
            if ($item !== '') {
                $normalized[$item] = true;
            }
        }

        $answers = array_keys($normalized);
        sort($answers);
        return $answers;
    }

    function quiz_student_record($conn, $user_id)
    {
        $statement = mysqli_prepare($conn, '
            SELECT id, section_id
            FROM students
            WHERE user_id = ?
            LIMIT 1
        ');
        if (!$statement) {
            return null;
        }

        mysqli_stmt_bind_param($statement, 'i', $user_id);
        mysqli_stmt_execute($statement);
        $student = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return $student ?: null;
    }

    function quiz_student_access($conn, $user_id, $quiz_id, $published_only = true)
    {
        $sql = '
            SELECT q.*, ca.section_id, s.id AS student_id
            FROM quizzes q
            INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
            INNER JOIN students s ON s.section_id = ca.section_id
            WHERE q.id = ? AND s.user_id = ?
        ';
        if ($published_only) {
            $sql .= " AND q.status = 'published'";
        }
        $sql .= ' LIMIT 1';

        $statement = mysqli_prepare($conn, $sql);
        if (!$statement) {
            return null;
        }

        mysqli_stmt_bind_param($statement, 'ii', $quiz_id, $user_id);
        mysqli_stmt_execute($statement);
        $quiz = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return $quiz ?: null;
    }

    function quiz_faculty_access($conn, $user_id, $quiz_id)
    {
        $statement = mysqli_prepare($conn, '
            SELECT q.*, ca.faculty_id, sub.subject_name, sec.section_name
            FROM quizzes q
            INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
            INNER JOIN faculty f ON f.id = ca.faculty_id
            INNER JOIN subjects sub ON sub.id = ca.subject_id
            INNER JOIN sections sec ON sec.id = ca.section_id
            WHERE q.id = ? AND f.user_id = ?
            LIMIT 1
        ');
        if (!$statement) {
            return null;
        }

        mysqli_stmt_bind_param($statement, 'ii', $quiz_id, $user_id);
        mysqli_stmt_execute($statement);
        $quiz = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return $quiz ?: null;
    }

    function quiz_attempt_access($conn, $user_id, $attempt_id, $role)
    {
        if ($role === 'student') {
            $sql = '
                SELECT qa.*, q.title, q.instructions, q.time_limit,
                       q.passing_score, q.max_attempts, q.class_assignment_id,
                       s.user_id AS owner_user_id
                FROM quiz_attempts qa
                INNER JOIN quizzes q ON q.id = qa.quiz_id
                INNER JOIN students s ON s.id = qa.student_id
                WHERE qa.id = ? AND s.user_id = ?
                LIMIT 1
            ';
        } else {
            $sql = '
                SELECT qa.*, q.title, q.instructions, q.time_limit,
                       q.passing_score, q.max_attempts, q.class_assignment_id,
                       u.fullname AS student_name, s.student_no
                FROM quiz_attempts qa
                INNER JOIN quizzes q ON q.id = qa.quiz_id
                INNER JOIN students s ON s.id = qa.student_id
                INNER JOIN users u ON u.id = s.user_id
                INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
                INNER JOIN faculty f ON f.id = ca.faculty_id
                WHERE qa.id = ? AND f.user_id = ?
                LIMIT 1
            ';
        }

        $statement = mysqli_prepare($conn, $sql);
        if (!$statement) {
            return null;
        }

        mysqli_stmt_bind_param($statement, 'ii', $attempt_id, $user_id);
        mysqli_stmt_execute($statement);
        $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);
        return $attempt ?: null;
    }

    function quiz_question_ids($conn, $quiz_id, $shuffle = false)
    {
        $statement = mysqli_prepare($conn, '
            SELECT id
            FROM quiz_questions
            WHERE quiz_id = ?
            ORDER BY order_no ASC, id ASC
        ');
        mysqli_stmt_bind_param($statement, 'i', $quiz_id);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);
        $ids = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $ids[] = intval($row['id']);
        }
        mysqli_stmt_close($statement);

        if ($shuffle && count($ids) > 1) {
            shuffle($ids);
        }
        return $ids;
    }

    function quiz_attempt_expired($attempt)
    {
        return !empty($attempt['expires_at']) && strtotime($attempt['expires_at']) <= time();
    }

    function quiz_finalize_attempt($conn, $attempt_id)
    {
        $statement = mysqli_prepare($conn, '
            SELECT qa.id, qa.quiz_id, qa.status, q.passing_score
            FROM quiz_attempts qa
            INNER JOIN quizzes q ON q.id = qa.quiz_id
            WHERE qa.id = ?
            LIMIT 1
        ');
        mysqli_stmt_bind_param($statement, 'i', $attempt_id);
        mysqli_stmt_execute($statement);
        $attempt = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);

        if (!$attempt || $attempt['status'] !== 'in_progress') {
            return false;
        }

        $question_statement = mysqli_prepare($conn, '
            SELECT id, question_type, points, correct_answer
            FROM quiz_questions
            WHERE quiz_id = ?
        ');
        mysqli_stmt_bind_param($question_statement, 'i', $attempt['quiz_id']);
        mysqli_stmt_execute($question_statement);
        $questions = mysqli_stmt_get_result($question_statement);

        $answer_lookup = mysqli_prepare($conn, '
            SELECT id, selected_choice_id, answer_text
            FROM quiz_answers
            WHERE attempt_id = ? AND question_id = ?
            LIMIT 1
        ');
        $choice_lookup = mysqli_prepare($conn, '
            SELECT is_correct
            FROM quiz_choices
            WHERE id = ? AND question_id = ?
            LIMIT 1
        ');
        $insert_answer = mysqli_prepare($conn, '
            INSERT INTO quiz_answers
                (attempt_id, question_id, answer_text, auto_score, final_score, checked_by_faculty)
            VALUES (?, ?, "", ?, ?, ?)
        ');
        $update_answer = mysqli_prepare($conn, '
            UPDATE quiz_answers
            SET auto_score = ?, is_correct = ?, final_score = ?,
                checked_by_faculty = ?
            WHERE id = ?
        ');

        $score = 0.0;
        $total_points = 0.0;
        $needs_review = false;

        while ($question = mysqli_fetch_assoc($questions)) {
            $question_id = intval($question['id']);
            $points = floatval($question['points']);
            $total_points += $points;

            mysqli_stmt_bind_param($answer_lookup, 'ii', $attempt_id, $question_id);
            mysqli_stmt_execute($answer_lookup);
            $answer = mysqli_fetch_assoc(mysqli_stmt_get_result($answer_lookup));

            $auto_score = 0.0;
            $final_score = 0.0;
            $is_correct = 0;
            $checked = 1;
            $type = $question['question_type'];

            if ($type === 'essay') {
                $needs_review = true;
                $auto_score = null;
                $final_score = null;
                $is_correct = null;
                $checked = 0;
            } elseif ($type === 'multiple_choice' || $type === 'true_false') {
                if ($answer && intval($answer['selected_choice_id']) > 0) {
                    $choice_id = intval($answer['selected_choice_id']);
                    mysqli_stmt_bind_param($choice_lookup, 'ii', $choice_id, $question_id);
                    mysqli_stmt_execute($choice_lookup);
                    $choice = mysqli_fetch_assoc(mysqli_stmt_get_result($choice_lookup));
                    if ($choice && intval($choice['is_correct']) === 1) {
                        $auto_score = $points;
                        $final_score = $points;
                        $is_correct = 1;
                    }
                }
            } elseif ($type === 'identification') {
                $student_answer = quiz_normalize_answer($answer['answer_text'] ?? '');
                $accepted = array_map('quiz_normalize_answer', explode('|', (string) $question['correct_answer']));
                if ($student_answer !== '' && in_array($student_answer, $accepted, true)) {
                    $auto_score = $points;
                    $final_score = $points;
                    $is_correct = 1;
                }
            } elseif ($type === 'enumeration') {
                $student_items = quiz_split_enumeration($answer['answer_text'] ?? '');
                $correct_items = quiz_split_enumeration($question['correct_answer']);
                $correct_count = count($correct_items);

                if ($student_items && $correct_count > 0) {
                    $matched_count = count(array_intersect($student_items, $correct_items));

                    if ($matched_count > 0) {
                        $auto_score = $matched_count === $correct_count
                            ? $points
                            : round($points * ($matched_count / $correct_count), 2);
                        $final_score = $auto_score;
                        $is_correct = $matched_count === $correct_count ? 1 : 0;
                    }
                }
            }

            if ($answer) {
                mysqli_stmt_bind_param(
                    $update_answer,
                    'didii',
                    $auto_score,
                    $is_correct,
                    $final_score,
                    $checked,
                    $answer['id']
                );
                mysqli_stmt_execute($update_answer);
            } else {
                mysqli_stmt_bind_param(
                    $insert_answer,
                    'iiddi',
                    $attempt_id,
                    $question_id,
                    $auto_score,
                    $final_score,
                    $checked
                );
                mysqli_stmt_execute($insert_answer);
            }

            if ($final_score !== null) {
                $score += floatval($final_score);
            }
        }

        mysqli_stmt_close($question_statement);
        mysqli_stmt_close($answer_lookup);
        mysqli_stmt_close($choice_lookup);
        mysqli_stmt_close($insert_answer);
        mysqli_stmt_close($update_answer);

        $percentage = $total_points > 0 ? ($score / $total_points) * 100 : 0;
        $status = $needs_review ? 'needs_review' : 'graded';
        $is_passed = !$needs_review && $percentage >= floatval($attempt['passing_score']) ? 1 : 0;

        $update_attempt = mysqli_prepare($conn, '
            UPDATE quiz_attempts
            SET score = ?, total_points = ?, percentage = ?, is_passed = ?,
                status = ?, submitted_at = NOW(),
                graded_at = CASE WHEN ? = "graded" THEN NOW() ELSE NULL END
            WHERE id = ? AND status = "in_progress"
        ');
        mysqli_stmt_bind_param(
            $update_attempt,
            'dddissi',
            $score,
            $total_points,
            $percentage,
            $is_passed,
            $status,
            $status,
            $attempt_id
        );
        mysqli_stmt_execute($update_attempt);
        mysqli_stmt_close($update_attempt);

        return true;
    }
}
