<?php
/**
 * ==========================================================================
 * CONFIG.PHP - Gestione Connessione Database (PDO)
 * ==========================================================================
 * CONCETTI CHIAVE:
 * 1. Oggetto PDO: Interfaccia universale per database (MySQL, PostgreSQL, ecc.).
 * 2. Exception Handling: Gestione degli errori tramite blocchi try-catch.
 */
// 1. CARICAMENTO LIBRERIE (Composer)
// Assicurati di aver lanciato 'composer require vlucas/phpdotenv'
require_once __DIR__ . '/../vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Creiamo il logger
$log = new Logger('qr_magic');

// Specifichiamo dove salvare i log e il livello minimo (DEBUG, INFO, WARNING, ERROR, CRITICAL)
$log->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));

// Ora il logger è pronto per essere usato come variabile globale o passata alle funzioni

// Caricamento e VALIDAZIONE del .env
// required() lancia un'eccezione chiara se una variabile manca,
// invece di far emergere errori oscuri altrove nel codice.
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();
$dotenv->required(['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME', 'GOOGLE_SAFE_BROWSING_KEY']);
 
// Lettura credenziali dall'ambiente — niente più hardcoding!
$host     = $_ENV['DB_HOST'];
$username = $_ENV['DB_USER'];
$password = $_ENV['DB_PASSWORD'];
$database = $_ENV['DB_NAME'];
$charset  = "utf8mb4";


// DSN (Data Source Name): La "stringa di identificazione" per il driver
$dsn = "mysql:host=$host;dbname=$database;charset=$charset";

/**
 * Opzioni di configurazione PDO:
 * - ATTR_ERRMODE: Lancia eccezioni in caso di errore (fondamentale per il debug).
 * - DEFAULT_FETCH_MODE: Restituisce array associativi (come facevi con mysqli).
 * - EMULATE_PREPARES: Disabilitata per usare i veri prepared statements di MySQL.
 */
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Logghiamo l'errore reale (visibile solo nei log del server)
    // e mostriamo un messaggio generico all'utente — mai esporre $e->getMessage() in produzione!
    $log->error('Connessione DB fallita', ['error' => $e->getMessage()]);
    http_response_code(503);
    die("Servizio temporaneamente non disponibile. Riprova tra qualche minuto.");
}

?>