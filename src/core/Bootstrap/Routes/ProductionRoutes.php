<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $controller = \App\Kitchen\app\Http\Controllers\Production\ProductionController::class;

    // Pilotage de la production (module « Gestion de production », brique 1) :
    // état de la période 1 — prévu / vendu / écart par produit.
    $r->addRoute('GET', '/production/pilotage', [
        'controller' => \App\Kitchen\app\Http\Controllers\Production\PilotageController::class,
        'method'     => 'period1',
    ]);

    // Fin de journée — les gestes du volet « Solder » : jeter (photo +
    // quantité) et reporter au stock de demain. Écritures via l'API, jamais
    // de succès inventé.
    $r->addRoute('POST', '/ajax/production/pilotage/waste', [
        'controller' => \App\Kitchen\app\Http\Controllers\Production\PilotageController::class,
        'method'     => 'wasteAction',
    ]);
    $r->addRoute('POST', '/ajax/production/pilotage/stock-in', [
        'controller' => \App\Kitchen\app\Http\Controllers\Production\PilotageController::class,
        'method'     => 'stockAction',
    ]);

    // Écran unique, quatre vues : matin, midi, après-midi, stock.
    $r->addRoute('GET', '/production', [
        'controller' => $controller,
        'method'     => 'index',
    ]);

    // Stock live + propositions de recuisson, dans la même réponse.
    $r->addRoute('GET', '/ajax/production/stock', [
        'controller' => $controller,
        'method'     => 'ajaxStock',
    ]);

    // Mise en rayon : c'est elle qui rend les produits vendables.
    $r->addRoute('POST', '/ajax/production/shelf', [
        'controller' => $controller,
        'method'     => 'ajaxShelve',
    ]);

    // Validation de la MEP du jour : constate ce qui a été produit à l'atelier.
    $r->addRoute('POST', '/ajax/production/mep/validate', [
        'controller' => $controller,
        'method'     => 'ajaxValidateMep',
    ]);

    // Encodage de la MEP du lendemain, l'après-midi.
    $r->addRoute('POST', '/ajax/production/mep', [
        'controller' => $controller,
        'method'     => 'ajaxSaveMep',
    ]);

    // Validation d'une recuisson.
    $r->addRoute('POST', '/ajax/production/rebake', [
        'controller' => $controller,
        'method'     => 'ajaxRebake',
    ]);
};
