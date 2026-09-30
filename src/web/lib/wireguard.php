<?php
declare(strict_types=1);
// WireGuard client helpers: which peer names are allowed, where a client's config lives,
// and parsing `wg show wg0 dump`. Pure functions — api.php does the shelling out.

// A new client's name becomes a file name (<name>.conf) and is shown in the UI:
// Latin/Cyrillic letters, digits, _ and -, 1–32 characters. 'wg0' is the server's config.
function wg_peer_name_valid(string $name): bool {
    if ($name === 'wg0') return false;
    return preg_match('/^[A-Za-z0-9_\-А-Яа-яЁё]{1,32}$/u', $name) === 1;
}

// A WireGuard public key: 32 bytes in base64 (43 chars + '=').
function wg_pubkey_valid(string $key): bool {
    return preg_match('/^[A-Za-z0-9+\/]{43}=$/', $key) === 1;
}

// Path of an existing client config <dir>/<name>.conf, or null. Looks up existing files
// only (no slash, no leading dot, never wg0), so older peers whose file names predate
// wg_peer_name_valid() can still be shown, downloaded and deleted.
function wg_client_conf_path(string $dir, string $name): ?string {
    if ($name === '' || $name === 'wg0' || $name[0] === '.') return null;
    if (strpbrk($name, "/\\\0") !== false) return null;
    $path = "$dir/$name.conf";
    return is_file($path) ? $path : null;
}

// `wg show <if> dump`: first line is the interface, then one tab-separated line per peer:
// pubkey, psk, endpoint, allowed-ips, latest-handshake (unix, 0 = never), rx, tx, keepalive.
// @return array<string, array{handshake: int, rx: int, tx: int}> keyed by public key
function wg_parse_dump(string $dump): array {
    $peers = [];
    foreach (explode("\n", $dump) as $line) {
        $f = explode("\t", trim($line));
        if (count($f) < 8 || !wg_is_uint($f[4]) || !wg_is_uint($f[5])) continue;
        $peers[$f[0]] = ['handshake' => (int)$f[4], 'rx' => (int)$f[5], 'tx' => (int)$f[6]];
    }
    return $peers;
}

// Non-negative integer string (the router's PHP has no ctype extension).
function wg_is_uint(string $s): bool {
    return preg_match('/^\d+$/', $s) === 1;
}

// Byte count the way `wg show` prints it ("1.50 MiB") — kept for the legacy UI's rx/tx.
function wg_human_bytes(int $n): string {
    if ($n < 1024) return "$n B";
    $units = ['KiB', 'MiB', 'GiB', 'TiB'];
    $x = $n / 1024;
    $i = 0;
    while ($x >= 1024 && $i < count($units) - 1) {
        $x /= 1024;
        $i++;
    }
    return sprintf('%.2f %s', $x, $units[$i]);
}

// Handshake age the way `wg show` prints it ("2 minutes, 5 seconds ago").
function wg_human_ago(int $seconds): string {
    if ($seconds <= 0) return 'Now';
    $parts = [];
    foreach ([['day', 86400], ['hour', 3600], ['minute', 60], ['second', 1]] as [$unit, $len]) {
        $n = intdiv($seconds, $len);
        $seconds %= $len;
        if ($n > 0) $parts[] = $n . ' ' . $unit . ($n === 1 ? '' : 's');
    }
    return implode(', ', $parts) . ' ago';
}

// The <svg>…</svg> element from `qrencode -t SVG` output (XML prolog dropped), or null
// when the output is not a complete SVG — old qrencode builds without SVG print an error.
function wg_qr_svg_extract(string $out): ?string {
    $start = strpos($out, '<svg');
    $end = strrpos($out, '</svg>');
    if ($start === false || $end === false || $end < $start) return null;
    return substr($out, $start, $end - $start + strlen('</svg>'));
}
