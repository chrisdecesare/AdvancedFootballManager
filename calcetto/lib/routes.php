<?php
/*
 * Indirizzi leggibili: /avatar invece di avatar.php, /partita-12 invece di match.php?id=12.
 *
 * Come funziona:
 *  1. .htaccess traduce l'indirizzo leggibile nel file PHP vero (riscrittura interna: il browser non lo vede) e segna la richiesta
 *     con PRETTY_URLS=1, così qui si sa che la riscrittura funziona su questo server.
 *  2. pretty_boot(): chi apre un vecchio indirizzo .php (link salvati, email, notifiche) viene mandato a quello leggibile con un 301.
 *  3. pretty_filter(): prima di spedire la pagina, tutti i link (href, action) verso le pagine qui sotto diventano leggibili, così
 *     cliccando non c'è nemmeno il 301. Anche redirect() (lib/helpers.php) passa da pretty_url().
 *
 * Tutti gli indirizzi restano a un solo livello (partita-12, non partita/12): così i percorsi relativi del sito (assets/..., push.php,
 * i riferimenti interni degli SVG dell'Avatar) funzionano senza cambiare nulla.
 * Se il server non riscrive (manca mod_rewrite, sviluppo in locale con php -S) PRETTY_URLS non c'è e il sito usa i .php come prima.
 *
 * ATTENZIONE: le righe qui e le RewriteRule in .htaccess vanno tenute uguali.
 */

/** File PHP (senza .php) => [indirizzo leggibile, parametro numerico che finisce nel percorso («-12») oppure null]. */
function pretty_routes(): array
{
    return [
        'index' => ['', null],
        'matches' => ['partite', null],
        'match' => ['partita', 'id'],
        'players' => ['rosa', null],
        'player' => ['giocatore', 'id'],
        'player_edit' => ['modifica-giocatore', 'id'],
        'standings' => ['classifica', null],
        'bets' => ['scommesse', null],
        'shop' => ['negozio', null],
        'avatar' => ['avatar', null],
        'fanta' => ['fantacalcio', null],
        'jersey_creator' => ['crea-maglia', null],
        'drops' => ['uscite', null],
        'curiosities' => ['curiosita', null],
        'guess' => ['indovina', null],
        'profile' => ['profilo', null],
        'account' => ['sicurezza', null],
        'payments' => ['pagamenti', null],
        'league' => ['lega', 'id'],
        'create_league' => ['crea-lega', null],
        'join' => ['entra', null],
        'admin' => ['admin', null],
        'platform' => ['piattaforma', null],
        'login' => ['accedi', null],
        'register' => ['iscriviti', null],
        'forgot' => ['password-dimenticata', null],
        'reset' => ['nuova-password', null],
        'confirm' => ['conferma-password', null],
        'verify_email' => ['conferma-email', null],
    ];
}

/** Il server riscrive gli indirizzi? (lo segnala .htaccess; senza, tutto resta com'era) */
function pretty_enabled(): bool
{
    static $on = null;
    return $on ??= ($_SERVER['PRETTY_URLS'] ?? $_SERVER['REDIRECT_PRETTY_URLS'] ?? getenv('PRETTY_URLS')) === '1';
}

/**
 * Da «match.php?id=12&x=1#voti» a «partita-12?x=1#voti». Tutto il resto (indirizzi esterni, file, pagine non in elenco) resta uguale.
 */
function pretty_url(string $url): string
{
    if (!pretty_enabled() || !preg_match('/^([a-z_]+)\.php(\?[^#]*)?(#.*)?$/', $url, $m) || !isset(pretty_routes()[$m[1]])) {
        return $url;
    }
    [$slug, $param] = pretty_routes()[$m[1]];
    $query = ltrim($m[2] ?? '', '?');
    $frag = $m[3] ?? '';
    if ($param !== null) {
        // il parametro (se numerico) passa nel percorso; gli altri restano nella query, nello stesso ordine
        $parts = $query === '' ? [] : preg_split('/&(?:amp;)?/', $query);
        foreach ($parts as $i => $p) {
            if (preg_match('/^' . $param . '=(\d+)$/', $p, $pm)) {
                $slug .= '-' . $pm[1];
                unset($parts[$i]);
                $query = implode(str_contains($m[2], '&amp;') ? '&amp;' : '&', $parts);
                break;
            }
        }
    }
    if ($slug === '') {
        $slug = './';   // la Home: la cartella del sito
    }
    return $slug . ($query !== '' ? '?' . $query : '') . $frag;
}

/** Chi apre un vecchio indirizzo .php con GET va a quello leggibile (301). I POST no: li gestisce la pagina come sempre. */
function pretty_boot(): void
{
    if (!pretty_enabled() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        return;
    }
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!preg_match('#/([a-z_]+)\.php$#', $path, $m) || !isset(pretty_routes()[$m[1]])) {
        return;
    }
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    $to = pretty_url($m[1] . '.php' . ($qs !== '' ? '?' . $qs : ''));
    if ($to !== $m[1] . '.php' . ($qs !== '' ? '?' . $qs : '')) {
        header('Location: ' . $to, true, 301);
        exit;
    }
}

/** Filtro sulla pagina in uscita: i link href/action verso le pagine dell'elenco diventano leggibili. Solo HTML. */
function pretty_filter(string $html): string
{
    foreach (headers_list() as $h) {
        if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
            return $html;   // JSON, immagini, file: non si toccano
        }
    }
    return preg_replace_callback('/\b(href|action)="([a-z_]+\.php(?:\?[^"#]*)?(?:#[^"]*)?)"/',
        fn($m) => $m[1] . '="' . pretty_url($m[2]) . '"', $html) ?? $html;
}
