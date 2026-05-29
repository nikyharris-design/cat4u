<?php
/**
 * ==========================================================================
 * LOGOUT.PHP — Chiusura della sessione
 * ==========================================================================
 *
 * Termina la sessione dell'utente e lo riporta alla pagina di login.
 *
 * L'ORDINE delle operazioni è importante:
 *   1. PRIMA leggiamo i dati che ci servono (qui: l'id per il log)…
 *   2. …POI svuotiamo e distruggiamo la sessione.
 * Invertire l'ordine significherebbe loggare un user_id ormai cancellato.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Registriamo l'evento di logout MENTRE i dati di sessione esistono ancora.
// L'operatore ?? evita un errore se per qualche motivo user_id non c'è.
$log->info('Logout effettuato', ['user_id' => $_SESSION['user_id'] ?? null]);

// session_unset(): svuota tutte le variabili $_SESSION (le azzera).
session_unset();

// session_destroy(): elimina la sessione vera e propria lato server.
// Le due chiamate insieme garantiscono una pulizia completa.
session_destroy();

// Riportiamo l'utente al login. exit() impedisce l'esecuzione di altro codice
// dopo il redirect.
header("Location: " . BASE_URL . "dashboard/login.php");
exit();