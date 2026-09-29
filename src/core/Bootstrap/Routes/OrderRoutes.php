<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    // Lista zamówień (z filtrami: data, nazwa klienta, pending_only)
    $r->addRoute('GET', '/orders', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('GET', '/orders/new', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'newOrder',
    ]);

    $r->addRoute('GET', '/orders/{id:\d+}/edit', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'edit',
    ]);

    $r->addRoute('POST', '/ajax/client-orders', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'ajaxInsert',
    ]);

    $r->addRoute('PATCH', '/ajax/client-orders/{id:\d+}', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'ajaxUpdate',
    ]);

    $r->addRoute('DELETE', '/ajax/client-orders/{id:\d+}', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'ajaxDelete',
    ]);

    // Compteur des commandes en attente — sondé par le front toutes les 10 s.
    $r->addRoute('GET', '/ajax/orders/pending-count', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'pendingCount',
    ]);

    // Szczegóły zamówienia
    $r->addRoute('GET', '/orders/{id:\d+}', [
        'controller' => \App\Kitchen\app\Http\Controllers\Order\OrderController::class,
        'method'     => 'show',
    ]);
};

