<?php
/**
 * ==========================================================================
 * ROUTER.PHP — Disambigua il secondo segmento dell'URL   [VERSIONE CORRETTA]
 * ==========================================================================
 *
 * Richiamato da index.php per gli URL pubblici a due segmenti:
 *     /nome-azienda/qualcosa
 * "qualcosa" può essere lo slug di un CATALOGO o di un GENERE. Qui interroghiamo
 * il DB per deciderlo.
 *
 * CORREZIONE: prima si leggevano $_GET['azienda'] / ['catalogo']; ora si usano
 * $_GET['a'] / ['c'], coerenti con index.php e con le pagine incluse
 * (catalogo.php e libreria.php leggono a/c/g). Senza questo allineamento, il
 * catalogo veniva incluso ma trovava i suoi parametri vuoti → 404.
 *
 * Strategia: proviamo PRIMA come catalogo; se non esiste, ricadiamo sulla
 * libreria (che userà $_GET['g'] come filtro di genere, già impostato da index.php).
 *
 * $pdo è disponibile perché bootstrap.php è già stato incluso da index.php.
 */

$azienda_slug = $_GET['a'] ?? ''; // slug azienda
$secondo      = $_GET['c'] ?? ''; // secondo segmento (ipotesi catalogo)

// Esiste un catalogo attivo con questo slug, per questa azienda?
$stmt = $pdo->prepare("
    SELECT c.id FROM cataloghi c
    JOIN aziende a ON a.id = c.azienda_id
    WHERE a.slug = ? AND c.slug = ? AND c.is_active = 1
    LIMIT 1
");
$stmt->execute([$azienda_slug, $secondo]);

if ($stmt->fetch()) {
    // È un catalogo → pagina del singolo catalogo (legge $_GET['a'] e ['c']).
    require __DIR__ . '/catalogo.php';
} else {
    // Non è un catalogo → libreria, eventualmente filtrata per genere
    // (libreria.php legge $_GET['a'] e $_GET['g'], entrambi già impostati).
    require __DIR__ . '/libreria.php';
}