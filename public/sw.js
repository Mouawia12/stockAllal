/*
 * Ligne internet instable (une partie des connexions vers l'etranger se perd, voir la fin de DEPLOY.md) :
 * une page qui ne repond pas est redemandee jusqu'a 3 fois ; si le reseau reste coupe, offline.html s'affiche
 * a la place de "Ce site est inaccessible" et recharge la page des que le site repond.
 *
 * Aucune page de l'application n'est mise en cache : seulement offline.html. Les POST (formulaires) et les
 * appels fetch des pages ne passent pas ici (ils ont fetchRetry dans layouts/app.blade.php).
 * Pour le desactiver : remplacer ce fichier par un service worker qui appelle self.registration.unregister().
 */
// a changer quand offline.html change (v2 : traduction fr / en, v3 : icone d'onglet) : sinon les navigateurs gardent l'ancienne copie
const CACHE = 'stockallal-offline-v4';
const OFFLINE_URL = '/offline.html';
const TRIES = 3;
const TIMEOUT_MS = 10000;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.add(new Request(OFFLINE_URL, {cache: 'reload'})))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

function fetchWithTimeout(request) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
    return fetch(request, {signal: ctrl.signal}).finally(() => clearTimeout(timer));
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.mode !== 'navigate' || request.method !== 'GET') {
        return;
    }
    event.respondWith((async () => {
        for (let attempt = 1; attempt <= TRIES; attempt++) {
            try {
                // une reponse HTTP, meme 500 ou 503, est rendue telle quelle : seul l'echec reseau est rejoue
                return await fetchWithTimeout(request);
            } catch (e) {
                // pas de reponse : nouvel essai, sur une nouvelle connexion
            }
        }
        return (await caches.match(OFFLINE_URL)) || Response.error();
    })());
});
