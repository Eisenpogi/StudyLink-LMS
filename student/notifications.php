<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/student_notifications.php';

$user_id = intval($_SESSION['user_id']);
$student = null;
$notifications = [];
$filter = $_GET['filter'] ?? 'all';

if (!in_array($filter, ['all', 'unread', 'important'], true)) {
    $filter = 'all';
}

$student_statement = mysqli_prepare(
    $conn,
    'SELECT s.id, s.student_no, s.section_id, sec.section_name
     FROM students s
     INNER JOIN sections sec ON sec.id = s.section_id
     WHERE s.user_id = ?
     LIMIT 1'
);
mysqli_stmt_bind_param($student_statement, 'i', $user_id);
mysqli_stmt_execute($student_statement);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($student_statement));
mysqli_stmt_close($student_statement);

$table_ready = student_notifications_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('/studyLink/student/notifications.php');

    if ($table_ready && ($_POST['action'] ?? '') === 'mark_all_read') {
        $statement = mysqli_prepare(
            $conn,
            'UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE user_id = ? AND is_read = 0'
        );
        mysqli_stmt_bind_param($statement, 'i', $user_id);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        redirect_to('/studyLink/student/notifications.php?updated=1');
    }

    if ($table_ready && ($_POST['action'] ?? '') === 'open_notification') {
        $notification_id = intval($_POST['notification_id'] ?? 0);
        $statement = mysqli_prepare(
            $conn,
            'SELECT target_url FROM notifications WHERE id = ? AND user_id = ? LIMIT 1'
        );
        mysqli_stmt_bind_param($statement, 'ii', $notification_id, $user_id);
        mysqli_stmt_execute($statement);
        $notification = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
        mysqli_stmt_close($statement);

        if ($notification) {
            $statement = mysqli_prepare(
                $conn,
                'UPDATE notifications
                 SET is_read = 1, read_at = COALESCE(read_at, NOW())
                 WHERE id = ? AND user_id = ?'
            );
            mysqli_stmt_bind_param($statement, 'ii', $notification_id, $user_id);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);

            $target_url = (string) ($notification['target_url'] ?? '');
            if (strpos($target_url, '/studyLink/') === 0) {
                redirect_to($target_url);
            }
        }

        redirect_to('/studyLink/student/notifications.php');
    }
}

$unread_count = 0;
$important_count = 0;

if ($table_ready) {
    student_notification_sync($conn, $user_id);

    $where = 'user_id = ?';
    if ($filter === 'unread') {
        $where .= ' AND is_read = 0';
    } elseif ($filter === 'important') {
        $where .= ' AND priority = "important"';
    }

    $statement = mysqli_prepare(
        $conn,
        "SELECT id, notification_type, title, message, target_url, priority, is_read, created_at
         FROM notifications
         WHERE {$where}
         ORDER BY is_read ASC, priority = 'important' DESC, created_at DESC
         LIMIT 75"
    );
    mysqli_stmt_bind_param($statement, 'i', $user_id);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = $row;
    }
    mysqli_stmt_close($statement);

    $statement = mysqli_prepare(
        $conn,
        'SELECT
            SUM(is_read = 0) AS unread_count,
            SUM(priority = "important") AS important_count
         FROM notifications
         WHERE user_id = ?'
    );
    mysqli_stmt_bind_param($statement, 'i', $user_id);
    mysqli_stmt_execute($statement);
    $counts = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
    mysqli_stmt_close($statement);
    $unread_count = intval($counts['unread_count'] ?? 0);
    $important_count = intval($counts['important_count'] ?? 0);
}

function student_notification_icon($type)
{
    $icons = [
        'assignment' => 'clipboard2-check',
        'quiz' => 'patch-question',
        'material' => 'file-earmark-text',
        'grade' => 'award',
        'deadline' => 'clock-history',
        'system' => 'info-circle'
    ];

    return $icons[$type] ?? 'bell';
}

function student_notification_time($value)
{
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return '';
    }

    $difference = time() - $timestamp;
    if ($difference < 60) {
        return 'Just now';
    }
    if ($difference < 3600) {
        return floor($difference / 60) . 'm ago';
    }
    if ($difference < 86400) {
        return floor($difference / 3600) . 'h ago';
    }
    if ($difference < 604800) {
        return floor($difference / 86400) . 'd ago';
    }

    return date('M j, Y', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
</head>
<body>
<aside class="student-sidebar">
    <a class="student-brand" href="/studyLink/student/dashboard.php"><span class="student-brand-mark">S</span>StudyLink</a>
    <nav class="student-nav">
        <a href="/studyLink/student/dashboard.php"><span class="student-nav-icon"><?php echo study_icon('grid'); ?></span>Dashboard</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Courses</a>
        <a href="/studyLink/student/calendar.php"><span class="student-nav-icon"><?php echo study_icon('calendar3'); ?></span>Calendar</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('clipboard2-check'); ?></span>Assignments</a>
        <a href="/studyLink/student/quizzes.php"><span class="student-nav-icon"><?php echo study_icon('patch-question'); ?></span>Quizzes</a>
        <a href="/studyLink/student/messages.php"><span class="student-nav-icon"><?php echo study_icon('envelope'); ?></span>Messages</a>
    </nav>
    <div class="student-account">
        <div class="student-avatar"><?php echo e(strtoupper(substr($_SESSION['fullname'], 0, 1))); ?></div>
        <div><div class="student-name"><?php echo e($_SESSION['fullname']); ?></div><div class="student-id"><?php echo $student ? 'Student ID: ' . e($student['student_no']) : 'Student account'; ?></div></div>
    </div>
</aside>
<header class="student-topbar">
    <div class="student-search notification-heading-search"><?php echo study_icon('bell'); ?><span>Important academic updates</span></div>
    <div class="student-tools"><?php render_student_notification_button($conn, $user_id); ?><a class="account-logout" href="/studyLink/auth/logout.php"><span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span><span class="account-logout-label">Log out</span></a></div>
</header>
<main class="student-main"><div class="student-shell notification-shell">
    <section class="page-heading notification-page-heading">
        <div><div class="eyebrow">Academic Updates</div><h1>Notifications</h1><p class="lead">Important activities and results from your enrolled courses.</p></div>
    </section>

    <?php if (isset($_GET['updated'])): ?><div class="notice">Unread notifications have been cleared.</div><?php endif; ?>

    <?php if (!$table_ready): ?>
        <div class="empty-state notification-setup">
            <?php echo study_icon('database-exclamation'); ?>
            <h2>Notifications are not ready</h2>
            <p>The notifications table is missing from the current StudyLink database.</p>
        </div>
    <?php else: ?>
        <section class="card notification-toolbar">
            <nav class="notification-filters" aria-label="Notification filters">
                <a class="<?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">All</a>
                <a class="<?php echo $filter === 'unread' ? 'active' : ''; ?>" href="?filter=unread">Unread <span><?php echo $unread_count; ?></span></a>
                <a class="<?php echo $filter === 'important' ? 'active' : ''; ?>" href="?filter=important">Important</a>
            </nav>
            <div class="notification-utilities">
                <span><?php echo $unread_count; ?> unread · <?php echo $important_count; ?> important</span>
                <?php if ($unread_count > 0): ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" title="Clear unread indicators">
                            <?php echo study_icon('check2'); ?> Mark unread as read
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <section class="notification-list">
            <?php if (!$notifications): ?>
                <div class="empty-state"><?php echo study_icon('bell-slash'); ?><h2>No notifications here</h2><p>Your important course updates will appear here.</p></div>
            <?php endif; ?>

            <?php foreach ($notifications as $notification): ?>
                <form method="post" class="notification-item-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="open_notification">
                    <input type="hidden" name="notification_id" value="<?php echo intval($notification['id']); ?>">
                    <button class="card notification-item <?php echo intval($notification['is_read']) === 0 ? 'unread' : ''; ?>" type="submit">
                        <span class="notification-item-icon"><?php echo study_icon(student_notification_icon($notification['notification_type'])); ?></span>
                        <span class="notification-item-copy">
                            <span class="notification-item-title"><?php echo e($notification['title']); ?></span>
                            <span class="notification-item-message"><?php echo e($notification['message']); ?></span>
                            <span class="notification-item-meta">
                                <?php if ($notification['priority'] === 'important'): ?><b>Important</b><?php endif; ?>
                                <time><?php echo e(student_notification_time($notification['created_at'])); ?></time>
                            </span>
                        </span>
                        <?php if (intval($notification['is_read']) === 0): ?><span class="notification-unread-dot" aria-label="Unread"></span><?php endif; ?>
                        <span class="notification-item-arrow"><?php echo study_icon('chevron-right'); ?></span>
                    </button>
                </form>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div></main>
</body>
</html>
