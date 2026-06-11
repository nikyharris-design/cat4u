<?php

use PHPUnit\Framework\TestCase;

// Carichiamo la funzione REALE da config/helpers.php, così il test verifica
// esattamente il codice che gira in produzione (non una copia che potrebbe
// divergere col tempo). helpers.php contiene solo funzioni pure, senza
// dipendenze da $pdo o sessione, quindi è sicuro includerlo da solo.
require_once __DIR__ . '/../config/helpers.php';

class MakeSlugTest extends TestCase
{
    public function test_trasforma_in_minuscolo(): void
    {
        $this->assertEquals('ciao', make_slug('CIAO'));
    }

    public function test_sostituisce_spazi_con_trattini(): void
    {
        $this->assertEquals('buon-giorno', make_slug('Buon Giorno'));
    }

    public function test_rimuove_accenti(): void
    {
        $this->assertEquals('caffe-te', make_slug('Càffè & Té'));
    }

    public function test_niente_trattini_ai_bordi(): void
    {
        $this->assertEquals('prova', make_slug('  Prova!  '));
    }
}