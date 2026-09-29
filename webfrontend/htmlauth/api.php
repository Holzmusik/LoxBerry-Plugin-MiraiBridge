<?php
// MiraiBridge Plugin API
require_once "loxberry_system.php";
require_once "loxberry_io.php";
require_once "loxberry_log.php";
header('Content-Type: application/json');
header('Cache-Control: no-store'); // Single-Page-App: JS ruft dieselbe URL wiederholt auf, nie cachen

// $lbpconfigdir/$lbpdatadir/$lbplogdir kommen automatisch von loxberry_system.php
// (INTERFACE 2.0: config/data/log liegen NICHT als Geschwister von webfrontend/,
// sondern in eigenen System-Verzeichnissen /opt/loxberry/{config,data,log}/plugins/<folder>/).
$CFG_FILE   = $lbpconfigdir . '/bridge.json';
$LOX_CACHE  = $lbpdatadir . '/lox_structure.json';
$LOG_FILE   = $lbplogdir . '/bridge.log';
$BRIDGE_SERVICE = 'miraibridge';

// Feste, angehängte Logdatei statt LOGSTART()/LOGEND() pro Request: api.php
// wird von der SPA sehr häufig aufgerufen (Polling, jede Nutzeraktion) — ein
// LOGSTART() pro Aufruf würde in LoxBerrys Log-Datenbank pro Klick eine neue
// Session anlegen (siehe loxberry_log.php: LOGSTART() fügt IMMER eine neue
// Zeile in die "logs"-Tabelle ein, unabhängig von "append"). Ohne LOGSTART
// landen INF/WARN/ERR trotzdem als sauber formatierte, log-level-gefilterte
// Zeilen in dieser Datei, die LoxBerrys Log-Manager (System → Log-Dateien)
// wie jede andere *.log-Datei im Plugin-Log-Verzeichnis anzeigt.
$log = LBLog::newLog([
    'name'     => 'api',
    'filename' => $lbplogdir . '/api.log',
    'append'   => 1,
    'addtime'  => 1,
]);

function cfg_load($file) {
    if (!file_exists($file)) return ['loxone'=>['msno'=>1],'panels'=>[]];
    return json_decode(file_get_contents($file), true) ?: ['loxone'=>['msno'=>1],'panels'=>[]];
}
function cfg_save($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function err($msg, $code=400, $level='WARN') {
    global $log;
    if ($level === 'ERR') { $log->ERR($msg); } else { $log->WARN($msg); }
    http_response_code($code);
    echo json_encode(['error'=>$msg]);
    exit;
}

// Miniserver-Zugangsdaten kommen aus der LoxBerry-Systemkonfiguration,
// nicht aus der Plugin-Config (dort steht nur die Miniserver-Nummer "msno").
function miniserver_list() {
    $out = [];
    foreach (LBSystem::get_miniservers() as $msno => $ms) {
        if (empty($ms['IPAddress'])) continue;
        $out[] = [
            'msno'      => (int)$msno,
            'name'      => $ms['Name'] ?: ('Miniserver ' . $msno),
            'ipaddress' => $ms['IPAddress'],
        ];
    }
    return $out;
}

function miniserver_conn($msno) {
    $all = LBSystem::get_miniservers();
    if (!$msno || !isset($all[$msno]) || empty($all[$msno]['IPAddress'])) return null;
    $ms = $all[$msno];
    return [
        'host' => $ms['IPAddress'],
        'port' => $ms['Port'] ?: 80,
        'user' => $ms['Admin_RAW'],
        'pass' => $ms['Pass_RAW'],
    ];
}

// Hilfsfunktion: MQTT-Nachricht per mosquitto_pub publishen.
// loxberry_io.php bietet kein mqtt_publish() in PHP — daher Shell-Aufruf.
function mqtt_pub($topic, $payload, $retain = false) {
    global $log;
    $mqtt = mqtt_connectiondetails();
    $host = $mqtt['brokerhost'] ?? '';
    if (empty($host)) {
        $log->ERR('mqtt_pub: MQTT Gateway nicht konfiguriert');
        return false;
    }
    $port    = intval($mqtt['brokerport'] ?: 1883);
    $userArg = !empty($mqtt['brokeruser']) ? '-u ' . escapeshellarg($mqtt['brokeruser']) : '';
    $passArg = !empty($mqtt['brokerpass']) ? '-P ' . escapeshellarg($mqtt['brokerpass']) : '';
    $retArg  = $retain ? '-r' : '';
    $cmd = "mosquitto_pub -h " . escapeshellarg($host) . " -p {$port} {$userArg} {$passArg} {$retArg}"
         . " -t " . escapeshellarg($topic)
         . " -m " . escapeshellarg($payload)
         . " 2>&1";
    $out = (string)@shell_exec($cmd);
    if ($out !== '') {
        $log->WARN('mqtt_pub: ' . trim($out));
        return false;
    }
    return true;
}

// Geräte-Scan über MQTT, exklusiv über unseren eigenen "mirai/"-Namespace —
// NICHT über ESPHomes generisches "<name>/status" (das würde jedes beliebige
// ESPHome-Gerät im Netz finden, nicht nur MiraiPanels). Die Firmware
// veröffentlicht seit dem "mirai/<name>/ip"-Fix (mqtt_router.yaml) retained
// ihre aktuelle IP direkt selbst — kein unzuverlässiges mDNS mehr nötig.
// "<name>/status" (ESPHomes eigenes Birth/LWT, existiert bereits) wird nur
// noch ergänzend für den Online-Status ausgelesen.
function scan_mqtt_devices() {
    global $log;
    $mqtt = mqtt_connectiondetails();
    $host = $mqtt['brokerhost'] ?? '';
    if (empty($host)) {
        $log->WARN('scan_panels: MQTT Gateway ist in LoxBerry nicht konfiguriert');
        return [];
    }
    if (!@shell_exec('which mosquitto_sub 2>/dev/null')) {
        $log->ERR('scan_panels: mosquitto_sub nicht gefunden — bitte mosquitto-clients installieren: sudo apt-get install -y mosquitto-clients');
        return ['_error' => 'mosquitto_sub nicht installiert'];
    }
    $host    = escapeshellarg($host);
    $port    = intval($mqtt['brokerport'] ?: 1883);
    $userArg = !empty($mqtt['brokeruser']) ? '-u ' . escapeshellarg($mqtt['brokeruser']) : '';
    $passArg = !empty($mqtt['brokerpass']) ? '-P ' . escapeshellarg($mqtt['brokerpass']) : '';
    $cmd = "timeout 5 mosquitto_sub -h {$host} -p {$port} {$userArg} {$passArg} -t 'mirai/+/ip' -t 'mirai/+/status' -v 2>&1";
    $log->INF('scan_panels: ' . $cmd);
    $out = (string)@shell_exec($cmd);
    $log->INF('scan_panels: Ergebnis: [' . trim($out) . ']');

    $ips = [];      // name => ip
    $statuses = []; // name => online/offline
    foreach (explode("\n", trim((string)$out)) as $line) {
        if (!$line) continue;
        $parts = preg_split('/\s+/', $line, 2);
        if (count($parts) < 2) continue;
        [$topic, $payload] = $parts;
        if (preg_match('#^mirai/([^/]+)/ip$#', $topic, $m)) {
            $ips[$m[1]] = trim($payload);
        } elseif (preg_match('#^mirai/([^/]+)/status$#', $topic, $m)) {
            $statuses[$m[1]] = strtolower(trim($payload));
        }
    }

    $devices = [];
    foreach ($ips as $name => $ip) {
        $devices[] = [
            'name' => $name,
            'friendly_name' => $name,
            'ip' => $ip,
            'online' => ($statuses[$name] ?? '') === 'online',
        ];
    }
    usort($devices, function($a, $b) { return strcmp($a['name'], $b['name']); });
    return $devices;
}

// Baut das flache "t_*"-Topic-Payload für ein Panel: Heizung/Beschattung/
// Licht werden automatisch aus den im Panel zugeordneten Loxone-Controls
// abgeleitet (states-UUIDs, siehe MiraiPanel-LCD/MiraiPanel.yaml apply_layout
// bzw. die states-Objekte in LoxAPP3.json) — alles andere (Hardware-Tasten,
// Sensor-Ziele, Audio-Kommandos, Zeit/Außenklima) kommt aus manuell
// gepflegten Feldern, da es keine feste Beziehung zu einem Control gibt.
function build_topics($panel, $global, $loxStructure) {
    $controls = $loxStructure['controls'] ?? [];
    $topics = [];

    $serial = $loxStructure['msInfo']['serialNr'] ?? null;
    if ($serial) $topics['lox_prefix'] = "mirai/lox/{$serial}/";

    foreach (($panel['controls'] ?? []) as $uuid => $mapping) {
        $ctrl = $controls[$uuid] ?? null;
        if (!$ctrl) continue;
        $states = $ctrl['states'] ?? [];
        switch ($mapping['type'] ?? '') {
            case 'IRoomControllerV2':
                // Heizung-Pool (max. 2 Instanzen) — Index aus dem Widget-Slot,
                // analog $blindsNums bei Jalousie.
                $heatingNums = ['content_heating1' => 1, 'content_heating2' => 2,
                                'content_heating'  => 1]; // legacy: vor Pool-Widget-Refactor
                $heatingNum  = $heatingNums[$mapping['widget'] ?? ''] ?? null;
                if ($heatingNum !== null) {
                    $sfx = $heatingNum === 1 ? '' : "_{$heatingNum}";
                    $topics["t_heat_cmd{$sfx}"]              = $ctrl['uuidAction'] ?? '';
                    $topics["t_heat_actual{$sfx}"]           = $states['tempActual'] ?? '';
                    $topics["t_heat_target{$sfx}"]           = $states['tempTarget'] ?? '';
                    $topics["t_heat_active{$sfx}"]           = $states['active'] ?? '';
                    $topics["t_heat_comfort{$sfx}"]          = $states['comfortTemperature'] ?? '';
                    $topics["t_heat_mode{$sfx}"]             = $states['activeMode'] ?? '';
                    $topics["t_heat_override_entries{$sfx}"] = $states['overrideEntries'] ?? '';
                    $topics["t_heat_window{$sfx}"]           = $states['openWindow'] ?? '';
                    $topics["t_heat_opmode{$sfx}"]           = $states['operatingMode'] ?? '';
                    // Immer auch die _N-Suffixvariante schreiben, damit topic_fields()
                    // beide Schreibweisen kennt (Alias + indiziert).
                    if ($heatingNum === 1) {
                        $topics["t_heat_cmd_1"]              = $ctrl['uuidAction'] ?? '';
                        $topics["t_heat_actual_1"]           = $states['tempActual'] ?? '';
                        $topics["t_heat_target_1"]           = $states['tempTarget'] ?? '';
                        $topics["t_heat_active_1"]           = $states['active'] ?? '';
                        $topics["t_heat_comfort_1"]          = $states['comfortTemperature'] ?? '';
                        $topics["t_heat_mode_1"]             = $states['activeMode'] ?? '';
                        $topics["t_heat_override_entries_1"] = $states['overrideEntries'] ?? '';
                        $topics["t_heat_window_1"]           = $states['openWindow'] ?? '';
                        $topics["t_heat_opmode_1"]           = $states['operatingMode'] ?? '';
                    }
                }
                break;
            case 'Jalousie':
                // Jalousie-Pool (max. 4 Instanzen, siehe MiraiPanel-LCD
                // content_blinds1-4) — Index kommt aus dem zugewiesenen
                // Widget-Slot, nicht aus der Reihenfolge im Raum.
                $blindsNums = ['content_blinds1' => 1, 'content_blinds2' => 2, 'content_blinds3' => 3, 'content_blinds4' => 4,
                               'content_blinds' => 1]; // legacy: vor Pool-Widget-Refactor
                $blindsNum  = $blindsNums[$mapping['widget'] ?? ''] ?? null;
                if ($blindsNum !== null) {
                    $topics["t_blinds_label_{$blindsNum}"] = $ctrl['name'] ?? '';
                    $topics["t_blinds_pos_{$blindsNum}"]   = $states['position'] ?? '';
                    $topics["t_blinds_shade_{$blindsNum}"] = $states['shadePosition'] ?? '';
                    $topics["t_blinds_up_{$blindsNum}"]    = $states['up'] ?? '';
                    $topics["t_blinds_down_{$blindsNum}"]  = $states['down'] ?? '';
                }
                break;
            case 'LightControllerV2':
                // Licht-Pool (max. 2 Instanzen) — Index aus dem Widget-Slot,
                // analog $heatingNums bei Heizung.
                $lightNums = ['content_light1' => 1, 'content_light2' => 2,
                              'content_light'  => 1]; // legacy: vor Pool-Widget-Refactor
                $lightNum  = $lightNums[$mapping['widget'] ?? ''] ?? null;
                if ($lightNum !== null) {
                    $sfx = $lightNum === 1 ? '' : "_{$lightNum}";
                    $topics["t_light_label{$sfx}"] = $ctrl['name'] ?? '';
                    $topics["t_light_moods{$sfx}"] = $states['activeMoodsNum'] ?? '';
                    $topics["t_light_list{$sfx}"]  = $states['moodList'] ?? '';
                    $topics["t_light_cmd{$sfx}"]   = $ctrl['uuidAction'] ?? '';
                    // Master-Helligkeit ist ein Subcontrol "<uuid>/masterValue"
                    foreach (($ctrl['subControls'] ?? []) as $subKey => $sub) {
                        if (substr($subKey, -12) === '/masterValue') {
                            $topics["t_light_master{$sfx}"] = $sub['states']['position'] ?? '';
                            break;
                        }
                    }
                    // Immer auch die _N-Suffixvariante schreiben, damit topic_fields()
                    // beide Schreibweisen kennt (Alias + indiziert).
                    if ($lightNum === 1) {
                        $topics['t_light_label_1'] = $ctrl['name'] ?? '';
                        $topics['t_light_moods_1'] = $states['activeMoodsNum'] ?? '';
                        $topics['t_light_list_1']  = $states['moodList'] ?? '';
                        $topics['t_light_cmd_1']   = $ctrl['uuidAction'] ?? '';
                        foreach (($ctrl['subControls'] ?? []) as $subKey => $sub) {
                            if (substr($subKey, -12) === '/masterValue') {
                                $topics['t_light_master_1'] = $sub['states']['position'] ?? '';
                                break;
                            }
                        }
                    }
                }
                break;
            case 'Switch':
            case 'TimedSwitch':
                // Schalter-Pool (9 Instanzen, content_switch1-9). Themen-
                // Nummerierung setzt die 8 Hardware-Tasten-Topics fort
                // (Widget-Instanz 1=9, ..., Instanz 9=17) — war schon vor
                // dem Pool-Umbau ein Instanz-Index, keine Alias/Migration
                // nötig wie bei Blinds/Heizung/Licht.
                $swNums = ['content_switch1' => 9,  'content_switch2' => 10, 'content_switch3' => 11,
                           'content_switch4' => 12, 'content_switch5' => 13, 'content_switch6' => 14,
                           'content_switch7' => 15, 'content_switch8' => 16, 'content_switch9' => 17];
                $swNum  = $swNums[$mapping['widget'] ?? ''] ?? null;
                if ($swNum !== null) {
                    $topics["t_sw{$swNum}_status"] = $states['active'] ?? '';
                    $topics["t_sw{$swNum}_cmd"]    = $ctrl['uuidAction'] ?? '';
                    $topics["t_sw{$swNum}_label"]  = $ctrl['name'] ?? '';
                }
                break;
        }
    }

    foreach (($panel['hw_keys'] ?? []) as $i => $uuid) {
        if ($uuid) $topics['t_sw' . ($i + 1)] = $uuid;
    }

    $sensorMap = [
        'room_temp' => 't_rt', 'room_humidity' => 't_rh', 'co2' => 't_co2', 'voc' => 't_voc',
        'pir' => 't_pir', 'mic' => 't_mic', 'brightness' => 't_brightness',
        'lux' => 't_lux', 'power' => 't_supply_power',
    ];
    foreach (($panel['sensor_targets'] ?? []) as $key => $uuid) {
        if ($uuid && isset($sensorMap[$key])) $topics[$sensorMap[$key]] = $uuid;
    }

    if (!empty($panel['audio_zone_topic'])) $topics['t_audio_zone']     = $panel['audio_zone_topic'];
    if (!empty($panel['buzzer_topic']))     $topics['t_buzzer_warning'] = $panel['buzzer_topic'];
    if (!empty($panel['sleep_cmd_uuid'])) {
        $uuid = $panel['sleep_cmd_uuid'];
        $ctrl = $controls[$uuid] ?? null;
        // Schalter (Switch/TimedSwitch): states.active liefert 0/1 — genau das
        // was die Firmware auf t_sleep_cmd erwartet. Kein Control-Treffer →
        // UUID direkt (rückwärtskompatibel, falls State-UUID direkt eingetragen).
        $topics['t_sleep_cmd'] = ($ctrl && isset($ctrl['states']['active']))
            ? $ctrl['states']['active'] : $uuid;
    }
    if (!empty($panel['notify_cmd_uuid']))  $topics['t_notify_cmd']     = $panel['notify_cmd_uuid'];
    // Wetter (Weather4Lox): nur das Präfix, kein Loxone-Control-UUID —
    // Weather4Lox published direkt auf denselben MQTT-Broker, die
    // restlichen Topic-Suffixe sind fest (siehe MiraiPanel-LCD mqtt_router.yaml).
    if (!empty($panel['weather_topic_prefix'])) $topics['t_weather_prefix'] = $panel['weather_topic_prefix'];

    $globalMap = [
        'time_uuid' => 't_time', 'date_uuid' => 't_date', 'dow_uuid' => 't_dow',
        'year_uuid' => 't_year', 'outside_temp_uuid' => 't_outside_temp',
        'outside_humidity_uuid' => 't_outside_hum',
    ];
    foreach (($global ?? []) as $key => $uuid) {
        if ($uuid && isset($globalMap[$key])) $topics[$globalMap[$key]] = $uuid;
    }

    return $topics;
}

$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

switch ($action) {

    // ── Konfiguration lesen/schreiben ───────────────────────
    case 'config':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            cfg_save($CFG_FILE, $body);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(cfg_load($CFG_FILE));
        }
        break;

    // ── Konfigurierte Miniserver (aus LoxBerry) auflisten ───
    case 'miniservers':
        echo json_encode(miniserver_list());
        break;

    // ── Netzwerk nach ESPHome-Geräten durchsuchen (via MQTT-Onlinestatus) ──
    case 'scan_panels':
        echo json_encode(scan_mqtt_devices());
        break;

    // ── MQTT-Broker-Info (aus LoxBerry MQTT-Gateway) ────────
    case 'mqtt_info':
        $mqtt = mqtt_connectiondetails();
        if (empty($mqtt['brokerhost'])) err('MQTT Gateway ist in LoxBerry nicht konfiguriert');
        echo json_encode(['broker' => $mqtt['brokeraddress']]);
        break;

    // ── Loxone Structure laden ──────────────────────────────
    case 'lox_structure':
        // Zuerst gecachte Version prüfen (max 1h alt)
        if (file_exists($LOX_CACHE) && (time() - filemtime($LOX_CACHE)) < 3600) {
            readfile($LOX_CACHE);
            break;
        }
        $cfg  = cfg_load($CFG_FILE);
        $lox  = miniserver_conn($cfg['loxone']['msno'] ?? null);
        if (!$lox) err('Kein Miniserver ausgewählt oder in LoxBerry konfiguriert');
        $url  = "http://{$lox['host']}:{$lox['port']}/data/LoxAPP3.json";
        $ctx  = stream_context_create(['http'=>[
            'timeout' => 5,
            'header'  => 'Authorization: Basic ' . base64_encode($lox['user'].':'.$lox['pass']),
        ]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) err('Loxone nicht erreichbar', 400, 'ERR');
        // Loxone älterer Firmware sendet Latin-1; sicherstellen dass Cache UTF-8 ist
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        }
        // Cache speichern
        @mkdir(dirname($LOX_CACHE), 0755, true);
        file_put_contents($LOX_CACHE, $data);
        echo $data;
        break;

    // ── Loxone Verbindung testen ────────────────────────────
    case 'lox_test':
        $cfg = cfg_load($CFG_FILE);
        $lox = miniserver_conn($cfg['loxone']['msno'] ?? null);
        if (!$lox) err('Kein Miniserver ausgewählt oder in LoxBerry konfiguriert');
        $url = "http://{$lox['host']}:{$lox['port']}/jdev/cfg/apiKey";
        $ctx = stream_context_create(['http'=>[
            'timeout' => 3,
            'header'  => 'Authorization: Basic ' . base64_encode($lox['user'].':'.$lox['pass']),
        ]]);
        $result = @file_get_contents($url, false, $ctx);
        echo json_encode(['ok' => ($result !== false)]);
        break;

    // ── Topic-Konfiguration an Panel senden (via MQTT) ──────
    // Löst auf dem Panel einen Neustart aus (siehe mqtt_router.yaml
    // "topics/set"), damit die MQTT-Subscriptions mit den neuen Topics
    // neu aufgebaut werden.
    case 'send_topics':
        $panel_name = $body['panel_name'] ?? '';
        if (!$panel_name) err('panel_name fehlt');

        $cfg = cfg_load($CFG_FILE);
        $panel = null;
        foreach ($cfg['panels'] as $p) {
            if ($p['name'] === $panel_name) { $panel = $p; break; }
        }
        if (!$panel) err('Panel nicht gefunden');

        if (!file_exists($LOX_CACHE)) err('Loxone-Struktur nicht verfügbar — zuerst im Panel einen Raum wählen');
        $rawJson = file_get_contents($LOX_CACHE);
        if (!mb_check_encoding($rawJson, 'UTF-8')) {
            $rawJson = mb_convert_encoding($rawJson, 'UTF-8', 'ISO-8859-1');
        }
        $loxStructure = json_decode($rawJson, true);
        if (!$loxStructure) err('Loxone-Struktur konnte nicht gelesen werden');

        $topics = build_topics($panel, $cfg['global'] ?? [], $loxStructure);

        // Payload in Batches à max. 1200 Bytes aufteilen:
        // ESPHome's MQTT-Buffer (~1536 Bytes) schneidet große Payloads still ab.
        // Das restart_after_topics-Script (mode: restart) setzt seinen 1,5s-Timer
        // bei jedem eingehenden Batch zurück — Neustart erst nach dem letzten Batch.
        $mqtt_topic = "mirai/{$panel_name}/topics/set";
        $batches = [];
        $current = [];
        foreach ($topics as $k => $v) {
            $current[$k] = $v;
            if (strlen(json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 800) {
                unset($current[$k]);
                $batches[] = $current;
                $current = [$k => $v];
            }
        }
        if (!empty($current)) $batches[] = $current;

        foreach ($batches as $i => $batch) {
            if ($i > 0) usleep(800000); // 800ms Abstand; Timer-Reset im Panel
            $result = mqtt_pub($mqtt_topic, json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($result === false) err('MQTT-Publish fehlgeschlagen (mosquitto_pub erreichbar?)', 400, 'ERR');
        }
        // Debug: Jalousie-Lookup nachvollziehen
        $debug_jalousie = [];
        foreach (($panel['controls'] ?? []) as $uuid => $mapping) {
            if (($mapping['type'] ?? '') === 'Jalousie') {
                $ctrl = $controls[$uuid] ?? null;
                $debug_jalousie[$uuid] = [
                    'widget'  => $mapping['widget'] ?? '',
                    'found'   => $ctrl !== null,
                    'states'  => $ctrl ? ($ctrl['states'] ?? []) : null,
                ];
            }
        }
        echo json_encode(['ok'=>true, 'topics'=>$topics, 'batches'=>count($batches),
            'debug_controls'  => array_map(fn($m) => $m['type'].'/'.$m['widget'], $panel['controls'] ?? []),
            'debug_jalousie'  => $debug_jalousie]);
        break;

    // ── Bridge-Status ────────────────────────────────────────
    // Die Bridge läuft als systemd-Service (installiert von postroot.sh).
    // Der "loxberry"-User (unter dem die Web-UI läuft) hat laut LoxBerrys
    // eigener sudoers-Konfiguration bereits passwortlosen systemctl-Zugriff.
    case 'bridge_status':
        $active = trim((string)@shell_exec("systemctl is-active " . escapeshellarg($BRIDGE_SERVICE) . " 2>/dev/null"));
        $running = ($active === 'active');
        $pid = trim((string)@shell_exec("systemctl show --property MainPID --value " . escapeshellarg($BRIDGE_SERVICE) . " 2>/dev/null"));
        if (!$running || $pid === '0') $pid = null;
        $logtail = '';
        if (file_exists($LOG_FILE)) {
            $lines = file($LOG_FILE);
            $logtail = implode('', array_slice($lines, -100)); // letzte 100 Zeilen
        }
        echo json_encode(['running'=>$running, 'pid'=>$pid, 'status'=>$active, 'log'=>$logtail]);
        break;

    // ── Bridge neu starten ───────────────────────────────────
    case 'bridge_restart':
        $log->INF('bridge_restart: Neustart von ' . $BRIDGE_SERVICE . ' angefordert');
        $out = @shell_exec("sudo systemctl restart " . escapeshellarg($BRIDGE_SERVICE) . " 2>&1");
        echo json_encode(['ok'=>true, 'out'=>$out]);
        break;

    // ── Zonen am Audioserver suchen (Panel-Editor: Audioserver-Host/Zone) ──
    // Fragt bridge.js' lokalen Scan-Server ab (127.0.0.1-only, siehe dort
    // startZoneScanServer/ZONE_SCAN_PORT) statt selbst eine WS-Verbindung
    // aufzubauen — nutzt so dieselbe, schon vorhandene AudioserverClient-
    // Logik. Braucht den laufenden miraibridge-Dienst.
    case 'scan_audio_zones':
        $host = trim($body['host'] ?? '');
        $port = (int)($body['port'] ?? 7091);
        if ($host === '') err('Audioserver-Host fehlt');
        $url = 'http://127.0.0.1:17091/scan-zones?host=' . urlencode($host) . '&port=' . $port;
        // Timeout > die 3s, die bridge.js für den Scan selbst braucht.
        $ctx = stream_context_create(['http' => ['timeout' => 6]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) err('Bridge nicht erreichbar — läuft der Dienst?', 502, 'ERR');
        header('Content-Type: application/json');
        echo $result;
        break;

    // ── Loxone-Radio-Sender (Streampal) in Sonn Core einrichten ─────────────
    // Legt die 13 kuratierten Loxone-Radio-Sender zentral als "Eigene
    // Streams" an (idempotent, siehe bin/loxone_radio_setup.js) — z.B. nach
    // einer Sonn-Core-Neuinstallation per Knopfdruck erneut auslösbar.
    // Gleiches Muster wie scan_audio_zones oben: fragt bridge.js' lokalen
    // Server ab statt selbst eine WS-Verbindung aufzubauen.
    case 'setup_loxone_radio':
        $host = trim($body['host'] ?? '');
        $port = (int)($body['port'] ?? 7091);
        if ($host === '') err('Audioserver-Host fehlt');
        $url = 'http://127.0.0.1:17091/setup-loxone-radio?host=' . urlencode($host) . '&port=' . $port;
        // Grosszuegiges Timeout: 13 Sender x ~400ms Pause + Verbindungsaufbau/Auth.
        $ctx = stream_context_create(['http' => ['timeout' => 20]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) err('Bridge nicht erreichbar — läuft der Dienst?', 502, 'ERR');
        header('Content-Type: application/json');
        echo $result;
        break;

    default:
        err('Unbekannte Aktion', 404);
}
?>
