<?php
/**
 * Routeur de développement pour le serveur web intégré à PHP.
 *
 *     php -S 127.0.0.1:8000 -t public tools/serve.php
 *
 * Le serveur intégré ne lit pas les .htaccess : sans lui, toute URL arrive à
 * index.php sans le paramètre `url`, et l'application route tout vers le
 * tableau de bord. Ce fichier reproduit la seule règle qui compte —
 * « tout ce qui n'est pas un fichier existant part sur index.php?url=… » —
 * et fixe l'hôte pour que ROOT ne pointe pas ailleurs.
 *
 * À n'utiliser qu'en développement. En production, c'est Apache qui réécrit.
 */

// config/app.php construit ROOT avec SERVER_NAME, qui ne porte pas le port :
// les redirections partiraient sur http://127.0.0.1/kitchen/... c'est-à-dire
// le port 80. On rétablit le port ici.
if (!str_contains($_SERVER['SERVER_NAME'], ':')) {
    $_SERVER['SERVER_NAME'] .= ':' . ($_SERVER['SERVER_PORT'] ?? '8000');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// L'application préfixe ses URL par /kitchen (RewriteBase). En local on sert
// depuis la racine : on accepte les deux formes.
$path = preg_replace('#^/kitchen#', '', $path) ?: '/';

// Fichier réel : on le sert nous-mêmes. Rendre `false` ferait chercher le
// chemin d'origine — préfixe /kitchen compris — que le serveur intégré ne
// trouve pas sous -t public, et la page arriverait sans styles.
$file = __DIR__ . '/../public' . $path;
if ($path !== '/' && is_file($file)) {
    $types = [
        // Sans le type html, un fichier statique .html sous public/ arrive en
        // application/octet-stream : le navigateur le télécharge au lieu de
        // l'afficher, et une iframe qui le vise reste blanche.
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'map' => 'application/json',
        // Le manifeste doit arriver en application/manifest+json, sinon le
        // navigateur l'ignore et l'application ne s'installe pas.
        'webmanifest' => 'application/manifest+json',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($file);
    return true;
}

$_GET['url']            = ltrim($path, '/');
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/../public/index.php';
