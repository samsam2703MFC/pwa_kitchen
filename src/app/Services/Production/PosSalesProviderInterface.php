<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\SalesProfileModel;

/**
 * D'où viennent les ventes qui alimentent la prévision.
 *
 * Aujourd'hui, d'un seul endroit : le back-office, qui consolide les ventes
 * remontées de la caisse. La PWA ne parle jamais directement au POS — c'est la
 * décision d'architecture prise avec le contrat d'API, et elle évite d'avoir à
 * écrire dans la caisse depuis une tablette d'atelier.
 *
 * L'interface existe malgré cela pour deux raisons concrètes : brancher un
 * autre POS le jour où le réseau en change, et surtout permettre de tester la
 * prévision sur un jeu de données figé (FixtureSalesProvider) sans serveur.
 */
interface PosSalesProviderInterface
{
    /**
     * Profil de ventes agrégé pour une journée.
     *
     * @param string $date        journée projetée, « Y-m-d »
     * @param int    $weeks       profondeur d'historique, en semaines
     * @param bool   $weekdayOnly n'agréger que les mêmes jours de semaine
     *
     * @return SalesProfileModel|null null quand la source ne répond pas — à
     *         distinguer d'un profil vide, qui signifie « rien ne s'est vendu »
     */
    public function getProfile(
        int $shopId,
        string $date,
        int $weeks,
        bool $weekdayOnly = true,
        int $granularityMinutes = 30
    ): ?SalesProfileModel;
}
