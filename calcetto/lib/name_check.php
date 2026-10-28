<?php
/*
 * Controllo dei nomi scelti dalle persone e mostrati sul sito (leghe): niente parolacce, insulti e simboli d'odio.
 * Ignora maiuscole, accenti, punteggiatura e le sostituzioni più comuni (4→a, 3→e, 0→o, 1→i, 5→s, @→a, $→s).
 * Radici forti cercate dentro il nome senza spazi e parole intere per i termini che in una parola più lunga vorrebbero dire altro.
 * Lo stesso elenco è nel sito Next (next-app/src/lib/name-check.ts): tienili uguali.
 */
const NAME_STEMS = ['cazz', 'minchi', 'vaffanc', 'stronz', 'merd', 'puttan', 'mignott', 'sborr', 'pompin', 'bocchin', 'rottinc', 'inculat', 'inculo', 'sgommat',
    'fuck', 'shit', 'cunt', 'bitch', 'nigg', 'whore', 'slut', 'fagg', 'hitler', 'nazi', 'kkk'];
const NAME_WORDS = ['figa', 'fica', 'culo', 'culi', 'troia', 'troie', 'zoccola', 'zoccole', 'ricchione', 'ricchioni', 'frocio', 'froci', 'negro', 'mongoloide', 'pene',
    'dick', 'cock', 'pussy', 'fag', 'sega', 'seghe', 'coglione', 'coglioni', 'deficiente'];

function name_squash(string $s): string
{
    return preg_replace_callback('/(.)\1+/', fn ($m) => $m[1], $s);
}

function offensive_name(string $name): bool
{
    $s = mb_strtolower($name);
    $s = strtr($s, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    $s = strtr($s, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's', '!' => 'i']);
    $plain = preg_replace('/[^a-z]/', '', $s);
    $collapsed = name_squash($plain);
    foreach (NAME_STEMS as $stem) {
        if (str_contains($plain, $stem) || str_contains($collapsed, name_squash($stem))) {
            return true;
        }
    }
    foreach (preg_split('/[^a-z]+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        if (in_array($t, NAME_WORDS, true) || in_array(name_squash($t), NAME_WORDS, true)) {
            return true;
        }
    }
    return false;
}

const OFFENSIVE_NAME_MESSAGE = 'Questo nome non è consentito: scegline un altro, senza parolacce o offese.';
