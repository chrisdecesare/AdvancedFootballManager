<?php
// TEMPORANEO: elenca i NOMI dei cookie che arrivano davvero a PHP (mai i valori). Da cancellare.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'metodo: ', $_SERVER['REQUEST_METHOD'] ?? '-', "\n";
echo 'nomi dei cookie ricevuti: ', $_COOKIE ? implode(', ', array_keys($_COOKIE)) : '(nessuno)', "\n";
