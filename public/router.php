<?php
/**
 * ==========================================================================
 * ROUTER.PHP — Disambigua il secondo segmento dell'URL pubblico
 * ==========================================================================
 *
 * Richiamato da index.php quando l'URL pulito ha DUE segmenti:
 *     /nome-azienda/qualcosa
 * Quel "qualcosa" può essere lo slug di un CATALOGO oppure di un GENERE:
 * dall'URL non si capisce. Questo file interroga il DB per deciderlo.
 *
 * index.php ha già preparato in $_GET:
 *   azienda  → slug dell'azienda
 *   catalogo → il secondo segmento (ipotesi "catalogo")
 *   genere   → lo stesso secondo segmento (ipotesi "genere")
 *
 * Strategia: proviamo PRIMA a interpretarlo come catalogo. Se esiste un
 * catalogo con quello slug, mostriamo la pagina del catalogo; altrimenti
 * ricadiamo sulla libreria (che gestirà il caso "genere" o mostrerà tutto).
 *
 * NB: questo file è incluso DOPO bootstrap.php (da index.php), quindi $pdo è
 * già disponibile.
 */

// Leggiamo i valori preparati da index.php.
$azienda_slug = $_GET['azienda'] ?? '';
$secondo      = $_GET['catalogo'] ?? '';

// Tentiamo l'interpretazione "catalogo": esiste un catalogo attivo con questo
// slug, per questa azienda? La JOIN lega catalogo e azienda tramite gli slug.
$stmt = $pdo->prepare("
    SELECT c.id FROM cataloghi c
    JOIN aziende a ON a.id = c.azienda_id
    WHERE a.slug = ? AND c.slug = ? AND c.is_active = 1
    LIMIT 1
");
$stmt->execute([$azienda_slug, $secondo]);

if ($stmt->fetch()) {
    // Trovato: è un catalogo → mostriamo la pagina del singolo catalogo.
    // (catalogo.php rileggerà gli slug da $_GET['a'] / $_GET['c']… vedi nota sotto.)
    require __DIR__ . '/catalogo.php';
} else {
    // Nessun catalogo con quello slug → trattiamo il segmento come genere
    // (o caso generico) e deleghiamo alla libreria, che applicherà il filtro.
    require __DIR__ . '/libreria.php';
}