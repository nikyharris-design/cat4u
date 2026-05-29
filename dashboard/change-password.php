<?php
/**
 * ==========================================================================
 * CHANGE-PASSWORD.PHP — Impostazione nuova password
 * ==========================================================================
 *
 * Pagina dove l'utente imposta una nuova password. È il passaggio obbligato al
 * primo accesso: chi ha must_change_password = 1 viene dirottato qui da
 * require_password_changed() finché non completa l'operazione.
 *
 * NB: qui si chiama require_login() ma NON require_password_changed().
 * Sarebbe un controsenso (e un loop infinito): è proprio la pagina che deve
 * permettere di sbloccare quel flag.
 *
 * Validazioni applicate:
 *   - CSRF sul POST
 *   - lunghezza minima 8 caratteri
 *   - conferma coincidente
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Serve essere autenticati, ma NON imponiamo require_password_changed():
// vedi nota in testa al file.
require_login();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Regola minima di robustezza: almeno 8 caratteri. (Lato browser è già
    // imposto da minlength, ma qui lo riverifichiamo: la validazione client
    // è solo comodità, quella server è la vera difesa.)
    if (strlen($new_password) < 8) {
        $error = "La password deve essere di almeno 8 caratteri.";
    } elseif ($new_password !== $confirm_password) {
        // Le due password digitate devono coincidere.
        $error = "Le password non coincidono.";
    } else {
        // Calcoliamo l'hash bcrypt: è ciò che verrà salvato, mai la password
        // in chiaro. bcrypt include automaticamente un "salt" casuale.
        $hash = password_hash($new_password, PASSWORD_BCRYPT);

        // Aggiorniamo la riga dell'utente:
        //  - nuova password (hash)
        //  - must_change_password = 0 → il vincolo è sciolto
        //  - password_changed_at = NOW() → traccia quando è avvenuto il cambio
        $stmt = $pdo->prepare("
            UPDATE users
            SET password = ?, must_change_password = 0, password_changed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$hash, $_SESSION['user_id']]);

        // IMPORTANTE: allineiamo anche la SESSIONE. Il DB ora dice 0, ma in
        // sessione il flag era true; senza questa riga require_password_changed()
        // continuerebbe a rimandare l'utente qui a ogni pagina.
        $_SESSION['must_change_password'] = false;

        $log->info('Password cambiata', ['user_id' => $_SESSION['user_id']]);

        // Tutto fatto: l'utente può finalmente entrare nella dashboard.
        header("Location: " . BASE_URL . "dashboard/index.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambia Password — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h1 class="text-3xl font-bold text-indigo-600 mb-1">Cat4U</h1>
        <h2 class="text-gray-700 font-semibold mb-1">Imposta nuova password</h2>
        <p class="text-gray-400 text-sm mb-6">Per continuare devi impostare una nuova password.</p>

        <!-- Messaggio di errore (es. password troppo corta o non coincidente). -->
        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- Token CSRF obbligatorio. -->
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <label class="block text-sm font-semibold text-gray-700 mb-1">Nuova password</label>
            <!-- minlength="8" = controllo lato browser (riverificato lato server). -->
            <input type="password" name="new_password" required minlength="8"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-4 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

            <label class="block text-sm font-semibold text-gray-700 mb-1">Conferma password</label>
            <input type="password" name="confirm_password" required minlength="8"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-6 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg transition">
                Salva password
            </button>
        </form>
    </div>
</body>
</html>