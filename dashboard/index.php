<?php
/**
 * ==========================================================================
 * INDEX.PHP — Controller della dashboard (pannello iniziale dopo il login)
 * ==========================================================================
 *
 * Prepara i tre contatori di riepilogo e delega la presentazione alla vista
 * views/index.view.php.
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
// così possiamo usare un semplice if ($azienda_id) per distinguere i casi.
$azienda_id = (int)$user['azienda_id'];

// Slug dell'azienda: serve alla vista per il link "Vedi libreria pubblica".
// Resta null per il superadmin (nessuna azienda propria): in quel caso la
// vista non mostra il link.
$azienda_slug = null;

if ($azienda_id) {
    // ---- RAMO "UTENTE DI UN'AZIENDA": conteggi filtrati per azienda_id ----

    // Slug dell'azienda, per costruire l'URL della vetrina pubblica.
    $stmt_slug = $pdo->prepare("SELECT slug FROM aziende WHERE id = ?");
    $stmt_slug->execute([$azienda_id]);
    $azienda_slug = $stmt_slug->fetchColumn() ?: null;

    $tot_cataloghi = $pdo->prepare("SELECT COUNT(*) FROM cataloghi WHERE azienda_id = ?");
    $tot_cataloghi->execute([$azienda_id]);
    $tot_cataloghi = (int)$tot_cataloghi->fetchColumn();

    $tot_attivi = $pdo->prepare("SELECT COUNT(*) FROM cataloghi WHERE azienda_id = ? AND is_active = 1");
    $tot_attivi->execute([$azienda_id]);
    $tot_attivi = (int)$tot_attivi->fetchColumn();

    // La tabella analytics non ha azienda_id: JOIN sui cataloghi per risalire all'azienda.
    $tot_scansioni = $pdo->prepare("
        SELECT COUNT(*) FROM catalogo_analytics ca
        JOIN cataloghi c ON c.id = ca.catalogo_id
        WHERE c.azienda_id = ?
    ");
    $tot_scansioni->execute([$azienda_id]);
    $tot_scansioni = (int)$tot_scansioni->fetchColumn();
} else {
    // ---- RAMO "SUPERADMIN": totali globali, nessun filtro per azienda ----
    $tot_cataloghi = (int)$pdo->query("SELECT COUNT(*) FROM cataloghi")->fetchColumn();
    $tot_attivi    = (int)$pdo->query("SELECT COUNT(*) FROM cataloghi WHERE is_active = 1")->fetchColumn();
    $tot_scansioni = (int)$pdo->query("SELECT COUNT(*) FROM catalogo_analytics")->fetchColumn();
}

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/index.view.php';