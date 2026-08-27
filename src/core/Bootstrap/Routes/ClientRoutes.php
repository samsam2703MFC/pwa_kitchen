<?php

use FastRoute\RouteCollector;

/*
 * Les trois routes « price-list » qui vivaient ici visaient
 * Controllers\Price\PriceController, un contrôleur supprimé du dépôt. Elles ne
 * rendaient pas un 404 mais un 500 nu — « Błąd DI : … » — parce que le routeur
 * les trouvait et que seul le conteneur échouait, une fois la coque perdue.
 * Deux autres fichiers de routes avaient le même défaut (CatalogRoutes,
 * IngredientRoutes) et ont été retirés entièrement.
 *
 * Une route ne se déclare qu'avec le contrôleur qui existe : mieux vaut un 404
 * honnête, qui dit « cet écran n'existe pas », qu'un 500 qui laisse croire à
 * une panne du serveur.
 */
return function (RouteCollector $r) {

    // /clients redirige vers la création de commande — c'est le seul endroit
    // d'où l'on ajoute un client aujourd'hui.
    $r->addRoute('GET', '/clients', [
        'controller' => \App\Kitchen\app\Http\Controllers\Client\ClientController::class,
        'method' => 'index'
    ]);

    $r->addRoute('POST', '/ajax/clients', [
        'controller' => \App\Kitchen\app\Http\Controllers\Client\ClientController::class,
        'method' => 'ajaxInsert'
    ]);

};
