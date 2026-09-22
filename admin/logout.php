<?php
// admin/logout.php - Mudra Archive Logout Handler
require_once __DIR__ . '/../includes/functions.php';

// Sign-out changes state, so it is POST-only and CSRF-protected: a plain link
// let any third-party page log the curator out mid-edit.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: dashboard.php');
    exit();
}

admin_session_destroy();

header('Location: login.php');
exit();
