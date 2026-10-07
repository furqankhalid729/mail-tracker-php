<?php
require_once __DIR__ . '/includes/init.php';

redirect(current_user() ? 'dashboard.php' : 'login.php');
