<?php

date_default_timezone_set('Asia/Manila');

$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'studylink_db';

mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    error_log('StudyLink database connection failed: ' . mysqli_connect_error());
    http_response_code(500);
    exit('Unable to connect to StudyLink. Please try again later.');
}

if (!mysqli_set_charset($conn, 'utf8mb4')) {
    error_log('StudyLink charset setup failed: ' . mysqli_error($conn));
    http_response_code(500);
    exit('Unable to prepare StudyLink. Please try again later.');
}

if (!mysqli_query($conn, "SET time_zone = '+08:00'")) {
    error_log('StudyLink timezone setup failed: ' . mysqli_error($conn));
    http_response_code(500);
    exit('Unable to prepare StudyLink. Please try again later.');
}
