# Mocks — modules Production et Cuisson

Un fichier par endpoint, avec la charge utile exacte que le front attend.
Le contrat en prose est dans `docs/ENDPOINTS_PRODUCTION.md` et
`docs/ENDPOINTS_CUISSON.md` ; ces fichiers en sont la forme exécutable.

**Le corps est nu, sans enveloppe `success`/`data`.** `ApiClient` ajoute
lui-même cette enveloppe à partir du code HTTP ; la remettre côté serveur
ferait arriver la charge utile sous `$response['data']['data']`.

Pour tester l'application en vrai plutôt que de lire des fichiers, voir
`tools/mock-api/` : un serveur qui sert ces endpoints **et garde son état**,
donc les écrans se traversent au lieu de se regarder.

| Fichier | Endpoint |
|---|---|
| `00_production_config.json` | `GET /shops/{shopId}/production/config` |
| `01_production_products.json` | `GET /shops/{shopId}/production/products` |
| `02a_mep_today_prepared.json` | `GET /shops/{shopId}/mep?date=<aujourd'hui>` — à valider |
| `02b_mep_today_validated.json` | idem, après validation |
| `02c_mep_tomorrow_draft.json` | `GET /shops/{shopId}/mep?date=<demain>` — brouillon repris à l'ouverture |
| `03a/03b_mep_save_*` | `POST /shops/{shopId}/mep` — encodage de la MEP de demain |
| `04a/04b_mep_validate_*` | `POST /shops/{shopId}/mep/validate` |
| `05_stock.json` | `GET /shops/{shopId}/stock` |
| `06_sales_profile.json` | `GET /shops/{shopId}/sales/profile` |
| `07a/07b_batch_*` | `POST /shops/{shopId}/production/batches` |
| `08_pending_count.json` | `GET /shops/{shopId}/production/pending-count` |
| `09_error_examples.json` | formes d'erreur attendues |

Les suffixes `_REQUEST` / `_RESPONSE` distinguent ce que le front envoie de ce
que le serveur renvoie.

## Ce que les mocks encodent volontairement

Ils ne sont pas un jeu « tout va bien » : chaque cas limite y est représenté,
parce que ce sont ceux qui cassent en production.

- **`6700140` (macaron) n'a pas de `batch_size`.** Le front le traite à
  l'unité et l'écrit à l'écran — proposer « 17 pièces » à un four qui sort des
  plaques de 24 n'est pas exécutable, et le taire serait pire.
- **`6700150` (bûche) est `is_active: false`.** Stock à zéro, jamais proposée,
  jamais affichée.
- **`6700160` (sandwich) est absent de `sales/profile`.** Produit dont on ne
  sait rien : aucune proposition. Le traiter comme « zéro vente » ferait
  enfourner à l'aveugle.
- **Quatre produits sur sept portent `is_pdb: true`.** Le sélecteur de la MEP
  du lendemain ne propose que ceux-là, et la catégorie « Traiteur » n'apparaît
  donc pas dans ses badges : un filtre qui ne mène nulle part n'est pas un
  filtre. Les tailles de fournée diffèrent exprès — 12 pour la baguette, 24
  pour le croissant, 6 pour la tarte — parce que c'est le pas des boutons
  `−` / `+`.
- **`04b` renvoie une ligne `SKIPPED` avec `quantity_validated: 0`.** Écarter
  une ligne et en produire zéro ne racontent pas la même chose.
- **`04b` et `07b` renvoient le stock résultant.** Le front réaffiche un chiffre
  serveur au lieu de faire sa propre addition : deux tablettes peuvent valider
  à quelques secondes d'intervalle.
- **`11` mélange les trois canaux et les deux secteurs**, avec une commande sans
  heure (« pour midi ») et une échue. Un carnet où tout est à l'heure et au même
  canal ne montrerait ni le tri par retard, ni le repli sur la période.
- **`12` porte `sector` et `is_pdm`.** Deux ateliers, l'un avec des planchers de
  vitrine par période, l'autre avec un produit qui ne se fait que sur commande :
  ce sont les trois cas que l'écran Minimums doit savoir distinguer.
- **`06` a une vraie courbe de journée** — pic du matin, creux de 10 h, pic de
  midi. Un profil plat validerait la mécanique mais pas les propositions.

## Trois jeux de données, trois usages

| Où | Pour quoi |
|---|---|
| `docs/mocks/` | la **forme** attendue de chaque réponse, à lire et à transmettre |
| `tools/mock-api/` | un **serveur** qui les sert avec état, pour cliquer dans l'app |
| `tests/fixtures/` | des chiffres **ronds**, calibrés pour que `php bin/forecast-test.php` se recalcule à la main |

Les mocks visent le réalisme, les fixtures visent la vérifiabilité. Les
confondre donnerait soit des écrans invraisemblables, soit des assertions
qu'on ne sait plus contrôler de tête.
