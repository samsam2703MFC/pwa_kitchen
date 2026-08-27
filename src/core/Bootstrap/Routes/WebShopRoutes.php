<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/webshop', [
        'controller' => \App\Kitchen\app\Http\Controllers\WebShop\WebShopController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('POST', '/ajax/webshop/order-status', [
        'controller' => \App\Kitchen\app\Http\Controllers\WebShop\WebShopController::class,
        'method'     => 'ajaxOrderStatus',
    ]);

};
