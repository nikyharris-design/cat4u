<?php
/**
 * ==========================================================================
 * FORGOT-PASSWORD.PHP — Richiesta di reset password  [CON RATE-LIMITING]
 * ==========================================================================
 *
 * Pagina pubblica. L'utente inserisce l'email; se l'account esiste, generiamo
 * un token monouso (hash nel DB, token in chiaro solo nel link) e inviamo
 * l'email di reset.
 *
 * Due protezioni:
 *   - anti-enumerazione: messaggio sempre uguale, esista o no l'email;
 *   - rate-limiting: max 3 richieste ogni 15 minuti per IP, per evitare che
 *     qualcuno spammi di email una casella o sondi il sistema.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/mailer.php';

$success = '';
$error   = '';

// Parametri del rate-limit per la richiesta di reset.
const FORGOT_MAX_RICHIESTE    = 3;
const FORGOT_FINESTRA_SECONDI = 900; // 15 minuti

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $ip    = client_ip();
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Inserisci un indirizzo email valido.";
    } elseif (rate_too_many('forgot', $ip, FORGOT_MAX_RICHIESTE, FORGOT_FINESTRA_SECONDI)) {
        // Blocco per troppe richieste. Questo messaggio NON rivela se l'email
        // esista: parla solo della frequenza delle richieste da questo IP.
        $error = "Hai effettuato troppe richieste. Riprova tra qualche minuto.";
    } else {
        // Ogni richiesta valida conta ai fini del limite.
        rate_record('forgot', $ip);

        // Cerchiamo l'utente. NON riveliamo l'esito.
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Invalidiamo eventuali token precedenti ancora attivi per l'email.
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0")
                ->execute([$user['email']]);

            $token      = bin2hex(random_bytes(32));
            $token_hash = hash('sha256', $token);
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // +1 ora

            $pdo->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)")
                ->execute([$user['email'], $token_hash, $expires_at]);

            $reset_url = BASE_URL . 'dashboard/reset-password.php?token=' . $token;

            send_password_reset_email($user['email'], $reset_url);
            $log->info('Richiesta reset password', ['user_id' => $user['id']]);
        }

        // Messaggio unico, indipendente dall'esito della ricerca.
        $success = "Se l'indirizzo e' registrato, riceverai un'email con le istruzioni per reimpostare la password.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password dimenticata — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h1 class="text-3xl font-bold text-indigo-600 mb-1">Cat4U</h1>
        <h2 class="text-gray-700 font-semibold mb-1">Password dimenticata</h2>
        <p class="text-gray-400 text-sm mb-6">Inserisci la tua email: ti invieremo un link per reimpostarla.</p>

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if ($success): ?>
            <p class="bg-green-100 text-green-700 px-4 py-3 rounded mb-4 text-sm"><?= htmlspecialchars($success) ?></p>
            <a href="<?= BASE_URL ?>dashboard/login.php" class="text-indigo-600 hover:underline text-sm">&larr; Torna al login</a>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                <input type="email" name="email" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-6 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

                <button type="submit"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg transition">
                    Invia link di reset
                </button>

                <p class="text-center mt-4">
                    <a href="<?= BASE_URL ?>dashboard/login.php" class="text-gray-500 hover:underline text-sm">&larr; Torna al login</a>
                </p>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>