-- ---------------------------------------------------------------------------
-- pwa_kitchen_param — contenu initial
--
-- Ce jeu reproduit EXACTEMENT ce que les tablettes affichaient avant que la
-- table n'existe. L'exécuter ne change donc rien à l'écran : on pose le
-- contenu sans rien casser, puis on ajuste une case à la fois.
--
-- Source : DeviceModeService::DEFAULT_NAV et DEFAULT_TABS (pwa_kitchen).
-- Après un changement de menu dans la PWA, ce fichier est à régénérer.
--
-- Rejouable : ON DUPLICATE KEY UPDATE. Relancer ne crée pas de doublon, et ne
-- touche pas aux surcharges par boutique, qui portent un id_shop différent.
-- ---------------------------------------------------------------------------

INSERT INTO pwa_kitchen_param (mode, feature, is_enabled, in_tabbar, sort_order, id_shop) VALUES

-- ── Production — le fournil ────────────────────────────────────────────────
-- Six entrées au menu, quatre dans la barre du bas. « knowledge » et
-- « complaints » se consultent, elles n'ont pas leur place sous le pouce.
  ('production', 'dashboard',  1, 1, 10, 0),
  ('production', 'production', 1, 1, 20, 0),
  ('production', 'checklists', 1, 1, 30, 0),
  ('production', 'orders',     1, 1, 40, 0),
  ('production', 'knowledge',  1, 0, 50, 0),
  ('production', 'complaints', 1, 0, 60, 0),

-- ── Gestion — le bureau ────────────────────────────────────────────────────
-- Ni production, ni commandes : ce poste contrôle, il ne fabrique pas.
  ('gestion',    'dashboard',  1, 1, 10, 0),
  ('gestion',    'checklists', 1, 1, 20, 0),
  ('gestion',    'knowledge',  1, 1, 30, 0),
  ('gestion',    'complaints', 1, 1, 40, 0),

-- ── WebShop — le comptoir ──────────────────────────────────────────────────
-- Une entrée de menu, et les trois vues internes dans la barre du bas. Les
-- « ws_* » n'existent QUE là : ce sont des onglets, pas des sections.
  ('webshop',    'webshop',    1, 0, 10, 0),
  ('webshop',    'ws_prep',    1, 1, 20, 0),
  ('webshop',    'ws_stock',   1, 1, 30, 0),
  ('webshop',    'ws_board',   1, 1, 40, 0)

ON DUPLICATE KEY UPDATE
  is_enabled = VALUES(is_enabled),
  in_tabbar  = VALUES(in_tabbar),
  sort_order = VALUES(sort_order);


-- ---------------------------------------------------------------------------
-- Vérification
-- ---------------------------------------------------------------------------
-- Doit rendre exactement :
--
--   gestion     menu : dashboard, checklists, knowledge, complaints
--               barre: dashboard, checklists, knowledge, complaints
--   production  menu : dashboard, production, checklists, orders, knowledge, complaints
--               barre: dashboard, production, checklists, orders
--   webshop     menu : webshop
--               barre: ws_prep, ws_stock, ws_board

SELECT mode,
       GROUP_CONCAT(CASE WHEN is_enabled = 1 AND feature NOT LIKE 'ws\_%' THEN feature END
                    ORDER BY sort_order SEPARATOR ', ') AS menu,
       GROUP_CONCAT(CASE WHEN is_enabled = 1 AND in_tabbar = 1 THEN feature END
                    ORDER BY sort_order SEPARATOR ', ') AS barre_du_bas
FROM pwa_kitchen_param
WHERE id_shop = 0
GROUP BY mode
ORDER BY mode;


-- ---------------------------------------------------------------------------
-- La requête de l'endpoint GET /devices/{id}/configuration
-- ---------------------------------------------------------------------------
-- Surcharge par boutique : la ligne de la boutique gagne sur celle du réseau.
-- La sous-requête sur MAX(id_shop) passe AVANT le filtre is_enabled — sans
-- elle, une ligne désactivée pour une boutique laisserait repasser la ligne
-- réseau, et la case décochée n'aurait aucun effet.

SELECT p.mode, p.feature, p.in_tabbar, p.sort_order
FROM pwa_kitchen_param p
WHERE p.is_enabled = 1
  AND p.id_shop = (
        SELECT MAX(q.id_shop) FROM pwa_kitchen_param q
        WHERE q.mode = p.mode AND q.feature = p.feature
          AND q.id_shop IN (0, :shop_id)
      )
ORDER BY p.mode, p.sort_order;

-- Assemblage attendu, pour chaque mode :
--   nav  = les features retenues, sauf « ws_* », dans l'ordre de sort_order
--   tabs = celles à in_tabbar = 1, dans le même ordre, quatre au plus
--
-- La PWA tronque au-delà de quatre onglets, mais mieux vaut ne pas l'y
-- obliger : la barre n'en affiche pas plus, et les suivants disparaîtraient
-- sans un mot.
