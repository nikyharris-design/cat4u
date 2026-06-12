<?php
/**
 * ==========================================================================
 * CONFIG.PHP — Connessione al Database (PDO) + Logger
 * ==========================================================================
 *
 * RESPONSABILITÀ DI QUESTO FILE:
 * 1. Caricare l'autoloader di Composer (rende disponibili tutte le librerie).
 * 2. Inizializzare il logger (Monolog) per scrivere su file gli eventi dell'app.
 * 3. Leggere e VALIDARE le credenziali dal file .env (niente password nel codice).
 * 4. Aprire la connessione PDO al database, gestendo eventuali errori.
 *
 * CONCETTI CHIAVE:
 * - PDO: interfaccia unica di PHP per parlare con qualsiasi database
 *        (MySQL, PostgreSQL, SQLite…). Cambiando driver cambia poco il codice.
 * - Prepared statements: query con segnaposto (?) che separano SQL e dati,
 *        prevenendo le SQL injection.
 * - Exception handling: gli errori vengono "lanciati" come eccezioni e
 *        catturati con try/catch, invece di restare silenziosi.
 *
 * Questo file viene incluso per primo da bootstrap.php, quindi al termine
 * mette a disposizione due variabili "globali" usate ovunque:
 *   $pdo  → oggetto connessione al database
 *   $log  → logger Monolog
 */
 
// --------------------------------------------------------------------------
// 1. CARICAMENTO LIBRERIE (Composer)
// --------------------------------------------------------------------------
// L'autoload di Composer carica automaticamente le classi delle dipendenze
// (Monolog, Dotenv, Endroid QrCode…) appena le usi, senza require manuali.
// Prerequisito: aver eseguito `composer install` per generare la cartella vendor/.
require_once __DIR__ . '/../vendor/autoload.php';
// --------------------------------------------------------------------------
// GESTIONE ERRORI: mai a schermo, sempre nel log del server.
// --------------------------------------------------------------------------
// display_errors a 0 impedisce che warning/notice (incluso quello di PHP sul
// POST troppo grande) finiscano nella pagina mostrata all'utente. Gli errori
// restano registrati nel log PHP per il debug.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
 
// Importiamo nello scope corrente le classi di Monolog che useremo a breve,
// così possiamo scrivere "Logger" invece del nome completo "Monolog\Logger".
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
 
// --------------------------------------------------------------------------
// 2. LOGGER (Monolog)
// --------------------------------------------------------------------------
// Creiamo un canale di log chiamato 'cat4u': è solo un'etichetta che comparirà
// nelle righe di log per riconoscere da quale applicazione provengono.
$log = new Logger('cat4u');
 
// Aggiungiamo un "handler": dice A DOVE e CON QUALE livello minimo scrivere.
// - StreamHandler scrive su un file (qui logs/app.log).
// - Logger::DEBUG è il livello minimo: registra TUTTO (DEBUG, INFO, WARNING,
//   ERROR, CRITICAL). In produzione si alza spesso a WARNING per ridurre rumore.
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));
 
// Da qui in avanti $log è pronto: lo si usa come variabile globale o lo si
// passa alle funzioni che devono registrare eventi.
 
// --------------------------------------------------------------------------
// 3. CARICAMENTO E VALIDAZIONE DEL .env (phpdotenv)
// --------------------------------------------------------------------------
// createImmutable() legge il file .env nella cartella indicata e popola
// $_ENV / getenv(). "Immutable" significa che, una volta caricate, le variabili
// d'ambiente non possono essere sovrascritte: comportamento più sicuro e
// prevedibile.
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
 
// required() impone che queste variabili ESISTANO: se ne manca anche una,
// viene lanciata subito un'eccezione chiara, invece di far emergere errori
// oscuri più avanti (es. connessione che fallisce senza spiegazioni).
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME']);
 
// Lettura delle credenziali dall'ambiente: nessun dato sensibile è scritto
// nel codice sorgente (che spesso finisce su Git). Il .env resta locale.
$host     = $_ENV['DB_HOST'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASSWORD'];
$database = $_ENV['DB_NAME'];
 
// Charset della connessione. utf8mb4 è l'UTF-8 "completo" di MySQL: gestisce
// anche emoji e caratteri a 4 byte (a differenza del vecchio "utf8" troncato).
$charset  = "utf8mb4";
 
// --------------------------------------------------------------------------
// 4. CONNESSIONE PDO
// --------------------------------------------------------------------------
// DSN (Data Source Name): la "stringa di connessione" che dice al driver
// PDO quale database raggiungere e con quali parametri.
$dsn = "mysql:host=$host;dbname=$database;charset=$charset";
 
/**
 * Opzioni di configurazione di PDO:
 * - ATTR_ERRMODE = ERRMODE_EXCEPTION
 *      In caso di errore SQL lancia un'eccezione invece di fallire in silenzio.
 *      Fondamentale per intercettare i problemi con try/catch.
 * - ATTR_DEFAULT_FETCH_MODE = FETCH_ASSOC
 *      I risultati arrivano come array associativi (es. $row['nome']) invece
 *      che con indici numerici: codice più leggibile.
 * - ATTR_EMULATE_PREPARES = false
 *      Disabilita i prepared statement "emulati" da PHP e usa quelli reali del
 *      server MySQL: più sicuri e con tipizzazione corretta dei parametri.
 */
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
 
try {
    // Tentiamo di aprire la connessione. Se fallisce (DB spento, credenziali
    // errate…) viene lanciata una PDOException, catturata qui sotto.
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Strategia in caso di errore di connessione:
    // 1. Registriamo il messaggio REALE nei log (visibile solo al gestore del
    //    server): utile per il debug.
    // 2. Mostriamo all'utente un messaggio GENERICO, senza dettagli tecnici.
    //    Esporre $e->getMessage() in produzione rivelerebbe informazioni utili
    //    a un attaccante (nome DB, host, struttura…).
    $log->error('Connessione DB fallita', ['error' => $e->getMessage()]);
 
    // 503 Service Unavailable: lo stato HTTP corretto per "servizio
    // temporaneamente non raggiungibile".
    http_response_code(503);
    die("Servizio temporaneamente non disponibile. Riprova tra qualche minuto.");
}
