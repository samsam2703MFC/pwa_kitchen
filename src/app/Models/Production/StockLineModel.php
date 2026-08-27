<?php

namespace App\Kitchen\app\Models\Production;

use JsonSerializable;

/**
 * Le stock live d'un produit, tel que le back-office le consolide
 * (productions validées − ventes). La PWA ne le recalcule jamais : plusieurs
 * tablettes dans le même atelier divergeraient en quelques minutes.
 */
class StockLineModel implements JsonSerializable
{
    private ?int $idProduct;
    private ?string $name;
    private ?string $categoryName;
    private float $quantity;
    private ?string $unitName;

    public function __construct(array $d)
    {
        $this->idProduct    = isset($d['id_product']) ? (int)$d['id_product'] : (isset($d['id']) ? (int)$d['id'] : null);
        $this->name         = $d['name'] ?? null;
        $this->categoryName = $d['category_name'] ?? null;
        $this->quantity     = (float)($d['quantity'] ?? 0);
        $this->unitName     = $d['unit_name'] ?? null;
    }

    public function getIdProduct(): ?int { return $this->idProduct; }
    public function getName(): ?string { return $this->name; }
    public function getCategoryName(): ?string { return $this->categoryName; }
    public function getQuantity(): float { return $this->quantity; }
    public function getUnitName(): ?string { return $this->unitName; }

    /** Un stock à zéro ou négatif n'est pas vendable en caisse. */
    public function isOutOfStock(): bool { return $this->quantity <= 0; }

    public function jsonSerialize(): mixed
    {
        return [
            'id_product' => $this->idProduct,
            'name' => $this->name,
            'category_name' => $this->categoryName,
            'quantity' => $this->quantity,
            'unit_name' => $this->unitName,
        ];
    }
}
