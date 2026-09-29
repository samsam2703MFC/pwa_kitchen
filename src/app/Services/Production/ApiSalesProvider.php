<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\SalesProfileModel;
use App\Kitchen\app\Repositories\Production\ProductionRepository;

/**
 * Les ventes telles que le back-office les consolide depuis la caisse.
 *
 * C'est l'implémentation de production. La PWA n'interroge pas le POS
 * directement : le back-office est la source de vérité, et une tablette
 * d'atelier n'a pas à détenir des identifiants de caisse.
 */
class ApiSalesProvider implements PosSalesProviderInterface
{
    public function __construct(
        private ProductionRepository $productionRepository
    ) {}

    public function getProfile(
        int $shopId,
        string $date,
        int $weeks,
        bool $weekdayOnly = true,
        int $granularityMinutes = 30
    ): ?SalesProfileModel {
        return $this->productionRepository->getSalesProfile(
            $shopId,
            $date,
            $weeks,
            $weekdayOnly,
            $granularityMinutes
        );
    }
}
