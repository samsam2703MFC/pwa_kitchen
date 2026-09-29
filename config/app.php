<?php



define('ROOT',
    (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
        ? 'https://'
        : 'http://') . $_SERVER['SERVER_NAME'] . '/kitchen');

// L'API est hébergée séparément (autre hôte que le front). Sans cette
// surcharge, l'app interroge « http://<hôte courant>/api/v1 » : sur le serveur
// de test, ce chemin n'existe pas, donc /public/shops ne renvoie rien et le
// sélecteur de magasin du login reste vide.
// À surcharger par l'env KITCHEN_API_BASE (SetEnv dans .htaccess), sinon on
// retombe sur le même origine + /api/v1. Même mécanisme que pwa_consultant
// (CONSULTANT_API_BASE), qui fonctionne déjà sur ce serveur.
$__kitchenApiBase = $_SERVER['KITCHEN_API_BASE'] ?? $_ENV['KITCHEN_API_BASE'] ?? getenv('KITCHEN_API_BASE') ?: '';
define('API_BASE_URL',
    $__kitchenApiBase !== ''
        ? rtrim($__kitchenApiBase, '/')
        : ((((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
            ? 'https://'
            : 'http://') . $_SERVER['SERVER_NAME'] . '/api/v1'));

// Back-office franchisé du WebShop, ouvert par le mode « WebShop » de la
// tablette. Même mécanisme que KITCHEN_API_BASE : SetEnv WEBSHOP_BO_URL dans
// le .htaccess du serveur, pour ne pas saisir la même URL sur chaque tablette.
// Vide par défaut, et c'est volontaire : sans URL, le mode WebShop est proposé
// grisé avec sa raison, pas ouvert sur une page blanche.
$__webshopBoUrl = $_SERVER['WEBSHOP_BO_URL'] ?? $_ENV['WEBSHOP_BO_URL'] ?? getenv('WEBSHOP_BO_URL') ?: '';
define('WEBSHOP_BO_URL', rtrim((string)$__webshopBoUrl, '/'));

define('SHARED_FILES_URL',
    (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
        ? 'https://'
        : 'http://') . $_SERVER['SERVER_NAME'] . '/shared-assets');

define('THEME_CONFIG_PATH',
    (((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
        ? 'https://'
        : 'http://') . $_SERVER['SERVER_NAME'] . '/shared/admin-theme-config.json');

define('JWT_SECRET_KEY', $_ENV['JWT_SECRET']); // Secret key for JWT
define('JWT_ISSUER', $_ENV['JWT_ISSUER']); // Issuer
define('JWT_ACCESS_TOKEN_EXPIRY', $_ENV['JWT_ACCESS_TOKEN_EXPIRY']); // Access token expiry in seconds
define('JWT_REFRESH_TOKEN_EXPIRY', $_ENV['JWT_REFRESH_TOKEN_EXPIRY']); // Refresh token expiry in seconds

// Langues réellement traduites (un dossier par code sous
// src/core/I18n/translations/page/). Une langue absente d'ici retombe sur
// APP_FALLBACK_LANGUAGE plutôt que de vider l'interface de ses libellés.
const APP_SUPPORTED_LANGUAGES = ['fr', 'en', 'pl', 'it', 'nl'];
const APP_FALLBACK_LANGUAGE = 'fr';

// ── Production ────────────────────────────────────────────────────────────
// Valeurs de repli. Dès que l'API sert /shops/{id}/production/config, elles
// sont écrasées par les réglages du magasin : deux magasins n'ouvrent pas aux
// mêmes heures, et une constante partagée serait fausse pour l'un des deux.
// Voir docs/ENDPOINTS_PRODUCTION.md.
const PRODUCTION_PERIODS = [
    ['key' => 'morning',   'start' => '05:00', 'end' => '11:00'],
    ['key' => 'noon',      'start' => '11:00', 'end' => '14:00'],
    ['key' => 'afternoon', 'start' => '14:00', 'end' => '19:00'],
];
// Durée sur laquelle on projette les ventes avant de proposer une recuisson.
const PRODUCTION_FORECAST_HOURS = 2;
// Profondeur d'historique, en semaines, pour la moyenne du même jour de semaine.
const PRODUCTION_HISTORY_WEEKS = 6;
// Marge de sécurité, en unités produit, retranchée avant de conclure au manque.
// À 0, on propose dès que la projection passe sous zéro.
const PRODUCTION_SAFETY_MARGIN = 0;

define('DEFAULT_LANGUAGE', $_ENV['DEFAULT_LANGUAGE'] ?? APP_FALLBACK_LANGUAGE);
define('COUNTRY_CODE', $_ENV['DEFAULT_COUNTRY']);
define('CURRENCY', $_ENV['CURRENCY']);
if (!defined('CURRENCY_SYMBOL')) {
    define('CURRENCY_SYMBOL', $_ENV['CURRENCY_SYMBOL']);
}
define('APP_CURRENCY_SYMBOL', $_ENV['CURRENCY_SYMBOL']);
define('APP_NAME', $_ENV['APP_NAME']);
define('APP_DESC', $_ENV['APP_DESC']);


/**
 * Version servie.
 *
 * Le déploiement écrit BUILD à la racine (sha court + horodatage). Sans ce
 * fichier — en local, ou si le déploiement n'a pas tourné — on le dit plutôt
 * que d'afficher une version inventée.
 *
 * Sert à répondre à « je ne vois aucun changement » : la page porte le commit
 * qu'elle sert, et le workflow vérifie qu'elle porte bien celui qu'il vient
 * de pousser.
 */
define('APP_BUILD', (function () {
    $f = __DIR__ . '/../BUILD';
    return is_file($f) ? trim((string)file_get_contents($f)) : 'dev';
})());

/*
 * L'affichage des erreurs PHP dans la page.
 *
 * Cette constante valait « true » en dur, et son seul effet est
 * ini_set('display_errors', 1) dans public/index.php : la moindre alerte PHP
 * s'imprimait donc au milieu de l'écran du magasin, chemins du serveur
 * compris. Sur une tablette de comptoir, personne ne sait lire ça, et ceux qui
 * savent n'ont pas à y avoir accès.
 *
 * Elle se lit maintenant depuis l'environnement, comme KITCHEN_API_BASE :
 * « SetEnv KITCHEN_DEBUG 1 » dans le .htaccess d'un poste de développement
 * suffit à la rallumer. Absente, elle est éteinte — c'est le bon défaut pour
 * une application qui n'existe qu'en production sur un serveur de client.
 *
 * Les erreurs restent journalisées côté serveur (error_log), on ne perd rien :
 * on cesse simplement de les montrer à l'équipe.
 */
$__kitchenDebug = $_SERVER['KITCHEN_DEBUG'] ?? $_ENV['KITCHEN_DEBUG'] ?? getenv('KITCHEN_DEBUG') ?: '';
define('DEBUG', in_array(strtolower((string)$__kitchenDebug), ['1', 'true', 'on', 'yes'], true));
