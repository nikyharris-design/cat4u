<?php
/**
 * ==========================================================================
 * CATALOGHI.PHP — Controller della pagina "Cataloghi"
 * ==========================================================================
 *
 * "Vigile del traffico": legge la richiesta, chiama CatalogoService per il
 * lavoro vero (validazione, upload PDF, slug, QR, query) e prepara i dati per
 * la vista. Nessuna logica di business o SQL qui dentro.
 *
 * Tutte le operazioni sono vincolate all'azienda dell'utente: il service
 * riceve $azienda_id e lo usa nei controlli di proprietà.
 *
 * Variabili passate alla vista: $error, $errors, $success, $generi,
 * $cataloghi, $modifica, $scadenza_value.
 */

require_once __DIR__ . '/../config/bootstrap.php';

use App\Services\CatalogoService;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;

require_role('superadmin', 'admin', 'user');
require_password_changed();

$user       = current_user();
$azienda_id = (int)$user['azienda_id'];
$is_superadmin = $user['role'] === 'superadmin';

// Il service riceve $pdo (dal bootstrap) e il percorso assoluto di uploads/.
$service = new CatalogoService($pdo, __DIR__ . '/../uploads');

$error   = '';
$errors  = [];
$success = '';

// --------------------------------------------------------------------------
// POST TRONCATO: upload oltre post_max_size
// Se la richiesta è POST ma $_POST/$_FILES sono vuoti pur essendoci un corpo,
// PHP ha scartato tutto perché troppo grande. È una condizione della RICHIESTA
// (non logica di business), quindi resta qui nel controller.
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST) && empty($_FILES)
    && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = "File troppo grande: l'upload supera il limite del server. Il PDF non può superare 100MB.";
}

// --------------------------------------------------------------------------
// AZIONI POST
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['attiva', 'disattiva'], true)) {
        csrf_verify();
        $service->impostaStato((int)($_POST['id'] ?? 0), $azienda_id, $action === 'attiva');
        $success = $action === 'attiva' ? "Catalogo attivato." : "Catalogo disattivato.";

    } elseif ($action === 'elimina') {
        csrf_verify();
        $service->elimina((int)($_POST['id'] ?? 0), $azienda_id);
        $success = "Catalogo eliminato.";

    } elseif ($action === 'carica') {
        csrf_verify();
        try {
            $res = $service->crea($_POST, $_FILES['pdf'] ?? [], $azienda_id, BASE_URL);
            $success = "Catalogo pubblicato. URL: <strong>" . htmlspecialchars($res['url']) . "</strong>";
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
        } catch (RuntimeException $e) {
            $error = "Errore durante il salvataggio del PDF. Riprova.";
        } catch (PDOException $e) {
            $log->error('Errore DB creazione catalogo', ['error' => $e->getMessage()]);
            $error = "Si è verificato un errore durante il salvataggio. Riprova.";
        }

    } elseif ($action === 'modifica') {
        csrf_verify();
        try {
            $service->modifica((int)($_POST['id'] ?? 0), $_POST, $_FILES['pdf'] ?? [], $azienda_id);
            $success = "Catalogo aggiornato.";
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
        } catch (NotFoundException $e) {
            $error = $e->getMessage();
        } catch (RuntimeException $e) {
            $error = "Errore durante il salvataggio del PDF. Riprova.";
        } catch (PDOException $e) {
            $log->error('Errore DB modifica catalogo', ['error' => $e->getMessage()]);
            $error = "Si è verificato un errore durante il salvataggio. Riprova.";
        }
    }
}

// --------------------------------------------------------------------------
// DATI PER LA VISTA
// --------------------------------------------------------------------------
if ($is_superadmin) {
    // Superadmin: TUTTI i cataloghi, con filtro opzionale per azienda (0 = tutte).
    $filtro_azienda = (int)($_GET['az'] ?? 0);
    $aziende_list   = $service->aziende();
    $cataloghi      = $service->cataloghiTutti($filtro_azienda);

    // Il superadmin non carica/modifica da qui: form disattivato.
    $generi         = [];
    $modifica       = null;
    $scadenza_value = '';
} else {
    $generi    = $service->generi($azienda_id);
    $cataloghi = $service->cataloghi($azienda_id);

    $modifica = isset($_GET['modifica'])
        ? $service->trova((int)$_GET['modifica'], $azienda_id)
        : null;

    $scadenza_value = '';
    if ($modifica && !empty($modifica['data_scadenza'])) {
        $scadenza_value = date('Y-m-d', strtotime((string)$modifica['data_scadenza']));
    } elseif (!empty($_POST['data_scadenza'])) {
        $scadenza_value = (string)$_POST['data_scadenza'];
    }
}

// --------------------------------------------------------------------------
// PRESENTAZIONE
// --------------------------------------------------------------------------
require __DIR__ . '/../views/cataloghi.view.php';