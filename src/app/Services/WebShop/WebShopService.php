<?php

namespace App\Kitchen\app\Services\WebShop;

/**
 * Les règles des trois écrans de comptoir.
 *
 * Pures : ni HTTP, ni cookie, ni horloge injectée d'office. Le service ne fait
 * que mettre en forme ce que le webshop renvoie, et c'est ce qui le rend
 * vérifiable sans serveur — voir bin/webshop-test.php.
 *
 * Le vocabulaire du comptoir n'est pas celui de l'API : le webshop parle de
 * `status`, le comptoir parle de « à préparer », « prête », « remise ». La
 * traduction se fait ici, une fois, plutôt que dans chaque vue.
 */
class WebShopService
{
    /** Ce qui reste à faire, dans l'ordre où le comptoir le vit. */
    public const TODO  = 'todo';    // à préparer
    public const READY = 'ready';   // prête, en attente du client
    public const DONE  = 'done';    // remise ou livrée

    /**
     * L'étape suivante d'une commande, ou null quand il n'y en a plus.
     * C'est cette valeur que porte le bouton : un seul geste par ligne, et le
     * même à la même place sur toutes les lignes.
     */
    public function nextStatus(string $status): ?string
    {
        return match ($status) {
            'pending', 'confirmed' => 'preparing',
            'preparing'            => 'ready',
            'ready'                => 'delivered',
            default                => null,
        };
    }

    /** Le groupe d'une commande — ce qui décide de sa colonne et de sa couleur. */
    public function bucket(string $status): string
    {
        return match ($status) {
            'ready'                => self::READY,
            'delivered', 'done'    => self::DONE,
            default                => self::TODO,
        };
    }

    /**
     * Met en forme les commandes du jour.
     *
     * Trie par groupe puis par créneau : ce qui reste à faire d'abord, ce qui
     * est fini en bas. Un comptoir lit du haut vers le bas et s'arrête dès que
     * les lignes ne le concernent plus.
     */
    public function orders(?array $raw): ?array
    {
        if ($raw === null) {
            return null;                     // « pas servi » ≠ « aucune commande »
        }

        $order = [self::TODO => 0, self::READY => 1, self::DONE => 2];
        $rows  = [];

        foreach ($raw as $o) {
            $status = (string) ($o['statusKey'] ?? 'pending');
            $bucket = $this->bucket($status);
            $rows[] = [
                'id'       => (string) ($o['id'] ?? ''),
                'ref'      => (string) ($o['ref'] ?? '—'),
                'client'   => (string) ($o['client'] ?? '—'),
                'mode'     => (string) ($o['mode'] ?? ''),
                'slot'     => (string) ($o['slot'] ?? '—'),
                'total'    => (string) ($o['total'] ?? ''),
                'status'   => $status,
                'bucket'   => $bucket,
                'next'     => $this->nextStatus($status),
                'sortKey'  => sprintf('%d|%s', $order[$bucket], (string) ($o['slot'] ?? '')),
            ];
        }

        usort($rows, fn($a, $b) => strcmp($a['sortKey'], $b['sortKey']));

        return $rows;
    }

    /** Combien reste-t-il à faire — le chiffre de la pastille d'onglet. */
    public function countTodo(?array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        return count(array_filter($rows, fn($r) => $r['bucket'] !== self::DONE));
    }

    /**
     * Aplatit le catalogue de stock en lignes de produits.
     *
     * L'API le sert en catégories imbriquées, ce qui est bon pour un
     * back-office qui les replie. Au comptoir, on cherche UN produit : une
     * liste plate, avec sa catégorie en petit, se parcourt plus vite qu'un
     * arbre à ouvrir.
     */
    public function stock(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $rows = [];
        foreach ($raw as $cat) {
            $catName = (string) ($cat['cat'] ?? $cat['name'] ?? $cat['label'] ?? '—');
            foreach (($cat['produits'] ?? $cat['products'] ?? $cat['items'] ?? []) as $p) {
                // Le stock peut être servi sous plusieurs noms selon la source.
                // On les accepte tous plutôt que d'afficher « — » sur une
                // donnée qui est là, sous un autre nom.
                $qty = $p['stock'] ?? $p['qty'] ?? $p['quantite'] ?? $p['quantity'] ?? null;
                $rows[] = [
                    'cat'   => $catName,
                    'name'  => (string) ($p['nom'] ?? $p['name'] ?? '—'),
                    'qty'   => $qty === null ? null : (float) $qty,
                    // Rupture : zéro est une décision, null est une ignorance.
                    // Les confondre ferait afficher « rupture » sur un produit
                    // dont on ne sait rien.
                    'out'   => $qty !== null && (float) $qty <= 0,
                ];
            }
        }

        return $rows;
    }

    /** Combien de produits en rupture — le chiffre qui décide d'aller voir. */
    public function countOut(?array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        return count(array_filter($rows, fn($r) => $r['out']));
    }
}
