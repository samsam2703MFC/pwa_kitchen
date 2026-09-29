<?php

use FastRoute\RouteCollector;

return function (RouteCollector $r) {

    $r->addRoute('GET', '/checklists', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'index',
    ]);

    $r->addRoute('POST', '/checklists/tasks/{taskId}/complete', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'completeTask',
    ]);

    // La prise de poste : le seul endroit où le PIN est saisi.
    $r->addRoute('POST', '/ajax/checklists/shift', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'openShift',
    ]);
    $r->addRoute('POST', '/ajax/checklists/shift/close', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'closeShift',
    ]);

    // Neutralne aliasy dla globalnego kontekstu osoby obsługującej tablet.
    // Dotychczasowe trasy checklist zostają, aby nie zrywać istniejącego flow.
    $r->addRoute('GET', '/ajax/tablet-worker/roster', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'tabletWorkerRoster',
    ]);
    $r->addRoute('POST', '/ajax/tablet-worker/start', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'openShift',
    ]);
    $r->addRoute('POST', '/ajax/tablet-worker/stop', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'closeShift',
    ]);

    $r->addRoute('GET', '/ajax/checklists/tasks/{taskId}/eligible-employees', [
        'controller' => \App\Kitchen\app\Http\Controllers\Checklist\ChecklistController::class,
        'method'     => 'eligibleEmployees',
    ]);
};
