<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

redirect_with_flash(
    'index.php',
    'error',
    'Permanent faculty deletion is disabled to protect classes and academic history. Deactivate the account instead.'
);
