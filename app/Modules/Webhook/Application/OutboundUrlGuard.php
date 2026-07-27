<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Application;

use App\Modules\Webhook\Contracts\HostResolver;
use App\Modules\Webhook\Domain\Destination;
use App\Modules\Webhook\Domain\Exceptions\UnsafeWebhookUrlException;
use App\Modules\Webhook\Domain\IpRangePolicy;

/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  SERVER-SIDE REQUEST FORGERY IS THE RISK OF THIS ENTIRE FEATURE.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The webhook subsystem is, described plainly, an API that lets any member make
 * this platform's application servers issue an HTTP POST to an address of the
 * member's choosing, repeatedly, on a schedule, from inside the production
 * network, with a body the member can partly influence — and then does not show
 * them the response. That is a textbook SSRF primitive handed out as a product
 * feature. The document does not mention it, so the reasoning is written down
 * here rather than left implicit in a regex.
 *
 * WHAT AN ATTACKER GETS IF THIS IS WRONG
 *
 *   · `http://169.254.169.254/latest/meta-data/iam/security-credentials/` —
 *     cloud instance metadata. On a default IMDSv1 setup this is the platform's
 *     own IAM credentials, i.e. the database, the object store, everything.
 *   · `http://10.0.0.x:6379/…` — Redis, Elasticsearch, an internal admin panel,
 *     a Kubernetes API server: services that are unauthenticated precisely
 *     because they are "not reachable from outside".
 *   · `http://127.0.0.1:…` — the application's own sidecars and management
 *     ports.
 *   · Even without reading the response, the delivery record leaks the status
 *     code and the response time, which is a working port scanner and an
 *     existence oracle for internal hosts.
 *
 * THE POLICY
 *
 *   1. HTTPS ONLY. The payload contains a member's trade volumes, prices and
 *      counterparties, and it is signed but not encrypted. Plain HTTP puts the
 *      lot on the wire in clear text, and a signature does not help a passive
 *      observer. Refusing `http://` also removes the redirect-to-http downgrade.
 *   2. NO CREDENTIALS OR FRAGMENTS IN THE URL. `https://user:pass@host/` is a
 *      classic parser-confusion vector — several HTTP clients and several
 *      humans disagree about which part is the host.
 *   3. THE HOST MUST NOT BE A NAME THAT MEANS "HERE". `localhost`, anything
 *      under `.localhost`, `.local`, `.internal`, `.home.arpa`, and the bare
 *      single-label names that resolve through a search domain.
 *   4. EVERY ADDRESS THE HOST RESOLVES TO MUST BE PUBLIC — not just the first
 *      one. A host with a public A record and an AAAA record on `::1` is not
 *      safe. IpRangePolicy owns the address table.
 *   5. THE PORT MUST NOT BE A KNOWN INTERNAL SERVICE PORT. Defence in depth: it
 *      costs nothing legitimate and it neuters the "public IP that NATs to an
 *      internal box" case that rule 4 cannot see.
 *   6. THE CHECK IS REPEATED AT DELIVERY TIME AND THE CONNECTION IS PINNED.
 *      This is the rule people leave out, and leaving it out makes the other
 *      five decorative — see below.
 *
 * WHY CHECKING AT REGISTRATION IS NOT ENOUGH (DNS REBINDING)
 *
 * Registration validates `https://hook.member.example/`, which resolves to a
 * public address. The attacker controls that zone and serves it with a TTL of
 * one second. When the first event fires, the name now answers `169.254.169.254`
 * and the platform cheerfully posts to the metadata service. Nothing about the
 * stored row changed; the world under it did. So:
 *
 *   · resolveForDelivery() re-runs the entire policy immediately before every
 *     single attempt, including retries hours later;
 *   · and because even that leaves a window between our lookup and cURL's own
 *     lookup, the address we validated is *pinned* into the request via
 *     CURLOPT_RESOLVE. The connection goes to the address that was inspected or
 *     it does not go at all. That closes the time-of-check/time-of-use gap
 *     rather than merely narrowing it.
 *
 * WHAT IS STILL NOT COVERED, STATED HONESTLY
 *
 *   · Redirects. The delivery does not follow them (`allow_redirects => false`),
 *     because a 302 to `http://169.254.169.254/` would bypass every check above.
 *     A receiver that answers 3xx is treated as a failure, not a destination.
 *   · A genuinely public IP that NATs or proxies into a private network. No
 *     address check can see that; rule 5 and network egress policy are the
 *     mitigations. The production deployment should also route webhook traffic
 *     through an egress proxy in its own network namespace — that is an
 *     infrastructure control, and this class does not pretend to replace it.
 *
 * THE LOCAL-DEVELOPMENT ESCAPE HATCH
 *
 * `goldb2b.webhook.allow_insecure_destinations` (env
 * WEBHOOK_ALLOW_INSECURE_DESTINATIONS) relaxes rules 1–4, and only for hosts
 * named in `insecure_host_allowlist`. Two properties matter: it defaults to
 * false, so a production box that never sets the variable is safe by omission;
 * and even switched on it is an allowlist, not an off switch, so a developer who
 * enables it for `host.docker.internal` has not also opened
 * `169.254.169.254`.
 */
final class OutboundUrlGuard
{
    private const MAX_URL_LENGTH = 500;

    /** Suffixes that, by definition, name something inside the local network. */
    private const INTERNAL_SUFFIXES = [
        '.localhost', '.local', '.internal', '.intranet', '.lan', '.home.arpa',
    ];

    private const INTERNAL_NAMES = ['localhost', 'localhost.localdomain', 'ip6-localhost'];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Validate a URL a member is trying to register or update.
     *
     * DNS is consulted here too — a host that already resolves into RFC1918 is
     * refused up front, which is a much better experience than accepting it and
     * failing every delivery. But a lookup FAILURE is not fatal at this stage:
     * the endpoint may not be in DNS yet, or may be behind split-horizon DNS
     * that differs from ours. Registration therefore accepts an unresolvable
     * host, and delivery — which fails closed — does not.
     *
     * @return string the normalised URL to store
     *
     * @throws UnsafeWebhookUrlException
     */
    public function assertRegistrable(string $url): string
    {
        $parts = $this->parse($url);

        $this->assertShape($parts, $url);

        $host = $this->host($parts);
        $port = $this->port($parts);

        // Hostname before scheme, so `http://localhost` is reported as "the
        // local machine" rather than as "must use HTTPS" — the first is the
        // reason that actually matters and the one a member needs to read.
        $this->assertHostname($host, $url);
        $this->assertScheme($parts, $host, $url);
        $this->assertPort($port, $url);

        if ($this->isExempt($host)) {
            return $this->normalise($parts);
        }

        foreach ($this->resolver->resolve($host) as $ip) {
            $this->assertAddressPublic($ip, $host, $url);
        }

        return $this->normalise($parts);
    }

    /**
     * Re-validate immediately before an attempt and pin the address.
     *
     * Fails closed on every uncertainty, including "the name does not resolve":
     * at this point we are about to open a socket, and "I could not tell" is not
     * a reason to proceed.
     *
     * @throws UnsafeWebhookUrlException
     */
    public function resolveForDelivery(string $url): Destination
    {
        $parts = $this->parse($url);

        $this->assertShape($parts, $url);

        $host = $this->host($parts);
        $port = $this->port($parts);
        $scheme = strtolower((string) $parts['scheme']);

        $this->assertHostname($host, $url);
        $this->assertScheme($parts, $host, $url);
        $this->assertPort($port, $url);

        if ($this->isExempt($host)) {
            $addresses = $this->resolver->resolve($host);

            return new Destination(
                url: $url,
                scheme: $scheme,
                host: $host,
                port: $port,
                pinnedIp: $addresses[0] ?? $host,
                resolvedIps: $addresses,
            );
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new UnsafeWebhookUrlException($url, sprintf('host "%s" does not resolve', $host));
        }

        foreach ($addresses as $ip) {
            $this->assertAddressPublic($ip, $host, $url);
        }

        return new Destination(
            url: $url,
            scheme: $scheme,
            host: $host,
            port: $port,
            // Every address passed, so any of them is safe; the first is used and
            // pinned so cURL cannot pick a different one a moment later.
            pinnedIp: $addresses[0],
            resolvedIps: $addresses,
        );
    }

    /** Non-throwing form, for validation rules that want a boolean. */
    public function isRegistrable(string $url): bool
    {
        try {
            $this->assertRegistrable($url);

            return true;
        } catch (UnsafeWebhookUrlException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function parse(string $url): array
    {
        if (strlen($url) > self::MAX_URL_LENGTH) {
            throw new UnsafeWebhookUrlException($url, 'URL is longer than '.self::MAX_URL_LENGTH.' characters');
        }

        // A URL with a control character or whitespace in it is a request
        // smuggling attempt, not a typo.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new UnsafeWebhookUrlException($url, 'URL contains whitespace or control characters');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeWebhookUrlException($url, 'URL is not absolute');
        }

        return $parts;
    }

    /** @param array<string, mixed> $parts */
    private function assertShape(array $parts, string $url): void
    {
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrlException($url, 'URL must not carry credentials');
        }

        if (isset($parts['fragment'])) {
            throw new UnsafeWebhookUrlException($url, 'URL must not carry a fragment');
        }
    }

    /**
     * IPv6 hosts arrive from parse_url() wrapped in brackets (`[::1]`), which
     * every IP function rejects. Unwrapping here means the range checks see the
     * address the connection will actually use.
     *
     * @param  array<string, mixed>  $parts
     */
    private function host(array $parts): string
    {
        $host = strtolower(trim((string) $parts['host']));

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return $host;
    }

    /** @param array<string, mixed> $parts */
    private function assertScheme(array $parts, string $host, string $url): void
    {
        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme === 'https') {
            return;
        }

        if ($scheme !== 'http') {
            throw new UnsafeWebhookUrlException($url, sprintf('scheme "%s" is not supported', $scheme));
        }

        if (! $this->isExempt($host)) {
            throw new UnsafeWebhookUrlException($url, 'webhook URLs must use HTTPS');
        }
    }

    private function assertHostname(string $host, string $url): void
    {
        if ($host === '') {
            throw new UnsafeWebhookUrlException($url, 'URL has no host');
        }

        if ($this->isExempt($host)) {
            return;
        }

        if (in_array($host, self::INTERNAL_NAMES, true)) {
            throw new UnsafeWebhookUrlException($url, sprintf('"%s" is the local machine', $host));
        }

        foreach (self::INTERNAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                throw new UnsafeWebhookUrlException($url, sprintf('"%s" names an internal network', $host));
            }
        }

        // An IP literal skips DNS entirely, so it is checked here as well as in
        // the resolve loop — belt and braces, since the loop is what an exempt
        // host bypasses.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertAddressPublic($host, $host, $url);

            return;
        }

        // `https://intranet/hook` — a single label resolves through the search
        // domain, i.e. to something inside the operator's own network.
        if (! str_contains($host, '.')) {
            throw new UnsafeWebhookUrlException($url, 'host must be a fully qualified domain name');
        }
    }

    private function assertPort(int $port, string $url): void
    {
        /** @var list<int> $blocked */
        $blocked = (array) config('goldb2b.webhook.blocked_ports', []);

        if (in_array($port, array_map('intval', $blocked), true)) {
            throw new UnsafeWebhookUrlException($url, sprintf('port %d is an internal service port', $port));
        }
    }

    private function assertAddressPublic(string $ip, string $host, string $url): void
    {
        $reason = IpRangePolicy::reasonForBlocking($ip);

        if ($reason === null) {
            return;
        }

        throw new UnsafeWebhookUrlException(
            $url,
            sprintf('"%s" resolves to %s (%s)', $host, $ip, $reason),
        );
    }

    /**
     * Is this host on the local-development allowlist?
     *
     * Both halves must agree: the feature switch AND the host being named. A
     * truthy switch with an empty list exempts nothing.
     */
    private function isExempt(string $host): bool
    {
        if (! (bool) config('goldb2b.webhook.allow_insecure_destinations', false)) {
            return false;
        }

        /** @var list<string> $allowlist */
        $allowlist = (array) config('goldb2b.webhook.insecure_host_allowlist', []);

        foreach ($allowlist as $allowed) {
            if (strtolower(trim((string) $allowed)) === $host) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $parts */
    private function port(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) $parts['scheme']) === 'http' ? 80 : 443;
    }

    /**
     * Stored form: lower-case scheme and host, default port dropped, everything
     * else byte-for-byte as the member gave it. Rewriting the path or the query
     * would be wrong — they are the receiver's, not ours.
     *
     * @param  array<string, mixed>  $parts
     */
    private function normalise(array $parts): string
    {
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        $default = $scheme === 'http' ? 80 : 443;

        return $scheme.'://'.$host
            .($port === null || $port === $default ? '' : ':'.$port)
            .((string) ($parts['path'] ?? ''))
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
