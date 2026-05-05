<?php
require_once __DIR__ . '/security_bootstrap.php';
book_system_secure_session_start();
session_destroy();
header("Location: login.php");
exit;

