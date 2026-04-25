<?php
declare(strict_types=1);
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$action = $_POST['action'] ?? '';
$service = $_POST['service'] ?? '';

$map = [
    'analog_bridge'    => ['analog_bridge.service'],
    'mmdvm_bridge'     => ['mmdvm_bridge.service'],
    'both'             => ['analog_bridge.service', 'mmdvm_bridge.service'],
];

if ($action !== 'restart') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported action']);
    exit;
}
if (!isset($map[$service])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported service']);
    exit;
}

$services = $map[$service];
$errors = [];
$verified = [];

foreach ($services as $svc) {
    $cmd = '/usr/bin/sudo /usr/bin/systemctl restart ' . escapeshellarg($svc) . ' 2>&1';
    $output = [];
    $status = 0;
    @exec($cmd, $output, $status);
    if ($status !== 0) {
        $errors[] = $svc . ': ' . trim(implode("\n", $output));
        continue;
    }

    $check = trim((string) @shell_exec('/bin/systemctl is-active ' . escapeshellarg($svc) . ' 2>/dev/null'));
    if ($check === 'active') {
        $verified[] = $svc;
    } else {
        $errors[] = $svc . ': restart command ran but service is "' . ($check !== '' ? $check : 'unknown') . '"';
    }
}

if ($errors) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Restart failed or could not be verified.',
        'detail' => $errors,
    ]);
    exit;
}

$message = match ($service) {
    'analog_bridge'   => 'Analog Bridge restart verified',
    'mmdvm_bridge'    => 'MMDVM Bridge restart verified',
    'both'            => 'Analog + MMDVM restart verified',
    default           => 'Restart verified',
};

echo json_encode([
    'ok' => true,
    'message' => $message,
    'verified' => $verified,
]);
