<?php
/**
 * Plugin Name: Calcetto Manager - home
 * Description: L'indirizzo principale del sito porta al gestionale in wp-content/calcetto/.
 *
 * Redirect temporaneo (302) solo per la home esatta ("/", senza parametri): il resto di
 * WordPress (wp-admin, wp-login.php, articoli, API) continua a funzionare come prima.
 * Per tornare al blog basta cancellare questo file da wp-content/mu-plugins/.
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('template_redirect', function () {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
    if ($path === '/' && $query === '' && ($method === 'GET' || $method === 'HEAD')) {
        wp_safe_redirect(home_url('/wp-content/calcetto/'), 302);
        exit;
    }
});
