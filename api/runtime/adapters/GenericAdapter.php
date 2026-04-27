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
    $lastIdxByModeSrc = [];

    foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(D-Star),\s+received (RF|network) .* from ([^ ]+) to ([^ ]+)/i', $line, $m)) {
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'D-Star', trim($m[3]), dc_clean_target($m[4]), strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'RF');
            $provider = 'D-Star'; $network = 'D-Star'; $path = 'D-Star'; $target = dc_clean_target($m[4]); $lastHeard = trim($m[3]); $lastSignal = max($lastSignal, $stamp['epoch']);
        } elseif (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(P25),\s+received (RF|network) .* from ([^ ]+) to (TG )?(.+)$/i', $line, $m)) {
            $src = strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'RF';
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'P25', trim($m[3]), dc_clean_target(($m[4] ?? '') . $m[5]), $src);
            $lastIdxByModeSrc['P25|' . $src] = count($rows) - 1;
            $provider = 'P25'; $network = 'P25'; $path = 'P25'; $target = dc_clean_target(($m[4] ?? '') . $m[5]); $lastHeard = trim($m[3]); $lastSignal = max($lastSignal, $stamp['epoch']);
        } elseif (preg_match('/^\w:\s+[0-9:\-\. ]+\s+(NXDN),\s+received (RF|network) .* from ([^ ]+) to (TG )?(.+)$/i', $line, $m)) {
            $src = strtoupper($m[2]) === 'NETWORK' ? 'Net' : 'RF';
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'NXDN', trim($m[3]), dc_clean_target(($m[4] ?? '') . $m[5]), $src);
            $lastIdxByModeSrc['NXDN|' . $src] = count($rows) - 1;
            $provider = 'NXDN'; $network = 'NXDN'; $path = 'NXDN'; $target = dc_clean_target(($m[4] ?? '') . $m[5]); $lastHeard = trim($m[3]); $lastSignal = max($lastSignal, $stamp['epoch']);
        } elseif (preg_match('/^\w:\s+[0-9:\-\. ]+\s+P25,\s+network end of transmission,\s+([0-9.]+)\s+seconds,\s+([0-9.]+%)\s+packet loss/i', $line, $m)) {
            $idx = $lastIdxByModeSrc['P25|Net'] ?? null;
            if ($idx !== null && isset($rows[$idx])) {
                $rows[$idx]['dur'] = $m[1];
                $rows[$idx]['loss'] = $m[2];
            }
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
        } elseif (preg_match('/^\w:\s+[0-9:\-\. ]+\s+P25,\s+RF end of transmission,\s+([0-9.]+)\s+seconds,\s+([0-9.]+%)\s+packet loss/i', $line, $m)) {
            $idx = $lastIdxByModeSrc['P25|RF'] ?? null;
            if ($idx !== null && isset($rows[$idx])) {
                $rows[$idx]['dur'] = $m[1];
                $rows[$idx]['loss'] = $m[2];
            }
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
        } elseif (preg_match('/^\w:\s+[0-9:\-\. ]+\s+NXDN,\s+received (network|RF) end of transmission,\s+([0-9.]+)\s+seconds(?:,\s+BER:\s+([0-9.]+%))?/i', $line, $m)) {
            $src = strtoupper($m[1]) === 'NETWORK' ? 'Net' : 'RF';
            $idx = $lastIdxByModeSrc['NXDN|' . $src] ?? null;
            if ($idx !== null && isset($rows[$idx])) {
                $rows[$idx]['dur'] = $m[2];
                if (!empty($m[3])) {
                    $rows[$idx]['ber'] = $m[3];
                }
            }
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
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