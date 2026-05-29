<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_login();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (strlen($new_password) < 8) {
        $error = "La password deve essere di almeno 8 caratteri.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Le password non coincidono.";
    } else {
        $hash = password_hash($new_password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            UPDATE users
            SET password = ?, must_change_password = 0, password_changed_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$hash, $_SESSION['user_id']]);

        $_SESSION['must_change_password'] = false;
        $log->info('Password cambiata', ['user_id' => $_SESSION['user_id']]);

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

        <?php if ($error): ?>
            <p class="bg-red-100 text-red-700 px-4 py-2 rounded mb-4 text-sm">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <label class="block text-sm font-semibold text-gray-700 mb-1">Nuova password</label>
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
