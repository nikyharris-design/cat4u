<?php
/**
 * AUTH.PHP - Funzioni di controllo accesso
 */

function require_login(): void {
    if (empty($_SESSION['autorizzato'])) {
        header("Location: " . BASE_URL . "dashboard/login.php");
        exit();
    }
}

function require_role(string ...$roles): void {
    require_login();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        die("Accesso negato.");
    }
}

function require_password_changed(): void {
    if (!empty($_SESSION['must_change_password'])) {
        header("Location: " . BASE_URL . "dashboard/change-password.php");
        exit();
    }
}

function current_user(): array {
    return [
        'id'         => $_SESSION['user_id']    ?? null,
        'name'       => $_SESSION['user_name']  ?? '',
        'email'      => $_SESSION['user_email'] ?? '',
        'role'       => $_SESSION['user_role']  ?? '',
        'azienda_id' => $_SESSION['azienda_id'] ?? null,
    ];
}