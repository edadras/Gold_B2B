<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Infrastructure;

use App\Modules\Webhook\Contracts\HostResolver;

/**
 * The production resolver: a real DNS lookup for A and AAAA.
 *
 * BOTH families are queried. Asking only for A records and then letting cURL
 * connect would be a hole you could drive a truck through — a host with an A
 * record on a public address and an AAAA record on `::1` passes an IPv4-only
 * check and then connects over IPv6 to the loopback interface. The guard needs
 * every address the connection could actually use.
 *
 * Results are not cached. Caching would reintroduce, inside our own process,
 * exactly the staleness the re-resolution at delivery time exists to remove.
 */
final class DnsHostResolver implements HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        /** @var list<array{type?: string, ip?: string, ipv6?: string}>|false $records */
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        foreach ($records ?: [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        // Fall back to the system resolver for A records: dns_get_record() reads
        // /etc/resolv.conf directly and ignores /etc/hosts and NSS, so a host
        // that the connection *would* resolve can come back empty here.
        if ($addresses === []) {
            $addresses = gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($addresses));
    }
}
