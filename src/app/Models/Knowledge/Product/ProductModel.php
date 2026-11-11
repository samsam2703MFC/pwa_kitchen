<?php

namespace App\Kitchen\app\Models\Knowledge\Product;

use JsonSerializable;

class ProductModel implements JsonSerializable{

    private $id;
    private $name;
    private $id_category;
    private $tax_val_perc;
    private $is_divisible;
    private $is_active;
    private $single_weight;
    private $is_vegetarian;
    private $id_recipe;
    private $id_positioning;
    private $id_storage;
    private $is_prepared_before_sales;
    private $nutriscore;
    private $allergene;
    private $suggested_sale_price;
    private $portion_price;
    private $quantity_per_label;
    private $is_piece_based;
    private $id_package;
    private $shelf_life_minutes;
    private $label_size;
    private $storage_temperature;
    private $shelf_life_category;
    private $reheating_time_minutes;
    private $reheating_temperature_celsius;
    private $category_name;
    private $positioning_name;
    private $positioning_description;
    private $storage_name;
    private $storage_description;

    /**
     * Chemin de la photo principale.
     *
     * Le modèle de liste n'en portait aucune : seul ProductDetailModel avait
     * des photos. Une liste de 573 produits tous coiffés de la même icône ne
     * se parcourt pas — c'est l'image qui distingue une assiette d'une autre.
     *
     * L'endpoint de liste ne renvoie peut-être pas encore le champ ; dans ce
     * cas il reste à null et les cartes retombent sur l'icône, sans casser.
     * Voir docs/ENDPOINTS_PHOTOS_PRODUITS.md.
     */
    private $main_photo_path;

    public function __construct($data) {
        $this->id = $data['id'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->id_category = $data['id_category'] ?? null;
        $this->tax_val_perc = $data['tax_val_perc'] ?? null;
        $this->is_divisible = $data['is_divisible'] ?? null;
        $this->is_active = $data['is_active'] ?? null;
        $this->single_weight = $data['single_weight'] ?? null;
        $this->is_vegetarian = $data['is_vegetarian'] ?? null;
        $this->id_recipe = $data['id_recipe'] ?? null;
        $this->id_positioning = $data['id_positioning'] ?? null;
        $this->id_storage = $data['id_storage'] ?? null;
        $this->is_prepared_before_sales = $data['is_prepared_before_sales'] ?? null;
        $this->nutriscore = $data['nutriscore'] ?? null;
        $this->allergene = $data['allergene'] ?? null;
        $this->suggested_sale_price = $data['suggested_sale_price'] ?? null;
        $this->portion_price = $data['portion_price'] ?? null;
        $this->quantity_per_label = $data['quantity_per_label'] ?? null;
        $this->is_piece_based = $data['is_piece_based'] ?? null;
        $this->id_package = $data['id_package'] ?? null;
        $this->shelf_life_minutes = $data['shelf_life_minutes'] ?? null;
        $this->label_size = $data['label_size'] ?? null;
        $this->storage_temperature = $data['storage_temperature'] ?? null;
        $this->shelf_life_category = $data['shelf_life_category'] ?? null;
        $this->reheating_time_minutes = $data['reheating_time_minutes'] ?? null;
        $this->reheating_temperature_celsius = $data['reheating_temperature_celsius'] ?? null;
        $this->category_name = $data['category_name'] ?? null;
        $this->positioning_name = $data['positioning_name'] ?? null;
        $this->positioning_description = $data['positioning_description'] ?? null;
        $this->storage_name = $data['storage_name'] ?? null;
        $this->storage_description = $data['storage_description'] ?? null;

        // Accepté à plat ou sous une clé « photos », selon ce que l'API sert.
        $this->main_photo_path = $data['main_photo_path']
            ?? ($data['photos']['main_photo_path'] ?? null);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'id_category' => $this->id_category,
            'tax_val_perc' => $this->tax_val_perc,
            'is_divisible' => $this->is_divisible,
            'is_active' => $this->is_active,
            'single_weight' => $this->single_weight,
            'is_vegetarian' => $this->is_vegetarian,
            'id_recipe' => $this->id_recipe,
            'id_positioning' => $this->id_positioning,
            'id_storage' => $this->id_storage,
            'is_prepared_before_sales' => $this->is_prepared_before_sales,
            'nutriscore' => $this->nutriscore,
            'allergene' => $this->allergene,
            'suggested_sale_price' => $this->suggested_sale_price,
            'portion_price' => $this->portion_price,
            'quantity_per_label' => $this->quantity_per_label,
            'is_piece_based' => $this->is_piece_based,
            'id_package' => $this->id_package,
            'shelf_life_minutes' => $this->shelf_life_minutes,
            'label_size' => $this->label_size,
            'storage_temperature' => $this->storage_temperature,
            'shelf_life_category' => $this->shelf_life_category,
            'reheating_time_minutes' => $this->reheating_time_minutes,
            'reheating_temperature_celsius' => $this->reheating_temperature_celsius,
            'category_name' => $this->category_name,
            'positioning_name' => $this->positioning_name,
            'positioning_description' => $this->positioning_description,
            'storage_name' => $this->storage_name,
            'storage_description' => $this->storage_description,
            'main_photo_path' => $this->main_photo_path
        ];
    }

    public function hasMainPhoto(): bool { return !empty($this->main_photo_path); }

    /**
     * URL absolue de la photo principale.
     *
     * Même résolution que ProductPhotoModel : les chemins arrivent en
     * « r2://… » et se résolvent sur SHARED_FILES_URL.
     */
    public function getMainPhotoUrl(): ?string
    {
        if (empty($this->main_photo_path)) {
            return null;
        }
        if (str_starts_with($this->main_photo_path, 'r2://')) {
            return rtrim(SHARED_FILES_URL, '/') . '/' . ltrim(substr($this->main_photo_path, 5), '/');
        }
        return $this->main_photo_path;
    }

    public function getId() { return $this->id; }
    public function getName() { return $this->name; }
    public function getCategoryName() { return $this->category_name; }
    public function getPositioningName() { return $this->positioning_name; }
    public function getPositioningDescription() { return $this->positioning_description; }
    public function getStorageName() { return $this->storage_name; }
    public function getStorageDescription() { return $this->storage_description; }
    public function getTaxValPerc() { return $this->tax_val_perc; }
    public function getIsDivisible() { return $this->is_divisible; }
    public function getIsActive() { return $this->is_active; }
    public function getSingleWeight() { return $this->single_weight; }
    public function getIsVegetarian() { return $this->is_vegetarian; }
    public function getIdRecipe() { return $this->id_recipe; }
    public function getIdPositioning() { return $this->id_positioning; }
    public function getIdStorage() { return $this->id_storage; }
    public function getIsPreparedBeforeSales() { return $this->is_prepared_before_sales; }
    public function getNutriscore() { return $this->nutriscore; }
    public function getAllergene() { return $this->allergene; }
    public function getSuggestedSalePrice() { return $this->suggested_sale_price; }
    public function getPortionPrice() { return $this->portion_price; }
    public function getQuantityPerLabel() { return $this->quantity_per_label; }
    public function getIsPieceBased() { return $this->is_piece_based; }
    public function getIdPackage() { return $this->id_package; }
    public function getShelfLifeMinutes() { return $this->shelf_life_minutes; }
    public function getLabelSize() { return $this->label_size; }
    public function getStorageTemperature() { return $this->storage_temperature; }
    public function getShelfLifeCategory() { return $this->shelf_life_category; }
    public function getReheatingTimeMinutes() { return $this->reheating_time_minutes; }
    public function getReheatingTemperatureCelsius() { return $this->reheating_temperature_celsius; }

}
