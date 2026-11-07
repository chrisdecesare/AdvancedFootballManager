<?php
/*
 * Avviso «c'è una nuova versione» per il sito PHP in pensione (al posto del vecchio redirect automatico).
 * Due tasti: verde = apre la nuova versione (con la stessa pagina e la stessa query, così i vecchi link di conferma email e recupero password
 * funzionano ancora), bianco = link alla nuova versione. Il verde porta anche le istruzioni per installarla come app (non esiste un file da scaricare).
 * Funzione pura (restituisce l'HTML) per poterla provare senza server.
 */

/** Indirizzo della pagina equivalente sul sito nuovo, con la query intatta. */
function pensione_destinazione(string $script, string $query): string
{
    $mappa = ['login.php' => '/login', 'register.php' => '/iscriviti', 'forgot.php' => '/password-dimenticata', 'reset.php' => '/nuova-password',
              'verify_email.php' => '/conferma-email', 'join.php' => '/entra'];
    return 'https://www.calcettomanager.win' . ($mappa[basename($script)] ?? '/') . ($query !== '' ? '?' . $query : '');
}

function pensione_pagina(string $verde, string $bianco): string
{
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $v = $e($verde);
    $b = $e($bianco);
    return <<<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>C'è una nuova versione · Calcetto Manager</title>
<style>
  :root { --paper:#fff6e0; --ink:#1f1a2e; --muted:#4a4360; --grass:#38d178; --grass-d:#23a85b; --yellow:#ffd23f; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px 16px; background:var(--paper);
         background-image:radial-gradient(rgba(31,26,46,.08) 1px, transparent 1px); background-size:18px 18px; color:var(--ink);
         font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  .card { width:100%; max-width:460px; background:#fff; border:3px solid var(--ink); border-radius:20px; box-shadow:6px 6px 0 var(--ink); padding:28px 24px 24px; text-align:center; }
  .badge { display:inline-block; background:var(--yellow); border:2px solid var(--ink); border-radius:999px; padding:4px 12px; font-weight:800; font-size:.8rem; transform:rotate(-2deg); }
  h1 { font-size:1.7rem; line-height:1.15; margin:14px 0 10px; }
  p { margin:0 0 12px; line-height:1.5; color:var(--muted); }
  .btn { display:block; width:100%; margin-top:12px; padding:14px 16px; border:3px solid var(--ink); border-radius:14px; font-weight:800; font-size:1.05rem;
         text-decoration:none; color:var(--ink); box-shadow:3px 3px 0 var(--ink); transition:transform .15s, box-shadow .15s; }
  .btn:active { transform:translate(2px,2px); box-shadow:1px 1px 0 var(--ink); }
  .btn-verde { background:var(--grass); }
  .btn-verde:hover { background:var(--grass-d); }
  .btn-bianco { background:#fff; }
  .come { margin-top:20px; text-align:left; font-size:.92rem; border-top:2px dashed rgba(31,26,46,.25); padding-top:14px; }
  .come b { color:var(--ink); }
  .come ul { margin:6px 0 0; padding-left:20px; color:var(--muted); }
  .come li { margin:4px 0; }
  .piccolo { margin-top:16px; font-size:.82rem; }
  @media (prefers-reduced-motion: reduce) { .btn { transition:none; } }
</style>
</head>
<body>
<main class="card">
  <span class="badge">Novità</span>
  <h1>C'è una nuova versione di Calcetto Manager</h1>
  <p>Questo indirizzo è la versione vecchia e non si usa più. Il tuo account, la tua lega e i tuoi dati ci sono già tutti nella nuova versione: accedi con lo stesso username (o con la tua email) e la stessa password.</p>
  <a class="btn btn-verde" href="{$v}">Scarica la nuova versione</a>
  <a class="btn btn-bianco" href="{$b}">Vai alla nuova versione</a>
  <div class="come">
    <b>Come averla come app sul telefono</b>
    <ul>
      <li><b>Android (Chrome):</b> apri la nuova versione, tocca i tre puntini in alto e scegli «Installa app».</li>
      <li><b>iPhone (Safari):</b> apri la nuova versione, tocca Condividi e scegli «Aggiungi alla schermata Home».</li>
    </ul>
  </div>
  <p class="piccolo">Se qualcosa non funziona, scrivici a info@calcettomanager.win.</p>
</main>
</body>
</html>
HTML;
}
