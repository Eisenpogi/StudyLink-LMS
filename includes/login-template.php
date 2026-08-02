<?php
require_once __DIR__ . '/login_appearance.php';
$login_visual_url = studylink_login_visual_url('../assets/uploads/login/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - StudyLink</title>
    <link rel="stylesheet" href="../assets/css/login.css">
</head>
<body>
<main class="login-page">
    <div class="login-shell">
        <section class="login-visual<?php echo $login_visual_url !== '' ? ' has-image' : ''; ?>" aria-label="StudyLink portal visual">
            <?php if ($login_visual_url !== ''): ?>
                <img src="<?php echo htmlspecialchars($login_visual_url); ?>" alt="">
            <?php endif; ?>
        </section>

        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-card">
                <header class="login-brand">
                    <div class="brand-mark" aria-hidden="true">
                        <div class="brand-mark-inner">
                            <svg class="bi"><use href="../assets/icons/bootstrap-icons.svg#book"></use></svg>
                        </div>
                    </div>
                    <div>
                        <p class="brand-name">StudyLink</p>
                        <h1 class="login-title" id="login-title">Welcome back</h1>
                        <p class="login-role"><?php echo htmlspecialchars($role_label); ?> Portal</p>
                    </div>
                </header>

                <?php if ($error_message !== ''): ?>
                    <div class="login-alert" role="alert">
                        <svg class="bi" aria-hidden="true"><use href="../assets/icons/bootstrap-icons.svg#exclamation-circle"></use></svg>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                <?php endif; ?>

                <form class="login-form" id="loginForm" action="../auth/login_process.php" method="POST">
                    <input type="hidden" name="portal" value="<?php echo htmlspecialchars($portal); ?>">

                    <div class="form-group">
                        <label class="form-label" for="username"><?php echo htmlspecialchars($username_label); ?></label>
                        <div class="input-wrap">
                            <svg class="bi input-icon" aria-hidden="true"><use href="../assets/icons/bootstrap-icons.svg#<?php echo htmlspecialchars($username_icon); ?>"></use></svg>
                            <input
                                class="login-input"
                                id="username"
                                type="text"
                                name="username"
                                placeholder="<?php echo htmlspecialchars($username_placeholder); ?>"
                                autocomplete="username"
                                maxlength="100"
                                autofocus
                                required
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="form-label-row">
                            <label class="form-label" for="password">Password</label>
                            <button class="forgot-link" id="forgotPassword" type="button" aria-expanded="false">
                                Forgot Password?
                            </button>
                        </div>
                        <div class="input-wrap">
                            <svg class="bi input-icon" aria-hidden="true"><use href="../assets/icons/bootstrap-icons.svg#lock"></use></svg>
                            <input
                                class="login-input"
                                id="password"
                                type="password"
                                name="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                            >
                            <button
                                class="password-toggle"
                                id="passwordToggle"
                                type="button"
                                aria-label="Show password"
                                aria-pressed="false"
                            >
                                <svg class="bi" aria-hidden="true"><use id="passwordIcon" href="../assets/icons/bootstrap-icons.svg#eye"></use></svg>
                            </button>
                        </div>
                    </div>

                    <p class="login-help" id="passwordHelp">
                        Please contact the school administrator to reset your password.
                    </p>

                    <button class="login-button" id="signInButton" type="submit">
                        <span>Sign In</span>
                        <svg class="bi" aria-hidden="true"><use href="../assets/icons/bootstrap-icons.svg#box-arrow-in-right"></use></svg>
                    </button>
                </form>

                <footer class="login-footer">
                    <a class="portal-link" href="../index.php">
                        <svg class="bi" aria-hidden="true"><use href="../assets/icons/bootstrap-icons.svg#arrow-left"></use></svg>
                        <span>Back to portal selection</span>
                    </a>
                    <p class="copyright">StudyLink &copy; <?php echo date('Y'); ?> &bull; Academic Excellence</p>
                </footer>
            </div>
        </section>
    </div>
</main>

<script>
(function () {
    var password = document.getElementById('password');
    var toggle = document.getElementById('passwordToggle');
    var icon = document.getElementById('passwordIcon');
    var forgot = document.getElementById('forgotPassword');
    var help = document.getElementById('passwordHelp');
    var form = document.getElementById('loginForm');
    var signIn = document.getElementById('signInButton');

    toggle.addEventListener('click', function () {
        var willShow = password.type === 'password';
        password.type = willShow ? 'text' : 'password';
        toggle.setAttribute('aria-label', willShow ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', willShow ? 'true' : 'false');
        icon.setAttribute('href', '../assets/icons/bootstrap-icons.svg#' + (willShow ? 'eye-slash' : 'eye'));
        password.focus();
    });

    forgot.addEventListener('click', function () {
        var isVisible = help.classList.toggle('is-visible');
        forgot.setAttribute('aria-expanded', isVisible ? 'true' : 'false');
    });

    form.addEventListener('submit', function () {
        signIn.disabled = true;
        signIn.querySelector('span').textContent = 'Signing In...';
    });
}());
</script>
</body>
</html>
