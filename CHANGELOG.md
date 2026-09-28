# Changelog

Alle nennenswerten Änderungen an diesem Plugin werden hier festgehalten.

## [Unreleased] - 2026-09-28

### Neu (Bridge)
- **Echter Loxone-Audioserver V2 jetzt vollwertig unterstützt** (bisher nur
  Sonn Core): neuer authentifizierter Steuerkanal (`bin/audioserver_auth.js`,
  `bin/miniserver_jwt.js`) implementiert den `secure/authenticate`-Handshake
  gegen Port 7091 (`audio/cfg/getkey/full` + Miniserver-JWT via
  `jdev/sys/getjwt`, Permission 2). Auf Hardware verifiziert gegen einen
  echten Audioserver UND Sonn Core — beide unterstützen den Handshake
  identisch. Ohne passenden Miniserver oder falls die Box `secure/
  authenticate` nicht unterstützt, bleibt die bisherige unauthentifizierte
  Zustands-Anzeige unverändert nutzbar (reiner Fallback, kein Funktionsverlust).
- **Raumfavoriten**: werden alle 10 Min. (und auf Anfrage sofort) per
  `getroomfavs` abgefragt und retained auf `<Zone>/favorites` publiziert.
  Neue Kommando-Topics `<Zone>/set/favorite` (abspielen, generischer
  `roomfav/play`-Befehl, funktioniert für alle Favoriten-Typen) und
  `<Zone>/set/favorites_refresh` (sofortiges Update anfordern — für die
  Firmware beim Öffnen des Favoriten-Overlays).
- Neues optionales Panel-Feld `audioserver_msno` (`bridge.json`) für den
  Fall, dass die Audioserver-Box einen anderen Miniserver braucht als der
  Rest der Bridge (`loxone.msno`) — Standard-Fallback deckt den üblichen
  Ein-Miniserver-Fall ohne Config-Änderung ab.

### Fix (Bridge)
- Mehrere Zonen auf derselben Audioserver-Box teilten sich beim
  gleichzeitigen Abfragen der Favoriten eine Antwort (Cross-Talk: die erste
  ankommende Antwort löste alle wartenden Anfragen auf, unabhängig davon
  für welche Zone sie bestimmt war). Anfragen auf einer geteilten Verbindung
  laufen jetzt strikt serialisiert.
- Connect-Timeout für den neuen Steuerkanal ergänzt (hing zuvor unbegrenzt,
  wenn die Box nicht erreichbar war).

## [Unreleased] - 2026-09-22 (3)

### Sonstiges
- **Repo umbenannt**: `LoxBerry-Plugin-MiraiPanel` → `LoxBerry-Plugin-MiraiBridge`
  (passend zum tatsächlichen Plugin-Namen `TITLE=MiraiBridge`, klarer
  abgegrenzt von den MiraiPanel-Firmware-/Hardware-Repos). `plugin.cfg`/
  `release.cfg`/`prerelease.cfg` auf die neuen URLs umgestellt.

## [Unreleased] - 2026-09-22 (2)

### Fix (Bridge)
- **Log-Zeitstempel zeigten UTC statt Lokalzeit** — `toISOString()` gibt
  immer UTC zurück, unabhängig von der System-Zeitzone. Jetzt über die
  Date-Getter (`getHours()` etc.) formatiert, die die System-Zeitzone des
  LoxBerry verwenden (Europe/Berlin inkl. Sommerzeit).

## [Unreleased] - 2026-09-22

### Bugfix (Bridge)
- **`Cannot read properties of undefined (reading 'prepare_secure_command')`
  bei jedem Reconnect nach Miniserver-Reboot** — `sendToLoxone()` prüfte vor
  dem Senden `loxApi.is_connected()`, das in `node-lox-ws-api` nur
  `this.connection !== undefined` (Transport-Ebene) bedeutet. Nach jedem
  (Re-)Connect steht die WebSocket-Verbindung aber schon, bevor der
  asynchrone Auth-Handshake (`this._auth`, von `send_command()` benötigt)
  neu aufgebaut ist — in diesem Fenster war `is_connected()` schon `true`,
  `this._auth` aber noch `undefined`. Jetzt wird stattdessen `loxAuthorized`
  geprüft (exakt auf das `authorized`-Event getaktet), Befehle in diesem
  Fenster werden sauber verworfen statt einen Fehler zu werfen. Auf
  Hardware reproduziert bei einem echten Miniserver-Reboot (Log vom
  2026-09-22).

## [Unreleased] - 2026-09-21

### Bugfix (Bridge)
- **Veraltete Daten nach Miniserver-Ausfall beim Bridge-Start** — war der
  Miniserver beim Start nicht erreichbar (`LoxAPP3.json nicht erreichbar:
  ECONNREFUSED`), blieb der State-Filter dauerhaft leer und `publishState()`
  verwarf alle Werte, obwohl der WebSocket später authentifiziert war. Zusätzlich
  fiel die Seriennummer auf `msno` zurück (falsches Topic-Präfix `mirai/lox/1/…`).
  Jetzt: Struktur wird bei jedem `get_structure_file`-Event des WebSockets neu
  übernommen (Filter, Seriennummer, Cmd-Abo), mit Cache-Fallback
  (`lox_structure.json`) für den Start.
- **Reconnect-Schleife ohne Pause** — `node-lox-ws-api` initialisiert
  `_reconnect_time` nie (→ 0 ms); jetzt explizit 5 s.
- `fetchLoxStructure()`: Timeout (10 s) + HTTP-Statuscheck.
- Watchdog: `[lox] Authentifiziert, aber keine Struktur erhalten` als Error.
- Zeitstempel in allen Log-Zeilen; kein "Unerwartetes Topic"-Rauschen mehr für
  `mirai/audio/…`.

## [Unreleased] - 2026-07-16

### Neu (MiraiPanel-LCD + Plugin)
- **Heizung als Pool-Widget (2 Instanzen)** — zweite Runde nach Jalousie:
  `content_heating` aufgeteilt in `content_heating1` (Standard, Seite 2)
  und `content_heating2` (geparkt, erst nach Plugin-Zuweisung sichtbar).
  Ablage als kompaktes Blob `Layout_JSON_Heating` (60 Bytes) statt
  JSON-Blob — identisches Muster wie `Layout_JSON_Blinds`.
  Firmware: `update_heating_display` parametrisiert (`n: int`), Dispatch-
  und Subscribe-Blöcke als 2-Instanzen-Schleife.
- **Struct-Globals `HeatingCache`** (`custom-components/pool_types.h`) —
  ein Global pro Instanz (`cache_Heat_1`, `cache_Heat_2`) statt 7 Einzel-
  Globals, hält actual/target/active/override_reason/window/comfort/
  active_mode/override_end_str.
- **Bugfix `t_heat_window`** — `Topic_Heat_OpenWindow` (Fenster-offen-Signal)
  wurde zwar im Dispatch verarbeitet, fehlte aber in `topic_fields()` (Firmware)
  und `build_topics()` (Plugin). Jetzt vollständig konfigurierbar (Alias
  `t_heat_window` + `t_heat_window_1/2` in der Firmware,
  aus `$states['openWindow']` im Plugin).
- Plugin `index.php`: `content_heating` → `content_heating1/2` in WIDGETS
  und CTRL_TYPES, `heatingCount`-Zähler analog `blindsCount`,
  Legacy-Migration `content_heating` → `content_heating1`.
- Web-UI `index.html`: Heizungs-Konfiguration mit 2. Instanz-Block
  (Progressive Disclosure), `LAYOUT_WIDGETS`/`LAYOUT_SIZES` aktualisiert,
  `renderHeatingStatus()` mit dynamischen Instanz-Karten.
- **Licht als Pool-Widget (2 Instanzen)** — dritte Runde, gleiches Muster
  wie Heizung: `content_light` aufgeteilt in `content_light1` (Standard,
  Seite 1) und `content_light2` (geparkt). Ablage als kompaktes Blob
  `Layout_JSON_Light`. Struct-Global `LightCache`
  (`custom-components/pool_types.h`) fasst `mood_id`/`master_value`/
  `last_on_id`/`mood_json` (inkl. Szenenliste als `std::string`-Feld) zu
  einem Global pro Instanz zusammen (`cache_Light_1`, `cache_Light_2`).
  - **Szenen-Overlay bleibt bewusst einmalig** (nicht pro Instanz
    dupliziert) — neues UI-Zustands-Global `cache_LightScenesInstance`
    merkt sich, welche Instanz "Szenen" gedrückt hat, damit die 5
    gemeinsamen Szenen-Buttons das richtige `Topic_Light_CMD_N`
    ansprechen. `open_light_scenes`-Script dafür parametrisiert (`n: int`).
  - Firmware: `update_light_display` parametrisiert, Dispatch- und
    Subscribe-Blöcke als 2-Instanzen-Schleife (Batch 3 + 3b).
  - Beim Umbau gefunden und mitbehoben: ein verwaister, handgeschriebener
    `content_light`-Eintrag im `layout/set`-MQTT-Handler
    (`modules/mqtt_router.yaml`), der nicht über den generischen
    `layout_widgets()`-Mechanismus lief und beim Pool-Umbau sonst
    übersehen worden wäre (führte zu `esphome config`-Fehlern wegen
    gelöschter `layout_light_*`-Globals — sofort aufgefallen und behoben).
  - Plugin `index.php`: `content_light` → `content_light1/2` in WIDGETS
    und CTRL_TYPES, `lightCount`-Zähler analog `heatingCount`,
    Legacy-Migration `content_light` → `content_light1`.
  - Plugin `api.php`: `LightControllerV2`-Case auf `$lightNums`-Lookup +
    Alias-Suffix-Muster umgestellt (analog `$heatingNums`), inkl. der
    Subcontrol-Suche für Master-Helligkeit pro Instanz.
  - Web-UI `index.html`: Licht-Konfiguration mit 2. Instanz-Block,
    `LAYOUT_WIDGETS`/`LAYOUT_SIZES` aktualisiert, der bisher inline in
    `applyStatus()` stehende Licht-Status-Code in eine eigene
    `renderLightStatus()`-Funktion mit dynamischen Instanz-Karten
    extrahiert (gleiches Muster wie `renderHeatingStatus`).
- **Schalter als Pool-Widget (3 → 9 Instanzen)** — vierte und letzte Runde
  dieser Reihe. Anders als Blinds/Heizung/Licht war Schalter schon
  *teilweise* pool-artig (Plugin-UI konnte schon 3 Slots automatisch
  zuweisen), die Firmware nutzte aber noch 3 fest kopierte LVGL-Blöcke +
  Einzel-Layout-Globals statt des Pool-Mechanismus — jetzt vollständig
  vereinheitlicht.
  - **Layout-Design geklärt statt neu erfunden**: 9 Schalter passen als
    3×3-Raster auf eine Seite, weil 3 gestapelte Schalter-Kacheln (je
    ~100 px) schon heute zusammen die Höhe eines Audio_small-/Heizungs-
    Blocks (~324 px) ergeben — kein neues Kompakt-Widget nötig, einfach
    das bestehende Schalter-Kachel-Design 9× wiederholen. **Instanz 1-3
    bleiben an ihrer heutigen Position (Seite 1) als Default** —
    Bestandsverhalten unverändert, Instanz 4-9 starten geparkt bis der
    Nutzer sie zuweist/platziert.
  - **Themen-Nummerierung bewusst NICHT auf `_1".."_9"` umgestellt**:
    `Topic_SW_9_STATUS/CMD` (Instanz 1) bis `Topic_SW_17_STATUS/CMD`
    (Instanz 9) setzen einfach die schon bestehende absolute Nummerierung
    fort (`Topic_SW_1..8` = 8 Hardware-Tasten, unabhängige Sache).
    Dadurch bleiben die Config-Keys (`t_sw9_status` usw.) für Instanz 1-3
    unverändert — keine Alias-Migration nötig, im Gegensatz zu
    Blinds/Heizung/Licht (dort war der Suffix vorher kein Instanz-Index).
  - Kein Struct-Cache nötig (anders als Heizung/Licht) — der An/Aus-
    Zustand wird direkt auf das LVGL-switch-Objekt angewendet, nie
    zwischengespeichert.
  - Firmware: Dispatch-Block als 9-Instanzen-Schleife (`mqtt_router.yaml`),
    neuer `Layout_JSON_Switch`-Blob, `pool_widget_defaults()` um 9
    Einträge erweitert. Beim Umbau erneut gefunden und mitbehoben: ein
    verwaister, handgeschriebener `content_switch1/2/3`-Block im
    `layout/set`-Handler (gleiche Art Fund wie bei Heizung/Licht zuvor).
  - Plugin `index.php`/`api.php`: `WIDGETS`/`swNames`/`$swNums` von 3 auf
    9 Einträge erweitert (Themen-Offset 9-17 statt 9-11).
  - Web-UI `index.html`: Konfiguration mit Instanz 4-9 als Progressive
    Disclosure (`switchRevealConfigured`/`switchAddInstance`),
    `LAYOUT_WIDGETS`/`LAYOUT_SIZES` um Instanz 4-9 erweitert. Kein neuer
    Status-Bereich (Schalter hatten nie einen — bewusst nicht neu
    eingeführt, außerhalb des Umbau-Umfangs).
  - **Damit ist die Pool-Widget-Rollout-Reihe abgeschlossen**: Jalousie
    (4x) → Heizung (2x) → Licht (2x) → Schalter (9x), alle nach demselben
    Muster.
  - **Nachzügler: editierbare Schalter-Bezeichnung** — `lbl_SW1-9` zeigte
    bisher nur generische Platzhalter ("Schalter 1" usw.) statt des
    echten Loxone-Controlnamens. Neues Topic-Feld `t_sw{9..17}_label`
    (Firmware: `Topic_SW_{9..17}_LABEL`, gleicher Mechanismus wie jedes
    andere Topic-Feld) wird beim Loxone-Scan aus `ctrl['name']` befüllt
    (`api.php`) und ist zusätzlich direkt über die Geräte-Weboberfläche
    editierbar. Neues Boot-Script `apply_switch_labels` überträgt den
    Wert nach `apply_layout` auf die LVGL-Labels (leerer Wert lässt den
    kompilierten Default stehen).

## [Unreleased] - 2026-07-10

### Neu (MiraiPanel-LCD, Firmware + Plugin)
- **Neue Wetter-Seite** (bisher leerer Platzhalter "Seite 3", jetzt
  `content_weather`) — aktuelle Bedingungen (großes Icon, Temperatur,
  Beschreibung, gefühlte Temperatur/Feuchte/Wind) plus 3-Tage-Vorhersage
  (Hoch/Tief/Icon/Wochentag je Tag).
  - **Datenquelle: [Weather4Lox](https://github.com/Jan21493/LoxBerry-Plugin-Weather4Lox)**,
    direkt per MQTT — kein Umweg über Loxone-Virtueingänge nötig.
    Weather4Lox published bereits retained Flach-Topics auf denselben
    LoxBerry-MQTT-Broker, den MiraiBridge auch nutzt (Präfix z. B. `w4lx`,
    in Weather4Lox unter SERVER.TOPIC einstellbar). Voraussetzung: Weather4Lox
    muss so konfiguriert sein, dass mindestens die Tagesvorhersage für
    heute/morgen/übermorgen an MQTT gesendet wird.
  - Nur EIN neues Konfigurationsfeld nötig (Wetter-MQTT-Präfix) statt einer
    ganzen Themen-Liste — Weather4Lox' Topic-Suffixe sind fest, anders als
    bei Loxone-Controls mit zufälligen UUIDs pro Installation.
  - **Icons**: echte Bitmaps (`icons/weather/*.png`, 28 Dateien, lokal im
    Firmware-Repo) statt Font-Glyphen — Lox-Icons.ttf hat keine
    brauchbaren Wettersymbole. Icons stammen aus Weather4Lox' "color"-Set
    (Apache-2.0-Repo, Herkunft/Zuordnung siehe `icons/weather/README.md`).
    Tag/Nacht-Auswahl aus Weather4Lox' eigenen Sonnenauf-/-untergangszeiten
    (`cur_sun_r`/`cur_sun_s`) gegen die geräteeigene SNTP-Uhr — bewusst
    nicht aus dem UI-Theme abgeleitet, da das manuell überschrieben/
    Lux-gesteuert sein kann.
  - Kein neuer Pool-Mechanismus: die Wetter-Seite bleibt im bestehenden
    Layout-Editor frei auf jede der 6 Seiten platzierbar (wie bisher schon
    bei "Seite 3"), nur umbenannt und mit echtem Inhalt gefüllt.
  - **Redesign nach erstem Live-Test auf echter Hardware:**
    - Fix: zwei Trennzeichen im Statustext ("Gefühlt · Feuchte · Wind")
      erschienen als leere Quadrate — `font_24` enthält kein Mittelpunkt-
      Zeichen (`·`, U+00B7). Ersetzt durch `•` (U+2022, bereits im
      Font-Glyphsatz, gleiches Zeichen wie in der Audio-Anzeige) und
      gleich zu 3 eigenen Stat-Spalten (Gefühlt/Feuchte/Wind) umgebaut —
      robuster als ein Fließtext mit Trennzeichen.
    - Volle Höhennutzung: die Seite füllte vorher nur ca. die Hälfte der
      992px (Inhalt endete bei ~y:580). Neuer Inhalt: Tageshoch/-tief
      ("Heute Max/Min") im Hero, Temperaturverlauf-Chart, vergrößerte
      Ausblick-Karten.
    - **Neuer Temperaturverlauf**: dünne Linie in der Geräte-Akzentfarbe
      (`theme_accent`) über die nächsten 24 Std, 8 Stützpunkte im
      3h-Raster (`hfc0,3,6,9,12,15,18,21_tt`, Weather4Lox' Stunden-
      vorhersage, bisher ungenutzt). Technisch analog zum bestehenden
      `draw_clock`-Canvas-Muster (`lv_canvas_init_layer` + `lv_draw_line`).
      Hinweis: die Punkte sind relativ "ab jetzt", nicht kalendertagfix
      Mitternacht–Mitternacht.
    - **Niederschlag ergänzt**: Weather4Lox liefert Regenwahrscheinlichkeit
      im selben Muster wie Temperatur (`cur_pop`, `dfc{N}_pop`,
      `hfc{N}_pop`, verifiziert in `datatoloxone.pl`). Als schmale blaue
      Balken unter der Temperaturlinie (gleiche 8 Stundenpunkte) sowie als
      Prozentwert auf den Ausblick-Karten (gedimmt, ab ca. 40% blau
      hervorgehoben). `_prec`/`_snow` (mm-Mengen) bleiben vorerst ungenutzt.
    - **"Heute"-Karte entfernt**: war redundant zum Hero (der zeigt bereits
      die aktuellen Bedingungen). Tageshoch/-tief wandert stattdessen in
      den Hero, die 3 Ausblick-Karten zeigen jetzt einen echten Ausblick
      auf 3 kommende Tage (`dfc1`/`dfc2`/`dfc3` statt `dfc0`/`dfc1`/`dfc2`
      — ein Tag mehr Daten als ursprünglich geplant).
- **Jalousie ist jetzt ein Pool-Widget (bis zu 4 Instanzen pro Panel).**
  Bisher war pro Panel genau 1 Jalousie-Widget fest verdrahtet — nicht jeder
  Raum ist aber gleich (ein Raum kann mehrere Fenster/Jalousien haben).
  Pilot für ein größeres Vorhaben: künftig sollen auch Licht (2x), Schalter
  (9x) und Heizung (2x) als konfigurierbare Pools folgen, nach demselben
  Muster.
  - Firmware: `content_blinds1..4` als parametrisiertes LVGL-Template
    (`modules/blinds_template.yaml`), analog zu den Touch-Tasten. Instanz 1
    verhält sich wie bisher (sichtbar, gleiche Position), Instanzen 2-4
    starten versteckt, bis sie zugewiesen/platziert werden.
  - MQTT-Topics jetzt indiziert (`t_blinds_pos_1`.."_4" etc.) — die alten
    unsuffixierten Felder bleiben zusätzlich als Alias für Instanz 1
    bestehen (Bestandskompatibilität, siehe Hinweise unten).
  - **Kein Cross-Talk zwischen Instanzen**: jede Instanz hat ihr eigenes
    Loxone-Control → eigenen MQTT-Topic-String, exakter Abgleich pro
    Instanz in beide Richtungen (Panel↔Loxone).
  - Layout-Speicherung für Pool-Widgets auf einen einzigen JSON-Blob
    (`Layout_JSON`-Global) umgestellt statt weiterer Einzel-Globals pro
    Widget — skaliert besser für die künftig weiteren Pool-Familien.
  - Seitenanzahl von 3 auf 6 erhöht (3 neue leere Pool-Seiten), damit für
    mehrere volle Jalousie-Widgets Platz ist. Die Fußzeilen-Punkte bleiben
    bewusst bei 3 (gefensterter Indikator: erste/mittlere/letzte Seite statt
    1:1-Zuordnung) — leere Seiten werden aus der Vor-/Zurück-Navigation
    automatisch ausgeblendet.
  - Plugin: Raum-Zuordnung erkennt mehrere Jalousie-Controls pro Raum und
    weist sie automatisch `content_blinds1..4` zu (wie bisher schon bei
    mehreren Schaltern), manuell im Formular korrigierbar.
- **Mehrstufiges Meldungs-Overlay am Display** (Info/Warnung/Fehler, Icon +
  Text) — Auslöser war ein I2C-Bus-Lockup auf `bus_b` (ToF-Sensor fiel aus,
  Display wachte danach nie wieder auf, ohne dass am Gerät etwas davon
  sichtbar war).
  - Neues LVGL-Overlay (`modules/lvgl_ui.yaml`, `notify_overlay`) — gleiches
    Bedienmuster wie das bestehende Audio-Overlay (Schließen-Button oben
    rechts), zeigt Icon (Lox-Icons-Codepoints U+EA2C/U+EAB0/U+E908 für
    Info/Warnung/Fehler) + Text zentriert.
  - Zwei Quellen: **geräteinterne Fehlererkennung** (neuer 30s-Health-Check
    in `MiraiPanel.yaml` — prüft alle `bus_b`-Sensoren via ESPHomes
    `is_failed()`, den neuen Touch-Treiber via `TouchDriver::is_healthy()`
    sowie Ethernet-/MQTT-Verbindung, flankengetriggert) und **externe
    Meldungen von Loxone per MQTT** (neuer Topic `Topic_Notify_Cmd`,
    JSON-Payload `{"level":...,"text":...}`, `{"clear":true}` zum
    Zurücknehmen).
  - Ungelesene Meldungen bleiben nach dem automatischen 20s-Timeout gemerkt
    und erscheinen beim nächsten Aufwachen (Touch/Näherung) erneut — läuft
    zentral über `screensaver_dismiss`, keine der ~12 Wake-Trigger-Stellen
    musste einzeln angepasst werden. Bewusst kein Dauerblockieren des
    Bildschirmschoners (Burn-in-Schutz bleibt erhalten).
  - Wegtippen publiziert ein MQTT-Ack (`.../ack`) zurück an Loxone.
  - `custom-components/touch_driver.h` bekam dabei erstmals eine I2C-
    Fehlerbehandlung (Rückgabewerte wurden bisher überall ignoriert) —
    macht einen hängenden Touch-Sensor jetzt sichtbar, statt ihn nur still
    im Log verschwinden zu lassen.
  - LoxBerry-Plugin: neues Feld "Meldungs-Kommando" im Panel-Formular
    (Sektion "Sonstiges", gleiches Muster wie "Sleep-Kommando").
  - **Nebenbei behobene, bisher ungetestete Lücke in `bin/bridge.js`:**
    `buildStateFilter()` berücksichtigte Kommando-Topics mit virtuellem
    Eingang (`sleep_cmd_uuid`, jetzt auch `notify_cmd_uuid`) bisher gar
    nicht — Loxone-Änderungen an diesen virtuellen Eingängen wurden nie
    tatsächlich nach MQTT weitergeleitet.
  - Aktive Meldung außerdem sichtbar in der Geräte-Weboberfläche (Kopfzeile-
    Badge + Karte im Status-Tab, dadurch auch im LoxBerry-Plugin-iframe).
  - Health-Check um den PIR-Bewegungssensor ergänzt: da er analog (ADC) statt
    I2C angebunden ist, gibt es dort kein "Kommunikationsfehler"-Signal wie
    bei den I2C-Sensoren — ein Ausfall zeigt sich stattdessen als dauerhaft
    unplausibler Wert (nahe 0V oder nahe VCC statt Schwingen um die
    Ruhespannung ~1,1V). Neue Plausibilitätsprüfung direkt im ADC-Handler
    (~10s sustained, kein einzelner Ausreißer).

### Geändert (MiraiPanel-LCD, Firmware)
- **MQTT-Feldname für den Loxone-Präfix vereinheitlicht: `t_lox_prefix` → `lox_prefix`.**
  Bisher lief `Topic_LoxPrefix` unter zwei verschiedenen JSON-Feldnamen —
  `lox_prefix` über die HTTP-Konfiguration (`/api/config`, Geräte-Weboberfläche)
  und `t_lox_prefix` über MQTT `topics/set` (LoxBerry-Plugin). Jetzt überall
  `lox_prefix`, dadurch braucht das Feld keine Sonderbehandlung mehr in der
  gemeinsamen Topic-Feldliste (`topic_fields()`, `custom-components/web_server.h`).
  Betrifft `api.php` (`build_topics()`) und `dev/mock_server.py` im Plugin.
- **Geräte-Webserver: nur noch eine aktive Verbindung gleichzeitig.**
  Mehrere gleichzeitig geöffnete Browser-Tabs überlasteten den Custom-
  Webserver (ESP-IDF-Default `max_open_sockets=7` nie erhöht, dazu
  `lru_purge_enable` killte still die am längsten inaktive Verbindung) und
  führten zu Hängern/Neustarts. Neuer Endpunkt `/api/session` meldet, ob
  bereits ein Client per WebSocket verbunden ist; die Geräte-Weboberfläche
  zeigt in dem Fall einen Hinweis ("Ein anderes Gerät ist gerade verbunden")
  und startet automatisch, sobald frei. `max_open_sockets` zusätzlich auf 10
  angehoben (defense in depth).
- **Eigener MPR121-Touch-Treiber** (`custom-components/touch_driver.h`)
  ersetzt den Stock-ESPHome-`mpr121`-Treiber der 8 Hardware-Tasten:
  - Echter GPIO-Interrupt (`gpio_isr_handler_add` auf GPIO4) statt
    Dauerpolling jeden Hauptschleifen-Tick.
  - Debounce-Register-Bug behoben — der Stock-Treiber berechnet den
    Debounce-Wert zwar korrekt, schreibt beim eigentlichen Registerzugriff
    aber hart codiert `0` statt des berechneten Werts.
  - Rohe Diagnoseregister (gefilterter Signalwert, Baseline) nutzbar
    gemacht — neue "Touch-Tasten"-Karte im Status-Tab zeigt Signalstärke
    je Taste relativ zum Schwellwert live an.
  - Empfindlichkeit (Schwellwert, Lade­strom, Ladezeit) jetzt über die
    Konfigurationsseite live nachjustierbar, ohne Neustart.

### Geändert
- **Layout-Editor (Seiten-/Positionierung der Widgets) aus dem Plugin entfernt
  — passiert jetzt direkt am Gerät.** Das Panel bekam eine eigene
  Weboberfläche für sein Display-Layout (MiraiPanel-LCD: neuer Tab "Layout",
  eigene `/api/layout`-Endpunkte, sofort wirksam ohne Neustart). Grund:
  dieselbe Drag&Drop-Logik nicht mehr doppelt pflegen (Plugin + Firmware),
  und das Layout so auch ohne LoxBerry/MQTT nutzbar machen. Die Loxone-Raum-/
  Control-Zuordnung (die das Panel gar nicht kennen kann) bleibt unverändert
  auf der Panels-Seite im Plugin. `send_layout` (MQTT) wurde aus `api.php`
  entfernt, da nichts mehr darauf zugreift.
- **Plugin-Seite "Panel" bettet jetzt die komplette Geräte-Weboberfläche ein**
  (Konfiguration/Layout/Status/Netzwerk/Firmware/Log), mit einer eigenen,
  im Plugin-Stil gehaltenen Sub-Tab-Leiste statt der Geräte-eigenen Tab-Leiste
  im iframe (sonst zwei Navigationsebenen übereinander). Dafür in
  MiraiPanel-LCD/web_ui/index.html: URL-Parameter `?tab=...` (direkter
  Tab-Aufruf) und `?embed=1` (blendet Kopfzeile/eigene Tab-Leiste aus,
  zeigt stattdessen einen Hinweis auf den vom Plugin verwalteten
  MQTT-Topic-Feldern). Daneben immer ein Link "Direkt am Gerät öffnen" als
  Fallback (z. B. bei LoxBerry-Fernzugriff über HTTPS — der Browser
  blockiert dann das Einbetten der unverschlüsselten Geräte-Seite als Mixed
  Content). Die "provisorisch"-Markierung der MQTT-Topic-Felder wurde
  entfernt — manuelles Anpassen ist ein bewusst unterstützter Weg für den
  eigenständigen Betrieb ohne LoxBerry, kein unfertiger Zustand.
- **Plugin umbenannt: "MiraiPanel" → "MiraiBridge".** Das Panel/die Firmware
  heißt weiterhin MiraiPanel (eigenes Repo, MiraiPanel-LCD) — nur das
  LoxBerry-Plugin (Bridge + Konfigurations-UI) trägt jetzt den eigenständigen
  Namen. Betrifft `plugin.cfg` (`NAME`/`FOLDER`: `miraipanel` → `miraibridge`,
  `TITLE`: `MiraiPanel` → `MiraiBridge`), den systemd-Service
  (`miraipanel-bridge` → `miraibridge`), `bin/package.json`, sowie alle
  Stellen im UI, die sich auf das Plugin selbst beziehen (Sidebar-Logo,
  Seitentitel, Export/Import-Dateinamen). MQTT-Topics (`mirai/...`) und die
  Bezeichnung der Panel-Geräte selbst sind davon **nicht** betroffen.
- **Netzwerk-Scan ("Panels → Netzwerk scannen") ist jetzt MiraiPanel-exklusiv.**
  Vorher wurde über ESPHomes generisches `<name>/status`-Birth-Topic gesucht,
  wodurch auch beliebige andere ESPHome-Geräte im Netz gefunden wurden. Die
  Firmware veröffentlicht jetzt retained ihre aktuelle IP unter dem eigenen
  `mirai/<name>/ip`-Topic (`EthernetManager::get_ip_str()`); `<name>/status`
  wird nur noch ergänzend für den Online-Status ausgelesen. Behebt auch das
  Problem, dass der Scan eine veraltete WLAN-IP statt der aktuellen LAN-IP
  anzeigte.
- **Panel-Übersicht zeigt jetzt den Online-Status** der bereits konfigurierten
  Panels (nicht nur beim manuellen Scan), automatisch aktualisiert beim Laden
  der Seite sowie nach Speichern/Löschen eines Panels.
- **Theme folgt jetzt der LoxBerry-Systemeinstellung** (hell/dunkel) statt
  fest verdrahtetem Dark-Design. Hell nutzt LoxBerrys Design-Tokens
  (`design-tokens.css`), Dunkel greift automatisch über `body.theme-dark`.
- **Layout-Editor: Licht/Jalousie lassen sich jetzt links/rechts tauschen.**
  Vorher war die X-Position bei halbbreiten Widgets fest verdrahtet und nur
  die Zeile (Y) war per Drag verschiebbar. Jetzt per Drag im Canvas oder über
  eine neue "Spalte"-Auswahl in der Widget-Liste möglich.
- **Einheitliches Erscheinungsbild aller Dropdowns und Text-Eingabefelder.**
  Mehrere Formularfelder (Layout-Editor "Seite"/"Zeile"/"Spalte", Funktions-
  block-Auswahl je Raum-Control, Hardware-Tasten/Sensor-Ziele/Audio-Kommandos)
  hatten keine oder abweichende CSS-Regeln und fielen dadurch auf den nativen,
  hellen Browser-Stil zurück bzw. wirkten kleiner/anders als der Rest der
  Oberfläche. Jetzt gibt es je eine gemeinsame Basis-Regel für alle `<select>`
  und alle Text-/Zahlen-Inputs; einzelne Kontexte passen nur noch die Breite
  an, nicht mehr Farbe/Rahmen/Radius.
- Strukturiertes Logging in `api.php` über LoxBerrys `LBLog`
  (`loxberry_log.php`): Verbindungsfehler (Loxone nicht erreichbar,
  MQTT-Publish fehlgeschlagen) und Admin-Aktionen (Bridge-Neustart) landen
  jetzt in `api.log` im Plugin-Log-Verzeichnis, sichtbar über LoxBerrys
  Log-Manager (System → Log-Dateien).
- **Layout-Editor: Zeilenraster für volle/halbe Widgets und Schalter
  vereinheitlicht** (`GRID_Y`). Vorher durften nur "switch"-Widgets auf die
  9 Unterpositionen einrasten — volle/halbe Widgets nur auf die 3
  Hauptzeilen. Dadurch blieb z. B. nach 1-2 Schaltern am Seitenanfang der
  Rest der vollen Zeile ungenutzt reserviert, bevor das nächste Widget
  starten durfte. Jetzt dürfen alle Widget-Arten auf jede der 9 Positionen
  einrasten.
- **Layout-Editor: Sidebar/Widget-Liste überarbeitet** — Breite auf 560px
  begrenzt (vorher zog sie sich auf breiten Bildschirmen beliebig in die
  Länge, "Zeile"-Dropdown wirkte dadurch absurd breit), Grid-Spalten der
  Zeilen normalisiert, Liste wird jetzt nach Seite gruppiert (Überschriften
  "Seite 1/2/3") statt alle Seiten ununterscheidbar untereinander zu zeigen.
- **Layout-Editor: Canvas-Vorschau optisch überarbeitet** — Gehäuse-Rahmen
  mit Verlauf/Schatten statt flacher schwarzer Fläche, Widget-Kacheln als
  gut lesbare neutrale Karten statt dauerhaftem Grün-Wash (Akzentfarbe
  erscheint jetzt gezielt nur noch bei Hover/Drag als Feedback).

- **Eingebettete Geräte-Seite übernimmt jetzt das aktive LoxBerry-Theme**
  (hell/dunkel) statt immer auf ihr eigenes Dunkel-Default zu fallen. Die
  Panel-Seite erkennt `body.theme-dark` (von LoxBerry serverseitig gesetzt)
  und hängt `&theme=light`/`&theme=dark` an die iframe-URL an; die
  Geräte-Seite (MiraiPanel-LCD: `web_ui/index.html`) übernimmt diesen
  Parameter beim ersten Laden als Theme-Vorgabe. Der eigene Umschalter der
  Geräte-Seite bleibt beim direkten (nicht eingebetteten) Aufruf unverändert
  nutzbar — im Embed-Modus ist die Kopfzeile mit dem Umschalter ohnehin
  bereits ausgeblendet.

### Behoben
- Plugin-Icons fehlten (`icons/` war leer) und führten bei der Installation
  zu `cp: cannot stat '.../icons/*'` sowie Fallback auf Default-Icons. Jetzt
  liegen `icon_64.png`, `icon_128.png`, `icon_256.png` und `icon_512.png` bei.
- In `api.php`'s `bridge_status`-Aktion überschattete eine lokale Variable
  `$log` (Logfile-Tail-Text) das globale `LBLog`-Objekt gleichen Namens —
  umbenannt zu `$logtail`.
- **Theme "Glass" (und vermutlich weitere Nicht-Dark-Themes) blieb trotz
  Theme-Umschaltung hell/weiß.** Ursache: `--surf2` (Hintergrund von
  Inputs/Selects/Badges/Funktionsblock-Zeilen) war auf `--lb-gray-100`
  gemappt — ein Token, das außer unserem eigenen `theme-dark`-Override kein
  LoxBerry-Theme selbst überschreibt. Jetzt auf `--lb-input-bg` umgestellt,
  das von Themes wie Glass tatsächlich pro Theme gepflegt wird.
- **`config/bridge.json` enthielt Test-Panels und Test-Werte** bei den
  globalen Feldern, die aus lokalen Mock-Server-Läufen stammten (der Mock-
  Server schrieb bisher direkt in dieselbe Datei, die auch ins Plugin-Paket
  wandert). Config zurückgesetzt; `dev/mock_server.py` persistiert jetzt in
  einer eigenen `dev/mock_bridge.json`, sodass das nicht mehr passieren kann.
- **Status-Seite: Licht-Helligkeit (Master-Helligkeit) blieb immer bei 0,
  obwohl das Panel-Display korrekt ~30 % zeigte.** Ursache: `bin/bridge.js`s
  `buildStateFilter()` sammelte nur die States der Steuerung selbst
  (`loxCtrl.states`), nicht die ihrer Subcontrols. Master-Helligkeit ist
  aber ein Subcontrol (`<uuid>/masterValue`, siehe `api.php`
  `build_topics()`) — dessen State-UUID landete nie im Filter, Loxone
  sendete den Wert korrekt, die Bridge hat ihn aber lautlos verworfen
  (`publishState()`'s `stateFilter`-Check). Fix sammelt jetzt generisch
  auch alle `subControls`-States mit — deckt automatisch auch künftige
  Subcontrol-basierte Topics ab, nicht nur `masterValue`. Debuggt über ein
  Live-Diagnose-Muster (`applyStatus` per Konsole umbiegen, Rohwerte pro
  WS-Push loggen) statt Vermutungen — die parallel gemeldete Jalousie-
  Anzeige stellte sich dabei als vorübergehender `bridge.json`-Sync-Zustand
  heraus, kein Code-Fehler.

### Hinweise
- **Jalousie-Pool: unproblematisch in beide Update-Reihenfolgen.** Neue
  Firmware + altes Plugin funktioniert weiter (Alias `t_blinds_pos` auf
  Instanz 1). Neues Plugin + alte Firmware ignoriert die neuen indizierten
  Felder, Panel behält den letzten Stand (kein Datenverlust). Für mehr als
  eine Jalousie pro Panel ist trotzdem die neue Firmware nötig (Compile/
  Flash nur vom Heim-PC aus möglich, siehe unten) — reine Konfiguration
  einer einzelnen Jalousie ist von diesem Update nicht betroffen.
- **`t_lox_prefix` → `lox_prefix`: Plugin und Firmware müssen zusammen
  aktualisiert sein.** Bis die neu geflashte Firmware auf dem Panel läuft,
  kommt eine über das Plugin gesendete Präfix-Änderung nicht an (altes
  Firmware-Feld erwartet noch `t_lox_prefix`) — betrifft nur das
  Ändern/Neusetzen des Loxone-Präfix in genau diesem Übergangsfenster,
  nicht den laufenden Betrieb mit bereits gesetztem Präfix.
- Änderungen an der Firmware (`mirai/<name>/ip`-Publish) erfordern ein
  Neu-Kompilieren/Flashen von MiraiPanel-LCD und lassen sich von dieser
  Entwicklungsumgebung aus nicht end-to-end testen (nur gegen den lokalen
  Mock-Server unter `dev/mock_server.py`).
- Das mitgelieferte `LoxBerry-Plugin-MiraiPanel.zip` im Projekt-Root ist mit
  altem Stand (u. a. der kontaminierten `config/bridge.json` sowie dem alten
  Plugin-Namen `miraipanel`) gepackt — muss vor dem nächsten Install-Test
  komplett neu gepackt werden (sinnvollerweise auch gleich umbenannt, z. B.
  `LoxBerry-Plugin-MiraiBridge.zip`).
- **Die Ordner-/Paketnamen-Änderung (`miraipanel` → `miraibridge`) bedeutet
  für LoxBerry eine komplette Neuinstallation, kein Update.** Eine bereits
  installierte "miraipanel"-Instanz (z. B. auf deinem LoxBerry CM5) bleibt
  als eigenständige Installation bestehen und muss über die LoxBerry
  Plugin-Verwaltung manuell deinstalliert werden — vorher die aktuelle
  Konfiguration über die neue Export-Funktion (Einstellungen → Konfiguration
  exportieren) sichern und nach der Neuinstallation unter MiraiBridge wieder
  importieren.
