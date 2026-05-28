<?php
require_once __DIR__ . '/../config/bootstrap.php';

$log->info('Logout effettuato', ['user_id' => $_SESSION['user_id'] ?? null]);

session_unset();
session_destroy();

header("Location: " . BASE_URL . "dashboard/login");
exit();
