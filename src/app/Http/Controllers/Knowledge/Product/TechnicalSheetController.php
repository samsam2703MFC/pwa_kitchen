<?php

namespace App\Kitchen\app\Http\Controllers\Knowledge\Product;


use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Knowledge\Product\ProductionBookBuilder;
use App\Kitchen\app\Services\Knowledge\Product\TechnicalSheetService;
use App\Kitchen\core\Support\Route;

class TechnicalSheetController extends Controller
{

    public function __construct(
        private TechnicalSheetService $technicalSheetService,
        private ProductionBookBuilder $productionBookBuilder
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
        // Le « pas à pas » : la même fiche, une étape par écran (cuisson guidée façon Thermomix).
        $data['book'] = $this->productionBookBuilder->build($product);

        $this->view("knowledge/product/detail", $data);
    }
}

