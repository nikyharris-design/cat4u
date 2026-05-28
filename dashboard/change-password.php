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

        header("Location: " . BASE_URL . "dashboard");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Cambia Password — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <div class="login-wrapper">
        <h2>Imposta nuova password</h2>
        <p>Per continuare devi impostare una nuova password.</p>

        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <label for="new_password">Nuova password</label>
            <input type="password" id="new_password" name="new_password" required minlength="8">

            <label for="confirm_password">Conferma password</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8">

            <button type="submit">Salva password</button>
        </form>
    </div>
</body>
</html>
