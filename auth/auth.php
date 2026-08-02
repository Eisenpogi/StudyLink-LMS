<?php

if (!defined('STUDYLINK_AUTH_LOADED')) {
    define('STUDYLINK_AUTH_LOADED', true);

    function studylink_request_portal()
    {
        $allowed = ['admin', 'faculty', 'student'];
        $explicit = strtolower((string) ($_GET['portal'] ?? $_POST['portal'] ?? ''));
        if (in_array($explicit, $allowed, true)) return $explicit;

        $paths = [(string) ($_SERVER['REQUEST_URI'] ?? '')];
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '') $paths[] = (string) (parse_url($referer, PHP_URL_PATH) ?? '');

        foreach ($paths as $path) {
            if (preg_match('~/(admin|faculty|student)(?:/|$)~i', $path, $match)) {
                return strtolower($match[1]);
            }
        }

        return '';
    }

    function studylink_session_name($portal)
    {
        $names = [
            'admin' => 'STUDYLINK_ADMIN_SESSID',
            'faculty' => 'STUDYLINK_FACULTY_SESSID',
            'student' => 'STUDYLINK_STUDENT_SESSID'
        ];
        return $names[$portal] ?? 'STUDYLINK_SESSID';
    }

    function studylink_login_url($portal, $error = '')
    {
        $allowed = ['admin', 'faculty', 'student'];
        $base = in_array($portal, $allowed, true)
            ? '/studyLink/' . $portal . '/index.php'
            : '/studyLink/index.php';

        return $error === '' ? $base : $base . '?error=' . rawurlencode($error);
    }

    if (session_status() === PHP_SESSION_NONE) {
        $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $session_lifetime = 43200;
        $request_portal = studylink_request_portal();

        ini_set('session.gc_maxlifetime', (string) $session_lifetime);
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        session_name(studylink_session_name($request_portal));

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $is_https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();
    }

    function studylink_role_timeout($role)
    {
        $timeouts = ['admin' => 3600, 'faculty' => 7200, 'student' => 10800];
        return $timeouts[$role] ?? 3600;
    }

    function studylink_logout_session()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    function require_login()
    {
        $request_portal = studylink_request_portal();

        if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || empty($_SESSION['fullname'])) {
            header('Location: ' . studylink_login_url($request_portal, 'login_required'));
            exit();
        }

        if ($request_portal !== '' && $_SESSION['role'] !== $request_portal) {
            access_denied();
        }

        $timeout = studylink_role_timeout($_SESSION['role']);
        if (isset($_SESSION['last_activity']) && (time() - intval($_SESSION['last_activity'])) > $timeout) {
            studylink_logout_session();
            header('Location: ' . studylink_login_url($request_portal, 'session_expired'));
            exit();
        }
        $_SESSION['last_activity'] = time();
    }

    function access_denied()
    {
        http_response_code(403);
        header('Location: /studyLink/errors/403.php');
        exit();
    }

    function require_role($allowed_roles)
    {
        require_login();
        if (!in_array($_SESSION['role'], (array) $allowed_roles, true)) access_denied();
    }

    function redirect_to($location) { header('Location: ' . $location); exit(); }

    function require_post($redirect = null)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') return;
        if ($redirect !== null) redirect_to($redirect);
        http_response_code(405);
        header('Allow: POST');
        exit('Method Not Allowed');
    }

    function csrf_token()
    {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf_token'];
    }

    function csrf_field()
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    function require_csrf($redirect = null)
    {
        $submitted_token = $_POST['csrf_token'] ?? '';
        $session_token = $_SESSION['csrf_token'] ?? '';
        if ($submitted_token !== '' && $session_token !== '' && hash_equals($session_token, $submitted_token)) return;
        if ($redirect !== null) {
            set_flash('error', 'Invalid or expired request. Please try again.');
            redirect_to($redirect);
        }
        access_denied();
    }

    function set_flash($type, $message) { $_SESSION['flash_message'] = ['type' => $type, 'message' => $message]; }
    function get_flash() { $flash = $_SESSION['flash_message'] ?? null; unset($_SESSION['flash_message']); return $flash; }
    function redirect_with_flash($location, $type, $message) { set_flash($type, $message); redirect_to($location); }
    function e($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}

require_login();
