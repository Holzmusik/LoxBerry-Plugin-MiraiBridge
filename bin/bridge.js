'use strict';

const fs   = require('fs');
const path = require('path');
const http = require('http');
const https = require('https');
const { execFileSync } = require('child_process');
const WebSocket = require('ws');
const mqtt = require('mqtt');
const { getAudioserverClient } = require('./audioserver');
const { getAudioserverControlClient } = require('./audioserver_auth');
const { setupLoxoneRadioStreams } = require('./loxone_radio_setup');
const loxberrylog = require('./loxberrylog');

// ── Pfade: LoxBerry vs. lokale Entwicklung ─────────────────
// Auf dem LoxBerry liegen bin/, config/, data/ und log/ in
// getrennten Systemverzeichnissen; im Entwicklungs-Checkout
// sind sie Geschwister-Ordner neben bin/.
const LBHOMEDIR = process.env.LBHOMEDIR || '';
const PLUGIN_FOLDER = path.basename(__dirname); // 'miraibridge' auf LoxBerry, 'bin' lokal

// Macht LoxBerrys "Log-Level"-Schalter (Plugin-Verwaltung) fuer die
// hochfrequenten Zeilen ([cmd]/[latenz]/[state], je Befehl/Zustands-
// aenderung EINE Zeile) nutzbar - real bestaetigt als Hauptursache fuer
// unkontrolliertes Wachstum von bridge.log (2026-09-18). Siehe
// bin/loxberrylog.js (identisch zum Schwester-Plugin KNXtoLOX).
const logLevel = loxberrylog.create(PLUGIN_FOLDER, LBHOMEDIR);

// Zeitstempel vor jeder Log-Zeile — ohne war bisher nicht nachvollziehbar, wie
// lange z.B. ein Miniserver-Ausfall oder eine Reconnect-Schleife gedauert hat.
// Lokalzeit statt toISOString() (2026-09-22): Date.prototype.toISOString()
// gibt IMMER UTC zurück, unabhängig von der System-Zeitzone des LoxBerry -
// im Log stand dadurch z.B. 09:11 Uhr, obwohl es lokal (Europe/Berlin,
// Sommerzeit UTC+2) tatsächlich 11:11 Uhr war. Die Date-Getter (getHours()
// etc.) verwenden dagegen die System-Zeitzone, genau wie sie z.B. `date`
// auf der Kommandozeile des LoxBerry anzeigen würde.
function localTimestamp() {
  const d = new Date();
  const pad = (n, w = 2) => String(n).padStart(w, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` +
         `T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}.${pad(d.getMilliseconds(), 3)}`;
}
for (const lvl of ['log', 'warn', 'error']) {
  const orig = console[lvl].bind(console);
  console[lvl] = (...args) => orig(localTimestamp(), ...args);
}

const PATHS = LBHOMEDIR
  ? {
      cfg:  path.join(LBHOMEDIR, 'config', 'plugins', PLUGIN_FOLDER, 'bridge.json'),
      data: path.join(LBHOMEDIR, 'data',   'plugins', PLUGIN_FOLDER),
    }
  : {
      cfg:  path.join(__dirname, '..', 'config', 'bridge.json'),
      data: path.join(__dirname, '..', 'data'),
    };

const CFG_FILE = PATHS.cfg;

function loadConfig() {
  try {
    return JSON.parse(fs.readFileSync(CFG_FILE, 'utf8'));
  } catch (e) {
    console.error('[config] Fehler beim Laden (', CFG_FILE, '):', e.message);
    process.exit(1);
  }
}

const cfg = loadConfig();

// ── Miniserver- und MQTT-Zugangsdaten aus der LoxBerry-Systemkonfiguration ──
// Das Plugin speichert nur die Miniserver-Nummer (msno); Host/User/Pass für
// Miniserver und MQTT-Broker stammen aus general.json (Miniserver-Widget bzw.
// MQTT-Gateway-Plugin), damit sie nicht doppelt gepflegt werden müssen.
function readGeneralJson() {
  const LBHOMEDIR = process.env.LBHOMEDIR || '/opt/loxberry';
  const generalPath = path.join(LBHOMEDIR, 'config', 'system', 'general.json');
  return JSON.parse(fs.readFileSync(generalPath, 'utf8'));
}

function loadMiniserverConn(msno) {
  if (!msno) throw new Error('Keine Miniserver-Nummer (loxone.msno) in bridge.json konfiguriert');
  const general = readGeneralJson();

  // LoxBerry 4.x general.json: Miniserver-Objekt, Keys 1-indexiert als Strings.
  // Feldnamen: Ipaddress, Port, Admin_raw, Pass_raw (verifiziert mit LoxBerry 4.x)
  const msSection = general.Miniserver || general.Miniservers;
  const ms = msSection && (msSection[msno] || msSection[String(msno)]);
  if (!ms) throw new Error(`Miniserver #${msno} nicht in LoxBerry general.json gefunden`);

  const host = ms.Ipaddress || ms.IPAddress;
  if (!host) throw new Error(`Miniserver #${msno}: IP-Adresse nicht gefunden`);

  return {
    host,
    port: ms.Port   || 80,
    user: ms.Admin_raw || ms.Admin || '',
    pass: ms.Pass_raw  || ms.Pass  || '',
  };
}

function loadMqttConn() {
  const general = readGeneralJson();
  const m = general.Mqtt;
  if (!m || !m.Brokerhost) throw new Error('MQTT Gateway ist in LoxBerry nicht konfiguriert (general.json → Mqtt.Brokerhost)');
  return {
    host: m.Brokerhost,
    port: parseInt(m.Brokerport, 10) || 1883,
    user: m.Brokeruser || undefined,
    pass: m.Brokerpass || undefined,
  };
}

// loadMiniserverConn/loadMqttConn werden erst in main() aufgerufen,
// damit Fehler sauber geloggt werden (vorher gab es process.exit(1)
// noch bevor irgendeine Ausgabe in die Log-Datei geschrieben wurde).

// cfg Struktur (bridge.json — MQTT-Broker- und Miniserver-Zugangsdaten stehen
// NICHT hier, sondern in LoxBerrys general.json, siehe loadMiniserverConn/loadMqttConn):
// {
//   "loxone": { "msno": 1 },
//   "panels": [
//     {
//       "name": "miraipanel",          // ESPHome device name (MQTT target)
//       "room_uuid": "...",            // Loxone Raum-UUID
//       "controls": {                  // UUID → Typ-Mapping (aus LoxAPP3.json)
//         "<uuid>": { "type": "IRoomControllerV2", "widget": "content_heating" },
//         "<uuid>": { "type": "Jalousie",            "widget": "content_blinds1" }, // Pool, bis zu 4 Instanzen (content_blinds1-4)
//         "<uuid>": { "type": "AudioZoneV2",        "widget": "content_Audio_small" },
//         "<uuid>": { "type": "LightControllerV2",  "widget": "content_light"  }
//       },
//       "topic_prefix": "loxone/"     // Prefix der Loxone-MQTT-Topics
//     }
//   ]
// }

// ── Loxone Struktur laden ──────────────────────────────────
function fetchLoxStructure(lox, timeoutMs = 10000) {
  return new Promise((resolve, reject) => {
    const auth = Buffer.from(`${lox.user}:${lox.pass}`).toString('base64');
    const options = {
      host: lox.host,
      port: lox.port || 80,
      path: '/data/LoxAPP3.json',
      headers: { 'Authorization': 'Basic ' + auth }
    };
    const req = http.get(options, (res) => {
      if (res.statusCode !== 200) {
        res.resume();
        reject(new Error(`HTTP ${res.statusCode}`));
        return;
      }
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try { resolve(JSON.parse(data)); }
        catch (e) { reject(e); }
      });
    });
    // Ohne Timeout hängt ein halboffener Socket das gesamte main() (und damit
    // MQTT + Audio) für immer.
    req.setTimeout(timeoutMs, () => req.destroy(new Error(`Timeout nach ${timeoutMs}ms`)));
    req.on('error', reject);
  });
}

// ── State-UUID-Filter aus LoxAPP3.json aufbauen ────────────
// Liest alle in bridge.json konfigurierten Control-UUIDs, sucht deren
// State-Variablen in LoxAPP3.json und gibt ein Set der relevanten
// State-UUIDs zurück. Nur diese werden von Loxone nach MQTT weitergeleitet.
function buildStateFilter(cfg, loxStructure) {
  const stateUuids = new Set(); // state-uuid → MQTT-Topic
  if (!loxStructure || !loxStructure.controls) return stateUuids;

  const loxControls = loxStructure.controls;

  for (const panel of cfg.panels || []) {
    for (const controlUuid of Object.keys(panel.controls || {})) {
      const loxCtrl = loxControls[controlUuid] || loxControls[controlUuid.toLowerCase()];
      if (!loxCtrl || !loxCtrl.states) {
        console.warn(`[bridge] Control ${controlUuid} nicht in LoxAPP3.json — übersprungen`);
        continue;
      }
      for (const stateUuid of Object.values(loxCtrl.states)) {
        stateUuids.add(stateUuid.toLowerCase());
      }
      // Subcontrols (z.B. LightControllerV2 → ".../masterValue") haben eigene
      // states-Objekte, die hier separat mit aufgenommen werden müssen — sonst
      // filtert buildStateFilter() ihre State-UUIDs heraus, obwohl api.php
      // build_topics() genau solche Subcontrol-States als t_*-Topic verwendet
      // (z.B. t_light_master). Loxone sendet den Wert dann zwar, die Bridge
      // verwirft ihn aber lautlos (siehe publishState()'s stateFilter-Check).
      for (const sub of Object.values(loxCtrl.subControls || {})) {
        for (const stateUuid of Object.values(sub.states || {})) {
          stateUuids.add(stateUuid.toLowerCase());
        }
      }
    }

    // sleep_cmd_uuid ist ein Loxone-Schalter (Switch/TimedSwitch) — der
    // relevante Zustand ist states.active (0/1), nicht states.value.
    // Wenn die UUID direkt eine State-UUID ist (kein Control-Treffer), wird
    // sie unverändert übernommen (rückwärtskompatibel).
    const sleepUuid = panel['sleep_cmd_uuid'];
    if (sleepUuid) {
      const loxCtrl = loxControls[sleepUuid] || loxControls[String(sleepUuid).toLowerCase()];
      const stateUuid = (loxCtrl && loxCtrl.states && (loxCtrl.states.active || loxCtrl.states.value)) || sleepUuid;
      stateUuids.add(String(stateUuid).toLowerCase());
    }

    // notify_cmd_uuid bleibt wie bisher (InfoOnly/value-basiert)
    const notifyUuid = panel['notify_cmd_uuid'];
    if (notifyUuid) {
      const loxCtrl = loxControls[notifyUuid] || loxControls[String(notifyUuid).toLowerCase()];
      const stateUuid = (loxCtrl && loxCtrl.states && loxCtrl.states.value) || notifyUuid;
      stateUuids.add(String(stateUuid).toLowerCase());
    }
  }

  // Globale Werte (Zeit & Außenklima, siehe Einstellungen-Tab) — einfache
  // Info-Controls (InfoOnlyAnalog/TextState), bei denen uuidAction meist mit
  // states.value übereinstimmt. Falls die Struktur ein explizites states.value
  // liefert, das nehmen — sonst die UUID direkt (deckt beide Fälle ab).
  for (const uuid of Object.values(cfg.global || {})) {
    if (!uuid) continue;
    const loxCtrl = loxControls[uuid] || loxControls[String(uuid).toLowerCase()];
    const stateUuid = (loxCtrl && loxCtrl.states && loxCtrl.states.value) || uuid;
    stateUuids.add(String(stateUuid).toLowerCase());
  }

  return stateUuids;
}

// ── Echter Loxone Audioserver ODER Sonn Core — reines Protokoll-Routing ────
// 2026-09-10 auf Hardware verifiziert (gegen Sonn Cores Emulation eines
// AudioZoneV2 — ECHTE Loxone-Audioserver-Hardware stand nicht zum Test zur
// Verfügung, siehe Diskussion/[[project_miraipanel_audioserver_bridge]]):
//   - Lesen:   ws://<host>:7091/ws/rfc6455 pusht "audio_event" für alle
//              Zonen einer Box, KEINE Authentifizierung nötig (audioserver.js)
//   - Schreiben: http://<host>:7090/audio/<zone>/play|pause|next|prev|
//              volume/<0-100> — GET reicht, Antwort {"<cmd>_result":[],...}
//              (dieselbe klassische Route, die die Firmware für Favoriten
//              schon nutzt, siehe MiraiPanel-LCD MiraiPanel.yaml roomfav/play)
//
// Bewusst KEINE Loxone-Struktur/UUID-Anbindung nötig — Sonn Core emuliert
// gegenüber dem Miniserver zwar einen vollwertigen AudioZoneV2, aber diese
// beiden Ports antwortet dieselbe Box unabhängig davon direkt, ob dahinter
// Sonn Core oder ein echter Audioserver steckt. Die Bridge muss deshalb nur
// IP + Zonen-Nummer kennen (dieselben Werte, die auf dem Panel unter
// "AudioServer IP"/"AudioServer Zone" ohnehin schon konfiguriert werden) —
// kein Unterschied im Code zwischen Sonn Core und echtem Audioserver V2.
//
// Config pro Panel (bridge.json):
//   "audio_zone_topic":  "mirai/audio/<irgendwas>"  (bereits vorhandenes Feld
//                         — MQTT-Basis-Topic, das die Firmware kennt)
//   "audioserver_host":  "192.168.179.14"           (neu)
//   "audioserver_zone":  5                           (neu — playerid der Zone)
// Panels ohne audioserver_host/audioserver_zone werden übersprungen (Sonn
// Core publiziert dann wie bisher direkt selbst, ohne dass diese Bridge
// überhaupt beteiligt ist).
// Raumfavoriten ändern sich selten (Nutzer legt sie in der Loxone-App an) —
// alle 10 Minuten neu abfragen reicht, hält aber die retained MQTT-Kopie
// aktuell falls sich doch mal was ändert, ohne die Box unnötig zu pollen.
const FAVORITES_REFRESH_MS = 10 * 60 * 1000;

function setupAudioZones(cfg, mqttClient) {
  const zones = []; // { topicBase, host, httpPort, zoneNum, panelName, ctrl }

  for (const panel of cfg.panels || []) {
    const host = panel.audioserver_host;
    const zoneNum = panel.audioserver_zone;
    const topicBase = panel.audio_zone_topic;
    if (!host || zoneNum === undefined || zoneNum === null || !topicBase) continue;

    const wsPort = panel.audioserver_ws_port || 7091;
    const httpPort = panel.audioserver_http_port || 7090;
    console.log(`[audio] Panel "${panel.name}": Zone ${zoneNum} @ ${host} <-> ${topicBase}`);

    const client = getAudioserverClient(host, wsPort);
    // audioserver.js dedupt nur den GESAMTEN State — da "time" (Elapsed)
    // real jede Sekunde hochzählt, gilt der State immer als "geändert" und
    // ein 'zone'-Event kommt jede Sekunde neu rein. Ohne die Prüfung unten
    // würden dabei auch Titel/Artist/Album/Cover/State/Volume/Source jede
    // Sekunde erneut publiziert, obwohl die sich gar nicht geändert haben
    // (auf Hardware beobachtet, 2026-09-10: 8 MQTT-Messages/Sekunde statt 2).
    // Deshalb hier zusätzlich pro Feld gegen den zuletzt publizierten Wert
    // vergleichen — nur Elapsed/Duration ticken bewusst immer mit.
    let lastPublished = {};
    client.on('zone', (pid, state) => {
      if (String(pid) !== String(zoneNum) || !mqttClient.connected) return;
      const publishIfChanged = (key, topic, value, retain = true) => {
        if (lastPublished[key] === value) return;
        lastPublished[key] = value;
        mqttClient.publish(topic, String(value), { qos: 0, retain });
      };
      publishIfChanged('source', `${topicBase}/source/name`,   state.station || state.name);
      // Zonenname (z.B. "Esszimmer") - eigenes Topic, getrennt von
      // source/name (Radiosender/Quelle) - zeigt oben rechts im Widget an
      // (lbl_AudioZoneName/_ov, siehe mqtt_router.yaml topic==base+"/name").
      publishIfChanged('zonename', `${topicBase}/name`, state.name);
      publishIfChanged('title',  `${topicBase}/track/title`,   state.title);
      publishIfChanged('artist', `${topicBase}/track/artist`,  state.artist);
      publishIfChanged('album',  `${topicBase}/track/album`,   state.album);
      publishIfChanged('cover',  `${topicBase}/track/coverUrl`, state.cover);
      publishIfChanged('state',  `${topicBase}/state`,         state.playing ? 'playing' : 'paused');
      publishIfChanged('volume', `${topicBase}/volume`,        state.volume);
      publishIfChanged('online', `${topicBase}/server/online`, '1');
      // duration bleibt über den ganzen Track gleich — nur bei echtem
      // Wechsel (neuer Track) neu publizieren, nicht jede Sekunde mit.
      publishIfChanged('duration', `${topicBase}/duration`, state.duration, false);
      // position dagegen bewusst IMMER publizieren (tickt jede Sekunde
      // real hoch) — Firmware erwartet "position", nicht "elapsed" (siehe
      // mqtt_router.yaml topic==base+"/position" — Fortschrittsbalken).
      mqttClient.publish(`${topicBase}/position`, String(state.time), { qos: 0, retain: false });
    });

    // Eigenes Online/Offline-Signal statt des bisherigen Topic_AudioPrefix-
    // Wegs (den nur Sonn Cores natives Publishing bedient hatte — unsere
    // Bridge kannte den zugehörigen Prefix bisher gar nicht und hat da nie
    // etwas publiziert). Unter demselben audio_zone_topic-Stamm statt einem
    // eigenen Server-weiten Prefix: pro Panel/Zone eigenes Flag, dafür kein
    // zusätzliches Config-Feld nötig — sieht die Firmware jetzt zusätzlich
    // zu ap+"server/online" (siehe mqtt_router.yaml).
    client.on('close', () => {
      if (!mqttClient.connected) return;
      lastPublished.online = '0';
      mqttClient.publish(`${topicBase}/server/online`, '0', { qos: 0, retain: true });
    });

    // ── Raumfavoriten: Auth+Fallback-Steuerkanal (audioserver_auth.js) ──────
    // Auf Hardware verifiziert (2026-09-28) gegen Sonn Core UND einen echten
    // Loxone-Audioserver V2: secure/authenticate + getroomfavs + roomfav/play
    // funktionieren auf beiden identisch. panel.audioserver_msno ist die
    // general.json-Nummer/der Name des Miniservers, der für DIESE
    // Audioserver-Box das JWT ausstellt — kann vom für den Rest der Bridge
    // genutzten cfg.loxone.msno abweichen (z.B. mehrere Kunden-Miniserver auf
    // einer LoxBerry). Ohne audioserver_msno bleibt die Zone einfach ohne
    // Favoriten — Zustand/Play/Pause/Volume oben sind davon unberührt.
    const audioMsno = panel.audioserver_msno || (cfg.loxone && cfg.loxone.msno);
    let ctrl = null;
    if (audioMsno) {
      try {
        const msConn = loadMiniserverConn(audioMsno);
        ctrl = getAudioserverControlClient(host, wsPort, msConn);
      } catch (e) {
        console.warn(`[audio] Panel "${panel.name}": Miniserver #${audioMsno} für Favoriten nicht ladbar (${e.message}) — Favoriten-Feature bleibt für diese Zone aus`);
      }
    }

    // publishFavorites bewusst außerhalb des "if (ctrl)"-Blocks deklariert
    // (als No-Op falls kein ctrl) — der MQTT-Handler unten (case
    // 'favorites_refresh') ruft dieselbe Funktion über zone.publishFavorites
    // auf, wenn die Firmware beim Öffnen des Favoriten-Overlays aktiv ein
    // frisches Update anfordert (statt bis zu 10 Minuten auf den nächsten
    // Timer-Tick zu warten).
    let publishFavorites = async () => {};
    if (ctrl) {
      publishFavorites = async () => {
        try {
          const authenticated = await ctrl.ready();
          if (!authenticated) {
            console.warn(`[audio] Panel "${panel.name}": Box unterstützt secure/authenticate nicht (${ctrl.authFailReason}) — keine Favoriten für diese Zone`);
            return;
          }
          const items = await ctrl.getRoomFavorites(zoneNum);
          if (mqttClient.connected) {
            mqttClient.publish(`${topicBase}/favorites`, JSON.stringify({ items }), { qos: 0, retain: true });
          }
        } catch (e) {
          console.warn(`[audio] Panel "${panel.name}": Favoriten-Abfrage fehlgeschlagen: ${e.message}`);
        }
      };
      publishFavorites();
      setInterval(publishFavorites, FAVORITES_REFRESH_MS);
    }

    zones.push({ topicBase, host, httpPort, zoneNum, panelName: panel.name, ctrl, publishFavorites });
  }

  if (zones.length === 0) return;

  // Re-Abo bei jedem (Re-)Connect, analog zum bestehenden loxCmdTopic-Muster
  // weiter unten in main().
  mqttClient.on('connect', () => {
    for (const z of zones) {
      const cmdTopic = `${z.topicBase}/set/+`;
      mqttClient.subscribe(cmdTopic, { qos: 0 }, (err) => {
        if (err) console.error(`[audio] Abo von ${cmdTopic} fehlgeschlagen:`, err.message);
        else console.log(`[audio] Panel-Kommandos abonniert: ${cmdTopic}`);
      });
    }
  });

  // Kommando-Topics/Payloads exakt wie von der Firmware publiziert (siehe
  // MiraiPanel-LCD MiraiPanel.yaml audio_http_play/pause/next/prev/vol_up/
  // vol_down — "von Sonn Core dokumentierte MQTT-Transportbefehle") — hier
  // 1:1 auf die klassische Port-7090-Route gemappt statt auf Sonn Cores
  // eigenes MQTT-Handling.
  mqttClient.on('message', (topic, message) => {
    const zone = zones.find((z) => topic.startsWith(`${z.topicBase}/set/`));
    if (!zone) return;
    const sub = topic.substring(`${zone.topicBase}/set/`.length);
    const payload = message.toString();
    let cmdPath;
    switch (sub) {
      case 'state':    cmdPath = payload === 'playing' ? 'play' : 'pause'; break;
      // "next"/"prev" (Miniserver-jdev/sps/io-Namen, siehe Kommentar oben)
      // waren für DIESE Route falsch geraten — die Box beantwortet JEDEN
      // Pfad mit HTTP 200 und einem leeren "<name>_result":[] (Echo-
      // Handler ohne Validierung), ohne dass etwas passiert. Auf Hardware
      // verifiziert (2026-09-11, per curl + Loxone-App-Beobachtung):
      // "queueplus"/"queueminus" schalten den Titel wirklich um UND liefern
      // ein gefülltes "<name>_result":{"action":"..."} statt eines leeren
      // Arrays — das lässt sich als Signal für "echtes Kommando erkannt"
      // nutzen, siehe echte openapi.yaml-Doku (mr-manuel/Loxone_api_
      // documentation), "Zone Queue"-Endpunkte.
      case 'next':     cmdPath = 'queueplus';  break;
      case 'previous': cmdPath = 'queueminus'; break;
      case 'volume': {
        const vol = Math.max(0, Math.min(100, parseInt(payload, 10) || 0));
        cmdPath = `volume/${vol}`;
        break;
      }
      // Favorit abspielen läuft NICHT über die klassische HTTP-7090-Route
      // (dort auf Hardware verifiziert: reiner Echo-Handler, kein echter
      // Effekt, siehe Kommentar oben), sondern über den authentifizierten
      // WS-Kanal (audioserver_auth.js) — deshalb eigener Zweig mit eigenem
      // return statt cmdPath/sendAudioserverCommand.
      case 'favorite': {
        if (!zone.ctrl) {
          console.warn(`[audio] "favorite" Kommando ignoriert (${zone.panelName}): kein Miniserver für Favoriten konfiguriert (panel.audioserver_msno)`);
          return;
        }
        zone.ctrl.playRoomFavorite(zone.zoneNum, payload)
          .then((res) => console.log(`[audio] Favorit ${payload} auf Zone ${zone.zoneNum} (${zone.panelName}):`, JSON.stringify(res.roomfav_result)))
          .catch((e) => console.warn(`[audio] Favorit ${payload} abspielen fehlgeschlagen (${zone.panelName}): ${e.message}`));
        return;
      }
      // Firmware fordert das beim Öffnen des Favoriten-Overlays aktiv an
      // (statt bis zu FAVORITES_REFRESH_MS auf den nächsten Timer-Tick zu
      // warten) — siehe MiraiPanel.yaml fetch_audio_favs.
      case 'favorites_refresh':
        zone.publishFavorites();
        return;
      default:
        console.warn(`[audio] Unbekanntes Kommando-Subtopic: ${sub} (${zone.panelName})`);
        return;
    }
    sendAudioserverCommand(zone.host, zone.httpPort, zone.zoneNum, cmdPath);
  });
}

// GET http://<host>:<port>/audio/<zone>/<cmdPath>. Achtung (auf Hardware
// verifiziert 2026-09-11): die Box beantwortet JEDEN Pfad mit HTTP 200 —
// auch frei erfundene Kommandos — über einen Echo-Handler ohne Validierung.
// HTTP 200 beweist also nicht, dass das Kommando etwas bewirkt hat. Einziger
// beobachtete Unterschied: ein echtes/erkanntes Kommando liefert ein
// GEFÜLLTES "<cmd>_result" (z.B. {"action":"queueplus"}), ein unbekanntes
// ein LEERES Array ("<cmd>_result":[]) — das hier als grobe Heuristik
// ausgewertet und geloggt, damit ein zukünftiger Tippfehler/falsch geratener
// Kommandoname nicht wieder stillschweigend nichts bewirkt.
function sendAudioserverCommand(host, port, zone, cmdPath) {
  const url = `http://${host}:${port}/audio/${zone}/${cmdPath}`;
  http.get(url, (res) => {
    let body = '';
    res.on('data', (chunk) => { body += chunk; });
    res.on('end', () => {
      console.log(`[audio] ${url} -> HTTP ${res.statusCode}`);
      try {
        const parsed = JSON.parse(body);
        const resultKey = Object.keys(parsed).find((k) => k.endsWith('_result'));
        const result = resultKey && parsed[resultKey];
        if (Array.isArray(result) && result.length === 0) {
          console.warn(`[audio] "${cmdPath}" liefert ein leeres Ergebnis zurück — vermutlich kein echtes Kommando (Box validiert Pfade nicht, siehe Kommentar)`);
        }
      } catch {
        // Antwort kein JSON — nicht weiter auswertbar, Statuscode oben reicht als Log.
      }
    });
  }).on('error', (e) => console.error(`[audio] Kommando fehlgeschlagen (${url}):`, e.message));
}

// ── Loxone Binary-Protokoll: UUID aus 16 Bytes rekonstruieren ──
// Loxone speichert UUIDs als: D1(4 Byte LE) + D2(2 Byte LE) + D3(2 Byte LE) + D4(8 Byte BE)
// Beispiel: "1236c0e4-0251-4285-ffff825f2d0084ef"
function loxUuidFromBytes(buf) {
  const d1 = buf.readUInt32LE(0).toString(16).padStart(8, '0');
  const d2 = buf.readUInt16LE(4).toString(16).padStart(4, '0');
  const d3 = buf.readUInt16LE(6).toString(16).padStart(4, '0');
  const d4 = buf.slice(8, 16).toString('hex');
  return `${d1}-${d2}-${d3}-${d4}`;
}

// ── Haupt-Loop ─────────────────────────────────────────────
async function main() {
  console.log('[bridge] MiraiBridge startet');
  console.log('[bridge] CFG_FILE:', CFG_FILE);
  console.log('[bridge] PATHS.data:', PATHS.data);

  // Verbindungsdaten laden — hier, damit Fehler sauber in den Log kommen
  const loxConn  = loadMiniserverConn(cfg.loxone && cfg.loxone.msno);
  const mqttConn = loadMqttConn();
  console.log(`[bridge] Miniserver: ${loxConn.host}:${loxConn.port}`);
  console.log(`[bridge] MQTT-Broker: ${mqttConn.host}:${mqttConn.port}`);

  // Struktur-abhängiger Zustand. Wird beim Start (HTTP oder Cache) gesetzt und
  // bei JEDEM späteren Erhalt der Struktur über den WebSocket (Event
  // 'get_structure_file', kommt nach jeder erfolgreichen Authentifizierung)
  // neu aufgebaut. Früher wurde LoxAPP3.json nur einmal beim Start geholt: war
  // der Miniserver dann noch nicht erreichbar (z.B. nach Stromausfall), blieb
  // stateFilter dauerhaft leer und publishState() verwarf jeden Wert, obwohl
  // der WebSocket später sauber authentifiziert war.
  let loxStructure = null;
  let structureLive = false; // true = Struktur direkt vom Miniserver (nicht Cache)
  let msSerial = String(cfg.loxone.msno);
  let stateFilter = new Set();
  let mqttClient = null;
  let loxCmdTopic = null;

  const controlCount = cfg.panels.reduce((s, p) => s + Object.keys(p.controls || {}).length, 0);
  const globalCount  = Object.values(cfg.global || {}).filter(Boolean).length;
  const cachePath = path.join(PATHS.data, 'lox_structure.json');

  // Panel → Loxone: die Firmware schreibt (Sensor-Werte, Heizung-Sollwert,
  // Jalousie-Up/Down-Pulse etc.) immer auf "<Topic_LoxPrefix><ziel-uuid>/cmd"
  // (siehe MiraiPanel-LCD/MiraiPanel.yaml, z.B. RoomTemperature-Sensor-Handler).
  // Topic_LoxPrefix wird von uns selbst auf "mirai/lox/<serial>/" gesetzt
  // (siehe api.php build_topics/"lox_prefix"), die Bridge muss also genau
  // dieses Schema abonnieren, nicht ein eigenes. Wird bei geänderter Serial
  // (Fallback → echte Seriennummer) umgestellt.
  function subscribeLoxCmd() {
    if (!mqttClient || !mqttClient.connected) return;
    const wanted = `mirai/lox/${msSerial}/+/cmd`;
    if (loxCmdTopic && loxCmdTopic !== wanted) mqttClient.unsubscribe(loxCmdTopic);
    loxCmdTopic = wanted;
    mqttClient.subscribe(wanted, { qos: 0 }, (err) => {
      if (err) console.error(`[mqtt] Abo von ${wanted} fehlgeschlagen:`, err.message);
      else console.log(`[mqtt] Panel-Kommandos abonniert: ${wanted}`);
    });
  }

  function applyStructure(structure, source, live) {
    if (!structure || !structure.controls) return false;
    loxStructure = structure;
    if (live) structureLive = true;
    const oldSerial = msSerial;
    msSerial = (structure.msInfo && structure.msInfo.serialNr) || String(cfg.loxone.msno);
    stateFilter = buildStateFilter(cfg, structure);
    console.log(`[bridge] Struktur aktiv (${source}): ${stateFilter.size} State-UUIDs aus ${controlCount} Controls + ${globalCount} globalen Werten`);
    if (stateFilter.size > 0) {
      if (logLevel.enabled(logLevel.DEBUG)) {
        for (const uuid of stateFilter) console.log(`[topics] mirai/lox/${msSerial}/${uuid}`);
      }
    } else {
      console.warn('[bridge] Keine State-UUIDs — bitte Panel mit echten Loxone-Controls konfigurieren');
    }
    if (msSerial !== oldSerial) {
      console.warn(`[bridge] Miniserver-Seriennummer ${oldSerial} → ${msSerial}, MQTT-Abo wird umgestellt`);
      subscribeLoxCmd();
    }
    return true;
  }

  function cacheStructure(structure) {
    try {
      fs.mkdirSync(PATHS.data, { recursive: true });
      fs.writeFileSync(cachePath, JSON.stringify(structure, null, 2));
    } catch (e) {
      console.warn('[bridge] lox_structure.json konnte nicht geschrieben werden:', e.message);
    }
  }

  try {
    const fetched = await fetchLoxStructure(loxConn);
    console.log('[bridge] LoxAPP3.json geladen');
    cacheStructure(fetched);
    applyStructure(fetched, 'HTTP', true);
  } catch (e) {
    console.warn('[bridge] LoxAPP3.json nicht erreichbar:', e.message);
    // Fallback: zuletzt gecachte Struktur, damit Serial/Filter von Anfang an
    // stimmen. Sobald der WebSocket authentifiziert ist, ersetzt die Live-
    // Struktur diese (siehe 'get_structure_file' weiter unten).
    try {
      const cached = JSON.parse(fs.readFileSync(cachePath, 'utf8'));
      if (applyStructure(cached, 'Cache', false)) {
        console.warn('[bridge] Verwende gecachte Struktur bis der Miniserver antwortet');
      }
    } catch (_) {
      console.warn('[bridge] Kein Struktur-Cache vorhanden — warte auf WebSocket-Struktur');
    }
  }

  // ── MQTT Client ──────────────────────────────────────────
  mqttClient = mqtt.connect({
    host:     mqttConn.host,
    port:     mqttConn.port,
    username: mqttConn.user,
    password: mqttConn.pass,
    clientId: 'mirai-bridge',
    clean:    true,
    reconnectPeriod: 5000,
  });

  // Echter Audioserver / Sonn Core: reines IP+Zone-Routing, unabhängig von
  // der Loxone-Struktur (siehe setupAudioZones()-Kommentar) — registriert
  // bei Bedarf eigene 'connect'/'message'-Listener auf mqttClient, no-op
  // falls kein Panel audioserver_host/audioserver_zone konfiguriert hat.
  setupAudioZones(cfg, mqttClient);

  // Abo (neu) setzen bei jedem (Re-)Connect — clean:true, Session-State geht
  // beim Reconnect verloren. Siehe subscribeLoxCmd() weiter oben.
  mqttClient.on('connect', () => {
    console.log('[mqtt] Verbunden mit Broker');
    subscribeLoxCmd();
  });

  // Panel → Loxone ist bewusst ungefiltert: JEDE UUID, die das Panel unter
  // diesem Topic-Schema published (Sensorwerte, Heizung-Sollwert, Jalousie-
  // Puls, Tastendruck, Audio-Kommando, ...), wird 1:1 an Loxone weitergereicht
  // — anders als bei publishState() gibt es hier keine Allowlist, weil wir
  // per Wildcard-Abo sowieso nur exakt das empfangen, was die Firmware selbst
  // unter mirai/lox/<serial>/+/cmd sendet.
  mqttClient.on('message', (topic, message) => {
    // Audio-Topics (mirai/audio/...) behandelt setupAudioZones() selbst.
    if (!topic.startsWith('mirai/lox/')) return;
    const cmdMatch = topic.match(/^mirai\/lox\/([^/]+)\/([^/]+)\/cmd$/);
    if (!cmdMatch) {
      console.warn(`[cmd] Unerwartetes Topic ignoriert: ${topic}`);
      return;
    }
    if (cmdMatch[1] !== msSerial) {
      console.warn(`[cmd] Serial-Mismatch ignoriert: ${topic} (erwartet ${msSerial})`);
      return;
    }
    sendToLoxone(cmdMatch[2], message.toString());
  });

  // ── Loxone WebSocket: eigene Implementierung (Token-Auth + Binary-Parser) ─
  // Kein externes Loxone-Paket — alles selbst implementiert, nur 'ws' + built-in
  // 'crypto' nötig.

  const publishedOnce = new Set();
  function publishState(uuid, value) {
    const key = uuid.toLowerCase();
    if (!stateFilter.has(key)) return;
    if (!mqttClient.connected) return;
    const topic = `mirai/lox/${msSerial}/${key}`;
    mqttClient.publish(topic, String(value), { qos: 0, retain: true });
    if (!publishedOnce.has(key)) {
      publishedOnce.add(key);
      if (logLevel.enabled(logLevel.DEBUG)) console.log(`[state] ${topic} = ${String(value).substring(0, 80)}`);
    }
  }

  // ── Loxone via node-lox-ws-api (Token-Enc = RSA+AES, identisch zu lox2mqtt) ─
  const LoxoneAPI = require('node-lox-ws-api');

  const loxApi = new LoxoneAPI(
    `${loxConn.host}:${loxConn.port || 80}`,
    loxConn.user,
    loxConn.pass,
    true,        // auto-reconnect
    'Token-Enc', // RSA+AES-verschlüsselte Token-Auth (Firmware 9.x+)
    30000
  );

  // node-lox-ws-api ruft in reconnect() setTimeout(..., this._reconnect_time)
  // auf, initialisiert _reconnect_time aber nirgends → undefined → 0 ms. Bei
  // nicht erreichbarem Miniserver (Boot, Netz weg) entsteht dadurch eine
  // Reconnect-Schleife ohne Pause (im Log: hunderte "Verbindung getrennt /
  // Reconnect…" in Folge). Daher hier explizit setzen.
  loxApi._reconnect_time = 5000;

  // Merkt, ob die Loxone-WS-Verbindung aktuell authentifiziert ist — Befehle,
  // die davor ankommen (z.B. Panel published direkt nach eigenem Boot,
  // während die Bridge noch verbindet), würden sonst stillschweigend verloren
  // gehen, ohne dass das im Log auftaucht.
  let loxAuthorized = false;
  let structureWatchdog = null;
  loxApi.on('authorized',      () => {
    loxAuthorized = true;
    console.log('[lox] Authentifiziert ✓');
    // Die Lib holt LoxAPP3.json nach jeder Authentifizierung selbst und meldet
    // sie per 'get_structure_file'. Bleibt das aus, wären wir verbunden, aber
    // ohne Filter — das soll nicht mehr still passieren.
    clearTimeout(structureWatchdog);
    structureWatchdog = setTimeout(() => {
      if (loxAuthorized && !structureLive) {
        console.error('[lox] Authentifiziert, aber keine Struktur (LoxAPP3.json) erhalten — State-Updates werden verworfen');
      }
    }, 30000);
  });
  // Live-Struktur vom Miniserver: ersetzt Start-/Cache-Zustand und baut
  // stateFilter + Seriennummer neu auf (läuft synchron VOR enablebinstatusupdate,
  // die initialen Werte landen also schon im neuen Filter).
  loxApi.on('get_structure_file', (structure) => {
    console.log('[lox] Struktur (LoxAPP3.json) per WebSocket erhalten');
    cacheStructure(structure);
    applyStructure(structure, 'WebSocket', true);
  });
  loxApi.on('auth_failed',     () => { loxAuthorized = false; console.error('[lox] Authentifizierung fehlgeschlagen'); });
  loxApi.on('connect_failed',  () => { loxAuthorized = false; console.error('[lox] Verbindung fehlgeschlagen'); });
  loxApi.on('connection_error',(e) => console.error('[lox] Fehler:', e && e.message || e));
  loxApi.on('close',           () => { loxAuthorized = false; structureLive = false; console.warn('[lox] Verbindung getrennt'); });
  loxApi.on('reconnect',       () => console.log('[lox] Reconnect…'));

  // ── Latenz-Messung: MQTT-Befehl empfangen → Loxone meldet neue State ──
  // uuid → Zeitpunkt (ms) des letzten gesendeten Befehls für diese UUID.
  // Wird beim Senden gesetzt, beim passenden Rückmelde-Event ausgelesen und
  // gelöscht. Loxone sendet State-Updates für ALLE Controls (nicht nur die
  // im stateFilter erlaubten), daher funktioniert das auch für Ziele wie
  // Sensor-Ziele, die die Bridge sonst nicht nach MQTT weiterleitet.
  const pendingCommands = new Map();

  function handleLoxUpdate(uuid, value) {
    const key = uuid.toLowerCase();
    const sentAt = pendingCommands.get(key);
    if (sentAt !== undefined) {
      pendingCommands.delete(key);
      if (logLevel.enabled(logLevel.DEBUG)) console.log(`[latenz] ${key}: ${Date.now() - sentAt}ms (MQTT-Befehl → Loxone-Rückmeldung)`);
    }
    publishState(uuid, value);
  }

  loxApi.on('update_event_value', (uuid, value) => handleLoxUpdate(uuid, value));
  loxApi.on('update_event_text',  (uuid, text)  => handleLoxUpdate(uuid, text));

  // Manche Topic_*-Felder in der Firmware haben nie eine echte UUID bekommen
  // und stehen noch auf dem Platzhalter-Default aus globals.yaml (z.B.
  // "Topic_Mic", falls "Mikrofon / Geräusch" im Panel nicht konfiguriert
  // wurde). Solche Werte werden trotzdem published — ohne diese Prüfung
  // würde die Bridge sinnlos "jdev/sps/io/topic_mic/…" an Loxone schicken.
  const LOX_UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{16}$/i;

  // node-lox-ws-api's send_command() hat keinerlei Fehlerbehandlung/Rückgabe
  // (kein Callback, kein Promise, keine Prüfung ob die Verbindung überhaupt
  // steht) — ein interner Fehler (z.B. Verbindung/Auth noch nicht bereit)
  // geht sonst komplett lautlos unter. Deshalb hier explizit absichern.
  //
  // NICHT über is_connected() prüfen (Bug, 2026-09-22 auf Hardware
  // reproduziert — Log zeigte wiederholt "send_command fehlgeschlagen ...
  // Cannot read properties of undefined (reading 'prepare_secure_command')"
  // während eines Reconnects nach Miniserver-Reboot): is_connected() prüft
  // in node-lox-ws-api nur `this.connection !== undefined` (Transport-Ebene)
  // — die Property wird synchron gesetzt, sobald die WebSocket-Verbindung
  // steht (API.connect() → register_connection()), aber der Auth-Handshake
  // (this._auth, benötigt von send_command() für prepare_secure_command())
  // läuft danach noch asynchron über mehrere Round-Trips und wird erst bei
  // erfolgreicher Auth neu erzeugt (siehe register_auth_object() in
  // node-lox-ws-api/lib/API.js). Es gibt also nach jedem (Re-)Connect ein
  // reales Zeitfenster, in dem is_connected()==true, aber this._auth noch
  // undefined ist — genau das Fenster, das den Crash auslöste. loxAuthorized
  // (unser eigenes, exakt auf das 'authorized'-Event getaktetes Flag) ist
  // der korrekte Gate, nicht is_connected().
  function sendToLoxone(uuid, value) {
    const key = uuid.toLowerCase();
    if (!LOX_UUID_RE.test(key)) {
      console.warn(`[cmd] Ziel sieht nicht wie eine echte Loxone-UUID aus, übersprungen: "${key}" = ${value} — vermutlich unkonfiguriertes Topic_*-Feld im Panel (siehe Einstellungen/Sensor-Ziele)`);
      return;
    }
    if (!loxAuthorized) {
      console.warn(`[cmd] Noch nicht bei Loxone authentifiziert — Befehl verworfen: ${key} = ${value}`);
      return;
    }
    if (logLevel.enabled(logLevel.DEBUG)) console.log(`[cmd] jdev/sps/io/${key}/${value}`);
    try {
      pendingCommands.set(key, Date.now());
      loxApi.send_command(`jdev/sps/io/${key}/${value}`);
    } catch (e) {
      pendingCommands.delete(key);
      console.error(`[cmd] send_command fehlgeschlagen für ${key}:`, e && e.message || e);
    }
  }

  console.log(`[lox] Verbinde mit ${loxConn.host}:${loxConn.port || 80}…`);
  loxApi.connect();
}

// ── Zonen-Scan für die Config-UI ────────────────────────────────────────
// Nur 127.0.0.1, NIE von außen erreichbar — api.php (läuft auf demselben
// Host) fragt das per file_get_contents() ab, wenn im Web-UI auf "Zonen
// suchen" geklickt wird (siehe Panel-Editor, Audioserver-Host/Zone). Erspart
// das manuelle "Probe-Skript laufen lassen und JSON lesen"-Prozedere von
// vorher — nutzt exakt dieselbe AudioserverClient-Verbindung/-Logik wie der
// normale Zonen-Betrieb (siehe setupAudioZones), nur einmalig und mit
// Timeout statt dauerhaft.
//
// Bekannte Einschränkung: für jeden je abgefragten (auch falschen/getippten)
// Host bleibt über getAudioserverClient() eine dauerhafte WS-Verbindung
// bestehen (inkl. eigenem Reconnect-Loop, siehe audioserver.js) — bei
// gelegentlicher manueller Nutzung im Web-UI vernachlässigbar, kein Cleanup
// vorgesehen.
const ZONE_SCAN_PORT = 17091;

function startZoneScanServer(port) {
  const server = http.createServer((req, res) => {
    const url = new URL(req.url, `http://127.0.0.1:${port}`);

    if (url.pathname === '/scan-zones') {
      const host = url.searchParams.get('host');
      const wsPort = parseInt(url.searchParams.get('port'), 10) || 7091;
      if (!host) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'host fehlt' }));
        return;
      }

      console.log(`[scan] Zonen-Suche gestartet: ${host}:${wsPort}`);
      const client = getAudioserverClient(host, wsPort);

      // Aus dem Zonen-Cache lesen (getKnownZones()), nicht auf frische 'zone'-
      // Events warten: bei einer schon länger laufenden Verbindung (z.B. weil
      // eine konfigurierte Zone dieselbe Verbindung schon nutzt) kommen sonst
      // nur für gerade AKTIV spielende Zonen neue Events — ruhige/pausierte
      // Zonen wären unsichtbar, obwohl der Cache sie längst kennt (auf
      // Hardware beobachtet 2026-09-10: nur 1 von 4 Zonen gefunden). Trotzdem
      // kurz warten, falls die Verbindung gerade erst neu aufgebaut wird und
      // den initialen Dump noch nicht empfangen hat.
      setTimeout(() => {
        const result = client.getKnownZones().sort((a, b) => a.playerid - b.playerid);
        console.log(`[scan] ${result.length} Zone(n) gefunden für ${host}:${wsPort}`);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(result));
      }, 3000);
      return;
    }

    // ── Loxone-Radio-Sender (Streampal) per Knopfdruck in Sonn Core einrichten ─
    // Zentrale, einmalige/wiederholbare Registrierung (siehe
    // loxone_radio_setup.js) — z.B. nach einer Sonn-Core-Neuinstallation
    // erneut auslösbar, ohne Dubletten (Idempotenz-Check dort). msno kommt
    // NICHT aus dem Web-UI (panel.audioserver_msno ist dort bisher nicht
    // editierbar) — Fallback auf cfg.loxone.msno, exakt wie in
    // setupAudioZones() oben.
    if (url.pathname === '/setup-loxone-radio') {
      const host = url.searchParams.get('host');
      const wsPort = parseInt(url.searchParams.get('port'), 10) || 7091;
      const msnoParam = url.searchParams.get('msno');
      const msno = msnoParam || (cfg.loxone && cfg.loxone.msno);
      if (!host) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'host fehlt' }));
        return;
      }
      if (!msno) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Kein Miniserver konfiguriert (cfg.loxone.msno)' }));
        return;
      }

      console.log(`[loxone-radio-setup] Gestartet: ${host}:${wsPort} (Miniserver #${msno})`);
      let msConn;
      try {
        msConn = loadMiniserverConn(msno);
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: `Miniserver #${msno} nicht ladbar: ${e.message}` }));
        return;
      }
      setupLoxoneRadioStreams(host, wsPort, msConn)
        .then((result) => {
          console.log(`[loxone-radio-setup] Fertig: ${result.added.length} neu, ${result.skipped.length} vorhanden, ${result.errors.length} Fehler`);
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify(result));
        })
        .catch((e) => {
          console.error('[loxone-radio-setup] Fehlgeschlagen:', e.message);
          res.writeHead(502, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ error: e.message }));
        });
      return;
    }

    res.writeHead(404);
    res.end();
  });
  server.on('error', (e) => console.error('[scan] Server-Fehler:', e.message));
  server.listen(port, '127.0.0.1', () => console.log(`[scan] Zonen-Scan-Server auf 127.0.0.1:${port}`));
}

startZoneScanServer(ZONE_SCAN_PORT);

// ── WSS-Proxy fuers Babyphone (MiraiFon-App) ──────────────────────────────
// Das Panel spricht nur Klartext-ws:// (siehe MiraiPanel-LCD,
// custom-components/babyfon_stream.h) — das reicht fuers Audio im LAN, aber
// sobald die App selbst ueber HTTPS laeuft (noetig fuer Benachrichtigungen/
// Kamera-QR-Scan), wuerde der Browser eine Klartext-ws://-Verbindung als
// Mixed Content blockieren. Dieser Proxy terminiert TLS auf dem LoxBerry
// (selbstsigniertes Zertifikat, Browser fragt beim ersten Mal einmalig nach
// Vertrauen) und reicht alles unveraendert als Klartext-ws:// ans Panel
// weiter — am Panel selbst aendert sich dafuer nichts. 2026-10-01.
const WSS_PROXY_PORT = 8443;
const WSS_CERT_DIR = path.join(PATHS.data, 'ssl');

function ensureProxyCert() {
  const certFile = path.join(WSS_CERT_DIR, 'wss-proxy.crt');
  const keyFile  = path.join(WSS_CERT_DIR, 'wss-proxy.key');
  if (fs.existsSync(certFile) && fs.existsSync(keyFile)) {
    return { cert: fs.readFileSync(certFile), key: fs.readFileSync(keyFile) };
  }
  fs.mkdirSync(WSS_CERT_DIR, { recursive: true });
  console.log('[wss-proxy] Erzeuge einmaliges selbstsigniertes Zertifikat...');
  // -days 3650 (10 Jahre): bewusst lang, da das Zertifikat sowieso nie von
  // einer oeffentlichen CA signiert/validiert wird — der Browser muss ihm
  // ohnehin einmalig manuell vertrauen, ein kurzes Ablaufdatum brächte hier
  // keinen Sicherheitsgewinn, nur wiederkehrenden Aerger.
  execFileSync('openssl', [
    'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
    '-keyout', keyFile, '-out', certFile,
    '-days', '3650', '-subj', '/CN=miraifon-proxy',
  ]);
  return { cert: fs.readFileSync(certFile), key: fs.readFileSync(keyFile) };
}

function startBabyfonWssProxy(port) {
  let certPair;
  try {
    certPair = ensureProxyCert();
  } catch (e) {
    console.error('[wss-proxy] Konnte Zertifikat nicht erzeugen/laden (ist openssl installiert?):', e.message);
    return;
  }

  const httpsServer = https.createServer(certPair, (req, res) => {
    res.writeHead(404);
    res.end();
  });
  const wss = new WebSocket.Server({ server: httpsServer, path: '/babyfon' });

  wss.on('connection', (appWs, req) => {
    const url = new URL(req.url, `https://127.0.0.1:${port}`);
    const panelHost = url.searchParams.get('panel');
    const panelPort = parseInt(url.searchParams.get('port'), 10) || 5000;
    const token = url.searchParams.get('token') || '';

    if (!panelHost) {
      console.warn('[wss-proxy] Verbindung ohne ?panel= abgelehnt');
      appWs.close(1008, 'panel fehlt');
      return;
    }

    const panelUrl = `ws://${panelHost}:${panelPort}/api/babyfon/stream?token=${encodeURIComponent(token)}`;
    console.log(`[wss-proxy] App verbunden, leite weiter an ${panelHost}:${panelPort}`);
    const panelWs = new WebSocket(panelUrl);

    // Reine bidirektionale Weiterleitung, roh - der Proxy muss nicht
    // verstehen, was durchfliesst (binaeres PCM-Audio, JSON-Alarm als Text),
    // nur TLS auf der App-Seite terminieren. Kurze Warteschlange, falls die
    // App (bisher ungenutzt, aber zur Vollstaendigkeit) etwas sendet, bevor
    // die Panel-Verbindung selbst steht.
    let panelReady = false;
    const pending = [];
    panelWs.on('open', () => {
      panelReady = true;
      for (const msg of pending) panelWs.send(msg);
      pending.length = 0;
    });
    panelWs.on('message', (data, isBinary) => {
      if (appWs.readyState === WebSocket.OPEN) appWs.send(data, { binary: isBinary });
    });
    panelWs.on('close', () => appWs.close());
    panelWs.on('error', (e) => {
      console.warn('[wss-proxy] Panel-Verbindung fehlgeschlagen:', e.message);
      appWs.close(1011, 'Panel nicht erreichbar');
    });

    appWs.on('message', (data) => {
      if (panelReady && panelWs.readyState === WebSocket.OPEN) panelWs.send(data);
      else pending.push(data);
    });
    appWs.on('close', () => {
      if (panelWs.readyState === WebSocket.OPEN || panelWs.readyState === WebSocket.CONNECTING) panelWs.close();
    });
    appWs.on('error', () => panelWs.close());
  });

  httpsServer.on('error', (e) => console.error('[wss-proxy] Server-Fehler:', e.message));
  httpsServer.listen(port, '0.0.0.0', () => {
    console.log(`[wss-proxy] Babyfon-WSS-Proxy auf Port ${port} (wss://<loxberry-ip>:${port}/babyfon?panel=<panel-ip>&token=<token>)`);
  });
}

startBabyfonWssProxy(WSS_PROXY_PORT);

main().catch(e => {
  console.error('[bridge] Fataler Fehler:', e);
  process.exit(1);
});
