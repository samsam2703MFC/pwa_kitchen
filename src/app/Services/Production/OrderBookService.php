<?php

namespace App\Kitchen\app\Services\Production;

use App\Kitchen\app\Models\Production\OrderLineModel;

/**
 * Les commandes fermes, ramenées à ce qu'elles changent pour la production.
 *
 * Le profil de ventes projette ce qui partira ; les commandes disent ce qui
 * partira à coup sûr, et à quelle heure. Les deux s'ajoutent. Les traiter comme
 * des ventes prévues les diluerait dans une moyenne, alors qu'une commande de
 * 40 sandwichs pour midi n'a rien d'une moyenne — et les ignorer ferait
 * découvrir le manque au moment de servir le client.
 *
 * Service PUR : ni HTTP, ni horloge, ni session. Vérifiable hors serveur —
 * bin/sector-test.php.
 */
class OrderBookService
{
    /**
     * Ce qui est dû par produit sur une fenêtre horaire.
     *
     * @param OrderLineModel[] $orders
     * @return array<int, float> indexé par id_product ; les produits sans
     *         commande sont absents, pas à zéro
     */
    public function dueBetween(array $orders, int $fromMinutes, int $toMinutes): array
    {
        $out = [];
        foreach ($orders as $o) {
            $id = $o->getIdProduct();
            if ($id === null || $o->getQuantity() <= 0) {
                continue;
            }
            // Borne haute exclue : une commande due à 11 h 00 pile appartient à
            // la période qui commence à 11 h, pas à celle qui vient de finir —
            // sinon elle serait comptée deux fois sur deux horizons contigus.
            $due = $o->getDueMinutes();
            if ($due < $fromMinutes || $due >= $toMinutes) {
                continue;
            }
            $out[$id] = ($out[$id] ?? 0.0) + $o->getQuantity();
        }
        return $out;
    }

    /**
     * Ce qui est dû par produit sur SA fenêtre de production.
     *
     * La fenêtre n'est pas la même pour tous : un pain qui demande trente
     * minutes de cuisson doit être lancé trente minutes plus tôt qu'un cookie,
     * donc on regarde trente minutes plus loin pour lui. C'est exactement la
     * fenêtre de ForecastService, et volontairement la même — les commandes et
     * les ventes prévues doivent se juger sur le même horizon, sinon la
     * projection additionne deux futurs différents.
     *
     * @param OrderLineModel[]                                         $orders
     * @param \App\Kitchen\app\Models\Production\ProductionProductModel[] $products
     * @return array<int, float>
     */
    public function dueForProducts(array $orders, array $products, int $nowMinutes, int $forecastMinutes): array
    {
        $byProduct = [];
        foreach ($orders as $o) {
            $id = $o->getIdProduct();
            if ($id !== null && $o->getQuantity() > 0) {
                $byProduct[$id][] = $o;
            }
        }

        $out = [];
        foreach ($products as $p) {
            $id = $p->getIdProduct();
            if ($id === null || !isset($byProduct[$id])) {
                continue;
            }
            $to = $nowMinutes + $forecastMinutes + $p->getLeadMinutes();
            foreach ($byProduct[$id] as $o) {
                // Les commandes déjà échues comptent encore : elles n'ont pas
                // disparu parce que l'heure est passée, et c'est souvent celles
                // qu'on a ratées qu'il faut produire en premier.
                if ($o->getDueMinutes() < $to) {
                    $out[$id] = ($out[$id] ?? 0.0) + $o->getQuantity();
                }
            }
        }
        return $out;
    }

    /**
     * Ce qui est dû par produit sur une période nommée.
     *
     * L'échéance passe avant l'étiquette : une commande porte souvent les deux,
     * et l'heure est celle que le client a en tête. La période ne sert que pour
     * les commandes sans heure — « pour midi », sans plus.
     *
     * @param OrderLineModel[] $orders
     * @return array<int, float>
     */
    public function dueInPeriod(array $orders, string $periodKey, int $fromMinutes, int $toMinutes): array
    {
        $out = [];
        foreach ($orders as $o) {
            $id = $o->getIdProduct();
            if ($id === null || $o->getQuantity() <= 0) {
                continue;
            }

            $in = $o->getDueTime() !== null
                ? ($o->getDueMinutes() >= $fromMinutes && $o->getDueMinutes() < $toMinutes)
                : ($o->getPeriod() === strtolower($periodKey));

            if ($in) {
                $out[$id] = ($out[$id] ?? 0.0) + $o->getQuantity();
            }
        }
        return $out;
    }

    /**
     * Total par produit, toute la journée — pour dire « dont N commandés » sur
     * une tuile sans rouvrir le carnet.
     *
     * @param OrderLineModel[] $orders
     * @return array<int, array{total: float, channels: array<string, float>, next: ?string}>
     */
    public function byProduct(array $orders): array
    {
        $out = [];
        foreach ($orders as $o) {
            $id = $o->getIdProduct();
            if ($id === null || $o->getQuantity() <= 0) {
                continue;
            }

            if (!isset($out[$id])) {
                $out[$id] = [
                    'total'    => 0.0,
                    'channels' => array_fill_keys(OrderLineModel::CHANNELS, 0.0),
                    'next'     => null,
                ];
            }

            $out[$id]['total'] += $o->getQuantity();
            $out[$id]['channels'][$o->getChannel()] += $o->getQuantity();

            // La plus proche échéance : c'est elle qui commande le geste.
            $due = $o->getDueTime();
            if ($due !== null && ($out[$id]['next'] === null || $due < $out[$id]['next'])) {
                $out[$id]['next'] = $due;
            }
        }
        return $out;
    }

    /**
     * Le carnet à venir, trié par échéance — ce qu'on lit avant de lancer.
     *
     * Les commandes déjà dues restent en tête plutôt que d'être écartées : une
     * commande en retard est le premier chose à traiter, pas la dernière.
     *
     * @param OrderLineModel[] $orders
     * @return OrderLineModel[]
     */
    public function upcoming(array $orders, int $nowMinutes, ?int $untilMinutes = null): array
    {
        $kept = array_values(array_filter(
            $orders,
            fn(OrderLineModel $o) => $o->getQuantity() > 0
                && ($untilMinutes === null || $o->getDueMinutes() <= $untilMinutes)
        ));

        usort($kept, function (OrderLineModel $a, OrderLineModel $b) use ($nowMinutes) {
            // En retard d'abord, puis par échéance : deux clés plutôt qu'un tri
            // sur l'écart absolu, qui remonterait une commande de 18 h avant
            // une de 11 h 30 quand il est 15 h.
            $la = $a->getDueMinutes() < $nowMinutes ? 0 : 1;
            $lb = $b->getDueMinutes() < $nowMinutes ? 0 : 1;
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            $c = $a->getDueMinutes() <=> $b->getDueMinutes();
            return $c !== 0 ? $c : strcasecmp((string)$a->getName(), (string)$b->getName());
        });

        return $kept;
    }

    /**
     * Compte par canal, pour les pastilles de filtre.
     *
     * @param OrderLineModel[] $orders
     * @return array<string, int>
     */
    public function counts(array $orders): array
    {
        $counts = array_fill_keys(OrderLineModel::CHANNELS, 0);
        $counts['all'] = 0;
        foreach ($orders as $o) {
            if ($o->getQuantity() <= 0) {
                continue;
            }
            $counts['all']++;
            $counts[$o->getChannel()]++;
        }
        return $counts;
    }

    /**
     * Additionne deux cartes produit → quantité.
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     * @return array<int, float>
     */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $id => $qty) {
            $a[$id] = ($a[$id] ?? 0.0) + $qty;
        }
        return $a;
    }
}
