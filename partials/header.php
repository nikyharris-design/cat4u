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
 * e il link di logout. Su telefono il menu collassa in un hamburger (solo CSS,
 * checkbox-hack: nessuno script inline, compatibile con la CSP).
 *
 * Le voci "Analytics", "Aziende" e "Utenti" compaiono solo per i ruoli che ne
 * hanno diritto: è una comodità di interfaccia. La vera protezione resta nelle
 * singole pagine (require_role).
 */

if (!isset($user)) {
    $user = current_user();
}
?>
<style>
    /* Desktop: tutto in riga, hamburger nascosto. */
    .nav-cb{ display:none; }
    .nav-burger{ display:none; }
    .nav-collapse{ display:flex; }

    /* Telefono: header più basso + menu a tendina via hamburger. */
    @media (max-width:767px){
        .nav-bar{ height:2.75rem; gap:.75rem; position:relative; }
        .nav-logo{ font-size:1rem; }

        .nav-burger{
            display:flex; flex-direction:column; justify-content:center;
            gap:4px; margin-left:auto; color:#fff; cursor:pointer;
            width:2.25rem; height:2.25rem; padding:.4rem; border-radius:.5rem;
        }
        .nav-burger span{
            display:block; width:100%; height:2px;
            background:currentColor; border-radius:2px;
            transition:transform .2s ease, opacity .2s ease;
        }
        /* Le tre barrette diventano una X quando il menu è aperto. */
        #nav-toggle:checked ~ .nav-bar .nav-burger span:nth-child(1){ transform:translateY(6px) rotate(45deg); }
        #nav-toggle:checked ~ .nav-bar .nav-burger span:nth-child(2){ opacity:0; }
        #nav-toggle:checked ~ .nav-bar .nav-burger span:nth-child(3){ transform:translateY(-6px) rotate(-45deg); }

        .nav-collapse{
            display:none;
            position:absolute; top:100%; left:0; right:0;
            flex-direction:column; align-items:stretch; gap:.15rem;
            background:var(--surface);
            border-top:1px solid var(--border);
            border-bottom:1px solid var(--border);
            box-shadow:0 8px 24px rgba(0,0,0,.45);
            padding:.5rem;
        }
        #nav-toggle:checked ~ .nav-bar .nav-collapse{ display:flex; }

        .nav-collapse nav{ flex:none; flex-direction:column; align-items:stretch; gap:.1rem; }
        .nav-collapse nav a{ display:block; }
        .nav-collapse > div{
            flex-direction:column; align-items:stretch; gap:.25rem;
            border-top:1px solid var(--border); margin-top:.25rem; padding-top:.5rem;
        }
    }
</style>

<header class="bg-indigo-600 text-white shadow sticky top-0 z-50">
    <input type="checkbox" id="nav-toggle" class="nav-cb">
    <div class="nav-bar max-w-5xl mx-auto px-4 h-14 flex items-center gap-6">
        <!-- Logo: riporta alla dashboard. -->
        <a href="<?= BASE_URL ?>dashboard/index.php"
           class="nav-logo font-bold text-lg text-white hover:text-indigo-200 transition">Cat4U</a>

        <!-- Hamburger (solo telefono). Il <label> apre/chiude il checkbox. -->
        <label for="nav-toggle" class="nav-burger" aria-label="Apri o chiudi il menu">
            <span></span><span></span><span></span>
        </label>

        <!-- Blocco che collassa nell'hamburger: voci + nome + logout. -->
        <div class="nav-collapse items-center gap-6 flex-1">
            <nav class="flex items-center gap-1 flex-1">
                <!-- "Cataloghi": tutti i ruoli autenticati. -->
                <a href="<?= BASE_URL ?>dashboard/cataloghi.php"
                   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                    Cataloghi
                </a>

                <!-- "Analytics": superadmin e admin. -->
                <?php if (in_array($user['role'], ['superadmin', 'admin'])): ?>
                <a href="<?= BASE_URL ?>dashboard/analytics.php"
                   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                    Analytics
                </a>
                <?php endif; ?>

                <!-- "Libreria": solo superadmin. -->
                <?php if ($user['role'] === 'superadmin'): ?>
                <a href="<?= BASE_URL ?>public/libreria.php"
                   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                    Libreria
                </a>
                <?php endif; ?>

                <!-- "Generi": tutti tranne il superadmin. -->
                <?php if ($user['role'] !== 'superadmin'): ?>
                <a href="<?= BASE_URL ?>dashboard/generi.php"
                   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                    Generi
                </a>
                <?php endif; ?>

                <!-- "Aziende": solo superadmin. -->
                <?php if ($user['role'] === 'superadmin'): ?>
                <a href="<?= BASE_URL ?>admin/aziende.php"
                   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                    Aziende
                </a>
                <?php endif; ?>

                <!-- "Utenti": superadmin e admin. -->
                <?php if (in_array($user['role'], ['superadmin', 'admin'])): ?>
                <a href="<?= BASE_URL ?>admin/utenti.php"
                   class="text-indigo-200 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded text-sm transition">
                    Utenti
                </a>
                <?php endif; ?>
            </nav>

            <!-- Nome utente + logout. -->
            <div class="flex items-center gap-3 text-sm">
                <span class="text-indigo-200"><?= htmlspecialchars($user['name']) ?></span>
                <a href="<?= BASE_URL ?>dashboard/logout.php"
                   class="text-red-300 hover:text-white hover:bg-white/10 px-3 py-1.5 rounded transition">
                    Esci
                </a>
            </div>
        </div>
    </div>
</header>