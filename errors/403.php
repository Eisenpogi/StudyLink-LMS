<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

http_response_code(403);
$role = $_SESSION['role'] ?? '';
$destinations = [
    'admin' => '/studyLink/admin/dashboard.php',
    'faculty' => '/studyLink/faculty/dashboard.php',
    'student' => '/studyLink/student/dashboard.php'
];
$home = $destinations[$role] ?? '/studyLink/index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access Denied - StudyLink</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f5f7fc;color:#10213a;font-family:Arial,Helvetica,sans-serif}.error-card{width:min(480px,100%);padding:36px;border:1px solid #e2e7f0;border-radius:18px;background:#fff;box-shadow:0 18px 55px rgba(7,26,56,.09)}.error-code{display:inline-flex;padding:7px 11px;border-radius:999px;background:#fff3cf;color:#765d00;font-size:12px;font-weight:800;letter-spacing:.08em}.error-card h1{margin:18px 0 10px;font-size:30px}.error-card p{margin:0;color:#687386;line-height:1.6}.error-actions{display:flex;gap:10px;margin-top:26px}.error-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 17px;border-radius:10px;background:#071a38;color:#fff;text-decoration:none}.error-actions a.secondary{border:1px solid #e2e7f0;background:#fff;color:#10213a}
    </style>
</head>
<body>
    <main class="error-card">
        <span class="error-code">ERROR 403</span>
        <h1>Access denied</h1>
        <p>Your account does not have permission to open this page or perform this action. If you believe this is incorrect, contact the system administrator.</p>
        <div class="error-actions">
            <a href="<?php echo htmlspecialchars($home, ENT_QUOTES, 'UTF-8'); ?>">Return to dashboard</a>
            <a class="secondary" href="/studyLink/auth/logout.php">Switch account</a>
        </div>
    </main>
</body>
</html>
