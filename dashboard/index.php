<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_login();
require_password_changed();

$user = current_user();
$azienda_id = (int)$user['azienda_id'];

if ($azienda_id) {
    $tot_cataloghi = $pdo->prepare("SELECT COUNT(*) FROM cataloghi WHERE azienda_id = ?");
    $tot_cataloghi->execute([$azienda_id]);
    $tot_cataloghi = (int)$tot_cataloghi->fetchColumn();

    $tot_attivi = $pdo->prepare("SELECT COUNT(*) FROM cataloghi WHERE azienda_id = ? AND is_active = 1");
    $tot_attivi->execute([$azienda_id]);
    $tot_attivi = (int)$tot_attivi->fetchColumn();

    $tot_scansioni = $pdo->prepare("
        SELECT COUNT(*) FROM catalogo_analytics ca
        JOIN cataloghi c ON c.id = ca.catalogo_id
        WHERE c.azienda_id = ?
    ");
    $tot_scansioni->execute([$azienda_id]);
    $tot_scansioni = (int)$tot_scansioni->fetchColumn();
} else {
    $tot_cataloghi = (int)$pdo->query("SELECT COUNT(*) FROM cataloghi")->fetchColumn();
    $tot_attivi    = (int)$pdo->query("SELECT COUNT(*) FROM cataloghi WHERE is_active = 1")->fetchColumn();
    $tot_scansioni = (int)$pdo->query("SELECT COUNT(*) FROM catalogo_analytics")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Dashboard</h2>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-6 text-center">
                <div class="text-4xl font-bold text-indigo-600"><?= $tot_cataloghi ?></div>
                <div class="text-gray-500 mt-1 text-sm">Cataloghi totali</div>
            </div>
            <div class="bg-white rounded-xl shadow p-6 text-center">
                <div class="text-4xl font-bold text-green-600"><?= $tot_attivi ?></div>
                <div class="text-gray-500 mt-1 text-sm">Cataloghi attivi</div>
            </div>
            <div class="bg-white rounded-xl shadow p-6 text-center">
                <div class="text-4xl font-bold text-blue-600"><?= $tot_scansioni ?></div>
                <div class="text-gray-500 mt-1 text-sm">Scansioni QR totali</div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">Accesso rapido</h3>
            <div class="flex flex-wrap gap-3">
                <a href="<?= BASE_URL ?>dashboard/cataloghi.php"
                   class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    + Carica catalogo
                </a>
                <a href="<?= BASE_URL ?>dashboard/generi.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Gestione Generi
                </a>
                <?php if ($user['role'] === 'superadmin'): ?>
                <a href="<?= BASE_URL ?>admin/aziende.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Gestione Aziende
                </a>
                <a href="<?= BASE_URL ?>admin/utenti.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Gestione Utenti
                </a>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>