<?php
/**
 * ==========================================================================
 * BASE.PHP — Sessioni, identità tecnica e protezioni di base
 * ==========================================================================
 * Incluso da bootstrap.php subito dopo config.php (quindi $_ENV è già caricato).
 * Si occupa di: BASE_URL, sessione sicura, fingerprint, CSRF, timeout, Safe Browsing.
 */

// --------------------------------------------------------------------------
// BASE_URL — radice dell'applicazione, ora letta dal .env
// --------------------------------------------------------------------------
// Prima era "incollata" nel codice. Ora la leggiamo dalla variabile APP_URL del
// .env: per andare in produzione basta cambiare quel valore, non il codice.
// Fallback prudente a localhost se la variabile manca.
// rtrim + '/' garantisce SEMPRE esattamente uno slash finale, perché tutto il
// codice concatena BASE_URL . "dashboard" ecc. (senza slash si romperebbero i link).
$app_url = $_ENV['APP_URL'] ?? 'http://localhost/cat4u/';
define("BASE_URL", rtrim($app_url, '/') . '/');

// --------------------------------------------------------------------------
// Rilevazione HTTPS (per il flag "secure" del cookie)
// --------------------------------------------------------------------------
// true solo se la connessione è cifrata. Copriamo tre casi: HTTPS diretto,
// porta 443, oppure header X-Forwarded-Proto impostato da un eventuale proxy.
// In locale (http) resta false → il cookie funziona; in produzione (https)
// diventa true → il cookie viaggia solo su connessione cifrata.
$is_https =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// --------------------------------------------------------------------------
// CONFIGURAZIONE DEL COOKIE DI SESSIONE
// --------------------------------------------------------------------------
$sessionParams = [
    'lifetime' => 0,            // scade alla chiusura del browser
    'path' => '/',
    'domain' => '',
    'secure' => $is_https,      // automatico: HTTPS → true, HTTP locale → false
    'httponly' => true,         // non leggibile da JavaScript (mitiga XSS)
    'samesite' => 'Strict'      // non inviato su richieste cross-site (anti-CSRF)
];
session_set_cookie_params($sessionParams);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// --------------------------------------------------------------------------
// HEADER DI SICUREZZA (validi per tutte le pagine, via bootstrap)
// --------------------------------------------------------------------------
// Vanno inviati prima di qualsiasi output. Si applicano a ogni pagina perché
// base.php è incluso da bootstrap.php.

// Impedisce il "MIME sniffing": il browser rispetta il Content-Type dichiarato
// e non prova a indovinare. Rilevante perché serviamo PDF caricati da utenti.
header('X-Content-Type-Options: nosniff');

// Anti-clickjacking: nessun sito esterno può incorniciare le nostre pagine.
// NB: i nostri iframe di catalogo.php sono same-origin, quindi non si rompono.
header('X-Frame-Options: SAMEORIGIN');

// Limita le informazioni inviate nel Referer verso siti esterni.
header('Referrer-Policy: strict-origin-when-cross-origin');

// HSTS: forza HTTPS sulle visite successive. Lo inviamo SOLO se siamo già in
// HTTPS, altrimenti in locale (http) bloccheremmo l'accesso al sito.
if ($is_https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
// --------------------------------------------------------------------------
// FINGERPRINT DELLA SESSIONE (anti session hijacking)
// --------------------------------------------------------------------------
$ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// Solo i primi 3 ottetti dell'IP: compromesso per non sloggare gli utenti su
// rete mobile (che cambiano spesso l'ultima parte dell'IP).
$ip_partial = substr($ip_address, 0, strrpos($ip_address, '.'));
$user_fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . $ip_partial);

if (!isset($_SESSION['fingerprint'])) {
    $_SESSION['fingerprint'] = $user_fingerprint;
} else {
    if ($_SESSION['fingerprint'] !== $user_fingerprint) {
        session_unset();
        session_destroy();
        header("Location: " . BASE_URL . "dashboard/login.php?error=sessione_non_sicura");
        exit();
    }
}

// --------------------------------------------------------------------------
// CSRF — Cross-Site Request Forgery
// --------------------------------------------------------------------------
/**
 * Restituisce il token CSRF della sessione, generandolo al primo utilizzo.
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica il token CSRF inviato via POST contro quello in sessione.
 * hash_equals confronta in tempo costante (evita i timing attack).
 */
function csrf_verify(): void {
    $token_ricevuto = $_POST['csrf_token'] ?? '';
    $token_sessione = $_SESSION['csrf_token'] ?? '';
    if (empty($token_sessione) || !hash_equals($token_sessione, $token_ricevuto)) {
        http_response_code(403);
        die("Richiesta non valida. Possibile attacco CSRF.");
    }
}

// --------------------------------------------------------------------------
// not_found() — pagina 404 centralizzata
// --------------------------------------------------------------------------
/**
 * Imposta lo stato HTTP 404 e mostra la pagina 404 stilizzata, poi termina.
 * Sostituisce i vari die("...") con testo grezzo: tutte le pagine possono
 * chiamare not_found() per una "pagina non trovata" coerente.
 *
 * @param string $messaggio testo mostrato all'utente (personalizzabile per contesto)
 */
function not_found(string $messaggio = "La pagina che cerchi non esiste o è stata rimossa."): void {
    http_response_code(404);
    // Reso disponibile alla view 404.php.
    $messaggio_404 = $messaggio;
    require __DIR__ . '/../public/404.php';
    exit();
}

// --------------------------------------------------------------------------
// TIMEOUT DI INATTIVITÀ (auto-logout)
// --------------------------------------------------------------------------
$timeout_duration = 1800; // 30 minuti

if (isset($_SESSION['autorizzato'])) {

    // --- INVALIDAZIONE SESSIONE SU CAMBIO PASSWORD ALTROVE ---
    // Se nel DB password_changed_at è più recente del valore registrato al
    // login di QUESTA sessione, la password è stata cambiata/reimpostata da
    // un'altra parte: questa sessione non è più valida.
    if (!empty($_SESSION['user_id'])) {
        $stmtPwd = $pdo->prepare("SELECT password_changed_at FROM users WHERE id = ? LIMIT 1");
        $stmtPwd->execute([(int)$_SESSION['user_id']]);
        $db_pwd_changed = $stmtPwd->fetchColumn();

        // Utente sparito dal DB → fuori.
        if ($db_pwd_changed === false) {
            session_unset();
            session_destroy();
            header("Location: " . BASE_URL . "dashboard/login.php");
            exit();
        }

        $sess_pwd_changed = $_SESSION['pwd_changed_at'] ?? null;
        // strtotime(null/'') → false; lo trattiamo come 0 (nessun cambio noto).
        $db_ts   = $db_pwd_changed ? strtotime($db_pwd_changed) : 0;
        $sess_ts = $sess_pwd_changed ? strtotime($sess_pwd_changed) : 0;

        if ($db_ts > $sess_ts) {
            session_unset();
            session_destroy();
            header("Location: " . BASE_URL . "dashboard/login.php?error=sessione_non_sicura");
            exit();
        }
    }

    $_SESSION['last_activity'] = time();
}

// --------------------------------------------------------------------------
// isUrlSafe() — Verifica di un URL tramite Google Safe Browsing
// --------------------------------------------------------------------------
/**
 * Interroga Google Safe Browsing. Logica "fail-open": se manca la chiave o
 * l'API non risponde, considera l'URL sicuro (privilegia la disponibilità).
 *
 * @param  string $url_to_check
 * @return bool   true = sicuro; false = segnalato come minaccia.
 */
function isUrlSafe($url_to_check) {
    $apiKey = $_ENV['GOOGLE_SAFE_BROWSING_KEY'] ?? '';
    if (empty($apiKey)) return true;

    $apiUrl = "https://safebrowsing.googleapis.com/v4/threatMatches:find?key=" . $apiKey;
    $requestData = [
        "client" => ["clientId" => "cat4u", "clientVersion" => "1.0.0"],
        "threatInfo" => [
            "threatTypes"      => ["MALWARE", "SOCIAL_ENGINEERING", "UNWANTED_SOFTWARE"],
            "platformTypes"    => ["ANY_PLATFORM"],
            "threatEntryTypes" => ["URL"],
            "threatEntries"    => [["url" => $url_to_check]]
        ]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

    $response = curl_exec($ch);
    if ($response === false || curl_errno($ch)) {
        curl_close($ch);
        return true;
    }
    $result = json_decode($response, true);
    curl_close($ch);
    return empty($result['matches']);
}