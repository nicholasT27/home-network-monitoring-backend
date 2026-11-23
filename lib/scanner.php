<?php

require_once __DIR__ . '/vendor.php';

// This function turns a network range (like 192.168.0.0/24) into a list of addresses.
function cidr_hosts(string $cidr): array
{
    if (!preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', $cidr, $m) || !filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new InvalidArgumentException("Invalid subnet: $cidr");
    }
    $prefix = (int) $m[2];
    if ($prefix < 22 || $prefix > 30) {
        throw new InvalidArgumentException('Subnet prefix must be between /22 and /30');
    }
    $mask = (~0 << (32 - $prefix)) & 0xFFFFFFFF;
    $network = ip2long($m[1]) & $mask;
    $broadcast = $network | (~$mask & 0xFFFFFFFF);
    $hosts = [];
    for ($i = $network + 1; $i < $broadcast; $i++) {
        $hosts[] = long2ip($i);
    }
    return $hosts;
}

// This function tells whether this computer runs Windows, so the right commands are used.
function is_windows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

// This function pings every address and gives back the ones that answered.
function ping_sweep(array $ips, int $batch = 64): array
{
    $alive = [];
    foreach (array_chunk($ips, $batch) as $chunk) {
        $procs = [];
        foreach ($chunk as $ip) {
            $cmd = is_windows() ? ['ping', '-n', '1', '-w', '500', $ip]
                : (PHP_OS_FAMILY === 'Darwin' ? ['ping', '-c', '1', '-W', '1000', $ip] : ['ping', '-c', '1', '-W', '1', $ip]);
            $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $p = @proc_open($cmd, $spec, $pipes);
            if (is_resource($p)) {
                $procs[] = [$p, $pipes, $ip];
            }
        }
        foreach ($procs as [$p, $pipes, $ip]) {
            $out = (string) stream_get_contents($pipes[1]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($p);
            if (preg_match('/ttl=/i', $out)) {
                $alive[$ip] = true;
            }
        }
    }
    return $alive;
}

// This function checks again the addresses that did not answer the ping.
function arp_probe(array $ips, int $rounds = 3, int $pauseUs = 700000): void
{
    for ($r = 0; $r < $rounds && $ips; $r++) {
        foreach ($ips as $ip) {
            $sock = @stream_socket_client('udp://' . $ip . ':9', $errno, $errstr, 1);
            if ($sock) {
                @fwrite($sock, 'x');
                fclose($sock);
            }
        }
        usleep($pauseUs);
    }
}

// This function clears the computer's saved list of devices, so only devices online now are counted.
function flush_arp_cache(): bool
{
    if (is_windows()) {
        $cmd = 'arp -d * 2>&1';
    } else {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : (int) trim((string) shell_exec('id -u'));
        if ($uid !== 0) {
            return false;
        }
        $cmd = PHP_OS_FAMILY === 'Darwin' ? 'arp -d -a 2>&1' : 'ip neigh flush all 2>&1';
    }
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    return $code === 0 && !preg_match('/elevat|denied|not permitted|failed|not found/i', implode(' ', $out));
}

// This function decides which addresses are online.
function online_ips(array $pingReplies, array $arpEntries, bool $cacheFlushed): array
{
    $alive = $pingReplies;
    if ($cacheFlushed) {
        foreach ($arpEntries as $ip => $mac) {
            $alive[$ip] = true;
        }
    }
    return $alive;
}

// This function writes a MAC address in one standard way.
function normalise_mac(string $mac): string
{
    return strtoupper(str_replace('-', ':', $mac));
}

// This function reads the computer's device list and turns it into addresses and MAC addresses.
function parse_arp(string $text, string $cidr): array
{
    $hosts = array_flip(cidr_hosts($cidr));
    $found = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (!preg_match('/(\d{1,3}(?:\.\d{1,3}){3}).*?\b((?:[0-9a-f]{1,2}[:-]){5}[0-9a-f]{1,2})\b/i', $line, $m)) {
            continue;
        }
        $parts = preg_split('/[:-]/', $m[2]);
        $mac = normalise_mac(implode(':', array_map(fn($p) => str_pad($p, 2, '0', STR_PAD_LEFT), $parts)));
        $ip = $m[1];
        if (!isset($hosts[$ip]) || $mac === 'FF:FF:FF:FF:FF:FF' || $mac === '00:00:00:00:00:00'
            || str_starts_with($mac, '01:00:5E')) {
            continue;
        }
        $found[$ip] = $mac;
    }
    return $found;
}

// This function reads the computer's saved list of devices.
function read_arp_cache(string $cidr): array
{
    $outputs = is_windows() ? ['arp -a'] : ['ip neigh show 2>/dev/null', 'arp -an 2>/dev/null'];
    $found = [];
    foreach ($outputs as $cmd) {
        $found += parse_arp((string) shell_exec($cmd), $cidr);
    }
    return $found;
}

// This function looks up a device's name from its address.
function resolve_hostname(string $ip): ?string
{
    $name = @gethostbyaddr($ip);
    return ($name === false || $name === $ip || $name === '') ? null : $name;
}

// This function scans the network and gives back the list of devices found.
function scan_network(array $config, ?int $deadline = null, int $demoRound = 1): array
{
    if (($config['scan_mode'] ?? 'live') === 'demo') {
        return demo_scan($demoRound);
    }
    $cidr = $config['subnet'];
    $flushed = flush_arp_cache();
    $pinged = ping_sweep(cidr_hosts($cidr));
    if ($flushed) {
        arp_probe(array_values(array_filter(cidr_hosts($cidr), fn($ip) => !isset($pinged[$ip]))));
    }
    $arp = read_arp_cache($cidr);
    $alive = online_ips($pinged, $arp, $flushed);
    $devices = [];
    foreach ($arp as $ip => $mac) {
        $skipDns = $deadline !== null && time() > $deadline;
        $devices[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $skipDns ? null : resolve_hostname($ip), 'alive' => isset($alive[$ip])];
    }
    return $devices;
}

// This function makes a pretend network, for trying the system without a real one (used for testing).
function demo_scan(int $n): array
{
    $devices = [
        ['3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'],
        ['A4:83:E7:AA:BB:01', '192.168.1.12', 'alex-macbook'],
        ['8C:77:12:44:55:66', '192.168.1.20', 'living-room-tv'],
        ['24:0A:C4:9A:12:34', '192.168.1.31', 'smart-plug-kitchen'],
        ['44:65:0D:77:88:99', '192.168.1.33', 'echo-dot'],
    ];
    $devices[] = [sprintf('DA:A1:19:00:00:%02X', intdiv($n, 4) % 256), '192.168.1.40', 'alex-iphone'];
    if ($n >= 3) {
        $devices[] = ['5E:CF:7F:0B:AD:01', '192.168.1.77', null];
    }
    return array_map(fn($d) => ['mac' => $d[0], 'ip' => $d[1], 'hostname' => $d[2], 'alive' => true], $devices);
}
