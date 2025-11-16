<?php
require_once __DIR__ . '/inc/auth.php';
logout_user();
header('Location: login.php');
exit;
