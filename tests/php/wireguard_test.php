<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/web/lib/wireguard.php';

// ---- wg_peer_name_valid() -----------------------------------------------------

function test_wg_peer_name_valid_accepts_latin_cyrillic_digits(): void {
    foreach (['iPhone', 'Mac_User', 'tv-1', 'Телефон', 'Ёлка_2', 'a', str_repeat('я', 32)] as $n) {
        eq(wg_peer_name_valid($n), true, "valid: $n");
    }
}

function test_wg_peer_name_valid_rejects_shell_and_path_chars(): void {
    foreach (["a'b", 'a b', 'a;b', 'a"b', 'a$b', 'a`b', 'a/b', '../x', 'a.b', "a\nb", 'a|b',
        'a&b', 'a\\b', "a\0b"] as $n) {
        eq(wg_peer_name_valid($n), false, 'rejected: ' . json_encode($n));
    }
}

function test_wg_peer_name_valid_length_and_empty(): void {
    eq(wg_peer_name_valid(''), false, 'empty');
    eq(wg_peer_name_valid(str_repeat('a', 33)), false, '33 latin chars');
    eq(wg_peer_name_valid(str_repeat('я', 33)), false, '33 cyrillic chars (counted as chars)');
    eq(wg_peer_name_valid("\xff\xfe"), false, 'invalid UTF-8');
    eq(wg_peer_name_valid('wg0'), false, 'server interface name is reserved');
    eq(wg_peer_name_valid('ñandú'), false, 'other scripts rejected');
}

// ---- wg_pubkey_valid() --------------------------------------------------------

function test_wg_pubkey_valid(): void {
    eq(wg_pubkey_valid('xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg='), true, 'real-looking key');
    eq(wg_pubkey_valid('xTIBA5rboUvnH4htodjb6e697QjLERt1NAB4mZqp8Dg'), false, 'no padding');
    eq(wg_pubkey_valid("x' ; rm -rf / #AAAAAAAAAAAAAAAAAAAAAAAAAAAA="), false, 'shell chars');
    eq(wg_pubkey_valid(''), false, 'empty');
}

// ---- wg_client_conf_path() ----------------------------------------------------

function test_wg_client_conf_path_existing_only(): void {
    $dir = sys_get_temp_dir() . '/wgtest_' . getmypid();
    @mkdir($dir);
    file_put_contents("$dir/wg0.conf", "[Interface]\n");
    file_put_contents("$dir/Телефон.conf", "[Interface]\n");
    file_put_contents("$dir/my.phone.conf", "[Interface]\n");
    try {
        eq(wg_client_conf_path($dir, 'Телефон'), "$dir/Телефон.conf", 'cyrillic peer found');
        eq(wg_client_conf_path($dir, 'my.phone'), "$dir/my.phone.conf",
            'legacy file name outside the new rules still resolves');
        eq(wg_client_conf_path($dir, 'wg0'), null, 'server config is not a client');
        eq(wg_client_conf_path($dir, 'nope'), null, 'missing file');
        eq(wg_client_conf_path($dir, '../wgtest_' . getmypid() . '/Телефон'), null, 'no slashes');
        eq(wg_client_conf_path($dir, ''), null, 'empty');
        eq(wg_client_conf_path($dir, '.x'), null, 'no dot-files');
    } finally {
        foreach (glob("$dir/*") as $f) unlink($f);
        rmdir($dir);
    }
}

// ---- wg_parse_dump() ----------------------------------------------------------

function test_wg_parse_dump_peers(): void {
    $dump = "cPriv=\tsPub=\t500\toff\n"
        . "PEER1key=\t(none)\t1.2.3.4:5555\t10.50.0.2/32\t1727690000\t1048576\t2048\t25\n"
        . "PEER2key=\t(none)\t(none)\t10.50.0.3/32\t0\t0\t0\toff\n";
    eq(wg_parse_dump($dump), [
        'PEER1key=' => ['handshake' => 1727690000, 'rx' => 1048576, 'tx' => 2048],
        'PEER2key=' => ['handshake' => 0, 'rx' => 0, 'tx' => 0],
    ], 'interface line skipped, peers keyed by pubkey');
    eq(wg_parse_dump(''), [], 'no output');
    eq(wg_parse_dump("Unable to access interface: No such device"), [], 'error text');
}

// ---- wg_human_bytes() / wg_human_ago() (legacy wg_peers strings) --------------

function test_wg_human_bytes(): void {
    eq(wg_human_bytes(0), '0 B', 'zero');
    eq(wg_human_bytes(512), '512 B', 'bytes');
    eq(wg_human_bytes(2048), '2.00 KiB', 'KiB');
    eq(wg_human_bytes(1048576 * 3 / 2), '1.50 MiB', 'MiB');
}

function test_wg_human_ago(): void {
    eq(wg_human_ago(5), '5 seconds ago', 'seconds');
    eq(wg_human_ago(125), '2 minutes, 5 seconds ago', 'minutes');
    eq(wg_human_ago(3600), '1 hour ago', 'one hour');
    eq(wg_human_ago(0), 'Now', 'now');
}

// ---- wg_qr_svg_extract() ------------------------------------------------------

function test_wg_qr_svg_extract(): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect/></svg>';
    eq(wg_qr_svg_extract("<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n$svg\n"),
        $svg, 'XML prolog dropped');
    eq(wg_qr_svg_extract($svg), $svg, 'bare svg');
    eq(wg_qr_svg_extract('qrencode: Invalid argument: SVG'), null, 'old qrencode error text');
    eq(wg_qr_svg_extract(''), null, 'empty');
    eq(wg_qr_svg_extract('<svg xmlns="x"><rect/>'), null, 'truncated');
}
