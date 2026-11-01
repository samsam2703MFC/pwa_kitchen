<?php
namespace App\Kitchen\app\Services\Knowledge\Product;

use App\Kitchen\app\Repositories\Knowledge\Product\TechnicalSheetRepository;
use App\Kitchen\app\Models\Knowledge\Product\ProductDetailModel;
use App\Kitchen\core\Support\GlobalRegistry;

class TechnicalSheetService
{
    public function __construct(
        private TechnicalSheetRepository $technicalSheetRepository
    )
    {
    }

    public function getById($id): ?ProductDetailModel
    {
        return $this->technicalSheetRepository->getById($id, GlobalRegistry::get('lang_code'));
    }

    /** @return array{success:bool, message:?string} */
    public function sendOvenProgram(int $productId, int $stepNumber, string $unoxProgramId): array
    {
        $shopId = (int) (GlobalRegistry::get('user')['shop_id'] ?? 0);
        if ($shopId <= 0) {
            return ['success' => false, 'message' => 'no_shop'];
        }
        return $this->technicalSheetRepository->sendOvenProgram($shopId, $productId, $stepNumber, $unoxProgramId);
    }
}