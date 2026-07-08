<?php
/**
 * ==========================================================================
 * CATALOGO.PHP — Controller della pagina pubblica di un singolo catalogo
 * ==========================================================================
 *
 * Pagina PUBBLICA (no login): è la destinazione del QR di un catalogo. Mostra
 * il PDF tramite un flipbook JS (con fallback iframe).
 *
 * Il PDF NON è più servito direttamente: $pdf_url punta al guardiano
 * public/pdf.php, che ripete i controlli prima di consegnarlo.
 *
 * Qui avviene anche il TRACCIAMENTO che riempie catalogo_analytics.
 *
 * Parametri (entrambi obbligatori): a = slug azienda, c = slug catalogo.
 *
 * Questo file è il CONTROLLER (logica). La presentazione è in
 * views/catalogo.view.php, inclusa in fondo.
 */

require_once __DIR__ . '/../config/bootstrap.php';

$azienda_slug  = trim($_GET['a'] ?? '');
$catalogo_slug = trim($_GET['c'] ?? '');

// Servono entrambi gli slug: senza, non c'è nulla da mostrare.
if (empty($azienda_slug) || empty($catalogo_slug)) {
    not_found();
}

// Recuperiamo l'azienda. Se non esiste, 404 dedicato.
$stmt = $pdo->prepare("SELECT * FROM aziende WHERE slug = ? LIMIT 1");
$stmt->execute([$azienda_slug]);
$azienda = $stmt->fetch();

if (!$azienda) {
    not_found("L'azienda richiesta non esiste.");
}

// Recuperiamo il catalogo SOLO se è davvero "mostrabile":
// appartiene a questa azienda, è attivo e non è scaduto.
// Così un link a un catalogo disattivato o scaduto restituisce 404.
$stmt = $pdo->prepare("
    SELECT c.*, a.nome_azienda
    FROM cataloghi c
    JOIN aziende a ON a.id = c.azienda_id
    WHERE c.slug = ?
      AND c.azienda_id = ?
      AND c.is_active = 1
      AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
    LIMIT 1
");
$stmt->execute([$catalogo_slug, $azienda['id']]);
$catalogo = $stmt->fetch();

if (!$catalogo) {
    not_found("Il catalogo non esiste o non è più disponibile.");
}

// URL del PDF: il guardiano pdf.php, NON il file diretto (bloccato dall'.htaccess
// in uploads/pdf/). Passiamo gli stessi slug a/c già validati sopra.
$pdf_url = BASE_URL . 'public/pdf.php?a='
    . urlencode($azienda_slug) . '&c=' . urlencode($catalogo_slug);

// --------------------------------------------------------------------------
// TRACCIAMENTO (analytics)
// --------------------------------------------------------------------------
// Tipo di dispositivo dallo user-agent: classificazione approssimativa, basta
// per statistiche di massima.
$device_type = 'desktop'; // default
$ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
    $device_type = 'mobile';
} elseif (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
    $device_type = 'tablet';
}

// IP salvato come HASH (non in chiaro): basta per contare i visitatori unici
// senza conservare un dato personale. Passa da client_ip() così online (dietro
// proxy) si hasha l'IP reale del visitatore e non quello del proxy.
$ip_hash = hash('sha256', client_ip());

// Registriamo la visita: una riga per ogni apertura del catalogo.
$stmt = $pdo->prepare("INSERT INTO catalogo_analytics (catalogo_id, device_type, ip_hash) VALUES (?, ?, ?)");
$stmt->execute([$catalogo['id'], $device_type, $ip_hash]);

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/catalogo.view.php';