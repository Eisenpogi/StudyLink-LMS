<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/ui_icons.php';
include '../includes/academic_term.php';

$user_id = intval($_SESSION['user_id']);
$assignment_id = isset($_GET['id']) ? intval($_GET['id']) : intval($_POST['assignment_id'] ?? 0);

$statement = mysqli_prepare($conn, '
    SELECT a.id, a.title, a.instructions, a.due_date, a.class_assignment_id,
           s.id AS student_id, asm.id AS submission_id,
           asm.file_path, asm.file_name, asm.student_comment, asm.submitted_at
    FROM assignments a
    INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
    INNER JOIN students s ON s.section_id = ca.section_id AND s.user_id = ?
    LEFT JOIN assignment_submissions asm
      ON asm.assignment_id = a.id AND asm.student_id = s.id
    WHERE a.id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($statement, 'ii', $user_id, $assignment_id);
mysqli_stmt_execute($statement);
$assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$assignment) {
    http_response_code(403);
    exit('Assignment not found or you are not allowed to submit to this class.');
}

$module_id = intval($_GET['module_id'] ?? $_POST['module_id'] ?? 0);
$module_class_id = intval($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$return_url = 'class_view.php?id=' . intval($assignment['class_assignment_id']) . '&tab=assignments';
$return_label = 'Back to Assignments';
if ($module_id > 0 && $module_class_id === intval($assignment['class_assignment_id'])) {
    $return_url = 'module_view.php?id=' . $module_id . '&class_id=' . $module_class_id;
    $return_label = 'Back to Module';
}

$has_submission = !empty($assignment['submission_id']);
$deadline_passed = strtotime($assignment['due_date']) < time();
$term = studylink_class_term($conn, intval($assignment['class_assignment_id']));
$term_writable = $term && $term['term_status'] === 'current';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$term_writable) {
        $error = 'This Academic Term is read-only. Your existing submission remains available for viewing.';
    } elseif ($has_submission && $deadline_passed) {
        exit('Resubmission is no longer allowed after the deadline.');
    } else {
        $comment = trim($_POST['student_comment'] ?? '');
    }
    if (!$term_writable) {
        // Keep the page in read-only mode.
    } elseif (strlen($comment) > 1000) {
        $error = 'Student comment must not exceed 1,000 characters.';
    } elseif (!isset($_FILES['submission_file']) ||
              $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please select a valid assignment file.';
    } else {
        $file = $_FILES['submission_file'];
        $max_size = 25 * 1024 * 1024;
        $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'zip', 'jpg', 'jpeg', 'png'];
        $allowed_mimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip-compressed',
            'image/jpeg',
            'image/png'
        ];

        $original_name = basename($file['name']);
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        if ($file['size'] <= 0 || $file['size'] > $max_size) {
            $error = 'Maximum assignment file size is 25 MB.';
        } elseif (!in_array($extension, $allowed_extensions, true) ||
                  !in_array($mime_type, $allowed_mimes, true)) {
            $error = 'Allowed files: PDF, Word, PowerPoint, Excel, ZIP, JPG, and PNG.';
        } else {
            $student_id = intval($assignment['student_id']);
            $upload_root = realpath(__DIR__ . '/../assets/uploads');
            if (!$upload_root) {
                $upload_root = __DIR__ . '/../assets/uploads';
            }
            $target_directory = $upload_root . '/submissions/' . $assignment_id . '/' . $student_id;

            if (!is_dir($target_directory) &&
                !mkdir($target_directory, 0755, true)) {
                $error = 'Unable to prepare the submission folder.';
            } else {
                $stored_name = bin2hex(random_bytes(16)) . '.' . $extension;
                $physical_path = $target_directory . '/' . $stored_name;
                $relative_path = 'assets/uploads/submissions/' . $assignment_id . '/' . $student_id . '/' . $stored_name;
                $submitted_at = date('Y-m-d H:i:s');
                $submission_status = strtotime($submitted_at) <= strtotime($assignment['due_date'])
                    ? 'on_time'
                    : 'late';

                if (!move_uploaded_file($file['tmp_name'], $physical_path)) {
                    $error = 'The assignment file could not be saved.';
                } else {
                    mysqli_begin_transaction($conn);
                    try {
                        if ($has_submission) {
                            $update = mysqli_prepare($conn, '
                                UPDATE assignment_submissions
                                SET file_path = ?, file_name = ?, file_size = ?,
                                    mime_type = ?, student_comment = ?,
                                    submitted_at = ?, submission_status = ?,
                                    score = NULL, remarks = NULL, graded_at = NULL,
                                    graded_by = NULL
                                WHERE id = ? AND student_id = ?
                            ');
                            mysqli_stmt_bind_param(
                                $update,
                                'ssissssii',
                                $relative_path,
                                $original_name,
                                $file['size'],
                                $mime_type,
                                $comment,
                                $submitted_at,
                                $submission_status,
                                $assignment['submission_id'],
                                $student_id
                            );
                            if (!mysqli_stmt_execute($update)) {
                                throw new Exception(mysqli_stmt_error($update));
                            }
                            mysqli_stmt_close($update);
                        } else {
                            $insert = mysqli_prepare($conn, '
                                INSERT INTO assignment_submissions
                                    (assignment_id, student_id, file_path, file_name,
                                     file_size, mime_type, student_comment,
                                     submitted_at, submission_status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ');
                            mysqli_stmt_bind_param(
                                $insert,
                                'iississss',
                                $assignment_id,
                                $student_id,
                                $relative_path,
                                $original_name,
                                $file['size'],
                                $mime_type,
                                $comment,
                                $submitted_at,
                                $submission_status
                            );
                            if (!mysqli_stmt_execute($insert)) {
                                throw new Exception(mysqli_stmt_error($insert));
                            }
                            mysqli_stmt_close($insert);
                        }

                        mysqli_commit($conn);

                        if ($has_submission && !empty($assignment['file_path'])) {
                            $old_path = realpath(__DIR__ . '/../' . ltrim($assignment['file_path'], '/'));
                            $submission_root = realpath(__DIR__ . '/../assets/uploads/submissions');
                            if ($old_path && $submission_root &&
                                strpos($old_path, $submission_root . DIRECTORY_SEPARATOR) === 0 &&
                                is_file($old_path)) {
                                unlink($old_path);
                            }
                        }

                        redirect_with_flash($return_url, 'success', 'Your assignment was submitted successfully.');
                    } catch (Throwable $exception) {
                        mysqli_rollback($conn);
                        if (is_file($physical_path)) {
                            unlink($physical_path);
                        }
                        $error = 'The submission could not be recorded. Please try again.';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Assignment - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <style>
        :root{--navy:#071d49;--navy2:#12366f;--gold:#f4c515;--ink:#10213a;--muted:#667085;--line:#e5eaf2;--bg:#f5f7fc;--blue:#eaf1ff;--danger:#b52d35;--success:#16794f}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,"Segoe UI",Arial,sans-serif;line-height:1.5}a{color:inherit}.topbar{height:72px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 7vw}.brand{display:flex;align-items:center;gap:11px;color:var(--navy);font-size:20px;font-weight:800;text-decoration:none}.brand-mark{width:36px;height:36px;border-radius:11px;background:var(--navy);color:#fff;display:grid;place-items:center;box-shadow:inset 0 -4px 0 var(--gold)}.student-label{font-size:12px;font-weight:750;color:var(--muted);letter-spacing:.08em;text-transform:uppercase}
        .page{width:min(1080px,calc(100% - 32px));margin:30px auto 60px}.back{display:inline-flex;gap:8px;text-decoration:none;color:#58667a;font-weight:750;font-size:14px;margin-bottom:18px}.hero{background:linear-gradient(135deg,var(--navy),var(--navy2));border-radius:22px;padding:30px 34px;color:#fff;position:relative;overflow:hidden;box-shadow:0 18px 45px rgba(7,29,73,.15)}.hero:after{content:"";position:absolute;width:210px;height:210px;border-radius:50%;right:-75px;top:-95px;background:rgba(244,197,21,.14)}.eyebrow{font-size:12px;font-weight:850;letter-spacing:.16em;color:#f7d94d;text-transform:uppercase}.hero h1{font-size:clamp(25px,4vw,38px);line-height:1.15;margin:8px 0 12px}.due{display:inline-flex;gap:8px;background:rgba(255,255,255,.12);padding:8px 12px;border-radius:999px;font-size:14px}
        .grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:24px;margin-top:24px}.panel{background:#fff;border:1px solid var(--line);border-radius:18px;padding:26px;box-shadow:0 8px 24px rgba(16,33,58,.05)}.panel h2{margin:0 0 5px;font-size:21px}.subtext{margin:0 0 24px;color:var(--muted);font-size:14px}.alert{padding:13px 15px;border-radius:12px;margin:0 0 18px;font-size:14px;font-weight:650}.error{background:#fff0f1;color:#a5262d;border:1px solid #ffd4d7}.warning{background:#fff8db;color:#735900;border:1px solid #f7df79}.current{padding:13px;background:var(--bg);border-radius:12px;margin-bottom:18px;font-size:13px}.current a{font-weight:800;color:var(--navy);word-break:break-word}
        label{display:block;font-weight:750;font-size:14px;margin:0 0 8px}.file-box{border:2px dashed #cbd5e5;background:#fafcff;border-radius:15px;padding:25px;text-align:center;transition:.2s}.file-box:hover,.file-box:focus-within{border-color:#6684bd;background:#f4f8ff}.file-box input{width:100%;max-width:420px}.file-title{font-weight:800;margin-bottom:7px}.help{font-size:12px;color:var(--muted);margin-top:8px}.field{margin-top:22px}textarea{width:100%;resize:vertical;min-height:120px;border:1px solid #cfd7e5;border-radius:12px;padding:13px 14px;font:inherit;outline:none}textarea:focus{border-color:#5375b5;box-shadow:0 0 0 3px rgba(83,117,181,.12)}.actions{display:flex;justify-content:flex-end;gap:12px;margin-top:22px}.btn{border:0;border-radius:11px;padding:12px 19px;font-weight:800;font-size:14px;cursor:pointer;text-decoration:none}.btn-secondary{background:#edf1f7;color:#344054}.btn-primary{background:var(--navy);color:#fff;box-shadow:0 8px 18px rgba(7,29,73,.18)}
        .side-title{font-size:17px;margin:0 0 16px}.checklist{list-style:none;padding:0;margin:0;display:grid;gap:14px}.checklist li{display:flex;gap:10px;color:#526075;font-size:13px}.check{width:22px;height:22px;flex:0 0 22px;border-radius:50%;display:grid;place-items:center;background:#e8f7f0;color:var(--success);font-weight:900}
        @media(max-width:760px){.topbar{padding:0 18px}.student-label{display:none}.page{margin-top:20px}.hero{padding:24px}.grid{grid-template-columns:1fr}.panel{padding:20px}.actions{flex-direction:column-reverse}.btn{width:100%;text-align:center}}
    </style>
</head>
<body>
<header class="topbar"><a class="brand" href="/studyLink/student/dashboard.php"><span class="brand-mark">S</span>StudyLink</a><span class="student-label">Student Workspace</span></header>
<main class="page">
<a class="back" href="<?php echo e($return_url); ?>"><?php echo study_icon('arrow-left'); ?> <?php echo e($return_label); ?></a>
<section class="hero"><div class="eyebrow"><?php echo $has_submission ? 'Update Submission' : 'Assignment Submission'; ?></div><h1><?php echo htmlspecialchars($assignment['title']); ?></h1><div class="due">◷ Due <?php echo htmlspecialchars(date('M d, Y · g:i A', strtotime($assignment['due_date']))); ?></div></section>

<?php if (!empty($error)): ?>
    <div class="alert error" style="margin-top:20px"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="grid"><section class="panel">
<h2><?php echo $has_submission ? 'Replace your file' : 'Upload your work'; ?></h2>
<p class="subtext">Review the file before submitting. The submission date and time will be recorded automatically.</p>
<?php if (!$term_writable): ?>
    <div class="alert warning">
        This Academic Term is archived or no longer current. You may view your existing work, but new submissions and replacements are disabled.
    </div>
    <?php if ($has_submission): ?>
        <div class="current"><strong>Recorded submission</strong><br>
            <a href="preview_file.php?type=submission&id=<?php echo intval($assignment['submission_id']); ?>" target="_blank" rel="noopener">
                <?php echo htmlspecialchars($assignment['file_name']); ?>
            </a>
        </div>
    <?php endif; ?>
<?php elseif ($has_submission && $deadline_passed): ?>
    <div class="alert warning">The deadline has passed. Your existing submission can no longer be replaced.</div>
<?php else: ?>
    <?php if ($has_submission): ?>
        <div class="current"><strong>Current submission</strong><br>
            <a href="preview_file.php?type=submission&id=<?php echo intval($assignment['submission_id']); ?>" target="_blank" rel="noopener">
                <?php echo htmlspecialchars($assignment['file_name']); ?>
            </a>
        </div>
    <?php elseif ($deadline_passed): ?>
        <div class="alert warning">
            The deadline has passed. This submission will be recorded as late.
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
        <input type="hidden" name="module_id" value="<?php echo $module_id; ?>">
        <input type="hidden" name="class_id" value="<?php echo $module_class_id; ?>">

        <label for="submission_file">Assignment file</label>
        <div class="file-box"><div class="file-title">Choose a file from your device</div>
        <input id="submission_file" type="file" name="submission_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.zip,.jpg,.jpeg,.png" required>
        <div class="help">PDF, Word, PowerPoint, Excel, ZIP, JPG or PNG · Maximum 25 MB</div></div>

        <div class="field"><label for="student_comment">Comment <span style="font-weight:500;color:var(--muted)">(optional)</span></label>
        <textarea id="student_comment" name="student_comment" maxlength="1000" placeholder="Add a short note for your instructor..."><?php
            echo htmlspecialchars($_POST['student_comment'] ?? ($assignment['student_comment'] ?? ''));
        ?></textarea></div>

        <div class="actions"><a class="btn btn-secondary" href="<?php echo e($return_url); ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><?php echo $has_submission ? 'Replace Submission' : 'Submit Assignment'; ?></button></div>
    </form>
<?php endif; ?>
</section>
<aside class="panel"><h3 class="side-title">Before you submit</h3><ul class="checklist">
<li><span class="check"><?php echo study_icon('check-circle'); ?></span><span>Make sure you selected the correct and final file.</span></li>
<li><span class="check"><?php echo study_icon('check-circle'); ?></span><span>Keep the file under the 25 MB size limit.</span></li>
<li><span class="check"><?php echo study_icon('check-circle'); ?></span><span>You can replace it before the deadline.</span></li>
<li><span class="check"><?php echo study_icon('check-circle'); ?></span><span>You can preview your own submission after uploading.</span></li>
</ul></aside></div>
</main>
</body>
</html>
