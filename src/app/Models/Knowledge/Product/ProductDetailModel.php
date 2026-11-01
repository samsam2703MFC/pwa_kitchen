<?php

namespace App\Kitchen\app\Models\Knowledge\Product;

use App\Kitchen\app\Models\Knowledge\Recipe\RecipeModel;
use App\Kitchen\app\Models\Knowledge\Recipe\PreparationModel;
use App\Kitchen\app\Models\Knowledge\Recipe\VisualCheckModel;
use JsonSerializable;

/**
 * ProductDetailModel - pełny model produktu z wszystkimi powiązanymi danymi
 * Odpowiada strukturze zwracanej przez API dla pojedynczego produktu
 */
class ProductDetailModel implements JsonSerializable
{
    private $object; // ProductModel with extended data
    private $photos; // ProductPhotoModel
    private $recipe; // RecipeModel
    private $package; // PackageModel
    private $competences; // Array of CompetenceModel
    private $preparation; // PreparationModel
    private $quality_checks; // VisualCheckModel[] — le contrôle final « à faire / à éviter »

    public function __construct($data)
    {
        // Parse object (product basic info + required_competences)
        if (isset($data['object'])) {
            $this->object = new ProductModel($data['object']);
        }

        // Parse photos
        if (isset($data['photos'])) {
            $this->photos = new ProductPhotoModel($data['photos']);
        }

        // Parse recipe
        if (isset($data['recipe'])) {
            $this->recipe = new RecipeModel($data['recipe']);
        }

        // Parse package
        if (isset($data['package'])) {
            $this->package = new PackageModel($data['package']);
        }

        // Parse competences
        $this->competences = [];
        if (isset($data['competences']) && is_array($data['competences'])) {
            foreach ($data['competences'] as $competence) {
                $this->competences[] = new CompetenceModel($competence);
            }
        }

        // Parse preparation
        if (isset($data['preparation'])) {
            $this->preparation = new PreparationModel($data['preparation']);
        }

        // Contrôle final (do / don't) — facultatif, voir docs/carte-produit-pas-a-pas.md
        $this->quality_checks = [];
        foreach ((array) ($data['quality_checks'] ?? []) as $check) {
            if (is_array($check)) {
                $this->quality_checks[] = new VisualCheckModel($check);
            }
        }
    }

    public function jsonSerialize(): mixed
    {
        return [
            'object' => $this->object,
            'photos' => $this->photos,
            'recipe' => $this->recipe,
            'package' => $this->package,
            'competences' => $this->competences,
            'preparation' => $this->preparation,
            'quality_checks' => $this->quality_checks
        ];
    }

    // Getters
    public function getObject() { return $this->object; }
    public function getPhotos() { return $this->photos; }
    public function getRecipe() { return $this->recipe; }
    public function getPackage() { return $this->package; }
    public function getCompetences() { return $this->competences; }
    public function getPreparation() { return $this->preparation; }
    public function getQualityChecks(): array { return $this->quality_checks; }

    // Helper methods
    public function hasPhotos(): bool { return $this->photos !== null; }
    public function hasRecipe(): bool { return $this->recipe !== null; }
    public function hasPackage(): bool { return $this->package !== null; }
    public function hasCompetences(): bool { return !empty($this->competences); }
    public function hasPreparation(): bool { return $this->preparation !== null; }

    // Convenience methods to access product data directly
    public function getId() { return $this->object?->getId(); }
    public function getName() { return $this->object?->getName(); }
    public function getCategoryName() { return $this->object?->getCategoryName(); }
    public function getStorageName() { return $this->object?->getStorageName(); }
    public function getShelfLifeMinutes() { return $this->object?->getShelfLifeMinutes(); }
    public function getSuggestedSalePrice() { return $this->object?->getSuggestedSalePrice(); }

    /**
     * Get all allergens from recipe
     */
    public function getAllAllergens(): array
    {
        if ($this->recipe) {
            return $this->recipe->getAllAllergens();
        }
        return [];
    }

    /**
     * Get total preparation time in minutes
     */
    public function getTotalPreparationMinutes(): ?float
    {
        if ($this->preparation) {
            return $this->preparation->getTotalDurationMinutes();
        }
        return null;
    }
}

