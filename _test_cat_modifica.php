<?php
require __DIR__ . '/config/bootstrap.php';

use App\Services\CatalogoService;
use App\Exceptions\ValidationException;
use App\Exceptions\NotFoundException;

$service = new CatalogoService($pdo, __DIR__ . '/uploads');

$az = $pdo->query("SELECT id FROM aziende ORDER BY id LIMIT 1")->fetch();
$aziendaId = (int)$az['id'];
$gen = $pdo->prepare("SELECT id FROM generi WHERE azienda_id = ? LIMIT 1");
$gen->execute([$aziendaId]);
$genereId = (string)$gen->fetchColumn();   // come dal form: STRINGA
if ($genereId === '' || $genereId === '0') { exit("L'azienda non ha generi.\n"); }

$slug = 'zzz-test-mod-' . time();
$pdfRel = 'uploads/pdf/' . $slug . '.pdf';
$qrRel  = 'uploads/qr/'  . $slug . '.png';
@file_put_contents(__DIR__.'/'.$pdfRel, '%PDF-finto');
@file_put_contents(__DIR__.'/'.$qrRel, 'finto');
$pdo->prepare(
    "INSERT INTO cataloghi (azienda_id, genere_id, titolo, pdf_path, slug, qr_code_path, is_active)
     VALUES (?, ?, 'ZZZ Titolo Originale', ?, ?, ?, 1)"
)->execute([$aziendaId, (int)$genereId, $pdfRel, $slug, $qrRel]);
$id = (int)$pdo->lastInsertId();
$noFile = ['name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => ''];
echo "Creato #$id, slug = $slug\n";

echo "=== Test 1: modifica SENZA nuovo PDF (slug/QR invariati) ===\n";
try {
    // titolo e genere_id come STRINGHE, esattamente come arriverebbero da $_POST.
    $service->modifica($id, ['titolo' => 'ZZZ Titolo MODIFICATO', 'genere_id' => $genereId], $noFile, $aziendaId);
    $dopo = $service->trova($id, $aziendaId);
    echo "  titolo aggiornato: ", ($dopo['titolo'] === 'ZZZ Titolo MODIFICATO' ? 'sì (corretto)' : 'NO!'), "\n";
    echo "  slug invariato:    ", ($dopo['slug'] === $slug ? 'sì (corretto)' : 'CAMBIATO!'), "\n";
    echo "  PDF invariato:     ", ($dopo['pdf_path'] === $pdfRel ? 'sì (corretto)' : 'CAMBIATO!'), "\n";
} catch (ValidationException $e) {
    // Mostriamo l'errore invece di lasciarlo esplodere nell'handler.
    echo "  ValidationException inattesa: ", implode(' | ', $e->getErrors()), "\n";
}

echo "=== Test 2: titolo vuoto -> ValidationException ===\n";
try {
    $service->modifica($id, ['titolo' => '', 'genere_id' => $genereId], $noFile, $aziendaId);
    echo "  NON dovrebbe arrivare qui\n";
} catch (ValidationException $e) {
    echo "  bloccato: ", implode(' | ', $e->getErrors()), "\n";
}

echo "=== Test 3: catalogo di un'altra azienda -> NotFoundException ===\n";
try {
    $service->modifica($id, ['titolo' => 'x', 'genere_id' => $genereId], $noFile, 999999);
    echo "  NON dovrebbe arrivare qui\n";
} catch (NotFoundException $e) {
    echo "  bloccato correttamente (404): ", $e->getMessage(), "\n";
}

echo "=== Pulizia ===\n";
$service->elimina($id, $aziendaId);
echo "  #$id rimosso.\n";