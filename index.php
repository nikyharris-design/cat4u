<?php
require_once __DIR__ . '/config/bootstrap.php';

if (!empty($_SESSION['autorizzato'])) {
    header("Location: " . BASE_URL . "dashboard/index.php");
} else {
    header("Location: " . BASE_URL . "dashboard/login.php");
}
exit();