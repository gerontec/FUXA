<?php
// dyness_api.php - self-describing machine/AI readable API for the Dyness battery (latest row of wagodb.dyness_battery)
//   dyness_api.php            JSON (default, same layout as ww_api.php: values.<key>.value/unit/description/source)
//   dyness_api.php?format=md  plain-text Markdown summary for language models
// Data: Dyness OpenAPI polled by dynessapi.py (cron */10 on heissa.de)
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/configbob.php';

header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
$self = 'https://heissa.de/web1/dyness_api.php';

$errors = [];
$r = null;
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r = $pdo->query("SELECT ts, device_sn, soc, soh, battery_power, battery_voltage, battery_current, battery_temp,
        cell_max_v, cell_min_v, cell_max_temp, cell_min_temp, remaining_kwh, rated_kwh, charge_limit_a, discharge_limit_a,
        cycle_count, battery_status, cells_per_box, box_count
        FROM dyness_battery ORDER BY ts DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $errors[] = 'database: ' . $e->getMessage();
}
if (!$r) $errors[] = 'no row in dyness_battery';

function v($value, string $unit, string $desc, string $src): array {
    return ['value' => $value, 'unit' => $unit, 'description' => $desc, 'source' => $src];
}
function num($x) { return $x === null ? null : $x + 0; }

$now = date('Y-m-d H:i:s');
$values = [];
if ($r) {
    $p = num($r['battery_power']);
    $values = [
        'soc' => v(num($r['soc']), '%', 'State of charge', 'dyness_battery.soc'),
        'soh' => v(num($r['soh']), '%', 'State of health', 'dyness_battery.soh'),
        'battery_power' => v($p, 'W', 'Battery power = voltage x current (the API field batteryPower is always 0); positive = charging, negative = discharging', 'dyness_battery.battery_power'),
        'battery_power_kw' => v($p === null ? null : round($p / 1000, 2), 'kW', 'battery_power in kW, positive = charging', 'derived'),
        'charge_power_kw' => v($p === null ? null : round(max($p, 0) / 1000, 2), 'kW', 'Charging power, 0 while discharging', 'derived'),
        'discharge_power_kw' => v($p === null ? null : round(max(-$p, 0) / 1000, 2), 'kW', 'Discharging power, 0 while charging', 'derived'),
        'battery_voltage' => v(num($r['battery_voltage']), 'V', 'Pack voltage (high-voltage stack)', 'dyness_battery.battery_voltage'),
        'battery_current' => v(num($r['battery_current']), 'A', 'Pack current, positive = charging', 'dyness_battery.battery_current'),
        'battery_temp' => v(num($r['battery_temp']), '°C', 'Battery temperature', 'dyness_battery.battery_temp'),
        'cell_max_v' => v(num($r['cell_max_v']), 'V', 'Highest cell voltage', 'dyness_battery.cell_max_v'),
        'cell_min_v' => v(num($r['cell_min_v']), 'V', 'Lowest cell voltage', 'dyness_battery.cell_min_v'),
        'cell_diff_mv' => v($r['cell_max_v'] === null || $r['cell_min_v'] === null ? null : round(($r['cell_max_v'] - $r['cell_min_v']) * 1000), 'mV', 'Cell voltage spread max - min', 'derived'),
        'cell_max_temp' => v(num($r['cell_max_temp']), '°C', 'Highest cell temperature', 'dyness_battery.cell_max_temp'),
        'cell_min_temp' => v(num($r['cell_min_temp']), '°C', 'Lowest cell temperature', 'dyness_battery.cell_min_temp'),
        'remaining_kwh' => v(num($r['remaining_kwh']), 'kWh', 'Remaining energy', 'dyness_battery.remaining_kwh'),
        'rated_kwh' => v(num($r['rated_kwh']), 'kWh', 'Rated capacity', 'dyness_battery.rated_kwh'),
        'charge_limit_a' => v(num($r['charge_limit_a']), 'A', 'BMS charge current limit', 'dyness_battery.charge_limit_a'),
        'discharge_limit_a' => v(num($r['discharge_limit_a']), 'A', 'BMS discharge current limit', 'dyness_battery.discharge_limit_a'),
        'cycle_count' => v(num($r['cycle_count']), '', 'Full cycles', 'dyness_battery.cycle_count'),
        'battery_status' => v($r['battery_status'], 'text', 'Charge / Discharge / Idle (API code 3 = Charge, 2 = Discharge)', 'dyness_battery.battery_status'),
        'charging' => v($r['battery_status'] === 'Charge', 'bool', 'Battery is charging', 'derived'),
        'discharging' => v($r['battery_status'] === 'Discharge', 'bool', 'Battery is discharging', 'derived'),
        'box_count' => v(num($r['box_count']), '', 'Number of battery modules in the stack', 'dyness_battery.box_count'),
        'cells_per_box' => v(num($r['cells_per_box']), '', 'Cells per module', 'dyness_battery.cells_per_box'),
    ];
}

$api = [
    'api' => ['name' => 'Dyness battery live API', 'version' => '1.0', 'self' => $self,
              'description' => 'Latest reading of the Dyness STACK100 high-voltage battery (13 modules) of customer Bob in Bobingen on the Sofar HYD 20KTL-3PH hybrid inverter (see sofar_api.php); polled from the Dyness OpenAPI every 10 minutes.'],
    'timestamp' => $r['ts'] ?? null,
    'timezone' => 'Europe/Berlin',
    'ok' => !$errors,
    'errors' => $errors,
    'data_age_s' => ['dyness_battery' => $r ? strtotime($now) - strtotime($r['ts']) : null],
    'device_sn' => $r['device_sn'] ?? null,
    'values' => $values,
];

if (($_GET['format'] ?? '') === 'md') {
    header('Content-Type: text/markdown; charset=utf-8');
    $fmt = function ($e) {
        $x = $e['value'];
        if ($x === null) return 'n/a';
        if (is_bool($x)) return $x ? 'yes' : 'no';
        return $x . (in_array($e['unit'], ['', 'text'], true) ? '' : ' ' . $e['unit']);
    };
    echo "# {$api['api']['name']}\n\n{$api['api']['description']}\n\n";
    echo "Timestamp: {$api['timestamp']} ({$api['timezone']}), data age {$api['data_age_s']['dyness_battery']} s, status: " . ($api['ok'] ? 'ok' : 'ERROR ' . implode('; ', $errors)) . "\n\n";
    echo "| key | value | meaning |\n|---|---|---|\n";
    foreach ($values as $k => $e) echo "| $k | {$fmt($e)} | " . str_replace('|', '/', $e['description']) . " |\n";
    echo "\nJSON: {$self}\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
