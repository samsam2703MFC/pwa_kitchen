<?php

namespace App\Kitchen\app\Http\Controllers\Knowledge\Product;


use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\app\Services\Knowledge\Product\ProductService;
use App\Kitchen\app\Services\Knowledge\Product\TrayLayout;
use App\Kitchen\app\Services\Knowledge\Recipe\RecipeService;
use App\Kitchen\core\Support\Route;

class ProductController extends Controller
{

    public function __construct(
        private ProductService $productService,
        private TrayLayout $trayLayout
    ) {}

    #[Route('GET', '/knowledge/products')]
    public function index()
    {
        $data['products'] = $this->productService->getAvailableToSale();

        $this->view("knowledge/product/overview", $data);
    }

    /**
     * Base de connaissances → « Pas à pas de production » : les produits du magasin (même
     * source que la liste des produits), rangés par catégorie, chacun ouvrant sa fiche
     * directement en pas à pas (#pas-a-pas). La vignette dessine la forme du produit,
     * comme sur la plaque.
     */
    #[Route('GET', '/knowledge/book')]
    public function book()
    {
        $groups = [];
        foreach ($this->productService->getAvailableToSale() as $product) {
            $name = trim((string) $product->getName());
            if ($name === '') {
                continue;
            }
            $category = trim((string) $product->getCategoryName());
            $shape = $this->trayLayout->shapeFor($name, $category);
            $groups[$category][] = [
                'id' => $product->getId(),
                'name' => $name,
                'svg' => $this->trayLayout->pieceSvg($shape),
            ];
        }

        // Catégories dans l'ordre alphabétique, « sans catégorie » à la fin ; produits par nom.
        uksort($groups, fn ($a, $b) => [$a === '', mb_strtolower($a)] <=> [$b === '', mb_strtolower($b)]);
        foreach ($groups as &$items) {
            usort($items, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
        }
        unset($items);

        $this->view("knowledge/book/overview", ['groups' => $groups]);
    }

}