# Parcours de préparation — ce que Kitchen consomme

Le réseau définit, produit par produit, la suite des gestes à faire : une
instruction, sa durée, éventuellement un groupe de batch, les paramètres du
four, et jusqu'à trois photos. C'est une configuration réseau, faite une fois
au back-office administrateur et partagée par tous les magasins.

Kitchen **lit**. Il n'écrit jamais.

## La seule route appelée

```
GET /api/v1/products/{productId}/preparation-path
```

`src/app/Repositories/Knowledge/Preparation/PreparationPathRepository.php`

Les onze autres routes du tag `Product preparation` — création et modification
d'étapes, ordre, photos, copie depuis un autre produit, groupes de batch —
configurent le réseau entier. Une tablette d'atelier n'a rien à y faire : elle
redéfinirait le parcours de tous les magasins depuis un plan de travail.

## Trois réponses, et elles n'appellent pas la même réaction

C'est le point de ce module, et la raison pour laquelle il ne se contente pas
d'afficher une liste.

| Réponse | État | Ce que l'écran dit | Qui corrige |
|---|---|---|---|
| Étapes servies | `served` | Le parcours, durées et four compris | — |
| `configured: false`, ou aucune étape | `unconfigured` | La préparation de la fiche technique reprend la main | Le back-office, s'il faut un parcours |
| La route ne répond pas | `missing` | Le bandeau nomme `GET /products/{id}/preparation-path` | Le développeur |

Un produit sans parcours et une route muette se ressemblent à l'écran — les
deux ne montrent rien de neuf — et se règlent à deux endroits différents. Les
confondre envoie chercher au back-office une configuration qui y est déjà.

**Il n'y a pas de repli qui invente.** Quand la route se tait, Kitchen n'essaie
pas de recomposer un parcours ; il affiche la préparation que la fiche
technique, elle, a bien servie (`/products/{id}/technical-sheet/raw`, une autre
route, qui répond), et le bandeau du haut dit que le parcours manque. Deux
sources réelles, jamais une source inventée.

## Ce qu'on lit dans une étape

Les noms explicitement donnés par la documentation du back sont lus tels quels :
`batch_group_id`, `batch_capacity`, `products_per_tray`, `trays_per_oven`,
`configured`.

Trois champs ne sont **pas** nommés par cette documentation — elle dit seulement
« description, durée en secondes, et jusqu'à trois clés d'image ». Kitchen
accepte donc quelques orthographes pour ceux-là :

| Rôle | Noms acceptés |
|---|---|
| Instruction | `description`, `instruction`, `step_description`, `text`, `label`, `name` |
| Durée (secondes) | `duration_seconds`, `duration_second`, `duration`, `seconds` |
| Photos | `photos[]`, `images[]`, `image_keys[]`, ou `image_key_1..3` / `photo_1..3` |
| Rang | `position`, `sort_order`, `step_order`, `order`, `step_number` |

**À refermer** dès que la forme réelle est connue : il ne doit en rester qu'une
par ligne. Ce n'est pas de la complaisance — chacune correspond à une façon dont
l'écran afficherait des étapes muettes — mais une liste ouverte finit par
masquer un changement de contrat.

Une étape servie dont on ne sait pas lire l'instruction n'est **pas** masquée :
elle est écartée de l'affichage et comptée, et l'écran écrit combien. Un
parcours tronqué qui a l'air complet est pire qu'un parcours qui manque.

Les clés d'image suivent la règle du reste de l'application
(`ProductPhotoModel::resolveUrl()`) : `r2://…` désigne le stockage partagé, une
URL entière se suit telle quelle.

## L'ordre des étapes

Il vient du back — il a une route dédiée pour le persister
(`PATCH …/steps/order`). Kitchen respecte l'ordre servi, et ne trie que si les
lignes portent un rang explicite. Réordonner de sa propre initiative ferait
exécuter les gestes dans le mauvais sens, ce qui ne se rattrape pas.

## Ce qui reste à vérifier contre la vraie API

Deux points n'ont pas pu être établis depuis cette session — le proxy n'atteint
ni `atelierby.tfbuddy.com` ni le serveur de production :

1. **L'autorisation.** La documentation dit « Bearer », sans préciser si le
   jeton d'une tablette de magasin est accepté sur la route de lecture, ou si
   elle est réservée à l'administrateur réseau. Si elle répond 403 à une
   tablette, l'écran le signalera comme une route manquante — ce qui sera exact
   du point de vue de Kitchen, mais la correction sera côté droits.
2. **La forme exacte** des trois champs ci-dessus, et le nom sous lequel le
   groupe de batch est servi (`batch_group_name`, objet imbriqué, ou seulement
   son identifiant — les trois sont lus, le troisième s'affiche `#7`).

## Vérification

```bash
php bin/preparation-test.php          # 53 vérifications, sans réseau
```

Avec le bouchon, les trois états s'observent depuis la PWA :

```bash
php -S 127.0.0.1:8791 tools/mock-api/index.php                    # parcours complet
MOCK_PREP=off  php -S 127.0.0.1:8791 tools/mock-api/index.php     # produit sans parcours
MOCK_PREP=dead php -S 127.0.0.1:8791 tools/mock-api/index.php     # route muette
```

La cinquième étape du bouchon est volontairement sans instruction : c'est le cas
qui ferait afficher un geste sans consigne.

## Ce que ça ne fait pas encore

Le parcours porte ce qu'il faut pour **planifier** — durée de chaque geste,
capacité de batch, pièces par plaque et plaques par four — et la documentation
du back le dit explicitement : « configuration only, it does not create or
calculate a production schedule ».

Kitchen affiche aujourd'hui ces données ; il ne s'en sert pas encore pour
calculer un ordre de passage ni un remplissage de four. Le module Production
(`src/app/Services/Production/`) reste sur son propre modèle. C'est le
raccordement à décider ensuite.
