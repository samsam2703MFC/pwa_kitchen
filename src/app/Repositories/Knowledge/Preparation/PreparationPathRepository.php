<?php

namespace App\Kitchen\app\Repositories\Knowledge\Preparation;

use App\Kitchen\core\Http\ApiClient;

/**
 * Le parcours de préparation d'un produit.
 *
 * `GET /products/{id}/preparation-path` — les étapes que le réseau a définies
 * pour ce produit : l'instruction de travail, sa durée, le groupe de batch,
 * les paramètres de four, et jusqu'à trois photos par étape.
 *
 * ── Ce que ça remplace ──
 * La préparation arrivait jusqu'ici enfouie dans la fiche technique
 * (`/products/{id}/technical-sheet/raw`, voir TechnicalSheetRepository), sous
 * la forme d'un numéro et d'un texte. Le parcours, lui, porte ce qu'il faut
 * pour PLANIFIER : combien de temps dure chaque geste, combien de pièces
 * tiennent dans un four, et quelles étapes de produits différents peuvent
 * passer ensemble. C'est la source destinée à remplacer l'ancienne.
 *
 * ── Trois réponses, pas deux ──
 * L'endpoint distingue explicitement un produit SANS parcours (`configured`
 * à false) d'une route qui ne répond pas. On garde cette distinction jusqu'à
 * l'écran : « ce produit n'a pas de parcours » se règle au back-office,
 * « la route ne répond pas » se règle chez le développeur, et les confondre
 * envoie chercher au mauvais endroit.
 *
 * ── Écriture : jamais d'ici ──
 * Les onze autres routes du tag `Product preparation` (POST, PATCH, DELETE,
 * photos, copie) configurent le réseau entier depuis le back-office
 * administrateur. Une tablette d'atelier lit ; elle ne redéfinit pas le
 * parcours de tous les magasins.
 */
class PreparationPathRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /**
     * @return array<string, mixed>|null
     *         null = route non servie — à ne pas confondre avec une réponse
     *         portant `configured: false`, qui est un fait, pas une panne.
     */
    public function get(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $res = $this->apiClient->get("/products/{$productId}/preparation-path");
        if (!($res['success'] ?? false)) {
            return null;
        }

        $data = $res['data'] ?? null;
        if (!is_array($data)) {
            return null;
        }

        // La réponse peut être l'objet lui-même ou l'envelopper une fois de
        // plus. On ne devine pas au-delà : un emballage inconnu rend null, et
        // l'écran nomme la route plutôt que d'afficher un parcours vide.
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        return $data;
    }
}
