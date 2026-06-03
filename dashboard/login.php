<?php
/**
 * ==========================================================================
 * LOGIN.PHP — Autenticazione utente  [CON RATE-LIMITING]
 * ==========================================================================
 *
 * Rispetto alla versione base, aggiunge una protezione contro il brute-force:
 *   - dopo troppi tentativi falliti dallo stesso IP, il login viene bloccato
 *     temporaneamente (per la durata della finestra);
 *   - ogni credenziale errata registra un tentativo;
 *   - un login riuscito azzera il contatore (l'utente legittimo non e' penalizzato).
 *
 * Soglia scelta: max 5 tentativi falliti ogni 15 minuti (900s) per IP.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Se gia' loggato -> dashboard.
if (!empty($_SESSION['autorizzato'])) {
    header("Location: " . BASE_URL . "dashboard");
    exit();
}

$error = '';

// Parametri del rate-limit per il login.
const LOGIN_MAX_TENTATIVI    = 5;
const LOGIN_FINESTRA_SECONDI = 900; // 15 minuti

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Chiave del rate-limit: l'IP del client.
    $ip = client_ip();

    if (rate_too_many('login', $ip, LOGIN_MAX_TENTATIVI, LOGIN_FINESTRA_SECONDI)) {
        // Troppi tentativi: blocchiamo senza nemmeno controllare le credenziali.
        $error = "Troppi tentativi di accesso. Attendi qualche minuto e riprova.";
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            // Campi mancanti: non lo consideriamo un "tentativo" da conteggiare.
            $error = "Compila tutti i campi.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // SUCCESSO: azzeriamo i tentativi di questo IP.
                rate_clear('login', $ip);

                session_regenerate_id(true);

                $_SESSION['autorizzato']          = true;
                $_SESSION['user_id']              = $user['id'];
                $_SESSION['user_name']            = $user['name'];
                $_SESSION['user_email']           = $user['email'];
                $_SESSION['user_role']            = $user['role'];
                $_SESSION['azienda_id']           = $user['azienda_id'];
                $_SESSION['must_change_password'] = (bool)$user['must_change_password'];
                $_SESSION['last_activity']        = time();

                $log->info('Login effettuato', ['user_id' => $user['id'], 'email' => $email]);

                if ($user['must_change_password']) {
                    header("Location: " . BASE_URL . "dashboard/change-password.php");
                } else {
                    header("Location: " . BASE_URL . "dashboard");
                }
                exit();
            } else {
                // FALLIMENTO: registriamo il tentativo (alimenta il contatore).
                rate_record('login', $ip);

                $log->warning('Tentativo di login fallito', ['email' => $email, 'ip' => $ip]);
                // Messaggio generico (anti-enumerazione).
                $error = "Credenziali non valide.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
        <h1 class="text-3xl font-bold text-indigo-600 mb-1">Cat4U</h1>
        <h2 class="text-gray-500 font-normal mb-6">Accedi</h2>

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <?php
        $msg_map = [
            'timeout'             => 'Sessione scaduta per inattivita\'.',
            'sessione_non_sicura' => 'Sessione non valida. Accedi di nuovo.',
        ];
        $error_key = $_GET['error'] ?? '';
        if (isset($msg_map[$error_key])): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                <?= htmlspecialchars($msg_map[$error_key]) ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
            <input type="email" name="email" required autofocus
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-4 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

            <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
            <input type="password" name="password" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-6 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg transition">
                Accedi
            </button>
        </form>

        <!-- Link al recupero password. -->
        <p class="text-center mt-4">
            <a href="<?= BASE_URL ?>dashboard/forgot-password.php"
               class="text-gray-500 hover:underline text-sm">Password dimenticata?</a>
        </p>
    </div>
</body>
</html>