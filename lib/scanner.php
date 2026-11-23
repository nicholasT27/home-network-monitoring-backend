<?php
// Finding the devices on the network. First every address is pinged, which makes active devices answer and fills this
// computer's list of neighbours (the ARP list). Only then is that list read. Reading it without pinging first would miss quiet devices.

require_once __DIR__ . '/vendor.php';

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

function is_windows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

// Pings every address and returns the ones that actually answered. An answer (shown as TTL in the output) is
// proof that the device is online right now: the computer keeps old entries in its neighbour list for about 20 minutes after a device leaves.
function ping_sweep(array $ips, int $batch = 64): array
{
    $alive = [];
    foreach (array_chunk($ips, $batch) as $chunk) {
        $procs = [];
        foreach ($chunk as $ip) {
            // The waiting-time setting uses different units: Windows -w and Mac -W are milliseconds, Linux -W is seconds.
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

// Second pass after pinging: asks the network "who has this address?" again for every address that did not answer
// the ping. Sending a tiny message makes this computer ask that question out loud (an ARP request). This does not
// depend on ping, and a device's firewall cannot block it. A device that is asleep or slow gets several tries over a few seconds.
// The answers are read afterwards, so this function only sends the questions and waits between the rounds.
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
        usleep($pauseUs);   // give the devices a moment to answer
    }
}

// Clears this computer's list of neighbours (the ARP list) so that, after pinging, a device is only in the list if it
// answered during this scan. A device cannot hide from this question, so it finds devices that ignore pings
// (for example a Windows laptop). It needs administrator rights: sudo on Mac and Linux, an Administrator terminal
// on Windows. Gives back true only if the list was really cleared; otherwise the scan works as before.
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

// Decides which addresses count as online. An answer to a ping always counts. If the neighbour list was cleared first, an
// entry in it also counts: it can only be there because the device answered during this scan. Without clearing,
// an entry might be an old leftover (they last about 20 minutes), so only a ping answer is trusted.
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

function normalise_mac(string $mac): string
{
    return strtoupper(str_replace('-', ':', $mac));
}

// Reads the output of the `arp -a` command (Windows, Mac, Linux) or `ip neigh` (Linux) and turns it into a list of address => hardware ID, only for the chosen range.
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

function read_arp_cache(string $cidr): array
{
    $outputs = is_windows() ? ['arp -a'] : ['ip neigh show 2>/dev/null', 'arp -an 2>/dev/null'];
    $found = [];
    foreach ($outputs as $cmd) {
        $found += parse_arp((string) shell_exec($cmd), $cidr);
    }
    return $found;
}

function resolve_hostname(string $ip): ?string
{
    $name = @gethostbyaddr($ip);
    return ($name === false || $name === $ip || $name === '') ? null : $name;
}

// Gives back a list of devices, each with its hardware ID ('mac'), address ('ip'), name ('hostname') and whether it answered ('alive').
// 'alive' is false for devices that are only an old leftover entry and did not answer (unless the list was cleared first, see online_ips).
function scan_network(array $config, ?int $deadline = null, int $demoRound = 1): array
{
    if (($config['scan_mode'] ?? 'live') === 'demo') {
        return demo_scan($demoRound);
    }
    $cidr = $config['subnet'];
    $flushed = flush_arp_cache();
    $pinged = ping_sweep(cidr_hosts($cidr));   // pass 1: ping every address (this also makes devices announce themselves)
    if ($flushed) {
        // Pass 2: pinging is done; now ask again, by ARP, for every address that did not answer the ping.
        arp_probe(array_values(array_filter(cidr_hosts($cidr), fn($ip) => !isset($pinged[$ip]))));
    }
    $arp = read_arp_cache($cidr);
    $alive = online_ips($pinged, $arp, $flushed);
    $devices = [];
    foreach ($arp as $ip => $mac) {
        // Skip looking up device names if time is nearly up, so the scan still finishes.
        $skipDns = $deadline !== null && time() > $deadline;
        $devices[] = ['mac' => $mac, 'ip' => $ip, 'hostname' => $skipDns ? null : resolve_hostname($ip), 'alive' => isset($alive[$ip])];
    }
    return $devices;
}

// A pretend network for demonstrations: a fixed household, plus an unknown intruder from the 3rd scan on.
function demo_scan(int $n): array
{
    $devices = [
        ['3C:5A:B4:11:22:33', '192.168.1.1', 'router.lan'],
        ['A4:83:E7:AA:BB:01', '192.168.1.12', 'alex-macbook'],
        ['8C:77:12:44:55:66', '192.168.1.20', 'living-room-tv'],
        ['24:0A:C4:9A:12:34', '192.168.1.31', 'smart-plug-kitchen'],
        ['44:65:0D:77:88:99', '192.168.1.33', 'echo-dot'],
    ];
    // A phone that keeps changing its private hardware ID: same name, new ID every 4 scans.
    $devices[] = [sprintf('DA:A1:19:00:00:%02X', intdiv($n, 4) % 256), '192.168.1.40', 'alex-iphone'];
    if ($n >= 3) {
        $devices[] = ['5E:CF:7F:0B:AD:01', '192.168.1.77', null];
    }
    return array_map(fn($d) => ['mac' => $d[0], 'ip' => $d[1], 'hostname' => $d[2], 'alive' => true], $devices);
}
