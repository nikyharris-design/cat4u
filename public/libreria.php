<?php
/**
 * ==========================================================================
 * LIBRERIA.PHP — Controller della vetrina pubblica di un'azienda
 * ==========================================================================
 *
 * Pagina PUBBLICA (nessun login): è ciò che vede un cliente finale dopo aver
 * scansionato il QR aziendale. Mostra i cataloghi attivi dell'azienda, con
 * filtro opzionale per genere.
 *
 * Si raggiunge in due modi (entrambi finiscono qui):
 *   - URL pulito:  /nome-azienda            (gestito da index.php)
 *   - URL diretto: public/libreria.php?a=slug-azienda&g=slug-genere
 *
 * Parametri: a = slug azienda (obbligatorio), g = slug genere (opzionale).
 *
 * Questo file è il CONTROLLER (logica). La presentazione è in
 * views/libreria.view.php, inclusa in fondo. Usa solo prepared statement.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Slug azienda dalla query string. Senza, non sappiamo cosa mostrare → 404.
$slug = trim($_GET['a'] ?? '');


if (empty($slug)) {
    not_found();
}

// Recuperiamo l'azienda dallo slug. Se non esiste, 404.
$stmt = $pdo->prepare("SELECT * FROM aziende WHERE slug = ? LIMIT 1");
$stmt->execute([$slug]);
$azienda = $stmt->fetch();

if (!$azienda) {
    not_found("L'azienda richiesta non esiste.");
}

// --- FILTRO GENERE (opzionale) ---
$genere_slug   = trim($_GET['g'] ?? '');
$genere_attivo = null; // resta null se non si filtra per genere

if ($genere_slug) {
    // Cerchiamo il genere PER QUESTA azienda (slug + azienda_id): impedisce di
    // filtrare con un genere di un'altra azienda.
    $stmt = $pdo->prepare("SELECT * FROM generi WHERE slug = ? AND azienda_id = ? LIMIT 1");
    $stmt->execute([$genere_slug, $azienda['id']]);
    $genere_attivo = $stmt->fetch();
}

// --- GENERI DA MOSTRARE COME "TAB" DI FILTRO ---
// Solo i generi che hanno almeno un catalogo pubblicabile (attivo e non scaduto).
$generi = $pdo->prepare("
    SELECT g.* FROM generi g
    WHERE g.azienda_id = ?
    AND EXISTS (
        SELECT 1 FROM cataloghi c
        WHERE c.genere_id = g.id
        AND c.is_active = 1
        AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
    )
    ORDER BY g.nome_genere ASC
");
$generi->execute([$azienda['id']]);
$generi = $generi->fetchAll();

// --- ELENCO CATALOGHI ---
// Condizioni comuni "attivo + non scaduto"; cambia solo se filtriamo per genere.
if ($genere_attivo) {
    $stmt = $pdo->prepare("
        SELECT c.*, g.nome_genere FROM cataloghi c
        JOIN generi g ON g.id = c.genere_id
        WHERE c.azienda_id = ?
        AND c.genere_id = ?
        AND c.is_active = 1
        AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$azienda['id'], $genere_attivo['id']]);
} else {
    $stmt = $pdo->prepare("
        SELECT c.*, g.nome_genere FROM cataloghi c
        JOIN generi g ON g.id = c.genere_id
        WHERE c.azienda_id = ?
        AND c.is_active = 1
        AND (c.data_scadenza IS NULL OR c.data_scadenza > NOW())
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$azienda['id']]);
}
$cataloghi = $stmt->fetchAll();

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/libreria.view.php';