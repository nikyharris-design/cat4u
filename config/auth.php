<?php
/**
 * AUTH.PHP - Funzioni di controllo accesso
 * Da includere nelle pagine protette tramite bootstrap.php
 */

/**
 * Verifica che l'utente sia loggato.
 * Se non lo è, redirige al login.
 */
function require_login(): void {
    if (empty($_SESSION['autorizzato'])) {
        header("Location: " . BASE_URL . "dashboard/login");
        exit();
    }
}

/**
 * Verifica che l'utente abbia uno dei ruoli richiesti.
 * Uso: require_role('superadmin') oppure require_role('superadmin', 'admin')
 */
function require_role(string ...$roles): void {
    require_login();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        die("Accesso negato.");
    }
}

/**
 * Verifica se l'utente deve cambiare la password al primo accesso.
 * Se sì, lo forza alla pagina di cambio password.
 */
function require_password_changed(): void {
    if (!empty($_SESSION['must_change_password'])) {
        header("Location: " . BASE_URL . "dashboard/password");
        exit();
    }
}

/**
 * Restituisce i dati dell'utente loggato dalla sessione.
 */
function current_user(): array {
    return [
        'id'       => $_SESSION['user_id']    ?? null,
        'name'     => $_SESSION['user_name']  ?? '',
        'email'    => $_SESSION['user_email'] ?? '',
        'role'     => $_SESSION['user_role']  ?? '',
        'azienda_id' => $_SESSION['azienda_id'] ?? null,
    ];
}
