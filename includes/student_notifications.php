<?php

require_once __DIR__ . '/ui_icons.php';

function student_notifications_ready($conn)
{
    static $ready = null;

    if ($ready === null) {
        $result = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
        $ready = $result && mysqli_num_rows($result) > 0;
    }

    return $ready;
}

function student_notification_sync($conn, $user_id)
{
    if (!student_notifications_ready($conn)) {
        return;
    }

    $profile_statement = mysqli_prepare(
        $conn,
        'SELECT id, section_id FROM students WHERE user_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($profile_statement, 'i', $user_id);
    mysqli_stmt_execute($profile_statement);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($profile_statement));
    mysqli_stmt_close($profile_statement);

    if (!$student) {
        return;
    }

    $student_id = intval($student['id']);
    $section_id = intval($student['section_id']);

    $queries = [
        [
            'INSERT IGNORE INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("assignment:new:", a.id), "assignment",
                    CONCAT("New assignment: ", a.title),
                    CONCAT(sub.subject_code, " · Due ", DATE_FORMAT(a.due_date, "%b %e, %Y at %l:%i %p")),
                    CONCAT("/studyLink/student/class_view.php?id=", ca.id, "&tab=assignments"),
                    IF(a.due_date <= DATE_ADD(NOW(), INTERVAL 3 DAY), "important", "normal"),
                    a.created_at
             FROM assignments a
             INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             WHERE ca.section_id = ? AND a.created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)',
            [$user_id, $section_id],
            'ii'
        ],
        [
            'INSERT IGNORE INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("material:new:", lm.id), "material",
                    CONCAT("New learning material: ", lm.title),
                    CONCAT(sub.subject_code, " · Added by your faculty"),
                    CONCAT("/studyLink/student/class_view.php?id=", ca.id, "&tab=materials"),
                    "normal", lm.created_at
             FROM learning_materials lm
             INNER JOIN class_assignments ca ON ca.id = lm.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             WHERE ca.section_id = ? AND lm.created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)',
            [$user_id, $section_id],
            'ii'
        ],
        [
            'INSERT IGNORE INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("quiz:published:", q.id), "quiz",
                    CONCAT(
                        IF(q.available_from IS NOT NULL AND q.available_from > NOW(),
                           "Upcoming quiz: ", "Quiz available: "),
                        q.title
                    ),
                    CONCAT(
                        sub.subject_code,
                        IF(q.available_from IS NOT NULL AND q.available_from > NOW(),
                           CONCAT(" · Opens ", DATE_FORMAT(q.available_from, "%b %e at %l:%i %p")),
                           IF(q.available_until IS NULL, " · No closing date",
                              CONCAT(" · Closes ", DATE_FORMAT(q.available_until, "%b %e at %l:%i %p"))))
                    ),
                    "/studyLink/student/quizzes.php",
                    IF(q.available_until IS NOT NULL AND q.available_until <= DATE_ADD(NOW(), INTERVAL 1 DAY), "important", "normal"),
                    q.created_at
             FROM quizzes q
             INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             WHERE ca.section_id = ? AND q.status = "published"
               AND q.created_at >= DATE_SUB(NOW(), INTERVAL 45 DAY)',
            [$user_id, $section_id],
            'ii'
        ],
        [
            'INSERT IGNORE INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("quiz:grade:", qa.id), "grade",
                    CONCAT("Quiz result published: ", q.title),
                    CONCAT(sub.subject_code, " · ", FORMAT(qa.percentage, 0), "%"),
                    CONCAT("/studyLink/student/quiz_result.php?attempt_id=", qa.id),
                    "important", COALESCE(qa.graded_at, qa.submitted_at)
             FROM quiz_attempts qa
             INNER JOIN quizzes q ON q.id = qa.quiz_id
             INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             WHERE qa.student_id = ? AND qa.status = "graded"
               AND COALESCE(qa.graded_at, qa.submitted_at) >= DATE_SUB(NOW(), INTERVAL 90 DAY)',
            [$user_id, $student_id],
            'ii'
        ],
        [
            'INSERT IGNORE INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("assignment:grade:", asm.id), "grade",
                    CONCAT("Assignment graded: ", a.title),
                    CONCAT(sub.subject_code, IF(asm.score IS NULL, "", CONCAT(" · Score ", FORMAT(asm.score, 2)))),
                    CONCAT("/studyLink/student/class_view.php?id=", ca.id, "&tab=assignments"),
                    "important", asm.graded_at
             FROM assignment_submissions asm
             INNER JOIN assignments a ON a.id = asm.assignment_id
             INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             WHERE asm.student_id = ? AND asm.graded_at IS NOT NULL
               AND asm.graded_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)',
            [$user_id, $student_id],
            'ii'
        ],
        [
            'INSERT IGNORE INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("assignment:due:", a.id), "deadline",
                    CONCAT("Assignment due soon: ", a.title),
                    CONCAT(sub.subject_code, " · Due ", DATE_FORMAT(a.due_date, "%b %e at %l:%i %p")),
                    CONCAT("/studyLink/student/class_view.php?id=", ca.id, "&tab=assignments"),
                    "important", NOW()
             FROM assignments a
             INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             LEFT JOIN assignment_submissions asm
                    ON asm.assignment_id = a.id AND asm.student_id = ?
             WHERE ca.section_id = ? AND asm.id IS NULL
               AND a.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)',
            [$user_id, $student_id, $section_id],
            'iii'
        ]
    ];

    foreach ($queries as $query) {
        $statement = mysqli_prepare($conn, $query[0]);
        if (!$statement) {
            continue;
        }

        if (count($query[1]) === 2) {
            $first = intval($query[1][0]);
            $second = intval($query[1][1]);
            mysqli_stmt_bind_param($statement, 'ii', $first, $second);
        } else {
            $first = intval($query[1][0]);
            $second = intval($query[1][1]);
            $third = intval($query[1][2]);
            mysqli_stmt_bind_param($statement, 'iii', $first, $second, $third);
        }
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
    }
}

function student_notification_unread_count($conn, $user_id)
{
    if (!student_notifications_ready($conn)) {
        return 0;
    }

    student_notification_sync($conn, $user_id);
    $statement = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0'
    );
    mysqli_stmt_bind_param($statement, 'i', $user_id);
    mysqli_stmt_execute($statement);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);

    return intval($row['unread_count'] ?? 0);
}

function render_student_notification_button($conn, $user_id, $class_name = 'student-tool')
{
    $unread_count = student_notification_unread_count($conn, $user_id);
    ?>
    <a
        class="<?php echo e($class_name); ?> notification-tool<?php echo $unread_count > 0 ? ' has-unread' : ''; ?>"
        href="/studyLink/student/notifications.php"
        aria-label="<?php echo $unread_count > 0 ? e($unread_count . ' unread notifications') : 'Notifications'; ?>"
        title="Notifications"
    >
        <?php echo study_icon($unread_count > 0 ? 'bell-fill' : 'bell'); ?>
        <?php if ($unread_count > 0): ?>
            <span class="notification-badge"><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span>
        <?php endif; ?>
    </a>
    <?php
}
