<?php
declare(strict_types=1);

function dc_tgif_clean_station(string $value): string {
    $value = strtoupper(trim($value));
    if ($value === '' || $value === 'NONE' || $value === 'UNKNOWN') return '';
    return $value;
}

function dc_tgif_should_hide_net_identity(string $src, string $dst, string $gateway, string $target, string $slot, string $cc): bool {
    $src = preg_replace('/\D+/', '', $src) ?? '';
    $dst = preg_replace('/\D+/', '', $dst) ?? '';
    $gateway = preg_replace('/\D+/', '', $gateway) ?? '';
    $target = preg_replace('/\D+/', '', $target) ?? '';

    // Keep parrot readable.
    if ($target === '9990' || $src === '9990' || $dst === '9990') return false;

    // For non-parrot TGIF network RX, the backend may expose an upstream/gateway
    // identity instead of the true speaker. Keep the RX event and target visible,
    // but do not present that identity as a confirmed caller.
    if ($slot === '2' && $cc === '0') {
        return true;
    }

    return false;
}

function dc_tgif_rx_row(string $utc, string $display, string $target, string $dur = '--'): array {
    $row = dc_make_row($utc, $display, 'DMR/TGIF', 'TGIF RX', 'TG ' . $target, 'Net', $dur);
    $row['callsign_display'] = 'TGIF RX';
    unset($row['dmr_id'], $row['qrz_url'], $row['callsign_lookup']);
    $row['identity_confidence'] = 'network_rx_only';
    return $row;
}

function dc_tgif_active_mmdvm_path(): array {
    $path = '/opt/MMDVM_Bridge/MMDVM_Bridge.ini';
    $out = ['mode' => 'unknown', 'address' => '', 'port' => ''];

    if (!is_readable($path)) return $out;

    $section = '';
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim(preg_replace('/[;#].*$/', '', (string)$line));
        if ($line === '') continue;

        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $section = strtolower(trim($m[1]));
            continue;
        }

        if ($section !== 'dmr network') continue;

        if (preg_match('/^Address\s*=\s*(.+)$/i', $line, $m)) {
            $out['address'] = strtolower(trim($m[1]));
        } elseif (preg_match('/^Port\s*=\s*(.+)$/i', $line, $m)) {
            $out['port'] = trim($m[1]);
        }
    }

    if (in_array($out['address'], ['127.0.0.1', 'localhost'], true) && $out['port'] === '62033') {
        $out['mode'] = 'hblink';
    } elseif (str_contains($out['address'], 'tgif.network') || $out['port'] === '62031') {
        $out['mode'] = 'direct_tgif';
    }

    return $out;
}

function dc_adapter_tgif_hblink(array $analogLines, array $abinfo, array $services, string $tzName): array {
    $rows = [];
    $currentTarget = '';
    $lastHeard = '--';
    $lastSignal = 0;
    $lastConnectSignal = 0;
    $lastDisconnectSignal = 0;
    $lastIdx = null;
    $lastIdxEpoch = 0;
    $lastIdxWasMaskedNetRx = false;
    $targetSource = '';
    $targetCameFromGatewayCorrectedFrame = false;
    $modeContext = '';

    $liveMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));
    $gateway = trim((string)($abinfo['digital']['gw'] ?? ''));
    $localCall = trim((string)($abinfo['digital']['call'] ?? ''));
    $hblinkActive = in_array(($services['hblink'] ?? ''), ['active', 'activating'], true)
        || !empty($services['hblink_process']);

    $privateLink = is_array($abinfo['_runtime']['private_audio_link'] ?? null)
        ? $abinfo['_runtime']['private_audio_link']
        : [];
    $privateLinkKnown = !empty($privateLink['known']) && trim((string)($privateLink['dvswitch_node'] ?? '')) !== '';
    $privateLinkActive = !empty($privateLink['linked']);
    $privateLinkNode = trim((string)($privateLink['dvswitch_node'] ?? ''));

    $localStation = function () use ($gateway, $localCall): string {
        if ($localCall !== '') return $localCall;
        if ($gateway !== '') return $gateway;
        return 'Local';
    };

    // TGIF/HBLink is a DMR-lane display adapter. It does not connect or disconnect anything.
    if ($liveMode !== 'DMR') {
        return dc_idle_adapter('TGIF');
    }

    $configuredTarget = trim((string)($abinfo['_runtime']['tgif_hblink_target']['value'] ?? ''));
    $configuredSource = trim((string)($abinfo['_runtime']['tgif_hblink_target']['source'] ?? ''));

    $activeMmdvmPath = dc_tgif_active_mmdvm_path();
    $useHblinkConfig = ($activeMmdvmPath['mode'] === 'hblink');

    // hblink.cfg is the best source for the selected TGIF room number, but it is
    // NOT proof that the room is still connected. The helper may leave StartRef in
    // place after disconnect, so state must come from live service/log evidence.
    if ($useHblinkConfig && $configuredTarget !== '' && $configuredTarget !== '0' && ($gateway === '' || $configuredTarget !== $gateway)) {
        $currentTarget = $configuredTarget;
        $targetSource = 'hblink_config';
    }

    $latestTxTg = (string)($abinfo['_runtime']['latest_txtg']['value'] ?? '');
    $latestTxTgEpoch = (int)($abinfo['_runtime']['latest_txtg']['epoch'] ?? 0);
    $latestTxTgDisconnect = (bool)($abinfo['_runtime']['latest_txtg']['disconnect'] ?? false);

    if ($latestTxTgDisconnect && $latestTxTgEpoch > 0) {
        $lastDisconnectSignal = max($lastDisconnectSignal, $latestTxTgEpoch);
    }

    // Only use txTg when it is a real TG, not the local gateway/private ID.
    if (!$latestTxTgDisconnect && $latestTxTg !== '' && $latestTxTg !== '0' && ($gateway === '' || $latestTxTg !== $gateway)) {
        if ($currentTarget === '') {
            $currentTarget = $latestTxTg;
            $targetSource = 'txtg';
        }
        $lastConnectSignal = max($lastConnectSignal, $latestTxTgEpoch);
        $lastSignal = max($lastSignal, $latestTxTgEpoch);
    }

    foreach ($analogLines as $line) {
        $stamp = dc_parse_log_dt($line, $tzName);
        $epoch = (int)($stamp['epoch'] ?? 0);

        if (preg_match('/MESSAGE packet sent to USRP client:\s+Setting mode to\s+([A-Z0-9_-]+)/i', $line, $m)) {
            $modeContext = strtoupper(trim($m[1]));
        } elseif (preg_match('/\bambeMode\s*=\s*([A-Z0-9_-]+)/i', $line, $m)) {
            $modeContext = strtoupper(trim($m[1]));
        }

        if (preg_match('/EVENT:\s+\{"topic":"dvswitch\/MMDVM_Bridge\/DMR","message":"login success"\}/', $line)) {
            if ($currentTarget !== '' && $currentTarget !== '0' && ($gateway === '' || $currentTarget !== $gateway)) {
                $lastConnectSignal = max($lastConnectSignal, $epoch);
                $lastSignal = max($lastSignal, $epoch);
            }
            continue;
        }

        if (preg_match('/\btxTg\s*=?\s*:?\s*([0-9#]+)/i', $line, $m)) {
            $raw = trim($m[1]);
            $tg = rtrim($raw, '#');

            if (str_ends_with($raw, '#') || $tg === '' || $tg === '0') {
                $lastDisconnectSignal = max($lastDisconnectSignal, $epoch);
                continue;
            }

            if ($gateway === '' || $tg !== $gateway) {
                if ($targetSource !== 'hblink_config') {
                    $currentTarget = $tg;
                    $targetSource = 'txtg';
                    $targetCameFromGatewayCorrectedFrame = false;
                }
                $lastConnectSignal = max($lastConnectSignal, $epoch);
                $lastSignal = max($lastSignal, $epoch);
            }
            continue;
        }

        if (preg_match('/Begin TX:\s*src=([0-9]+)\s+rpt=([0-9]+)\s+dst=([0-9]+)\s+slot=([0-9]+)\s+cc=([0-9]+)\s+call=([A-Z0-9\-]*)/i', $line, $m)) {
            $src = trim($m[1]);
            $call = trim($m[6]) !== '' ? trim($m[6]) : $src;
            $dst = trim($m[3]);
            $slot = trim($m[4]);
            $cc = trim($m[5]);

            // BM/stock DMR commonly appears as slot=1 cc=1.
            // Do not let those rows duplicate as DMR/TGIF.
            if ($slot === '1' && $cc === '1') {
                continue;
            }

            $rowTarget = '';

            // TGIF/HBLink RX frames often use dst=<local gateway ID> and
            // src=<station DMR ID>.  In that case src is the station, not the
            // TGIF room.  Prefer the selected HBLink target for the Target
            // column.  Fall back to src only when there is no selected target.
            if ($gateway !== '' && $dst === $gateway && $src !== $gateway) {
                if ($currentTarget !== '' && $currentTarget !== '0' && $currentTarget !== $gateway) {
                    $rowTarget = $currentTarget;
                } elseif ($useHblinkConfig && $configuredTarget !== '' && $configuredTarget !== '0' && $configuredTarget !== $gateway) {
                    $rowTarget = $configuredTarget;
                    $currentTarget = $configuredTarget;
                    $targetSource = 'hblink_config';
                } else {
                    $rowTarget = $src;
                    if ($targetSource !== 'hblink_config') {
                        $currentTarget = $src;
                        $targetSource = 'gateway_corrected_frame';
                        $targetCameFromGatewayCorrectedFrame = true;
                    }
                }
            } elseif ($dst !== '' && $dst !== '0' && ($gateway === '' || $dst !== $gateway)) {
                $rowTarget = $dst;
                if ($currentTarget === '') {
                    $currentTarget = $dst;
                    $targetSource = 'frame_dst';
                }
            } elseif ($currentTarget !== '') {
                $rowTarget = $currentTarget;
            }

            if ($rowTarget === '' || $rowTarget === '0' || ($gateway !== '' && $rowTarget === $gateway)) {
                continue;
            }

            // If the active runtime says TGIF/HBLink is using StartRef, that is
            // the selected TGIF room. Some DVSwitch/HBLink paths still expose
            // Analog_Bridge txTg/dst from an older or startup value, such as
            // 19570. Do not let that stale dst override the active TGIF target.
            if ($useHblinkConfig && $configuredTarget !== '' && $configuredTarget !== '0' && ($gateway === '' || $configuredTarget !== $gateway)) {
                $rowTarget = $configuredTarget;
            }

            $slot = trim($m[4]);
            $cc = trim($m[5]);
            $maskNetIdentity = dc_tgif_should_hide_net_identity($src, $dst, $gateway, $rowTarget, $slot, $cc);

            if ($maskNetIdentity) {
                $rows[] = dc_tgif_rx_row(
                    (string)($stamp['utc'] ?? ''),
                    (string)($stamp['display'] ?? '--'),
                    $rowTarget
                );
                $lastHeard = 'TGIF RX';
                $lastIdxWasMaskedNetRx = true;
            } else {
                $safeCall = dc_tgif_clean_station($call);
                if ($safeCall === '') {
                    $safeCall = $src !== '' ? $src : 'TGIF RX';
                }

                $rows[] = dc_make_row(
                    (string)($stamp['utc'] ?? ''),
                    (string)($stamp['display'] ?? '--'),
                    'DMR/TGIF',
                    $safeCall,
                    'TG ' . $rowTarget,
                    'Net'
                );
                $lastHeard = $safeCall;
                $lastIdxWasMaskedNetRx = false;
            }

            $lastIdx = count($rows) - 1;
            $lastIdxEpoch = $epoch;
            $lastConnectSignal = max($lastConnectSignal, $epoch);
            $lastSignal = max($lastSignal, $epoch);
            continue;
        }

        if (preg_match('/PTT off \(keyed for ([0-9]+) ms\)/i', $line, $m)) {
            $dur = number_format(((int)$m[1]) / 1000, 2, '.', '');
            if ($lastIdx !== null && isset($rows[$lastIdx]) && $lastIdxEpoch > 0 && ($epoch - $lastIdxEpoch) <= 15) {
                $rows[$lastIdx]['dur'] = $dur;
                $lastSignal = max($lastSignal, $epoch);
            } else {
                $localTarget = '';
                if ($useHblinkConfig && $configuredTarget !== '' && $configuredTarget !== '0' && ($gateway === '' || $configuredTarget !== $gateway)) {
                    $localTarget = $configuredTarget;
                } elseif ($currentTarget !== '' && $currentTarget !== '0' && ($gateway === '' || $currentTarget !== $gateway)) {
                    $localTarget = $currentTarget;
                }

                if ($modeContext === 'DMR' && $localTarget !== '') {
                    $rows[] = dc_make_row(
                        (string)($stamp['utc'] ?? ''),
                        (string)($stamp['display'] ?? '--'),
                        'DMR/TGIF',
                        $localStation(),
                        'TG ' . $localTarget,
                        'LNet',
                        $dur
                    );
                    $lastIdx = count($rows) - 1;
                    $lastIdxEpoch = $epoch;
                    $lastHeard = $localStation();
                    $lastIdxWasMaskedNetRx = false;
                    $lastConnectSignal = max($lastConnectSignal, $epoch);
                    $lastSignal = max($lastSignal, $epoch);
                }
            }
        }
    }

    $hasCurrentTarget = $currentTarget !== '' && $currentTarget !== '0' && ($gateway === '' || $currentTarget !== $gateway);
    $hasRecentConnectSignal = dc_is_recent_epoch($lastConnectSignal, 90);
    $disconnectWins = $lastDisconnectSignal > 0 && $lastDisconnectSignal >= $lastConnectSignal;

    $state = 'Idle';
    if ($privateLinkKnown) {
        if ($privateLinkActive && $hasCurrentTarget && !$disconnectWins) {
            $state = 'Connected';
        }
    } elseif ($hasCurrentTarget && !$disconnectWins && $hasRecentConnectSignal) {
        $state = 'Connected';
    }

    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    $note = '(from TGIF/HBLink runtime; TGIF Net caller identity is hidden when only a gateway/upstream ID is exposed)';
    if ($targetSource === 'hblink_config') {
        $note = '(from TGIF/HBLink StartRef in hblink.cfg)';
    } elseif ($targetSource === 'gateway_corrected_frame') {
        $note = '(from TGIF/HBLink frame; gateway ID corrected)';
    } elseif ($targetSource === 'txtg') {
        $note = '(from Analog_Bridge txTg)';
    }
    if ($disconnectWins) {
        $note = '(TGIF idle/disconnected; last txTg was 0 or disconnect marker)';
    } elseif ($privateLinkKnown && !$privateLinkActive) {
        $note = '(TGIF idle; private DVSwitch audio node ' . $privateLinkNode . ' is not linked)';
    } elseif ($privateLinkKnown && $privateLinkActive && $targetSource === 'hblink_config') {
        $note = '(from TGIF/HBLink StartRef in hblink.cfg; private audio node linked; TGIF Net caller may be RX-only if upstream does not expose individual ID)';
    } elseif ($state !== 'Connected' && $hasCurrentTarget && $targetSource === 'hblink_config') {
        $note = '(TGIF target remembered in hblink.cfg, but no current private-link/live evidence)';
    }

    $displayTarget = ($state === 'Connected' && $hasCurrentTarget) ? ('TG ' . $currentTarget) : '--';

    return [
        'adapter' => 'tgif_hblink',
        'provider' => 'TGIF/HBLink',
        'network' => 'TGIF',
        'connection_state' => $state,
        'path_label' => $state === 'Connected' ? 'HBLink + DMR' : 'Idle',
        'target_display' => $displayTarget,
        'target_note' => $note,
        'last_heard' => $state === 'Connected' ? $lastHeard : '--',
        'rows' => $state === 'Connected' ? array_slice($rows, 0, 40) : [],
        'left_label' => 'Current TG',
        'left_value' => $displayTarget,
        'signal_epoch' => $state === 'Connected' ? $lastSignal : 0,
        'debug_target_source' => $configuredSource,
        'debug_last_connect_epoch' => $lastConnectSignal,
        'debug_last_disconnect_epoch' => $lastDisconnectSignal,
        'debug_hblink_active' => $hblinkActive,
        'debug_active_mmdvm_mode' => $activeMmdvmPath['mode'],
        'debug_active_mmdvm_address' => $activeMmdvmPath['address'],
        'debug_active_mmdvm_port' => $activeMmdvmPath['port'],
        'debug_recent_connect_signal' => $hasRecentConnectSignal,
        'debug_private_link_known' => $privateLinkKnown,
        'debug_private_link_active' => $privateLinkActive,
        'debug_private_link_node' => $privateLinkNode,
    ];
}
