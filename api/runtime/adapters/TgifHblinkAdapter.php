<?php
declare(strict_types=1);

function dc_adapter_tgif_hblink(array $analogLines, array $abinfo, array $services, string $tzName): array {
    $rows = [];
    $currentTarget = '';
    $lastHeard = '--';
    $lastSignal = 0;
    $lastConnectSignal = 0;
    $lastDisconnectSignal = 0;
    $lastIdx = null;
    $lastIdxEpoch = 0;
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

    // hblink.cfg is the best source for the selected TGIF room number, but it is
    // NOT proof that the room is still connected. The helper may leave StartRef in
    // place after disconnect, so state must come from live service/log evidence.
    if ($configuredTarget !== '' && $configuredTarget !== '0' && ($gateway === '' || $configuredTarget !== $gateway)) {
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
            // Login is useful live evidence only when we already have a real TGIF target.
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
                // txTg 0 / txTg # is disconnect/idle evidence. Do not count it as
                // TGIF activity and do not let stale hblink.cfg override it.
                $lastDisconnectSignal = max($lastDisconnectSignal, $epoch);
                continue;
            }

            if ($gateway === '' || $tg !== $gateway) {
                // Do not let Analog_Bridge txTg overwrite the configured HBLink target.
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
            $rowTarget = '';

            // Known TGIF/HBLink quirk: dst/txTg can be the local gateway ID while
            // the TGIF room is carried in src, e.g. src=9990 ... dst=<gateway>.
            if ($gateway !== '' && $dst === $gateway && $src !== $gateway) {
                $rowTarget = $src;
                if ($targetSource !== 'hblink_config') {
                    $currentTarget = $src;
                    $targetSource = 'gateway_corrected_frame';
                    $targetCameFromGatewayCorrectedFrame = true;
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

            $rows[] = dc_make_row(
                (string)($stamp['utc'] ?? ''),
                (string)($stamp['display'] ?? '--'),
                'DMR/TGIF',
                $call,
                'TG ' . $rowTarget,
                'Net'
            );
            $lastIdx = count($rows) - 1;
            $lastIdxEpoch = $epoch;
            $lastHeard = $call;
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
                // Local TGIF/HBLink key-ups often only appear as Analog_Bridge
                // PTT on/off lines. There may be no matching TGIF/HBLink Begin TX
                // row for the local side. Use the selected HBLink StartRef when
                // available; do not reuse a recent remote/parrot Begin TX target
                // such as 9990 for the local row when the node is actually linked
                // to another TGIF room.
                $localTarget = '';
                if ($configuredTarget !== '' && $configuredTarget !== '0' && ($gateway === '' || $configuredTarget !== $gateway)) {
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
                    $lastConnectSignal = max($lastConnectSignal, $epoch);
                    $lastSignal = max($lastSignal, $epoch);
                }
            }
        }
    }

    $hasCurrentTarget = $currentTarget !== '' && $currentTarget !== '0' && ($gateway === '' || $currentTarget !== $gateway);
    $hasRecentConnectSignal = dc_is_recent_epoch($lastConnectSignal, 90);

    // A recent/latest explicit disconnect must beat stale hblink.cfg, stale rows,
    // and a persistent HBLink process. Crucially, HBLink still running and
    // StartRef still present are NOT proof of an active TGIF connection. AllTune2
    // can leave the sidecar/config behind after Disconnect, so this adapter now
    // requires recent live TGIF evidence after any disconnect marker.
    $disconnectWins = $lastDisconnectSignal > 0 && $lastDisconnectSignal >= $lastConnectSignal;

    $state = 'Idle';

    // The important guard for AllTune2/HBLink:
    // HBLink may continue to run and may continue to receive TGIF network frames
    // even after the user disconnects the local/private DVSwitch audio node.
    // Therefore TGIF is only truly active when the private audio link is up.
    if ($privateLinkKnown) {
        if ($privateLinkActive && $hasCurrentTarget && !$disconnectWins) {
            $state = 'Connected';
        }
    } elseif ($hasCurrentTarget && !$disconnectWins && $hasRecentConnectSignal) {
        // Fallback for systems where the cockpit cannot read Asterisk rpt nodes.
        // This is intentionally weaker than the private-link truth above.
        $state = 'Connected';
    }

    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    $note = '(from TGIF/HBLink runtime)';
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
        $note = '(from TGIF/HBLink StartRef in hblink.cfg; private audio node linked)';
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
        'rows' => array_slice($rows, 0, 40),
        'left_label' => 'Current TG',
        'left_value' => $displayTarget,
        'signal_epoch' => $state === 'Connected' ? $lastSignal : 0,
        'debug_target_source' => $configuredSource,
        'debug_last_connect_epoch' => $lastConnectSignal,
        'debug_last_disconnect_epoch' => $lastDisconnectSignal,
        'debug_hblink_active' => $hblinkActive,
        'debug_recent_connect_signal' => $hasRecentConnectSignal,
        'debug_private_link_known' => $privateLinkKnown,
        'debug_private_link_active' => $privateLinkActive,
        'debug_private_link_node' => $privateLinkNode,
    ];
}
