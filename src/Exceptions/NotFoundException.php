<?php
/**
 * ==========================================================================
 * NOTFOUNDEXCEPTION — 404 Not Found
 * ==========================================================================
 *
 * Da lanciare quando la risorsa richiesta non esiste: azienda con uno slug
 * inesistente, catalogo non trovato, pagina sconosciuta.
 *
 * Sostituirà le attuali chiamate a not_found() sparse nei controller, dando
 * un punto unico di gestione (il futuro error handler).
 */

namespace App\Exceptions;

class NotFoundException extends AppException
{
    protected int $statusCode = 404;
}