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

// Impedisce il "MIME sniffing": il browser rispetta il Content-Type dichiarato.
header('X-Content-Type-Options: nosniff');

// Anti-clickjacking: nessun sito esterno può incorniciare le nostre pagine.
// I nostri iframe (fallback PDF) sono same-origin, quindi non si rompono.
header('X-Frame-Options: SAMEORIGIN');

// Limita le informazioni inviate nel Referer verso siti esterni.
header('Referrer-Policy: strict-origin-when-cross-origin');

// Content-Security-Policy. Le direttive sono tarate sul flipbook:
//   - script-src 'self'         → niente script inline (l'URL del PDF passa
//                                  via data-attribute, non interpolato)
//   - worker-src 'self' blob:   → PDF.js istanzia il proprio Web Worker
//   - img-src 'self' data: blob:→ le pagine renderizzate sono data/blob URL
//   - style-src 'unsafe-inline' → finché restano stili inline nelle pagine
//   - object-src 'none'         → nessun plugin/embed
//   - frame-ancestors 'self'    → raddoppia l'anti-clickjacking
header("Content-Security-Policy: default-src 'self'; "
     . "script-src 'self'; "
     . "worker-src 'self' blob:; "
     . "img-src 'self' data: blob:; "
     . "style-src 'self' 'unsafe-inline'; "
     . "object-src 'none'; "
     . "base-uri 'self'; "
     . "frame-ancestors 'self'");

// HSTS: forza HTTPS sulle visite successive. Solo se siamo già in HTTPS,
// altrimenti in locale (http) bloccheremmo l'accesso.
if ($is_https) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
// --------------------------------------------------------------------------
// FINGERPRINT DELLA SESSIONE (anti session hijacking)
// --------------------------------------------------------------------------
// Fingerprint basato SOLO sullo user-agent. L'IP è stato tolto di proposito:
// cambia troppo spesso durante una sessione legittima (rete mobile e, soprattutto,
// reti dual-stack dove lo stesso dispositivo alterna IPv4 e IPv6). Con l'IP nel
// fingerprint, tornare da una pagina pubblica servita con un IP diverso bastava a
// distruggere la sessione. La protezione forte contro il riuso di un cookie rubato
// resta il cookie stesso: HttpOnly + Secure + SameSite=Strict (impostati sopra).
$user_fingerprint = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');

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
       // Token mancante o non combaciante (possibile CSRF, o più spesso una
        // sessione scaduta): 403 via eccezione. L'error handler globale la
        // trasforma in pagina pulita con lo stato corretto.
        throw new \App\Exceptions\ForbiddenException(
            "Richiesta non valida. Ricarica la pagina e riprova."
        );
    }
}

// --------------------------------------------------------------------------
// not_found() — pagina 404 centralizzata
// --------------------------------------------------------------------------
/**
 * Lancia una NotFoundException (404): la pagina di errore viene poi prodotta
 * centralmente dall'error handler globale. Non ritorna mai (never).
 * Sostituisce i vari die("...") con testo grezzo: tutte le pagine possono
 * chiamare not_found() per una "pagina non trovata" coerente.
 *
 * @param string $messaggio testo mostrato all'utente (personalizzabile per contesto)
 */
function not_found(string $messaggio = "La pagina che cerchi non esiste o è stata rimossa."): never {
    // Stessa firma e stessi messaggi di prima, ma invece di renderizzare qui
    // lanciamo l'eccezione: l'error handler globale produce la pagina 404 in
    // modo centralizzato. Così TUTTE le chiamate a not_found(...) nei controller
    // passano dall'handler senza toccare ogni singolo file.
    // (public/404.php resta per eventuali ErrorDocument di Apache, cioè i 404
    // che non arrivano nemmeno a PHP.)
    throw new \App\Exceptions\NotFoundException($messaggio);
}

// --------------------------------------------------------------------------
// TIMEOUT DI INATTIVITÀ (auto-logout)
// --------------------------------------------------------------------------
$timeout_duration = 1800; // 30 minuti

if (isset($_SESSION['autorizzato'])) {
    // --- TIMEOUT DI INATTIVITÀ ---
    // Se è passato più di $timeout_duration dall'ultima attività, la sessione
    // è scaduta: la distruggiamo e rimandiamo al login con avviso dedicato.
    if (isset($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) > $timeout_duration) {
        session_unset();
        session_destroy();
        header("Location: " . BASE_URL . "dashboard/login.php?error=timeout");
        exit();
    }

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

