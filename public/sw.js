/*
 * Service worker — la coque en cache, les données jamais.
 *
 * ── Ce qu'il fait, et ce qu'il refuse de faire ──
 * Il met en cache les fichiers statiques : feuilles de style, scripts, polices,
 * icônes. Ce sont eux qui pèsent, et ils ne changent qu'au déploiement.
 *
 * Il ne met en cache AUCUNE page HTML et AUCUN appel /ajax/. C'est délibéré, et
 * c'est la règle qui tient toute l'application : une tablette de cuisine ne doit
 * jamais afficher une donnée d'hier comme si elle était d'aujourd'hui. Un stock
 * périmé servi depuis un cache ferait produire à côté ; une checklist d'hier
 * ferait signer deux fois. Quand le réseau manque, on le dit — on n'invente pas.
 *
 * Le gain est donc de la vitesse et de l'installabilité, pas de l'autonomie :
 * l'application s'installe sur l'écran d'accueil, s'ouvre en plein écran, et
 * charge sa coque instantanément. Hors ligne, elle affiche une page qui dit
 * qu'on est hors ligne.
 *
 * ── Versionnage ──
 * Le nom du cache vient du paramètre « v » de l'URL d'enregistrement, alimenté
 * par APP_BUILD. Un déploiement change donc l'URL du worker, ce qui en installe
 * un neuf, qui purge les caches des versions précédentes. Sans cela, un
 * changement de CSS resterait invisible jusqu'à ce que quelqu'un vide le cache
 * de la tablette — c'est-à-dire jamais.
 */

const VERSION = new URL(self.location).searchParams.get('v') || 'dev';
const CACHE = 'kitchen-shell-' + VERSION;

/* Le socle : présent sur chaque écran, donc rentable à précharger. Les
   bibliothèques d'un seul écran ne sont pas listées — elles seront mises en
   cache à la première visite de cet écran, par la règle générale plus bas. */
const SHELL = [
    'assets/css/bootstrap.css',
    'assets/vendors/bootstrap-icons/bootstrap-icons.css',
    'assets/css/app.css',
    'assets/css/snackbarStyle.css',
    'assets/css/custom.css',
    'assets/css/app-shell.css',
    'assets/css/atelier-theme.css',
    'assets/js/bootstrap.bundle.min.js',
    'assets/brand/logo.png',
    'assets/brand/icon-192.png',
    'offline.html',
];

/* Ce qu'on met en cache au vol : uniquement des fichiers, jamais des réponses
   applicatives. La liste est fermée volontairement — une règle par extension
   attraperait un jour une réponse JSON déguisée. */
const CACHEABLE = /\.(css|js|png|jpe?g|svg|webp|woff2?|ttf|otf|eot|ico)(\?|$)/i;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(CACHE);
        // addAll échoue en bloc si un seul fichier manque : on ajoute un par un
        // pour qu'un asset renommé n'empêche pas l'installation entière.
        await Promise.all(SHELL.map((path) =>
            cache.add(new Request(new URL(path, self.registration.scope), { cache: 'reload' }))
                 .catch(() => null)
        ));
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(
            names.filter((n) => n.startsWith('kitchen-shell-') && n !== CACHE)
                 .map((n) => caches.delete(n))
        );
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Une écriture ne passe jamais par un cache, et n'a pas à être rejouée.
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // Rien de ce qui n'est pas à nous : l'application ne contacte aucun tiers,
    // et si cela changeait, ce ne serait pas au worker d'en décider.
    if (url.origin !== self.location.origin) return;

    // Les données, jamais. On laisse passer sans toucher : le navigateur fera
    // ce qu'il fait d'habitude, et l'écran dira « réseau » s'il n'y arrive pas.
    if (url.pathname.includes('/ajax/')) return;

    // Les pages : réseau d'abord, toujours. Hors ligne, une page qui l'annonce
    // plutôt qu'une page d'hier.
    const wantsHtml = req.mode === 'navigate' ||
        (req.headers.get('accept') || '').includes('text/html');

    if (wantsHtml) {
        event.respondWith((async () => {
            try {
                return await fetch(req);
            } catch (e) {
                const cache = await caches.open(CACHE);
                const offline = await cache.match(new URL('offline.html', self.registration.scope));
                return offline || new Response(
                    '<!doctype html><meta charset="utf-8"><title>Hors ligne</title>' +
                    '<p style="font:600 1rem/1.5 sans-serif;padding:2rem;text-align:center">' +
                    'Pas de réseau. Les données ne sont pas à jour tant que la connexion n\'est pas revenue.</p>',
                    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                );
            }
        })());
        return;
    }

    // Les fichiers : cache d'abord, puis réseau, et on garde ce qu'on reçoit.
    if (!CACHEABLE.test(url.pathname)) return;

    event.respondWith((async () => {
        const cache = await caches.open(CACHE);
        const hit = await cache.match(req);
        if (hit) return hit;

        const res = await fetch(req);
        // On ne garde qu'une réponse complète et servie par nous : une réponse
        // partielle (206) ou opaque en cache rendrait des fichiers tronqués.
        if (res && res.status === 200 && res.type === 'basic') {
            cache.put(req, res.clone());
        }
        return res;
    })());
});
