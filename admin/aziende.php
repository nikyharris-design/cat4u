<?php
/**
 * ==========================================================================
 * AZIENDE.PHP — Controller "Gestione Aziende" (solo superadmin)
 * ==========================================================================
 *
 * "Vigile del traffico": legge la richiesta, chiama AziendaService per il
 * lavoro vero (validazione, slug, QR, query) e prepara i messaggi per la vista.
 * Nessuna logica di business o SQL qui dentro.
 *
 * UN SOLO form serve sia a "crea" sia a "modifica": il campo nascosto 'action'
 * distingue i due casi, e $modifica decide cosa precompilare.
 *
 * Variabili passate alla vista: $error (stringa), $errors (array di errori di
 * validazione), $success, $aziende, $modifica.
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Services\AziendaService;
use App\Exceptions\ValidationException;

// Guardia stretta: SOLO il superadmin.
require_role('superadmin');
require_password_changed();

// Il service riceve $pdo (dal bootstrap) e il percorso assoluto di uploads/.
$service = new AziendaService($pdo, __DIR__ . '/../uploads');

$error   = '';
$errors  = [];   // errori di validazione (campo => messaggio) per la vista
$success = '';

// --------------------------------------------------------------------------
// AZIONE: ELIMINA
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $service->elimina((int)($_POST['id'] ?? 0));
    $success = "Azienda eliminata.";
}

// --------------------------------------------------------------------------
// AZIONE: CREA o MODIFICA
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['crea', 'modifica'], true)) {
    csrf_verify();
    $action = $_POST['action'];

    try {
        if ($action === 'crea') {
            $service->crea($_POST, BASE_URL);
            $success = "Azienda creata.";
        } else {
            $service->modifica((int)($_POST['id'] ?? 0), $_POST);
            $success = "Azienda aggiornata.";
        }
    } catch (ValidationException $e) {
        // Dati non validi: passiamo TUTTI gli errori alla vista, che li elenca.
        // I campi del form si ri-popolano da $_POST (lo fa già la vista).
        $errors = $e->getErrors();
    } catch (PDOException $e) {
        // Imprevisto a livello DB (es. vincolo non gestito): messaggio generico.
        // Il dettaglio reale è già finito nel log tramite l'error handler? No:
        // qui lo catturiamo noi, quindi logghiamo a mano l'evento per il debug.
        $log->error('Errore DB in gestione aziende', ['error' => $e->getMessage()]);
        $error = "Si è verificato un errore durante il salvataggio. Riprova.";
    }
}

// --------------------------------------------------------------------------
// DATI PER LA VISTA
// --------------------------------------------------------------------------
$aziende = $service->tutte();

// ?modifica=ID → carica l'azienda da precompilare nel form (null se assente).
$modifica = isset($_GET['modifica'])
    ? $service->trova((int)$_GET['modifica'])
    : null;

// --------------------------------------------------------------------------
// PRESENTAZIONE
// --------------------------------------------------------------------------
require __DIR__ . '/../views/aziende.view.php';