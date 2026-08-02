<?php

function faculty_notifications_ready($conn)
{
    static $ready = null;

    if ($ready === null) {
        $result = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
        $ready = $result && mysqli_num_rows($result) > 0;
    }

    return $ready;
}

function faculty_notification_sync($conn, $user_id)
{
    if (!faculty_notifications_ready($conn) || $user_id <= 0) {
        return;
    }

    $profile_statement = mysqli_prepare(
        $conn,
        'SELECT id FROM faculty WHERE user_id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($profile_statement, 'i', $user_id);
    mysqli_stmt_execute($profile_statement);
    $faculty = mysqli_fetch_assoc(mysqli_stmt_get_result($profile_statement));
    mysqli_stmt_close($profile_statement);

    if (!$faculty) {
        return;
    }

    $faculty_id = intval($faculty['id']);
    $queries = [
        [
            'INSERT INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("faculty:assignment-submission:", asm.id), "submission",
                    CONCAT("New submission: ", a.title),
                    CONCAT(u.fullname, " · ", sub.subject_code,
                           IF(asm.submission_status = "late", " · Submitted late", "")),
                    CONCAT("/studyLink/faculty/assignments/submissions.php?id=", a.id,
                           "&submission_id=", asm.id),
                    IF(asm.submission_status = "late", "important", "normal"),
                    asm.submitted_at
             FROM assignment_submissions asm
             INNER JOIN assignments a ON a.id = asm.assignment_id
             INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             INNER JOIN students st ON st.id = asm.student_id
             INNER JOIN users u ON u.id = st.user_id
             WHERE ca.faculty_id = ? AND asm.score IS NULL
               AND asm.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                message = VALUES(message),
                target_url = VALUES(target_url),
                priority = VALUES(priority)',
            $faculty_id
        ],
        [
            'INSERT INTO notifications
                (user_id, event_key, notification_type, title, message, target_url, priority, created_at)
             SELECT ?, CONCAT("faculty:quiz-review:", qa.id), "quiz-review",
                    CONCAT("Quiz needs review: ", q.title),
                    CONCAT(u.fullname, " · ", sub.subject_code),
                    CONCAT("/studyLink/faculty/quizzes/grade_attempt.php?attempt_id=", qa.id),
                    "important",
                    qa.submitted_at
             FROM quiz_attempts qa
             INNER JOIN quizzes q ON q.id = qa.quiz_id
             INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
             INNER JOIN subjects sub ON sub.id = ca.subject_id
             INNER JOIN students st ON st.id = qa.student_id
             INNER JOIN users u ON u.id = st.user_id
             WHERE ca.faculty_id = ? AND qa.status IN ("needs_review", "submitted")
               AND qa.submitted_at IS NOT NULL
               AND qa.submitted_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title),
                message = VALUES(message),
                target_url = VALUES(target_url),
                priority = VALUES(priority)',
            $faculty_id
        ]
    ];

    foreach ($queries as $query) {
        $statement = mysqli_prepare($conn, $query[0]);
        if (!$statement) {
            continue;
        }

        $target_faculty_id = intval($query[1]);
        mysqli_stmt_bind_param($statement, 'ii', $user_id, $target_faculty_id);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
    }
}

function faculty_notification_unread_count($conn, $user_id)
{
    if (!faculty_notifications_ready($conn) || $user_id <= 0) {
        return 0;
    }

    faculty_notification_sync($conn, $user_id);
    $statement = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS unread_count
         FROM notifications
         WHERE user_id = ? AND is_read = 0'
    );
    mysqli_stmt_bind_param($statement, 'i', $user_id);
    mysqli_stmt_execute($statement);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);

    return intval($row['unread_count'] ?? 0);
}

function render_faculty_notification_button($conn, $user_id)
{
    $unread_count = faculty_notification_unread_count($conn, $user_id);
    ?>
    <a
        class="faculty-icon-link notification-tool faculty-notification-link<?php echo $unread_count > 0 ? ' has-unread' : ''; ?>"
        href="/studyLink/faculty/notifications.php"
        aria-label="<?php echo $unread_count > 0 ? e($unread_count . ' unread notifications') : 'Notifications'; ?>"
        title="Notifications"
    >
        <?php echo study_icon($unread_count > 0 ? 'bell-fill' : 'bell'); ?>
        <?php if ($unread_count > 0): ?>
            <span class="notification-badge faculty-notification-badge"><?php echo $unread_count > 99 ? '99+' : $unread_count; ?></span>
        <?php endif; ?>
    </a>
    <?php
}
