<?php
// Prova temporanea: il proxy di Altervista lascia passare i Worker di Cloudflare (*.workers.dev)? Si cancella subito dopo.
header('Content-Type: text/plain');
foreach (['https://calcetto-cron.chris-de-cesaree.workers.dev/', 'https://workers.dev/'] as $u) {
    $t = microtime(true);
    $c = curl_init($u);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $b = curl_exec($c);
    printf("%s -> HTTP %s %s %.0f ms | %s\n", $u, curl_getinfo($c, CURLINFO_HTTP_CODE), curl_error($c), (microtime(true) - $t) * 1000, substr(preg_replace('/\s+/', ' ', (string) $b), 0, 80));
}
