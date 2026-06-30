<?php
/**
 * ==========================================================================
 * CATALOGOSERVICE — Logica di business dei cataloghi
 * ==========================================================================
 *
 * Come AziendaService, ma multi-tenant: ogni metodo riceve $aziendaId e lo usa
 * nei controlli, così un utente non può MAI toccare cataloghi di un'altra
 * azienda (difesa IDOR). Riceve \PDO e il percorso uploads/ nel costruttore.
 *
 * Per ora solo le letture (generi, cataloghi, trova). Nei prossimi passi:
 * elimina, attiva/disattiva, e infine crea/modifica con upload PDF e QR.
 */

namespace App\Services;

class CatalogoService
{
    public function __construct(
        private \PDO $pdo,
        private string $uploadsDir
    ) {
    }

    /**
     * Generi dell'azienda, per la tendina del form.
     *
     * @return list<array<string,mixed>>
     */
    public function generi(int $aziendaId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM generi WHERE azienda_id = ? ORDER BY nome_genere");
        $stmt->execute([$aziendaId]);
        return $stmt->fetchAll();
    }

    /**
     * Cataloghi dell'azienda, con nome del genere e slug azienda (per i link).
     *
     * @return list<array<string,mixed>>
     */
    public function cataloghi(int $aziendaId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.*, g.nome_genere, a.slug AS azienda_slug
             FROM cataloghi c
             JOIN generi g ON g.id = c.genere_id
             JOIN aziende a ON a.id = c.azienda_id
             WHERE c.azienda_id = ?
             ORDER BY c.created_at DESC"
        );
        $stmt->execute([$aziendaId]);
        return $stmt->fetchAll();
    }

    /**
     * Un singolo catalogo dell'azienda (controllo di proprietà incluso), o null.
     * Se l'id esiste ma è di un'altra azienda, torna comunque null: l'utente
     * non deve nemmeno sapere che esiste.
     *
     * @return array<string,mixed>|null
     */
    public function trova(int $id, int $aziendaId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM cataloghi WHERE id = ? AND azienda_id = ?");
        $stmt->execute([$id, $aziendaId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Crea un nuovo catalogo: valida titolo/genere/PDF, verifica che il genere
     * sia dell'azienda, genera slug + QR, salva il PDF e inserisce la riga.
     * Restituisce un array con id e l'URL pubblico (utile per il messaggio).
     *
     * Se qualcosa fallisce DOPO aver salvato i file, li ripuliamo per non
     * lasciare PDF/QR orfani sul disco.
     *
     * @param array<string,mixed> $dati  dati del form ($_POST)
     * @param array<string,mixed> $file  l'upload ($_FILES['pdf'])
     * @param string              $baseUrl BASE_URL dell'app
     * @return array{id:int,url:string}
     * @throws \App\Exceptions\ValidationException se i dati non sono validi
     */
    public function crea(array $dati, array $file, int $aziendaId, string $baseUrl): array
    {
        $titolo    = trim((string)($dati['titolo'] ?? ''));
        $genereId  = (int)($dati['genere_id'] ?? 0);
        $scadenza  = trim((string)($dati['data_scadenza'] ?? '')) ?: null;

        // --- Validazione (formato + business), tutto raccolto insieme ---
        $v = new \App\Helpers\Validator($dati);
        $v->required('titolo', 'Titolo')
          ->required('genere_id', 'Genere');

        // Il genere deve esistere ED essere dell'azienda corrente.
        if ($genereId > 0 && !$this->genereDellAzienda($genereId, $aziendaId)) {
            $v->add('genere_id', 'Genere non valido.');
        }

        // Validazione del PDF (obbligatorio in creazione).
        $pdfErr = $this->validaPdf($file);
        if ($pdfErr !== '') {
            $v->add('pdf', $pdfErr);
        }

        $v->validate(); // se c'è un errore, lancia ValidationException con tutti

        // --- Dati validi: slug, salvataggio PDF, QR, INSERT ---
        $slug        = $this->slugUnivoco($titolo);
        $aziendaSlug = $this->slugAzienda($aziendaId);

        $pdfPath = null;
        $qrPath  = null;
        try {
            $pdfPath = $this->salvaPdf($file, $slug);
            $qrPath  = $this->generaQr($aziendaSlug, $slug, $baseUrl);

            $stmt = $this->pdo->prepare(
                "INSERT INTO cataloghi (azienda_id, genere_id, titolo, pdf_path, slug, qr_code_path, data_scadenza)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$aziendaId, $genereId, $titolo, $pdfPath, $slug, $qrPath, $scadenza]);
        } catch (\Throwable $e) {
            // Pulizia dei file eventualmente già scritti, poi rilanciamo.
            if ($pdfPath !== null) { $this->eliminaFileSicuro($pdfPath); }
            if ($qrPath  !== null) { $this->eliminaFileSicuro($qrPath); }
            throw $e;
        }

     $id  = (int)$this->pdo->lastInsertId();
        $url = $baseUrl . 'public/catalogo.php?a=' . $aziendaSlug . '&c=' . $slug;

        return ['id' => $id, 'url' => $url];
    }

    /**
     * Modifica un catalogo esistente dell'azienda.
     *
     * - slug e QR NON cambiano mai (i QR stampati restano validi);
     * - il PDF è facoltativo: si sostituisce solo se ne arriva uno nuovo, e in
     *   quel caso il vecchio file viene cancellato;
     * - controllo di proprietà: se il catalogo non è dell'azienda, 404.
     *
     * @param array<string,mixed> $dati dati del form ($_POST)
     * @param array<string,mixed> $file l'upload ($_FILES['pdf']); può essere "vuoto"
     * @throws \App\Exceptions\NotFoundException   se il catalogo non è dell'azienda
     * @throws \App\Exceptions\ValidationException se i dati non sono validi
     */
    public function modifica(int $id, array $dati, array $file, int $aziendaId): void
    {
        // Il catalogo deve esistere ed essere dell'azienda.
        $cat = $this->trova($id, $aziendaId);
        if ($cat === null) {
            throw new \App\Exceptions\NotFoundException("Catalogo non trovato.");
        }

        $titolo   = trim((string)($dati['titolo'] ?? ''));
        $genereId = (int)($dati['genere_id'] ?? 0);
        $scadenza = trim((string)($dati['data_scadenza'] ?? '')) ?: null;

        // --- Validazione ---
        $v = new \App\Helpers\Validator($dati);
        $v->required('titolo', 'Titolo')
          ->required('genere_id', 'Genere');

        if ($genereId > 0 && !$this->genereDellAzienda($genereId, $aziendaId)) {
            $v->add('genere_id', 'Genere non valido.');
        }

        // Il PDF è opzionale: lo validiamo SOLO se l'utente ne ha caricato uno.
        $nuovoPdf = !empty($file['name']);
        if ($nuovoPdf) {
            $pdfErr = $this->validaPdf($file);
            if ($pdfErr !== '') {
                $v->add('pdf', $pdfErr);
            }
        }

        $v->validate();

        // --- Aggiornamento ---
        // Di default teniamo il PDF attuale; lo sostituiamo solo se c'è il nuovo.
        $pdfPath    = (string)$cat['pdf_path'];
        $vecchioPdf = null;

        if ($nuovoPdf) {
            $pdfPath    = $this->salvaPdf($file, (string)$cat['slug']);
            $vecchioPdf = (string)$cat['pdf_path']; // da cancellare dopo l'UPDATE
        }

        $stmt = $this->pdo->prepare(
            "UPDATE cataloghi SET titolo = ?, genere_id = ?, data_scadenza = ?, pdf_path = ?
             WHERE id = ? AND azienda_id = ?"
        );
        $stmt->execute([$titolo, $genereId, $scadenza, $pdfPath, $id, $aziendaId]);

        // Solo ora che l'UPDATE è andato a buon fine, rimuoviamo il vecchio PDF.
        if ($vecchioPdf !== null && $vecchioPdf !== $pdfPath) {
            $this->eliminaFileSicuro($vecchioPdf);
        }
    }

    /**
     * Attiva o disattiva un catalogo (vincolato all'azienda).
     * Se l'id non è dell'azienda, l'UPDATE non tocca righe: nessun effetto.
     */
    public function impostaStato(int $id, int $aziendaId, bool $attivo): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE cataloghi SET is_active = ? WHERE id = ? AND azienda_id = ?"
        );
        $stmt->execute([$attivo ? 1 : 0, $id, $aziendaId]);
    }

    /**
     * Elimina un catalogo dell'azienda e i suoi file (PDF + QR) dal disco.
     * Il controllo azienda_id impedisce di cancellare cataloghi altrui: se il
     * catalogo non è dell'azienda, non troviamo la riga e non facciamo nulla.
     */
    public function elimina(int $id, int $aziendaId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT pdf_path, qr_code_path FROM cataloghi WHERE id = ? AND azienda_id = ?"
        );
        $stmt->execute([$id, $aziendaId]);
        $cat = $stmt->fetch();

        // Non è dell'azienda (o non esiste): stop, niente da eliminare.
        if ($cat === false) {
            return;
        }

        $this->pdo->prepare("DELETE FROM cataloghi WHERE id = ?")->execute([$id]);

        // Puliamo entrambi i file in modo sicuro.
        $this->eliminaFileSicuro((string)($cat['pdf_path'] ?? ''));
        $this->eliminaFileSicuro((string)($cat['qr_code_path'] ?? ''));
    }

   /** True se il genere esiste ed è dell'azienda indicata. */
    private function genereDellAzienda(int $genereId, int $aziendaId): bool
    {
        $stmt = $this->pdo->prepare("SELECT id FROM generi WHERE id = ? AND azienda_id = ?");
        $stmt->execute([$genereId, $aziendaId]);
        return $stmt->fetch() !== false;
    }

    /** Slug dell'azienda (serve per costruire l'URL del QR del catalogo). */
    private function slugAzienda(int $aziendaId): string
    {
        $stmt = $this->pdo->prepare("SELECT slug FROM aziende WHERE id = ?");
        $stmt->execute([$aziendaId]);
        return (string)$stmt->fetchColumn();
    }

    /**
     * Slug univoco a partire dal titolo: se occupato, prova base-1, base-2…
     * L'unicità è globale (come nel codice attuale), perché lo slug del
     * catalogo finisce nell'URL pubblico insieme a quello dell'azienda.
     */
    private function slugUnivoco(string $titolo): string
    {
        $base = make_slug($titolo);
        $slug = $base;
        $i = 1;

        $stmt = $this->pdo->prepare("SELECT id FROM cataloghi WHERE slug = ?");
        while (true) {
            $stmt->execute([$slug]);
            if ($stmt->fetch() === false) {
                return $slug;
            }
            $slug = $base . '-' . $i++;
        }
    }

    /**
     * Valida un upload PDF guardando il CONTENUTO, non il MIME dichiarato dal
     * browser (falsificabile). Tornava una stringa nel vecchio controller:
     * manteniamo la stessa convenzione ('' = ok, altrimenti il messaggio),
     * così crea()/modifica() possono passarla al Validator come errore.
     *
     * @param array<string,mixed> $file una voce di $_FILES (es. $_FILES['pdf'])
     * @return string '' se valido, altrimenti il messaggio d'errore
     */
    private function validaPdf(array $file, int $maxBytes = 20 * 1024 * 1024): string
    {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            return match ($err) {
                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE => "Il PDF è troppo grande (max 20MB).",
                UPLOAD_ERR_PARTIAL   => "Il caricamento si è interrotto. Riprova.",
                UPLOAD_ERR_NO_FILE   => "Seleziona un file PDF.",
                default              => "Errore nel caricamento del file.",
            };
        }
        if ($file['size'] <= 0 || $file['size'] > $maxBytes) {
            return "Il PDF non può superare 20MB.";
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return "File non valido.";
        }
        // MIME reale dai magic bytes del file salvato sul tmp.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
            return "Il file deve essere un PDF.";
        }
        // Conferma sulla firma iniziale (i PDF iniziano con "%PDF-").
        $fh = fopen($file['tmp_name'], 'rb');
        if ($fh === false) {
            return "Impossibile leggere il file.";
        }
        $head = fread($fh, 5);
        fclose($fh);
        if ($head !== '%PDF-') {
            return "Il file deve essere un PDF.";
        }
        return '';
    }

    /**
     * Sposta il PDF caricato in uploads/pdf/ e restituisce il path RELATIVO.
     * Da chiamare solo dopo validaPdf(). Il nome usa lo slug + timestamp per
     * evitare collisioni e cache del browser sul file vecchio.
     *
     * @param array<string,mixed> $file voce di $_FILES
     * @return string path relativo (es. uploads/pdf/foo_123.pdf)
     * @throws \RuntimeException se lo spostamento fallisce
     */
    private function salvaPdf(array $file, string $slug): string
    {
        $name    = $slug . '_' . time() . '.pdf';
        $relPath = 'uploads/pdf/' . $name;
        $dest    = $this->uploadsDir . '/pdf/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new \RuntimeException("Salvataggio del PDF non riuscito.");
        }
        return $relPath;
    }

    /**
     * Genera il QR del CATALOGO: punta alla pagina pubblica del catalogo
     * (slug azienda + slug catalogo). Salva in uploads/qr/ e torna il path
     * relativo. Confina qui tutto il "come si disegna un QR".
     *
     * @return string path relativo (es. uploads/qr/foo_123.png)
     */
    private function generaQr(string $aziendaSlug, string $catalogoSlug, string $baseUrl): string
    {
        $qrUrl   = $baseUrl . 'public/catalogo.php?a=' . $aziendaSlug . '&c=' . $catalogoSlug;
        $name    = $catalogoSlug . '_' . time() . '.png';
        $relPath = 'uploads/qr/' . $name;

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
        $writer->write($qrCode)->saveToFile($this->uploadsDir . '/qr/' . $name);

        return $relPath;
    }

    /**
     * Cancella un file in modo SICURO: solo se è realmente dentro uploads/.
     * Difesa contro path traversal (identica a quella di AziendaService).
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
}