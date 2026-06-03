<?php
/**
 * ==========================================================================
 * BOOTSTRAP.PHP — Punto di ingresso unico delle dipendenze
 * ==========================================================================
 *
 * È il PRIMO file che ogni pagina include:
 *   require_once __DIR__ . '/../config/bootstrap.php';
 *
 * Il suo unico compito è caricare gli altri file di configurazione NELL'ORDINE
 * CORRETTO. L'ordine non è casuale: ogni step dipende da ciò che lo precede.
 *
 * Perché un file di bootstrap?
 *   - Le pagine includono UNA sola riga invece di tre: meno duplicazione.
 *   - L'ordine di caricamento è deciso in un solo posto: se cambia, si tocca
 *     solo qui e non decine di file.
 *
 * require_once (anziché require) garantisce che ciascun file venga incluso
 * una sola volta, anche se richiamato più volte: evita ridefinizioni di
 * funzioni e costanti (che genererebbero errori fatali).
 */

// STEP 1 — Librerie, .env, connessione DB ($pdo) e logger ($log).
// Deve venire per primo: gli step successivi possono aver bisogno del DB,
// e config.php valida anche le variabili d'ambiente indispensabili.
require_once __DIR__ . '/config.php';

// STEP 2 — Sessione, BASE_URL, fingerprint, CSRF e timeout.
// Richiede che l'ambiente (.env) sia già caricato; definisce BASE_URL e avvia
// la sessione, su cui si basa lo step 3.
require_once __DIR__ . '/base.php';

// STEP 3 — Funzioni di autenticazione e controllo ruoli.
// Per ultimo, perché le sue funzioni leggono $_SESSION (avviata nello step 2)
// e usano BASE_URL (definita nello step 2) per i redirect.
require_once __DIR__ . '/auth.php';

// STEP 4: Funzioni di rate-limiting
require_once __DIR__ . '/ratelimit.php';