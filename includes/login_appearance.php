<?php

if (!defined('STUDYLINK_LOGIN_APPEARANCE_LOADED')) {
    define('STUDYLINK_LOGIN_APPEARANCE_LOADED', true);

    function studylink_login_visual_directory()
    {
        return dirname(__DIR__) . '/assets/uploads/login';
    }

    function studylink_login_visual_files()
    {
        $directory = studylink_login_visual_directory();
        $files = [];

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $path = $directory . '/login-visual.' . $extension;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    function studylink_login_visual_filename()
    {
        $files = studylink_login_visual_files();
        return $files ? basename($files[0]) : '';
    }

    function studylink_login_visual_url($base_path = '/studyLink/assets/uploads/login/')
    {
        $filename = studylink_login_visual_filename();
        return $filename === '' ? '' : rtrim($base_path, '/') . '/' . rawurlencode($filename);
    }
}
