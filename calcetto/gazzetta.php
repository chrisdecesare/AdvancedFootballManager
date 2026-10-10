<?php
/* La Gazzetta del mercoledì di una lega (lib/gazzetta.php): la stessa della Home, a tutta pagina. */
require __DIR__ . '/lib/bootstrap.php';
require_view();

$gid = int_get('g') ?: (int) gazzetta_group();
if (!$gid || is_guest() || (!is_admin() && !in_array($gid, allowed_group_ids(), true))) {
    http_response_code(404);
    layout_start('Gazzetta', 'home');
    echo '<div class="card"><h2>Gazzetta non trovata</h2><a href="index.php"><i class="ti ti-arrow-left"></i> Home</a></div>';
    layout_end();
    exit;
}
layout_start('Gazzetta', 'home');
?>
<a class="back" href="index.php"><i class="ti ti-arrow-left"></i> Home</a>
<?= gazzetta_html(gazzetta($gid), true) ?>
<?php
layout_end();
