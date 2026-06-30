<?php
/**
 * ==========================================================================
 * VALIDATIONEXCEPTION — 422 Unprocessable Entity
 * ==========================================================================
 *
 * Da lanciare quando l'input dell'utente è formalmente arrivato ma NON supera
 * le regole di validazione (email malformata, campo obbligatorio vuoto,
 * partita IVA non valida…).
 *
 * 422 è lo stato semantico corretto: la richiesta è ben formata come HTTP, ma
 * il CONTENUTO non è processabile. Diverso da 400 (richiesta malformata).
 *
 * Questa eccezione sarà il "collante" con il Validator che creeremo dopo:
 * il Validator raccoglie gli errori e, se ce ne sono, lancia una di queste.
 */

namespace App\Exceptions;

class ValidationException extends AppException
{
    protected int $statusCode = 422;
}