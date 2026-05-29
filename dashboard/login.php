<?php
require_once __DIR__ . '/../config/bootstrap.php';

// Se già loggato, vai alla dashboard
if (!empty($_SESSION['autorizzato'])) {
    header("Location: " . BASE_URL . "dashboard");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = "Compila tutti i campi.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Rigenera l'ID di sessione per prevenire session fixation
            session_regenerate_id(true);

            $_SESSION['autorizzato']         = true;
            $_SESSION['user_id']             = $user['id'];
            $_SESSION['user_name']           = $user['name'];
            $_SESSION['user_email']          = $user['email'];
            $_SESSION['user_role']           = $user['role'];
            $_SESSION['azienda_id']          = $user['azienda_id'];
            $_SESSION['must_change_password'] = (bool)$user['must_change_password'];
            $_SESSION['last_activity']       = time();

            $log->info('Login effettuato', ['user_id' => $user['id'], 'email' => $email]);

            // Forza cambio password se richiesto
            if ($user['must_change_password']) {
                header("Location: " . BASE_URL . "dashboard/change-password.php");
            } else {
                header("Location: " . BASE_URL . "dashboard");
            }
            exit();
        } else {
            $log->warning('Tentativo di login fallito', ['email' => $email]);
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

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

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
    </div>
</body>
</html>
