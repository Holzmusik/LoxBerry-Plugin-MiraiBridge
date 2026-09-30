// Minimaler Service Worker — reicht fuer die "Zum Startbildschirm hinzufuegen"-
// Installierbarkeitskriterien (Chrome/Android verlangt einen registrierten SW).
// Bewusst ohne Caching-Logik: der Audio-Stream ist naturgemaess live/nicht
// cachebar, und ein Offline-Modus ergibt fuer ein Babyphone keinen Sinn.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {}); // Passthrough, kein Caching
