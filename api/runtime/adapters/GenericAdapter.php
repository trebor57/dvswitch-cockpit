<?php
declare(strict_types=1);

function dc_adapter_generic(array $bridgeLines, string $tzName): array {
    $rows = [];
    $provider = 'Idle';
    $network = 'Idle';
    $path = 'Idle';
    $target = '--';
    $lastHeard = '--';
    $lastSignal = 0;

    foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(D-Star),\s+received (RF|network) .* from ([^ ]+) to ([^ ]+)/i', $line, $m)) {
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'D-Star', trim($m[3]), dc_clean_target($m[4]), strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'RF');
            $provider = 'D-Star'; $network = 'D-Star'; $path = 'D-Star'; $target = dc_clean_target($m[4]); $lastHeard = trim($m[3]); $lastSignal = max($lastSignal, $stamp['epoch']);
        } elseif (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(P25),\s+received (RF|network) .* from ([^ ]+) to (TG )?(.+)$/i', $line, $m)) {
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'P25', trim($m[3]), dc_clean_target(($m[4] ?? '') . $m[5]), strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'RF');
            $provider = 'P25'; $network = 'P25'; $path = 'P25'; $target = dc_clean_target(($m[4] ?? '') . $m[5]); $lastHeard = trim($m[3]); $lastSignal = max($lastSignal, $stamp['epoch']);
        } elseif (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(NXDN),\s+received (RF|network) .* from ([^ ]+) to (TG )?(.+)$/i', $line, $m)) {
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'NXDN', trim($m[3]), dc_clean_target(($m[4] ?? '') . $m[5]), strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'RF');
            $provider = 'NXDN'; $network = 'NXDN'; $path = 'NXDN'; $target = dc_clean_target(($m[4] ?? '') . $m[5]); $lastHeard = trim($m[3]); $lastSignal = max($lastSignal, $stamp['epoch']);
        }
    }

    usort($rows, fn($a,$b) => strcmp((string)$b['utc'], (string)$a['utc']));
    return [
        'adapter' => 'generic',
        'provider' => $provider,
        'network' => $network,
        'connection_state' => dc_is_recent_epoch((int)$lastSignal, 180) ? 'Connected' : 'Idle',
        'path_label' => dc_is_recent_epoch((int)$lastSignal, 180) ? $path : 'Idle',
        'target_display' => $target,
        'target_note' => '(from stock bridge logs)',
        'last_heard' => $lastHeard,
        'rows' => array_slice($rows, 0, 40),
        'left_label' => 'Last Heard',
        'left_value' => $lastHeard,
        'signal_epoch' => $lastSignal,
    ];
}
?>