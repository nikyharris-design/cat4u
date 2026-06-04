<?php
/**
 * ==========================================================================
 * HEADER.PHP — Barra di navigazione dell'area riservata (partial condiviso)
 * ==========================================================================
 *
 * "Partial": frammento di pagina riutilizzabile, incluso in cima a tutte le
 * pagine della dashboard/admin con:
 *     require __DIR__ . '/../partials/header.php';
 *
 * Mostra il logo, il menu (le cui voci variano col ruolo), il nome dell'utente
 * e il link di logout.
 *
 * Le voci "Analytics", "Aziende" e "Utenti" compaiono solo per i ruoli che ne
 * hanno diritto: è una comodità di interfaccia. La vera protezione resta nelle
 * singole pagine (require_role): nascondere un link NON è una misura di
 * sicurezza, ma solo di pulizia visiva.
 */

// Rete di sicurezza: di norma chi include questo file ha già definito $user.
// Se così non fosse, lo ricaviamo qui per evitare errori "variabile non
// definita" e poter usare $user['name'], $user['role'], ecc. più sotto.
if (!isset($user)) {
    $user = current_user();
}
?>
<!-- sticky top-0 + z-50: l'header resta agganciato in alto durante lo scroll,
     sopra agli altri elementi. -->
<header class="bg-indigo-600 text-white shadow sticky top-0 z-50">
    <div class="max-w-5xl mx-auto px-4 h-14 flex items-center gap-6">
        <!-- Logo: riporta alla dashboard. -->
        <a href="<?= BASE_URL ?>dashboard/index.php"
           class="font-bold text-lg text-white hover:text-indigo-200 transition">Cat4U</a>

        <nav class="flex items-center gap-1 flex-1">
            <!-- "Cataloghi": visibile a tutti i ruoli autenticati. -->
            <?php if ($user['role'] !== 'superadmin'): ?>
<a href="<?= BASE_URL ?>dashboard/cataloghi.php"
   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
    Cataloghi
</a>
<?php endif; ?>

            <!-- "Analytics": solo superadmin e admin. -->
            <?php if (in_array($user['role'], ['superadmin', 'admin'])): ?>
<a href="<?= BASE_URL ?>dashboard/analytics.php"
   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
    Analytics
</a>
<?php endif; ?>

            <!-- "Generi": visibile a tutti i ruoli autenticati. -->
              <?php if ($user['role'] !== 'superadmin'): ?>
            <a href="<?= BASE_URL ?>dashboard/generi.php"
               class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                Generi
            </a>
<?php endif; ?>
            <!-- "Aziende": riservato al solo superadmin. -->
            <?php if ($user['role'] === 'superadmin'): ?>
<a href="<?= BASE_URL ?>admin/aziende.php"
   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
    Aziende
</a>
<?php endif; ?>

            <!-- "Utenti": superadmin e admin (l'admin gestisce solo la sua azienda). -->
<?php if (in_array($user['role'], ['superadmin', 'admin'])): ?>
<a href="<?= BASE_URL ?>admin/utenti.php"
   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
    Utenti
</a>
<?php endif; ?>
        </nav>

        <!-- Lato destro: nome utente + logout. flex-1 sul <nav> spinge questo
             blocco all'estremità destra. -->
        <div class="flex items-center gap-3 text-sm">
            <span class="text-indigo-200"><?= htmlspecialchars($user['name']) ?></span>
            <a href="<?= BASE_URL ?>dashboard/logout.php"
               class="text-red-300 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded transition">
                Esci
            </a>
        </div>
    </div>
</header>