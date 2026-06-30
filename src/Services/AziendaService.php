<?php
/**
 * ==========================================================================
 * AZIENDASERVICE — Logica di business delle aziende
 * ==========================================================================
 *
 * Raccoglie tutto ciò che "fa" l'app con le aziende (query, slug, QR, file),
 * fuori dal controller. Riceve \PDO e il percorso uploads/ nel COSTRUTTORE:
 * niente require di bootstrap, niente variabili globali. Classe "pura",
 * caricata via autoload PSR-4.
 */

namespace App\Services;

class AziendaService
{
    public function __construct(
        private \PDO $pdo,
        private string $uploadsDir
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function tutte(): array
    {
        return $this->pdo
            ->query("SELECT * FROM aziende ORDER BY nome_azienda ASC")
            ->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function trova(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM aziende WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Crea una nuova azienda: valida i dati, controlla i duplicati, genera slug
     * univoco e QR, inserisce la riga. Restituisce l'id della nuova azienda.
     *
     * @param array<string,mixed> $dati    dati del form (tipicamente $_POST)
     * @param string              $baseUrl BASE_URL dell'app (per l'URL del QR)
     * @return int                         id della nuova azienda
     * @throws \App\Exceptions\ValidationException se i dati non sono validi
     */
    public function crea(array $dati, string $baseUrl): int
    {
        $nome  = trim((string)($dati['nome_azienda']   ?? ''));
        $tipo  = trim((string)($dati['tipo_azienda']   ?? ''));
        $iva   = trim((string)($dati['partita_iva']    ?? ''));
        $email = trim((string)($dati['email_contatto'] ?? ''));

        // Formato + business: tutti gli errori raccolti insieme.
        $v = new \App\Helpers\Validator($dati);
        $v->required('nome_azienda', 'Nome')
          ->required('tipo_azienda', 'Tipo')
          ->required('partita_iva', 'Partita IVA')
          ->required('email_contatto', 'Email')
          ->email('email_contatto', 'Email');

        // Controlli sul DB solo se il campo è valorizzato (il vuoto lo copre required).
        if ($nome !== '' && $this->nomeEsiste($nome)) {
            $v->add('nome_azienda', "Esiste già un'azienda registrata con questo nome.");
        }
        if ($iva !== '' && $this->ivaEsiste($iva)) {
            $v->add('partita_iva', 'Partita IVA già presente nel sistema.');
        }

        $v->validate(); // se c'è anche un solo errore, lancia ValidationException

        // Dati validi: slug + QR, poi INSERT.
        $slug   = $this->slugUnivoco($nome);
        $qrPath = $this->generaQr($slug, $baseUrl);

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO aziende (nome_azienda, tipo_azienda, partita_iva, email_contatto, slug, qr_code_path)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$nome, $tipo, $iva, $email, $slug, $qrPath]);
        } catch (\PDOException $e) {
            // Se l'INSERT fallisce, il QR appena creato resterebbe orfano: lo togliamo.
            $this->eliminaFileSicuro($qrPath);
            throw $e;
        }

       return (int)$this->pdo->lastInsertId();
    }

    /**
     * Modifica i dati anagrafici di un'azienda esistente.
     *
     * NON tocca slug né QR: l'URL codificato nel QR resta invariato, così un
     * codice già stampato continua a funzionare. I controlli sui duplicati
     * escludono l'azienda stessa (altrimenti "troverebbe sé stessa").
     *
     * @param array<string,mixed> $dati dati del form (tipicamente $_POST)
     * @throws \App\Exceptions\ValidationException se i dati non sono validi
     */
    public function modifica(int $id, array $dati): void
    {
        $nome  = trim((string)($dati['nome_azienda']   ?? ''));
        $tipo  = trim((string)($dati['tipo_azienda']   ?? ''));
        $iva   = trim((string)($dati['partita_iva']    ?? ''));
        $email = trim((string)($dati['email_contatto'] ?? ''));

        $v = new \App\Helpers\Validator($dati);
        $v->required('nome_azienda', 'Nome')
          ->required('tipo_azienda', 'Tipo')
          ->required('partita_iva', 'Partita IVA')
          ->required('email_contatto', 'Email')
          ->email('email_contatto', 'Email');

        // Duplicati ESCLUDENDO l'azienda corrente ($id).
        if ($nome !== '' && $this->nomeEsiste($nome, $id)) {
            $v->add('nome_azienda', "Esiste già un'azienda registrata con questo nome.");
        }
        if ($iva !== '' && $this->ivaEsiste($iva, $id)) {
            $v->add('partita_iva', 'Partita IVA già presente nel sistema.');
        }

        $v->validate();

        // Solo i dati anagrafici: slug e qr_code_path restano invariati.
        $stmt = $this->pdo->prepare(
            "UPDATE aziende SET nome_azienda = ?, tipo_azienda = ?, partita_iva = ?, email_contatto = ?
             WHERE id = ?"
        );
        $stmt->execute([$nome, $tipo, $iva, $email, $id]);
    }

    /**
     * Elimina un'azienda e il suo file QR dal disco.
     */
    public function elimina(int $id): void
    {
        $stmt = $this->pdo->prepare("SELECT qr_code_path FROM aziende WHERE id = ?");
        $stmt->execute([$id]);
        $az = $stmt->fetch();

        $this->pdo->prepare("DELETE FROM aziende WHERE id = ?")->execute([$id]);

        if ($az && !empty($az['qr_code_path'])) {
            $this->eliminaFileSicuro((string)$az['qr_code_path']);
        }
    }

    /**
     * True se esiste già un'azienda con questo nome. $excludeId esclude un id
     * dal confronto (serve in modifica, per non considerare "duplicato" se stessa).
     */
    private function nomeEsiste(string $nome, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare("SELECT id FROM aziende WHERE nome_azienda = ? AND id <> ? LIMIT 1");
            $stmt->execute([$nome, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM aziende WHERE nome_azienda = ? LIMIT 1");
            $stmt->execute([$nome]);
        }
        return $stmt->fetch() !== false;
    }

    /** Come nomeEsiste, ma sulla partita IVA. */
    private function ivaEsiste(string $iva, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->pdo->prepare("SELECT id FROM aziende WHERE partita_iva = ? AND id <> ? LIMIT 1");
            $stmt->execute([$iva, $excludeId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT id FROM aziende WHERE partita_iva = ? LIMIT 1");
            $stmt->execute([$iva]);
        }
        return $stmt->fetch() !== false;
    }

    /**
     * Cancella un file in modo SICURO: solo se è realmente dentro uploads/.
     * Difesa contro path traversal.
     */
    private function eliminaFileSicuro(string $relPath): void
    {
        if ($relPath === '') {
            return;
        }

        $root = dirname($this->uploadsDir);
        $base = realpath($this->uploadsDir);
        $file = realpath($root . DIRECTORY_SEPARATOR . $relPath);

        if ($file === false || $base === false
            || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0) {
            return;
        }

        unlink($file);
    }

    /**
     * Slug univoco: se lo slug base è occupato, prova base-1, base-2…
     */
    private function slugUnivoco(string $nome): string
    {
        $base = make_slug($nome);
        $slug = $base;
        $i = 1;

        $stmt = $this->pdo->prepare("SELECT id FROM aziende WHERE slug = ?");

        while (true) {
            $stmt->execute([$slug]);
            if ($stmt->fetch() === false) {
                return $slug;
            }
            $slug = $base . '-' . $i++;
        }
    }

    /**
     * Genera il QR della libreria pubblica, lo salva in uploads/qr/ e
     * restituisce il percorso relativo da mettere nel DB.
     *
     * @param string $slug    slug dell'azienda
     * @param string $baseUrl BASE_URL dell'app
     * @return string         es. uploads/qr/azienda-foo.png
     */
    private function generaQr(string $slug, string $baseUrl): string
    {
        $qrUrl   = $baseUrl . 'public/libreria.php?a=' . $slug;
        $qrName  = 'azienda-' . $slug . '.png';
        $relPath = 'uploads/qr/' . $qrName;

        $qrCode = new \Endroid\QrCode\QrCode(
            data: $qrUrl,
            encoding: new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
            errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::High,
            size: 400,
            margin: 20,
            foregroundColor: new \Endroid\QrCode\Color\Color(0, 0, 0),
            backgroundColor: new \Endroid\QrCode\Color\Color(255, 255, 255)
        );

        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $writer->write($qrCode)->saveToFile($this->uploadsDir . '/qr/' . $qrName);

        return $relPath;
    }
}