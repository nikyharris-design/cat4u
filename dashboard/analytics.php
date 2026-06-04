<?php
/**
 * ==========================================================================
 * ANALYTICS.PHP — Statistiche di scansione dei cataloghi
 * ==========================================================================
 *
 * Mostra quante volte sono stati aperti i cataloghi (scansioni QR), con
 * suddivisione per dispositivo (mobile/tablet/desktop) e IP unici.
 *
 * Accesso: solo superadmin e admin (gli "user" non vedono le analytics).
 *
 * Tre concetti chiave da seguire:
 *
 *  1) FILTRI A CASCATA: Azienda → Genere → Catalogo.
 *     Ogni livello popola le opzioni del successivo. Il superadmin parte dalla
 *     scelta dell'azienda; l'admin è già "ancorato" alla propria.
 *
 *  2) WHERE COSTRUITO DINAMICAMENTE.
 *     Invece di scrivere tante query diverse, costruiamo un array di condizioni
 *     e un array di parametri, poi li uniamo. Più filtri attivi = più condizioni.
 *     I valori restano sempre parametrizzati (?) → niente SQL injection.
 *
 *  3) QUERY DI AGGREGAZIONE.
 *     COUNT, SUM con CASE e GROUP BY per ottenere totali e ripartizioni.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin', 'admin');
require_password_changed();

$user          = current_user();
$is_superadmin = $user['role'] === 'superadmin';

// --------------------------------------------------------------------------
// LETTURA DEI FILTRI (dalla query string)
// --------------------------------------------------------------------------
// az = azienda, g = genere, c = catalogo. 0 = "nessun filtro a questo livello".
// Per l'admin, il filtro azienda è forzato alla sua azienda (non può cambiarlo).
$filtro_azienda  = (int)($_GET['az'] ?? ($is_superadmin ? 0 : $user['azienda_id']));
$filtro_genere   = (int)($_GET['g'] ?? 0);
$filtro_catalogo = (int)($_GET['c'] ?? 0);

// --------------------------------------------------------------------------
// LISTE PER I MENU A TENDINA (popolate "a cascata")
// --------------------------------------------------------------------------
// Elenco aziende: solo al superadmin serve sceglierla.
$aziende_list = $is_superadmin
    ? $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll()
    : [];

// I generi compaiono solo dopo aver scelto un'azienda.
$generi_list = [];
if ($filtro_azienda > 0) {
    $stmt = $pdo->prepare("SELECT id, nome_genere FROM generi WHERE azienda_id = ? ORDER BY nome_genere ASC");
    $stmt->execute([$filtro_azienda]);
    $generi_list = $stmt->fetchAll();
}

// I cataloghi dipendono dal genere (se scelto) o comunque dall'azienda.
$cataloghi_list = [];
if ($filtro_genere > 0) {
    $stmt = $pdo->prepare("SELECT id, titolo FROM cataloghi WHERE genere_id = ? AND azienda_id = ? AND is_active = 1 ORDER BY titolo ASC");
    $stmt->execute([$filtro_genere, $filtro_azienda]);
    $cataloghi_list = $stmt->fetchAll();
} elseif ($filtro_azienda > 0) {
    $stmt = $pdo->prepare("SELECT id, titolo FROM cataloghi WHERE azienda_id = ? AND is_active = 1 ORDER BY titolo ASC");
    $stmt->execute([$filtro_azienda]);
    $cataloghi_list = $stmt->fetchAll();
}

// --------------------------------------------------------------------------
// COSTRUZIONE DINAMICA DEL "WHERE"
// --------------------------------------------------------------------------
// $where raccoglie le condizioni SQL (come stringhe con segnaposto ?),
// $params raccoglie i valori corrispondenti, nello STESSO ordine.
$where = [];
$params = [];

// Si applica il filtro PIÙ SPECIFICO disponibile (catalogo > genere > azienda).
// Gli elseif garantiscono che ne valga uno solo per volta.
if ($filtro_catalogo > 0) {
    $where[] = "ca.catalogo_id = ?";
    $params[] = $filtro_catalogo;
} elseif ($filtro_genere > 0) {
    $where[] = "c.genere_id = ?";
    $params[] = $filtro_genere;
} elseif ($filtro_azienda > 0) {
    $where[] = "c.azienda_id = ?";
    $params[] = $filtro_azienda;
} elseif (!$is_superadmin) {
    // Sicurezza: un admin senza filtri NON deve vedere i dati globali.
    // Lo confiniamo comunque alla sua azienda.
    $where[] = "c.azienda_id = ?";
    $params[] = $user['azienda_id'];
}

// Trasformiamo l'array di condizioni in una stringa SQL. Se è vuoto (superadmin
// senza filtri), $where_sql resta vuota → la query considera TUTTO.
$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// --------------------------------------------------------------------------
// QUERY 1 — Totali globali (con la ripartizione per dispositivo)
// --------------------------------------------------------------------------
// SUM(CASE WHEN ...) è un trucco classico: conta le righe che soddisfano una
// condizione (1) ignorando le altre (0). Così in una sola query otteniamo i
// totali per ciascun tipo di dispositivo.
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS totale,
        COUNT(DISTINCT ca.ip_hash) AS ip_unici,
        SUM(CASE WHEN ca.device_type = 'mobile' THEN 1 ELSE 0 END) AS mobile,
        SUM(CASE WHEN ca.device_type = 'tablet' THEN 1 ELSE 0 END) AS tablet,
        SUM(CASE WHEN ca.device_type = 'desktop' THEN 1 ELSE 0 END) AS desktop
    FROM catalogo_analytics ca
    JOIN cataloghi c ON c.id = ca.catalogo_id
    $where_sql
");
$stmt->execute($params); // gli stessi $params, nell'ordine in cui li abbiamo accodati
$stats = $stmt->fetch();

// --------------------------------------------------------------------------
// QUERY 2 — Dettaglio per singolo catalogo
// --------------------------------------------------------------------------
// GROUP BY raggruppa le scansioni per catalogo, così ogni riga del risultato
// è un catalogo con i suoi totali. ORDER BY totale DESC = i più visti in cima.
$stmt = $pdo->prepare("
    SELECT 
        c.titolo,
        c.slug,
        g.nome_genere,
        COUNT(*) AS totale,
        COUNT(DISTINCT ca.ip_hash) AS ip_unici,
        SUM(CASE WHEN ca.device_type = 'mobile' THEN 1 ELSE 0 END) AS mobile,
        SUM(CASE WHEN ca.device_type = 'tablet' THEN 1 ELSE 0 END) AS tablet,
        SUM(CASE WHEN ca.device_type = 'desktop' THEN 1 ELSE 0 END) AS desktop
    FROM catalogo_analytics ca
    JOIN cataloghi c ON c.id = ca.catalogo_id
    JOIN generi g ON g.id = c.genere_id
    $where_sql
    GROUP BY c.id, c.titolo, c.slug, g.nome_genere
    ORDER BY totale DESC
");
$stmt->execute($params);
$per_catalogo = $stmt->fetchAll();

// --------------------------------------------------------------------------
// QUERY 3 — Andamento per giorno (ultimi 30 giorni)
// --------------------------------------------------------------------------
// Nota sulla composizione SQL: dato che $where_sql può già contenere "WHERE",
// scegliamo se aggiungere la condizione sui 30 giorni con "AND" o "WHERE".
// DATE(...) raggruppa per giorno ignorando l'orario.
$stmt = $pdo->prepare("
    SELECT 
        DATE(ca.scanned_at) AS giorno,
        COUNT(*) AS totale,
        COUNT(DISTINCT ca.ip_hash) AS ip_unici
    FROM catalogo_analytics ca
    JOIN cataloghi c ON c.id = ca.catalogo_id
    $where_sql
    " . ($where ? "AND" : "WHERE") . " ca.scanned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(ca.scanned_at)
    ORDER BY giorno DESC
");
$stmt->execute($params);
$per_giorno = $stmt->fetchAll();

// --------------------------------------------------------------------------
// SUPPORTO ALLA PRESENTAZIONE (solo per il rendering, niente logica dati)
// --------------------------------------------------------------------------
// Valore massimo giornaliero: serve a scalare le barrine del mini-grafico,
// così la barra più alta riempie il 100% e le altre sono proporzionali.
$max_giorno = 0;
foreach ($per_giorno as $r) {
    if ((int)$r['totale'] > $max_giorno) $max_giorno = (int)$r['totale'];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Analytics — Cat4U</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <?php require __DIR__ . '/../partials/header.php'; ?>
    <main class="max-w-5xl mx-auto py-8 px-4">

        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">Analytics</h2>
        </div>

        <!-- ============================ FILTRI ============================ -->
        <!-- Un unico form GET: ogni <select> ha onchange="this.form.submit()",
             quindi cambiare un filtro ricarica la pagina con i nuovi parametri. -->
        <div class="bg-white rounded-xl shadow p-4 mb-6">
            <form method="GET" action="" class="flex flex-wrap items-end gap-3">

                <!-- Filtro azienda: solo superadmin. -->
                <?php if ($is_superadmin): ?>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Azienda</label>
                    <select name="az" onchange="this.form.submit()"
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

                <!-- Filtro genere: compare solo se ci sono generi da mostrare
                     (cioè se è stata scelta un'azienda). -->
                <?php if (!empty($generi_list)): ?>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 uppercase mb-1">Genere</label>
                    <select name="g" onchange="this.form.submit()"
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
                    <select name="c" onchange="this.form.submit()"
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
                   style="display:inline-flex; align-items:center; align-self:center; height:38px; padding:0 1rem; background:#e5e7eb; color:#374151; border-radius:0.5rem; font-size:0.875rem; font-weight:500; text-decoration:none; white-space:nowrap;"
                   onmouseover="this.style.background='#d1d5db'"
                   onmouseout="this.style.background='#e5e7eb'">
                    Reset filtri
                </a>
                <?php endif; ?>

            </form>
        </div>
        <!-- ===================== STATISTICHE GLOBALI ===================== -->
        <!-- Card di riepilogo: a sinistra le due metriche principali (scansioni
             totali e IP unici), a destra la ripartizione per dispositivo come
             barra + legenda, nello stesso linguaggio delle card dei cataloghi.
             I "?? 0" coprono il caso senza dati (la query di aggregazione può
             restituire NULL se non ci sono righe). -->
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
        <!-- Prima era una tabella a tutta larghezza: ora una griglia di card.
             Ogni card mette in evidenza il totale, mostra una barra di
             ripartizione dei dispositivi e in fondo gli IP unici. -->
        <div class="mb-6">
            <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Scansioni per catalogo</h3>

            <?php if (empty($per_catalogo)): ?>
                <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400 text-sm">
                    Nessuna scansione registrata.
                </div>
            <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($per_catalogo as $row):
                    // Percentuali per la barra di ripartizione (calcolate solo per
                    // la grafica: non toccano i dati). Se non ci sono dispositivi
                    // censiti, la barra resta vuota (track grigio).
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

                    <!-- Barra di ripartizione dispositivi (mobile/tablet/desktop) -->
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

                    <!-- IP unici in fondo, separato da una linea -->
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
        <!-- Anche qui niente più tabella a tutta larghezza: una sola card con un
             mini grafico a barre orizzontali. La larghezza della barra è
             proporzionale al giorno con più scansioni ($max_giorno). -->
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
                    <!-- strtotime + date riformattano la data in gg/mm. -->
                    <span class="text-xs text-gray-500 w-12 shrink-0"><?= date('d/m', strtotime($row['giorno'])) ?></span>

                    <!-- Track della barra + riempimento proporzionale -->
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