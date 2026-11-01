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

    /**
     * « Envoyer au four » du pas à pas : le panel pousse le programme Unox de l'étape vers les
     * fours connectés du magasin. La tablette ne parle jamais à Unox elle-même : les accès
     * restent au panel (docs/carte-produit-pas-a-pas.md, § Unox).
     */
    #[Route('POST', '/knowledge/products/{id:\d+}/oven/send')]
    public function sendOvenProgram($id): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $stepNumber = (int) ($_POST['step_number'] ?? 0);
        $programId = trim((string) ($_POST['unox_program_id'] ?? ''));

        if ((int) $id <= 0 || $stepNumber <= 0 || $programId === '') {
            return $this->json(['success' => false, 'message' => 'invalid_request'], 400);
        }

        $result = $this->technicalSheetService->sendOvenProgram((int) $id, $stepNumber, $programId);

        return $this->json($result, $result['success'] ? 200 : 502);
    }
}

