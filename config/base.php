<?php

/*
 * ==========================================================================
 * CONFIG.PHP - File di configurazione globale del progetto
 * ==========================================================================
 *
 * CONCETTI DIDATTICI TRATTATI:
 * 1. Le costanti PHP con define()
 * 2. Le sessioni PHP con session_start()
 * 3. Il concetto di "file di configurazione" centralizzato
 *
 * PERCHÉ QUESTO FILE?
 * Contiene le impostazioni usate in TUTTO il progetto.
 * Viene incluso come PRIMO file in ogni pagina.
 * Se un giorno il progetto si sposta su un altro server, basta
 * modificare BASE_URL qui e tutto il sito funzionerà correttamente.
 *
 * ATTENZIONE: session_start() DEVE essere chiamato PRIMA di qualsiasi
 * output HTML (anche uno spazio vuoto prima di <?php causa errori!).
 * Ecco perché questo file inizia subito con <?php senza spazi.
 * ==========================================================================
 */

/*
 * define() crea una COSTANTE: un valore che non può essere modificato.
 * A differenza delle variabili ($nome), le costanti:
 * - NON hanno il $ davanti
 * - Si scrivono per convenzione in MAIUSCOLO
 * - Una volta definite, NON possono cambiare valore
 *
 * BASE_URL rappresenta l'indirizzo base del nostro progetto.
 * Lo usiamo per costruire tutti i link in modo che funzionino
 * indipendentemente dalla posizione del file che li usa.
 */
define("BASE_URL", "http://localhost:8080/"); // percorso assoluto

// Configurazione dei parametri del cookie di sessione per aumentare la sicurezza
$sessionParams = [
    'lifetime' => 0,             // Il cookie scade quando il browser si chiude
    'path' => '/',                // Valido per tutto il dominio
    'domain' => '',              // Lasciare vuoto per il dominio corrente
    'secure' => false,           // CAMBIARE IN 'true' quando passerai a HTTPS (Cloud/Produzione)
    'httponly' => true,          // Impedisce a JavaScript di accedere al cookie (Protezione XSS)
    'samesite' => 'Strict'       // Impedisce l'invio del cookie in richieste cross-site (Protezione CSRF)
];

// Applica i parametri definiti sopra prima di iniziare la sessione
session_set_cookie_params($sessionParams);

/*
 * session_start() inizializza il meccanismo delle sessioni.
 * Una SESSIONE permette di conservare dati dell'utente (es. login)
 * tra una pagina e l'altra. Senza sessioni, ogni richiesta HTTP
 * sarebbe completamente indipendente (il server "dimentica" tutto).
 *
 * Quando chiamiamo session_start():
 * 1. PHP crea (o riprende) un file temporaneo sul server
 * 2. Assegna un ID univoco (PHPSESSID) salvato in un cookie del browser
 * 3. Rende disponibile l'array $_SESSION per leggere/scrivere dati
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Perché IP parziale e non IP completo?
// - Alcuni utenti cambiano IP legittimamente durante la sessione (es. passaggio
//   da WiFi a 4G, CGNAT, VPN che ruota).
// - Escludendo l'ultimo ottetto (es. 192.168.1.x → usiamo 192.168.1)
//   accettiamo cambi di IP nella stessa sottorete, bloccando comunque
//   attaccanti da reti diverse.
// - L'hash SHA-256 rende il fingerprint non reversibile: nessun dato
//   sensibile viene salvato in chiaro nella sessione.
 
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
 
// Prendiamo tutto tranne l'ultimo ottetto: "192.168.1.45" → "192.168.1"
$ip_partial = substr($ip_address, 0, strrpos($ip_address, '.'));
 
$user_fingerprint = hash('sha256',
    ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . $ip_partial
);


// Controllo per prevenire il Session Hijacking (Furto di sessione)
if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = $user_fingerprint;
} else {
    if ($_SESSION['fingerprint'] !== $user_fingerprint) {
        session_unset();
        session_destroy();
        header("Location: ../dashboard/login.php?error=sessione_non_sicura");
        exit();
    }
}
/**
 * Genera (o recupera) il token CSRF della sessione corrente.
 * Il token viene creato una volta sola e riutilizzato per tutta la sessione.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica che il token CSRF ricevuto via POST corrisponda a quello in sessione.
 * Se non corrisponde, blocca l'esecuzione immediatamente.
 */
function csrf_verify(): void {
    $token_ricevuto = $_POST['csrf_token'] ?? '';
    $token_sessione = $_SESSION['csrf_token'] ?? '';

    // hash_equals è resistente ai timing attack, a differenza di ===
    if (empty($token_sessione) || !hash_equals($token_sessione, $token_ricevuto)) {
        http_response_code(403);
        die("Richiesta non valida. Possibile attacco CSRF.");
    }
}

// Definizione del tempo massimo di inattività (30 minuti in secondi)
$timeout_duration = 1800; 

// Gestione del timeout automatico per utenti autorizzati (Admin)
if (isset($_SESSION['autorizzato'])) {
    // 1. Controlliamo se esiste già un timestamp dell'ultima attività
    if (isset($_SESSION['last_activity'])) {
        // Calcoliamo quanto tempo è passato dall'ultima interazione
        $elapsed_time = time() - $_SESSION['last_activity'];

        if ($elapsed_time > $timeout_duration) {
            // Tempo scaduto! Pulizia totale e reindirizzamento al login
            session_unset();
            session_destroy();
            header("Location: ../dashboard/login.php?error=timeout");
            exit();
        }
    }

    // 2. Aggiorniamo il timestamp dell'attività (si resetta ad ogni caricamento di pagina)
    $_SESSION['last_activity'] = time();
}

// --- NUOVA FUNZIONE: GOOGLE SAFE BROWSING ---
// Interroga le API di Google per verificare se un URL è malevolo
function isUrlSafe($url_to_check) {
    // Recupera la chiave API dal file .env caricato precedentemente in config.php
    $apiKey = $_ENV['GOOGLE_SAFE_BROWSING_KEY'] ?? ''; 
    
    // Se la chiave non è configurata, lo script prosegue per evitare blocchi totali
    if (empty($apiKey)) return true; 

    // Endpoint di Google Safe Browsing API (v4)
    $apiUrl = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key=" . $apiKey;

    // Preparazione del corpo della richiesta JSON secondo le specifiche di Google
    $requestData = [
        "client" => ["clientId" => "qr-magic", "clientVersion" => "1.0.0"],
        "threatInfo" => [
            "threatTypes"      => ["MALWARE", "SOCIAL_ENGINEERING", "UNWANTED_SOFTWARE"],
            "platformTypes"    => ["ANY_PLATFORM"],
            "threatEntryTypes" => ["URL"],
            "threatEntries"    => [["url" => $url_to_check]]
        ]
    ];

    // Inizializzazione della libreria cURL per la chiamata HTTP POST
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Restituisce il risultato come stringa
    curl_setopt($ch, CURLOPT_POST, true);           // Imposta il metodo come POST
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData)); // Invia i dati in formato JSON
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); // Specifica l'header JSON
    // Timeout: non aspettiamo più di 3 secondi totali o 2 per la connessione.
    // Senza questo, una risposta lenta di Google blocca l'intera pagina dell'utente.
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    

    
    // Esecuzione della chiamata e decodifica della risposta JSON
    $response = curl_exec($ch);
    $result = json_decode($response, true);
        if ($response === false || curl_errno($ch)) {
        // Opzionale: logga l'errore per diagnostica
        // $log->warning('Google Safe Browsing non raggiungibile', ['error' => curl_error($ch)]);
        curl_close($ch); // Chiudiamo la risorsa anche in caso di errore
        return true;     // Fail-open: preferiamo non bloccare l'utente se Google è down
    }
    // Chiudiamo sempre la risorsa cURL dopo l'uso per liberare memoria.
    // Senza curl_close(), ogni chiamata alla funzione lascia aperta una risorsa
    // — su pagine con molte chiamate diventa un memory leak reale.
    curl_close($ch);

    
    // Se l'array 'matches' restituito da Google è vuoto, l'URL è considerato sicuro
    return empty($result['matches']);
}
?>