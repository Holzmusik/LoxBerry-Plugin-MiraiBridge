'use strict';

// ── AudioZoneV2 / echter Loxone Audioserver — Verbindungs-Probe ────────────
// Einmal-Testskript, KEIN Teil des laufenden bridge.js. Liegt bewusst in
// dev/ statt bin/ (Sicherheitsreview 2026-11-27, Punkt 6) - gehört nicht in
// den Produktionscode.
//
// STAND 2026-09-10, auf echter Hardware verifiziert: Der Audioserver pusht
// über ws://<ip>:7091/ws/rfc6455 nach dem Connect SOFORT vollständige
// "audio_event"-Daten für ALLE Zonen — Titel/Artist/Album/Cover/Mode/Power/
// Volume — OHNE jede Authentifizierung. Kommandos (Play/Pause/Volume/...)
// laufen ohnehin über die bestehende Miniserver-Verbindung (jdev/sps/io/
// <uuid>/play etc., siehe bridge.js sendToLoxone()), nicht über diese
// Verbindung. Der RSA/AES-Handshake (secure/authenticate/...), den Loxpanel
// als Referenz nutzt, ist deshalb hier absichtlich NICHT mehr drin — wird nur
// gebraucht falls sich rausstellt, dass laufende Updates (nicht nur der
// initiale Dump beim Connect) doch eine Authentifizierung brauchen.
//
// Dieser Lauf prüft genau das: bleibt die Verbindung offen und kommen bei
// einer echten Änderung (Titel/Play-Pause/Lautstärke über die Loxone-App an
// einer der Zonen) neue audio_event-Pushes, ganz ohne dass wir irgendwas
// senden?
//
// Nutzung:
//   node audioserver_probe.js <audioserver-host> [port=7091] [ws-path=/ws/rfc6455] [laufzeit-s=90]
//
// Während es läuft: an einer Zone in der Loxone-App Play/Pause oder
// Lautstärke ändern und schauen, ob ein neuer RAW-Block mit aktualisierten
// Werten für die passende playerid auftaucht.

const WebSocket = require('ws');

async function main() {
  const [, , hostArg, portArg, wsPathArg, durationArg] = process.argv;
  if (!hostArg) {
    console.error('Nutzung: node audioserver_probe.js <audioserver-host> [port=7091] [ws-path=/ws/rfc6455] [laufzeit-s=90]');
    process.exit(1);
  }
  const port = portArg ? parseInt(portArg, 10) : 7091;
  const wsPath = wsPathArg || '/ws/rfc6455';
  const durationS = durationArg ? parseInt(durationArg, 10) : 90;

  const url = `ws://${hostArg}:${port}${wsPath}`;
  console.log(`[probe] Verbinde zu Audioserver: ${url}`);
  console.log(`[probe] Laufzeit: ${durationS}s — bitte in der Zwischenzeit an einer Zone etwas ändern (Play/Pause/Volume)`);
  const ws = new WebSocket(url);

  let eventCount = 0;

  ws.on('open', () => console.log('[probe] WS offen'));

  ws.on('message', (data) => {
    eventCount++;
    const text = data.toString();
    console.log(`[probe] #${eventCount}`, new Date().toISOString(), 'RAW <<<', text.substring(0, 1000));
  });

  ws.on('error', (e) => console.error('[probe] WS-Fehler:', e.message));
  ws.on('close', (code, reason) => console.log('[probe] WS geschlossen:', code, reason.toString()));

  setTimeout(() => {
    console.log(`[probe] Fertig nach ${durationS}s — insgesamt ${eventCount} Nachrichten empfangen`);
    ws.close();
    process.exit(0);
  }, durationS * 1000);
}

main().catch((e) => { console.error('[probe] Fehler:', e); process.exit(1); });
