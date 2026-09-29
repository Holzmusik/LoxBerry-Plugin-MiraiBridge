<?php
// MiraiBridge Haupt-UI, eingebettet in die LoxBerry-Seitenchrome (Header + Plugin-Sidebar).
require_once "loxberry_web.php";
// $htmlhead wird von get_head() nach den Standard-Favicons, aber noch vor
// </head> eingefuegt (siehe templates/system/head.html im LoxBerry-Core-
// Repo) -- das eigene Favicon ueberschreibt dadurch fuer diese Seite das
// System-Standard-Icon, ohne LoxBerry selbst anzufassen.
global $htmlhead;
$htmlhead = '<link rel="icon" type="image/x-icon" href="favicon.ico">';
LBWeb::lbheader("MiraiBridge", "", "", true); // true = kein jQuery Mobile (eigenes Vanilla-JS-UI)
?>
<style>
.mp-app {
  /* Hell (Standard): an LoxBerrys Design-System-Tokens (design-tokens.css)
     angelehnt, damit MiraiBridge zu Classic/Glass/Modern & Co. passt.
     --surf2 bewusst über --lb-input-bg (nicht --lb-gray-100!) hergeleitet:
     Themes wie theme-glass überschreiben --lb-input-bg pro Theme, fassen
     die rohen --lb-gray-*-Skalen-Token aber nie an — mit --lb-gray-100 blieb
     --surf2 (Hintergrund von Inputs/Selects/Badges/Funktionsblock-Zeilen) in
     jedem Theme außer unserem eigenen Dark-Override immer hell/weiß hängen. */
  --bg:      var(--lb-bg, #f7f7f7);
  --surface: var(--lb-card-bg, #fff);
  --surf2:   var(--lb-input-bg, #f5f5f5);
  --brd:     var(--lb-border-color, #e5e5e5);
  --txt:     var(--lb-text, #171717);
  --txt2:    var(--lb-text-muted, #737373);
  --acc:     var(--lb-primary, #6dac20);
  --warn:    var(--lb-warning, #ca8a04);
  --danger:  var(--lb-danger, #dc2626);
  --radius:  var(--lb-radius, 12px);
  color: var(--txt); font: 14px/1.5 system-ui, sans-serif;
}
/* Diese Regel ist bewusst nicht aktiv — LoxBerry 4.x setzt --lb-bg, --lb-card-bg
   etc. per Theme-CSS selbst, die Plugin-Variablen (--bg: var(--lb-bg,...)) erben
   automatisch die richtigen Werte inkl. Glassmorphism-Dark. Ein harter Override
   hier würde LoxBerrys eigene Theme-Farben überschreiben.
   Klasse für Glassmorphism-Dark: "theme-glass" (body-class in LB 4.x). */
body.theme-glass-disabled .mp-app {
  --bg:      #111;
  --surface: #1a1a1a;
  --surf2:   #252525;
  --brd:     #333;
  --txt:     #f0f0f0;
  --txt2:    #888;
  --acc:     #66cd00;
  --warn:    #ff9800;
  --danger:  #f44;
}
.mp-app, .mp-app * { box-sizing: border-box; margin: 0; padding: 0; }

/* ── Layout ── */
.mp-app .shell {
  display: flex; min-height: 640px; background: var(--bg);
  border-radius: var(--radius); overflow: hidden; border: 1px solid var(--brd);
}
.mp-app .sidebar { width: 220px; background: var(--surface); border-right: 1px solid var(--brd); display: flex; flex-direction: column; flex-shrink: 0; }
.mp-app .sidebar-logo { padding: 20px 18px 12px; font-size: 17px; font-weight: 700; color: var(--acc); letter-spacing: -.3px; border-bottom: 1px solid var(--brd); }
.mp-app .sidebar-logo span { color: var(--txt2); font-weight: 400; font-size: 12px; display: block; margin-top: 2px; }
.mp-app .nav { flex: 1; padding: 8px 0; }
.mp-app .nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 18px; cursor: pointer; color: var(--txt2); border-left: 3px solid transparent; transition: all .15s; }
.mp-app .nav-item:hover { color: var(--txt); background: var(--surf2); }
.mp-app .nav-item.active { color: var(--acc); border-left-color: var(--acc); background: rgba(102,205,0,.07); }
.mp-app .nav-item svg { flex-shrink: 0; }
/* Gruppiert die Geräte-Tabs (Konfiguration/Layout/Status/Netzwerk/Firmware/
   Log) einklappbar unter den Hauptpunkten. Der Umschalter ("Gerät") sieht
   wie ein normaler Hauptpunkt aus (gleiche .nav-item-Optik), mit Chevron
   rechts, der beim Aufklappen rotiert. Standard: zugeklappt (siehe
   #device-nav-items style="display:none" im Markup). Das Monitor-Icon
   dreht sich bewusst mit auf "hochkant" — kleiner verspielter Touch, kein
   Bug (war ursprünglich einer, gefiel aber auf Wunsch). */
.mp-app .nav-section-toggle { margin-top: 6px; }
#device-nav-chevron { flex-shrink: 0; margin-left: auto; transition: transform .15s; }
.nav-section-toggle.open #device-nav-chevron { transform: rotate(90deg); }
#device-nav-icon { transition: transform .2s; }
.nav-section-toggle.open #device-nav-icon { transform: rotate(90deg); }
/* Eigene Icons wie bei den Hauptpunkten halten die Textausrichtung
   konsistent -- zusätzlich etwas mehr padding-left als die Hauptpunkte,
   damit die Gruppe sichtbar eingerückt/verschachtelt wirkt. */
.mp-app .nav-item.nav-sub { padding-left: 26px; font-size: 13px; }
.mp-app .main { flex: 1; overflow: hidden; padding: 28px; min-height: 0; display: flex; flex-direction: column; }

/* ── Karten ── */
.mp-app .page { display: none; }
/* flex-direction:column damit Seiten ihren Inhalt stapeln; overflow-y:auto damit
   Seiten mit viel Inhalt (Einstellungen, Panels) selbst scrollen statt .main */
.mp-app .page.active { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow-y: auto; }
/* Editor-Seite: kein eigener Scroll — iframe wächst per flex auf die restliche Höhe */
#page-editor.page.active { overflow: hidden; }
/* layout-embed: wenn sichtbar (JS setzt display:flex), füllt restliche page-editor-Höhe */
#layout-embed { flex: 1; min-height: 0; flex-direction: column; }
.mp-app h2 { font-size: 18px; font-weight: 600; margin-bottom: 20px; }
.mp-app h3 { font-size: 14px; font-weight: 600; margin-bottom: 14px; color: var(--txt2); text-transform: uppercase; letter-spacing: .5px; }
.mp-app .card { background: var(--surface); border: 1px solid var(--brd); border-radius: var(--radius); padding: 20px; margin-bottom: 16px; }
.mp-app .card-title { font-size: 15px; font-weight: 600; margin-bottom: 16px; }

/* ── Formular ── */
/* Basis-Look für JEDES <select> im Plugin — ohne diese Regel fallen
   Dropdowns ohne eigene Klasse (z.B. im Layout-Editor) auf den nativen,
   hellen Browser-Stil zurück statt zum dunklen Theme zu passen. Alle
   Selects im Plugin nutzen exakt diesen Look (Farbe/Rahmen/Radius/Padding/
   Schriftgröße) — nur die Breite wird je nach Einbau-Kontext angepasst
   (z.B. .ctrl-widget: max-width statt 100%, weil es inline neben Text
   sitzt; .widget-row-fields: enge Grid-Spalten im Editor). */
/* appearance:none + eigener SVG-Pfeil statt des nativen Browser-/OS-Pfeils
   (den man mit CSS sonst nicht einfärben/gestalten kann) — sieht sonst vor
   allem im hellen Theme sehr "nackt"/unstyled aus, da Hintergrund/Rahmen
   dort ohnehin sehr dezent (weiß/hellgrau) sind. Rechtes Padding macht
   Platz für den Pfeil, an LoxBerrys eigenem .lb-select-Muster orientiert. */
.mp-app select {
  background-color: var(--surf2);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  appearance: none; -webkit-appearance: none; -moz-appearance: none;
  border: 1px solid var(--brd); border-radius: 8px; padding: 9px 32px 9px 12px;
  color: var(--txt); font-size: 14px; outline: none; cursor: pointer;
}
.mp-app select:focus { border-color: var(--acc); box-shadow: 0 0 0 3px rgba(102,205,0,.15); }
.mp-app select option { background: var(--surf2); color: var(--txt); }

/* Gleiche Überlegung für Text-/Zahlen-Inputs: ohne diese Basis-Regel bleiben
   Inputs außerhalb von .field (z.B. die Hardware-Tasten/Sensor-Ziele/Audio-
   Kommandos-Felder in .ctrl-widget, die <input list="..."> als Datalist-
   Kombifeld nutzen) im nativen, hellen Browser-Stil hängen. */
.mp-app input[type=text], .mp-app input[type=number] { background: var(--surf2); border: 1px solid var(--brd); border-radius: 8px; padding: 9px 12px; color: var(--txt); font-size: 14px; outline: none; }
.mp-app input[type=text]:focus, .mp-app input[type=number]:focus { border-color: var(--acc); }

.mp-app .field { margin-bottom: 14px; }
.mp-app .field label { display: block; font-size: 12px; color: var(--txt2); margin-bottom: 5px; }
.mp-app .field input, .mp-app .field select { width: 100%; }
.mp-app .row { display: flex; gap: 12px; }
.mp-app .row .field { flex: 1; }

/* ── Buttons ── */
.mp-app .btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; font-weight: 500; transition: opacity .15s; }
.mp-app .btn:hover { opacity: .85; }
.mp-app .btn-primary { background: var(--acc); color: #000; }
.mp-app .btn-secondary { background: var(--surf2); color: var(--txt); border: 1px solid var(--brd); }
.mp-app .btn-danger { background: var(--danger); color: #fff; }
.mp-app .btn-sm { padding: 6px 12px; font-size: 13px; }
/* margin-bottom fehlte bisher: eine .card, die direkt auf eine .btn-group
   folgt (z.B. "Konfiguration sichern & wiederherstellen" nach Speichern/
   Loxone testen in den Einstellungen), klebte dadurch fast ohne Abstand am
   Button. */
.mp-app .btn-group { display: flex; gap: 10px; margin-top: 18px; margin-bottom: 20px; flex-wrap: wrap; }

/* ── Panel-Liste ── */
.mp-app .panel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; margin-bottom: 20px; }
.mp-app .panel-card { background: var(--surface); border: 1px solid var(--brd); border-radius: var(--radius); padding: 16px; cursor: pointer; transition: border-color .15s; }
.mp-app .panel-card:hover { border-color: var(--acc); }
.mp-app .panel-card.selected { border-color: var(--acc); background: rgba(102,205,0,.06); }
.mp-app .panel-name { font-weight: 600; margin-bottom: 4px; }
.mp-app .panel-room { font-size: 12px; color: var(--txt2); margin-bottom: 10px; }
.mp-app .panel-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.mp-app .badge { font-size: 11px; padding: 3px 8px; border-radius: 20px; background: var(--surf2); color: var(--txt2); border: 1px solid var(--brd); }
.mp-app .badge.online { background: rgba(102,205,0,.15); color: var(--acc); border-color: rgba(102,205,0,.3); }
.mp-app .badge.warn   { background: rgba(255,152,0,.15); color: var(--warn); border-color: rgba(255,152,0,.3); }
.mp-app .add-panel-btn { border: 2px dashed var(--brd); border-radius: var(--radius); padding: 24px; text-align: center; cursor: pointer; color: var(--txt2); transition: border-color .15s; }
.mp-app .add-panel-btn:hover { border-color: var(--acc); color: var(--acc); }

/* ── Raum-Picker ── */
.mp-app .room-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; max-height: 300px; overflow-y: auto; }
.mp-app .room-item { padding: 10px 14px; border-radius: 8px; border: 1px solid var(--brd); cursor: pointer; transition: all .15s; }
.mp-app .room-item:hover { border-color: var(--acc); }
.mp-app .room-item.selected { border-color: var(--acc); background: rgba(102,205,0,.1); color: var(--acc); }
.mp-app .ctrl-list { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
.mp-app .ctrl-item { display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: var(--surf2); border-radius: 8px; border: 1px solid var(--brd); }
.mp-app .ctrl-type { font-size: 11px; color: var(--txt2); }
.mp-app .ctrl-widget select { max-width: 220px; }

/* ── Status ── */
.mp-app .status-bar { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--surf2); border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
.mp-app .status-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--txt2); flex-shrink: 0; }
.mp-app .status-dot.ok { background: var(--acc); }
.mp-app .status-dot.err { background: var(--danger); }
.mp-app .toast { position: fixed; bottom: 24px; right: 24px; background: var(--surf2); border: 1px solid var(--brd); border-radius: 10px; padding: 12px 18px; font-size: 13px; box-shadow: 0 4px 20px rgba(0,0,0,.5); transform: translateY(80px); opacity: 0; transition: all .25s; z-index: 999; }
.mp-app .toast.show { transform: translateY(0); opacity: 1; }
.mp-app .toast.ok  { border-color: var(--acc); }
.mp-app .toast.err { border-color: var(--danger); }
.mp-app .divider { border: none; border-top: 1px solid var(--brd); margin: 20px 0; }

/* ── Mobile (≤680px) ── */
@media (max-width: 680px) {
  /* Shell: von Zeile auf Spalte, kein overflow:hidden (sonst clippt Inhalt) */
  .mp-app .shell {
    flex-direction: column; min-height: 0;
    overflow: visible; border-radius: 0; border: none;
  }

  /* Sidebar → horizontale Tab-Leiste oben */
  .mp-app .sidebar {
    width: 100%; flex-direction: row; flex-shrink: 0;
    border-right: none; border-bottom: 1px solid var(--brd);
  }
  .mp-app .sidebar-logo { display: none; }
  .mp-app .nav { flex-direction: row; padding: 0; overflow-x: auto; }
  .mp-app .nav-item {
    flex-direction: column; gap: 3px; padding: 10px 14px;
    font-size: 11px; white-space: nowrap;
    border-left: none; border-bottom: 3px solid transparent;
    min-height: 52px; justify-content: center; align-items: center;
  }
  .mp-app .nav-item.active {
    border-left-color: transparent; border-bottom-color: var(--acc);
  }

  /* Main: weniger Padding, kein inneres Scroll (Seite scrollt selbst); display:block
     überschreibt das Desktop-flex, damit auf Mobil die Outer-Page-Scroll greift */
  .mp-app .main { padding: 16px; overflow-y: visible; display: block; }

  /* Formular-Zeilen: untereinander statt nebeneinander */
  .mp-app .row { flex-direction: column; gap: 0; }

  /* Panel-Grid: eine Spalte */
  .mp-app .panel-grid { grid-template-columns: 1fr; }

  /* Raum-Liste: 2 Spalten reichen auf Mobil */
  .mp-app .room-list { grid-template-columns: 1fr 1fr; }

  /* Control-Zeilen: Widget-Select nimmt volle Breite */
  .mp-app .ctrl-item { flex-wrap: wrap; gap: 6px; }
  .mp-app .ctrl-widget { width: 100%; margin-top: 4px; }
  .mp-app .ctrl-widget select { max-width: 100%; width: 100%; }

  /* Layout: iframe kleiner auf mobilen Geräten */
  #layout-iframe { height: calc(100vh - 300px); min-height: 400px; }

  /* Toast: volle Breite unten */
  .mp-app .toast { left: 12px; right: 12px; bottom: 12px; }
}
</style>

<div class="mp-app">
<div class="shell">

<!-- ── Sidebar ── -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div style="display:flex;align-items:center;gap:8px;">
      <svg width="26" height="26" viewBox="0 0 256 256" aria-hidden="true">
        <defs><linearGradient id="sidebarlogo" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#155C40"/><stop offset="1" stop-color="#0E3B2E"/></linearGradient></defs>
        <rect x="8" y="8" width="240" height="240" rx="56" fill="url(#sidebarlogo)"/>
        <rect x="82" y="30" width="40" height="40" rx="10" fill="none" stroke="#F4FBF6" stroke-width="12"/>
        <rect x="82" y="82" width="40" height="40" rx="10" fill="none" stroke="#F4FBF6" stroke-width="12"/>
        <rect x="134" y="82" width="40" height="40" rx="10" fill="none" stroke="#F4FBF6" stroke-width="12"/>
        <rect x="134" y="134" width="40" height="40" rx="10" fill="none" stroke="#F4FBF6" stroke-width="12"/>
        <rect x="82" y="186" width="40" height="40" rx="10" fill="none" stroke="#F4FBF6" stroke-width="12"/>
        <rect x="134" y="186" width="40" height="40" rx="10" fill="none" stroke="#F4FBF6" stroke-width="12"/>
        <rect x="82" y="134" width="40" height="40" rx="10" fill="#7ddc4a"/>
      </svg>
      MiraiBridge
    </div>
    <span>LoxBerry Plugin</span>
  </div>
  <nav class="nav">
    <div class="nav-item active" data-page="panels" onclick="showPage('panels',this)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Panels
    </div>
    <div class="nav-item nav-section-toggle" onclick="toggleDeviceNav()">
      <svg id="device-nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
      <span style="flex:1;">Gerät</span>
      <svg id="device-nav-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg>
    </div>
    <div id="device-nav-items" class="nav-sub-group" style="display:none;">
      <div class="nav-item nav-sub" data-page="editor" onclick="showDeviceTab('config',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        Konfiguration
      </div>
      <div class="nav-item nav-sub" data-page="editor" onclick="showDeviceTab('layout',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
        Layout
      </div>
      <div class="nav-item nav-sub" data-page="editor" onclick="showDeviceTab('status',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
        Status
      </div>
      <div class="nav-item nav-sub" data-page="editor" onclick="showDeviceTab('network',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.55a11 11 0 0 1 14.08 0M1.42 9a16 16 0 0 1 21.16 0M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor" stroke="none"/></svg>
        Netzwerk
      </div>
      <div class="nav-item nav-sub" data-page="editor" onclick="showDeviceTab('ota',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Firmware
      </div>
      <div class="nav-item nav-sub" data-page="editor" onclick="showDeviceTab('log',this)">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h7"/></svg>
        Log
      </div>
    </div>
    <div class="nav-item" data-page="settings" onclick="showPage('settings',this)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      Einstellungen
    </div>
    <div class="nav-item" data-page="bridge" onclick="showPage('bridge',this)">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
      Bridge-Status
    </div>
  </nav>
</aside>

<!-- ── Main ── -->
<main class="main">

  <!-- PANELS ── -->
  <div class="page active" id="page-panels">
    <h2>Panels</h2>
    <div class="panel-grid" id="panel-grid"></div>
    <div class="add-panel-btn" onclick="newPanel()">+ Neues Panel hinzufügen</div>

    <!-- Panel-Detail (hidden by default) -->
    <div id="panel-detail" style="display:none; margin-top:24px;">
      <hr class="divider">
      <div class="card">
        <div class="card-title" id="detail-title">Panel konfigurieren</div>

        <div class="row">
          <div class="field">
            <label>Panel-Name <small style="color:var(--txt2);font-weight:400">(= ESPHome <code>name:</code>)</small></label>
            <input type="text" id="p-name" placeholder="miraipanel">
          </div>
          <div class="field">
            <label>IP-Adresse</label>
            <input type="text" id="p-ip" placeholder="192.168.x.x">
          </div>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="scanPanels()" id="scan-btn">Netzwerk scannen</button>
        <div id="scan-results" style="display:none; margin-top:12px;">
          <h3>Gefundene MiraiPanel-Geräte</h3>
          <div class="ctrl-list" id="scan-list"></div>
        </div>

        <h3 style="margin-top:16px;">Raum aus Loxone Structure wählen</h3>
        <div id="room-loading" style="color:var(--txt2);font-size:13px;">Lade Räume…</div>
        <div class="room-list" id="room-list"></div>

        <div id="ctrl-section" style="display:none; margin-top:18px;">
          <h3>Erkannte Funktionsblöcke im Raum</h3>
          <div class="ctrl-list" id="ctrl-list"></div>
        </div>

        <div style="font-size:12px;color:var(--txt2);margin-top:16px;">
          Die Suche in den folgenden drei Abschnitten ist auf den oben gewählten Raum eingeschränkt (dort liegen die virtuellen Eingänge erfahrungsgemäß).
          <a href="#" onclick="updateVirtualInputDatalist(null); toast('Suche zeigt jetzt alle Räume'); return false;" style="color:var(--acc);">Alle Räume durchsuchen</a>
        </div>

        <h3 style="margin-top:16px;">Hardware-Tasten (1–8)</h3>
        <div style="font-size:12px;color:var(--txt2);margin-bottom:10px;">
          Physische Touch-Tasten am Panel — Ziel ist ein virtueller Eingang in Loxone (Typ "Slider").
        </div>
        <div class="ctrl-list" id="hw-keys-list"></div>

        <h3 style="margin-top:16px;">Sensor-Ziele</h3>
        <div style="font-size:12px;color:var(--txt2);margin-bottom:10px;">
          Virtuelle Eingänge in Loxone (Typ "Slider"), an die das Panel seine eigenen Messwerte sendet — nicht die Status-Bausteine, die den Wert später anzeigen.
        </div>
        <div class="ctrl-list" id="sensor-targets-list"></div>

        <h3 style="margin-top:16px;">Sonstiges</h3>
        <div class="row">
          <div class="field"><label>Audio-Zone (AudioServer4Home-Topic)</label><input type="text" id="p-audio-zone" placeholder="audioserver4home/zones/5"></div>
          <div class="field"><label>Buzzer-Topic</label><input type="text" id="p-buzzer-topic" placeholder="miraipanel/buzzer/warning"></div>
        </div>
        <div class="row">
          <div class="field"><label>Audioserver-Host (IP)</label><input type="text" id="p-audioserver-host" placeholder="192.168.179.14"></div>
          <div class="field" style="max-width:160px;"><label>Audioserver-Zone (Nr.)</label><input type="number" id="p-audioserver-zone" min="0"></div>
          <div class="field" style="max-width:140px;justify-content:flex-end;">
            <label>&nbsp;</label>
            <button type="button" class="btn btn-secondary" onclick="scanAudioZones()">Zonen suchen</button>
          </div>
        </div>
        <div id="audio-zone-results" style="display:none;margin:-4px 0 10px;font-size:13px;"></div>
        <div style="font-size:12px;color:var(--txt2);margin:-6px 0 10px;">
          Nur nötig, wenn die Bridge selbst Titel/Cover/Lautstärke live an die
          obige Audio-Zone publizieren soll (echter Loxone Audioserver oder
          Sonn Core, dessen Zonen-Nummer identisch mit der oben) — leer
          lassen, wenn der Audioserver/Sonn Core das MQTT-Publizieren schon
          selbst übernimmt. "Zonen suchen" braucht nur den Host oben (fragt
          kurz beim Audioserver an, ca. 3 Sekunden).
        </div>
        <div class="row">
          <div class="field"><label>Schlaf-Steuerung (Schalter)</label><input type="text" list="switch-control-datalist" id="p-sleep-cmd"></div>
          <div class="field" style="max-width:160px;"><label>Sleep-Dauer (Minuten)</label><input type="number" id="p-sleep-minutes" value="5" min="1"></div>
        </div>
        <div class="row">
          <div class="field"><label>Meldungs-Kommando (virtueller Eingang)</label><input type="text" list="virtual-input-write-datalist" id="p-notify-cmd"></div>
        </div>
        <div style="font-size:12px;color:var(--txt2);margin-bottom:10px;">
          Payload als JSON: <code>{"level":"info|warning|error","text":"..."}</code> zeigt eine Meldung
          am Panel-Display; <code>{"clear":true}</code> nimmt sie zurück, bevor am Panel weggetippt
          wurde. Beim Wegtippen publiziert das Panel <code>1</code> auf demselben Topic mit Endung
          <code>/ack</code> zurück.
        </div>
        <div class="row">
          <div class="field"><label>Wetter MQTT-Präfix (Weather4Lox)</label><input type="text" id="p-weather-prefix" placeholder="w4lx"></div>
        </div>
        <div style="font-size:12px;color:var(--txt2);margin-bottom:10px;">
          Nur das Präfix eintragen, in Weather4Lox unter SERVER.TOPIC einstellbar (Default
          <code>w4lx</code>) — kein virtueller Eingang, da nicht an ein Loxone-Control gebunden.
          Die Wetter-Topics selbst sind fest benannt und werden am Panel automatisch daraus abgeleitet.
        </div>

        <div class="btn-group">
          <button class="btn btn-primary" onclick="savePanel()">Speichern</button>
          <button class="btn btn-secondary" onclick="applyLayout()">Layout senden</button>
          <button class="btn btn-secondary" onclick="applyTopics()">Topics senden</button>
          <button class="btn btn-danger btn-sm" onclick="deletePanel()">Löschen</button>
        </div>
      </div>
    </div>
  </div>

  <!-- PANEL (Geräte-Weboberfläche eingebettet) ── -->
  <!-- Die Positionierung der Widgets, Status/Netzwerk/Firmware/Log passiert
       direkt am Gerät (eigene Weboberfläche des Panels, Port 5000) — das
       Panel kennt z.B. seine LVGL-Widget-Größen/-Raster selbst am besten,
       und so bleibt diese Logik nur an einer Stelle gepflegt statt doppelt
       (hier + Firmware). Die Raum-/Control-Zuordnung (Loxone-spezifisch,
       das Panel kennt Loxone gar nicht) bleibt auf der Panels-Seite im
       Plugin. Die Navigation zwischen den Geräte-Tabs passiert über die
       "Gerät"-Gruppe in der Sidebar (nicht als zusätzliche Tab-Zeile hier
       im Inhalt — sonst zwei Navigationsebenen übereinander); alle 6
       Sidebar-Einträge zeigen dieselbe Seite und steuern per URL
       (?tab=...&embed=1) nur den passenden Tab im iframe an. -->
  <div class="page" id="page-editor">
    <h2>Panel</h2>

    <div class="field" style="max-width:260px; margin-bottom:20px;">
      <label>Panel auswählen</label>
      <select id="layout-panel-select" onchange="layoutPanelChange()">
        <option value="">— Panel wählen —</option>
      </select>
    </div>

    <div id="layout-no-ip" style="display:none;color:var(--txt2);font-size:13px;">
      Für dieses Panel ist keine IP-Adresse hinterlegt (Panels → Panel auswählen → IP-Adresse eintragen).
    </div>

    <div id="layout-embed" style="display:none;">
      <div class="btn-group" style="margin-top:0;margin-bottom:14px;">
        <a class="btn btn-secondary" id="layout-open-direct" href="#" target="_blank" rel="noopener">Direkt am Gerät öffnen ↗</a>
      </div>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:10px;">
        Bleibt die Vorschau leer — z.&nbsp;B. bei Fernzugriff auf LoxBerry über HTTPS (Browser blockieren dann das
        Einbetten der unverschlüsselten Geräte-Seite) — nutze den Link oben.
      </div>
      <iframe id="layout-iframe" src="about:blank"
        style="width:100%;flex:1;height:0;min-height:400px;border:1px solid var(--brd);border-radius:var(--radius);background:#fff;"></iframe>
    </div>
  </div>

  <!-- EINSTELLUNGEN ── -->
  <div class="page" id="page-settings">
    <h2>Einstellungen</h2>
    <div class="card">
      <div class="card-title">Loxone Miniserver</div>
      <div class="field">
        <label>Miniserver (aus LoxBerry-Konfiguration)</label>
        <select id="s-lox-msno"></select>
      </div>
      <div style="font-size:12px;color:var(--txt2);">
        Benutzer und Passwort werden aus der LoxBerry-Systemkonfiguration übernommen.
      </div>
    </div>
    <div class="card">
      <div class="card-title">MQTT Broker</div>
      <div class="status-bar" style="margin-bottom:0;">
        <div class="status-dot" id="mqtt-dot"></div>
        <span id="mqtt-status-text">Unbekannt</span>
      </div>
      <div style="font-size:12px;color:var(--txt2);margin-top:10px;">
        Broker-Adresse und Zugangsdaten kommen aus dem LoxBerry MQTT-Gateway-Plugin.
      </div>
    </div>
    <div class="card">
      <div class="card-title">Globale Werte (Zeit &amp; Außenklima)</div>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:14px;">
        Gilt für alle Panels (Miniserver-weit). Nur Status-Bausteine (InfoOnlyAnalog/-Digital/-Text) — tippen zum Filtern.
      </div>
      <div class="row">
        <div class="field"><label>Zeit</label><input type="text" list="status-read-datalist" data-field="time_uuid" class="global-field"></div>
        <div class="field"><label>Datum</label><input type="text" list="status-read-datalist" data-field="date_uuid" class="global-field"></div>
      </div>
      <div class="row">
        <div class="field"><label>Wochentag</label><input type="text" list="status-read-datalist" data-field="dow_uuid" class="global-field"></div>
        <div class="field"><label>Jahr</label><input type="text" list="status-read-datalist" data-field="year_uuid" class="global-field"></div>
      </div>
      <div class="row">
        <div class="field"><label>Außentemperatur</label><input type="text" list="status-read-datalist" data-field="outside_temp_uuid" class="global-field"></div>
        <div class="field"><label>Außenfeuchtigkeit</label><input type="text" list="status-read-datalist" data-field="outside_humidity_uuid" class="global-field"></div>
      </div>
      <div class="btn-group">
        <button class="btn btn-secondary btn-sm" onclick="loadAllControlsDatalist(true)">Loxone-Suche aktualisieren</button>
      </div>
    </div>
    <div class="btn-group">
      <button class="btn btn-primary" onclick="saveSettings()">Speichern</button>
      <button class="btn btn-secondary" onclick="testLoxone()">Loxone testen</button>
    </div>

    <div class="card">
      <div class="card-title">Konfiguration sichern &amp; wiederherstellen</div>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:14px;">
        Sichert alle Panels, Raum-/Control-Zuordnungen und Einstellungen als
        Datei — z.&nbsp;B. vor einer Neuinstallation des Plugins. Enthält
        keine Zugangsdaten (Miniserver/MQTT kommen ohnehin aus LoxBerry
        selbst, nicht aus dieser Datei).
      </div>
      <div class="btn-group">
        <button class="btn btn-secondary" onclick="exportConfig()">Konfiguration exportieren</button>
        <button class="btn btn-secondary" onclick="document.getElementById('import-file-input').click()">Konfiguration importieren…</button>
        <input type="file" id="import-file-input" accept="application/json,.json" style="display:none;" onchange="importConfig(this.files[0])">
      </div>
    </div>

    <!-- ── Experimentell -->
    <div class="card">
      <div class="card-title">Experimentell: Loxone-Radio für Sonn Core</div>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:14px;">
        Legt die 13 kuratierten Loxone-Radio-Sender als "Eigene Streams" bei
        Sonn Core an.
      </div>
      <div class="row">
        <div class="field"><label>Sonn-Core-Host (IP)</label><input type="text" id="s-loxone-radio-host" placeholder="192.168.179.14"></div>
        <div class="field" style="max-width:220px;justify-content:flex-end;">
          <label>&nbsp;</label>
          <button type="button" class="btn btn-secondary" onclick="setupLoxoneRadio()">Sender einrichten</button>
        </div>
      </div>
      <div id="loxone-radio-results" style="display:none;margin-top:8px;font-size:13px;"></div>
    </div>
  </div>

  <!-- BRIDGE-STATUS ── -->
  <div class="page" id="page-bridge">
    <h2>Bridge-Status</h2>
    <div class="status-bar">
      <div class="status-dot" id="bridge-dot"></div>
      <span id="bridge-status-text">Unbekannt</span>
    </div>
    <div class="card">
      <div class="card-title">Log</div>
      <pre id="bridge-log" style="font-size:12px;color:var(--txt2);max-height:400px;overflow-y:auto;white-space:pre-wrap;"></pre>
    </div>
    <div class="btn-group">
      <button class="btn btn-primary" onclick="bridgeRestart()">Bridge neu starten</button>
      <button class="btn btn-secondary" onclick="loadBridgeLog()">Log aktualisieren</button>
    </div>
  </div>

</main>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- Zwei getrennte Loxone-Control-Suchen, je nach Datenrichtung (siehe
     Structure-File-Analyse): Lesen aus Loxone nutzt Status-Bausteine
     (InfoOnlyAnalog/-Digital/-Text), Schreiben ans Loxone nutzt virtuelle
     Eingänge (immer Typ "Slider" — bestätigt an Taste 1-8 = S1-S8 usw.).
     Werden befuellt sobald loxStructure lädt. -->
<datalist id="status-read-datalist"></datalist>
<datalist id="virtual-input-write-datalist"></datalist>
<datalist id="switch-control-datalist"></datalist>
</div>

<script>
'use strict';
// Viewport sicherstellen (für Mobile — falls LoxBerrys lbheader es nicht setzt)
if (!document.querySelector('meta[name="viewport"]')) {
  const _vp = document.createElement('meta');
  _vp.name = 'viewport'; _vp.content = 'width=device-width, initial-scale=1';
  document.head.appendChild(_vp);
}

// ── Widget-Definitionen (spiegeln lvgl_ui.yaml) ──────────────
// Nur noch für die Funktionsblock→Widget-Zuordnung ("Erkannte Funktions-
// blöcke im Raum", WIDGET_OPTIONS unten) gebraucht — die Positionierung
// auf dem Display (Seite/Zeile/Spalte) passiert seit Kurzem direkt am
// Gerät (eigene Weboberfläche, Tab "Layout", siehe Panels → Layout).
const WIDGETS = [
  { id:'content_light1',      label:'Licht 1'      },
  { id:'content_light2',      label:'Licht 2'      },
  { id:'content_blinds1',     label:'Jalousie 1'   },
  { id:'content_blinds2',     label:'Jalousie 2'   },
  { id:'content_blinds3',     label:'Jalousie 3'   },
  { id:'content_blinds4',     label:'Jalousie 4'   },
  { id:'content_Audio_small', label:'Audio'        },
  { id:'content_switch1',     label:'Schalter 1'   },
  { id:'content_switch2',     label:'Schalter 2'   },
  { id:'content_switch3',     label:'Schalter 3'   },
  { id:'content_switch4',     label:'Schalter 4'   },
  { id:'content_switch5',     label:'Schalter 5'   },
  { id:'content_switch6',     label:'Schalter 6'   },
  { id:'content_switch7',     label:'Schalter 7'   },
  { id:'content_switch8',     label:'Schalter 8'   },
  { id:'content_switch9',     label:'Schalter 9'   },
  { id:'content_heating1',    label:'Heizung 1'    },
  { id:'content_heating2',    label:'Heizung 2'    },
  { id:'content_stat_2',      label:'Sensoren 1'   },
  { id:'content_stat_3',      label:'Sensoren 2'   },
  { id:'content_weather',     label:'Wetter'       },
];

// Laufender State
let cfg = { loxone:{}, mqtt:{}, panels:[] };
let loxStructure = null;
let editingPanelIdx = -1;

// ── Hausweite Loxone-Control-Suche, getrennt nach Datenrichtung ──────
// Lesen aus Loxone (Panel zeigt Wert an) → Status-Baustein:
//   InfoOnlyAnalog / InfoOnlyDigital / InfoOnlyText
// Schreiben ans Loxone (Panel sendet Wert/Tastendruck) → virtueller
//   Eingang, im Structure File immer als Typ "Slider" geführt (verifiziert
//   an Taste 1-8 = "S1".."S8", Audio Play/Pause/Volume = "MV"/"Play"/... usw.)
// "TextState" ergänzt: Uhrzeit/Datum/Wochentag laufen in echten Loxone-
// Strukturen als TextState, nicht InfoOnlyText (an Structure_file.txt verifiziert).
const STATUS_READ_TYPES  = new Set(['InfoOnlyAnalog', 'InfoOnlyDigital', 'InfoOnlyText', 'TextState']);
const VIRTUAL_INPUT_TYPES = new Set(['Slider']);

async function loadAllControlsDatalist(forceRefresh) {
  if (forceRefresh) loxStructure = null;
  if (!loxStructure) {
    try { loxStructure = await api('lox_structure'); }
    catch(e) { toast('Loxone-Struktur nicht geladen: ' + e.message, 'err'); return; }
  }
  const readList   = document.getElementById('status-read-datalist');
  const switchList = document.getElementById('switch-control-datalist');
  const rooms = loxStructure.rooms || {};
  readList.innerHTML   = '';
  switchList.innerHTML = '';
  Object.entries(loxStructure.controls || {}).forEach(([uuid, c]) => {
    const roomName = (rooms[c.room] || {}).name || '';
    const label = `${c.name}${roomName ? ' (' + roomName + ')' : ''} · ${c.type}`;
    if (STATUS_READ_TYPES.has(c.type))
      readList.innerHTML += `<option value="${uuid}">${label}</option>`;
    if (c.type === 'Switch' || c.type === 'TimedSwitch')
      switchList.innerHTML += `<option value="${uuid}">${label}</option>`;
  });
  updateVirtualInputDatalist(null); // zunächst hausweit, wird bei Raumwahl eingeschränkt
}

// Schreib-Ziele (Hardware-Tasten/Sensor-Ziele/Audio-Kommandos) liegen laut
// Structure-File-Analyse typischerweise im selben Raum wie das Panel selbst
// (z.B. alle "S1".."S8"-Tasten im Esszimmer). Sobald ein Raum gewählt ist,
// filtern wir die Suche darauf ein — bei 30+ Slidern im ganzen Haus sonst
// unübersichtlich. roomUuid=null zeigt wieder alle Räume.
function updateVirtualInputDatalist(roomUuid) {
  if (!loxStructure) return;
  const rooms = loxStructure.rooms || {};
  const writeList = document.getElementById('virtual-input-write-datalist');
  writeList.innerHTML = '';
  Object.entries(loxStructure.controls || {}).forEach(([uuid, c]) => {
    if (!VIRTUAL_INPUT_TYPES.has(c.type)) return;
    if (roomUuid && c.room !== roomUuid) return;
    const roomName = (rooms[c.room] || {}).name || '';
    writeList.innerHTML += `<option value="${uuid}">${c.name}${roomName ? ' (' + roomName + ')' : ''} · ${c.type}</option>`;
  });
}

const HW_KEY_COUNT = 8;
const SENSOR_TARGET_FIELDS = [
  { key:'room_temp',     label:'Raumtemperatur' },
  { key:'room_humidity', label:'Raumfeuchte' },
  { key:'co2',           label:'CO₂' },
  { key:'voc',           label:'VOC' },
  { key:'pir',           label:'PIR / Bewegung' },
  { key:'mic',           label:'Mikrofon / Geräusch' },
  { key:'brightness',    label:'Display-Helligkeit' },
  { key:'lux',           label:'Lichtsensor' },
  { key:'power',         label:'Leistung (W)' },
];

function renderHwKeysAndSensors(panel) {
  const hw = panel.hw_keys || [];
  const hwList = document.getElementById('hw-keys-list');
  hwList.innerHTML = '';
  for (let i = 0; i < HW_KEY_COUNT; i++) {
    hwList.innerHTML += `
      <div class="ctrl-item">
        <div style="font-weight:600;font-size:13px;">Taste ${i+1}</div>
        <div class="ctrl-widget">
          <input type="text" list="virtual-input-write-datalist" data-hwkey="${i}" value="${hw[i] || ''}" style="width:280px;">
        </div>
      </div>`;
  }
  const sensors = panel.sensor_targets || {};
  const sensorList = document.getElementById('sensor-targets-list');
  sensorList.innerHTML = '';
  SENSOR_TARGET_FIELDS.forEach(f => {
    sensorList.innerHTML += `
      <div class="ctrl-item">
        <div style="font-weight:600;font-size:13px;">${f.label}</div>
        <div class="ctrl-widget">
          <input type="text" list="virtual-input-write-datalist" data-sensor="${f.key}" value="${sensors[f.key] || ''}" style="width:280px;">
        </div>
      </div>`;
  });
}

// ── Navigation ───────────────────────────────────────────────
let _bridgeLogTimer = null;

function showPage(id, el) {
  if (_bridgeLogTimer) { clearInterval(_bridgeLogTimer); _bridgeLogTimer = null; }
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + id).classList.add('active');
  el.classList.add('active');
  if (id === 'editor')  refreshLayoutPanelSelect();
  if (id === 'bridge')  { loadBridgeLog(); _bridgeLogTimer = setInterval(loadBridgeLog, 5000); }
  if (id === 'settings') loadSettings();
  if (id === 'panels')  refreshPanelStatuses();
}

// ── API-Helfer ───────────────────────────────────────────────
// cache: 'no-store' ist nötig, weil dies eine Single-Page-App ist — ein
// "Seitenwechsel" ist nur JS (showPage), kein echter Reload. Ohne das kann
// der Browser die GET-Antwort von z.B. api.php?action=config cachen, sodass
// nach dem Speichern beim Zurückwechseln kurzzeitig wieder der alte Stand
// erscheint (gerade gespeicherte Werte scheinen "verschwunden").
async function api(path, method='GET', body=null) {
  const opts = { method, headers:{'Content-Type':'application/json'}, cache:'no-store' };
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch('api.php?action=' + path, opts);
  if (!r.ok) {
    const txt = await r.text();
    let msg = txt;
    try { msg = JSON.parse(txt).error || txt; } catch(_) {}
    throw new Error(msg || `HTTP ${r.status}`);
  }
  return r.json();
}

function toast(msg, type='ok') {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'toast show ' + type;
  setTimeout(() => el.className = 'toast', 3000);
}

// ── Einstellungen ─────────────────────────────────────────────
async function loadSettings() {
  try {
    cfg = await api('config');
    await loadMiniserverSelect(cfg.loxone?.msno);
    await loadMqttInfo();
    await loadAllControlsDatalist();
    const g = cfg.global || {};
    document.querySelectorAll('.global-field').forEach(el => {
      el.value = g[el.dataset.field] || '';
    });
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

async function loadMqttInfo() {
  const dot  = document.getElementById('mqtt-dot');
  const text = document.getElementById('mqtt-status-text');
  try {
    const r = await api('mqtt_info');
    dot.className = 'status-dot ok';
    text.textContent = 'Broker: ' + r.broker;
  } catch(e) {
    dot.className = 'status-dot err';
    text.textContent = 'MQTT Gateway nicht konfiguriert oder nicht erreichbar';
  }
}

async function loadMiniserverSelect(selectedMsno) {
  const sel = document.getElementById('s-lox-msno');
  sel.innerHTML = '';
  try {
    const list = await api('miniservers');
    if (!list.length) {
      sel.innerHTML = '<option value="">— kein Miniserver in LoxBerry konfiguriert —</option>';
      return;
    }
    list.forEach(m => {
      sel.innerHTML += `<option value="${m.msno}">${m.name} (${m.ipaddress})</option>`;
    });
    if (selectedMsno) sel.value = selectedMsno;
  } catch(e) {
    sel.innerHTML = '<option value="">— Fehler beim Laden —</option>';
  }
}

async function saveSettings() {
  cfg.loxone = {
    msno: parseInt(document.getElementById('s-lox-msno').value) || null,
  };
  cfg.global = cfg.global || {};
  document.querySelectorAll('.global-field').forEach(el => {
    cfg.global[el.dataset.field] = el.value.trim();
  });
  try {
    await api('config', 'POST', cfg);
    loxStructure = null; // Cache invalidieren
    toast('Einstellungen gespeichert — Bridge wird neu gestartet…');
    await api('bridge_restart', 'POST');
    setTimeout(() => toast('Bridge läuft wieder'), 2500);
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

async function testLoxone() {
  try {
    const r = await api('lox_test');
    toast(r.ok ? 'Loxone erreichbar ✓' : 'Fehler: ' + r.error, r.ok ? 'ok' : 'err');
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

// ── Audio-Zonen suchen ───────────────────────────────────────────
// Fragt die Bridge (bin/bridge.js, lokaler Scan-Server) statt selbst per
// WebSocket beim Audioserver nachzufragen — erspart eine zweite WS-
// Implementierung nur fürs Web-UI. ~3s Wartezeit, siehe api.php/bridge.js.
async function scanAudioZones() {
  const host = document.getElementById('p-audioserver-host').value.trim();
  const box  = document.getElementById('audio-zone-results');
  if (!host) { toast('Erst Audioserver-Host eintragen', 'err'); return; }
  box.style.display = 'block';
  box.innerHTML = 'Suche Zonen …';
  try {
    const zones = await api('scan_audio_zones', 'POST', { host });
    if (!zones.length) {
      box.innerHTML = 'Keine Zonen gefunden — IP prüfen, Audioserver erreichbar?';
      return;
    }
    box.innerHTML = '';
    zones.forEach(z => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn btn-secondary btn-sm';
      btn.textContent = `${z.playerid} — ${z.name || '(ohne Namen)'}`;
      btn.style.marginRight = '6px';
      btn.style.marginBottom = '6px';
      btn.onclick = () => {
        document.getElementById('p-audioserver-zone').value = z.playerid;
        toast(`Zone ${z.playerid} (${z.name}) übernommen`);
      };
      box.appendChild(btn);
    });
  } catch(e) {
    box.innerHTML = 'Fehler: ' + e.message;
  }
}

// ── Loxone-Radio-Sender (Streampal) in Sonn Core einrichten ──────
// Bewusst mit Bestätigungsdialog (nicht "ein Klick") — nutzt einen von
// Loxone/Streampal nicht offiziell freigegebenen Zugang, siehe Hinweistext
// in den Einstellungen. Gleiches Muster wie scanAudioZones() — fragt
// bridge.js' lokalen Server ab. Idempotent (siehe bin/loxone_radio_setup.js),
// darf beliebig oft geklickt werden, z.B. nach einer Sonn-Core-Neuinstallation.
async function setupLoxoneRadio() {
  const host = document.getElementById('s-loxone-radio-host').value.trim();
  const box  = document.getElementById('loxone-radio-results');
  if (!host) { toast('Erst Sonn-Core-Host eintragen', 'err'); return; }
  const ok = confirm(
    'Dieser Zugang ist von Loxone/Streampal nicht offiziell freigegeben und ' +
    'kann jederzeit aufhören zu funktionieren. Nur zum eigenen Testen, nicht ' +
    'automatisiert/häufig ausführen.\n\nJetzt trotzdem einrichten?'
  );
  if (!ok) return;
  box.style.display = 'block';
  box.innerHTML = 'Richte Sender ein … (kann einige Sekunden dauern)';
  try {
    const r = await api('setup_loxone_radio', 'POST', { host });
    let msg = `${r.added.length} neu angelegt, ${r.skipped.length} bereits vorhanden`;
    if (r.errors && r.errors.length) msg += `, ${r.errors.length} Fehler`;
    box.innerHTML = msg;
    toast(msg, r.errors && r.errors.length ? 'err' : 'ok');
  } catch(e) {
    box.innerHTML = 'Fehler: ' + e.message;
    toast('Fehler: ' + e.message, 'err');
  }
}

// ── Konfiguration sichern & wiederherstellen ────────────────────
// Reine Frontend-Funktionen: exportConfig() nutzt den bereits geladenen
// "cfg"-State, importConfig() schickt die Datei an denselben "config"-
// Endpunkt, der auch beim normalen Speichern verwendet wird (siehe
// saveSettings() oben) — kein eigener Backend-Code nötig, die Datei
// entspricht 1:1 dem Inhalt von config/bridge.json.
function exportConfig() {
  const blob  = new Blob([JSON.stringify(cfg, null, 2)], { type: 'application/json' });
  const url   = URL.createObjectURL(blob);
  const stamp = new Date().toISOString().slice(0, 10);
  const a = document.createElement('a');
  a.href = url;
  a.download = `miraibridge-config_${stamp}.json`;
  a.click();
  URL.revokeObjectURL(url);
  toast('Konfiguration exportiert');
}

async function importConfig(file) {
  const fileInput = document.getElementById('import-file-input');
  if (!file) return;
  let imported;
  try {
    imported = JSON.parse(await file.text());
  } catch(e) {
    toast('Datei ist kein gültiges JSON', 'err');
    fileInput.value = '';
    return;
  }
  if (!Array.isArray(imported.panels) || typeof imported.loxone !== 'object') {
    toast('Datei sieht nicht wie eine MiraiBridge-Konfiguration aus', 'err');
    fileInput.value = '';
    return;
  }
  if (!confirm(`Aktuelle Konfiguration durch die importierte Datei ersetzen (${imported.panels.length} Panel(s))? Das kann nicht rückgängig gemacht werden.`)) {
    fileInput.value = '';
    return;
  }
  try {
    await api('config', 'POST', imported);
    cfg = imported;
    loxStructure = null; // Cache invalidieren
    renderPanelGrid();
    refreshPanelStatuses();
    toast('Konfiguration importiert — Bridge wird neu gestartet…');
    await api('bridge_restart', 'POST');
    setTimeout(() => toast('Bridge läuft wieder'), 2500);
  } catch(e) {
    toast('Import fehlgeschlagen: ' + e.message, 'err');
  } finally {
    fileInput.value = '';
  }
}

// ── Panel-Liste ───────────────────────────────────────────────
async function loadPanels() {
  try {
    cfg = await api('config');
    renderPanelGrid();
    refreshPanelStatuses(); // im Hintergrund, blockiert das Rendern der Liste nicht (mosquitto_sub braucht bis zu 5s)
  } catch(e) { toast('Konfiguration konnte nicht geladen werden', 'err'); }
}

function renderPanelGrid() {
  const grid = document.getElementById('panel-grid');
  grid.innerHTML = '';
  (cfg.panels || []).forEach((p, i) => {
    const ctrlCount = Object.keys(p.controls || {}).length;
    const room = p.room_name || p.room_uuid || '—';
    grid.innerHTML += `
      <div class="panel-card" onclick="selectPanel(${i})">
        <div class="panel-name">${p.name}</div>
        <div class="panel-room">${room}</div>
        <div class="panel-badges">
          <span class="badge" data-status-for="${p.name}">prüfe…</span>
          <span class="badge">${p.ip || 'keine IP'}</span>
          <span class="badge">${ctrlCount} Controls</span>
        </div>
      </div>`;
  });
}

// Fragt den Online-Status der bereits konfigurierten Panels ab (via
// scan_mqtt_devices() / "mirai/<name>/ip" + ESPHomes "<name>/status", siehe
// api.php) und aktualisiert nur die Status-Badges, statt die ganze Liste neu
// zu rendern (damit z.B. die "selected"-Markierung erhalten bleibt).
async function refreshPanelStatuses() {
  let devices;
  try {
    devices = await api('scan_panels');
  } catch(e) { return; } // Status ist "nice to have" -- kein Toast bei Fehlschlag
  const byName = {};
  devices.forEach(d => { byName[d.name] = d.online; });
  document.querySelectorAll('[data-status-for]').forEach(el => {
    const name = el.dataset.statusFor;
    if (!(name in byName)) {
      el.textContent = 'unbekannt';
      el.className = 'badge';
      return;
    }
    el.textContent = byName[name] ? 'online' : 'offline';
    el.className = 'badge ' + (byName[name] ? 'online' : 'warn');
  });
}

async function selectPanel(idx) {
  editingPanelIdx = idx;
  document.querySelectorAll('.panel-card').forEach((c,i) =>
    c.classList.toggle('selected', i === idx));
  document.getElementById('panel-detail').style.display = 'block';
  const p = cfg.panels[idx];
  document.getElementById('detail-title').textContent = 'Panel: ' + p.name;
  document.getElementById('p-name').value = p.name || '';
  document.getElementById('p-ip').value   = p.ip   || '';
  document.getElementById('p-audio-zone').value    = p.audio_zone_topic || '';
  document.getElementById('p-buzzer-topic').value  = p.buzzer_topic || '';
  document.getElementById('p-audioserver-host').value = p.audioserver_host || '';
  document.getElementById('p-audioserver-zone').value = p.audioserver_zone ?? '';
  document.getElementById('p-sleep-cmd').value     = p.sleep_cmd_uuid || '';
  document.getElementById('p-sleep-minutes').value = p.sleep_minutes || 5;
  document.getElementById('p-notify-cmd').value    = p.notify_cmd_uuid || '';
  document.getElementById('p-weather-prefix').value = p.weather_topic_prefix || '';
  renderHwKeysAndSensors(p);
  await loadRooms(p.room_uuid);
  renderControls(p);
}

function newPanel() {
  editingPanelIdx = -1;
  document.querySelectorAll('.panel-card').forEach(c => c.classList.remove('selected'));
  document.getElementById('panel-detail').style.display = 'block';
  document.getElementById('detail-title').textContent = 'Neues Panel';
  document.getElementById('p-name').value = '';
  document.getElementById('p-ip').value   = '';
  document.getElementById('p-audio-zone').value    = '';
  document.getElementById('p-buzzer-topic').value  = 'miraipanel/buzzer/warning';
  document.getElementById('p-audioserver-host').value = '';
  document.getElementById('p-audioserver-zone').value = '';
  document.getElementById('p-sleep-cmd').value     = '';
  document.getElementById('p-sleep-minutes').value = 5;
  document.getElementById('p-notify-cmd').value    = '';
  document.getElementById('p-weather-prefix').value = 'w4lx';
  renderHwKeysAndSensors({});
  document.getElementById('ctrl-section').style.display = 'none';
  document.getElementById('scan-results').style.display = 'none';
  loadRooms(null);
}

// ── Netzwerk-Scan (via eigenem "mirai/<name>/ip"-Topic) ─────────
// Findet ausschließlich MiraiPanel-Geräte (nicht irgendwelche ESPHome-
// Geräte im Netz) — die Firmware veröffentlicht ihre aktuelle IP selbst
// retained unter "mirai/<name>/ip", der Online-Status kommt ergänzend aus
// ESPHomes "<name>/status".
async function scanPanels() {
  const btn  = document.getElementById('scan-btn');
  const wrap = document.getElementById('scan-results');
  const list = document.getElementById('scan-list');
  btn.disabled = true;
  btn.textContent = 'Scanne…';
  try {
    const devices = await api('scan_panels');
    wrap.style.display = 'block';
    list.innerHTML = '';
    if (devices._error) {
      list.innerHTML = `<div style="color:var(--err,#e53935);font-size:13px;">⚠ ${devices._error}<br><small>Auf LoxBerry ausführen: <code>sudo apt-get install -y mosquitto-clients</code></small></div>`;
      return;
    }
    if (!devices.length) {
      list.innerHTML = '<div style="color:var(--txt2);font-size:13px;">Keine MiraiPanel-Geräte gefunden — ist das Topic <code>mirai/+/ip</code> im MQTT Explorer sichtbar?</div>';
      return;
    }
    devices.forEach(d => {
      list.innerHTML += `
        <div class="ctrl-item" style="cursor:pointer;" onclick="pickScannedDevice('${d.name}','${d.ip}')">
          <div>
            <div style="font-weight:600;font-size:13px;">${d.friendly_name} <span class="badge ${d.online ? 'online' : 'warn'}">${d.online ? 'online' : 'offline'}</span></div>
            <div class="ctrl-type">${d.name}${d.ip ? ' · ' + d.ip : ' · IP unbekannt, bitte manuell eintragen'}</div>
          </div>
        </div>`;
    });
  } catch(e) {
    toast('Scan fehlgeschlagen: ' + e.message, 'err');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Netzwerk scannen';
  }
}

function pickScannedDevice(name, ip) {
  document.getElementById('p-name').value = name;
  document.getElementById('p-ip').value   = ip;
  document.getElementById('scan-results').style.display = 'none';
}

// ── Loxone Structure / Räume ──────────────────────────────────
async function loadRooms(selectedUuid) {
  const list = document.getElementById('room-list');
  const loading = document.getElementById('room-loading');
  loading.style.display = 'block';
  list.innerHTML = '';
  try {
    if (!loxStructure) {
      loxStructure = await api('lox_structure');
    }
    loading.style.display = 'none';
    loadAllControlsDatalist(); // nutzt bereits geladenes loxStructure (kein Refetch)
    // Die UUID ist in LoxAPP3.json immer der Dictionary-Key, nicht zwingend
    // ein internes Feld — daher Object.entries statt Object.values.
    const rooms = Object.entries(loxStructure.rooms || {})
      .map(([uuid, room]) => ({ ...room, uuid }))
      .sort((a,b) => a.name.localeCompare(b.name));
    rooms.forEach(room => {
      const div = document.createElement('div');
      div.className = 'room-item' + (room.uuid === selectedUuid ? ' selected' : '');
      div.textContent = room.name;
      div.dataset.uuid = room.uuid;
      div.onclick = () => {
        document.querySelectorAll('.room-item').forEach(r => r.classList.remove('selected'));
        div.classList.add('selected');
        showControlsForRoom(room.uuid);
      };
      list.appendChild(div);
    });
    if (selectedUuid) showControlsForRoom(selectedUuid);
  } catch(e) {
    loading.textContent = 'Fehler beim Laden der Loxone-Struktur. Bitte Einstellungen prüfen.';
    loading.style.color = 'var(--danger)';
  }
}

const CTRL_TYPES = {
  'IRoomControllerV2':  { label:'Heizung',   widget:'content_heating1'     },
  'Jalousie':           { label:'Jalousie',   widget:'content_blinds1'      },
  'LightControllerV2':  { label:'Licht',      widget:'content_light1'       },
  'AudioZoneV2':        { label:'Audio',      widget:'content_Audio_small'  },
  'Switch':             { label:'Schalter',   widget:'content_switch1'      },
  'TimedSwitch':        { label:'Schalter',   widget:'content_switch1'      },
};

const WIDGET_OPTIONS = WIDGETS.map(w =>
  `<option value="${w.id}">${w.label} (${w.id})</option>`
).join('') + '<option value="">— nicht anzeigen —</option>';

function showControlsForRoom(roomUuid) {
  if (!loxStructure) return;
  const section = document.getElementById('ctrl-section');
  const list    = document.getElementById('ctrl-list');
  section.style.display = 'block';
  list.innerHTML = '';
  updateVirtualInputDatalist(roomUuid); // Hardware-Tasten/Sensor-Ziele/Audio-Suche auf diesen Raum einschränken

  // Alle Controls dieses Raumes. Auch hier: die UUID ist in LoxAPP3.json
  // nur der Dictionary-Key (kein "uuid"-Feld im Control selbst, siehe
  // Loxone Structure-File-Doku — das interne Feld heißt "uuidAction").
  const existing = editingPanelIdx >= 0
    ? (cfg.panels[editingPanelIdx].controls || {}) : {};

  const controls = Object.entries(loxStructure.controls || {})
    .map(([uuid, ctrl]) => ({ ...ctrl, uuid }))
    .filter(c => c.room === roomUuid && CTRL_TYPES[c.type]);

  if (!controls.length) {
    list.innerHTML = '<div style="color:var(--txt2);font-size:13px;">Keine unterstützten Funktionsblöcke in diesem Raum</div>';
    return;
  }

  // Schalter aufzählen für switch1/2/3, Jalousien für blinds1-4, Heizung
  // für heating1/2, Licht für light1/2
  let switchCount = 0;
  let blindsCount = 0;
  let heatingCount = 0;
  let lightCount = 0;

  controls.forEach(ctrl => {
    const typeDef = CTRL_TYPES[ctrl.type];
    let defaultWidget = typeDef.widget;
    if (ctrl.type === 'Switch' || ctrl.type === 'TimedSwitch') {
      const swNames = ['content_switch1','content_switch2','content_switch3',
                        'content_switch4','content_switch5','content_switch6',
                        'content_switch7','content_switch8','content_switch9'];
      defaultWidget = swNames[switchCount] || '';
      switchCount++;
    }
    if (ctrl.type === 'Jalousie') {
      const blindsNames = ['content_blinds1','content_blinds2','content_blinds3','content_blinds4'];
      defaultWidget = blindsNames[blindsCount] || '';
      blindsCount++;
    }
    if (ctrl.type === 'IRoomControllerV2') {
      const heatingNames = ['content_heating1','content_heating2'];
      defaultWidget = heatingNames[heatingCount] || '';
      heatingCount++;
    }
    if (ctrl.type === 'LightControllerV2') {
      const lightNames = ['content_light1','content_light2'];
      defaultWidget = lightNames[lightCount] || '';
      lightCount++;
    }
    let currentWidget = (existing[ctrl.uuid] || {}).widget || defaultWidget;
    if (currentWidget === 'content_blinds') currentWidget = 'content_blinds1'; // legacy migration
    if (currentWidget === 'content_heating') currentWidget = 'content_heating1'; // legacy migration
    if (currentWidget === 'content_light') currentWidget = 'content_light1'; // legacy migration
    list.innerHTML += `
      <div class="ctrl-item" data-uuid="${ctrl.uuid}" data-type="${ctrl.type}">
        <div>
          <div style="font-weight:600;font-size:13px;">${ctrl.name}</div>
          <div class="ctrl-type">${ctrl.type} · ${ctrl.uuid.substring(0,8)}…</div>
        </div>
        <div class="ctrl-widget">
          <select data-uuid="${ctrl.uuid}" data-type="${ctrl.type}">
            ${WIDGET_OPTIONS.replace(`value="${currentWidget}"`, `value="${currentWidget}" selected`)}
          </select>
        </div>
      </div>`;
  });
}

function renderControls(panel) {
  if (!loxStructure || !panel.room_uuid) return;
  showControlsForRoom(panel.room_uuid);
}

// ── Panel speichern ───────────────────────────────────────────
async function savePanel() {
  const name = document.getElementById('p-name').value.trim();
  const ip   = document.getElementById('p-ip').value.trim();
  if (!name) { toast('Bitte Panel-Namen eingeben', 'err'); return; }

  const roomEl = document.querySelector('.room-item.selected');
  const roomUuid = roomEl?.dataset.uuid || '';
  const roomName = roomEl?.textContent  || '';

  // Controls aus den Selects
  const controls = {};
  document.querySelectorAll('#ctrl-list select').forEach(sel => {
    if (!sel.value) return;
    controls[sel.dataset.uuid] = { type: sel.dataset.type, widget: sel.value };
  });

  // Hardware-Tasten (1–8) und Sensor-Ziele aus den Suchfeldern
  const hw_keys = [];
  for (let i = 0; i < HW_KEY_COUNT; i++) {
    const el = document.querySelector(`[data-hwkey="${i}"]`);
    hw_keys.push(el ? el.value.trim() : '');
  }
  const sensor_targets = {};
  document.querySelectorAll('[data-sensor]').forEach(el => {
    sensor_targets[el.dataset.sensor] = el.value.trim();
  });
  // audioserver_zone bewusst null statt '' bei leerem Feld (nicht 0 per
  // parseInt('')||0 — Zone 0 wäre sonst nicht von "nicht konfiguriert" zu
  // unterscheiden) — setupAudioZones() in bridge.js überspringt Panels ohne
  // audioserver_host/audioserver_zone komplett.
  const audioserverZoneRaw = document.getElementById('p-audioserver-zone').value.trim();
  const panel = {
    name, ip, room_uuid: roomUuid, room_name: roomName, controls,
    hw_keys, sensor_targets,
    audio_zone_topic: document.getElementById('p-audio-zone').value.trim(),
    buzzer_topic:     document.getElementById('p-buzzer-topic').value.trim(),
    audioserver_host: document.getElementById('p-audioserver-host').value.trim(),
    audioserver_zone: audioserverZoneRaw === '' ? null : parseInt(audioserverZoneRaw, 10),
    sleep_cmd_uuid:   document.getElementById('p-sleep-cmd').value.trim(),
    sleep_minutes:    parseInt(document.getElementById('p-sleep-minutes').value) || 5,
    notify_cmd_uuid:  document.getElementById('p-notify-cmd').value.trim(),
    weather_topic_prefix: document.getElementById('p-weather-prefix').value.trim(),
  };

  if (editingPanelIdx >= 0) {
    const existing = cfg.panels[editingPanelIdx];
    if (existing?.layout) panel.layout = existing.layout;
    cfg.panels[editingPanelIdx] = panel;
  } else {
    cfg.panels.push(panel);
    editingPanelIdx = cfg.panels.length - 1;
  }

  try {
    await api('config', 'POST', cfg);
    renderPanelGrid();
    refreshPanelStatuses();
    toast('Panel gespeichert — Bridge wird neu gestartet…');
    // Bridge neu starten damit sie die neuen Control-UUIDs einliest
    await api('bridge_restart', 'POST');
    setTimeout(() => toast('Bridge läuft wieder'), 2500);
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

async function deletePanel() {
  if (editingPanelIdx < 0) return;
  if (!confirm('Panel wirklich löschen?')) return;
  cfg.panels.splice(editingPanelIdx, 1);
  editingPanelIdx = -1;
  document.getElementById('panel-detail').style.display = 'none';
  try {
    await api('config', 'POST', cfg);
    renderPanelGrid();
    refreshPanelStatuses();
    toast('Panel gelöscht — Bridge wird neu gestartet…');
    await api('bridge_restart', 'POST');
    setTimeout(() => toast('Bridge läuft wieder'), 2500);
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

async function applyLayout() {
  if (editingPanelIdx < 0) return;
  const panel = cfg.panels[editingPanelIdx];
  try {
    await api('send_layout', 'POST', { panel_name: panel.name });
    toast('Layout an Panel gesendet');
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

// Sendet die komplette Topic-Konfiguration (Heizung/Beschattung/Licht aus
// den zugeordneten Controls automatisch abgeleitet, Rest aus den Feldern
// dieses Panels) — löst auf dem Panel einen Neustart aus, damit die neuen
// MQTT-Subscriptions greifen (siehe mqtt_router.yaml "topics/set").
async function applyTopics() {
  if (editingPanelIdx < 0) { toast('Bitte zuerst ein Panel öffnen', 'err'); return; }
  // Immer zuerst speichern — api.php liest aus bridge.json, nicht aus dem JS-Speicher
  await savePanel();
  const panel = cfg.panels[editingPanelIdx];
  try {
    const res = await api('send_topics', 'POST', { panel_name: panel.name });
    console.log('send_topics debug_controls:', res.debug_controls);
    console.log('send_topics debug_jalousie:', res.debug_jalousie);
    toast('Topics gesendet — Panel startet neu');
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

// ── Panel (Geräte-Weboberfläche eingebettet) ──────────────────
// Alle Geräte-Einstellungen (Konfiguration/Layout/Status/Netzwerk/Firmware/
// Log) leben direkt am Panel (eigener Webserver auf Port 5000) — kein
// doppelter Editor mehr hier. Die 6 Geräte-Tabs sind eigene Einträge in der
// Sidebar-Gruppe "Gerät" (nicht als zusätzliche Tab-Zeile im Inhalt — sonst
// zwei Navigationsebenen übereinander); sie zeigen alle dieselbe Seite
// (page-editor) und steuern per URL-Parameter (?tab=...&embed=1, siehe
// web_ui/index.html im Firmware-Repo) nur den passenden Tab im iframe an —
// dessen eigene Kopfzeile/Tab-Leiste blendet der Embed-Modus dort aus.
let layoutDeviceTab = 'layout';

function toggleDeviceNav() {
  const items = document.getElementById('device-nav-items');
  const label = document.querySelector('.nav-section-toggle');
  const open  = items.style.display !== 'none';
  items.style.display = open ? 'none' : 'block';
  label.classList.toggle('open', !open);
}

function showDeviceTab(tabName, el) {
  layoutDeviceTab = tabName;
  // Gruppe automatisch aufklappen, damit die Aktiv-Markierung sichtbar ist
  // (z.B. wenn dieser Klick von einem Reload/anderswo her ausgelöst wurde).
  document.getElementById('device-nav-items').style.display = 'block';
  document.querySelector('.nav-section-toggle').classList.add('open');
  showPage('editor', el);
}

function refreshLayoutPanelSelect() {
  const sel = document.getElementById('layout-panel-select');
  const cur = sel.value;
  sel.innerHTML = '<option value="">— Panel wählen —</option>';
  (cfg.panels || []).forEach((p, i) => {
    sel.innerHTML += `<option value="${i}">${p.name}</option>`;
  });
  if (cur) sel.value = cur;
  layoutPanelChange();
}

function layoutPanelChange() {
  const sel   = document.getElementById('layout-panel-select');
  const embed = document.getElementById('layout-embed');
  const noIp  = document.getElementById('layout-no-ip');
  const idx   = parseInt(sel.value);
  const panel = !isNaN(idx) ? cfg.panels[idx] : null;

  if (!panel) { embed.style.display = 'none'; noIp.style.display = 'none'; return; }
  if (!panel.ip) { embed.style.display = 'none'; noIp.style.display = 'block'; return; }

  noIp.style.display  = 'none';
  embed.style.display = 'flex';
  layoutUpdateIframe();
}

// LoxBerry 4.x setzt "theme-glass" auf <body> für das Glassmorphism-Dark-Theme
// (das einzige dunkle Theme). Alle anderen Themes sind hell.
function getLBTheme() {
  return document.body.classList.contains('theme-glass') ? 'dark' : 'light';
}

function layoutUpdateIframe() {
  const sel   = document.getElementById('layout-panel-select');
  const idx   = parseInt(sel.value);
  const panel = !isNaN(idx) ? cfg.panels[idx] : null;
  if (!panel || !panel.ip) return;
  const theme = getLBTheme();
  document.getElementById('layout-iframe').src = `http://${panel.ip}:5000/?tab=${layoutDeviceTab}&embed=1&theme=${theme}`;
  // "Direkt öffnen" bewusst OHNE embed=1/theme= — dort soll die volle
  // Geräte-Seite mit eigener Kopfzeile/Navigation und eigenem Theme-Stand
  // erscheinen, nur der Tab wird übernommen.
  document.getElementById('layout-open-direct').href = `http://${panel.ip}:5000/?tab=${layoutDeviceTab}`;
}

// Beobachtet Klassen-Änderungen auf body (z.B. wenn LoxBerry das Theme
// dynamisch ohne Seitenneuladen wechselt) und aktualisiert den iframe.
(function () {
  const obs = new MutationObserver(() => layoutUpdateIframe());
  obs.observe(document.body, { attributes: true, attributeFilter: ['class'] });
})();

// ── Bridge-Status ─────────────────────────────────────────────
async function loadBridgeLog() {
  try {
    const r = await api('bridge_status');
    const dot  = document.getElementById('bridge-dot');
    const text = document.getElementById('bridge-status-text');
    dot.className  = 'status-dot ' + (r.running ? 'ok' : 'err');
    text.textContent = r.running ? 'Bridge läuft (PID ' + r.pid + ')' : 'Bridge gestoppt';
    document.getElementById('bridge-log').textContent = r.log || '(kein Log)';
  } catch(e) {
    document.getElementById('bridge-status-text').textContent = 'Fehler beim Laden';
  }
}

async function bridgeRestart() {
  try {
    await api('bridge_restart', 'POST');
    toast('Bridge wird neu gestartet…');
    setTimeout(loadBridgeLog, 2000);
  } catch(e) { toast('Fehler: ' + e.message, 'err'); }
}

// ── Init ─────────────────────────────────────────────────────
// Shell-Höhe dynamisch anpassen: misst die tatsächliche Position des Shells
// im Viewport, damit der LoxBerry-Header/Footer/Padding nicht hardcodiert
// werden muss und kein äußerer Seiten-Scroll entsteht.
(function fitShell() {
  const shell = document.querySelector('.mp-app .shell');
  const mp    = document.querySelector('.mp-app');
  if (!mp || !shell) return;

  // LoxBerry-Content-Container: nur den direkten Parent anpassen.
  if (mp.parentElement && mp.parentElement !== document.body) {
    mp.parentElement.style.padding = '8px';
  }

  function resize() {
    // lb-main hat nur min-height:100vh, kein height:100vh → kann größer werden.
    // Deshalb nicht die aktuelle footer-Position messen (die ist bereits falsch
    // wenn Shell zu groß), sondern die GEWÜNSCHTE: Footer soll genau am
    // Viewport-Bottom enden → desiredFooterTop = innerHeight - footerH.
    const footer  = document.querySelector('footer.lb-footer');
    const footerH = footer ? footer.offsetHeight : 0;
    const shellTop = shell.getBoundingClientRect().top;
    const botPad   = parseFloat(getComputedStyle(mp.parentElement).paddingBottom) || 8;
    shell.style.height = Math.max(640, window.innerHeight - footerH - shellTop - botPad) + 'px';
  }
  // lbfooter() kommt im PHP nach dem script-Block → footer.lb-footer beim inline-
  // Ausführen noch nicht im DOM → footerH=0 → Shell ~135px zu groß → Page-Scroll.
  // DOMContentLoaded wartet bis der komplette HTML (inkl. Footer) geparsed ist.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', resize);
  } else {
    resize();
  }
  window.addEventListener('resize', resize);
})();

loadPanels();
</script>
<?php
LBWeb::lbfooter();
