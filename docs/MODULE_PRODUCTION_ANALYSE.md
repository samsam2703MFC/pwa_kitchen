# Module « Pilotage de la production » — analyse préalable

> **Aucune ligne de code écrite.** Ce document est l'étape 0-2 demandée par le
> brief : inventaire Swagger, mapping écran → endpoint, algorithme de prévision,
> et la liste des trous à combler côté ERP avant de coder.
>
> Source : swagger de production `atelierby.tfbuddy.com/api/swagger/openapi.json`
> (933 routes), croisé avec des lectures réelles sur la prod (26-27/08/2026).

---

## 0. Deux constats qui commandent tout le reste

**a) 61 % des routes sont « runtime », sans contrat documenté.** Le swagger les
liste (méthode + chemin) mais indique explicitement *« Request, authorization
and response contracts are not inferred »*. Pour ces routes je connais le
chemin, pas la forme d'entrée/sortie garantie. J'ai relevé la forme réelle par
appel direct quand la route répond sans jeton ; sinon, **la forme est à
confirmer avec un vrai jeton avant de s'appuyer dessus.**

**b) Le module ne peut PAS être bâti sur les endpoints « production/* » du
brief historique** (`/shops/{id}/production/config`, `/production/products`,
`/stock`, `/mep`, `/production/batches`, `/baking`, `/ovens`) : **aucun n'existe
au swagger de prod.** En revanche l'ERP porte la donnée sous d'autres routes,
ci-dessous. C'est sur celles-là qu'il faut construire.

---

## 1. Inventaire des endpoints utiles (par besoin)

| Besoin | Endpoint | Contrat | Réel observé |
|---|---|---|---|
| **Prévision / planning** (qté prévue par produit et par tranche) | `GET /shops/{id}/statistics/production-planning` | runtime | Répond. `{summary, products[], time_distribution[]}`. Chaque tranche : `label, code, time_from, time_to, sold_quantity, full_product_equivalent`. Paramètres pressentis : `date_from, date_to, week_days[], distribution_mode`. **Non authentifié → `distribution_mode=whole_day`, products vide** : le détail par produit et par tranche demande un jeton + boutique. |
| **Ventes réelles par heure** (jour courant) | `GET /shops/{id}/statistics/sales/hourly-distribution/{date}` | runtime | Répond (liste). Vide sans jeton/boutique. |
| **Ventes du jour, comparaison** | `GET /shops/{id}/statistics/sales/compare-to-last-week/{date}` · `GET /shops/{id}/statistics/daily-summary` | runtime | Complément. |
| **Entrée en stock à la validation** | `POST /product-movements` | runtime, **POST seul** | Existe. Corps non documenté. |
| **Stock restant par produit** | `PATCH /shops/{id}/products/{id}/inventory` | runtime | **PATCH seul** — pas de GET documenté pour LIRE le stock (`/shops/{id}/stock` n'existe pas). À clarifier : où lit-on le stock vendable courant ? |
| **Tranches horaires paramétrables** (période 1 = 06:00–11:00, etc.) | `GET /admin/sales-dayparts` · `/active` · `GET/PUT/DELETE /{id}` | **documenté** (200/401/422) | Existe, demande un jeton admin. C'est le candidat naturel pour les « périodes » du brief. À confirmer : porte-t-il `time_from`/`time_to` par tranche ? |
| **Gamme de production** (étapes ordonnées + durées) | `GET /products/{id}/preparation-path` · `GET /preparation-paths/configured-product-ids` · `GET /preparation-batch-groups` | schéma confirmé (test) | Étape : `sort_order, description, duration_seconds, uses_oven, batch_group_id/name, batch_capacity, products_per_tray, trays_per_oven, photo_1..3_url`. **Déjà consommé par Kitchen.** |

`product-availability-periods` a été écarté : ce sont des **gammes saisonnières**
(« 🥖 Gamme Standard », `from_md=101`/`to_md=1231` = 1ᵉʳ janvier → 31 décembre),
pas des tranches intra-journée. Ne pas le confondre avec les périodes.

---

## 2. Mapping écran → endpoint

### Brique 1 — Écran « État période 1 »
Par produit : prévu · déjà produit · ventes réelles de la tranche · écart.

- **Tranches** : `GET /admin/sales-dayparts/active` → définit 06:00–11:00 & suivantes.
- **Prévu** : `GET /shops/{id}/statistics/production-planning?date_from&date_to&week_days&distribution_mode=<par daypart>` → `products[].{prévu par tranche}`.
- **Déjà produit** : somme des `product-movements` d'entrée de la journée sur la période — **or on ne peut pas les relire** (POST seul). ⚠️ voir Trou #1.
- **Ventes réelles de la tranche** : `GET /shops/{id}/statistics/sales/hourly-distribution/{date}` agrégé sur la tranche.
- **Écart** = prévu − (produit − ventes réelles), selon la définition retenue (à trancher).

### Brique 2 — Validation → entrée en stock
- **Écriture** : `POST /product-movements` (produit, qté, période, boutique ; utilisateur + horodatage côté serveur).
- **Traçage / correction / annulation** : ⚠️ **aucun GET/PATCH/DELETE** sur `product-movements`. Trou #1.
- **Blocage des périodes suivantes tant que P1 non validée** : état déduit des mouvements de P1 → suppose de pouvoir les **relire**. Trou #1 à nouveau.

### Brique 3 — Relances (périodes 2, 3, 4…)
- Mêmes endpoints que la brique 1, tranche par tranche (`sales-dayparts`).
- Correction par les **ventes réelles** (`hourly-distribution`) et le **stock restant** (source à confirmer, voir tableau).

### Brique 4 — Temps de préparation & ordonnancement
- **Gamme** : `GET /products/{id}/preparation-path` → étapes + `duration_seconds` + `batch_capacity`.
- **Durée totale pour une quantité** : ⚠️ **pas d'endpoint qui prend une quantité et renvoie la durée totale.** Trou #2. On la calcule à partir des étapes (voir §3).
- **Conflits d'équipement** : ⚠️ **pas d'inventaire d'équipement par boutique** (les `devices` sont des caisses, pas des fours). Seul `batch_group_name` figure dans l'étape. Trou #3.

---

## 3. Algorithme de prévision (formule exacte)

Pour un produit *p*, une boutique *s*, une tranche horaire *T* = [h₁,h₂) et le
jour de semaine *j* :

```
prévision(p,s,T,j) = agrégat( ventes(p,s,T,j,semaine k) )  pour k = 1..N
```

- **N** = fenêtre d'historique, **paramètre en base** (proposé : 6 semaines).
- **agrégat** : moyenne, ou moyenne pondérée si l'on privilégie les semaines
  récentes — proposé : pondération linéaire `poids(k) = (N+1−k)`, normalisée.
- **Même jour de semaine** *j* et **même tranche** *T* : un mardi matin se
  prévoit sur les mardis matin précédents.
- Source des ventes historiques par tranche : `production-planning` (agrégat
  serveur) **ou** `hourly-distribution/{date}` répété sur N dates (au client).
  À trancher selon ce que `production-planning` sait déjà agréger.

**Jours atypiques (fériés, événements)** — à exclure de l'historique. L'ERP ne
porte pas de calendrier de fériés au swagger : soit **table de paramètres en
base** (jours exclus par boutique/réseau), soit signalés à la main. Trou #4.

**Heures de rupture de stock** — une tranche où le produit était épuisé sous-
estime la demande. Deux options :
1. **Exclure** la tranche de l'historique (demande inconnue, pas nulle) ;
2. **Corriger** en remontant à la dernière pente de vente avant rupture.
Option 1 est plus sûre et plus simple. Elle **suppose de connaître les heures de
rupture** → il faut l'historique du stock par heure, que le swagger ne fournit
pas aujourd'hui. Trou #5.

**Correction jour courant (brique 3)** :
```
reste_à_produire(p,T) = max(0, prévision(p,T) − stock_restant(p) − déjà_produit(p,T≥maintenant))
```

**ETA / ordonnancement (brique 4)** :
```
durée_totale(p,q) = Σ_étapes  durée_étape × ⌈ q / batch_capacity_étape ⌉   (batch)
                    +  durée_étape                                          (non-batch)
heure_lancement(p) = heure_cible_rayon(T) − durée_totale(p,q)
conflit  ⇔  deux lancements se recouvrent sur le même batch_group / four
```

---

## 4. Ce qui manque (à créer côté ERP, je ne les invente pas)

| # | Besoin | Endpoint attendu | Contrat attendu |
|---|---|---|---|
| **1** | Relire / corriger / annuler les mouvements de production | `GET /shops/{id}/product-movements?date&type=production` · `PATCH /product-movements/{id}` · `DELETE /product-movements/{id}` | liste `{id, product_id, qty, daypart, user, created_at, cancelled_at?}` ; PATCH `{qty}` ; DELETE = annulation tracée |
| **2** | Durée de production pour une quantité | `GET /products/{id}/production-time?quantity=Q` **ou** confirmer qu'on calcule au client depuis `preparation-path` | `{total_seconds, steps:[{label, seconds, batch_capacity, equipment}]}` |
| **3** | Inventaire d'équipement par boutique (fours, pétrins…) pour les conflits | `GET /shops/{id}/production-equipment` | `[{id, name, type, batch_group_id?}]` |
| **4** | Jours atypiques à exclure de l'historique | paramètre en base + `GET /shops/{id}/atypical-days` | `[{date, reason}]` |
| **5** | Historique de rupture de stock par tranche | soit un flag dans `hourly-distribution`, soit `GET /shops/{id}/stockouts?date` | `[{product_id, time_from, time_to}]` |
| **6** | **Confirmer** le contrat de `production-planning` en mode par-tranche (auth + boutique) et de `product-movements` (corps du POST) | — | à relever avec un jeton valide |

---

## 5. Points à trancher ensemble avant de coder

1. **Périodes 2, 3, 4** : leurs horaires. `sales-dayparts` est-il la source
   (à confirmer qu'il porte `time_from`/`time_to`), ou on les paramètre ailleurs ?
2. **Fenêtre N** : 6 semaines par défaut ? Pondération des semaines récentes
   (oui/non, et quelle courbe) ?
3. **Ruptures** : exclure la tranche (proposé) ou corriger la pente ? Et
   d'abord, **comment connaître les heures de rupture** (Trou #5) ?
4. **Périmètre** : par boutique seulement, ou aussi consolidé labo central ?
5. **Définition de l'« écart »** : prévu − vendu, ou prévu − (produit − vendu) ?
6. **Traçage des mouvements** (Trou #1) : sans relecture, on ne peut ni bloquer
   les périodes suivantes de façon fiable, ni corriger/annuler. C'est le
   préalable technique n°1.
