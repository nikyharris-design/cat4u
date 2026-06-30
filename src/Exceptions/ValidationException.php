<?php
/**
 * ==========================================================================
 * VALIDATIONEXCEPTION — 422 Unprocessable Entity
 * ==========================================================================
 *
 * Lanciata quando l'input dell'utente non supera le regole di validazione.
 * A differenza delle altre eccezioni porta con sé l'ELENCO degli errori (uno
 * per campo), così chi la cattura può ri-mostrare il form evidenziando tutti
 * i problemi in una volta sola.
 *
 * 422 = la richiesta è ben formata come HTTP, ma il CONTENUTO non è
 * processabile (diverso dal 400 = richiesta malformata).
 *
 * Uso tipico: il controller la CATTURA e ridisegna il form. L'error handler
 * globale resta solo come rete di sicurezza se nessuno la cattura.
 */

namespace App\Exceptions;

class ValidationException extends AppException
{
    protected int $statusCode = 422;

    /** @var array<string,string> mappa campo => messaggio d'errore */
    private array $errors;

    /**
     * @param array<string,string> $errors elenco errori (campo => messaggio)
     */
    public function __construct(
        array $errors,
        string $message = "I dati inviati non sono validi."
    ) {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * Tutti gli errori raccolti, per ri-mostrarli accanto ai campi del form.
     *
     * @return array<string,string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}