'use strict';

// ── Loxone-Radio (Streampal) als "Eigene Streams" in Sonn Core einrichten ──
// Echte Loxone-Audioserver-Hardware hat ihren eigenen, zertifikatsgeschützten
// Zugang zu Loxones kuratierten Radiosendern (secure/authenticate +
// audio/cfg/getservicefolder/loxoneradio/...) — Sonn Core hat das nicht.
// Die 13 Sender sind technisch ein White-Label-Dienst der Firma Streampal
// (streampal.de), deren Streams auch ganz normal offen (ohne Client-
// Zertifikat) über https://de.streampal.net/play.cgi?stream=<mount>
// erreichbar sind (Herkunft/Details: reference_loxone_radio_streampal_urls
// Memory-Datei, 2026-09-29 per FritzBox-Paketmitschnitt gefunden).
//
// Registrierung laeuft ZENTRAL (nicht zonengebunden) ueber den nativen
// Loxone-WS-Befehl audio/cfg/radios/add — auf echtem Sonn Core verifiziert
// (2026-09-29, Host 192.168.179.14 / Miniserver 192.168.179.11). Persistiert
// dauerhaft in Sonn Cores eigener customradio/stations.json, ab dann fuer
// ALLE Zonen im "Custom"-Ordner verfuegbar, dort pro Zone individuell
// favorisierbar (danach laeuft alles ueber die normale, bereits fertige
// Favoriten-Pipeline aus audioserver_auth.js/bridge.js).
//
// WICHTIG zum Befehlsformat (Sonn-Core-Quellcode, providerHandlers.ts
// audioCfgRadiosAdd): die einfache 2-Segment-Form
// (audio/cfg/radios/add/<name>/<stream>) legt Eintraege OHNE Cover-Art an
// (alles ab dem 3. Segment wird wieder an die URL angehaengt, nicht als
// eigenes Cover-Feld erkannt). Cover-Art geht nur ueber die ALTERNATIVE
// Befehlsform mit GENAU einem Pfadsegment nach "add": ein Base64url-
// kodiertes JSON-Objekt {name, url, cover}.
//
// Idempotenz: audio/cfg/getradios wird vorher abgefragt, Sender mit
// bereits vorhandenem Namen werden uebersprungen — macht das Skript sicher
// wiederholt/per Knopfdruck ausfuehrbar (z.B. nach einer Sonn-Core-
// Neuinstallation), ohne Dubletten anzulegen (auf Hardware beobachtet:
// zweimaliger Aufruf der einfachen add-Form erzeugte zwei "Focus"-Eintraege).

const { getAudioserverControlClient } = require('./audioserver_auth');

// Snapshot 2026-09-29 (siehe reference_loxone_radio_streampal_urls Memory).
// coverurl = Loxone-eigene, offen erreichbare Domain (radio.loxonecloud.com)
// -- NICHT track_coverurl (coverart.streampal.net), das war in Tests leer.
const STATIONS = [
  { name: 'Focus',                       stream: 'https://de.streampal.net/play.cgi?stream=loxone_loxone0', cover: 'https://radio.loxonecloud.com/coverart/449c2aa2.jpg' },
  { name: 'Best of 80s',                 stream: 'https://de.streampal.net/play.cgi?stream=4U_80s',         cover: 'https://radio.loxonecloud.com/coverart/b78094f3.jpg' },
  { name: 'Best of 90s',                 stream: 'https://de.streampal.net/play.cgi?stream=4U_90s',         cover: 'https://radio.loxonecloud.com/coverart/b642fec4.jpg' },
  { name: 'Jazz & Soul',                 stream: 'https://de.streampal.net/play.cgi?stream=4U_bar',         cover: 'https://radio.loxonecloud.com/coverart/9f198c6a.jpg' },
  { name: 'Chill Out & Lounge',          stream: 'https://de.streampal.net/play.cgi?stream=4U_chill',       cover: 'https://radio.loxonecloud.com/coverart/54adb6b5.jpg' },
  { name: 'Classical Music',             stream: 'https://de.streampal.net/play.cgi?stream=4U_classical',   cover: 'https://radio.loxonecloud.com/coverart/2e5cfeba.jpg' },
  { name: 'Smooth Dance Music',          stream: 'https://de.streampal.net/play.cgi?stream=4U_dance',       cover: 'https://radio.loxonecloud.com/coverart/d3b1c3c7.jpg' },
  { name: 'Hits only',                   stream: 'https://de.streampal.net/play.cgi?stream=4U_hitmusic',    cover: 'https://radio.loxonecloud.com/coverart/2807fbbd.jpg' },
  { name: 'Oldies',                      stream: 'https://de.streampal.net/play.cgi?stream=4U_oldies',      cover: 'https://radio.loxonecloud.com/coverart/13bf7a50.jpg' },
  { name: 'Best of Rock',                stream: 'https://de.streampal.net/play.cgi?stream=4U_rock',        cover: 'https://radio.loxonecloud.com/coverart/f5eab7ea.jpg' },
  { name: 'Schlager',                    stream: 'https://de.streampal.net/play.cgi?stream=4U_schlager',    cover: 'https://radio.loxonecloud.com/coverart/e2163e15.jpg' },
  { name: 'Spherical & Ambient Sounds',  stream: 'https://de.streampal.net/play.cgi?stream=4U_sphere',      cover: 'https://radio.loxonecloud.com/coverart/8d3fb763.jpg' },
  { name: 'Urban & Summer Vibes',        stream: 'https://de.streampal.net/play.cgi?stream=4U_urban',       cover: 'https://radio.loxonecloud.com/coverart/d35c93ec.jpg' },
];

function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

function waitFor(ws, matcher, timeoutMs = 6000) {
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => { ws.removeListener('message', onMsg); reject(new Error('Timeout')); }, timeoutMs);
    function onMsg(data) {
      let parsed;
      try { parsed = JSON.parse(data.toString()); } catch (e) { return; }
      if (matcher(parsed)) { clearTimeout(t); ws.removeListener('message', onMsg); resolve(parsed); }
    }
    ws.on('message', onMsg);
  });
}

// Liest audio/cfg/getradios und liefert die Menge der bereits vorhandenen
// Sender-Namen (u.a. eigene Custom Streams). Struktur nicht 1:1 live gegen-
// geprueft (nur der add/getroomfavs-Pfad wurde genau vermessen) — deshalb
// bewusst defensiv: mehrere plausible Formen (flaches Array, Ordner mit
// .items[]) werden abgeflacht, bei unbekannter Form bleibt einfach ein
// leeres Set (dann wird ohne Duplikat-Schutz angelegt, kein Absturz).
function flattenNamed(v) {
  if (Array.isArray(v)) return v.flatMap(flattenNamed);
  if (v && typeof v === 'object') {
    if (Array.isArray(v.items)) return v.items.flatMap(flattenNamed);
    if (v.name) return [v];
  }
  return [];
}

// host/wsPort: Audioserver-Box (Sonn Core oder echte Hardware, macht keinen
// Unterschied). msConn: { host, port, secure, user, pass } des Miniservers,
// der fuer DIESE Box das JWT ausstellt (siehe bridge.js loadMiniserverConn).
// Liefert { added: [name,...], skipped: [name,...], errors: [{name, error}] }.
async function setupLoxoneRadioStreams(host, wsPort, msConn) {
  const ctrl = getAudioserverControlClient(host, wsPort, msConn);
  const authenticated = await ctrl.ready();
  if (!authenticated) {
    throw new Error(`Authentifizierung fehlgeschlagen (${ctrl.authFailReason || 'unbekannt'}) — Box unterstuetzt secure/authenticate evtl. nicht`);
  }

  let existingNames = new Set();
  try {
    const getWait = waitFor(ctrl.ws, (m) => !!m.getradios_result);
    ctrl.ws.send('audio/cfg/getradios');
    const getMsg = await getWait;
    for (const item of flattenNamed(getMsg.getradios_result)) {
      if (item && item.name) existingNames.add(item.name);
    }
  } catch (e) {
    console.warn(`[loxone-radio-setup] Konnte vorhandene Sender nicht auslesen (${e.message}) — fahre ohne Duplikat-Pruefung fort.`);
  }

  const result = { added: [], skipped: [], errors: [] };
  for (const st of STATIONS) {
    if (existingNames.has(st.name)) {
      result.skipped.push(st.name);
      continue;
    }
    const blob = Buffer.from(JSON.stringify({ name: st.name, url: st.stream, cover: st.cover })).toString('base64url');
    const cmd = `audio/cfg/radios/add/${blob}`;
    try {
      const addWait = waitFor(ctrl.ws, (m) => !!m.command && m.command.startsWith('audio/cfg/radios/add'));
      ctrl.ws.send(cmd);
      const res = await addWait;
      if (res.error) {
        result.errors.push({ name: st.name, error: JSON.stringify(res.error) });
      } else {
        result.added.push(st.name);
      }
    } catch (e) {
      result.errors.push({ name: st.name, error: e.message });
    }
    await sleep(400);
  }

  return result;
}

module.exports = { setupLoxoneRadioStreams, STATIONS };
