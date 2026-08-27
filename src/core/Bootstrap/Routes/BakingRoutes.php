<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $controller = \App\Kitchen\app\Http\Controllers\Baking\BakingController::class;

    // Écran de cuisson : plan de charge simplifié + fournées en cours.
    $r->addRoute('GET', '/baking', [
        'controller' => $controller,
        'method'     => 'index',
    ]);

    // Relecture du plan — sondée toutes les 30 secondes.
    $r->addRoute('GET', '/ajax/baking', [
        'controller' => $controller,
        'method'     => 'ajaxPlan',
    ]);

    // Programmation d'une fournée, depuis un manque constaté en production.
    $r->addRoute('POST', '/ajax/baking', [
        'controller' => $controller,
        'method'     => 'ajaxCreate',
    ]);

    // Passage d'une fournée à l'étape suivante.
    $r->addRoute('PATCH', '/ajax/baking/{id:\d+}', [
        'controller' => $controller,
        'method'     => 'ajaxAdvance',
    ]);
};
