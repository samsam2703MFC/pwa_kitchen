<?php
/**
 * Jeux de données du serveur bouchon.
 *
 * Les horaires sont calculés par rapport à l'heure courante plutôt que figés :
 * un plan de cuisson daté de 06 h 00 ne montre rien d'intéressant quand on
 * teste à 15 h. Ici, il y a toujours une fournée au four.
 */

const MOCK_SHOP_ID = 1;

/**
 * ShopModel lit chacun de ces champs sans valeur de repli : en omettre un
 * suffit à couvrir les journaux d'avertissements. Ils sont tous fournis.
 */
function mock_shops(): array
{
    $shop = fn(int $id, string $name, string $city, string $zip, string $street, string $num) => [
        'id'                  => $id,
        'name'                => $name,
        'representative_name' => $name,
        'email'               => 'atelier' . $id . '@example.test',
        'street'              => $street,
        'street_num'          => $num,
        'city'                => $city,
        'zip'                 => $zip,
        'phone'               => '+32 2 000 00 0' . $id,
        'vat_number'          => 'BE0700.000.00' . $id,
        'opening_hours'       => '06:30',
        'closing_hours'       => '19:00',
    ];

    return [
        $shop(1, "L'Atelier — Bruxelles Centre", 'Bruxelles',        '1000', 'Rue du Marché aux Herbes', '42'),
        $shop(2, "L'Atelier — Louvain-la-Neuve", 'Louvain-la-Neuve', '1348', 'Grand-Place',              '7'),
        $shop(3, "L'Atelier — Namur",            'Namur',            '5000', 'Rue de Fer',               '18'),
    ];
}

/**
 * L'équipe du magasin.
 *
 * `on_schedule` est servi ici pour que le filtre « personnel actif » soit
 * réellement exerçable : un absent (Sofia) et un nom sans deuxième mot (Ali)
 * y sont volontairement, parce que ce sont les deux cas qui cassent — le
 * premier le filtre, le second le calcul d'initiales.
 *
 * Le PIN reste dans la réponse : c'est le serveur qui le vérifie, et le front
 * le retire avant d'atteindre la moindre vue (StaffService).
 */
function mock_employees(): array
{
    return [
        ['id' => 41, 'name' => 'Nathan Colin',   'pin' => '1234', 'on_schedule' => true],
        ['id' => 42, 'name' => 'Aïcha Benali',   'pin' => '2345', 'on_schedule' => true],
        ['id' => 43, 'name' => 'Marek Kowalski', 'pin' => '3456', 'on_schedule' => true],
        ['id' => 44, 'name' => 'Ali',            'pin' => '4567', 'on_schedule' => true],
        ['id' => 45, 'name' => 'Sofia Ferreira', 'pin' => '5678', 'on_schedule' => false],
    ];
}

/**
 * Le planning d'un jour — la seule source du personnel depuis le 13/08/2026.
 *
 * Il porte les personnes : identifiant ET nom. Volontairement bruité, parce
 * qu'un bouchon qui ne sert que des données propres ne sert à rien :
 *   • « id » est celui du SERVICE, pas de l'employé — le confondre
 *     attribuerait la tâche au mauvais identifiant ;
 *   • un identifiant rendu en chaîne là où les autres sont des nombres ;
 *   • une personne qui fait deux services dans la journée ;
 *   • une fiche imbriquée plutôt qu'à plat.
 */
function mock_schedule(string $date): array
{
    return [
        ['id' => 901, 'employee_id' => 41,   'name' => 'Nathan Colin',
         'date' => $date, 'start' => '06:00', 'end' => '14:00'],
        ['id' => 902, 'employee_id' => '43', 'name' => 'Marek Kowalski',
         'date' => $date, 'start' => '06:00', 'end' => '14:00'],
        ['id' => 903, 'employee' => ['id' => 44, 'name' => 'Ali'],
         'date' => $date, 'start' => '13:00', 'end' => '20:00'],
        // Ali revient le soir : deux lignes, une seule personne à l'écran.
        ['id' => 904, 'employee' => ['id' => 44, 'name' => 'Ali'],
         'date' => $date, 'start' => '20:00', 'end' => '22:00'],
    ];
}

function mock_ovens(): array
{
    return [
        ['id' => 1, 'name' => 'Four 1 — Rotatif', 'levels' => 8, 'temp_min' => 160, 'temp_max' => 250],
        ['id' => 2, 'name' => 'Four 2 — Ventilé', 'levels' => 5, 'temp_min' => 140, 'temp_max' => 220],
        ['id' => 3, 'name' => 'Four 3 — Sole',    'levels' => 4, 'temp_min' => 200, 'temp_max' => 280],
    ];
}

/**
 * Catalogue de production.
 *
 * Trois cas limites y sont volontairement représentés, parce que ce sont ceux
 * qui cassent : un produit sans taille de fournée, un produit inactif, et un
 * produit absent du profil de ventes.
 *
 * `is_pdb` — « prep day before » — dit si le produit se façonne la veille.
 * Seuls ceux-là sont proposés à l'encodage de la MEP du lendemain : la
 * pâtisserie du jour et le traiteur restent hors du sélecteur.
 *
 * `sector` sépare les deux ateliers du magasin — boulangerie et traiteur. Ce
 * sont deux équipes et souvent deux pièces, mais une seule vitrine : d'où un
 * filtre en tête des écrans plutôt qu'un second module.
 *
 * `is_pdm` dit que le produit se pilote à un plancher de vitrine plutôt qu'à la
 * prévision de ventes, et `pdm_minimums` donne ce plancher période par période.
 * Deux baguettes en rayon ne vendent pas, même si deux baguettes suffisent à la
 * demande de 16 h.
 */
function mock_products(): array
{
    return [
        ['id_product' => 6700106, 'name' => 'Croissant pur beurre',      'id_category' => 12, 'category_name' => 'Viennoiserie',
         'periods' => ['morning', 'noon'], 'batch_size' => 24, 'unit_name' => 'pc', 'production_lead_minutes' => 40, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 48, 'noon' => 24, 'afternoon' => 12]],
        ['id_product' => 6700110, 'name' => 'Pain au chocolat',          'id_category' => 12, 'category_name' => 'Viennoiserie',
         'periods' => ['morning', 'noon'], 'batch_size' => 24, 'unit_name' => 'pc', 'production_lead_minutes' => 40, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 36, 'noon' => 18, 'afternoon' => 8]],
        ['id_product' => 6700120, 'name' => 'Baguette tradition',        'id_category' => 14, 'category_name' => 'Boulangerie',
         'periods' => ['morning', 'noon', 'afternoon'], 'batch_size' => 12, 'unit_name' => 'pc', 'production_lead_minutes' => 20, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 24, 'noon' => 24, 'afternoon' => 12]],
        ['id_product' => 6700130, 'name' => 'Tarte au citron meringuée', 'id_category' => 16, 'category_name' => 'Pâtisserie',
         'periods' => ['noon', 'afternoon'], 'batch_size' => 6, 'unit_name' => 'pc', 'production_lead_minutes' => 0, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => false],
        // Pas de batch_size : la proposition tombe à l'unité, et l'écran le dit.
        ['id_product' => 6700140, 'name' => 'Macaron pistache',          'id_category' => 16, 'category_name' => 'Pâtisserie',
         'periods' => ['afternoon'], 'unit_name' => 'pc', 'production_lead_minutes' => 0, 'is_active' => true, 'is_pdb' => false, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => false],
        // Inactif : jamais affiché, jamais proposé, même à stock zéro.
        ['id_product' => 6700150, 'name' => 'Bûche de Noël',             'id_category' => 16, 'category_name' => 'Pâtisserie',
         'periods' => ['afternoon'], 'batch_size' => 4, 'unit_name' => 'pc', 'production_lead_minutes' => 0, 'is_active' => false, 'is_pdb' => false, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => false],
        // Absent du profil de ventes : aucune recuisson proposée.
        ['id_product' => 6700160, 'name' => 'Sandwich club',             'id_category' => 18, 'category_name' => 'Traiteur',
         'periods' => ['noon'], 'batch_size' => 10, 'unit_name' => 'pc', 'production_lead_minutes' => 0, 'is_active' => true, 'is_pdb' => false, 'sector' => 'catering', 'sector_name' => 'Traiteur', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 0, 'noon' => 20, 'afternoon' => 6]],
        // Les produits du plan de cuisson. Ils DOIVENT porter les mêmes ids
        // ici et dans mock_baking_batches() : c'est par l'id que l'écran de
        // période lit l'étape d'un produit, et deux catalogues qui divergent
        // affichent un four sous le nom d'un autre produit.
        ['id_product' => 6700190, 'name' => 'Chausson aux pommes',       'id_category' => 12, 'category_name' => 'Viennoiserie',
         'periods' => ['morning', 'noon'], 'batch_size' => 18, 'unit_name' => 'pc', 'production_lead_minutes' => 20, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 18, 'noon' => 9, 'afternoon' => 0]],
        ['id_product' => 6700200, 'name' => 'Pain céréales',             'id_category' => 14, 'category_name' => 'Boulangerie',
         'periods' => ['morning', 'noon', 'afternoon'], 'batch_size' => 10, 'unit_name' => 'pc', 'production_lead_minutes' => 30, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 20, 'noon' => 10, 'afternoon' => 10]],
        ['id_product' => 6700210, 'name' => 'Éclair chocolat',           'id_category' => 16, 'category_name' => 'Pâtisserie',
         'periods' => ['morning', 'noon'], 'batch_size' => 12, 'unit_name' => 'pc', 'production_lead_minutes' => 20, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => false],
        ['id_product' => 6700220, 'name' => 'Chouquette glacée',         'id_category' => 16, 'category_name' => 'Pâtisserie',
         'periods' => ['morning', 'noon'], 'batch_size' => 30, 'unit_name' => 'pc', 'production_lead_minutes' => 15, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => false],
        ['id_product' => 6700230, 'name' => 'Cookie chocolat',           'id_category' => 16, 'category_name' => 'Pâtisserie',
         'periods' => ['noon', 'afternoon'], 'batch_size' => 20, 'unit_name' => 'pc', 'production_lead_minutes' => 12, 'is_active' => true, 'is_pdb' => false, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 0, 'noon' => 20, 'afternoon' => 20]],
        // ── Traiteur ──
        // Le second atelier. Mêmes écrans, mêmes gestes, mais ses propres
        // catégories : c'est ce qui rend le sélecteur de secteur utile plutôt
        // que décoratif — filtrer par catégorie ne suffirait pas, il en faudrait
        // quatre à cocher.
        ['id_product' => 6700300, 'name' => 'Sandwich jambon-beurre',    'id_category' => 20, 'category_name' => 'Sandwichs',
         'periods' => ['morning', 'noon'], 'batch_size' => 10, 'unit_name' => 'pc', 'production_lead_minutes' => 0, 'is_active' => true, 'is_pdb' => false,
         'sector' => 'catering', 'sector_name' => 'Traiteur', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 6, 'noon' => 24, 'afternoon' => 8]],
        ['id_product' => 6700310, 'name' => 'Salade César',              'id_category' => 21, 'category_name' => 'Salades',
         'periods' => ['noon', 'afternoon'], 'batch_size' => 8, 'unit_name' => 'pc', 'production_lead_minutes' => 0, 'is_active' => true, 'is_pdb' => false,
         'sector' => 'catering', 'sector_name' => 'Traiteur', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 0, 'noon' => 12, 'afternoon' => 6]],
        ['id_product' => 6700320, 'name' => 'Quiche lorraine',           'id_category' => 22, 'category_name' => 'Traiteur chaud',
         'periods' => ['noon', 'afternoon'], 'batch_size' => 6, 'unit_name' => 'pc', 'production_lead_minutes' => 45, 'is_active' => true, 'is_pdb' => true,
         'sector' => 'catering', 'sector_name' => 'Traiteur', 'is_pdm' => false],
        // Traiteur sans plancher de vitrine : il ne se fait que sur commande,
        // et c'est le carnet qui le déclenche — pas la présentation.
        ['id_product' => 6700330, 'name' => 'Plateau apéritif 20 pièces', 'id_category' => 22, 'category_name' => 'Traiteur chaud',
         'periods' => ['afternoon'], 'batch_size' => 2, 'unit_name' => 'pc', 'production_lead_minutes' => 30, 'is_active' => true, 'is_pdb' => false,
         'sector' => 'catering', 'sector_name' => 'Traiteur', 'is_pdm' => false],
        ['id_product' => 6700240, 'name' => 'Pain de campagne',          'id_category' => 14, 'category_name' => 'Boulangerie',
         'periods' => ['morning', 'noon'], 'batch_size' => 8, 'unit_name' => 'pc', 'production_lead_minutes' => 35, 'is_active' => true, 'is_pdb' => true, 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 16, 'noon' => 8, 'afternoon' => 8]],
    ];
}

function mock_initial_stock(): array
{
    return [
        6700106 => 10, 6700110 => 60, 6700120 => 30,
        6700130 => 2,  6700140 => 3,  6700150 => 0, 6700160 => 0,
        6700190 => 8,  6700200 => 24, 6700210 => 4, 6700220 => 45,
        6700230 => 60, 6700240 => 6,
        // Traiteur : deux produits sous leur plancher de vitrine, un au-dessus.
        6700300 => 4,  6700310 => 3,  6700320 => 9, 6700330 => 0,
    ];
}

/** Profil de ventes : une vraie courbe de journée, pas un plateau. */
function mock_sales_profile(): array
{
    $slots = [];
    for ($m = 6 * 60; $m < 19 * 60; $m += 30) {
        $slots[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    // Pic du matin, creux de 10 h, pic de midi, fin d'après-midi qui retombe.
    $shape = [0.3,0.6,1.4,2.2,2.6,2.1,1.5,1.0,1.0,1.1,1.6,2.4,2.8,2.2,1.4,0.9,0.8,0.9,1.1,1.3,1.2,0.9,0.6,0.4,0.3,0.2];
    $rates = [
        6700106 => 2.3, 6700110 => 1.5, 6700120 => 1.15, 6700130 => 0.58, 6700140 => 0.77,
        6700300 => 1.1, 6700310 => 0.5, 6700320 => 0.35,
    ];

    $products = [];
    foreach ($rates as $id => $r) {
        $products[] = [
            'id_product' => $id,
            'expected'   => array_map(fn($s) => round($r * $s, 2), $shape),
        ];
    }

    return [
        'granularity_minutes' => 30,
        'weeks'               => 6,
        'weekday_only'        => true,
        'samples'             => 6,
        'slots'               => $slots,
        'products'            => $products,
    ];
}

/**
 * Le carnet de commandes du jour — magasin, click & collect, livraison.
 *
 * Calé sur l'heure courante comme le plan de cuisson : à toute heure de la
 * journée il y a une commande en retard, une pour tout de suite et deux pour
 * plus tard. Les trois canaux y sont, et les deux secteurs — sinon on ne
 * verrait jamais que le carnet change en changeant d'atelier.
 *
 * Une commande est due : elle s'ajoute aux ventes prévues, elle ne les remplace
 * pas. C'est ce qui la distingue d'une moyenne, et c'est pour ça qu'elle a sa
 * propre route plutôt qu'une correction du profil de ventes.
 */
function mock_orders(int $nowMinutes): array
{
    $clock = fn(int $m) => sprintf('%02d:%02d', intdiv(max(0, $m), 60) % 24, max(0, $m) % 60);

    // [décalage, produit, nom, catégorie, quantité, canal, référence]
    $book = [
        [-45, 6700120, 'Baguette tradition',         'Boulangerie',    24, 'shop',     'CMD-1042'],
        [-10, 6700300, 'Sandwich jambon-beurre',     'Sandwichs',      15, 'click',    'WEB-8817'],
        [ 20, 6700106, 'Croissant pur beurre',       'Viennoiserie',   36, 'click',    'WEB-8823'],
        [ 45, 6700330, 'Plateau apéritif 20 pièces', 'Traiteur chaud',  4, 'delivery', 'LIV-2291'],
        [ 90, 6700310, 'Salade César',               'Salades',        12, 'delivery', 'LIV-2294'],
        [120, 6700200, 'Pain céréales',              'Boulangerie',    10, 'shop',     'CMD-1051'],
        // Sans heure : « pour midi », sans plus. C'est la période qui la place.
        [null, 6700320, 'Quiche lorraine',           'Traiteur chaud',  6, 'shop',     'CMD-1053'],
    ];

    $rows = [];
    foreach ($book as $i => $o) {
        [$off, $idProduct, $name, $cat, $qty, $channel, $ref] = $o;
        $rows[] = [
            'id_order'      => 9100 + $i,
            'id_product'    => $idProduct,
            'name'          => $name,
            'category_name' => $cat,
            'quantity'      => $qty,
            'channel'       => $channel,
            'due_time'      => $off === null ? null : $clock($nowMinutes + $off),
            'period'        => $off === null ? 'noon' : null,
            'reference'     => $ref,
            'unit_name'     => 'pc',
        ];
    }
    return $rows;
}

/** MEP du jour : préparée hier, en attente de validation. */
function mock_mep_today(string $date): array
{
    return [
        'date'        => $date,
        'prepared_at' => date('Y-m-d 17:40:00', strtotime($date . ' -1 day')),
        'status'      => 'PREPARED',
        'lines'       => [
            ['id' => 4401, 'id_product' => 6700106, 'name' => 'Croissant pur beurre', 'category_name' => 'Viennoiserie',
             'period' => 'morning', 'quantity_planned' => 120, 'quantity_validated' => null, 'unit_name' => 'pc', 'status' => 'PREPARED', 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 48, 'noon' => 24, 'afternoon' => 12]],
            ['id' => 4402, 'id_product' => 6700110, 'name' => 'Pain au chocolat', 'category_name' => 'Viennoiserie',
             'period' => 'morning', 'quantity_planned' => 80, 'quantity_validated' => null, 'unit_name' => 'pc', 'status' => 'PREPARED', 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 36, 'noon' => 18, 'afternoon' => 8]],
            ['id' => 4403, 'id_product' => 6700120, 'name' => 'Baguette tradition', 'category_name' => 'Boulangerie',
             'period' => 'morning', 'quantity_planned' => 60, 'quantity_validated' => null, 'unit_name' => 'pc', 'status' => 'PREPARED', 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 24, 'noon' => 24, 'afternoon' => 12]],
            ['id' => 4404, 'id_product' => 6700130, 'name' => 'Tarte au citron meringuée', 'category_name' => 'Pâtisserie',
             'period' => 'noon', 'quantity_planned' => 12, 'quantity_validated' => null, 'unit_name' => 'pc', 'status' => 'PREPARED', 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => false],
        ],
    ];
}

/** MEP du lendemain : brouillon partiel, pour tester la reprise de saisie. */
function mock_mep_tomorrow(string $date): array
{
    return [
        'date'        => $date,
        'prepared_at' => date('Y-m-d H:i:s'),
        'status'      => 'PREPARED',
        'lines'       => [
            ['id' => 4501, 'id_product' => 6700106, 'name' => 'Croissant pur beurre', 'category_name' => 'Viennoiserie',
             'period' => 'morning', 'quantity_planned' => 144, 'quantity_validated' => null, 'unit_name' => 'pc', 'status' => 'PREPARED', 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 48, 'noon' => 24, 'afternoon' => 12]],
            ['id' => 4502, 'id_product' => 6700120, 'name' => 'Baguette tradition', 'category_name' => 'Boulangerie',
             'period' => 'morning', 'quantity_planned' => 72, 'quantity_validated' => null, 'unit_name' => 'pc', 'status' => 'PREPARED', 'sector' => 'bakery', 'sector_name' => 'Boulangerie', 'is_pdm' => true, 'pdm_minimums' => ['morning' => 24, 'noon' => 24, 'afternoon' => 12]],
        ],
    ];
}

/**
 * Plan de cuisson calé sur l'instant présent.
 *
 * Les décalages sont donnés en minutes autour de « maintenant » : à toute
 * heure de la journée, l'écran montre une fournée au four, une en finition,
 * une qui attend. Sinon il faudrait tester à 06 h 40 pour voir quelque chose.
 */
function mock_baking_batches(int $nowMinutes): array
{
    $clock = fn(int $m) => sprintf('%02d:%02d', intdiv(max(0, $m), 60) % 24, max(0, $m) % 60);

    // [décalage du début de préparation, produit, four, °C, prép, cuisson, finition, rayon]
    $plan = [
        [-80, 6700210, 'Éclair chocolat',      'Pâtisserie',   36, 2, 'Four 2 — Ventilé', 180, 30, 20, ['PIECE', 'Nappage', 1],           10],
        [-40, 6700220, 'Chouquette glacée',    'Pâtisserie',   30, 1, 'Four 1 — Rotatif', 175, 15, 15, ['PIECE', 'Glaçage', 0.5],          5],
        [-20, 6700120, 'Baguette tradition',   'Boulangerie',  48, 3, 'Four 3 — Sole',    240, 20, 22, ['LOT', 'Ressuage', 20],            5],
        [-50, 6700106, 'Croissant pur beurre', 'Viennoiserie', 48, 1, 'Four 1 — Rotatif', 175, 20, 18, ['LOT', 'Refroidissement', 15],     5],
        [-35, 6700190, 'Chausson aux pommes',  'Viennoiserie', 36, 1, 'Four 1 — Rotatif', 180, 15, 20, ['LOT', 'Refroidissement', 10],     5],
        [-20, 6700200, 'Pain céréales',        'Boulangerie',  20, 3, 'Four 3 — Sole',    230, 20, 30, ['LOT', 'Ressuage', 25],            5],
        [ 10, 6700106, 'Croissant pur beurre', 'Viennoiserie', 48, 1, 'Four 1 — Rotatif', 175, 20, 18, ['LOT', 'Refroidissement', 15],     5],
        [ 40, 6700230, 'Cookie chocolat',      'Pâtisserie',   40, 2, 'Four 2 — Ventilé', 165, 10, 12, ['LOT', 'Refroidissement', 10],     0],
    ];

    $batches = [];
    foreach ($plan as $i => $p) {
        [$off, $idProduct, $name, $cat, $qty, $oven, $ovenName, $temp, $prep, $cook, $fin, $shelf] = $p;

        $prepStart = $nowMinutes + $off;
        $cookStart = $prepStart + $prep + 5;   // cinq minutes entre la fin de la prép et l'enfournement
        $finStart  = $cookStart + $cook;
        $finDur    = $fin[0] === 'PIECE' ? $qty * $fin[2] : $fin[2];

        // Le statut découle des horaires au moment de la génération ; il est
        // ensuite persisté dans l'état, et ce sont les boutons qui le font
        // avancer — pas l'horloge.
        if ($nowMinutes < $prepStart)                    { $status = 'PLANNED'; }
        elseif ($nowMinutes < $prepStart + $prep)        { $status = 'PREPARING'; }
        elseif ($nowMinutes < $cookStart)                { $status = 'READY_TO_BAKE'; }
        elseif ($nowMinutes < $finStart)                 { $status = 'BAKING'; }
        elseif ($nowMinutes < $finStart + $finDur)       { $status = 'FINISHING'; }
        else                                             { $status = 'DONE'; }

        $batch = [
            'id'                  => 5501 + $i,
            'id_product'          => $idProduct,
            'name'                => $name,
            'category_name'       => $cat,
            'quantity'            => $qty,
            'unit_name'           => 'pc',
            'id_oven'             => $oven,
            'oven_name'           => $ovenName,
            'temperature'         => $temp,
            'prep_start'          => $clock($prepStart),
            'prep_minutes'        => $prep,
            'cook_start'          => $clock($cookStart),
            'cook_minutes'        => $cook,
            'finish_type'         => $fin[0],
            'finish_label'        => $fin[1],
            'shelf_delay_minutes' => $shelf,
            'status'              => $status,
            'prep_started_at'     => null,
            'cook_started_at'     => null,
            'finish_started_at'   => null,
        ];
        if ($fin[0] === 'PIECE') {
            $batch['finish_per_piece_minutes'] = $fin[2];
        } else {
            $batch['finish_minutes'] = $fin[2];
        }
        $batches[] = $batch;
    }

    return $batches;
}

/**
 * Checklists du jour.
 *
 * Ce que l'écran lit : le nom, l'heure d'exécution, et l'avancement — c'est
 * l'avancement qui décide de la barre et du choix de la checklist ouverte.
 */
function mock_checklists(): array
{
    return [
        ['id' => 1, 'name' => 'Ouverture du magasin', 'execution_time' => '06:00:00', 'tasks_done' => 1, 'tasks_total' => 5],
        ['id' => 2, 'name' => 'Contrôles HACCP',      'execution_time' => '11:00:00', 'tasks_done' => 0, 'tasks_total' => 3],
        ['id' => 3, 'name' => 'Fermeture',            'execution_time' => '19:00:00', 'tasks_done' => 0, 'tasks_total' => 4],
    ];
}

function mock_checklist_progress(int $id): array
{
    // Le bouchon renvoyait « Ouverture du magasin » quelle que soit la
    // checklist demandée. Une vérification d'écran y lisait alors un titre qui
    // contredisait la sélection, et faisait chercher un bug dans le contrôleur.
    $par_liste = [
        1 => ['Ouverture du magasin', '06:00:00', [
            ['Relevé température chambre froide', 'DONE',    '06:00:00', true,  true],
            ['Nettoyage du plan de travail',      'PENDING', '06:15:00', false, false],
            ['Contrôle des DLC en vitrine',       'FAILED',  '06:30:00', true,  false],
            ['Lavage du sol du laboratoire',      'PENDING', '06:45:00', false, false],
            ['Relevé température four',           'PENDING', '07:00:00', true,  true],
        ]],
        2 => ['Contrôles HACCP', '11:00:00', [
            ['Températures des vitrines réfrigérées', 'PENDING', '11:00:00', true,  true],
            ['Traçabilité des lots du jour',          'PENDING', '11:15:00', true,  false],
            ['Contrôle des huiles de friture',        'PENDING', '11:30:00', false, false],
        ]],
        3 => ['Fermeture', '19:00:00', [
            ['Nettoyage des vitrines',        'PENDING', '19:00:00', true,  false],
            ['Invendus : pesée et sortie',    'PENDING', '19:15:00', true,  true],
            ['Nettoyage du four',             'PENDING', '19:30:00', false, false],
            ['Fermeture de caisse',           'PENDING', '19:45:00', true,  false],
        ]],
    ];

    [$nom, $heure, $lignes] = $par_liste[$id] ?? $par_liste[1];

    $tasks = [];
    foreach ($lignes as $i => [$name, $status, $time, $mandatory, $photo]) {
        $t = [
            'task_id'        => $id * 100 + $i + 1,
            'name'           => $name,
            'status'         => $status,
            'is_mandatory'   => $mandatory,
            'requires_photo' => $photo,
            'execution_time' => $time,
        ];
        if ($status === 'DONE') {
            $t['completed_by'] = 'Nathan Colin';
            $t['completed_at'] = date('Y-m-d') . ' 06:12:00';
            $t['note']         = '3 °C — conforme';
        }
        if ($status === 'FAILED') {
            $t['completed_by'] = 'Aïcha Benali';
            $t['completed_at'] = date('Y-m-d') . ' 06:34:00';
            $t['note']         = 'Vitrine en cours de reparation, DLC controlees en reserve';
        }
        $tasks[] = $t;
    }

    $done = count(array_filter($tasks, fn($t) => $t['status'] === 'DONE'));

    return [
        'checklist' => ['id' => $id, 'name' => $nom, 'execution_time' => $heure],
        'summary'   => ['total' => count($tasks), 'done' => $done, 'not_done' => count($tasks) - $done],
        'tasks'     => $tasks,
    ];
}

/**
 * La table pwa_kitchen_param, telle que l'endpoint /devices/{id}/configuration la sert.
 *
 * Le jeu est celui du seed de docs/BACKEND_A_FAIRE.md §8.4 : il reproduit
 * exactement ce que les tablettes affichent sans configuration. Un bouchon qui
 * servirait autre chose ferait croire a un changement la ou il n'y en a pas.
 *
 * MOCK_MODES_OFF permet d'en retirer des cases pour verifier a l'ecran :
 *     MOCK_MODES_OFF=production:complaints,gestion:knowledge php -S ...
 */
function mock_device_config(): array
{
    $lignes = [
        ['production', 'dashboard',  1, 10],
        ['production', 'production', 1, 20],
        ['production', 'checklists', 1, 30],
        ['production', 'orders',     1, 40],
        ['production', 'knowledge',  0, 50],
        ['production', 'complaints', 0, 60],

        ['gestion',    'dashboard',  1, 10],
        ['gestion',    'checklists', 1, 20],
        ['gestion',    'knowledge',  1, 30],
        ['gestion',    'complaints', 1, 40],

        ['webshop',    'webshop',    0, 10],
        ['webshop',    'ws_prep',    1, 20],
        ['webshop',    'ws_stock',   1, 30],
        ['webshop',    'ws_board',   1, 40],
    ];

    $off = [];
    foreach (explode(',', (string)getenv('MOCK_MODES_OFF')) as $paire) {
        $paire = trim($paire);
        if ($paire !== '') {
            $off[$paire] = true;
        }
    }

    $modes = [];
    foreach ($lignes as [$mode, $feature, $inTabbar, $ordre]) {
        if (isset($off[$mode . ':' . $feature])) {
            continue;   // is_enabled = 0
        }
        $modes[$mode]['nav'][$ordre] = $feature;
        if ($inTabbar) {
            $modes[$mode]['tabs'][$ordre] = $feature;
        }
    }

    // « nav » porte tout ce qui est actif ; les vues internes du WebShop ne
    // vivent que dans la barre du bas.
    foreach ($modes as $mode => &$m) {
        foreach (['nav', 'tabs'] as $cle) {
            $liste = $m[$cle] ?? [];
            ksort($liste);
            $m[$cle] = array_values(array_filter(
                $liste,
                fn($f) => $cle === 'tabs' || !str_starts_with($f, 'ws_')
            ));
        }
    }
    unset($m);

    // Pas de « mode » : il reste un reglage de la tablette, pas du serveur.
    // Le servir ici ferait croire qu'il agit — voir §8.6 du document de
    // passation.
    return ['modes' => $modes];
}

/**
 * Chargement de la fixture reelle de la boutique 2.
 *
 * ── Un vrai releve, pas une invention ──
 * tools/mock-api/fixtures/shop2-real.json est une CAPTURE des reponses reelles
 * de la prod (atelierby.tfbuddy.com, boutique 2 « Corbais », relevee le
 * 27/08/2026) : ventes par produit sur 7 dates (le jour meme + les 6 memes
 * jours de semaine precedents) et parcours de preparation reels. Le bouchon ne
 * fabrique plus de produits : il rejoue ce que l'API a servi, pour que l'ecran
 * se developpe sur le vrai catalogue et la vraie prevision.
 *
 * @return array<string, mixed>
 */
function mock_shop2_fixture(): array
{
    static $fx = null;
    if ($fx === null) {
        $path = __DIR__ . '/fixtures/shop2-real.json';
        $fx = is_file($path) ? (json_decode((string)file_get_contents($path), true) ?: []) : [];
    }
    return $fx;
}

/**
 * Le parcours de preparation d'un produit — releve reel.
 *
 * Rejoue la reponse reelle : la Baguette Tradition 500 g. (1300003) porte ses
 * 3 gestes reels (mettre sur grille ; cuisson au four, batch « Boulangerie »,
 * capacite 30 ; ressuage). Un produit sans parcours repond `configured: false`,
 * exactement comme l'API — la PWA doit distinguer « pas de parcours » d'une
 * route muette.
 */
function mock_preparation_path(int $productId): array
{
    $paths = mock_shop2_fixture()['preparation_paths'] ?? [];
    $p = $paths[(string)$productId] ?? null;

    return is_array($p) ? $p : ['product_id' => $productId, 'configured' => false, 'steps' => []];
}

/**
 * La fiche technique d'un produit.
 *
 * Minimale a dessein : elle n'existe ici que pour pouvoir OUVRIR l'ecran et y
 * regarder le parcours de preparation. Elle porte quand meme l'ancienne
 * preparation (`preparation.steps`), parce que c'est elle qui doit reprendre
 * la main quand le parcours n'est pas configure ou que sa route se tait — et
 * qu'un repli qu'on ne peut pas voir n'est pas verifie.
 */
function mock_technical_sheet(int $productId): array
{
    return [
        'object' => [
            'id' => $productId,
            'name' => 'Pain de campagne 350 g',
            'category_name' => 'Boulangerie',
            'description' => 'Pâte au levain naturel, longue fermentation.',
        ],
        'preparation' => [
            'types' => [
                ['id' => 1, 'name' => 'Pétrissage', 'duration_second' => 240],
                ['id' => 2, 'name' => 'Cuisson',    'duration_second' => 1320],
            ],
            'steps' => [
                ['step_number' => 1, 'step_description' => 'Pétrir, pointer, façonner.'],
                ['step_number' => 2, 'step_description' => 'Cuire à 240 °C.'],
            ],
        ],
    ];
}

/**
 * Les postes d'un employe — forme EmployeePosition du swagger.
 * Nathan (41) boulanger, Aicha (42) vente, Ali (44) traiteur. Marek (43) SANS
 * poste : l'ecran ne doit rien afficher sous son nom.
 */
function mock_employee_positions(int $id): array
{
    $map = [
        41 => [['id' => 1, 'name' => 'Boulanger', 'level_id' => 2, 'level_name' => 'Confirme', 'level_order' => 2]],
        42 => [['id' => 2, 'name' => 'Vente',     'level_id' => 1, 'level_name' => 'Base',     'level_order' => 1]],
        44 => [['id' => 3, 'name' => 'Traiteur',  'level_id' => 1, 'level_name' => 'Base',     'level_order' => 1]],
    ];
    return $map[$id] ?? [];
}

/**
 * Ventes par produit d'une journee — releve reel de la boutique 2.
 *
 * Rejoue la capture : pour une date presente dans la fixture (le jour meme ou
 * l'un des 6 memes jours de semaine precedents), renvoie les vraies lignes
 * produit servies par l'API — identifiants, noms, `group_name` (la vraie
 * structure de production), et `sold_qty` reels. Une date hors capture renvoie
 * une liste vide : le bouchon n'invente aucune vente.
 */
function mock_product_category_groups(string $date): array
{
    return mock_shop2_fixture()['sales_by_date'][$date] ?? [];
}
