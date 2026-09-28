'use strict';

const fs = require('fs');
const path = require('path');

// ── Miniserver-Zugangsdaten aus general.json — robuste Variante ───────────
// bridge.js hat eine eigene, einfachere loadMiniserverConn() (nur ms.Port,
// kein HTTPS/wss-Erkennung). Für den Audioserver-Auth-Handshake reicht das
// nicht: ms.Port kann bei HTTPS-Miniservern falsch/veraltet sein (empirisch
// 2026-09-28 beobachtet: zeigte "80" trotz https://...@host:443) — hier
// deshalb wie in den Probe-Skripten aus Fulluri/Fulluri_raw geparst.
// msnoOrName: general.json-Schlüssel ("1","2",...) ODER der "Name"-Feldwert
// des Miniservers (z.B. "Patrick") — Namenssuche ist robuster, da die
// Nummerierung mehrerer Miniserver auf einer LoxBerry nicht offensichtlich ist.
function readGeneralJson() {
  const LBHOMEDIR = process.env.LBHOMEDIR || '/opt/loxberry';
  const generalPath = path.join(LBHOMEDIR, 'config', 'system', 'general.json');
  return JSON.parse(fs.readFileSync(generalPath, 'utf8'));
}

function loadMiniserverConn(msnoOrName) {
  const general = readGeneralJson();
  const msSection = general.Miniserver || general.Miniservers;
  if (!msSection) throw new Error('Kein Miniserver-Abschnitt in general.json gefunden');
  let ms = msSection[msnoOrName] || msSection[String(msnoOrName)];
  if (!ms) ms = Object.values(msSection).find((v) => v && v.Name === msnoOrName);
  if (!ms) throw new Error(`Miniserver "${msnoOrName}" nicht in LoxBerry general.json gefunden`);

  const host = ms.Ipaddress || ms.IPAddress;
  if (!host) throw new Error(`Miniserver "${msnoOrName}": IP-Adresse nicht gefunden`);

  // KEIN new URL(): LoxBerry schreibt IPv4-Adressen bei Ipv6format:"1" in
  // eckigen Klammern ("[192.168.178.48]"), das ist kein gültiges URL-Format
  // und lässt den WHATWG-Parser werfen — einfaches Regex-Parsen ist robuster.
  const fulluri = ms.Fulluri || ms.Fulluri_raw || '';
  const secure = /^https:/i.test(fulluri);
  const portMatch = fulluri.match(/:(\d+)(?:\/|$)/);
  const port = portMatch ? Number(portMatch[1]) : (secure ? 443 : 80);

  return {
    host,
    port,
    secure,
    user: ms.Admin_raw || ms.Admin || '',
    pass: ms.Pass_raw || ms.Pass || '',
    name: ms.Name || '',
  };
}

module.exports = { loadMiniserverConn, readGeneralJson };
