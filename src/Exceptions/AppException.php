<?php
/**
 * ==========================================================================
 * APPEXCEPTION — Eccezione base dell'applicazione
 * ==========================================================================
 *
 * È la "radice" di tutte le eccezioni che lanceremo DI PROPOSITO nel codice
 * dell'app (accesso negato, risorsa inesistente, dati non validi…).
 *
 * Perché una classe base nostra invece di usare \Exception direttamente?
 *   - Associa a ogni errore un CODICE HTTP ($statusCode). Così, in un punto
 *     solo (il futuro error handler globale), potremo fare:
 *         catch (AppException $e) { http_response_code($e->getStatusCode()); }
 *     e ogni eccezione "sa" già con quale stato rispondere.
 *   - Distingue i NOSTRI errori "previsti" da quelli imprevisti (bug, DB
 *     offline…), che restano \Exception/\Error generiche.
 *
 * Estende \RuntimeException (figlia di \Exception): scelta idiomatica per
 * errori che emergono durante l'esecuzione. Resta catturabile come \Exception.
 *
 * Questa base = errore generico → 500. Le sottoclassi sovrascriveranno
 * $statusCode con 403, 404, 422.
 */

namespace App\Exceptions;

class AppException extends \RuntimeException
{
    /**
     * Codice di stato HTTP associato all'errore.
     * 500 = errore generico del server. Le sottoclassi lo ridefiniscono.
     */
    protected int $statusCode = 500;

    /**
     * Restituisce il codice HTTP da usare nella risposta.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}