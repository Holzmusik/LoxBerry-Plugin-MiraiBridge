'use strict';

const crypto = require('crypto');
const http = require('http');
const https = require('https');
const WebSocket = require('ws');

// ── Miniserver-JWT (Permission 2) holen — eigener Token-Enc-Handshake ──────
// node-lox-ws-api (das bridge.js für die normale Miniserver-Verbindung
// nutzt) kennt nur "jdev/sys/gettoken" (liefert einen opaken Token-Enc-
// Sitzungsstring, KEIN JWT) und hat dafür Permission "2" fest verdrahtet.
// Für secure/authenticate gegenüber einem echten Audioserver braucht es
// aber ein echtes signiertes JWT (header {"typ":"JWT","alg":"RS256"}), das
// nur "jdev/sys/getjwt" liefert — deshalb hier ein eigener, minimaler
// RSA-Keyexchange + AES-Handshake (1:1 nach node-lox-ws-api/lib/Auth/
// Token-Enc.js), nur mit getjwt statt gettoken. Auf Hardware verifiziert
// 2026-09-28 gegen einen echten Loxone-Miniserver.
const CLIENT_UUID = 'edfc5f9a-df3f-4cad-9dddcdc42c732be2';
const CLIENT_INFO = 'miraibridge';

function getPublicKey(host, port, secure) {
  return new Promise((resolve, reject) => {
    const mod = secure ? https : http;
    const opts = secure ? { rejectUnauthorized: false } : {};
    mod.get(`${secure ? 'https' : 'http'}://${host}:${port}/jdev/sys/getPublicKey`, opts, (res) => {
      let body = '';
      res.on('data', (c) => (body += c));
      res.on('end', () => {
        try {
          const data = JSON.parse(body);
          let key = data.LL.value.replace(/CERTIFICATE/g, 'PUBLIC KEY');
          key = key.replace(/^(-+BEGIN PUBLIC KEY-+)(\S)/, '$1\n$2');
          key = key.replace(/(\S)(-+END PUBLIC KEY-+)/, '$1\n$2');
          resolve(key);
        } catch (e) {
          reject(new Error('getPublicKey nicht parsbar: ' + e.message));
        }
      });
    }).on('error', reject);
  });
}

// conn = { host, port, secure, user, pass } — z.B. das Ergebnis von
// bridge.js loadMiniserverConn(msno). Liefert { token, user } (token =
// signiertes JWT für secure/authenticate) oder wirft bei Fehler/Timeout.
function fetchMiniserverJwt(conn, timeoutMs = 8000) {
  return new Promise((resolve, reject) => {
    const wsUrl = `${conn.secure ? 'wss' : 'ws'}://${conn.host}:${conn.port}/ws/rfc6455`;
    const ws = new WebSocket(wsUrl, 'remotecontrol', conn.secure ? { rejectUnauthorized: false } : undefined);

    const overallTimer = setTimeout(() => {
      ws.terminate();
      reject(new Error('Timeout beim Holen des Miniserver-JWT'));
    }, timeoutMs);
    const done = (fn) => (...args) => { clearTimeout(overallTimer); fn(...args); };
    const resolveOnce = done(resolve);
    const rejectOnce = done(reject);

    const aesKey = crypto.createHash('sha256').update(crypto.randomBytes(16).toString('hex')).digest();
    const aesIv = crypto.randomBytes(16);
    let saltUsage = 0;
    let currentSalt = crypto.randomBytes(16).toString('hex');

    function cipher(data) {
      const c = crypto.createCipheriv('aes-256-cbc', aesKey, aesIv);
      return c.update(data + '\0', 'utf-8', 'base64') + c.final('base64');
    }
    function encCommand(command) {
      let saltPart = 'salt/' + currentSalt;
      saltUsage++;
      if (saltUsage >= 20) {
        const oldSalt = currentSalt;
        currentSalt = crypto.randomBytes(16).toString('hex');
        saltPart = `nextSalt/${oldSalt}/${currentSalt}`;
        saltUsage = 0;
      }
      return 'jdev/sys/enc/' + encodeURIComponent(cipher(saltPart + '/' + command));
    }

    const waiters = [];
    function waitFor(pattern, ms) {
      return new Promise((res, rej) => {
        const t = setTimeout(() => {
          const i = waiters.findIndex((w) => w.resolve === wrapped);
          if (i >= 0) waiters.splice(i, 1);
          rej(new Error('Timeout beim Warten auf ' + pattern));
        }, ms || 6000);
        const wrapped = (v) => { clearTimeout(t); res(v); };
        waiters.push({ pattern, resolve: wrapped });
      });
    }

    ws.on('message', (data) => {
      let parsed;
      try { parsed = JSON.parse(data.toString()); } catch (e) { return; }
      const control = parsed.LL && parsed.LL.control;
      if (control === undefined) return;
      const frame = { control, value: parsed.LL.value, code: parsed.LL.Code !== undefined ? parsed.LL.Code : parsed.LL.code };
      for (let i = waiters.length - 1; i >= 0; i--) {
        if (waiters[i].pattern.test(frame.control)) {
          const w = waiters[i];
          waiters.splice(i, 1);
          w.resolve(frame);
        }
      }
    });
    ws.on('error', (e) => rejectOnce(new Error('Miniserver-WS-Fehler: ' + e.message)));

    (async () => {
      try {
        await new Promise((res, rej) => { ws.once('open', res); ws.once('error', rej); });
        const pubKeyPem = await getPublicKey(conn.host, conn.port, conn.secure).catch((e) => {
          throw new Error('getPublicKey (HTTP) fehlgeschlagen: ' + e.message);
        });
        let rsaEnc;
        try {
          rsaEnc = crypto.publicEncrypt(
            { key: pubKeyPem, padding: crypto.constants.RSA_PKCS1_PADDING },
            Buffer.from(aesKey.toString('hex') + ':' + aesIv.toString('hex'))
          );
        } catch (e) {
          throw new Error('publicEncrypt (Keyexchange) fehlgeschlagen: ' + e.message);
        }
        const keyexchangeWait = waitFor(/^j?dev\/sys\/keyexchange\//);
        ws.send('jdev/sys/keyexchange/' + rsaEnc.toString('base64'));
        await keyexchangeWait;

        const getkey2Wait = waitFor(/^j?dev\/sys\/getkey2\//);
        ws.send(encCommand('jdev/sys/getkey2/' + conn.user));
        const getkey2Frame = await getkey2Wait;
        const serverKey = Buffer.from(getkey2Frame.value.key, 'hex').toString('utf8');
        const salt = getkey2Frame.value.salt;
        const pwHash = crypto.createHash('sha1').update(conn.pass + ':' + salt).digest('hex').toUpperCase();
        const hmac = crypto.createHmac('sha1', serverKey).update(conn.user + ':' + pwHash).digest('hex');

        const getjwtWait = waitFor(/^j?dev\/sys\/getjwt\//);
        ws.send(encCommand(`jdev/sys/getjwt/${hmac}/${conn.user}/2/${CLIENT_UUID}/${CLIENT_INFO}`));
        const getjwtFrame = await getjwtWait;
        if (String(getjwtFrame.code) !== '200') {
          throw new Error('getjwt fehlgeschlagen, Code ' + getjwtFrame.code);
        }
        ws.close();
        resolveOnce({ token: getjwtFrame.value.token, user: conn.user });
      } catch (err) {
        try { ws.terminate(); } catch (e) { /* egal, wird ohnehin verworfen */ }
        rejectOnce(err);
      }
    })();
  });
}

module.exports = { fetchMiniserverJwt };
