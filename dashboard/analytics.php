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
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition">
                    Reset filtri
                </a>
                <?php endif; ?>

            </form>
        </div>

        <!-- ===================== STATISTICHE GLOBALI ===================== -->
        <!-- I "?? 0" coprono il caso senza dati (la query di aggregazione può
             restituire NULL se non ci sono righe). -->
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-4 text-center">
                <div class="text-3xl font-bold text-indigo-600"><?= $stats['totale'] ?? 0 ?></div>
                <div class="text-xs text-gray-500 mt-1">Scansioni totali</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 text-center">
                <div class="text-3xl font-bold text-blue-600"><?= $stats['ip_unici'] ?? 0 ?></div>
                <div class="text-xs text-gray-500 mt-1">IP unici</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 text-center">
                <div class="text-3xl font-bold text-green-600"><?= $stats['mobile'] ?? 0 ?></div>
                <div class="text-xs text-gray-500 mt-1">Mobile</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 text-center">
                <div class="text-3xl font-bold text-yellow-600"><?= $stats['tablet'] ?? 0 ?></div>
                <div class="text-xs text-gray-500 mt-1">Tablet</div>
            </div>
            <div class="bg-white rounded-xl shadow p-4 text-center">
                <div class="text-3xl font-bold text-gray-600"><?= $stats['desktop'] ?? 0 ?></div>
                <div class="text-xs text-gray-500 mt-1">Desktop</div>
            </div>
        </div>

        <!-- ===================== DETTAGLIO PER CATALOGO ===================== -->
        <div class="bg-white rounded-xl shadow overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-500 uppercase">Scansioni per catalogo</h3>
            </div>
            <?php if (empty($per_catalogo)): ?>
                <p class="text-gray-400 text-sm text-center py-8">Nessuna scansione registrata.</p>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Catalogo</th>
                        <th class="px-4 py-3 text-left">Genere</th>
                        <th class="px-4 py-3 text-right">Totale</th>
                        <th class="px-4 py-3 text-right">IP unici</th>
                        <th class="px-4 py-3 text-right">Mobile</th>
                        <th class="px-4 py-3 text-right">Tablet</th>
                        <th class="px-4 py-3 text-right">Desktop</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($per_catalogo as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-800"><?= htmlspecialchars($row['titolo']) ?></td>
                        <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars($row['nome_genere']) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-indigo-600"><?= $row['totale'] ?></td>
                        <td class="px-4 py-3 text-right text-blue-600"><?= $row['ip_unici'] ?></td>
                        <td class="px-4 py-3 text-right text-green-600"><?= $row['mobile'] ?></td>
                        <td class="px-4 py-3 text-right text-yellow-600"><?= $row['tablet'] ?></td>
                        <td class="px-4 py-3 text-right text-gray-600"><?= $row['desktop'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- ================== ANDAMENTO ULTIMI 30 GIORNI ================== -->
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-500 uppercase">Andamento ultimi 30 giorni</h3>
            </div>
            <?php if (empty($per_giorno)): ?>
                <p class="text-gray-400 text-sm text-center py-8">Nessuna scansione negli ultimi 30 giorni.</p>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-500 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Giorno</th>
                        <th class="px-4 py-3 text-right">Scansioni</th>
                        <th class="px-4 py-3 text-right">IP unici</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($per_giorno as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <!-- strtotime + date riformattano la data in gg/mm/aaaa. -->
                        <td class="px-4 py-3 text-gray-700"><?= date('d/m/Y', strtotime($row['giorno'])) ?></td>
                        <td class="px-4 py-3 text-right font-semibold text-indigo-600"><?= $row['totale'] ?></td>
                        <td class="px-4 py-3 text-right text-blue-600"><?= $row['ip_unici'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </main>
</body>
</html>