<?php
/*
 * Calendario del "Daily access" (lib/daily.php): 30 giorni, una ricompensa per ogni giorno di accesso.
 * Si modifica a mano, una riga per giorno (giorno 1 = primo giorno della campagna):
 *   ['koin' => KOIN regalati, 'item' => chiave di un oggetto da sbloccare (o null), 'label' => testo mostrato]
 * L'oggetto, se c'è, è un premio ESCLUSIVO non acquistabile dal Negozio: la chiave dev'essere una di quelle definite in
 * reward_items() (lib/daily.php), così si vede addosso ma non si compra. Ai traguardi (5, 10, 14, 20, 25, 30) un oggetto;
 * negli altri giorni solo KOIN. PROPOSTA DA RIVEDERE: valori e oggetti sono una bozza, da correggere prima di pubblicare.
 */
return [
    1  => ['koin' => 20,  'item' => null,          'label' => '20 KOIN'],
    2  => ['koin' => 25,  'item' => null,          'label' => '25 KOIN'],
    3  => ['koin' => 30,  'item' => null,          'label' => '30 KOIN'],
    4  => ['koin' => 40,  'item' => null,          'label' => '40 KOIN'],
    5  => ['koin' => 50,  'item' => 'x_alloro',     'label' => '50 KOIN + Alloro d\'oro (esclusivo)'],
    6  => ['koin' => 40,  'item' => null,          'label' => '40 KOIN'],
    7  => ['koin' => 60,  'item' => null,          'label' => '60 KOIN · fine prima settimana'],
    8  => ['koin' => 40,  'item' => null,          'label' => '40 KOIN'],
    9  => ['koin' => 45,  'item' => null,          'label' => '45 KOIN'],
    10 => ['koin' => 75,  'item' => 'x_showtime',   'label' => '75 KOIN + Esultanza «Show del fortunato» (esclusiva)'],
    11 => ['koin' => 50,  'item' => null,          'label' => '50 KOIN'],
    12 => ['koin' => 55,  'item' => null,          'label' => '55 KOIN'],
    13 => ['koin' => 60,  'item' => null,          'label' => '60 KOIN'],
    14 => ['koin' => 100, 'item' => 'x_cilindro_v', 'label' => '100 KOIN + Cilindro dorato (esclusivo) · due settimane'],
    15 => ['koin' => 60,  'item' => null,          'label' => '60 KOIN'],
    16 => ['koin' => 65,  'item' => null,          'label' => '65 KOIN'],
    17 => ['koin' => 70,  'item' => null,          'label' => '70 KOIN'],
    18 => ['koin' => 80,  'item' => null,          'label' => '80 KOIN'],
    19 => ['koin' => 90,  'item' => null,          'label' => '90 KOIN'],
    20 => ['koin' => 150, 'item' => 'x_mago_stel',  'label' => '150 KOIN + Cappello stellato (esclusivo)'],
    21 => ['koin' => 80,  'item' => null,          'label' => '80 KOIN'],
    22 => ['koin' => 85,  'item' => null,          'label' => '85 KOIN'],
    23 => ['koin' => 90,  'item' => null,          'label' => '90 KOIN'],
    24 => ['koin' => 100, 'item' => null,          'label' => '100 KOIN'],
    25 => ['koin' => 200, 'item' => 'x_jackpot',    'label' => '200 KOIN + Esultanza «Jackpot» (esclusiva)'],
    26 => ['koin' => 100, 'item' => null,          'label' => '100 KOIN'],
    27 => ['koin' => 110, 'item' => null,          'label' => '110 KOIN'],
    28 => ['koin' => 120, 'item' => null,          'label' => '120 KOIN'],
    29 => ['koin' => 150, 'item' => null,          'label' => '150 KOIN'],
    30 => ['koin' => 500, 'item' => 'x_corona_re',  'label' => '500 KOIN + Corona del Re (esclusiva) · gran premio'],
];
