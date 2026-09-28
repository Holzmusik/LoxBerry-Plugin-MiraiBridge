'use strict';

const EventEmitter = require('events');
const crypto = require('crypto');
const WebSocket = require('ws');
const { fetchMiniserverJwt } = require('./miniserver_jwt');

// ── Authentifizierte Steuer-Verbindung zum Audioserver (Port 7091) ─────────
// Ergänzt audioserver.js (das bewusst UNVERÄNDERT bleibt — reines Auslesen
// von Titel/Cover/Volume über /ws/rfc6455 braucht laut Hardware-Test
// (2026-09-10 + erneut bestätigt 2026-09-28) KEINE Authentifizierung, egal
// ob echter Audioserver oder Sonn Core dahintersteckt).
//
// Für Raumfavoriten (audio/cfg/getroomfavs/...) verlangt ein ECHTER Loxone-
// Audioserver dagegen secure/authenticate (siehe audioserver_probe_auth.js-
// Recherche vom 2026-09-28). Ob Sonn Core diesen Handshake überhaupt
// unterstützt, ist unklar — deshalb hier bewusst mit Fallback: schlägt die
// Anmeldung fehl oder läuft in einen Timeout, bleibt die Verbindung trotzdem
// nutzbar (für alles, was ohnehin keine Auth braucht), nur
// getRoomFavorites()/servicePlay() lehnen dann mit einem klaren Fehler ab,
// statt die ganze Bridge zum Absturz zu bringen.
//
// Verifizierter Ablauf (audio/cfg/getkey/full → Miniserver-JWT via
// jdev/sys/getjwt Permission 2 → secure/authenticate):
//   1. WS-Connect zu ws://<host>:<port>/ mit Unterprotokoll "remotecontrol"
//   2. Banner (Klartext): "LWSS V <firmware> | ~API:<api>~ | Session-Token: <token>"
//   3. "audio/cfg/getkey/full" -> {"getkey_result":[{"pubkey":"-----BEGIN PUBLIC KEY-----..."}]}
//   4. Miniserver-JWT holen (siehe miniserver_jwt.js)
//   5. AES-256-CBC(PKCS7) über das JWT, RSA-PKCS1v1.5 über "key:iv:sessionToken"
//      mit dem Audioserver-Public-Key, dann:
//      "secure/authenticate/<user>/<urlenc(rsaB64)>/<urlenc(aesB64)>"
//      -> {"authenticate_result":"authentication successful"}

const GREETING_RE = /^LWSS V (\S+) \| ~API:([^~]*)~ \| Session-Token: (.+)$/;
const CONNECT_TIMEOUT_MS = 5000;
const BANNER_TIMEOUT_MS = 5000;
const AUTH_TIMEOUT_MS = 8000;

// ── ASN.1/DER-Minimalparser für SPKI-RSA-Keys ──────────────────────────────
// KORREKTUR 2026-09-28: Sonn Cores "audio/cfg/getkey/full"-Antwort ist
// vollkommen korrektes DER (Standard 2048-Bit RSA, Exponent 65537, korrektes
// Vorzeichen-Padding-Byte am Modulus — per Hand nachgeprüft) — trotzdem
// lehnt Node v25.9.0/OpenSSL 3.5.5s PEM/SPKI-Decoder (crypto.createPublicKey
// mit format:'pem') genau DIESEN validen Key mit "error:1E08010C:DECODER
// routines::unsupported" ab (Node-/OpenSSL-Eigenheit, nicht der Schlüssel
// ist kaputt). Über den JWK-Weg (Modulus/Exponent selbst aus dem DER lesen,
// dann format:'jwk') funktioniert exakt derselbe Schlüssel dagegen
// zuverlässig — deshalb hier grundsätzlich so statt über crypto's
// PEM-Decoder, für PEM- UND rohe Hex-Modulus-Antworten gleichermaßen.
function readDerLength(buf, pos) {
  const first = buf[pos];
  if (first < 0x80) return { length: first, next: pos + 1 };
  const numBytes = first & 0x7f;
  let length = 0;
  for (let i = 0; i < numBytes; i++) length = (length << 8) | buf[pos + 1 + i];
  return { length, next: pos + 1 + numBytes };
}
function readDerElement(buf, pos) {
  const tag = buf[pos];
  const { length, next } = readDerLength(buf, pos + 1);
  return { tag, start: next, end: next + length, after: next + length };
}

// der = komplette SubjectPublicKeyInfo-DER-Struktur (SEQUENCE{AlgorithmIdentifier, BIT STRING{SEQUENCE{INTEGER n, INTEGER e}}})
function rsaJwkFromSpkiDer(der) {
  const outer = readDerElement(der, 0);
  const alg = readDerElement(der, outer.start);
  const bitstr = readDerElement(der, alg.after);
  const rsaSeq = readDerElement(der, bitstr.start + 1); // +1: "unused bits"-Byte der BIT STRING überspringen
  const modInt = readDerElement(der, rsaSeq.start);
  const expInt = readDerElement(der, modInt.after);
  let nBuf = der.subarray(modInt.start, modInt.end);
  if (nBuf.length > 1 && nBuf[0] === 0x00) nBuf = nBuf.subarray(1); // Vorzeichen-Padding-Byte, JWK erwartet unsigned
  const eBuf = der.subarray(expInt.start, expInt.end);
  return { kty: 'RSA', n: nBuf.toString('base64url'), e: eBuf.toString('base64url') };
}

function publicKeyFromGetkey(getkeyResult) {
  const entry = Array.isArray(getkeyResult) ? getkeyResult[0] : getkeyResult;
  if (!entry || !entry.pubkey) throw new Error('getkey_result ohne pubkey');
  let jwk;
  if (/^-+BEGIN /.test(entry.pubkey)) {
    const b64 = entry.pubkey.replace(/-+BEGIN PUBLIC KEY-+/, '').replace(/-+END PUBLIC KEY-+/, '').replace(/\s+/g, '');
    jwk = rsaJwkFromSpkiDer(Buffer.from(b64, 'base64'));
  } else {
    // Altes exp+hex-Modulus-Schema ohne PEM-Hülle (siehe audioserver_probe_auth.js) —
    // bisher auf keiner Firmware beobachtet, aber ohne Risiko mitgenommen.
    const exp = entry.exp || 65537;
    const nBuf = Buffer.from(entry.pubkey, 'hex');
    const hex = exp.toString(16);
    const eBuf = Buffer.from(hex.length % 2 === 0 ? hex : '0' + hex, 'hex');
    jwk = { kty: 'RSA', n: nBuf.toString('base64url'), e: eBuf.toString('base64url') };
  }
  return crypto.createPublicKey({ key: jwk, format: 'jwk' });
}

function buildAuthenticateCommand(user, jwt, sessionToken, publicKey) {
  const key = crypto.randomBytes(32);
  const iv = crypto.randomBytes(16);
  const cipher = crypto.createCipheriv('aes-256-cbc', key, iv); // Node-Default PKCS7
  const aesB64 = Buffer.concat([cipher.update(jwt, 'utf8'), cipher.final()]).toString('base64');
  const rsaPlain = Buffer.from(`${key.toString('hex')}:${iv.toString('hex')}:${sessionToken}`, 'utf8');
  const rsaB64 = crypto.publicEncrypt({ key: publicKey, padding: crypto.constants.RSA_PKCS1_PADDING }, rsaPlain).toString('base64');
  return `secure/authenticate/${user}/${encodeURIComponent(rsaB64)}/${encodeURIComponent(aesB64)}`;
}

class AudioserverControlClient extends EventEmitter {
  // msConn = { host, port, secure, user, pass } des Miniservers, der für
  // dieses Audioserver-Box das JWT ausstellen soll (siehe bridge.js
  // loadMiniserverConn(msno)). Ohne msConn wird gar nicht erst versucht,
  // sich anzumelden — die Verbindung bleibt dauerhaft im Fallback-Modus.
  constructor(host, port = 7091, msConn = null) {
    super();
    this.host = host;
    this.port = port;
    this.msConn = msConn;
    this.authenticated = false;
    this.authFailReason = null;
    this.closed = false;
    this._sessionToken = null;
    this._pending = [];
    // Mehrere Panels/Zonen teilen sich EINE Verbindung pro Box (Registry,
    // siehe getAudioserverControlClient) — die Antworten (z.B. zwei
    // getroomfavs_result für zwei verschiedene Zonen) sind aber generisch
    // und lassen sich am Inhalt NICHT unterscheiden, welche Anfrage sie
    // beantworten. Ohne Serialisierung hätte eine einzelne ankommende
    // Antwort ALLE wartenden Aufrufe mit demselben (falschen) Ergebnis
    // aufgelöst (auf Hardware beobachtet 2026-09-28: Wohnzimmer- und
    // Esszimmer-Favoriten kamen identisch an, obwohl Wohnzimmer nur einen
    // eigenen Favoriten hat). _call() serialisiert deshalb jede Anfrage,
    // die eine Antwort erwartet, strikt nacheinander über diese Kette.
    this._chain = Promise.resolve();
    this._ready = this._connectAndAuthenticate();
  }

  _waitFor(matcher, timeoutMs) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        const i = this._pending.findIndex((p) => p.resolve === wrapped);
        if (i >= 0) this._pending.splice(i, 1);
        reject(new Error('Timeout beim Warten auf Audioserver-Antwort'));
      }, timeoutMs || 6000);
      const wrapped = (v) => { clearTimeout(timer); resolve(v); };
      this._pending.push({ matcher, resolve: wrapped });
    });
  }

  // Sendet cmd und wartet auf die passende Antwort — garantiert seriell
  // relativ zu allen anderen _call()-Aufrufen auf demselben Client (siehe
  // Kommentar am Konstruktor). NICHT für den initialen Auth-Handshake
  // genutzt (läuft ohnehin allein vor jedem anderen Aufruf, siehe _ready).
  _call(cmd, matcher, timeoutMs) {
    const run = () => {
      const wait = this._waitFor(matcher, timeoutMs);
      this.ws.send(cmd);
      return wait;
    };
    const result = this._chain.then(run, run);
    this._chain = result.then(() => {}, () => {}); // Kette lebt weiter, auch nach Fehler/Timeout
    return result;
  }

  async _connectAndAuthenticate() {
    const url = `ws://${this.host}:${this.port}/`;
    console.log(`[audioserver-auth] Verbinde zu ${url} (Unterprotokoll remotecontrol)...`);
    const ws = new WebSocket(url, 'remotecontrol');
    this.ws = ws;

    ws.on('message', (data) => {
      const text = data.toString();
      const greet = GREETING_RE.exec(text.trim());
      if (greet && !this._sessionToken) {
        this._sessionToken = greet[3];
        return;
      }
      let parsed;
      try { parsed = JSON.parse(text); } catch (e) { return; }
      for (let i = this._pending.length - 1; i >= 0; i--) {
        if (this._pending[i].matcher(parsed)) {
          const p = this._pending[i];
          this._pending.splice(i, 1);
          p.resolve(parsed);
          break; // nur der EINE passende Wartende darf diese Nachricht bekommen (siehe _call()-Kommentar am Konstruktor)
        }
      }
    });
    ws.on('error', (e) => {
      console.error(`[audioserver-auth] ${this.host}: WS-Fehler:`, e.message);
      this.emit('error', e);
    });
    ws.on('close', () => {
      this.authenticated = false;
      this.closed = true; // Registry (getAudioserverControlClient) erkennt daran: neu verbinden statt toten Client weiterreichen.
      this.emit('close');
    });

    try {
      await new Promise((resolve, reject) => {
        const t = setTimeout(() => reject(new Error('Timeout beim Verbindungsaufbau')), CONNECT_TIMEOUT_MS);
        ws.once('open', () => { clearTimeout(t); resolve(); });
        ws.once('error', (e) => { clearTimeout(t); reject(e); });
      });
    } catch (e) {
      this.closed = true;
      this.authFailReason = 'connect-failed: ' + e.message;
      try { ws.terminate(); } catch (_) { /* schon tot */ }
      return;
    }

    const bannerDeadline = Date.now() + BANNER_TIMEOUT_MS;
    while (!this._sessionToken && Date.now() < bannerDeadline && ws.readyState === WebSocket.OPEN) {
      await new Promise((r) => setTimeout(r, 100));
    }
    if (!this._sessionToken) {
      this.authFailReason = 'kein Banner/Session-Token erhalten';
      console.warn(`[audioserver-auth] ${this.host}: ${this.authFailReason} — bleibe im Fallback-Modus (kein Auth).`);
      this.emit('auth-failed', this.authFailReason);
      return;
    }

    if (!this.msConn) {
      this.authFailReason = 'kein Miniserver für JWT konfiguriert';
      this.emit('auth-failed', this.authFailReason);
      return;
    }

    try {
      await this._authenticate();
      this.authenticated = true;
      console.log(`[audioserver-auth] ${this.host}: Authentifiziert ✓ — Raumfavoriten/Steuerbefehle verfügbar.`);
      this.emit('authenticated');
    } catch (e) {
      this.authFailReason = e.message;
      console.warn(`[audioserver-auth] ${this.host}: Anmeldung fehlgeschlagen (${e.message}) — Fallback: Box läuft ohne Favoriten/Steuerbefehle über Auth weiter, reine Zustands-Anzeige (audioserver.js) ist davon nicht betroffen.`);
      this.emit('auth-failed', this.authFailReason);
    }
  }

  async _authenticate() {
    const getkeyWait = this._waitFor((m) => !!m.getkey_result, AUTH_TIMEOUT_MS);
    this.ws.send('audio/cfg/getkey/full');
    const getkeyMsg = await getkeyWait;
    let publicKey;
    try {
      publicKey = publicKeyFromGetkey(getkeyMsg.getkey_result);
    } catch (e) {
      throw new Error(`audio/cfg/getkey/full lieferte keinen brauchbaren Public-Key (${e.message}) — Box unterstützt diesen Befehl vermutlich nicht`);
    }

    const { token: jwt, user } = await fetchMiniserverJwt(this.msConn, AUTH_TIMEOUT_MS);

    const authWait = this._waitFor((m) => !!m.authenticate_result, AUTH_TIMEOUT_MS);
    this.ws.send(buildAuthenticateCommand(user, jwt, this._sessionToken, publicKey));
    const authMsg = await authWait;
    if (authMsg.authenticate_result !== 'authentication successful') {
      throw new Error('Audioserver antwortete: ' + authMsg.authenticate_result);
    }
  }

  // Wartet bis Connect+Auth-Versuch (Erfolg ODER Fallback) abgeschlossen ist.
  async ready() {
    await this._ready;
    return this.authenticated;
  }

  async getRoomFavorites(playerid) {
    await this._ready;
    if (!this.authenticated) {
      throw new Error(`Raumfavoriten nicht verfügbar (${this.authFailReason || 'nicht authentifiziert'}) — Fallback aktiv, Box unterstützt secure/authenticate evtl. nicht.`);
    }
    const msg = await this._call(`audio/cfg/getroomfavs/${playerid}/0/50`, (m) => !!m.getroomfavs_result, AUTH_TIMEOUT_MS);
    const group = (msg.getroomfavs_result || [])[0] || {};
    return group.items || [];
  }

  // Verifiziert 2026-09-28 auf Sonn Core UND echtem Audioserver: generischer,
  // typunabhängiger Play-Befehl (funktioniert für loxoneradio/custom_stream/
  // spotify_track/tunein gleichermaßen — kein serviceplay-Nachbau pro Typ nötig).
  async playRoomFavorite(playerid, favId) {
    await this._ready;
    if (!this.authenticated) {
      throw new Error(`Favorit abspielen nicht verfügbar (${this.authFailReason || 'nicht authentifiziert'}) — Fallback aktiv, Box unterstützt secure/authenticate evtl. nicht.`);
    }
    return this._call(`audio/${playerid}/roomfav/play/${favId}`, (m) => !!m.roomfav_result, AUTH_TIMEOUT_MS);
  }

  close() {
    this.closed = true;
    if (this.ws) this.ws.close();
  }
}

// ── Registry: eine Steuer-Verbindung pro Host:Port, wiederverwendet über
// alle Panels/Zonen derselben Box (Auth-Handshake dauert ~1-2s — nicht pro
// Kommando neu aufbauen). Ein Client, der unerwartet geschlossen wurde
// (ws close-Event setzt closed=true, siehe oben), wird beim nächsten Zugriff
// automatisch durch einen frischen ersetzt.
const _controlClients = new Map();

function getAudioserverControlClient(host, port, msConn) {
  const key = `${host}:${port}`;
  let client = _controlClients.get(key);
  if (!client || client.closed) {
    client = new AudioserverControlClient(host, port, msConn);
    _controlClients.set(key, client);
  }
  return client;
}

module.exports = { AudioserverControlClient, getAudioserverControlClient, publicKeyFromGetkey, buildAuthenticateCommand };
