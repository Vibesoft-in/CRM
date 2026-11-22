<?php
require_once __DIR__ . '/../../includes/functions.php';
\App\Security\Auth::logout();
header('Location: ' . BASE_URL . 'modules/auth/login.php'); exit;
