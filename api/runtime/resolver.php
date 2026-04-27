<?php
declare(strict_types=1);

function dc_adapter_is_connected(array $adapter): bool {
    return (($adapter['connection_state'] ?? 'Idle') === 'Connected');
}

function dc_newer_adapter(array $a, array $b): array {
    $aEpoch = (int)($a['signal_epoch'] ?? 0);
    $bEpoch = (int)($b['signal_epoch'] ?? 0);
    return $aEpoch >= $bEpoch ? $a : $b;
}

function dc_resolve_active(array $abinfo, array $adapters, array &$stateCache = []): array {
    $liveMode = strtoupper((string)($abinfo['tlv']['ambe_mode'] ?? ''));

    $bmStfu  = $adapters['bm_stfu']      ?? dc_idle_adapter('BrandMeister');
    $bmStock = $adapters['bm_stock']     ?? dc_idle_adapter('BrandMeister');
    $ysf     = $adapters['ysf']          ?? dc_idle_adapter('YSF');
    $dstar   = $adapters['dstar']        ?? dc_idle_adapter('D-Star');
    $tgif    = $adapters['tgif_hblink']  ?? dc_idle_adapter('TGIF');
    $generic = $adapters['generic']      ?? dc_idle_adapter('Idle');

    if ($liveMode !== 'STFU') {
        unset($stateCache['bm_stfu']);
    }
    if (!str_starts_with($liveMode, 'YSF')) {
        unset($stateCache['ysf']);
    }
    if ($liveMode !== 'DSTAR') {
        unset($stateCache['dstar']);
    }
    if ($liveMode !== 'DMR') {
        unset($stateCache['bm_stock'], $stateCache['tgif_hblink']);
    }

    if ($liveMode === 'STFU') {
        return dc_adapter_is_connected($bmStfu) ? $bmStfu : dc_idle_adapter('Idle');
    }

    if (str_starts_with($liveMode, 'YSF')) {
        return dc_adapter_is_connected($ysf) ? $ysf : dc_idle_adapter('Idle');
    }

    if ($liveMode === 'DSTAR') {
        return dc_adapter_is_connected($dstar) ? $dstar : dc_idle_adapter('Idle');
    }

    if ($liveMode === 'DMR') {
        $tgifUp = dc_adapter_is_connected($tgif);
        $bmStfuUp = dc_adapter_is_connected($bmStfu);
        $bmStockUp = dc_adapter_is_connected($bmStock);

        // TGIF/HBLink and BM/STFU can both use the DMR TLV lane. TGIF wins
        // when its private audio link says it is truly connected. Otherwise,
        // allow BM/STFU to report connected even before the first PTT/voice row.
        if ($tgifUp) return $tgif;
        if ($bmStfuUp && $bmStockUp) {
            return dc_newer_adapter($bmStfu, $bmStock);
        }
        if ($bmStfuUp) return $bmStfu;
        if ($bmStockUp) return $bmStock;
        return dc_idle_adapter('Idle');
    }

    if (dc_adapter_is_connected($generic)) {
        return $generic;
    }

    return dc_idle_adapter('Idle');
}

function dc_resolve_active_adapter(array $abinfo, array $adapters, array &$stateCache = []): array {
    return dc_resolve_active($abinfo, $adapters, $stateCache);
}
