# Commandes web — ce que l'API doit fournir

État au 31/07/2026. Ce document liste ce qui manque côté API pour que l'écran
des commandes du kitchen distingue réellement le Click & Collect de la
livraison.

## Où en est le front

La bascule **Toutes · Click & Collect · Livraison** existe et fonctionne sur
`/orders`. Elle est branchée sur le paramètre d'URL `?fulfilment=all|collect|delivery`,
et le tri se fait aujourd'hui **en PHP**, sur la liste déjà chargée.

`OrderModel` sait lire un mode de remise sous plusieurs graphies plausibles —
`fulfilment_mode`, `fulfillment_mode`, `delivery_type`, `delivery_mode`,
`order_type`, ou les booléens `is_delivery` / `delivery`. Le jour où le
back-end livre le champ sous l'un de ces noms, l'écran fonctionne sans
modification.

**En attendant, aucun de ces champs n'est renvoyé.** Toutes les commandes
tombent donc dans « mode inconnu ». Plutôt que de laisser le filtre vider
silencieusement la liste — ce qui le ferait passer pour cassé — l'écran
affiche une phrase explicite tant qu'aucune commande ne porte l'information.

## Ce qui manque

### 1. Le mode de remise, sur la liste et le détail

`GET /shops/{shopId}/client-orders`
`GET /client-orders/{id}`

Un champ par commande, valeurs closes. Nom suggéré :

```json
{ "fulfilment_mode": "collect" }   // ou "delivery"
```

Sans lui, rien de ce qui suit n'a d'objet.

### 2. Le filtre côté serveur

`GET /shops/{shopId}/client-orders?fulfilment_mode=collect`

Le tri en PHP ne porte que sur la page déjà chargée : dès que la liste sera
paginée, filtrer côté client donnera des comptes faux. À prévoir avant la
pagination, pas après.

### 3. Le canal de prise de commande

Distinguer une commande passée **sur le web** d'une commande prise au
comptoir. `OrderModel::isWebOrder()` lit déjà `channel`, `source`, `origin` ou
`order_source` :

```json
{ "channel": "web" }   // ou "shop"
```

Aujourd'hui l'écran mélange les deux sans le dire. C'est une demande
explicite : « tout ce qui est nécessaire à savoir sur les commandes passées
par le web ».

### 4. Les données propres à la livraison

Une commande livrée porte des informations qu'une commande retirée n'a pas, et
que la cuisine doit voir pour préparer :

- adresse de livraison (rue, code postal, ville, complément)
- créneau de livraison, s'il diffère de `pick_up_datetime`
- consignes de livraison (étage, code d'accès, téléphone de contact)
- transporteur ou mode d'acheminement, si le réseau en utilise plusieurs

À décider : champs à plat sur la commande, ou objet `delivery` imbriqué. La
seconde forme se prête mieux au fait que ces champs n'existent que pour une
partie des commandes.

### 5. Les compteurs par mode

Pour afficher le nombre de commandes sur chaque onglet sans charger les trois
listes :

```json
{ "counts": { "all": 24, "collect": 17, "delivery": 7 } }
```

Utile, pas indispensable — l'écran fonctionne sans.

## Ce qui existe déjà et suffit

- `GET /shops/{shopId}/client-orders` avec `date_from`, `date_to`,
  `client_name`, `pending_only`
- `GET /client-orders/{id}?include=products`
- `POST /client-orders`, `PUT /client-orders/{id}`, `DELETE /client-orders/{id}`

## Une fois l'API en place

1. Retirer le tri PHP (`OrderService::filterByFulfilment`) au profit du
   paramètre serveur.
2. Retirer la phrase d'avertissement et `hasFulfilmentData()`.
3. Garder `readFulfilmentMode()` : la normalisation reste utile quel que soit
   le nom retenu côté back-end.
