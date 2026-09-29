'use strict';

// ── CLI-Wrapper um loxone_radio_setup.js — fuer manuelle/Ad-hoc-Nutzung
//    (die produktive Variante laeuft per Knopfdruck aus dem Web-UI ueber
//    bridge.js' lokalen Scan-Server, siehe dort /setup-loxone-radio). ────
//
// Nutzung:
//   node setup_loxone_radio_streams.js <sonncore-host> <miniserver-msno>

const { setupLoxoneRadioStreams } = require('./loxone_radio_setup');
const { loadMiniserverConn } = require('./loxberry_general');

async function main() {
  const [host, msnoArg] = process.argv.slice(2);
  if (!host || !msnoArg) {
    console.error('Nutzung: node setup_loxone_radio_streams.js <sonncore-host> <miniserver-msno>');
    process.exit(1);
  }

  const msConn = loadMiniserverConn(msnoArg);
  const result = await setupLoxoneRadioStreams(host, 7091, msConn);

  console.log(`\nNeu angelegt (${result.added.length}): ${result.added.join(', ') || '-'}`);
  console.log(`Uebersprungen, schon vorhanden (${result.skipped.length}): ${result.skipped.join(', ') || '-'}`);
  if (result.errors.length) {
    console.log(`Fehler (${result.errors.length}):`);
    for (const e of result.errors) console.log(`  - ${e.name}: ${e.error}`);
  }
  process.exit(result.errors.length ? 1 : 0);
}

main().catch((e) => { console.error('[setup] Fehler:', e); process.exit(1); });
