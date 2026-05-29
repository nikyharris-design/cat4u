<?php
/**
 * ==========================================================================
 * INDEX.PHP (dashboard) — Pannello iniziale dopo il login
 * ==========================================================================
 *
 * Pagina di atterraggio dell'area riservata. Mostra:
 *   - tre contatori di riepilogo (cataloghi totali, attivi, scansioni)
 *   - una sezione "accesso rapido" con link che cambiano in base al ruolo.
 *
 * Logica dei dati a due livelli:
 *   - Utente legato a un'azienda → vede SOLO i numeri della propria azienda.
 *   - Superadmin (azienda_id nullo) → vede i totali GLOBALI di tutte le aziende.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Basta essere loggati (qualsiasi ruolo) e avere già cambiato la password.
require_login();
require_password_changed();

$user = current_user();

// azienda_id è null per il superadmin; il cast a int lo trasforma in 0,
// così sotto possiamo usare un semplice if ($azienda_id) per distinguere i casi.
$azienda_id = (int)$user['azienda_id'];

if ($azienda_id) {
    // ---- RAMO "UTENTE DI UN'AZIENDA": tutti i conteggi sono filtrati per azienda_id ----

    // Conteggio cataloghi dell'azienda. fetchColumn() restituisce il primo
    // valore della prima riga: perfetto per un COUNT(*).
    $tot_cataloghi = $pdo->prepare("SELECT COUNT(*) FROM cataloghi WHERE azienda_id = ?");
    $tot_cataloghi->execute([$azienda_id]);
    $tot_cataloghi = (int)$tot_cataloghi->fetchColumn();

    // Solo i cataloghi attualmente attivi.
    $tot_attivi = $pdo->prepare("SELECT COUNT(*) FROM cataloghi WHERE azienda_id = ? AND is_active = 1");
    $tot_attivi->execute([$azienda_id]);
    $tot_attivi = (int)$tot_attivi->fetchColumn();

    // Scansioni QR: la tabella analytics non ha azienda_id, quindi facciamo una
    // JOIN sui cataloghi per risalire all'azienda e contare solo le sue.
    $tot_scansioni = $pdo->prepare("
        SELECT COUNT(*) FROM catalogo_analytics ca
        JOIN cataloghi c ON c.id = ca.catalogo_id
        WHERE c.azienda_id = ?
    ");
    $tot_scansioni->execute([$azienda_id]);
    $tot_scansioni = (int)$tot_scansioni->fetchColumn();
} else {
    // ---- RAMO "SUPERADMIN": totali globali, nessun filtro per azienda ----
    // Qui usiamo query() (non prepare): non ci sono parametri da passare,
    // quindi non serve un prepared statement.
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
    <!-- Header comune (navigazione). Vede $user perché è già definito sopra. -->
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Dashboard</h2>

        <!-- TRE CARD DI RIEPILOGO. Su mobile vanno in colonna (grid-cols-1),
             da schermo "sm" in su su tre colonne (sm:grid-cols-3). -->
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
                <!-- Link disponibili a tutti i ruoli autenticati. -->
                <a href="<?= BASE_URL ?>dashboard/cataloghi.php"
                   class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    + Carica catalogo
                </a>
                <a href="<?= BASE_URL ?>dashboard/generi.php"
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Gestione Generi
                </a>

                <!-- Analytics: solo superadmin e admin (non gli utenti "user"). -->
                <?php if (in_array($user['role'], ['superadmin', 'admin'])): ?>
            <a href="<?= BASE_URL ?>dashboard/analytics.php"
                     class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                        Analytics
                    </a>
                    <?php endif; ?>

                <!-- Gestione aziende e utenti: riservate al solo superadmin. -->
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