<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Support;

use App\Modules\Webhook\Contracts\HostResolver;

/**
 * An in-memory DNS zone.
 *
 * Every SSRF assertion needs a host that resolves to a particular address, and
 * the DNS-rebinding test needs one whose answer CHANGES between the registration
 * check and the delivery check. Neither is expressible against real DNS, which
 * is the whole reason HostResolver is an interface.
 */
final class FakeHostResolver implements HostResolver
{
    /** @var array<string, list<string>> */
    private array $zone = [];

    /** Hosts not in the zone resolve here: an ordinary public address. */
    private string $defaultIp = '93.184.216.34';

    /** @param list<string> $ips */
    public function set(string $host, array $ips): self
    {
        $this->zone[strtolower($host)] = $ips;

        return $this;
    }

    public function setDefault(string $ip): self
    {
        $this->defaultIp = $ip;

        return $this;
    }

    /** @return list<string> */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->zone[strtolower($host)] ?? [$this->defaultIp];
    }
}
