<?php
/**
 * ==========================================================================
 * BASE.PHP — Sessioni, identità tecnica e protezioni di base
 * ==========================================================================
 *
 * Viene incluso da bootstrap.php SUBITO DOPO config.php. Si occupa di tutto
 * ciò che riguarda la "sessione sicura" dell'utente:
 *   - costante BASE_URL (radice del sito)
 *   - configurazione e avvio della sessione PHP
 *   - "fingerprint" per legare la sessione al dispositivo
 *   - funzioni anti-CSRF (token)
 *   - timeout di inattività (auto-logout)
 *   - isUrlSafe(): controllo URL tramite Google Safe Browsing
 *
 * NB: questo file non stampa nulla a schermo; predispone solo l'ambiente.
 */

// BASE_URL è l'indirizzo radice dell'applicazione. Tutti i link e i redirect
// si costruiscono concatenando questa costante (es. BASE_URL . "dashboard").
// Centralizzarla qui rende banale spostare l'app in produzione: si cambia
// un solo valore.
define("BASE_URL", "http://localhost/cat4u/");

// --------------------------------------------------------------------------
// CONFIGURAZIONE DEL COOKIE DI SESSIONE
// --------------------------------------------------------------------------
// Impostiamo gli attributi del cookie PRIMA di avviare la sessione, altrimenti
// non avrebbero effetto.
$sessionParams = [
    'lifetime' => 0,          // 0 = il cookie scade alla chiusura del browser.
    'path' => '/',            // Valido per l'intero dominio.
    'domain' => '',           // Vuoto = solo il dominio corrente (no sottodomini).
    'secure' => false,        // ATTENZIONE: in produzione (HTTPS) va messo true,
                              // così il cookie viaggia solo su connessione cifrata.
    'httponly' => true,       // Il cookie NON è leggibile da JavaScript:
                              // mitiga il furto di sessione via XSS.
    'samesite' => 'Strict'    // Il cookie non viene inviato su richieste
                              // cross-site: difesa aggiuntiva contro il CSRF.
];
session_set_cookie_params($sessionParams);

// Avviamo la sessione solo se non è già attiva, per evitare warning quando
// il file viene incluso più volte.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --------------------------------------------------------------------------
// FINGERPRINT DELLA SESSIONE (anti session hijacking)
// --------------------------------------------------------------------------
// Idea: calcoliamo un'"impronta" del client e la confrontiamo a ogni richiesta.
// Se cambia di colpo, è sospetto (un cookie di sessione rubato e usato altrove)
// e chiudiamo la sessione per sicurezza.
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Usiamo solo i primi 3 ottetti dell'IP (es. "192.168.1.x" → "192.168.1").
// Compromesso voluto: molti provider mobili cambiano l'ultima parte dell'IP
// di frequente; tagliarla evita falsi logout senza perdere troppa robustezza.
$ip_partial = substr($ip_address, 0, strrpos($ip_address, '.'));

// L'impronta unisce user-agent + IP parziale e ne fa un hash SHA-256.
// In sessione salviamo l'hash, non i dati grezzi.
$user_fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . $ip_partial);

if (!isset($_SESSION['fingerprint'])) {
    // Prima richiesta della sessione: memorizziamo l'impronta di riferimento.
    $_SESSION['fingerprint'] = $user_fingerprint;
} else {
    // Richieste successive: se l'impronta non combacia, distruggiamo tutto
    // e rimandiamo al login con un messaggio dedicato.
    if ($_SESSION['fingerprint'] !== $user_fingerprint) {
        session_unset();      // Svuota le variabili di sessione.
        session_destroy();    // Elimina la sessione lato server.
        header("Location: " . BASE_URL . "dashboard/login.php?error=sessione_non_sicura");
        exit();
    }
}

// --------------------------------------------------------------------------
// CSRF — Cross-Site Request Forgery
// --------------------------------------------------------------------------
// Il CSRF è un attacco in cui un sito malevolo induce il browser della vittima
// (già autenticata) a inviare richieste non volute alla nostra app.
// Difesa: ogni form include un token segreto che solo il nostro server conosce;
// le richieste senza token valido vengono rifiutate.

/**
 * Restituisce il token CSRF della sessione, generandolo al primo utilizzo.
 * Si inserisce nei form come campo nascosto:
 *   <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
 *
 * @return string Token esadecimale (32 byte casuali → 64 caratteri).
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        // random_bytes() è una sorgente di casualità crittograficamente sicura,
        // adatta a scopi di sicurezza (a differenza di rand()/mt_rand()).
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica il token CSRF inviato via POST contro quello in sessione.
 * Da chiamare all'inizio di OGNI azione che modifica dati (POST).
 * In caso di mancata corrispondenza interrompe l'esecuzione con un 403.
 */
function csrf_verify(): void {
    $token_ricevuto = $_POST['csrf_token'] ?? '';
    $token_sessione = $_SESSION['csrf_token'] ?? '';

    // hash_equals() confronta le stringhe in "tempo costante": impiega lo stesso
    // tempo a prescindere da dove differiscono. Evita i "timing attack", in cui
    // si indovina un token misurando i tempi di risposta.
    if (empty($token_sessione) || !hash_equals($token_sessione, $token_ricevuto)) {
        http_response_code(403); // 403 Forbidden
        die("Richiesta non valida. Possibile attacco CSRF.");
    }
}

// --------------------------------------------------------------------------
// TIMEOUT DI INATTIVITÀ (auto-logout)
// --------------------------------------------------------------------------
// Durata massima di inattività in secondi (1800 = 30 minuti).
$timeout_duration = 1800;

// Il controllo riguarda solo gli utenti autenticati.
if (isset($_SESSION['autorizzato'])) {
    if (isset($_SESSION['last_activity'])) {
        // Tempo trascorso dall'ultima azione registrata.
        $elapsed_time = time() - $_SESSION['last_activity'];
        if ($elapsed_time > $timeout_duration) {
            // Sessione scaduta: la chiudiamo e rimandiamo al login.
            session_unset();
            session_destroy();
            header("Location: " . BASE_URL . "dashboard/login.php?error=timeout");
            exit();
        }
    }
    // Ogni richiesta valida "resetta il cronometro" aggiornando l'orario.
    $_SESSION['last_activity'] = time();
}

// --------------------------------------------------------------------------
// isUrlSafe() — Verifica di un URL tramite Google Safe Browsing
// --------------------------------------------------------------------------
/**
 * Interroga l'API Google Safe Browsing per capire se un URL è segnalato come
 * pericoloso (malware, phishing, software indesiderato).
 * Qui viene usata, ad esempio, prima di mostrare il PDF di un catalogo.
 *
 * Scelta di progetto importante: in caso di dubbio "lascia passare"
 * (ritorna true). Cioè se manca la API key o l'API non risponde, NON blocchiamo
 * l'utente. È un compromesso disponibilità/sicurezza: l'app resta usabile anche
 * se il servizio esterno è giù.
 *
 * @param  string $url_to_check URL da verificare.
 * @return bool   true = considerato sicuro; false = segnalato come minaccia.
 */
function isUrlSafe($url_to_check) {
    // API key letta dall'ambiente. Se assente, non possiamo controllare:
    // restituiamo true (fail-open) per non bloccare il servizio.
    $apiKey = $_ENV['GOOGLE_SAFE_BROWSING_KEY'] ?? '';
    if (empty($apiKey)) return true;

    $apiUrl = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key=" . $apiKey;

    // Corpo della richiesta nel formato richiesto dall'API: descriviamo quali
    // tipi di minaccia ci interessano e quale URL controllare.
    $requestData = [
        "client" => ["clientId" => "cat4u", "clientVersion" => "1.0.0"],
        "threatInfo" => [
            "threatTypes"      => ["MALWARE", "SOCIAL_ENGINEERING", "UNWANTED_SOFTWARE"],
            "platformTypes"    => ["ANY_PLATFORM"],
            "threatEntryTypes" => ["URL"],
            "threatEntries"    => [["url" => $url_to_check]]
        ]
    ];

    // Eseguiamo la chiamata HTTP POST con cURL.
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);              // Ritorna la risposta come stringa invece di stamparla.
    curl_setopt($ch, CURLOPT_POST, true);                        // Metodo POST.
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData)); // Payload JSON.
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);                        // Max 3s totali: non blocchiamo l'utente.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);                 // Max 2s per la sola connessione.

    $response = curl_exec($ch);

    // Se la chiamata fallisce (timeout, rete giù…), applichiamo di nuovo la
    // logica fail-open: chiudiamo cURL e consideriamo l'URL sicuro.
    if ($response === false || curl_errno($ch)) {
        curl_close($ch);
        return true;
    }

    $result = json_decode($response, true);
    curl_close($ch);

    // L'API restituisce "matches" SOLO se trova minacce. Nessun match = sicuro.
    return empty($result['matches']);
}