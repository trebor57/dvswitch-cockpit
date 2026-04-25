<?php
declare(strict_types=1);

function dc_adapter_ysf(array $bridgeLines, array $abinfo, array $cache, string $tzName): array {
    $rows = [];
    $currentTarget = '';
    $state = 'Idle';
    $lastSignal = 0;
    $lastHeard = '--';
    $lastGatewayIdx = null;
    $lastLocalIdx = null;
    $hasDisconnect = false;

    foreach ($bridgeLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);

        if (preg_match('/YSF,\s+Remote CMD:\s+txTg=(.+)$/i', $line, $m)) {
            $cmd = trim($m[1]);
            if (strcasecmp($cmd, 'disconnect') === 0) {
                $hasDisconnect = true;
            } else {
                $currentTarget = dc_clean_target($cmd);
                $hasDisconnect = false;
            }
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+YSF,\s+received network data from\s+(.+?)\s+to\s+(.+?)\s+at\s+(.+)$/i', $line, $m)) {
            $callsign = dc_clean_target($m[1]);
            $target = dc_clean_target($m[2]) . ' @ ' . dc_clean_target($m[3]);
            if ((stripos($target, 'ALL @') === 0 || strtoupper($target) === 'ALL') && $currentTarget !== '') {
                $target = $currentTarget;
            } elseif ((stripos($target, 'ALL @') === 0 || strtoupper($target) === 'ALL') && isset($cache['ysf']['target_display'])) {
                $target = (string)$cache['ysf']['target_display'];
            }
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'YSF', $callsign, $target, 'Net');
            $lastGatewayIdx = count($rows) - 1;
            $lastHeard = $callsign;
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+YSF,\s+received network end of transmission,\s+([0-9.]+)\s+seconds,\s+([0-9.]+%)\s+packet loss,\s+BER:\s+([0-9.]+%)/i', $line, $m)) {
            if ($lastGatewayIdx !== null && isset($rows[$lastGatewayIdx])) {
                $rows[$lastGatewayIdx]['dur'] = $m[1];
                $rows[$lastGatewayIdx]['loss'] = $m[2];
                $rows[$lastGatewayIdx]['ber'] = $m[3];
            }
            if ($lastLocalIdx !== null && isset($rows[$lastLocalIdx])) {
                $rows[$lastLocalIdx]['dur'] = $m[1];
                $rows[$lastLocalIdx]['loss'] = $m[2];
                $rows[$lastLocalIdx]['ber'] = $m[3];
            }
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }

        if (preg_match('/^\w:\s+[0-9:\-\. ]+\s+YSF,\s+Begin TX: src=([0-9]+)\s+rpt=([0-9]+)\s+dst=([0-9]+).*metadata=([A-Za-z0-9\-\/_ ]*)/i', $line, $m)) {
            $call = trim($m[4]) !== '' ? trim($m[4]) : trim($m[1]);
            $target = $currentTarget !== '' ? $currentTarget : (($cache['ysf']['target_display'] ?? '') ?: 'YSF');
            $rows[] = dc_make_row($stamp['utc'], $stamp['display'], 'YSF', $call, $target, 'LNet');
            $lastLocalIdx = count($rows) - 1;
            $lastSignal = max($lastSignal, (int)$stamp['epoch']);
            continue;
        }
    }

    $tlvMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $isYsfMode = str_starts_with($tlvMode, 'YSF');
    $recentTxTg = (string)($abinfo['_runtime']['latest_txtg']['value'] ?? '');
    $recentTxTgEpoch = (int)($abinfo['_runtime']['latest_txtg']['epoch'] ?? 0);
    $now = time();

    if ($currentTarget === '' && isset($cache['ysf']['target_display'])) {
        $currentTarget = (string)$cache['ysf']['target_display'];
    }

    $hasRecentTraffic = $lastSignal > 0 && (($now - $lastSignal) < 180);
    $recentIdleControl = $recentTxTg === '0' && $recentTxTgEpoch > 0 && (($now - $recentTxTgEpoch) < 120) && !$hasRecentTraffic;

    if ($isYsfMode && !$hasDisconnect && !$recentIdleControl && ($hasRecentTraffic || $currentTarget !== '')) {
        $state = 'Connected';
    } else {
        $state = 'Idle';
    }

    usort($rows, fn($a,$b) => strcmp((string)$b['utc'], (string)$a['utc']));
    return [
        'adapter' => 'ysf',
        'provider' => 'YSF',
        'network' => 'YSF',
        'connection_state' => $state,
        'path_label' => $state === 'Connected' ? 'YSF' : 'Idle',
        'target_display' => $currentTarget !== '' ? $currentTarget : '--',
        'target_note' => '(from YSFGateway / MMDVM_Bridge)',
        'last_heard' => $lastHeard !== '--' ? $lastHeard : (string)($cache['ysf']['last_heard'] ?? '--'),
        'rows' => array_slice($rows, 0, 40),
        'left_label' => 'Last Heard',
        'left_value' => $lastHeard !== '--' ? $lastHeard : (string)($cache['ysf']['last_heard'] ?? '--'),
        'signal_epoch' => $lastSignal,
    ];
}
