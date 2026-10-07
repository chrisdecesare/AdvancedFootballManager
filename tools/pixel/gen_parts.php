<?php
/*
 * Genera calcetto/lib/avatar_pixel_parts.php: le parti dell'Avatar in pixel art che hanno linee in diagonale (braccia e gambe di
 * fronte, corpi di profilo). Ogni parte è descritta da forme semplici (capsule e poligoni) in coordinate dello sprite (x da -8 a 40,
 * y da -8 a 56, terreno alla riga 55); qui si rasterizzano a 1 pixel e si aggiunge il contorno.
 * Uso: php tools/pixel/gen_parts.php  (poi si ricontrollano i disegni con l'anteprima).
 *
 * Lettere come in lib/avatar_pixel.php. I "gruppi" servono al contorno: dove una forma disegnata dopo tocca una forma di un gruppo
 * lontano (>= 10) compare una riga di contorno, così un braccio davanti al busto resta staccato.
 */

const G_OX = 8, G_OY = 8, G_W = 48, G_H = 64;

final class Canvas
{
    public array $g;
    public function __construct() { $this->g = array_fill(0, G_H, array_fill(0, G_W, null)); }

    private function each(callable $in, string $L, int $grp): void
    {
        for ($y = 0; $y < G_H; $y++) {
            for ($x = 0; $x < G_W; $x++) {
                if ($in($x - G_OX + .5, $y - G_OY + .5)) {
                    $this->g[$y][$x] = [$L, $grp];
                }
            }
        }
    }
    public function cap(string $L, float $x1, float $y1, float $x2, float $y2, float $r, int $grp): self
    {
        $this->each(function ($px, $py) use ($x1, $y1, $x2, $y2, $r) {
            $dx = $x2 - $x1; $dy = $y2 - $y1; $l = $dx * $dx + $dy * $dy;
            $t = $l ? max(0, min(1, (($px - $x1) * $dx + ($py - $y1) * $dy) / $l)) : 0;
            return ($px - $x1 - $t * $dx) ** 2 + ($py - $y1 - $t * $dy) ** 2 <= $r * $r;
        }, $L, $grp);
        return $this;
    }
    public function poly(string $L, array $pts, int $grp): self
    {
        $this->each(function ($px, $py) use ($pts) {
            $in = false; $n = count($pts);
            for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                [$xi, $yi] = $pts[$i]; [$xj, $yj] = $pts[$j];
                if ((($yi > $py) != ($yj > $py)) && ($px < ($xj - $xi) * ($py - $yi) / ($yj - $yi) + $xi)) $in = !$in;
            }
            return $in;
        }, $L, $grp);
        return $this;
    }
    /** Righe con il contorno, ritagliate: [x0, y0, righe] in coordinate dello sprite (null se vuota). */
    public function rows(?callable $keep = null): ?array
    {
        $g = $this->g;
        if ($keep) {
            foreach ($g as $y => $r) foreach ($r as $x => $c) if ($c !== null && !$keep($c[1])) $g[$y][$x] = null;
        }
        $out = [];
        for ($y = 0; $y < G_H; $y++) {
            $row = '';
            for ($x = 0; $x < G_W; $x++) {
                $c = $g[$y][$x];
                $nb = [];
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$a, $b]) $nb[] = $g[$y + $b][$x + $a] ?? null;
                if ($c === null) {
                    $row .= array_filter($nb) ? 'o' : '.';
                    continue;
                }
                $edge = false;
                foreach ($nb as $q) if ($q !== null && $q[1] < $c[1] && $c[1] - $q[1] >= 10) $edge = true;
                $row .= $edge ? 'o' : $c[0];
            }
            $out[] = $row;
        }
        $ys = array_keys(array_filter($out, fn($r) => trim($r, '.') !== ''));
        if (!$ys) return null;
        $x0 = G_W; $x1 = 0;
        foreach ($ys as $y) { $x0 = min($x0, strspn($out[$y], '.')); $x1 = max($x1, strlen(rtrim($out[$y], '.'))); }
        $rows = [];
        for ($y = min($ys); $y <= max($ys); $y++) $rows[] = rtrim(substr($out[$y], $x0, $x1 - $x0), '.');
        return [$x0 - G_OX, min($ys) - G_OY, $rows];
    }
}

$parts = [];
$add = function (string $name, Canvas $c, bool $split = false) use (&$parts) {
    if ($split) {
        $parts[$name] = $c->rows(fn($g) => $g < 50);
        $over = $c->rows(fn($g) => $g >= 50);
        if ($over) $parts[$name . '.over'] = $over;
    } else {
        $parts[$name] = $c->rows();
    }
};

/* ---------------------------------------------------------------- braccia di fronte (il sinistro dello schermo) */

// spalla, gomito, mano; la manica copre la prima metà del braccio. $f = dito (verso), $open = mano aperta più larga
$arm = function (array $E, array $H, ?array $finger = null, float $hand = 2.2) {
    $S = [10, 27];
    $c = new Canvas();
    $m = [$S[0] + ($E[0] - $S[0]) * .5, $S[1] + ($E[1] - $S[1]) * .5];
    $k = [$S[0] + ($E[0] - $S[0]) * .62, $S[1] + ($E[1] - $S[1]) * .62];
    $c->cap('s', $S[0], $S[1], $E[0], $E[1], 2.0, 50)->cap('s', $E[0], $E[1], $H[0], $H[1], 1.9, 50);
    $c->cap('k', $S[0], $S[1], $k[0], $k[1], 2.7, 50)->cap('a', $S[0], $S[1], $m[0], $m[1], 2.7, 50);
    $c->cap('s', $H[0], $H[1], $H[0], $H[1], $hand, 50);
    if ($finger) $c->cap('s', $H[0], $H[1], $finger[0], $finger[1], .75, 50);
    return $c;
};
$arms = [
    'sky' => [[9, 19], [8.5, 10.5], [8.5, 5.5]],
    'out' => [[3, 27.5], [-3.5, 27.5], null, 2.4],
    'out_low' => [[4.5, 32], [0, 36.5], null, 2.4],
    'flex' => [[3, 28], [3.5, 19.5]],
    'wave_a' => [[3.5, 27], [1.5, 18.5], null, 2.5],
    'wave_b' => [[3.5, 27], [5.5, 18.5], null, 2.5],
    'point' => [[4, 26], [-1.5, 24], [-6, 23]],
    'hip' => [[4, 32], [8.5, 37.5]],
    'chest' => [[8, 34], [13, 31]],
    'cross' => [[8, 35], [19, 33]],
    'heart' => [[6.5, 32.5], [14, 29]],
    'ear' => [[3.5, 25], [5.5, 17.5], null, 2.4],
    'ear_b' => [[3.5, 25.5], [6, 18.5], null, 2.4],
    'mouth' => [[6, 33], [13.5, 21.5]],
    'salute' => [[3, 24], [9, 15]],
    'cradle' => [[8, 35], [15, 36.5]],
    'selfie' => [[4, 26], [4, 16]],
    'robot_a' => [[3, 27.5], [3, 35.5]],
    'fist_low' => [[7, 35], [6, 29]],
];
foreach ($arms as $n => $a) $add('arm_' . $n, $arm(...$a));

/* ---------------------------------------------------------------- gambe di fronte (la sinistra dello schermo) */

$leg = function (array $hip, array $knee, array $ankle, array $toe, float $sock = .35) {
    $c = new Canvas();
    $c->cap('s', $hip[0], $hip[1], $knee[0], $knee[1], 2.3, 10);
    $band = [$knee[0] + ($ankle[0] - $knee[0]) * .15, $knee[1] + ($ankle[1] - $knee[1]) * .15];
    $c->cap('c', $band[0], $band[1], $ankle[0], $ankle[1], 2.2, 11)->cap('k', $knee[0] + ($ankle[0] - $knee[0]) * .1, $knee[1] + ($ankle[1] - $knee[1]) * .1, $band[0], $band[1], 2.2, 11);
    $c->cap('f', $ankle[0], $ankle[1] + .8, $toe[0], $toe[1], 1.5, 12);
    return $c;
};
$add('leg_wide', $leg([12, 42], [10, 46.5], [9, 52], [8, 53.8]));
$add('leg_jump', $leg([12.5, 42], [9.5, 46], [11, 50.5], [12.5, 51.8]));
$add('leg_spread', $leg([12, 42], [8, 47], [5, 52], [3, 54.3]));
$add('leg_step', $leg([12.5, 42], [12, 45.5], [12.5, 48.5], [13.5, 50]));
$add('leg_crouch', $leg([12.5, 47], [8.5, 50], [10, 52.5], [11.5, 53.8]));
$c = new Canvas();   // in ginocchio: la coscia scende verso chi guarda, il ginocchio a terra
$c->cap('s', 12.5, 47.5, 12.5, 53.3, 2.5, 10)->cap('c', 10, 54.3, 9, 54.5, 1.2, 9);
$add('leg_kneel', $c);
$c = new Canvas();   // seduto a gambe incrociate
$c->cap('c', 14, 53, 6.5, 53.3, 2.2, 11)->cap('s', 6, 51.8, 6, 51.8, 2.5, 12)->cap('f', 14.5, 54, 16, 54, 1.5, 13);
$add('leg_lotus', $c);

/* ---------------------------------------------------------------- corpi di profilo (verso destra) */

$torsoStand = [[12, 25.5], [21, 25.5], [21, 37.5], [12.5, 37.5]];
$side = [];
$side['run1'] = function (Canvas $c) {
    $c->cap('A', 19, 30, 22, 33, 2.3, 0)->cap('S', 22, 33, 25, 30, 1.7, 1)->cap('S', 26, 29, 26, 29, 2, 1);
    $c->cap('P', 15, 42, 12, 47, 2.8, 5)->cap('C', 11.5, 48, 7, 51, 2, 6)->cap('F', 6, 51.5, 4.5, 53, 1.3, 7);
    $c->poly('j', [[13, 27.5], [22, 27.5], [21, 39], [12, 39]], 20);
    $c->poly('p', [[11.5, 38.5], [20.5, 38.5], [21, 44], [12, 44]], 22)->cap('p', 17, 42, 21.5, 45.5, 3.1, 22);
    $c->cap('s', 22.5, 46.5, 23, 47.5, 2.5, 30)->cap('c', 23, 48.5, 22, 53, 2.2, 31)->cap('f', 21.5, 53.8, 26, 53.8, 1.4, 40);
    $c->cap('a', 17, 30, 14, 33, 2.6, 50)->cap('s', 14, 33, 12, 38, 1.9, 51)->cap('s', 12, 39.5, 12, 39.5, 2.2, 51);
};
$side['run2'] = function (Canvas $c) {
    $c->cap('A', 18, 30, 15, 33, 2.3, 0)->cap('S', 15, 33, 13, 38, 1.7, 1)->cap('S', 13, 39.5, 13, 39.5, 2, 1);
    $c->cap('P', 16, 42, 20.5, 45.5, 2.8, 5)->cap('C', 21, 47.5, 20.5, 52, 2, 6)->cap('F', 20, 53.8, 24.5, 53.8, 1.3, 7);
    $c->poly('j', [[13, 27.5], [22, 27.5], [21, 39], [12, 39]], 20);
    $c->poly('p', [[11.5, 38.5], [20.5, 38.5], [21, 44], [12, 44]], 22)->cap('p', 16, 42, 13, 46.5, 3.1, 22);
    $c->cap('s', 12.5, 47.5, 12, 48.5, 2.4, 30)->cap('c', 11.5, 49.5, 7.5, 52, 2.1, 31)->cap('f', 6.5, 52.5, 5, 54, 1.4, 40);
    $c->cap('a', 18.5, 30, 21.5, 33, 2.6, 50)->cap('s', 21.5, 33, 25, 30, 1.9, 51)->cap('s', 26, 29, 26, 29, 2.2, 51);
};
$side['kneel'] = function (Canvas $c) {
    $c->cap('A', 17.5, 36, 19, 31, 2.3, 0)->cap('S', 19, 31, 21, 20, 1.7, 1)->cap('S', 21.3, 17.8, 21.3, 17.8, 2, 1);
    $c->cap('P', 16, 48, 20, 52, 2.8, 5)->cap('C', 20, 54, 11, 54.4, 1.9, 6)->cap('F', 9.5, 54.2, 8, 53.3, 1.3, 7);
    $c->poly('j', [[12, 33.5], [21, 33.5], [21, 45.5], [12.5, 45.5]], 20);
    $c->poly('p', [[12, 45], [21, 45], [21.5, 49.5], [12, 49.5]], 22)->cap('p', 17, 48, 21, 51.5, 3, 22);
    $c->cap('s', 22, 52.5, 22, 52.5, 2.4, 30)->cap('c', 21, 54.2, 12.5, 54.6, 2, 31)->cap('f', 11, 54.3, 9, 53.3, 1.4, 40);
    $c->cap('a', 15.5, 36, 14.5, 31.5, 2.6, 50)->cap('s', 14.5, 31.5, 12, 20, 1.9, 51)->cap('s', 11.5, 17.5, 11.5, 17.5, 2.2, 51);
};
$side['dive'] = function (Canvas $c) {
    $c->cap('A', 17, 47.5, 24, 45, 2.3, 0)->cap('S', 24, 45, 32, 43, 1.7, 1)->cap('S', 33.5, 42.5, 33.5, 42.5, 2, 1);
    $c->cap('P', -1, 50, -6, 50, 2.8, 5)->cap('C', -6.5, 49, -5, 43, 2, 6)->cap('F', -5, 42, -2.5, 41, 1.3, 7);
    $c->poly('j', [[5, 46.5], [19, 46.5], [19, 55], [5, 55]], 20);
    $c->poly('p', [[-2, 47.5], [6, 47], [6, 55], [-2, 55]], 22)->cap('p', 0, 51.5, -5, 52, 3, 22);
    $c->cap('s', -6, 52.5, -6, 52.5, 2.4, 30)->cap('c', -7, 51, -5, 45, 2.1, 31)->cap('f', -5, 43.8, -2.2, 42.8, 1.4, 40);
    $c->cap('a', 17, 50, 23, 49, 2.6, 50)->cap('s', 23, 49, 31, 47.5, 1.9, 51)->cap('s', 33, 47, 33, 47, 2.2, 51);
};
$side['gun'] = function (Canvas $c) use ($torsoStand) {
    $c->cap('A', 19, 29, 21, 33, 2.3, 0)->cap('S', 21, 33, 27, 34.5, 1.7, 1)->cap('S', 28.3, 34.5, 28.3, 34.5, 2, 1);
    $c->cap('P', 15, 42, 12, 47, 2.8, 5)->cap('C', 11.5, 48, 10, 52.5, 2, 6)->cap('F', 8.5, 53.6, 13, 53.6, 1.3, 7);
    $c->poly('j', $torsoStand, 20);
    $c->poly('p', [[12, 37], [21, 37], [21.5, 43], [12.5, 43]], 22)->cap('p', 17, 42, 20, 46.5, 3.1, 22);
    $c->cap('s', 20.5, 47.5, 21, 48.5, 2.5, 30)->cap('c', 21, 49.5, 21.5, 52.5, 2.2, 31)->cap('f', 20.5, 53.6, 25, 53.6, 1.4, 40);
    $c->cap('a', 17, 29, 18.5, 34, 2.6, 50)->cap('s', 18.5, 34, 25, 36.5, 1.9, 51)->cap('s', 26.5, 36.5, 26.5, 36.5, 2.2, 51);
};
$side['archer'] = function (Canvas $c) use ($torsoStand) {
    $c->cap('A', 19, 28.5, 24, 27.8, 2.3, 0)->cap('S', 24, 27.8, 30, 27.3, 1.7, 1)->cap('S', 31.5, 27.3, 31.5, 27.3, 2, 1);
    $c->cap('P', 15, 42, 12, 47, 2.8, 5)->cap('C', 11.5, 48, 10, 52.5, 2, 6)->cap('F', 8.5, 53.6, 13, 53.6, 1.3, 7);
    $c->poly('j', $torsoStand, 20);
    $c->poly('p', [[12, 37], [21, 37], [21.5, 43], [12.5, 43]], 22)->cap('p', 17, 42, 20, 46.5, 3.1, 22);
    $c->cap('s', 20.5, 47.5, 21, 48.5, 2.5, 30)->cap('c', 21, 49.5, 21.5, 52.5, 2.2, 31)->cap('f', 20.5, 53.6, 25, 53.6, 1.4, 40);
    $c->cap('a', 16.5, 29, 12.5, 28.8, 2.6, 50)->cap('s', 12.5, 28.8, 9.5, 28.5, 1.9, 51)->cap('s', 9.5, 28.5, 17, 25.5, 1.9, 51)->cap('s', 18, 25.3, 18, 25.3, 2.1, 51);
};
foreach ($side as $n => $fn) { $c = new Canvas(); $fn($c); $add('side_' . $n, $c, true); }

// le pose del backflip, scritte con cap($g, ...) / poly($g, ...) come nei primi disegni (tools/pixel/side/*.php)
function cap(Canvas $g, string $L, float $x1, float $y1, float $x2, float $y2, float $r, int $grp): void { $g->cap($L, $x1, $y1, $x2, $y2, $r, $grp); }
function poly(Canvas $g, string $L, array $pts, int $grp): void { $g->poly($L, $pts, $grp); }
foreach (glob(__DIR__ . '/side/*.php') as $f) {
    $g = new Canvas();
    (function () use (&$g, $f) { include $f; })();
    $add('side_' . basename($f, '.php'), $g, true);
}

/* ---------------------------------------------------------------- scrittura */

$php = "<?php\n/*\n * Generato da tools/pixel/gen_parts.php: non modificare a mano (cambia le forme lì e rigenera).\n"
    . " * Parti dell'Avatar in pixel art: [x, y, righe] in coordinate dello sprite (lettere come in lib/avatar_pixel.php).\n */\n\n"
    . "function px_parts(): array\n{\n    static \$p = [\n";
foreach ($parts as $n => [$x, $y, $rows]) {
    $php .= "        '" . $n . "' => [" . $x . ', ' . $y . ", [\n";
    foreach ($rows as $r) $php .= "            '" . $r . "',\n";
    $php .= "        ]],\n";
}
$php .= "    ];\n    return \$p;\n}\n";
file_put_contents(__DIR__ . '/../../calcetto/lib/avatar_pixel_parts.php', $php);
echo count($parts) . " parti\n";
