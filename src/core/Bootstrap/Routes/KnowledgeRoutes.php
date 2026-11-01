<?php


use FastRoute\RouteCollector;

return function(RouteCollector $r) {

    // VIEW
    $r->addRoute('GET', '/knowledge/products/{id:\d+}', [
        'controller' => \App\Kitchen\app\Http\Controllers\Knowledge\Product\TechnicalSheetController::class,
        'method'     => 'show'
    ]);


    // Pas à pas : envoyer le programme four d'une étape aux fours Unox du magasin (via le panel)
    $r->addRoute('POST', '/knowledge/products/{id:\d+}/oven/send', [
        'controller' => \App\Kitchen\app\Http\Controllers\Knowledge\Product\TechnicalSheetController::class,
        'method'     => 'sendOvenProgram'
    ]);

    // VIEW
    $r->addRoute('GET', '/knowledge', [
        'controller' => \App\Kitchen\app\Http\Controllers\Knowledge\KnowledgeController::class,
        'method'     => 'index'
    ]);

    // Base de connaissances → Pas à pas de production
    $r->addRoute('GET', '/knowledge/book', [
        'controller' => \App\Kitchen\app\Http\Controllers\Knowledge\Product\ProductController::class,
        'method'     => 'book'
    ]);

    $r->addRoute('GET', '/knowledge/products', [
        'controller' => \App\Kitchen\app\Http\Controllers\Knowledge\Product\ProductController::class,
        'method'     => 'index'
    ]);


};