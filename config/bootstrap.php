<?php
/**
 * BOOTSTRAP.PHP - Punto di ingresso unico per tutte le dipendenze.
 * Garantisce l'ordine corretto: prima .env e DB, poi sessioni e funzioni.
 */

// STEP 1: Carica .env, PDO ($pdo) e Monolog ($log)
require_once __DIR__ . '/config.php';

// STEP 2: Carica sessioni, fingerprint, CSRF e timeout
require_once __DIR__ . '/base.php';

// STEP 3: Funzioni di autenticazione e controllo ruoli
require_once __DIR__ . '/auth.php';
