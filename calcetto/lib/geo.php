<?php
/*
 * Geografia per la mappa delle leghe (come next-app/src/lib/geo.ts): slug della città e, se il server riesce a chiamare OpenStreetMap, le coordinate.
 * Altervista fa uscire le richieste solo verso una lista di siti: se Nominatim non risponde le coordinate restano vuote e le completa il sito Next
 * alla prima visita della pagina della città. La lega si vede comunque nell'elenco.
 */

function city_slug(string $city): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $city) ?: $city;
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $s));
    return substr(trim($s, '-'), 0, 80);
}

/** Coordinate di «zona, città» (poi della sola città): [lat, lng] oppure null. */
function geocode(string $area, string $city): ?array
{
    $try = function (string $q): ?array {
        if (!function_exists('curl_init')) {
            return null;
        }
        $c = curl_init('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=it&q=' . rawurlencode($q));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['User-Agent: CalcettoManager/1.0 (+https://calcettomanager.win)', 'Accept-Language: it']]);
        $body = curl_exec($c);
        $j = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($j) || !isset($j[0]['lat'], $j[0]['lon'])) {
            return null;
        }
        return [round((float) $j[0]['lat'], 6), round((float) $j[0]['lon'], 6)];
    };
    if (trim($area) !== '' && ($p = $try(trim($area) . ', ' . trim($city) . ', Italia'))) {
        return $p;
    }
    return $try(trim($city) . ', Italia');
}
