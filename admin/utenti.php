<?php
/**
 * ==========================================================================
 * UTENTI.PHP — Controller "Gestione Utenti" (superadmin e admin)
 * ==========================================================================
 *
 * Permette di creare ed eliminare utenti, con permessi diversi per ruolo:
 *   SUPERADMIN: vede tutti gli utenti, crea qualsiasi ruolo e per qualsiasi azienda.
 *   ADMIN:      vede solo gli utenti della propria azienda, crea solo "user" di quella.
 *
 * Principio guida: non fidarsi MAI dei campi sensibili (ruolo, azienda) inviati
 * dal form. Per l'admin sono FORZATI lato server, ignorando il browser.
 *
 * Questo file è il CONTROLLER (logica). La presentazione è in
 * views/utenti.view.php, inclusa in fondo.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin', 'admin');
require_password_changed();

$user          = current_user();
$is_superadmin = $user['role'] === 'superadmin';

$error   = '';
$success = '';

// --------------------------------------------------------------------------
// AZIONE: ELIMINA un utente
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    if ($id === (int)$_SESSION['user_id']) {
        // Protezione anti-autodistruzione: non puoi cancellare il tuo account.
        $error = "Non puoi eliminare il tuo account.";
    } else {
        // L'admin può eliminare SOLO utenti della propria azienda.
        if (!$is_superadmin) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND azienda_id = ?");
            $stmt->execute([$id, $user['azienda_id']]);
            if (!$stmt->fetch()) {
                $error = "Operazione non consentita.";
            }
        }
        if (empty($error)) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $success = "Utente eliminato.";
        }
    }
}

// --------------------------------------------------------------------------
// AZIONE: CREA un utente
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crea') {
    csrf_verify();

    $name  = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role  = $_POST['role'] ?? 'user';

    // --- CHI PUÒ CREARE COSA (la parte più delicata) ---
    if ($is_superadmin) {
        // Il superadmin può creare superadmin (azienda nulla) o assegnare un'azienda.
        $azienda_id = $_POST['azienda_id'] === '' ? null : (int)$_POST['azienda_id'];
    } else {
        // ADMIN: ruolo e azienda FORZATI lato server, ignorando il form.
        $azienda_id = (int)$user['azienda_id'];
        $role = 'user';
    }

    // --- VALIDAZIONI ---
    if (empty($name) || empty($email)) {
        $error = "Nome ed email sono obbligatori.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Email non valida.";
    } elseif (!in_array($role, ['superadmin', 'admin', 'user'], true)) {
        // Whitelist dei ruoli ammessi: nessun valore "creativo".
        $error = "Ruolo non valido.";
    } elseif ($role !== 'superadmin' && $azienda_id === null) {
        // Solo il superadmin può non avere azienda.
        $error = "Seleziona un'azienda per questo ruolo.";
    } else {
        // Password temporanea casuale; salviamo solo l'hash.
        $temp_password = bin2hex(random_bytes(8));
        $hash = password_hash($temp_password, PASSWORD_BCRYPT);
        try {
            // must_change_password = 1 → al primo login dovrà cambiarla.
            $stmt = $pdo->prepare("
                INSERT INTO users (azienda_id, name, email, password, role, must_change_password)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$azienda_id, $name, $email, $hash, $role]);
            // La password temporanea si mostra UNA SOLA VOLTA: nel DB c'è solo l'hash.
            // È già escapata qui, perché la vista stampa $success senza htmlspecialchars.
            $success = "Utente creato. Password temporanea: <strong>" . htmlspecialchars($temp_password) . "</strong> — comunicala all'utente, non verrà mostrata di nuovo.";
        } catch (PDOException $e) {
            // Verosimilmente il vincolo UNIQUE sull'email.
            $error = "Email già presente nel sistema.";
        }
    }
}

// --------------------------------------------------------------------------
// ELENCO UTENTI (filtrato in base al ruolo di chi guarda)
// --------------------------------------------------------------------------
if ($is_superadmin) {
    // Superadmin: tutti gli utenti (LEFT JOIN perché può non avere azienda).
    $utenti = $pdo->query("
        SELECT u.*, a.nome_azienda
        FROM users u
        LEFT JOIN aziende a ON a.id = u.azienda_id
        ORDER BY u.role, u.name ASC
    ")->fetchAll();
} else {
    // Admin: solo gli utenti della propria azienda.
    $stmt = $pdo->prepare("
        SELECT u.*, a.nome_azienda
        FROM users u
        LEFT JOIN aziende a ON a.id = u.azienda_id
        WHERE u.azienda_id = ?
        ORDER BY u.role, u.name ASC
    ");
    $stmt->execute([(int)$user['azienda_id']]);
    $utenti = $stmt->fetchAll();
}

// L'elenco aziende serve solo al superadmin (menu nel form di creazione).
$aziende = $is_superadmin
    ? $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll()
    : [];

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/utenti.view.php';