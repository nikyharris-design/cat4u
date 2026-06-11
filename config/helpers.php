<?php
/**
 * ==========================================================================
 * HELPERS.PHP — Funzioni di utilità condivise
 * ==========================================================================
 *
 * Raccolta di piccole funzioni riutilizzabili in più pagine. Viene inclusa da
 * bootstrap.php, quindi è disponibile ovunque senza require manuali.
 *
 * Qui confluiscono funzioni che prima erano duplicate in più file (es. la
 * generazione dello slug, che esisteva in tre varianti quasi identiche in
 * generi.php, cataloghi.php e aziende.php).
 */

/**
 * Trasforma una stringa in uno "slug" usabile in URL.
 * Es: "Càffè & Té" → "caffe-te".
 *
 * Passaggi: minuscolo + trim → sostituzione accenti → ogni gruppo di caratteri
 * non alfanumerici diventa un trattino → si tolgono i trattini ai bordi.
 *
 * @param  string $str testo di partenza
 * @return string slug risultante
 */
function make_slug(string $str): string {
    $str = mb_strtolower(trim($str)); // minuscolo (mb_ = sicuro con UTF-8)
    $str = strtr($str, ['à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
    $str = preg_replace('/[^a-z0-9]+/', '-', $str); // tutto il resto → "-"
    return trim($str, '-'); // niente trattini iniziali/finali
}