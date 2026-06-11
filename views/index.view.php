<?php
/**
 * ==========================================================================
 * INDEX.VIEW.PHP — Vista della dashboard (pannello iniziale)
 * ==========================================================================
 *
 * Solo presentazione. Inclusa da dashboard/index.php dopo che il controller ha
 * preparato i dati. Variabili già disponibili qui: $user, $tot_cataloghi,
 * $tot_attivi, $tot_scansioni. Non va mai aperta direttamente (views/.htaccess).
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Dashboard — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Header comune (navigazione). Vede $user perché è già definito dal controller. -->
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Dashboard</h2>

        <!-- TRE CARD DI RIEPILOGO. Su mobile in colonna, da "sm" in su su tre colonne. -->
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

        <!-- ACCESSO RAPIDO. Alcuni link compaiono solo per certi ruoli. -->
        <div class="bg-white rounded-xl shadow p-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">Accesso rapido</h3>
            <div class="flex flex-wrap gap-3">
                <!-- Cataloghi e Generi: tutti i ruoli tranne il superadmin. -->
                <?php if ($user['role'] !== 'superadmin'): ?>
                <a href="<?= BASE_URL ?>dashboard/cataloghi.php"
                   class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    + Carica catalogo
                </a>
                <a href="<?= BASE_URL ?>dashboard/generi.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Gestione Generi
                </a>
                <?php endif; ?>

                <!-- Analytics: solo superadmin e admin. -->
                <?php if (in_array($user['role'], ['superadmin', 'admin'])): ?>
                <a href="<?= BASE_URL ?>dashboard/analytics.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Analytics
                </a>
                <?php endif; ?>

                <!-- Aziende e Utenti: solo superadmin. -->
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