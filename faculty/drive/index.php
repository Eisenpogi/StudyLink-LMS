<?php

include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');
include('../../includes/faculty_ui.php');

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);

if (!$faculty) {
    http_response_code(403);
    exit('Faculty record not found.');
}

$faculty_id = intval($faculty['id']);
$current_folder_id = null;
$current_folder = null;

if (isset($_GET['folder']) && $_GET['folder'] !== '') {
    $current_folder_id = intval($_GET['folder']);

    $current_folder_query = mysqli_query($conn, "
        SELECT id, folder_name, parent_id
        FROM faculty_drive_folders
        WHERE id = '$current_folder_id'
        AND faculty_id = '$faculty_id'
        LIMIT 1
    ");

    $current_folder = mysqli_fetch_assoc($current_folder_query);

    if (!$current_folder) {
        header('Location: index.php');
        exit();
    }
}

$breadcrumbs = [];

if ($current_folder_id !== null) {
    $breadcrumb_folder_id = $current_folder_id;

    while ($breadcrumb_folder_id !== null) {
        $breadcrumb_query = mysqli_query($conn, "
            SELECT id, folder_name, parent_id
            FROM faculty_drive_folders
            WHERE id = '$breadcrumb_folder_id'
            AND faculty_id = '$faculty_id'
            LIMIT 1
        ");

        $breadcrumb_folder = mysqli_fetch_assoc($breadcrumb_query);

        if (!$breadcrumb_folder) {
            break;
        }

        $breadcrumbs[] = $breadcrumb_folder;
        $breadcrumb_folder_id = $breadcrumb_folder['parent_id'] !== null
            ? intval($breadcrumb_folder['parent_id'])
            : null;
    }

    $breadcrumbs = array_reverse($breadcrumbs);
}

if ($current_folder_id === null) {
    $folders_query = mysqli_query($conn, "
        SELECT id, folder_name, created_at
        FROM faculty_drive_folders
        WHERE faculty_id = '$faculty_id'
        AND parent_id IS NULL
        ORDER BY folder_name ASC
    ");

    $items_query = mysqli_query($conn, "
        SELECT *
        FROM faculty_drive_items
        WHERE faculty_id = '$faculty_id'
        AND folder_id IS NULL
        AND storage_scope = 'library'
        ORDER BY created_at DESC
    ");
} else {
    $folders_query = mysqli_query($conn, "
        SELECT id, folder_name, created_at
        FROM faculty_drive_folders
        WHERE faculty_id = '$faculty_id'
        AND parent_id = '$current_folder_id'
        ORDER BY folder_name ASC
    ");

    $items_query = mysqli_query($conn, "
        SELECT *
        FROM faculty_drive_items
        WHERE faculty_id = '$faculty_id'
        AND folder_id = '$current_folder_id'
        AND storage_scope = 'library'
        ORDER BY created_at DESC
    ");
}

$storage_query = mysqli_query($conn, "
    SELECT
        COALESCE(SUM(file_size), 0) AS used_storage,
        COUNT(*) AS total_files
    FROM faculty_drive_items
    WHERE faculty_id = '$faculty_id'
    AND item_type = 'file'
");

$storage_data = mysqli_fetch_assoc($storage_query);
$used_storage = intval($storage_data['used_storage']);
$total_files = intval($storage_data['total_files']);
$storage_limit = 1024 * 1024 * 1024;
$storage_percentage = $storage_limit > 0 ? ($used_storage / $storage_limit) * 100 : 0;
$storage_percentage = min(100, $storage_percentage);

function formatFileSize($bytes)
{
    if ($bytes >= 1073741824) {
        return round($bytes / 1073741824, 2) . ' GB';
    }

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 2) . ' KB';
    }

    return $bytes . ' B';
}

function driveFileType($mime_type, $original_filename)
{
    $extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));

    if ($mime_type === 'application/pdf' || $extension === 'pdf') {
        return ['PDF', 'pdf'];
    }

    if (in_array($extension, ['doc', 'docx'])) {
        return ['Word', 'word'];
    }

    if (in_array($extension, ['xls', 'xlsx', 'csv'])) {
        return ['Spreadsheet', 'sheet'];
    }

    if (in_array($extension, ['ppt', 'pptx'])) {
        return ['Presentation', 'slides'];
    }

    if (strpos($mime_type, 'image/') === 0) {
        return ['Image', 'image'];
    }

    if (in_array($extension, ['zip', 'rar', '7z'])) {
        return ['Archive', 'archive'];
    }

    if (in_array($extension, ['txt', 'rtf'])) {
        return ['Document', 'text'];
    }

    return [strtoupper($extension ?: 'File'), 'file'];
}

function driveIcon($type)
{
    $icons = [
        'folder' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6.75A1.75 1.75 0 0 1 4.75 5h5l2 2h7.5A1.75 1.75 0 0 1 21 8.75v8.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Z"/></svg>',
        'pdf' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4v14.5H6V2.75Zm8 1.5v3.5h3.5M8.5 15.5h7M8.5 12.5h7"/></svg>',
        'word' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4v14.5H6V2.75Zm8 1.5v3.5h3.5M8.5 12l1.25 5 2.25-4 2.25 4 1.25-5"/></svg>',
        'sheet' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4v14.5H6V2.75Zm8 1.5v3.5h3.5M8.5 11h7v6h-7v-6Zm3.5 0v6M8.5 14h7"/></svg>',
        'slides' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4v14.5H6V2.75Zm8 1.5v3.5h3.5M8.5 11h7v5h-7v-5Zm3.5 5v2M10 18h4"/></svg>',
        'image' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4V5Zm2.5 11 3.5-4 2.5 2.5 2-2 3 3.5M8.5 9.25h.01"/></svg>',
        'archive' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5V4Zm5-1h4M10 7h4M10 10h4M11 13h2v4h-2v-4Z"/></svg>',
        'text' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4v14.5H6V2.75Zm8 1.5v3.5h3.5M8.5 12h7M8.5 15h7M8.5 18h4.5"/></svg>',
        'file' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2.75h8l4 4v14.5H6V2.75Zm8 1.5v3.5h3.5M8.5 13h7M8.5 16h5"/></svg>'
    ];

    return $icons[$type] ?? $icons['file'];
}

$folder_count = mysqli_num_rows($folders_query);
$item_count = mysqli_num_rows($items_query);
$current_title = $current_folder ? $current_folder['folder_name'] : 'My Drive';
$parent_url = $current_folder && $current_folder['parent_id'] !== null
    ? 'index.php?folder=' . intval($current_folder['parent_id'])
    : 'index.php';

$notice = '';
$notice_type = 'success';

if (isset($_GET['created'])) {
    $notice = 'Folder created successfully.';
} elseif (isset($_GET['uploaded'])) {
    $notice = 'File uploaded successfully.';
} elseif (isset($_GET['folder_uploaded'])) {
    $uploaded_count = max(1, intval($_GET['count'] ?? 1));
    $notice = $uploaded_count . ' folder file' . ($uploaded_count === 1 ? '' : 's') . ' uploaded successfully.';
} elseif (isset($_GET['deleted'])) {
    $notice = 'File deleted successfully.';
} elseif (isset($_GET['error'])) {
    $notice_type = 'error';
    $messages = [
        'empty_name' => 'Folder name is required.',
        'duplicate' => 'A folder with the same name already exists in this location.',
        'invalid_parent' => 'The selected folder is invalid.',
        'no_file' => 'Please select a file.',
        'upload_failed' => 'The file could not be uploaded.',
        'invalid_type' => 'This file type is not allowed.',
        'file_too_large' => 'The selected file exceeds the 100 MB limit.',
        'storage_limit' => 'You have reached the 1 GB storage limit.',
        'folder_failed' => 'The upload folder could not be prepared.',
        'file_not_found' => 'The requested file was not found.',
        'invalid_request' => 'The request is invalid.',
        'delete_failed' => 'The file could not be deleted.',
        'file_in_use' => 'This file is used by a learning material or assignment. Remove those references first.',
        'too_many_files' => 'The selected folder contains too many files for one upload. Choose a smaller folder or upload it in parts.',
        'invalid_folder' => 'The selected folder structure is invalid.'
    ];
    $notice = $messages[$_GET['error']] ?? 'Something went wrong. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faculty Drive - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-drive.css">
</head>
<body class="faculty-interface faculty-drive-page">
<?php render_faculty_sidebar('drive', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Faculty Drive', 'Private academic file storage'); ?>

<main class="faculty-main">
    <div class="faculty-shell drive-shell">
        <section class="drive-page-heading">
            <div>
                <div class="faculty-eyebrow">Academic File Management</div>
                <h1>Faculty Drive</h1>
                <p>Keep course files organized, reusable, and ready for your classes.</p>
            </div>
            <div class="drive-heading-actions">
                <button class="drive-button secondary" type="button" data-dialog-open="createFolderDialog">
                    <span class="button-icon"><?php echo driveIcon('folder'); ?></span>
                    New folder
                </button>
                <button class="drive-button primary" type="button" data-dialog-open="uploadFileDialog">
                    <span class="button-icon"><?php echo driveIcon('file'); ?></span>
                    Upload file
                </button>
                <button class="drive-button primary" type="button" data-dialog-open="uploadFolderDialog">
                    <span class="button-icon"><?php echo driveIcon('folder'); ?></span>
                    Upload folder
                </button>
            </div>
        </section>

        <?php if ($notice !== ''): ?>
            <div class="drive-notice <?php echo $notice_type === 'error' ? 'error' : ''; ?>" role="status">
                <span><?php echo $notice_type === 'error' ? '!' : '✓'; ?></span>
                <?php echo htmlspecialchars($notice); ?>
            </div>
        <?php endif; ?>

        <section class="drive-summary-grid" aria-label="Drive summary">
            <article class="drive-summary-card storage">
                <div class="summary-icon"><?php echo driveIcon('archive'); ?></div>
                <div class="summary-copy">
                    <span>Storage used</span>
                    <strong><?php echo formatFileSize($used_storage); ?></strong>
                    <small>of 1 GB faculty storage</small>
                </div>
                <div class="drive-storage-ring" style="--storage: <?php echo round($storage_percentage, 2); ?>%">
                    <span><?php echo round($storage_percentage); ?>%</span>
                </div>
            </article>
            <article class="drive-summary-card">
                <div class="summary-icon blue"><?php echo driveIcon('file'); ?></div>
                <div class="summary-copy">
                    <span>Total files</span>
                    <strong><?php echo $total_files; ?></strong>
                    <small>Across all folders</small>
                </div>
            </article>
            <article class="drive-summary-card">
                <div class="summary-icon gold"><?php echo driveIcon('folder'); ?></div>
                <div class="summary-copy">
                    <span>Current folder</span>
                    <strong><?php echo $folder_count + $item_count; ?></strong>
                    <small><?php echo $folder_count; ?> folders · <?php echo $item_count; ?> files</small>
                </div>
            </article>
        </section>

        <section class="drive-workspace">
            <div class="drive-location-bar">
                <nav class="drive-breadcrumbs" aria-label="Current folder">
                    <?php if ($current_folder): ?>
                        <a class="drive-back" href="<?php echo htmlspecialchars($parent_url); ?>" aria-label="Go to parent folder">‹</a>
                    <?php endif; ?>
                    <a href="index.php">My Drive</a>
                    <?php foreach ($breadcrumbs as $index => $breadcrumb): ?>
                        <span>/</span>
                        <?php if ($index === count($breadcrumbs) - 1): ?>
                            <strong><?php echo htmlspecialchars($breadcrumb['folder_name']); ?></strong>
                        <?php else: ?>
                            <a href="index.php?folder=<?php echo intval($breadcrumb['id']); ?>">
                                <?php echo htmlspecialchars($breadcrumb['folder_name']); ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
                <div class="drive-toolbar">
                    <label class="drive-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>
                        <input id="driveSearch" type="search" placeholder="Search this folder..." autocomplete="off">
                    </label>
                    <div class="drive-view-switch" aria-label="View options">
                        <button class="active" type="button" data-view="grid" aria-label="Grid view">
                            <svg viewBox="0 0 24 24"><path d="M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z"/></svg>
                        </button>
                        <button type="button" data-view="list" aria-label="List view">
                            <svg viewBox="0 0 24 24"><path d="M4 5h3v3H4V5Zm6 0h10M4 10.5h3v3H4v-3Zm6 1.5h10M4 16h3v3H4v-3Zm6 1.5h10"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="drive-content">
                <?php if ($folder_count === 0 && $item_count === 0): ?>
                    <div class="drive-empty">
                        <div class="empty-icon"><?php echo driveIcon('folder'); ?></div>
                        <h2>This folder is ready for your files</h2>
                        <p>Create a folder to organize a course, or upload your first resource.</p>
                        <div>
                            <button class="drive-button secondary" type="button" data-dialog-open="createFolderDialog">Create folder</button>
                            <button class="drive-button primary" type="button" data-dialog-open="uploadFileDialog">Upload file</button>
                            <button class="drive-button primary" type="button" data-dialog-open="uploadFolderDialog">Upload folder</button>
                        </div>
                    </div>
                <?php else: ?>
                    <?php if ($folder_count > 0): ?>
                        <div class="drive-section-heading">
                            <h2>Folders</h2>
                            <span><?php echo $folder_count; ?> in this location</span>
                        </div>
                        <div class="drive-item-grid" data-drive-group>
                            <?php while ($folder = mysqli_fetch_assoc($folders_query)): ?>
                                <a
                                    class="drive-item folder"
                                    href="index.php?folder=<?php echo intval($folder['id']); ?>"
                                    data-drive-item
                                    data-search="<?php echo htmlspecialchars(strtolower($folder['folder_name'])); ?>"
                                >
                                    <span class="drive-item-icon folder-icon"><?php echo driveIcon('folder'); ?></span>
                                    <span class="drive-item-copy">
                                        <strong title="<?php echo htmlspecialchars($folder['folder_name']); ?>">
                                            <?php echo htmlspecialchars($folder['folder_name']); ?>
                                        </strong>
                                        <small>Folder · <?php echo date('M d, Y', strtotime($folder['created_at'])); ?></small>
                                    </span>
                                    <span class="drive-chevron">›</span>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($item_count > 0): ?>
                        <div class="drive-section-heading files-heading">
                            <h2>Files</h2>
                            <span><?php echo $item_count; ?> in this location</span>
                        </div>
                        <div class="drive-item-grid" data-drive-group>
                            <?php while ($item = mysqli_fetch_assoc($items_query)): ?>
                                <?php
                                [$file_label, $file_type] = driveFileType(
                                    $item['mime_type'] ?? '',
                                    $item['original_filename'] ?? ''
                                );
                                ?>
                                <article
                                    class="drive-item file <?php echo htmlspecialchars($file_type); ?>"
                                    data-drive-item
                                    data-search="<?php echo htmlspecialchars(strtolower(($item['item_name'] ?? '') . ' ' . $file_label)); ?>"
                                >
                                    <span class="drive-item-icon"><?php echo driveIcon($file_type); ?></span>
                                    <span class="drive-item-copy">
                                        <strong title="<?php echo htmlspecialchars($item['item_name']); ?>">
                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                        </strong>
                                        <small>
                                            <?php echo htmlspecialchars($file_label); ?> ·
                                            <?php echo formatFileSize(intval($item['file_size'])); ?> ·
                                            <?php echo date('M d, Y', strtotime($item['created_at'])); ?>
                                        </small>
                                    </span>
                                    <?php if (!empty($item['file_path'])): ?>
                                        <span class="drive-item-actions">
                                            <a
                                                href="<?php echo htmlspecialchars($item['file_path']); ?>"
                                                target="_blank"
                                                rel="noopener"
                                                class="drive-open"
                                            >Open</a>
                                            <?php if ($item['item_type'] === 'file'): ?>
                                                <form action="delete_file.php" method="POST" onsubmit="return confirm('Delete this file permanently?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="item_id" value="<?php echo intval($item['id']); ?>">
                                                    <input type="hidden" name="folder_id" value="<?php echo $current_folder_id ?? ''; ?>">
                                                    <button type="submit" class="drive-delete">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </article>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>

                    <div class="drive-no-results" id="driveNoResults" hidden>
                        <div class="empty-icon"><?php echo driveIcon('file'); ?></div>
                        <strong>No results in this folder</strong>
                        <span>Try another keyword or clear the search to view all items.</span>
                        <button class="drive-clear-search" id="driveClearSearch" type="button">Clear search</button>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<dialog class="drive-dialog" id="createFolderDialog">
    <form action="create_folder.php" method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="parent_id" value="<?php echo $current_folder_id ?? ''; ?>">
        <div class="dialog-heading">
            <div class="dialog-icon"><?php echo driveIcon('folder'); ?></div>
            <div><h2>Create a folder</h2><p>Organize files inside <?php echo htmlspecialchars($current_title); ?>.</p></div>
            <button type="button" data-dialog-close aria-label="Close">×</button>
        </div>
        <label class="drive-field">
            <span>Folder name</span>
            <input type="text" name="folder_name" maxlength="150" placeholder="e.g. Midterm Resources" required autofocus>
        </label>
        <div class="dialog-actions">
            <button class="drive-button ghost" type="button" data-dialog-close>Cancel</button>
            <button class="drive-button primary" type="submit">Create folder</button>
        </div>
    </form>
</dialog>

<dialog class="drive-dialog" id="uploadFileDialog">
    <form action="upload_file.php" method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="folder_id" value="<?php echo $current_folder_id ?? ''; ?>">
        <div class="dialog-heading">
            <div class="dialog-icon upload"><?php echo driveIcon('file'); ?></div>
            <div><h2>Upload a file</h2><p>Add a resource to <?php echo htmlspecialchars($current_title); ?>.</p></div>
            <button type="button" data-dialog-close aria-label="Close">×</button>
        </div>
        <label class="drive-upload-field" id="driveUploadField">
            <span class="upload-field-icon"><?php echo driveIcon('file'); ?></span>
            <strong id="uploadFileName">Choose a file to upload</strong>
            <small>PDF, Office, image, text, ZIP, RAR or 7Z · maximum 100 MB</small>
            <input id="driveFileInput" type="file" name="drive_file" required>
        </label>
        <div class="dialog-actions">
            <button class="drive-button ghost" type="button" data-dialog-close>Cancel</button>
            <button class="drive-button primary" type="submit">Upload file</button>
        </div>
    </form>
</dialog>

<dialog class="drive-dialog" id="uploadFolderDialog">
    <form action="upload_folder.php" method="POST" enctype="multipart/form-data" id="folderUploadForm">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="folder_id" value="<?php echo $current_folder_id ?? ''; ?>">
        <div id="folderRelativePaths"></div>
        <div class="dialog-heading">
            <div class="dialog-icon upload"><?php echo driveIcon('folder'); ?></div>
            <div><h2>Upload a folder</h2><p>Keep the folder and subfolder structure inside <?php echo htmlspecialchars($current_title); ?>.</p></div>
            <button type="button" data-dialog-close aria-label="Close">×</button>
        </div>
        <label class="drive-upload-field" id="driveFolderUploadField">
            <span class="upload-field-icon"><?php echo driveIcon('folder'); ?></span>
            <strong id="uploadFolderName">Choose a folder</strong>
            <small id="uploadFolderSummary">Allowed academic file types · 100 MB per file · total counts toward your 1 GB storage</small>
            <input id="driveFolderInput" type="file" name="folder_files[]" webkitdirectory directory multiple required>
        </label>
        <div class="dialog-actions">
            <button class="drive-button ghost" type="button" data-dialog-close>Cancel</button>
            <button class="drive-button primary" type="submit">Upload folder</button>
        </div>
    </form>
</dialog>

<script>
(function () {
    var dialogs = document.querySelectorAll('.drive-dialog');

    document.querySelectorAll('[data-dialog-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = document.getElementById(button.dataset.dialogOpen);
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });

    document.querySelectorAll('[data-dialog-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            var dialog = button.closest('dialog');
            if (dialog) {
                dialog.close();
            }
        });
    });

    dialogs.forEach(function (dialog) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                dialog.close();
            }
        });
    });

    var search = document.getElementById('driveSearch');
    var noResults = document.getElementById('driveNoResults');
    var clearSearch = document.getElementById('driveClearSearch');

    if (search) {
        var filterDriveItems = function () {
            var query = search.value.toLowerCase().trim();
            var visible = 0;

            document.querySelectorAll('[data-drive-item]').forEach(function (item) {
                var match = !query || item.dataset.search.indexOf(query) !== -1;
                item.hidden = !match;
                if (match) {
                    visible++;
                }
            });

            if (noResults) {
                noResults.hidden = query === '' || visible !== 0;
            }
        };

        search.addEventListener('input', filterDriveItems);

        if (clearSearch) {
            clearSearch.addEventListener('click', function () {
                search.value = '';
                filterDriveItems();
                search.focus();
            });
        }
    }

    document.querySelectorAll('[data-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('[data-view]').forEach(function (item) {
                item.classList.toggle('active', item === button);
            });
            document.querySelectorAll('[data-drive-group]').forEach(function (group) {
                group.classList.toggle('list-view', button.dataset.view === 'list');
            });
            localStorage.setItem('facultyDriveView', button.dataset.view);
        });
    });

    var savedView = localStorage.getItem('facultyDriveView');
    var savedButton = document.querySelector('[data-view="' + savedView + '"]');
    if (savedButton) {
        savedButton.click();
    }

    var fileInput = document.getElementById('driveFileInput');
    var fileName = document.getElementById('uploadFileName');
    var uploadField = document.getElementById('driveUploadField');

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var selected = fileInput.files && fileInput.files[0];
            fileName.textContent = selected ? selected.name : 'Choose a file to upload';
            uploadField.classList.toggle('selected', !!selected);
        });
    }

    var folderInput = document.getElementById('driveFolderInput');
    var folderName = document.getElementById('uploadFolderName');
    var folderSummary = document.getElementById('uploadFolderSummary');
    var folderField = document.getElementById('driveFolderUploadField');
    var relativePaths = document.getElementById('folderRelativePaths');

    if (folderInput) {
        folderInput.addEventListener('change', function () {
            var files = Array.prototype.slice.call(folderInput.files || []);
            relativePaths.innerHTML = '';
            files.forEach(function (file) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'relative_paths[]';
                hidden.value = file.webkitRelativePath || file.name;
                relativePaths.appendChild(hidden);
            });
            var firstPath = files[0] ? (files[0].webkitRelativePath || files[0].name) : '';
            var rootName = firstPath.indexOf('/') >= 0 ? firstPath.split('/')[0] : firstPath;
            folderName.textContent = files.length ? rootName : 'Choose a folder';
            folderSummary.textContent = files.length
                ? files.length + ' file' + (files.length === 1 ? '' : 's') + ' selected · folder structure will be preserved'
                : 'Allowed academic file types · 100 MB per file · total counts toward your 1 GB storage';
            folderField.classList.toggle('selected', files.length > 0);
        });
    }
})();
</script>
</body>
</html>
