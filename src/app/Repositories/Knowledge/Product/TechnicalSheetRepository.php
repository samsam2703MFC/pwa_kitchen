<?php

namespace App\Kitchen\app\Repositories\Knowledge\Product;


use App\Kitchen\app\Models\Knowledge\Product\ProductDetailModel;
use App\Kitchen\core\Http\ApiClient;

class TechnicalSheetRepository
{
    public function __construct(
        private ApiClient $apiClient
    )
    {
    }

    public function getById($id, $langCode = "pl"): ?ProductDetailModel
    {
        $queryStr = "?" . http_build_query([
                'lang_code' => $langCode
            ]);

        $response = $this->apiClient->get("/products/$id/technical-sheet/raw$queryStr");

        if (!isset($response['success']) || !$response['success']) {
            return null;
        }

        $data = $response['data'] ?? null;

        if (!$data) {
            return null;
        }

        return new ProductDetailModel($data);
    }

    /**
     * POST /shops/{shopId}/ovens/programs/send — endpoint du panel (à ouvrir, voir
     * docs/carte-produit-pas-a-pas.md) qui relaie vers l'API Cloud REST d'Unox.
     * @return array{success:bool, message:?string}
     */
    public function sendOvenProgram(int $shopId, int $productId, int $stepNumber, string $unoxProgramId): array
    {
        $response = $this->apiClient->post("/shops/$shopId/ovens/programs/send", [
            'product_id' => $productId,
            'step_number' => $stepNumber,
            'unox_program_id' => $unoxProgramId,
        ]);

        return [
            'success' => (bool) ($response['success'] ?? false),
            'message' => $response['message'] ?? $response['description'] ?? null,
        ];
    }

}