<?php
/*
 * Tutorial di benvenuto: un giro guidato di tutte le schede del sito, mostrato al primo accesso
 * (users.tour_done = 0) e riapribile dal pulsante "?" in alto (index.php?tour=1).
 * Qui c'è solo il contenuto; il disegno è in assets/app.js (sezione "tutorial") e assets/style.css.
 */

/** true se al giocatore va mostrato il tutorial in questa pagina. */
function tour_wanted(?array $u): bool
{
    return $u !== null && (empty($u['tour_done']) || isset($_GET['tour']));
}

/**
 * Passi del tutorial. 'sel' è il selettore CSS dell'elemento da evidenziare (null = scheda al centro).
 * @return array<int, array{sel: ?string, icon: string, title: string, text: string, bullets: string[]}>
 */
function tour_steps(array $u): array
{
    $name = trim((string) ($u['player_name'] ?: $u['username']));
    $steps = [
        [
            'sel' => null, 'icon' => 'ball-football',
            'title' => 'Benvenuto, ' . $name . '!',
            'text' => 'Ti faccio fare un giro veloce (meno di un minuto) delle schede del sito, così sai cosa puoi fare.',
            'bullets' => ['Puoi saltare quando vuoi.', 'Lo rivedi in qualsiasi momento dal pulsante «?» in alto.'],
        ],
        [
            'sel' => '.nav a[href="index.php"]', 'icon' => 'home',
            'title' => 'Home',
            'text' => 'È la scheda da cui partire ogni volta che entri.',
            'bullets' => [
                'La prossima partita: data, campo e quota, con il pulsante «Aggiungi a Google Calendar» per salvarla nel tuo calendario e ricevere la notifica.',
                'Con «Ci sono» e «Non ci sono» confermi o disdici la tua presenza. È la cosa più importante da fare!',
                'Le formazioni delle due squadre, quando sono state fatte.',
                'Il resoconto dell\'ultima partita: MVP, marcatori, assist e voto più alto.',
            ],
        ],
        [
            'sel' => '.nav a[href="matches.php"]', 'icon' => 'calendar-event',
            'title' => 'Partite',
            'text' => 'Tutte le partite, quelle in programma e quelle giocate. Aprendone una trovi:',
            'bullets' => [
                'Il tasto con il calendario, accanto a ogni partita in programma, la salva su Google Calendar.',
                'Chi ha confermato, chi è assente e chi deve ancora rispondere.',
                'Le squadre bilanciate (ruoli, voti e intesa tra chi ha già giocato insieme) e il campo con la posizione di ognuno.',
                'Il risultato, con gol e assist.',
                'Il voto ai compagni (da 1 a 10) e la scelta dell\'MVP, quando la partita è finita.',
            ],
        ],
        [
            'sel' => '.nav a[href="players.php"]', 'icon' => 'shirt',
            'title' => 'Rosa',
            'text' => 'Tutti i giocatori del gruppo, con ruolo e numero di maglia.',
            'bullets' => [
                'Ordinali per nome, numero, ruolo, rating o gol.',
                'Tocca un giocatore per le sue statistiche: partite, gol, assist, media voto, forma e andamento.',
                'Nella scheda trovi anche «Intesa e note»: con quali compagni rende di più (risultati, assist e gol quando giocano insieme).',
            ],
        ],
        [
            'sel' => '.nav a[href="standings.php"]', 'icon' => 'trophy',
            'title' => 'Classifica',
            'text' => 'Chi sta andando meglio? Qui lo vedi.',
            'bullets' => [
                'Punti: ' . POINTS_WIN . ' per la vittoria, ' . POINTS_DRAW . ' per il pareggio.',
                'Cambia l\'ordine: punti, marcatori, assist, MVP, media voto o percentuale di vittorie.',
            ],
        ],
        [
            'sel' => '.nav a[href="curiosities.php"]', 'icon' => 'bulb',
            'title' => 'Curiosità',
            'text' => 'Le curiosità sui giocatori, scritte da loro stessi o dall\'admin.',
            'bullets' => [
                'Ogni giocatore scrive le sue dalla propria scheda in Rosa (sezione «Curiosità»).',
                'Ogni tanto ne compare una anche in Home, sotto le informazioni sulle partite.',
            ],
        ],
    ];
    if (($u['role'] ?? '') === 'manager') {
        $steps[] = [
            'sel' => '.nav a[href="matches.php"]', 'icon' => 'clipboard-list',
            'title' => 'Manager: gestisci le partite',
            'text' => 'Come manager puoi creare e gestire le partite dei tuoi gruppi.',
            'bullets' => [
                'In «Partite» crei una nuova partita: i giocatori del gruppo ricevono la notifica.',
                'Dentro ogni partita modifichi presenze e squadre, inserisci il risultato e apri o chiudi le votazioni.',
                'Restano riservati all\'admin: account, gruppi, giocatori, pagamenti ed eliminazione delle partite.',
            ],
        ];
    }
    if (($u['role'] ?? '') === 'admin') {
        $steps[] = [
            'sel' => '.nav a[href="payments.php"]', 'icon' => 'cash',
            'title' => 'Pagamenti (solo admin)',
            'text' => 'Le quote a partita e i saldi.',
            'bullets' => ['Vedi chi ha pagato e chi no, e segni i pagamenti man mano.'],
        ];
        $steps[] = [
            'sel' => '.nav a[href="admin.php"]', 'icon' => 'settings',
            'title' => 'Admin (solo admin)',
            'text' => 'La gestione del gruppo.',
            'bullets' => [
                'Approvi le iscrizioni dei nuovi giocatori (il pallino rosso ti avvisa).',
                'Crei account, cambi ruoli e reimposti le password.',
                'Gestisci i gruppi (es. YBQ e FANTA): ogni giocatore vede solo giocatori e partite dei suoi gruppi.',
                'Crei le partite, generi le squadre e inserisci i risultati dalla scheda Partite.',
            ],
        ];
    }
    $steps[] = [
        'sel' => '.userbox a.me', 'icon' => 'user-circle',
        'title' => 'Il tuo profilo',
        'text' => 'Tocca il tuo nome per modificare i tuoi dati.',
        'bullets' => [
            'Foto, numero di maglia, ruolo, piede preferito e password.',
            'Email e password: da «Sicurezza» colleghi la tua email (per recuperare la password da solo) e cambi la password.',
            'Le notifiche: ti avvisano quando c\'è una partita a cui non hai risposto e quando si aprono o chiudono le votazioni.',
        ],
    ];
    $steps[] = [
        'sel' => null, 'icon' => 'circle-check',
        'title' => 'Tutto pronto!',
        'text' => 'Il primo passo: vai in Home e conferma la tua presenza alla prossima partita.',
        'bullets' => ['Dopo ogni partita ricordati di votare i compagni.', 'Per rivedere questo giro, tocca il pulsante «?» in alto.'],
    ];
    return $steps;
}
