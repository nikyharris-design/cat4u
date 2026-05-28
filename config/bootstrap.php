<?php
/**
 * BOOTSTRAP.PHP - Punto di ingresso unico per tutte le dipendenze.
 * Garantisce l'ordine corretto: prima .env e DB, poi sessioni e funzioni.
 * Sostituisce le doppie include sparse nei file del progetto.
 */

// STEP 1: Carica .env, PDO ($pdo) e Monolog ($log)
require_once __DIR__ . '/config.php';

// STEP 2: Ora che $_ENV è popolato, carica sessioni e funzioni
require_once __DIR__ . '/base.php';