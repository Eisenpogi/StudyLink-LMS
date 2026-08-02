<?php
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/ui_icons.php';
require_once dirname(__DIR__) . '/includes/student_notifications.php';

$message_page_role = $message_page_role ?? '';
require_role($message_page_role);

$user_id = intval($_SESSION['user_id']);
$role = $_SESSION['role'];
$recipients = [];
$contact_state = [];
$conversation = [];
$notice = isset($_GET['sent']) ? 'Message sent successfully.' : '';
$error = '';
$account_label = ucfirst($role) . ' account';
$account_subtitle = ucfirst($role) . ' Portal';
$role_record_id = 0;

function message_initials($name)
{
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return $initials ?: 'U';
}

function add_message_recipient(&$recipients, $row)
{
    $id = intval($row['id']);
    if ($id > 0) {
        $recipients[$id] = $row;
    }
}

if ($role === 'student') {
    $stmt = mysqli_prepare($conn, 'SELECT id, student_no, section_id FROM students WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($student) {
        $role_record_id = intval($student['id']);
        $account_label = 'Student ID: ' . $student['student_no'];
        $section_id = intval($student['section_id']);

        $stmt = mysqli_prepare($conn, '
            SELECT
                u.id,
                u.fullname,
                u.role,
                GROUP_CONCAT(DISTINCT sub.subject_code ORDER BY sub.subject_code SEPARATOR ", ") AS context_name
            FROM users u
            INNER JOIN faculty f ON f.user_id = u.id
            INNER JOIN class_assignments ca ON ca.faculty_id = f.id
            INNER JOIN subjects sub ON sub.id = ca.subject_id
            WHERE u.status = "active" AND ca.section_id = ?
            GROUP BY u.id, u.fullname, u.role
            ORDER BY u.fullname
        ');
        mysqli_stmt_bind_param($stmt, 'i', $section_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            add_message_recipient($recipients, $row);
        }
        mysqli_stmt_close($stmt);
    }

    $result = mysqli_query($conn, 'SELECT id, fullname, role, "Administration" AS context_name FROM users WHERE role = "admin" AND status = "active" ORDER BY fullname');
    while ($row = mysqli_fetch_assoc($result)) {
        add_message_recipient($recipients, $row);
    }
} elseif ($role === 'faculty') {
    $stmt = mysqli_prepare($conn, 'SELECT id, faculty_id FROM faculty WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $faculty_record = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($faculty_record) {
        $role_record_id = intval($faculty_record['id']);
        $account_label = 'Faculty ID: ' . $faculty_record['faculty_id'];

        $stmt = mysqli_prepare($conn, '
            SELECT
                u.id,
                u.fullname,
                u.role,
                GROUP_CONCAT(DISTINCT sec.section_name ORDER BY sec.section_name SEPARATOR ", ") AS context_name
            FROM users u
            INNER JOIN students s ON s.user_id = u.id
            INNER JOIN sections sec ON sec.id = s.section_id
            INNER JOIN class_assignments ca ON ca.section_id = s.section_id
            WHERE u.status = "active" AND ca.faculty_id = ?
            GROUP BY u.id, u.fullname, u.role
            ORDER BY u.fullname
        ');
        mysqli_stmt_bind_param($stmt, 'i', $role_record_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            add_message_recipient($recipients, $row);
        }
        mysqli_stmt_close($stmt);
    }

    $result = mysqli_query($conn, 'SELECT id, fullname, role, "Administration" AS context_name FROM users WHERE role = "admin" AND status = "active" ORDER BY fullname');
    while ($row = mysqli_fetch_assoc($result)) {
        if (intval($row['id']) !== $user_id) {
            add_message_recipient($recipients, $row);
        }
    }
} else {
    $result = mysqli_query($conn, '
        SELECT
            u.id,
            u.fullname,
            u.role,
            CASE
                WHEN u.role = "faculty" THEN COALESCE(f.faculty_id, "Faculty")
                WHEN u.role = "student" THEN COALESCE(s.student_no, "Student")
                ELSE "Administration"
            END AS context_name
        FROM users u
        LEFT JOIN faculty f ON f.user_id = u.id
        LEFT JOIN students s ON s.user_id = u.id
        WHERE u.status = "active" AND u.id <> ' . $user_id . '
        ORDER BY u.role, u.fullname
    ');
    while ($row = mysqli_fetch_assoc($result)) {
        add_message_recipient($recipients, $row);
    }
}

$messages_table_ready = false;
$table_result = mysqli_query($conn, "SHOW TABLES LIKE 'messages'");
if ($table_result && mysqli_num_rows($table_result) > 0) {
    $messages_table_ready = true;
}

if ($messages_table_ready) {
    $stmt = mysqli_prepare($conn, '
        SELECT id, sender_id, recipient_id, subject, message_body, is_read, created_at
        FROM messages
        WHERE sender_id = ? OR recipient_id = ?
        ORDER BY created_at DESC, id DESC
    ');
    mysqli_stmt_bind_param($stmt, 'ii', $user_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $partner_id = intval($row['sender_id']) === $user_id ? intval($row['recipient_id']) : intval($row['sender_id']);
        if (!isset($recipients[$partner_id])) {
            continue;
        }
        if (!isset($contact_state[$partner_id])) {
            $contact_state[$partner_id] = [
                'last_message' => $row['message_body'],
                'last_time' => $row['created_at'],
                'unread' => 0
            ];
        }
        if (intval($row['recipient_id']) === $user_id && intval($row['is_read']) === 0) {
            $contact_state[$partner_id]['unread']++;
        }
    }
    mysqli_stmt_close($stmt);

    uasort($recipients, function ($a, $b) use ($contact_state) {
        $a_time = $contact_state[intval($a['id'])]['last_time'] ?? '';
        $b_time = $contact_state[intval($b['id'])]['last_time'] ?? '';
        if ($a_time === $b_time) {
            return strcasecmp($a['fullname'], $b['fullname']);
        }
        return strcmp($b_time, $a_time);
    });
}

$with = intval($_GET['with'] ?? 0);
if ($with && !isset($recipients[$with])) {
    $with = 0;
}
if (!$with && !empty($contact_state)) {
    foreach ($recipients as $recipient_id => $recipient) {
        if (isset($contact_state[$recipient_id])) {
            $with = $recipient_id;
            break;
        }
    }
}
if (!$with && !empty($recipients)) {
    $with = intval(array_key_first($recipients));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $recipient_id = intval($_POST['recipient_id'] ?? 0);
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['message_body'] ?? '');

    if (!$messages_table_ready) {
        $error = 'Import sql/add_messages.sql once to activate Messages.';
    } elseif (!isset($recipients[$recipient_id])) {
        $error = 'Choose a valid recipient.';
    } elseif ($subject === '' || $body === '') {
        $error = 'Subject and message are required.';
    } elseif (strlen($subject) > 150 || strlen($body) > 5000) {
        $error = 'Your subject or message is too long.';
    } else {
        $stmt = mysqli_prepare($conn, 'INSERT INTO messages (sender_id, recipient_id, subject, message_body) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iiss', $user_id, $recipient_id, $subject, $body);
        $sent = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        if ($sent) {
            redirect_to('/studyLink/' . $role . '/messages.php?with=' . $recipient_id . '&sent=1');
        }
        $error = 'Message could not be sent. Please try again.';
    }

    if ($recipient_id && isset($recipients[$recipient_id])) {
        $with = $recipient_id;
    }
}

if ($messages_table_ready && $with) {
    $stmt = mysqli_prepare($conn, '
        SELECT m.*, s.fullname AS sender_name, r.fullname AS recipient_name
        FROM messages m
        INNER JOIN users s ON s.id = m.sender_id
        INNER JOIN users r ON r.id = m.recipient_id
        WHERE (m.sender_id = ? AND m.recipient_id = ?)
           OR (m.sender_id = ? AND m.recipient_id = ?)
        ORDER BY m.created_at ASC, m.id ASC
        LIMIT 200
    ');
    mysqli_stmt_bind_param($stmt, 'iiii', $user_id, $with, $with, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $conversation[] = $row;
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, 'UPDATE messages SET is_read = 1, read_at = NOW() WHERE sender_id = ? AND recipient_id = ? AND is_read = 0');
    mysqli_stmt_bind_param($stmt, 'ii', $with, $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$selected_recipient = $with && isset($recipients[$with]) ? $recipients[$with] : null;
$last_subject = !empty($conversation) ? end($conversation)['subject'] : '';
$page_url = '/studyLink/' . $role . '/messages.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
    <link rel="stylesheet" href="/studyLink/assets/css/calendar-messages.css">
    <?php if ($role === 'faculty'): ?><link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css"><?php endif; ?>
    <title>Messages - StudyLink</title>
</head>
<body class="message-center-body role-<?php echo e($role); ?>">
<?php if ($role === 'faculty'): ?>
    <?php
    require_once dirname(__DIR__) . '/includes/faculty_ui.php';
    render_faculty_sidebar('messages', $faculty_record['faculty_id'] ?? '');
    render_faculty_topbar('Messages', 'Communicate with students and administrators');
    ?>
<?php else: ?>
<aside class="student-sidebar message-sidebar">
    <a class="student-brand" href="/studyLink/<?php echo e($role); ?>/dashboard.php"><span class="student-brand-mark">S</span>StudyLink</a>
    <nav class="student-nav">
        <a href="/studyLink/<?php echo e($role); ?>/dashboard.php"><span class="student-nav-icon"><?php echo study_icon('grid'); ?></span>Dashboard</a>
        <?php if ($role === 'student'): ?>
            <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Courses</a>
            <a href="/studyLink/student/calendar.php"><span class="student-nav-icon"><?php echo study_icon('calendar3'); ?></span>Calendar</a>
            <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('clipboard2-check'); ?></span>Assignments</a>
            <a href="/studyLink/student/quizzes.php"><span class="student-nav-icon"><?php echo study_icon('patch-question'); ?></span>Quizzes</a>
        <?php elseif ($role === 'faculty'): ?>
            <a href="/studyLink/faculty/classes.php"><span class="student-nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Classes</a>
            <a href="/studyLink/faculty/drive/index.php"><span class="student-nav-icon"><?php echo study_icon('folder2-open'); ?></span>Faculty Drive</a>
        <?php else: ?>
            <a href="/studyLink/admin/academic_year/index.php"><span class="student-nav-icon"><?php echo study_icon('calendar3'); ?></span>Academic Year</a>
            <a href="/studyLink/admin/semester/index.php"><span class="student-nav-icon"><?php echo study_icon('collection'); ?></span>Semesters</a>
            <a href="/studyLink/admin/students/index.php"><span class="student-nav-icon"><?php echo study_icon('people'); ?></span>Students</a>
            <a href="/studyLink/admin/faculty/index.php"><span class="student-nav-icon"><?php echo study_icon('person-badge'); ?></span>Faculty</a>
        <?php endif; ?>
        <a class="active" href="<?php echo e($page_url); ?>"><span class="student-nav-icon"><?php echo study_icon('envelope'); ?></span>Messages</a>
    </nav>
    <div class="student-account">
        <div class="student-avatar"><?php echo e(message_initials($_SESSION['fullname'])); ?></div>
        <div><div class="student-name"><?php echo e($_SESSION['fullname']); ?></div><div class="student-id"><?php echo e($account_label); ?></div></div>
    </div>
</aside>

<header class="student-topbar">
    <label class="student-search"><span><?php echo study_icon('search'); ?></span><input id="messageSearch" type="search" placeholder="Search contacts or messages..." autocomplete="off"></label>
    <div class="student-tools"><?php if ($role === 'student') { render_student_notification_button($conn, $user_id); } ?><a class="account-logout" href="/studyLink/auth/logout.php"><span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span><span class="account-logout-label">Log out</span></a></div>
</header>
<?php endif; ?>

<main class="<?php echo $role === 'faculty' ? 'faculty-message-main' : 'student-main'; ?>">
    <div class="<?php echo $role === 'faculty' ? 'faculty-message-shell' : 'student-shell'; ?> messages-page">
        <section class="page-heading messages-heading">
            <div><div class="eyebrow">Communication</div><h1>Messages</h1><p class="lead">Send a message and continue conversations inside StudyLink.</p></div>
            <button class="button compose-button" type="button" id="composeButton"><span>＋</span> New Message</button>
        </section>

        <?php if ($notice): ?><div class="notice"><?php echo e($notice); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice error"><?php echo e($error); ?></div><?php endif; ?>

        <div class="message-workspace card">
            <aside class="contacts-panel">
                <div class="contacts-head">
                    <div><span class="eyebrow">Inbox</span><h2>Conversations</h2></div>
                    <span class="result-count"><?php echo count($recipients); ?></span>
                </div>
                <div class="contact-list">
                    <?php if (empty($recipients)): ?><div class="empty-state compact">No available contacts.</div><?php endif; ?>
                    <?php foreach ($recipients as $recipient): ?>
                        <?php $recipient_id = intval($recipient['id']); $state = $contact_state[$recipient_id] ?? null; ?>
                        <a class="contact-row <?php echo $with === $recipient_id ? 'active' : ''; ?>" href="?with=<?php echo $recipient_id; ?>" data-searchable>
                            <span class="contact-avatar"><?php echo e(message_initials($recipient['fullname'])); ?></span>
                            <span class="contact-copy">
                                <span class="contact-name-line">
                                    <strong><?php echo e($recipient['fullname']); ?></strong>
                                    <?php if ($state && $state['unread'] > 0): ?><b class="unread-count"><?php echo intval($state['unread']); ?></b><?php endif; ?>
                                </span>
                                <small><?php echo e(ucfirst($recipient['role']) . ' · ' . ($recipient['context_name'] ?: 'StudyLink')); ?></small>
                                <em><?php echo e($state ? $state['last_message'] : 'Start a conversation'); ?></em>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </aside>

            <section class="conversation-panel">
                <?php if ($selected_recipient): ?>
                    <div class="conversation-head">
                        <div class="conversation-person">
                            <span class="contact-avatar"><?php echo e(message_initials($selected_recipient['fullname'])); ?></span>
                            <span><strong><?php echo e($selected_recipient['fullname']); ?></strong><small><?php echo e(ucfirst($selected_recipient['role']) . ' · ' . ($selected_recipient['context_name'] ?: 'StudyLink')); ?></small></span>
                        </div>
                        <button class="icon-button" type="button" id="conversationInfo" aria-label="Conversation information">•••</button>
                    </div>

                    <div class="conversation-messages" id="conversationMessages">
                        <?php if (!$messages_table_ready): ?>
                            <div class="conversation-empty"><span>!</span><h3>Messages needs database setup</h3><p>Import <strong>sql/add_messages.sql</strong> once in phpMyAdmin.</p></div>
                        <?php elseif (empty($conversation)): ?>
                            <div class="conversation-empty"><span>✉</span><h3>Start your conversation</h3><p>Send the first message to <?php echo e($selected_recipient['fullname']); ?>.</p></div>
                        <?php endif; ?>

                        <?php $current_day = ''; ?>
                        <?php foreach ($conversation as $message): ?>
                            <?php
                            $outgoing = intval($message['sender_id']) === $user_id;
                            $message_day = date('F j, Y', strtotime($message['created_at']));
                            ?>
                            <?php if ($message_day !== $current_day): $current_day = $message_day; ?><div class="conversation-date"><span><?php echo e($message_day); ?></span></div><?php endif; ?>
                            <article class="message-bubble <?php echo $outgoing ? 'outgoing' : 'incoming'; ?>" data-searchable>
                                <div class="bubble-meta"><strong><?php echo $outgoing ? 'You' : e($message['sender_name']); ?></strong><time><?php echo date('g:i A', strtotime($message['created_at'])); ?></time></div>
                                <h3><?php echo e($message['subject']); ?></h3>
                                <p><?php echo nl2br(e($message['message_body'])); ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <form class="reply-box" method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="recipient_id" value="<?php echo intval($selected_recipient['id']); ?>">
                        <input type="hidden" name="subject" value="<?php echo e($last_subject ?: 'Message from ' . $_SESSION['fullname']); ?>">
                        <textarea name="message_body" maxlength="5000" required placeholder="Write a reply..."></textarea>
                        <button class="send-button" type="submit" <?php echo !$messages_table_ready ? 'disabled' : ''; ?> aria-label="Send reply">➤</button>
                    </form>
                <?php else: ?>
                    <div class="conversation-empty full"><span>✉</span><h3>No conversation selected</h3><p>Choose a contact to begin messaging.</p></div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>

<div class="compose-modal <?php echo $error && $_SERVER['REQUEST_METHOD'] === 'POST' ? 'open' : ''; ?>" id="composeModal">
    <div class="compose-dialog card">
        <button class="modal-close" id="closeCompose" type="button" aria-label="Close">×</button>
        <div class="eyebrow">New Conversation</div>
        <h2>Send a Message</h2>
        <form method="post">
            <?php echo csrf_field(); ?>
            <label>Recipient
                <select name="recipient_id" required>
                    <option value="">Choose a recipient</option>
                    <?php foreach ($recipients as $recipient): ?>
                        <option value="<?php echo intval($recipient['id']); ?>" <?php echo $with === intval($recipient['id']) ? 'selected' : ''; ?>><?php echo e($recipient['fullname'] . ' — ' . ucfirst($recipient['role']) . ' / ' . ($recipient['context_name'] ?: 'StudyLink')); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Subject<input name="subject" maxlength="150" required placeholder="What is this about?"></label>
            <label>Message<textarea name="message_body" maxlength="5000" required placeholder="Write your message..."></textarea></label>
            <button class="button full-button" type="submit" <?php echo !$messages_table_ready ? 'disabled' : ''; ?>>Send Message</button>
        </form>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('composeModal');
    var openButton = document.getElementById('composeButton');
    var closeButton = document.getElementById('closeCompose');
    if (openButton) openButton.onclick = function () { modal.classList.add('open'); };
    if (closeButton) closeButton.onclick = function () { modal.classList.remove('open'); };
    if (modal) modal.onclick = function (event) { if (event.target === modal) modal.classList.remove('open'); };

    var search = document.getElementById('messageSearch');
    if (search) {
        search.oninput = function () {
            var query = this.value.toLowerCase().trim();
            document.querySelectorAll('[data-searchable]').forEach(function (item) {
                item.hidden = query !== '' && !item.textContent.toLowerCase().includes(query);
            });
        };
    }

    var messages = document.getElementById('conversationMessages');
    if (messages) messages.scrollTop = messages.scrollHeight;
})();
</script>
</body>
</html>
