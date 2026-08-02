<?php

include '../auth/auth.php';
require_role('faculty');
include '../config/database.php';
include '../includes/faculty_ui.php';

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
if (!$faculty) {
    http_response_code(403);
    exit('Faculty profile not found.');
}

$filter = $_GET['filter'] ?? 'all';
$notifications = [];
if (!in_array($filter, ['all', 'unread', 'important'], true)) {
    $filter = 'all';
}

$table_ready = faculty_notifications_ready($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('/studyLink/faculty/notifications.php');
    $action = $_POST['action'] ?? '';

    if ($table_ready && $action === 'mark_all_read') {
        $statement = mysqli_prepare(
            $conn,
            'UPDATE notifications
             SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE user_id = ? AND is_read = 0'
        );
        mysqli_stmt_bind_param($statement, 'i', $user_id);
        mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        redirect_to('/studyLink/faculty/notifications.php?updated=1');
    }

    if ($table_ready && $action === 'open_notification') {
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

        redirect_to('/studyLink/faculty/notifications.php');
    }
}

$unread_count = 0;
$important_count = 0;

if ($table_ready) {
    faculty_notification_sync($conn, $user_id);
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

function faculty_notification_icon($type)
{
    $icons = [
        'submission' => 'file-earmark-check',
        'quiz-review' => 'patch-question',
        'assignment' => 'clipboard2-check',
        'quiz' => 'patch-question',
        'grade' => 'award',
        'deadline' => 'clock-history',
        'system' => 'info-circle'
    ];

    return $icons[$type] ?? 'bell';
}

function faculty_notification_time($value)
{
    $timestamp = strtotime((string) $value);
    if (!$timestamp) {
        return '';
    }

    $difference = time() - $timestamp;
    if ($difference < 60) return 'Just now';
    if ($difference < 3600) return floor($difference / 60) . 'm ago';
    if ($difference < 86400) return floor($difference / 3600) . 'h ago';
    if ($difference < 604800) return floor($difference / 86400) . 'd ago';
    return date('M j, Y', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Notifications - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('notifications', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Notifications', 'Important teaching and grading updates'); ?>

<main class="faculty-main">
    <div class="faculty-shell faculty-notification-shell">
        <section class="faculty-page-heading">
            <div>
                <div class="faculty-eyebrow">Academic Updates</div>
                <h1>Notifications</h1>
                <p>Teaching and grading updates that may need your attention.</p>
            </div>
        </section>

        <?php if (isset($_GET['updated'])): ?>
            <div class="faculty-notice">Unread notifications have been cleared.</div>
        <?php endif; ?>

        <?php if (!$table_ready): ?>
            <section class="faculty-card faculty-notification-empty">
                <?php echo study_icon('database-exclamation'); ?>
                <h2>Notifications are not ready</h2>
                <p>The notifications table is missing from the current StudyLink database.</p>
            </section>
        <?php else: ?>
            <section class="faculty-card faculty-notification-toolbar">
                <nav class="faculty-notification-filters" aria-label="Notification filters">
                    <a class="<?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">All</a>
                    <a class="<?php echo $filter === 'unread' ? 'active' : ''; ?>" href="?filter=unread">Unread <span><?php echo $unread_count; ?></span></a>
                    <a class="<?php echo $filter === 'important' ? 'active' : ''; ?>" href="?filter=important">Important</a>
                </nav>
                <div class="faculty-notification-utilities">
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

            <section class="faculty-notification-list">
                <?php if (!$notifications): ?>
                    <div class="faculty-card faculty-notification-empty">
                        <?php echo study_icon('bell-slash'); ?>
                        <h2>No notifications here</h2>
                        <p>New submissions and items requiring review will appear here.</p>
                    </div>
                <?php endif; ?>

                <?php foreach ($notifications as $notification): ?>
                    <form method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="open_notification">
                        <input type="hidden" name="notification_id" value="<?php echo intval($notification['id']); ?>">
                        <button class="faculty-card faculty-notification-item <?php echo intval($notification['is_read']) === 0 ? 'unread' : ''; ?>" type="submit">
                            <span class="faculty-notification-item-icon"><?php echo study_icon(faculty_notification_icon($notification['notification_type'])); ?></span>
                            <span class="faculty-notification-copy">
                                <strong><?php echo e($notification['title']); ?></strong>
                                <span><?php echo e($notification['message']); ?></span>
                                <small>
                                    <?php if ($notification['priority'] === 'important'): ?><b>Important</b><?php endif; ?>
                                    <?php echo e(faculty_notification_time($notification['created_at'])); ?>
                                </small>
                            </span>
                            <?php if (intval($notification['is_read']) === 0): ?><span class="faculty-notification-dot" aria-label="Unread"></span><?php endif; ?>
                            <?php echo study_icon('chevron-right'); ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
