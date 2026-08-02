<?php

include 'auth.php';
studylink_logout_session();
header('Location: /studyLink/index.php?success=logged_out');
exit();
