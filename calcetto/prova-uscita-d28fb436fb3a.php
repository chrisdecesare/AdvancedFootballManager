<?php
// Prova temporanea: Altervista riesce a uscire in HTTPS verso TiDB/Vercel/Stripe? Si cancella subito dopo.
header('Content-Type: text/plain');
$urls = [
    'https://calcettomanager.win/login',
    'https://http-gateway01.eu-central-1.prod.aws.tidbcloud.com/v1beta/sql',
    'https://api.stripe.com/v1/charges',
    'https://www.google.com/',
];
echo 'curl: ', function_exists('curl_init') ? 'si' : 'no', ' | allow_url_fopen: ', ini_get('allow_url_fopen'), "\n";
foreach ($urls as $u) {
    $t = microtime(true);
    $c = curl_init($u);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    if (strpos($u, '/sql') !== false) {
        curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"query":"SELECT 1"}']);
    }
    $b = curl_exec($c);
    printf("%s -> HTTP %s %s ip=%s %.0f ms | %s\n", $u, curl_getinfo($c, CURLINFO_HTTP_CODE), curl_error($c),
        curl_getinfo($c, CURLINFO_PRIMARY_IP), (microtime(true) - $t) * 1000, substr(preg_replace('/\s+/', ' ', (string) $b), 0, 100));
}
