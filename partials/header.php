<?php
if (!isset($user)) {
    $user = current_user();
}
?>
<header class="bg-indigo-600 text-white shadow sticky top-0 z-50">
    <div class="max-w-5xl mx-auto px-4 h-14 flex items-center gap-6">
        <a href="<?= BASE_URL ?>dashboard/index.php"
           class="font-bold text-lg text-white hover:text-indigo-200 transition">Cat4U</a>

        <nav class="flex items-center gap-1 flex-1">
            <a href="<?= BASE_URL ?>dashboard/cataloghi.php"
               class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                Cataloghi
            </a>
            <a href="<?= BASE_URL ?>dashboard/generi.php"
               class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                Generi
            </a>
            <?php if ($user['role'] === 'superadmin'): ?>
            <a href="<?= BASE_URL ?>admin/aziende.php"
               class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                Aziende
            </a>
            <a href="<?= BASE_URL ?>admin/utenti.php"
               class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                Utenti
            </a>
            <?php endif; ?>
        </nav>

        <div class="flex items-center gap-3 text-sm">
            <span class="text-indigo-200"><?= htmlspecialchars($user['name']) ?></span>
            <a href="<?= BASE_URL ?>dashboard/logout.php"
               class="text-red-300 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded transition">
                Esci
            </a>
        </div>
    </div>
</header>