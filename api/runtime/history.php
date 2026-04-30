<?php
declare(strict_types=1);

function dc_history_path(): string { return '/tmp/dvswitch_cockpit_gateway_history.json'; }
function dc_state_cache_path(): string { return '/tmp/dvswitch_cockpit_runtime_state.json'; }

function dc_load_history(): array {
    $file = dc_history_path();
    if (!is_readable($file)) return ['signature' => '', 'rows' => []];
    $json = json_decode((string)@file_get_contents($file), true);
    if (!is_array($json)) return ['signature' => '', 'rows' => []];
    if (!isset($json['rows']) || !is_array($json['rows'])) $json['rows'] = [];
    if (!isset($json['signature'])) $json['signature'] = '';
    return $json;
}
function dc_save_history(array $payload): void {
    @file_put_contents(dc_history_path(), json_encode($payload, JSON_PRETTY_PRINT));
}
function dc_merge_value_is_useful($value): bool {
    $value = trim((string)$value);
    return $value !== '' && $value !== '--' && $value !== '-';
}

function dc_merge_prefer_useful_row(array $preferred, array $fallback): array {
    // Keep the newest/current row shape, but do not lose useful duration,
    // quality, or lookup fields when another adapter pass returns the same
    // row with blanks. This prevents mode switches from turning Dur(s) back
    // into -- for already-known activity rows.
    foreach (['dur', 'loss', 'ber', 'callsign_display', 'dmr_id', 'qrz_url', 'callsign_lookup'] as $field) {
        if (!dc_merge_value_is_useful($preferred[$field] ?? '') && dc_merge_value_is_useful($fallback[$field] ?? '')) {
            $preferred[$field] = $fallback[$field];
        }
    }
    return $preferred;
}

function dc_history_row_is_local(array $row): bool {
    return strtoupper((string)($row['src'] ?? '')) === 'LNET';
}

function dc_history_dedupe_local_rows(array $rows): array {
    $seen = [];
    $out = [];
    foreach ($rows as $row) {
        if (dc_history_row_is_local($row)) {
            $key = implode('|', [
                (string)($row['utc'] ?? ''),
                (string)($row['mode'] ?? ''),
                (string)($row['callsign'] ?? ''),
                (string)($row['src'] ?? ''),
                (string)($row['dur'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
        }
        $out[] = $row;
    }
    return $out;
}

function dc_merge_history(array $existing, array $current): array {
    $map = [];
    foreach (array_merge($current, $existing) as $row) {
        $key = implode('|', [
            (string)($row['utc'] ?? ''),
            (string)($row['mode'] ?? ''),
            (string)($row['callsign'] ?? ''),
            (string)($row['target'] ?? ''),
            (string)($row['src'] ?? ''),
        ]);

        if (!isset($map[$key])) {
            $map[$key] = $row;
        } else {
            $map[$key] = dc_merge_prefer_useful_row($map[$key], $row);
        }
    }
    $rows = array_values($map);
    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));
    $rows = dc_history_dedupe_local_rows($rows);
    return array_slice($rows, 0, 120);
}
function dc_history_duplicate_key(array $row): string {
    $station = (string)($row['callsign_display'] ?? ($row['callsign'] ?? ''));
    return implode('|', [
        (string)($row['utc'] ?? ''),
        trim($station),
        (string)($row['target'] ?? ''),
        (string)($row['src'] ?? ''),
    ]);
}

function dc_history_remove_cross_mode_duplicates(array $rows): array {
    $digitalKeys = [];

    foreach ($rows as $row) {
        $mode = (string)($row['mode'] ?? '');
        if ($mode === 'NXDN' || $mode === 'P25') {
            $digitalKeys[dc_history_duplicate_key($row)] = true;
        }
    }

    if (!$digitalKeys) {
        return $rows;
    }

    $out = [];
    foreach ($rows as $row) {
        $mode = (string)($row['mode'] ?? '');
        if ($mode === 'DMR/BM' && isset($digitalKeys[dc_history_duplicate_key($row)])) {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

function dc_history_target_digits(string $target): string {
    return preg_replace('/\D+/', '', $target) ?? '';
}

function dc_history_display_station(array $row): string {
    return trim((string)($row['callsign_display'] ?? $row['callsign'] ?? ''));
}

function dc_history_parrot_display_key(array $row): string {
    $mode = strtoupper((string)($row['mode'] ?? ''));
    $src = strtoupper((string)($row['src'] ?? ''));
    $target = (string)($row['target'] ?? '');
    $targetDigits = dc_history_target_digits($target);

    // P25/NXDN talkback/parrot rows can appear multiple ways in history:
    // PARROT, 10999, 9999, or the local callsign. Display one stock-style row.
    if ($src === 'NET' && ($mode === 'P25' || $mode === 'NXDN') && $targetDigits === '10') {
        return implode('|', [
            (string)($row['utc'] ?? ''),
            $mode,
            'PARROT_TG10',
            $target,
            $src,
            (string)($row['dur'] ?? ''),
        ]);
    }

    // P25 TG 9999 is commonly shown by stock DVSwitch as MMDVM.
    if ($src === 'NET' && $mode === 'P25' && $targetDigits === '9999') {
        return implode('|', [
            (string)($row['utc'] ?? ''),
            $mode,
            'MMDVM_TG9999',
            $target,
            $src,
            (string)($row['dur'] ?? ''),
        ]);
    }

    return implode('|', [
        (string)($row['utc'] ?? ''),
        $mode,
        dc_history_display_station($row),
        $target,
        $src,
        (string)($row['dur'] ?? ''),
    ]);
}

function dc_history_display_row_score(array $row): int {
    $mode = strtoupper((string)($row['mode'] ?? ''));
    $src = strtoupper((string)($row['src'] ?? ''));
    $targetDigits = dc_history_target_digits((string)($row['target'] ?? ''));
    $station = strtoupper(dc_history_display_station($row));

    $score = 0;

    if ($src === 'LNET') {
        $score += 20;
    }

    if ($src === 'NET' && ($mode === 'P25' || $mode === 'NXDN') && $targetDigits === '10') {
        if ($station === 'PARROT') {
            $score += 100;
        } elseif (preg_match('/^\d+$/', $station)) {
            $score += 10;
        }
    }

    if ($src === 'NET' && $mode === 'P25' && $targetDigits === '9999') {
        if ($station === 'MMDVM') {
            $score += 100;
        } elseif (preg_match('/^\d+$/', $station)) {
            $score += 10;
        }
    }

    if (dc_merge_value_is_useful($row['dur'] ?? '')) {
        $score += 5;
    }
    if (dc_merge_value_is_useful($row['loss'] ?? '') || dc_merge_value_is_useful($row['ber'] ?? '')) {
        $score += 3;
    }

    return $score;
}

function dc_history_display_rows(array $rows, int $limit = 16): array {
    $rows = dc_history_remove_cross_mode_duplicates($rows);

    $deduped = [];
    foreach ($rows as $row) {
        $key = dc_history_parrot_display_key($row);

        if (!isset($deduped[$key])) {
            $deduped[$key] = $row;
            continue;
        }

        $old = $deduped[$key];

        if (dc_history_display_row_score($row) > dc_history_display_row_score($old)) {
            $deduped[$key] = dc_merge_prefer_useful_row($row, $old);
        } else {
            $deduped[$key] = dc_merge_prefer_useful_row($old, $row);
        }
    }

    $deduped = array_values($deduped);
    usort($deduped, fn($a, $b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    return array_slice($deduped, 0, $limit);
}

function dc_load_state_cache(): array {
    $f = dc_state_cache_path();
    if (!is_readable($f)) return [];
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function dc_save_state_cache(array $state): void {
    @file_put_contents(dc_state_cache_path(), json_encode($state, JSON_PRETTY_PRINT));
}
?>