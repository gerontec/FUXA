<?php
// sofar_api.php - self-describing machine/AI readable API for customer Bob's Sofar HYD 20KTL-3PH hybrid inverter
// (latest row of wagodb.inverter_data, device_id 2, register section 0x0040-0x007F; Modbus poll every 5 min)
//   sofar_api.php            JSON (same layout as ww_api.php / dyness_api.php)
//   sofar_api.php?format=md  plain-text Markdown summary for language models
// Not exposed: Power_Bat1 and SOC_Bat1 read 0 over Modbus here - battery values come from dyness_api.php.
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/configbob.php';

header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
$self = 'https://heissa.de/web1/sofar_api.php';

$errors = [];
$r = null;
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $r = $pdo->query("SELECT timestamp AS ts, Power_PV1, Power_PV2, ActivePower_Output_Total, ActivePower_Load_Sys,
        PV_Generation_Today, PV_Generation_Total, Energy_Purchase_Today, Energy_Selling_Today, Load_Consumption_Today,
        Bat_Charge_Today, Bat_Discharge_Today, SOH_Bat1
        FROM inverter_data WHERE device_id = '2' AND section LIKE '0x0040%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $errors[] = 'database: ' . $e->getMessage();
}
if (!$r) $errors[] = 'no row in inverter_data for device_id 2';

function v($value, string $unit, string $desc, string $src): array {
    return ['value' => $value, 'unit' => $unit, 'description' => $desc, 'source' => $src];
}
function num($x) { return $x === null ? null : $x + 0; }

$now = date('Y-m-d H:i:s');
$values = [];
if ($r) {
    $pv1 = num($r['Power_PV1']); $pv2 = num($r['Power_PV2']);
    $values = [
        'pv1_power' => v($pv1, 'kW', 'PV string 1 DC power', 'inverter_data.Power_PV1'),
        'pv2_power' => v($pv2, 'kW', 'PV string 2 DC power', 'inverter_data.Power_PV2'),
        'pv_power' => v($pv1 === null && $pv2 === null ? null : round(($pv1 ?? 0) + ($pv2 ?? 0), 2), 'kW', 'PV power, sum of both strings', 'derived'),
        'output_power' => v(num($r['ActivePower_Output_Total']), 'kW', 'Inverter AC output power, all three phases', 'inverter_data.ActivePower_Output_Total'),
        'load_power' => v(num($r['ActivePower_Load_Sys']), 'kW', 'House load power', 'inverter_data.ActivePower_Load_Sys'),
        'pv_today' => v(num($r['PV_Generation_Today']), 'kWh', 'PV generation today', 'inverter_data.PV_Generation_Today'),
        'pv_total' => v(num($r['PV_Generation_Total']), 'kWh', 'PV generation lifetime', 'inverter_data.PV_Generation_Total'),
        'grid_purchase_today' => v(num($r['Energy_Purchase_Today']), 'kWh', 'Energy bought from the grid today', 'inverter_data.Energy_Purchase_Today'),
        'grid_feed_in_today' => v(num($r['Energy_Selling_Today']), 'kWh', 'Energy fed into the grid today', 'inverter_data.Energy_Selling_Today'),
        'load_today' => v(num($r['Load_Consumption_Today']), 'kWh', 'House consumption today', 'inverter_data.Load_Consumption_Today'),
        'battery_charge_today' => v(num($r['Bat_Charge_Today']), 'kWh', 'Battery charged today', 'inverter_data.Bat_Charge_Today'),
        'battery_discharge_today' => v(num($r['Bat_Discharge_Today']), 'kWh', 'Battery discharged today', 'inverter_data.Bat_Discharge_Today'),
        'battery_soh' => v(num($r['SOH_Bat1']), '%', 'Battery state of health as reported to the inverter', 'inverter_data.SOH_Bat1'),
    ];
}

$api = [
    'api' => ['name' => 'Sofar inverter live API (customer Bob, Bobingen)', 'version' => '1.0', 'self' => $self,
              'description' => 'Latest reading of the Sofar HYD 20KTL-3PH three-phase hybrid inverter of customer Bob in Bobingen (with the Dyness STACK100 battery, see dyness_api.php); Modbus RTU poll every 5 minutes.'],
    'timestamp' => $r['ts'] ?? null,
    'timezone' => 'Europe/Berlin',
    'ok' => !$errors,
    'errors' => $errors,
    'data_age_s' => ['inverter_data' => $r ? strtotime($now) - strtotime($r['ts']) : null],
    'values' => $values,
];

if (($_GET['format'] ?? '') === 'md') {
    header('Content-Type: text/markdown; charset=utf-8');
    $fmt = fn($e) => $e['value'] === null ? 'n/a' : $e['value'] . ($e['unit'] === '' ? '' : ' ' . $e['unit']);
    echo "# {$api['api']['name']}\n\n{$api['api']['description']}\n\n";
    echo "Timestamp: {$api['timestamp']} ({$api['timezone']}), data age {$api['data_age_s']['inverter_data']} s, status: " . ($api['ok'] ? 'ok' : 'ERROR ' . implode('; ', $errors)) . "\n\n";
    echo "| key | value | meaning |\n|---|---|---|\n";
    foreach ($values as $k => $e) echo "| $k | {$fmt($e)} | " . str_replace('|', '/', $e['description']) . " |\n";
    echo "\nJSON: {$self}\n";
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($api, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
