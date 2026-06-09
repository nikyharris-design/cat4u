<?php

use PHPUnit\Framework\TestCase;

// Copia della funzione make_slug (presa da dashboard/generi.php) per poterla
// testare in isolamento, senza caricare l'intero file con le sue dipendenze.
function make_slug(string $str): string {
    $str = mb_strtolower(trim($str));
    $str = strtr($str, ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return trim($str, '-');
}

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