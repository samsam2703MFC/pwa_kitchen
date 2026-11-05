<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // L'onglet SOP : renvoie vers la tablette Formation du cockpit (magasin, poste, langue de l'appareil).
    $r->addRoute('GET', '/sop', [
        'controller' => \App\Kitchen\app\Http\Controllers\Sop\SopController::class,
        'method'     => 'index'
    ]);
};
