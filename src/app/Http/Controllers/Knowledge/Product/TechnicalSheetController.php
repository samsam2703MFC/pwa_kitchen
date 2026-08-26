<?php

namespace App\Kitchen\app\Http\Controllers\Knowledge\Product;


use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Knowledge\Preparation\PreparationPathService;
use App\Kitchen\app\Services\Knowledge\Product\TechnicalSheetService;
use App\Kitchen\core\Support\Route;

class TechnicalSheetController extends Controller
{

    public function __construct(
        private TechnicalSheetService $technicalSheetService,
        private PreparationPathService $preparationPathService
    ) {}

    #[Route('GET', '/knowledge/products/{id:\d+}')]
    public function show($id)
    {
        $product = $this->technicalSheetService->getById($id);

        if ($product === null) {
            $this->errors[] = 'Produkt nie został znaleziony.';
            $this->view("errors/404", []);
            return;
        }

        $data['product'] = $product;

        /* ── Le parcours de préparation ──
           Sa propre route, et son propre sort : la fiche technique peut très
           bien répondre pendant que le parcours ne répond pas. Les deux
           n'arrivent donc pas ensemble, et un parcours absent ne fait pas
           disparaître la fiche. */
        $data['prep'] = $this->safeFetch(
            fn() => $this->preparationPathService->forProduct((int)$id),
            $this->warnings,
            null,
            ['state' => 'missing', 'steps' => [], 'total_seconds' => 0,
             'unreadable' => 0, 'missing' => 'GET /products/' . (int)$id . '/preparation-path']
        );
        // La route manquante, s'il y en a une : la coque la nomme en haut de
        // l'écran. Voir Controller::view() et layouts/base.twig.
        $data['missing_api'] = $data['prep']['missing'];

        $this->view("knowledge/product/detail", $data);
    }
}
