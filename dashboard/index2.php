<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_login();
require_password_changed();

$user = current_user();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>
    <header>
        <h1>Cat4U</h1>
        <nav>
            <span>Ciao, <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)</span>
            <?php if ($user['role'] === 'superadmin'): ?>
                <a href="<?= BASE_URL ?>admin">Gestione Aziende</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>dashboard/logout.php">Esci</a>
        </nav>
    </header>

    <main>
        <h2>Dashboard</h2>
        <!-- Qui andrà: lista cataloghi, pulsante nuovo catalogo, analytics -->
        <p>Benvenuto nel pannello di gestione Cat4U.</p>
    </main>
</body>
</html>
