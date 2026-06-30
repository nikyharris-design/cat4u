<?php
/**
 * ==========================================================================
 * AUTH.PHP — Controllo accessi (autenticazione e autorizzazione)
 * ==========================================================================
 *
 * Terzo e ultimo file incluso da bootstrap.php. Contiene le funzioni-"guardia"
 * che le pagine chiamano all'inizio per stabilire CHI sta accedendo e SE ha i
 * permessi giusti.
 *
 * Distinzione importante:
 *   - AUTENTICAZIONE = "chi sei?"      → require_login()
 *   - AUTORIZZAZIONE = "cosa puoi fare?" → require_role()
 *
 * Tutte queste funzioni si appoggiano alle variabili di $_SESSION valorizzate
 * al momento del login (vedi dashboard/login.php).
 *
 * Ordine tipico di chiamata in cima a una pagina protetta:
 *   require_role('superadmin', 'admin');  // o require_login()
 *   require_password_changed();
 */

/**
 * Richiede che l'utente sia autenticato.
 * Se non lo è, lo reindirizza alla pagina di login e interrompe lo script.
 *
 * Il flag $_SESSION['autorizzato'] viene impostato a true solo dopo un login
 * andato a buon fine. empty() copre sia il caso "non impostato" sia "false".
 */
function require_login(): void {
    if (empty($_SESSION['autorizzato'])) {
        header("Location: " . BASE_URL . "dashboard/login.php");
        exit(); // exit() è fondamentale: senza, lo script proseguirebbe
                // eseguendo codice riservato nonostante il redirect.
    }
}

/**
 * Richiede che l'utente sia autenticato E abbia uno dei ruoli ammessi.
 *
 * Usa "...$roles" (argomenti variabili): si possono passare uno o più ruoli,
 * es. require_role('superadmin') oppure require_role('superadmin', 'admin').
 *
 * @param string ...$roles Elenco dei ruoli autorizzati per la pagina.
 */
function require_role(string ...$roles): void {
    // Prima l'autenticazione: se non sei loggato non ha senso valutare il ruolo.
    require_login();

    // in_array con terzo parametro true = confronto "strict" (anche di tipo):
    // evita confronti ambigui tra stringhe e altri valori.
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        // Sei loggato ma il tuo ruolo non rientra tra quelli ammessi:
        // 403 Forbidden e stop. (Diverso dal 401/redirect del non-loggato.)
       // Loggato ma senza il ruolo richiesto: 403 via eccezione, gestita
        // centralmente dall'handler invece che con un die() grezzo.
        throw new \App\Exceptions\ForbiddenException(
            "Non hai i permessi per accedere a questa pagina."
        );
    }
}

/**
 * Forza il cambio password al primo accesso.
 *
 * Quando un utente viene creato riceve una password temporanea e il flag
 * must_change_password = 1. Finché non la cambia, questa funzione lo "blocca"
 * sulla pagina di cambio password, impedendogli di usare il resto dell'app.
 *
 * Va chiamata DOPO require_login()/require_role(), non sulla pagina di cambio
 * password stessa (altrimenti creerebbe un loop di redirect).
 */
function require_password_changed(): void {
    if (!empty($_SESSION['must_change_password'])) {
        header("Location: " . BASE_URL . "dashboard/change-password.php");
        exit();
    }
}

/**
 * Restituisce i dati dell'utente corrente come array, leggendoli dalla sessione.
 *
 * Comodità: invece di scrivere ovunque $_SESSION['user_role'], le pagine usano
 * $user = current_user(); e poi $user['role']. Codice più leggibile e un unico
 * punto da modificare se cambia la struttura dei dati utente.
 *
 * L'operatore ?? fornisce un valore di default se la chiave non esiste, così
 * l'array restituito ha sempre la stessa forma.
 *
 * @return array Dati dell'utente (id, name, email, role, azienda_id).
 */
function current_user(): array {
    return [
        'id'         => $_SESSION['user_id']    ?? null,
        'name'       => $_SESSION['user_name']  ?? '',
        'email'      => $_SESSION['user_email'] ?? '',
        'role'       => $_SESSION['user_role']  ?? '',
        'azienda_id' => $_SESSION['azienda_id'] ?? null,
    ];
}