# Demande ERP — filtrer les ventes par produit sur un créneau horaire

**Pour :** développeur back-office ERP L'Atelier By
**Besoin :** piloter la production par créneau (Kitchen, brique 1). Les créneaux
sont déjà configurés ; il manque une seule chose pour les exploiter.

---

## Le contexte, en une phrase

`GET /admin/sales-dayparts` sert bien les 3 créneaux (Matin 06:00–11:00, Midi
11:00–13:00, Après-midi 14:00–19:00), mais **aucun endpoint de vente ne les
consomme** : les ventes par produit ne sont disponibles que sur la journée
entière. Kitchen ne peut donc pas afficher « ce qui s'est vendu le matin, par
produit », ni prévoir par créneau.

## La demande, précise

Faire accepter à **UN** endpoint existant un filtre de créneau. Le plus adapté,
parce qu'il porte déjà `product_id`, `sold_qty`, le secteur et le lead-time :

```
GET /api/v1/shops/{id}/statistics/sales/product-category-groups
    ?date_from=YYYY-MM-DD
    &date_to=YYYY-MM-DD
    &sales_daypart_id=<id>        ← NOUVEAU (facultatif)
```

Fichier concerné (d'après le Swagger) : `v1/Routes/Sales/salesRoutes.php:27` et
son contrôleur.

### Comportement attendu

- **Sans** `sales_daypart_id` : comportement actuel inchangé (journée entière).
- **Avec** `sales_daypart_id=N` : ne compter que les ventes dont l'heure du
  ticket tombe dans `[time_from, time_to[` du créneau N (table
  `sales_dayparts`), pour chaque date de la fenêtre `[date_from, date_to]`.
- La **forme de la réponse ne change pas** — mêmes objets, seules les quantités
  et montants sont restreints au créneau.

### La jointure (indication, pas une contrainte d'implémentation)

Les lignes de vente sont dans `transaction_product`, rattachées à `transaction`
qui porte l'heure (`insert_timestamp` / `occurred_at_utc`). Le filtre revient à
ajouter, quand `sales_daypart_id` est fourni :

```sql
JOIN sales_dayparts dp ON dp.id = :sales_daypart_id
WHERE TIME(t.insert_timestamp) >= dp.time_from
  AND TIME(t.insert_timestamp) <  dp.time_to
```

(en gardant `t.id_shop = :shop` et la fenêtre de dates déjà en place.)

### Vérification (ce que Kitchen testera)

Aujourd'hui, quel que soit le filtre tenté (`sales_daypart_id`, `time_from/to`…),
le total est **identique à la journée** — preuve qu'il est ignoré :

```
product-category-groups?date=2026-08-20                    → 443 unités
product-category-groups?date=2026-08-20&sales_daypart_id=1 → 443 unités  ← devrait être < 443
```

Après correction, la somme des trois créneaux d'un jour doit égaler (à peu près)
le total journée, et chaque créneau en donner une part.

## Ce que ça débloque côté Kitchen

Immédiatement, sans autre changement back :

- **Écran « État période 1 »** : prévu / vendu / écart par produit, par créneau.
- **Prévision** : 6 semaines pondérées, même jour, même créneau, en bouclant
  `product-category-groups?date=D&sales_daypart_id=N` sur les 6 dates.
- **Ordonnancement / ETA** : `preparation_lead_time_hours` est déjà dans la
  réponse — l'heure de lancement se calcule sans endpoint supplémentaire.

Le moteur de prévision et l'assemblage sont déjà écrits et testés côté Kitchen ;
seul ce filtre manque pour les brancher sur la vraie donnée par créneau.

---

*Champs déjà présents dans la réponse (relevés le 27/08) : `product_id`,
`product_name`, `group_name`, `category_name`, `sector_id`, `sector_name`,
`preparation_lead_time_hours`, `sold_qty`, `full_product_equivalent`,
`total_earning`, `total_cost`, `margin_value`, `margin_percent`.*
