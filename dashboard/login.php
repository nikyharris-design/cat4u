<?php
/**
 * ==========================================================================
 * LOGIN.PHP — Autenticazione utente
 * ==========================================================================
 *
 * Mostra il form di accesso e, su invio (POST), verifica le credenziali.
 * Se corrette, popola la sessione con i dati dell'utente e lo manda alla
 * dashboard (o al cambio password forzato, se è il primo accesso).
 *
 * Sicurezza applicata qui:
 *   - verifica CSRF sul POST
 *   - password confrontate con password_verify() (mai in chiaro)
 *   - session_regenerate_id() contro la session fixation
 *   - messaggi di errore volutamente generici (no user enumeration)
 *   - logging di login riusciti e tentativi falliti
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Se l'utente è GIÀ autenticato, non ha senso mostrargli il login:
// lo rimandiamo direttamente alla dashboard.
if (!empty($_SESSION['autorizzato'])) {
    header("Location: " . BASE_URL . "dashboard");
    exit();
}

$error = '';

// Il blocco seguente gira solo quando il form viene inviato (metodo POST).
// Al primo caricamento della pagina (GET) si salta direttamente all'HTML.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Primo controllo sempre: il token CSRF. Se non valido, lo script muore qui.
    csrf_verify();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Compila tutti i campi.";
    } else {
        // Cerchiamo l'utente per email usando un prepared statement:
        // il "?" è un segnaposto, il valore arriva separato → niente SQL injection.
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // password_verify confronta la password digitata con l'hash salvato nel DB.
        // Nel database NON c'è mai la password in chiaro, solo il suo hash bcrypt.
        // La condizione è "utente esiste E password corretta".
        if ($user && password_verify($password, $user['password'])) {

            // SESSION FIXATION: un attaccante potrebbe far usare alla vittima un
            // ID di sessione che conosce già. Rigenerando l'ID al login, il
            // vecchio identificativo diventa inutile. true = cancella la sessione
            // vecchia.
            session_regenerate_id(true);

            // Popoliamo la sessione con i dati che serviranno nelle altre pagine.
            // 'autorizzato' è il flag letto da require_login().
            $_SESSION['autorizzato']          = true;
            $_SESSION['user_id']              = $user['id'];
            $_SESSION['user_name']            = $user['name'];
            $_SESSION['user_email']           = $user['email'];
            $_SESSION['user_role']            = $user['role'];
            $_SESSION['azienda_id']           = $user['azienda_id'];
            $_SESSION['must_change_password'] = (bool)$user['must_change_password'];
            $_SESSION['last_activity']        = time(); // avvia il cronometro del timeout

            $log->info('Login effettuato', ['user_id' => $user['id'], 'email' => $email]);

            // Primo accesso con password temporanea: lo costringiamo a cambiarla
            // prima di poter usare il resto dell'app.
            if ($user['must_change_password']) {
                header("Location: " . BASE_URL . "dashboard/change-password.php");
            } else {
                header("Location: " . BASE_URL . "dashboard");
            }
            exit();
        } else {
            // Login fallito. Registriamo il tentativo (utile per individuare
            // attacchi brute force).
            $log->warning('Tentativo di login fallito', ['email' => $email]);

            // Messaggio GENERICO e identico sia che l'email non esista, sia che
            // la password sia sbagliata. Evita la "user enumeration": un
            // attaccante non deve poter capire quali email sono registrate.
            $error = "Credenziali non valide.";
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

        <!-- Errore di validazione/credenziali (se presente). htmlspecialchars
             previene l'XSS in caso il messaggio contenga input dell'utente. -->
        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <!-- Messaggi che arrivano via query string da altre pagine:
             ?error=timeout (sessione scaduta) o ?error=sessione_non_sicura
             (fingerprint cambiato). Mappiamo il codice a un testo leggibile. -->
        <?php
        $msg_map = [
            'timeout'             => 'Sessione scaduta per inattività.',
            'sessione_non_sicura' => 'Sessione non valida. Accedi di nuovo.',
        ];
        $error_key = $_GET['error'] ?? '';
        if (isset($msg_map[$error_key])): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                <?= htmlspecialchars($msg_map[$error_key]) ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- Token CSRF: campo nascosto verificato da csrf_verify() sul POST. -->
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
            <!-- value ripopolato dopo un errore così l'utente non riscrive l'email.
                 autofocus mette il cursore qui all'apertura della pagina. -->
            <input type="email" name="email" required autofocus
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-4 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

            <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
            <!-- La password NON viene mai ripopolata dopo un errore: per scelta
                 di sicurezza non la rimandiamo mai al browser. -->
            <input type="password" name="password" required
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 mb-6 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">

            <button type="submit"
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 rounded-lg transition">
                Accedi
            </button>
        </form>
    </div>
</body>
</html>