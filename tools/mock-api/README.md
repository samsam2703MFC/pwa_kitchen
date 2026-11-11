# Serveur d'API bouchon

Pour tester les modules **Production** et **Cuisson** avant que le back-office
ne serve quoi que ce soit. Ce n'est pas un jeu de JSON figés : il garde son
état, donc les écrans se traversent au lieu de se regarder.

> Poste de développement uniquement. Il ne vérifie aucune authentification et
> émet des jetons de façade. Il n'a rien à faire sur un serveur exposé.

## Démarrer

```bash
php -S 127.0.0.1:8081 tools/mock-api/index.php
```

Puis pointer l'application dessus. En local :

```bash
KITCHEN_API_BASE=http://127.0.0.1:8081 php -S 127.0.0.1:8000 -t public
```

Sur un serveur Apache, dans `public/.htaccess` :

```apache
SetEnv KITCHEN_API_BASE http://127.0.0.1:8081
```

Se connecter avec **n'importe quel identifiant** : le bouchon accepte tout et
renvoie un jeton pour le magasin choisi dans la liste.

## Ce qu'on peut réellement faire

L'état vit dans `tools/mock-api/.state.json` (ignoré par git) et se réinitialise
chaque jour. Les enchaînements suivants fonctionnent bout en bout :

| Geste dans l'app | Effet dans le bouchon |
|---|---|
| **MEP** → valider une ligne à 118 au lieu de 120 | la ligne passe `VALIDATED`, le stock monte de **118** |
| **MEP** → écarter une ligne | elle passe `SKIPPED`, le stock ne bouge pas |
| **MEP après-midi** → saisir les quantités de demain | remplace le brouillon de J+1, relisible à la réouverture |
| **Stock** → valider une recuisson | le stock augmente, la proposition disparaît au sondage suivant |
| **Cuisson** → « Enfourner », « Sortir du four », « Finition terminée » | la fournée avance d'une étape et change de colonne |

Remettre à zéro pour rejouer un scénario :

```bash
curl -X POST http://127.0.0.1:8081/__reset
```

## Deux partis pris

**Les horaires sont calculés par rapport à maintenant.** Un plan de cuisson
figé à 06 h 00 ne montre rien quand on teste à 15 h. Ici, il y a toujours une
fournée au four, une en finition et une qui attend — à toute heure.

**Le saut d'étape est refusé** (`409 invalid_transition`). Enfourner sans avoir
préparé n'existe pas en atelier ; le bouchon applique la règle que le
back-office devra appliquer, pour que le front soit testé contre le vrai
comportement et non contre un serveur complaisant.

**Une route non bouchonnée répond `404 not_mocked`**, jamais une liste vide. Un
écran qui se remplit de rien ne dit pas s'il manque une donnée ou un endpoint.
Les appels non couverts sont journalisés sur la sortie d'erreur du serveur —
c'est la liste de ce qu'il reste à écrire.

## Mode relais — voir les nouveaux modules sans perdre les autres écrans

```bash
MOCK_API_PASSTHROUGH=https://atelierby.tfbuddy.com/api/v1 \
  php -S 127.0.0.1:8081 tools/mock-api/index.php
```

Ce que le bouchon connaît, il le sert ; **tout le reste part vers l'API
réelle**. Sans cela, brancher l'app sur le bouchon ferait tomber en 404 le
tableau de bord, les checklists, les commandes et la base de connaissances : on
verrait Production et Cuisson au prix de tous les autres écrans.

C'est le mode à utiliser pour faire une démonstration complète.

## Endpoints couverts

**Authentification**

| Méthode | Route |
|---|---|
| GET | `/public/shops` |
| POST | `/devices/auth/login` · `/devices/auth/refresh` |

**Production** — contrat : `docs/ENDPOINTS_PRODUCTION.md`

| Méthode | Route |
|---|---|
| GET | `/shops/{id}/production/config` |
| GET | `/shops/{id}/production/products` |
| GET | `/shops/{id}/mep?date=` |
| POST | `/shops/{id}/mep` *(encodage de J+1)* |
| POST | `/shops/{id}/mep/validate` |
| GET | `/shops/{id}/stock` |
| GET | `/shops/{id}/sales/profile` |
| POST | `/shops/{id}/production/batches` |
| GET | `/shops/{id}/production/pending-count` |

**Cuisson** — contrat : `docs/ENDPOINTS_CUISSON.md`

| Méthode | Route |
|---|---|
| GET | `/shops/{id}/ovens` |
| GET | `/shops/{id}/baking?date=` |
| PATCH | `/baking/{id}` |
| GET | `/shops/{id}/baking/pending-count` |

Les autres écrans (tableau de bord, checklists, commandes, base de
connaissances) ne sont **pas** bouchonnés : ils appellent des endpoints qui
existent déjà côté back-office.

## Les cas limites sont dans les données

Ils y sont exprès — ce sont ceux qui cassent en production :

- **Macaron pistache** n'a pas de `batch_size` → proposition à l'unité, signalée.
- **Bûche de Noël** est `is_active: false` → jamais affichée, jamais proposée,
  même à stock zéro.
- **Sandwich club** est absent du profil de ventes → aucune proposition. Le
  traiter comme « zéro vente » ferait enfourner à l'aveugle.
- **Éclair chocolat** et **Chouquette glacée** ont une finition **à la pièce** :
  36 × 1 min mobilise quelqu'un 36 minutes, là où un ressuage en coûte 20 quelle
  que soit la quantité.
- Le **profil de ventes** suit une vraie courbe de journée. Un profil plat
  validerait la mécanique mais pas les propositions.
