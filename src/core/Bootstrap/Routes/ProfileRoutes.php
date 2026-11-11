<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    $r->addRoute('GET', '/me', [
        'controller' => \App\Kitchen\app\Http\Controllers\Me\ProfileController::class,
        'method'     => 'index'
    ]);

    $r->addRoute('POST', '/me/settings', [
        'controller' => \App\Kitchen\app\Http\Controllers\Me\ProfileController::class,
        'method'     => 'save'
    ]);

};
