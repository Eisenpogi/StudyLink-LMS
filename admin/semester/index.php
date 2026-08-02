<?php

include '../../auth/auth.php';
require_role('admin');

header('Location: ../academic_year/index.php#semester-records');
exit;
