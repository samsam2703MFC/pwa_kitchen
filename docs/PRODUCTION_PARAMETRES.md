# Production — paramètres et vérification de la prévision

## Les paramètres

Quatre réglages pilotent les propositions de recuisson. Ils vivent dans
`config/app.php` et sont **écrasés par l'API** dès que
`GET /shops/{id}/production/config` répond : deux magasins n'ouvrent pas aux
mêmes heures, et une constante partagée serait fausse pour l'un des deux.

| Paramètre | Constante | Défaut | Ce qu'il change |
|---|---|---|---|
| Fenêtre de prévision | `PRODUCTION_FORECAST_HOURS` | `2` | Sur combien de temps on projette les ventes. Plus large = on enfourne plus tôt et plus gros ; plus étroit = on colle à la demande, au risque de la rupture |
| Profondeur d'historique | `PRODUCTION_HISTORY_WEEKS` | `6` | Combien de mêmes jours de semaine entrent dans la moyenne. Court = réactif aux tendances récentes ; long = insensible aux journées atypiques |
| Marge de sécurité | `PRODUCTION_SAFETY_MARGIN` | `0` | Unités gardées en réserve avant de conclure au manque. À 0, on propose dès que la projection passe sous zéro |
| Bornes des périodes | `PRODUCTION_PERIODS` | 05:00–11:00 / 11:00–14:00 / 14:00–19:00 | Quel produit apparaît dans quelle vue, et sur quelle vue l'écran s'ouvre par défaut |

Deux réglages restent **par produit**, et ne peuvent venir que de l'API
(`GET /shops/{id}/production/products`) :

- `batch_size` — la proposition est arrondie à son multiple supérieur. Sans lui,
  le produit est traité à l'unité et l'écran l'écrit : proposer « 17 croissants »
  à un four qui sort des plaques de 24 n'est pas exécutable.
- `production_lead_minutes` — le temps de cuisson s'ajoute à la fenêtre. Une
  recuisson validée à 15 h 00 avec 40 minutes de cuisson ne couvre pas les
  ventes de 15 h 00 à 15 h 40.

## Les réglages qui vivent sur le produit

Les quatre ci-dessus sont des réglages de **magasin**. Trois autres se décident
**produit par produit**, parce qu'ils ne se moyennent pas : deux références du
même rayon ne se pilotent pas pareil.

| Champ | Ce qu'il change |
|---|---|
| `sector` | l'atelier — boulangerie, traiteur. Filtre tous les écrans de Production d'un coup ; absent partout, le sélecteur ne s'affiche pas |
| `is_pdb` | le produit se prépare la veille : il entre dans le sélecteur de la MEP du lendemain |
| `is_pdm` + `pdm_minimums` | le produit se pilote à un **plancher de vitrine** par période plutôt qu'à la prévision de ventes |

`is_pdm` et la prévision ne s'excluent pas : un produit peut être sous son
plancher sans être en rupture annoncée, et l'inverse. Ce sont deux questions
différentes, elles ont donc deux tableaux — Stock et Minimums — sous le même
onglet. Voir `docs/ENDPOINTS_PRODUCTION.md` pour la forme exacte des champs et
la migration attendue côté back-office.

Les **commandes fermes** (`GET /shops/{id}/orders`) ne sont pas un réglage mais
elles entrent dans la même arithmétique : elles s'ajoutent aux ventes prévues
sur la même fenêtre, et au plancher de vitrine sur l'écran Minimums.

## Vérifier la prévision

```bash
php bin/forecast-test.php            # jeu de référence + 11 assertions
php bin/forecast-test.php 15:30      # même jeu, à une autre heure
php bin/forecast-test.php 10:00 3    # fenêtre de 3 heures
php bin/forecast-test.php 10:00 2 5  # avec une marge de 5 unités

php bin/sector-test.php              # secteurs, commandes, minimums — 55 assertions
php bin/board-test.php               # le tableau de travail d'une période
php bin/outlook-test.php             # les horizons et les filtres de l'écran Stock
```

Aucun serveur, aucune API, aucune base : `ForecastService` est une fonction
pure et les données viennent de `tests/fixtures/`. Un désaccord sur une
proposition se tranche donc en modifiant un fichier, pas en discutant.

Le jeu de référence est calculable à la main — c'est délibéré, une assertion
qu'on ne sait pas recalculer ne prouve rien :

| Produit | Batch | Cuisson | Stock | Ventes prévues | Projeté | Proposition |
|---|---|---|---|---|---|---|
| Croissant | 24 | 40 min | 10 | 32,0 (fenêtre 160 min) | −22 | **24** (1 fournée) |
| Macaron | — | 0 | 3 | 8,0 (120 min) | −5 | **5** à l'unité, signalé |
| Tarte citron | 6 | 0 | 2 | 6,0 (120 min) | −4 | **6** (1 fournée) |
| Baguette | 12 | 20 min | 30 | 14,0 | +16 | aucune |
| Bûche (inactive) | 4 | 0 | 0 | — | — | aucune, jamais |
| Sandwich (hors profil) | 10 | 0 | 0 | inconnu | — | aucune |

Les deux dernières lignes sont les cas qui comptent : un produit inactif ne se
propose pas même à zéro, et un produit absent du profil de ventes non plus. Le
traiter comme « zéro vente » proposerait des recuissons sur des produits dont
on ne sait rien.

## Les fixtures

`tests/fixtures/` contient un magasin de démonstration complet :

| Fichier | Contenu |
|---|---|
| `products.json` | 7 produits, dont un inactif et un absent du profil |
| `stock.json` | stock live correspondant, de 0 à 60 |
| `sales_profile.json` | 26 créneaux de 30 minutes, 06:00 → 19:00, 6 journées agrégées |
| `mep.json` | 4 lignes de MEP préparées, non validées |

Ils servent aussi à rendre l'écran hors serveur pendant le développement, ce
qui permet de travailler l'interface avant que l'API n'existe.
