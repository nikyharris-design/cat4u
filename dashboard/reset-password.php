<?php
/**
 * ==========================================================================
 * RESET-PASSWORD.PHP — Imposta una nuova password tramite token
 * ==========================================================================
 *
 * Pagina pubblica raggiunta dal link inviato via email:
 *     dashboard/reset-password.php?token=XXXX
 *
 * Flusso:
 *   GET  → validiamo il token (esiste, non usato, non scaduto). Se ok, mostriamo
 *          il form per la nuova password; altrimenti un messaggio di errore.
 *   POST → rivalidiamo il token (mai fidarsi del solo campo nascosto), validiamo
 *          la password, aggiorniamo l'utente e marchiamo il token come usato.
 *
 * Sicurezza:
 *   - il token in chiaro non è mai nel DB: confrontiamo il suo hash
 *   - token monouso (used) e con scadenza (expires_at)
 *   - CSRF sul POST
 *   - la nuova password segue le stesse regole del cambio password (min 8, conferma)
 */

require_once __DIR__ . '/../config/bootstrap.php';

$error   = '';
$success = '';

/**
 * Cerca un token valido (non usato, non scaduto) e restituisce la riga di
 * password_resets, oppure null. Centralizza la verifica usata sia in GET sia in POST.
 */
function find_valid_reset(PDO $pdo, string $token): ?array {
    if ($token === '') return null;
    $token_hash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT * FROM password_resets
        WHERE token_hash = ? AND used = 0 AND expires_at > NOW()
        LIMIT 1
    ");
    $stmt->execute([$token_hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Il token arriva da GET (apertura del link) o da POST (invio del form).
$token = $_POST['token'] ?? $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // RIVALIDAZIONE: non basta che il token fosse valido all'apertura della
    // pagina; lo ricontrolliamo ora contro il DB (potrebbe essere scaduto o già
    // usato nel frattempo).
    $reset = find_valid_reset($pdo, $token);

    if (!$reset) {
        $error = "Link non valido o scaduto. Richiedi un nuovo reset.";
    } else {
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (strlen($new_password) < 8) {
            $error = "La password deve essere di almeno 8 caratteri.";
        } elseif ($new_password !== $confirm_password) {
            $error = "Le password non coincidono.";
        } else {
            // Verifichiamo che esista ancora un utente con quell'email (potrebbe
            // essere stato eliminato dopo la richiesta).
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$reset['email']]);
            $user = $stmt->fetch();

            if (!$user) {
                $error = "Account non più disponibile.";
            } else {
                // Impostiamo la nuova password. must_change_password = 0 perché
                // l'utente l'ha scelta lui (non è una password temporanea).
                $hash = password_hash($new_password, PASSWORD_BCRYPT);
                $pdo->prepare("
                    UPDATE users
                    SET password = ?, must_change_password = 0, password_changed_at = NOW()
                    WHERE id = ?
                ")->execute([$hash, $user['id']]);

                // Bruciamo il token: non potrà essere riutilizzato.
                $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")
                    ->execute([$reset['id']]);

                $log->info('Password reimpostata via reset', ['user_id' => $user['id']]);

                $success = "Password aggiornata con successo. Ora puoi accedere.";
            }
        }
    }
} else {
    // Apertura del link (GET): se il token non è valido, lo segnaliamo subito.
    if (!find_valid_reset($pdo, $token)) {
        $error = "Link non valido o scaduto. Richiedi un nuovo reset.";
    }
}

// Mostriamo il form solo se NON c'è già un esito (successo) e il token è valido.
// In presenza di $success nascondiamo il form; in presenza di $error mostriamo
// solo il messaggio se il token è irrecuperabile.
$mostra_form = ($success === '') && find_valid_reset($pdo, $token) !== null;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reimposta password — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h1 class="text-3xl font-bold text-indigo-600 mb-1">Cat4U</h1>
        <h2 class="text-gray-700 font-semibold mb-6">Reimposta password</h2>

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded mb-4 text-sm"><?= htmlspecialchars($success) ?></p>
            <a href="<?= BASE_URL ?>dashboard/login.php"
               class="block text-center w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg transition">
                Vai al login
            </a>
        <?php elseif ($mostra_form): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <!-- Il token viaggia nel form per essere rivalidato sul POST. -->
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <label class="block text-sm font-semibold text-gray-700 mb-1">Nuova password</label>
                <input type="password" name="new_password" required minlength="8"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-4 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

                <label class="block text-sm font-semibold text-gray-700 mb-1">Conferma password</label>
                <input type="password" name="confirm_password" required minlength="8"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-6 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

                <button type="submit"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg transition">
                    Salva nuova password
                </button>
            </form>
        <?php else: ?>
            <!-- Token non valido e nessun successo: offriamo di ripartire. -->
            <a href="<?= BASE_URL ?>dashboard/forgot-password.php" class="text-indigo-600 hover:underline text-sm">
                Richiedi un nuovo link di reset
            </a>
        <?php endif; ?>
    </div>
</body>
</html>