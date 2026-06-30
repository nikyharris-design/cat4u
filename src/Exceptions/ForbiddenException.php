<?php
/**
 * ==========================================================================
 * FORBIDDENEXCEPTION — 403 Forbidden
 * ==========================================================================
 *
 * Da lanciare quando l'utente è autenticato ma NON ha il diritto di compiere
 * l'azione (es. un admin di azienda che prova a entrare in una pagina da
 * superadmin).
 *
 * Differenza con 401: 401 = "non so chi sei, autenticati"; 403 = "so chi sei,
 * ma questo non puoi farlo".
 *
 * Eredita tutto da AppException: cambia solo il codice HTTP.
 */

namespace App\Exceptions;

class ForbiddenException extends AppException
{
    protected int $statusCode = 403;
}