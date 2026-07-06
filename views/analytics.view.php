<?php
/**
 * ==========================================================================
 * ANALYTICS.VIEW.PHP — Vista della pagina "Analytics"
 * ==========================================================================
 *
 * Solo presentazione. Inclusa da dashboard/analytics.php dopo che il controller
 * ha preparato i dati. Variabili disponibili: $is_superadmin, $filtro_azienda,
 * $filtro_genere, $filtro_catalogo, $aziende_list, $generi_list,
 * $cataloghi_list, $stats, $per_catalogo, $per_giorno, $max_giorno, $user.
 *
 * I calcoli presenti qui sono SOLO grafici (percentuali per le larghezze delle
 * barre): non toccano i dati, scalano la presentazione. Non va mai aperta
 * direttamente (views/.htaccess).
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Analytics — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script src="<?= BASE_URL ?>assets/js/dashboard.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4 analytics">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Analytics</h2>
        </div>

        <!-- ============================ FILTRI ============================ -->
        <!-- Un unico form GET: ogni <select> ha data-autosubmit, quindi cambiare
             un filtro ricarica la pagina con i nuovi parametri. -->
        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <form method="GET" action="" class="flex flex-wrap items-end gap-3">

                <!-- Filtro azienda: solo superadmin. -->
                <?php if ($is_superadmin): ?>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Azienda</label>
                    <select name="az" data-autosubmit
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <option value="0">— Tutte —</option>
                        <?php foreach ($aziende_list as $az): ?>
                            <option value="<?= $az['id'] ?>" <?= $filtro_azienda === $az['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($az['nome_azienda']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Filtro genere: compare solo se ci sono generi (azienda scelta). -->
                <?php if (!empty($generi_list)): ?>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Genere</label>
                    <select name="g" data-autosubmit
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <option value="0">— Tutti —</option>
                        <?php foreach ($generi_list as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= $filtro_genere === $g['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['nome_genere']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Filtro catalogo: come sopra, condizionato alla presenza di cataloghi. -->
                <?php if (!empty($cataloghi_list)): ?>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Catalogo</label>
                    <select name="c" data-autosubmit
                            class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <option value="0">— Tutti —</option>
                        <?php foreach ($cataloghi_list as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $filtro_catalogo === $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['titolo']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Reset: appare solo se almeno un filtro è attivo. -->
                <?php if ($filtro_azienda || $filtro_genere || $filtro_catalogo): ?>
                <a href="<?= BASE_URL ?>dashboard/analytics.php"
                   style="display:inline-flex; align-items:center; align-self:center; height:38px; padding:0 1rem; background:#1f222c; color:#e7e9ef; border:1px solid #2b2f3a; border-radius:0.5rem; font-size:0.875rem; font-weight:500; text-decoration:none; white-space:nowrap;"
                   data-base-bg="#1f222c" data-hover-bg="#282c38">
                    Reset filtri
                </a>
                <?php endif; ?>

            </form>
        </div>

        <!-- ===================== STATISTICHE GLOBALI ===================== -->
        <?php
            // Percentuali per la barra (solo grafica, non toccano i dati).
            $g_mobile  = (int)($stats['mobile']  ?? 0);
            $g_tablet  = (int)($stats['tablet']  ?? 0);
            $g_desktop = (int)($stats['desktop'] ?? 0);
            $g_tot_dev = $g_mobile + $g_tablet + $g_desktop;
            $g_pct_m = $g_tot_dev > 0 ? round($g_mobile  / $g_tot_dev * 100) : 0;
            $g_pct_t = $g_tot_dev > 0 ? round($g_tablet  / $g_tot_dev * 100) : 0;
            $g_pct_d = $g_tot_dev > 0 ? round($g_desktop / $g_tot_dev * 100) : 0;
        ?>
        <div class="bg-white rounded-xl shadow p-6 mb-6 flex flex-col md:flex-row md:items-center gap-6">

            <!-- Metriche principali -->
            <div class="flex md:pr-8 md:border-r md:border-gray-100" style="gap: 3rem;">
                <div>
                    <div class="text-4xl font-bold text-indigo-600 leading-none"><?= $stats['totale'] ?? 0 ?></div>
                    <div class="text-xs text-gray-500 mt-2 uppercase tracking-wide">Scansioni totali</div>
                </div>
                <div>
                    <div class="text-4xl font-bold text-blue-600 leading-none"><?= $stats['ip_unici'] ?? 0 ?></div>
                    <div class="text-xs text-gray-500 mt-2 uppercase tracking-wide">IP unici</div>
                </div>
            </div>

            <!-- Ripartizione dispositivi -->
            <div class="flex-1 min-w-0">
                <div class="text-xs text-gray-400 uppercase tracking-wide mb-2">Dispositivi</div>
                <div class="flex h-2.5 rounded-full overflow-hidden bg-gray-100 mb-3">
                    <div class="bg-green-500"  style="width: <?= $g_pct_m ?>%"></div>
                    <div class="bg-yellow-400" style="width: <?= $g_pct_t ?>%"></div>
                    <div class="bg-gray-400"   style="width: <?= $g_pct_d ?>%"></div>
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-green-500"></span>
                        <span class="text-gray-500">Mobile</span>
                        <span class="font-semibold text-gray-700"><?= $g_mobile ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-yellow-400"></span>
                        <span class="text-gray-500">Tablet</span>
                        <span class="font-semibold text-gray-700"><?= $g_tablet ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span>
                        <span class="text-gray-500">Desktop</span>
                        <span class="font-semibold text-gray-700"><?= $g_desktop ?></span>
                    </div>
                </div>
            </div>

        </div>

        <!-- ===================== DETTAGLIO PER CATALOGO ===================== -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Scansioni per catalogo</h3>

            <?php if (empty($per_catalogo)): ?>
                <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400 text-sm">
                    Nessuna scansione registrata.
                </div>
            <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($per_catalogo as $row):
                    // Percentuali per la barra di ripartizione (solo grafica).
                    $tot_dev = (int)$row['mobile'] + (int)$row['tablet'] + (int)$row['desktop'];
                    $pct_m = $tot_dev > 0 ? round((int)$row['mobile']  / $tot_dev * 100) : 0;
                    $pct_t = $tot_dev > 0 ? round((int)$row['tablet']  / $tot_dev * 100) : 0;
                    $pct_d = $tot_dev > 0 ? round((int)$row['desktop'] / $tot_dev * 100) : 0;
                ?>
                <div class="bg-white rounded-xl shadow p-5 flex flex-col">

                    <!-- Intestazione: titolo + genere a sinistra, totale a destra -->
                    <div class="flex items-start justify-between gap-3 mb-4">
                        <div class="min-w-0">
                            <h4 class="font-semibold text-gray-800 leading-tight truncate" title="<?= htmlspecialchars($row['titolo']) ?>">
                                <?= htmlspecialchars($row['titolo']) ?>
                            </h4>
                            <span class="inline-block mt-1 text-xs text-gray-500 bg-gray-100 rounded-full px-2 py-0.5">
                                <?= htmlspecialchars($row['nome_genere']) ?>
                            </span>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="text-2xl font-bold text-indigo-600 leading-none"><?= $row['totale'] ?></div>
                            <div class="text-[10px] uppercase tracking-wide text-gray-400 mt-1">scansioni</div>
                        </div>
                    </div>

                    <!-- Barra di ripartizione dispositivi -->
                    <div class="flex h-2 rounded-full overflow-hidden bg-gray-100 mb-4">
                        <div class="bg-green-500"  style="width: <?= $pct_m ?>%"></div>
                        <div class="bg-yellow-400" style="width: <?= $pct_t ?>%"></div>
                        <div class="bg-gray-400"   style="width: <?= $pct_d ?>%"></div>
                    </div>

                    <!-- Legenda con i conteggi -->
                    <div class="space-y-1.5 text-xs">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-green-500"></span>
                            <span class="text-gray-500">Mobile</span>
                            <span class="ml-auto font-medium text-gray-700"><?= $row['mobile'] ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-yellow-400"></span>
                            <span class="text-gray-500">Tablet</span>
                            <span class="ml-auto font-medium text-gray-700"><?= $row['tablet'] ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-gray-400"></span>
                            <span class="text-gray-500">Desktop</span>
                            <span class="ml-auto font-medium text-gray-700"><?= $row['desktop'] ?></span>
                        </div>
                    </div>

                    <!-- IP unici in fondo -->
                    <div class="border-t border-gray-100 mt-4 pt-3 flex items-center justify-between text-xs">
                        <span class="text-gray-500">IP unici</span>
                        <span class="font-semibold text-blue-600"><?= $row['ip_unici'] ?></span>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ================== ANDAMENTO ULTIMI 30 GIORNI ================== -->
        <div class="bg-white rounded-xl shadow p-5">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-4">Andamento ultimi 30 giorni</h3>

            <?php if (empty($per_giorno)): ?>
                <p class="text-gray-400 text-sm text-center py-6">Nessuna scansione negli ultimi 30 giorni.</p>
            <?php else: ?>
            <div class="space-y-2">
                <?php foreach ($per_giorno as $row):
                    $w = $max_giorno > 0 ? round((int)$row['totale'] / $max_giorno * 100) : 0;
                ?>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-500 w-12 shrink-0"><?= date('d/m', strtotime($row['giorno'])) ?></span>
                    <div class="flex-1 bg-gray-100 rounded-full h-5 overflow-hidden">
                        <div class="bg-indigo-500 h-full rounded-full transition-all" style="width: <?= max($w, 3) ?>%"></div>
                    </div>
                    <span class="text-sm font-semibold text-indigo-600 w-8 text-right shrink-0"><?= $row['totale'] ?></span>
                    <span class="text-xs text-blue-600 w-16 text-right shrink-0"><?= $row['ip_unici'] ?> IP</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </main>
</body>
</html>