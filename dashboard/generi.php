<?php
/**
 * ==========================================================================
 * GENERI.PHP — Controller della pagina "Gestione Generi"
 * ==========================================================================
 *
 * CRUD ridotto (Crea / Elenca / Elimina) dei "generi" con cui si raggruppano
 * i cataloghi di un'azienda (es. Alimentari, Abbigliamento…).
 *
 * Questo file è il CONTROLLER: si occupa solo della logica (accesso, gestione
 * dei POST, query). La presentazione (HTML) è in views/generi.view.php, inclusa
 * in fondo. Le variabili preparate qui ($user, $error, $success, $generi) sono
 * viste dalla vista perché un file incluso eredita lo scope di chi lo include.
 *
 * Tutte le azioni che modificano dati (POST) sono protette da CSRF e usano
 * prepared statement. La funzione make_slug vive in config/helpers.php.
 */

require_once __DIR__ . '/../config/bootstrap.php';

// Accesso consentito ad admin e user (il superadmin non gestisce i generi).
require_role('admin', 'user');
require_password_changed();

$user = current_user();

// Admin e user sono vincolati alla propria azienda.
$azienda_id = (int)$user['azienda_id'];

$error   = '';
$success = '';

// --------------------------------------------------------------------------
// AZIONE: ELIMINA un genere
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'elimina') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);

    // Controllo di proprietà: eliminiamo solo se il genere appartiene davvero
    // all'azienda corrente. Impedisce di cancellare generi di altre aziende
    // manomettendo l'id nel form.
    $stmt = $pdo->prepare("SELECT id FROM generi WHERE id = ? AND azienda_id = ?");
    $stmt->execute([$id, $azienda_id]);
    if ($stmt->fetch()) {
        try {
            $pdo->prepare("DELETE FROM generi WHERE id = ?")->execute([$id]);
            $success = "Genere eliminato.";
        } catch (PDOException $e) {
            // La foreign key cataloghi.genere_id (ON DELETE RESTRICT) impedisce
            // di cancellare un genere ancora usato da uno o più cataloghi.
            $error = "Non puoi eliminare un genere che contiene cataloghi. Sposta o elimina prima i cataloghi associati.";
        }
    } else {
        $error = "Operazione non consentita.";
    }
}

// --------------------------------------------------------------------------
// AZIONE: CREA un genere
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crea') {
    csrf_verify();
    $nome_genere = trim($_POST['nome_genere'] ?? '');

    if (empty($nome_genere)) {
        $error = "Il nome del genere è obbligatorio.";
    } else {
        // Generazione slug univoco: se lo slug base è già usato, prova
        // base-slug-1, base-slug-2, ecc. finché non ne trova uno libero.
        $base_slug = make_slug($nome_genere);
        $slug = $base_slug;
        $i = 1;
        while (true) {
            $stmt = $pdo->prepare("SELECT id FROM generi WHERE slug = ?");
            $stmt->execute([$slug]);
            if (!$stmt->fetch()) break;
            $slug = $base_slug . '-' . $i++;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO generi (azienda_id, nome_genere, slug) VALUES (?, ?, ?)");
            $stmt->execute([$azienda_id, $nome_genere, $slug]);
            $success = "Genere creato.";
        } catch (PDOException $e) {
            // Rete di sicurezza in caso di violazione di vincoli del DB.
            $error = "Errore durante la creazione del genere.";
        }
    }
}

// Elenco dei generi dell'azienda corrente, da mostrare in tabella.
$generi = $pdo->prepare("SELECT * FROM generi WHERE azienda_id = ? ORDER BY nome_genere ASC");
$generi->execute([$azienda_id]);
$generi = $generi->fetchAll();

// --------------------------------------------------------------------------
// PRESENTAZIONE: deleghiamo tutto l'HTML alla vista.
// --------------------------------------------------------------------------
require __DIR__ . '/../views/generi.view.php';