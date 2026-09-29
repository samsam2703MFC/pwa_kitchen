<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\ProductionProductModel;

/**
 * Les secteurs de production d'un magasin — boulangerie, traiteur, la suite.
 *
 * Un secteur n'est pas une catégorie : la boulangerie fait de la viennoiserie
 * ET de la boulangerie, le traiteur fait des sandwichs ET des salades. Ce sont
 * deux ateliers, deux équipes, souvent deux pièces — mais un seul magasin, une
 * seule vitrine, et exactement les mêmes questions : qu'est-ce qui va manquer,
 * qu'est-ce qui est au four, qu'est-ce qui part en rayon.
 *
 * D'où un filtre en tête des écrans plutôt qu'un second module : le boulanger
 * qui passe au traiteur retrouve ses gestes, il ne réapprend pas l'outil. La
 * liste vient du catalogue et n'est pas fermée — le back-office peut en
 * déclarer un troisième sans qu'on touche à l'écran.
 *
 * Service PUR : ni HTTP, ni horloge, ni session — bin/sector-test.php.
 */
class SectorService
{
    /**
     * Les secteurs présents au catalogue, avec leur poids.
     *
     * Les produits sans secteur ne créent pas de rubrique « Autre » : un
     * magasin dont le back-office n'a rien déclaré n'a pas deux ateliers, il en
     * a un seul et n'a que faire d'un sélecteur.
     *
     * @param ProductionProductModel[] $products
     * @return array<int, array{key: string, label: string, count: int}>
     */
    public function sectors(array $products): array
    {
        $seen = [];
        foreach ($products as $p) {
            $key = $p->getSector();
            if ($key === null || !$p->isActive()) {
                continue;
            }
            if (!isset($seen[$key])) {
                $seen[$key] = ['key' => $key, 'label' => $p->getSectorName() ?? $key, 'count' => 0];
            }
            $seen[$key]['count']++;
        }

        $out = array_values($seen);
        usort($out, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
        return $out;
    }

    /**
     * Le secteur retenu pour cette requête.
     *
     * Un secteur demandé mais absent du catalogue retombe sur « tous » plutôt
     * que sur un écran vide : c'est ce qui arrive quand une URL est partagée
     * entre deux magasins, et un tableau vide s'y lirait comme « rien à
     * produire ».
     *
     * @param array<int, array{key: string}> $sectors
     */
    public function resolve(array $sectors, ?string $requested): ?string
    {
        if ($requested === null || $requested === '') {
            return null;
        }
        $requested = strtolower(trim($requested));
        foreach ($sectors as $s) {
            if ($s['key'] === $requested) {
                return $requested;
            }
        }
        return null;
    }

    /**
     * Le catalogue ramené à un secteur. null = tout le catalogue.
     *
     * @param ProductionProductModel[] $products
     * @return ProductionProductModel[]
     */
    public function filter(array $products, ?string $sector): array
    {
        if ($sector === null || $sector === '') {
            return array_values($products);
        }
        return array_values(array_filter(
            $products,
            fn(ProductionProductModel $p) => $p->inSector($sector)
        ));
    }

    /**
     * Les identifiants d'un secteur, pour filtrer ce qui ne porte pas la fiche
     * produit — lignes de MEP, fournées, lignes de stock, commandes.
     *
     * @param ProductionProductModel[] $products
     * @return array<int, true> ensemble indexé par id_product
     */
    public function idsOf(array $products, ?string $sector): array
    {
        $ids = [];
        foreach ($this->filter($products, $sector) as $p) {
            if ($p->getIdProduct() !== null) {
                $ids[$p->getIdProduct()] = true;
            }
        }
        return $ids;
    }

    /**
     * Ne garde d'une liste que ce qui appartient au secteur.
     *
     * Un objet dont on ne sait pas lire le produit est CONSERVÉ : le filtre est
     * un confort de lecture, pas un contrôle d'accès, et faire disparaître une
     * fournée parce que son id manque coûterait plus qu'une fournée de trop à
     * l'écran.
     *
     * @template T
     * @param  T[] $items
     * @param  callable(T): ?int $idOf
     * @param  array<int, true>  $ids  ensemble rendu par idsOf()
     * @return T[]
     */
    public function keepIn(array $items, callable $idOf, ?string $sector, array $ids): array
    {
        if ($sector === null || $sector === '') {
            return array_values($items);
        }
        return array_values(array_filter($items, function ($item) use ($idOf, $ids) {
            $id = $idOf($item);
            return $id === null || isset($ids[$id]);
        }));
    }
}
