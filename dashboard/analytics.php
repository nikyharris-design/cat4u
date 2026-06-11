<?php
/**
 * ==========================================================================
 * ANALYTICS.PHP — Controller "Statistiche di scansione dei cataloghi"
 * ==========================================================================
 *
 * Aggrega le aperture dei cataloghi con suddivisione per dispositivo e IP unici.
 * Accesso: solo superadmin e admin.
 *
 * Concetti chiave:
 *  1) FILTRI A CASCATA: Azienda → Genere → Catalogo. Ogni livello popola il
 *     successivo. Il superadmin parte dall'azienda; l'admin è ancorato alla sua.
 *  2) WHERE COSTRUITO DINAMICAMENTE: array di condizioni + array di parametri,
 *     sempre parametrizzati (?) → niente SQL injection.
 *  3) QUERY DI AGGREGAZIONE: COUNT, SUM con CASE, GROUP BY.
 *
 * Questo file è il CONTROLLER (logica). La presentazione è in
 * views/analytics.view.php, inclusa in fondo.
 */

require_once __DIR__ . '/../config/bootstrap.php';
require_role('superadmin', 'admin');
require_password_changed();

$user          = current_user();
$is_superadmin = $user['role'] === 'superadmin';

// AZIENDA: il superadmin sceglie (0 = tutte). Chiunque altro è VINCOLATO alla
// propria: $_GET['az'] viene IGNORATO, così un admin non legge dati altrui.
if ($is_superadmin) {
    $filtro_azienda = (int)($_GET['az'] ?? 0);
} else {
    $filtro_azienda = (int)$user['azienda_id'];
}

$filtro_genere   = (int)($_GET['g'] ?? 0);
$filtro_catalogo = (int)($_GET['c'] ?? 0);

// VALIDAZIONE DI PROPRIETÀ: un genere/catalogo è accettato come filtro solo se
// appartiene DAVVERO all'azienda corrente. Altrimenti lo azzeriamo (chiude l'IDOR).
if ($filtro_genere > 0) {
    if ($filtro_azienda <= 0) {
        $filtro_genere = 0; // superadmin senza azienda scelta: filtro privo di senso
    } else {
        $chk = $pdo->prepare("SELECT 1 FROM generi WHERE id = ? AND azienda_id = ? LIMIT 1");
        $chk->execute([$filtro_genere, $filtro_azienda]);
        if (!$chk->fetchColumn()) $filtro_genere = 0;
    }
}

if ($filtro_catalogo > 0) {
    if ($filtro_azienda <= 0) {
        $filtro_catalogo = 0;
    } else {
        $chk = $pdo->prepare("SELECT 1 FROM cataloghi WHERE id = ? AND azienda_id = ? LIMIT 1");
        $chk->execute([$filtro_catalogo, $filtro_azienda]);
        if (!$chk->fetchColumn()) $filtro_catalogo = 0;
    }
}

// --------------------------------------------------------------------------
// LISTE PER I MENU A TENDINA (popolate "a cascata")
// --------------------------------------------------------------------------
$aziende_list = $is_superadmin
    ? $pdo->query("SELECT id, nome_azienda FROM aziende ORDER BY nome_azienda ASC")->fetchAll()
    : [];

$generi_list = [];
if ($filtro_azienda > 0) {
    $stmt = $pdo->prepare("SELECT id, nome_genere FROM generi WHERE azienda_id = ? ORDER BY nome_genere ASC");
    $stmt->execute([$filtro_azienda]);
    $generi_list = $stmt->fetchAll();
}

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
// Si applica il filtro PIÙ SPECIFICO disponibile (catalogo > genere > azienda).
$where = [];
$params = [];

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
    $where[] = "c.azienda_id = ?";
    $params[] = $user['azienda_id'];
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// --------------------------------------------------------------------------
// QUERY 1 — Totali globali (con ripartizione per dispositivo)
// --------------------------------------------------------------------------
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
$stmt->execute($params);
$stats = $stmt->fetch();

// --------------------------------------------------------------------------
// QUERY 2 — Dettaglio per singolo catalogo
// --------------------------------------------------------------------------
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
// $where_sql può già contenere "WHERE": scegliamo "AND" o "WHERE" di conseguenza.
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

// Valore massimo giornaliero: riferimento per scalare le barre del grafico.
$max_giorno = 0;
foreach ($per_giorno as $r) {
    if ((int)$r['totale'] > $max_giorno) $max_giorno = (int)$r['totale'];
}

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/analytics.view.php';