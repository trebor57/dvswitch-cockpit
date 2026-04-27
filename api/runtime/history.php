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
function dc_history_display_rows(array $rows, int $limit = 16): array {
    usort($rows, fn($a,$b) => strcmp((string)($b['utc'] ?? ''), (string)($a['utc'] ?? '')));

    $out = [];
    $used = [];
    $perModeLimit = 6;

    foreach ($rows as $idx => $row) {
        $mode = (string)($row['mode'] ?? 'unknown');
        $used[$mode] = $used[$mode] ?? 0;

        if ($used[$mode] >= $perModeLimit) {
            continue;
        }

        $out[] = $row;
        $used[$mode]++;
        if (count($out) >= $limit) {
            return $out;
        }
    }

    foreach ($rows as $row) {
        if (count($out) >= $limit) break;

        $key = implode('|', [
            (string)($row['utc'] ?? ''),
            (string)($row['mode'] ?? ''),
            (string)($row['callsign'] ?? ''),
            (string)($row['target'] ?? ''),
            (string)($row['src'] ?? ''),
        ]);

        $already = false;
        foreach ($out as $existing) {
            $existingKey = implode('|', [
                (string)($existing['utc'] ?? ''),
                (string)($existing['mode'] ?? ''),
                (string)($existing['callsign'] ?? ''),
                (string)($existing['target'] ?? ''),
                (string)($existing['src'] ?? ''),
            ]);
            if ($existingKey === $key) {
                $already = true;
                break;
            }
        }

        if (!$already) {
            $out[] = $row;
        }
    }

    return array_slice($out, 0, $limit);
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