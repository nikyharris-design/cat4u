<?php
/**
 * ==========================================================================
 * ERRORHANDLER — Gestore globale degli errori (rete di sicurezza)
 * ==========================================================================
 *
 * Una volta registrato (register()), intercetta:
 *   - le eccezioni NON catturate da alcun try/catch (set_exception_handler)
 *   - gli errori FATALI che terminano lo script (register_shutdown_function)
 *
 * Distinzione chiave:
 *   - Se è una nostra App\Exceptions\AppException → sa già con quale stato
 *     HTTP rispondere (403/404/422…) e porta un messaggio adatto all'utente.
 *   - Se è un errore IMPREVISTO (bug, DB caduto a metà richiesta…) → lo
 *     registriamo nel log e mostriamo un 500 generico, SENZA rivelare dettagli
 *     tecnici a chi guarda la pagina.
 *
 * In entrambi i casi l'utente vede public/error.php, mai una schermata bianca.
 */

namespace App;

use App\Exceptions\AppException;

class ErrorHandler
{
    /** Evita una doppia renderizzazione (es. shutdown dopo un exit). */
    private static bool $handled = false;

    /**
     * Registra i due gestori globali. Da chiamare UNA volta, presto, nel
     * bootstrap (dopo che $log è disponibile).
     */
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /** Gestisce un'eccezione non catturata. */
    public static function handleException(\Throwable $e): void
    {
        if ($e instanceof AppException) {
            // Errore "previsto": status e messaggio sono già adatti all'utente.
            self::render(
                $e->getStatusCode(),
                $e->getMessage() ?: self::defaultMessage($e->getStatusCode())
            );
        } else {
            // Errore IMPREVISTO: log dei dettagli reali + 500 generico.
            self::log($e);
            self::render(500, self::defaultMessage(500));
        }
    }

    /**
     * Intercetta gli errori fatali (es. chiamata a funzione inesistente) che
     * NON passano da set_exception_handler. Scatta alla fine dello script.
     */
    public static function handleShutdown(): void
    {
        $err = error_get_last();
        $fatali = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

        if ($err !== null && ($err['type'] & $fatali)) {
            self::log(new \ErrorException(
                $err['message'], 0, $err['type'], $err['file'], $err['line']
            ));
            self::render(500, self::defaultMessage(500));
        }
    }

    /** Messaggio utente di default per uno stato, se l'eccezione non ne porta uno. */
    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            403     => "Non hai i permessi per accedere a questa risorsa.",
            404     => "La pagina che cerchi non esiste o è stata rimossa.",
            422     => "I dati inviati non sono validi.",
            503     => "Servizio temporaneamente non disponibile.",
            default => "Si è verificato un errore imprevisto. Riprova più tardi.",
        };
    }

    /**
     * Registra l'errore reale (visibile solo a chi gestisce il server).
     * Usa Monolog ($log globale) se c'è, altrimenti il log di PHP.
     */
    private static function log(\Throwable $e): void
    {
        $contesto = [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ];

        $log = $GLOBALS['log'] ?? null;
        if ($log instanceof \Monolog\Logger) {
            $log->error('Errore non gestito', $contesto);
        } else {
            error_log('Cat4U errore non gestito: ' . json_encode($contesto));
        }
    }

    /**
     * Mostra la pagina di errore pulita con lo stato HTTP corretto.
     * Scritta per NON lanciare a sua volta eccezioni (eviterebbe un loop).
     */
    private static function render(int $status, string $messaggio): void
    {
        if (self::$handled) {
            return;
        }
        self::$handled = true;

        // Imposta lo stato solo se nulla è già stato inviato al client.
        if (!headers_sent()) {
            http_response_code($status);
        }

        // Svuota output parziali: la pagina d'errore deve sostituire tutto.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        require __DIR__ . '/../public/error.php';
        exit;
    }
}