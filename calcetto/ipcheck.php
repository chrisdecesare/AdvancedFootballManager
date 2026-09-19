<?php
// TEMPORANEO: mostra quale indirizzo IP vede PHP dietro la cache. Da cancellare subito.
header('Content-Type: text/plain'); header('Cache-Control: no-store');
echo 'REMOTE_ADDR=', $_SERVER['REMOTE_ADDR'] ?? '-', "\n";
echo 'X-Forwarded-For=', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '-', "\n";
echo 'X-Real-IP=', $_SERVER['HTTP_X_REAL_IP'] ?? '-', "\n";
echo 'HTTPS=', $_SERVER['HTTPS'] ?? '-', ' X-Forwarded-Proto=', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '-', "\n";
