<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\SalesProfileModel;

/**
 * Un profil de ventes figé, lu dans un fichier JSON.
 *
 * Sert à vérifier la prévision sans serveur ni API : c'est ce que consomme
 * bin/forecast-test.php. Aucune dépendance réseau, donc un résultat
 * reproductible — condition pour qu'un désaccord sur une proposition se
 * tranche par un jeu de données plutôt que par un avis.
 */
class FixtureSalesProvider implements PosSalesProviderInterface
{
    public function __construct(
        private string $fixturePath
    ) {}

    public function getProfile(
        int $shopId,
        string $date,
        int $weeks,
        bool $weekdayOnly = true,
        int $granularityMinutes = 30
    ): ?SalesProfileModel {
        if (!is_readable($this->fixturePath)) {
            return null;
        }
        $raw = json_decode((string)file_get_contents($this->fixturePath), true);
        if (!is_array($raw)) {
            return null;
        }
        // Le fichier peut être la réponse d'API complète ou son seul « data ».
        return new SalesProfileModel($raw['data'] ?? $raw);
    }
}
