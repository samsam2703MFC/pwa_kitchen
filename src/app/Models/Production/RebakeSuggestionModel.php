<?php

namespace App\Kitchen\app\Models\Production;

use JsonSerializable;

/**
 * Une proposition de recuisson, produite par ForecastService.
 *
 * L'objet porte tout ce qui a servi à la décider — stock, ventes prévues,
 * fenêtre, batch. En atelier, une proposition qu'on ne peut pas expliquer est
 * une proposition qu'on n'applique pas.
 */
class RebakeSuggestionModel implements JsonSerializable
{
    public function __construct(
        private ?int $idProduct,
        private ?string $name,
        private ?string $categoryName,
        private float $stock,
        private float $expectedSales,
        private float $projected,
        private float $deficit,
        private float $batchSize,
        private bool $batchKnown,
        private float $suggestedQuantity,
        private int $windowMinutes,
        private int $leadMinutes,
        private ?string $unitName,
        /**
         * Les commandes fermes dues sur la fenêtre.
         *
         * Comptées à part des ventes prévues, et pas fondues dedans : une
         * commande de 40 sandwichs n'est pas une moyenne, et le voir écrit
         * change la façon dont on lit la proposition.
         */
        private float $ordered = 0.0
    ) {}

    public function getIdProduct(): ?int { return $this->idProduct; }
    public function getName(): ?string { return $this->name; }
    public function getCategoryName(): ?string { return $this->categoryName; }
    public function getStock(): float { return $this->stock; }
    public function getExpectedSales(): float { return $this->expectedSales; }
    /** Commandes fermes dues sur la fenêtre — s'ajoutent aux ventes prévues. */
    public function getOrdered(): float { return $this->ordered; }
    public function hasOrders(): bool { return $this->ordered > 0; }
    /** Stock projeté en fin de fenêtre : négatif, c'est une rupture annoncée. */
    public function getProjected(): float { return $this->projected; }
    public function getDeficit(): float { return $this->deficit; }
    public function getBatchSize(): float { return $this->batchSize; }
    /** false quand le serveur n'a pas donné de taille de fournée. */
    public function isBatchKnown(): bool { return $this->batchKnown; }
    public function getSuggestedQuantity(): float { return $this->suggestedQuantity; }
    public function getWindowMinutes(): int { return $this->windowMinutes; }
    public function getLeadMinutes(): int { return $this->leadMinutes; }
    public function getUnitName(): ?string { return $this->unitName; }

    /** Nombre de fournées, pour dire « 2 plaques » plutôt que « 48 pièces ». */
    public function getBatchCount(): int
    {
        return $this->batchSize > 0 ? (int)round($this->suggestedQuantity / $this->batchSize) : 0;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id_product' => $this->idProduct,
            'name' => $this->name,
            'category_name' => $this->categoryName,
            'stock' => $this->stock,
            'expected_sales' => round($this->expectedSales, 2),
            'ordered' => round($this->ordered, 2),
            'projected' => round($this->projected, 2),
            'deficit' => round($this->deficit, 2),
            'batch_size' => $this->batchSize,
            'batch_known' => $this->batchKnown,
            'suggested_quantity' => $this->suggestedQuantity,
            'batch_count' => $this->getBatchCount(),
            'window_minutes' => $this->windowMinutes,
            'lead_minutes' => $this->leadMinutes,
            'unit_name' => $this->unitName,
        ];
    }
}
