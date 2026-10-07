<?php
require_once __DIR__ . '/includes/init.php';

// Logging out changes state, so it is POST + CSRF only
require_post();
logout_user();
start_secure_session();
flash('success', 'You have been signed out.');
redirect('login.php');
