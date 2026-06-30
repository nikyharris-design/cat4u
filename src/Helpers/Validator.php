<?php
/**
 * ==========================================================================
 * VALIDATOR — Validazione centralizzata degli input (principio DRY)
 * ==========================================================================
 *
 * Le regole di validazione (campo obbligatorio, email, lunghezze…) scritte
 * UNA volta sola, invece di ripeterle in ogni controller.
 *
 * Funziona "a catena": si passa l'array di dati (es. $_POST) e si concatenano
 * le regole. Gli errori vengono ACCUMULATI; alla fine validate() li lancia
 * tutti insieme dentro una ValidationException (422).
 *
 * Esempio d'uso (in un futuro controller):
 *
 *     (new Validator($_POST))
 *         ->required('nome', 'Nome')
 *         ->required('email', 'Email')
 *         ->email('email', 'Email')
 *         ->validate();   // se qualcosa non va, lancia la 422 con TUTTI gli errori
 *
 * In alternativa il controller può usare fails()/errors() per ridisegnare il
 * form senza far "salire" l'eccezione.
 */

namespace App\Helpers;

use App\Exceptions\ValidationException;

class Validator
{
    /** @var array<string,string> campo => primo messaggio d'errore per quel campo */
    private array $errors = [];

    /** @var array<string,mixed> i dati da validare (tipicamente $_POST) */
    private array $data;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Valore "pulito" (trim) di un campo. Se manca o non è una stringa, ''.
     */
    private function value(string $field): string
    {
        $v = $this->data[$field] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    /**
     * Registra un errore SOLO se per quel campo non ce n'è già uno: così
     * teniamo un solo messaggio per campo (il primo che scatta).
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    /** Il campo non può essere vuoto. */
    public function required(string $field, string $label): self
    {
        if ($this->value($field) === '') {
            $this->addError($field, "Il campo \"$label\" è obbligatorio.");
        }
        return $this;
    }

    /** Se valorizzato, deve essere un'email valida. (Vuoto: lo gestisce required.) */
    public function email(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "Il campo \"$label\" deve essere un'email valida.");
        }
        return $this;
    }

    /** Se valorizzato, lunghezza minima. */
    public function minLength(string $field, int $min, string $label): self
    {
        $v = $this->value($field);
        if ($v !== '' && mb_strlen($v) < $min) {
            $this->addError($field, "Il campo \"$label\" deve avere almeno $min caratteri.");
        }
        return $this;
    }

    /** Se valorizzato, lunghezza massima. */
    public function maxLength(string $field, int $max, string $label): self
    {
        $v = $this->value($field);
        if ($v !== '' && mb_strlen($v) > $max) {
            $this->addError($field, "Il campo \"$label\" non può superare $max caratteri.");
        }
        return $this;
    }

   /**
     * Aggiunge "a mano" un errore di business (es. un valore duplicato nel DB),
     * non derivante da una regola di formato. Stesso comportamento delle regole:
     * un solo messaggio per campo. Utile ai service per unire i loro controlli
     * a quelli di formato e lanciarli tutti insieme.
     */
    public function add(string $field, string $message): self
    {
        $this->addError($field, $message);
        return $this;
    }

    /** True se è stato raccolto almeno un errore. */
    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Tutti gli errori raccolti (campo => messaggio).
     *
     * @return array<string,string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Se c'è almeno un errore, lancia la ValidationException con TUTTI gli
     * errori. Altrimenti non fa nulla e l'esecuzione prosegue.
     */
    public function validate(): void
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
    }
}