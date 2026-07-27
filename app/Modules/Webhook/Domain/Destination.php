<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Domain;

/**
 * A webhook URL that has passed the SSRF guard, together with the address it
 * resolved to at that moment.
 *
 * `pinnedIp` is the point of the class. Knowing the URL is safe is not enough —
 * between the check and the connect, the name can start resolving somewhere
 * else. The delivery pins the connection to the address that was actually
 * inspected, and this object carries it from the guard to the HTTP client.
 */
final readonly class Destination
{
    /** @param list<string> $resolvedIps every address the host currently answers with */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $pinnedIp,
        public array $resolvedIps,
    ) {}

    /** cURL's `HOST:PORT:ADDRESS` pinning syntax. */
    public function curlResolveEntry(): string
    {
        return sprintf('%s:%d:%s', $this->host, $this->port, $this->pinnedIp);
    }

    public function isIpLiteral(): bool
    {
        return filter_var($this->host, FILTER_VALIDATE_IP) !== false;
    }
}
