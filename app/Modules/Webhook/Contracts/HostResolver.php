<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Contracts;

/**
 * Hostname → IP addresses.
 *
 * A seam, not an abstraction for its own sake. The SSRF guard's whole job is to
 * decide which addresses a hostname currently points at, and that decision has
 * to be testable without a network: a test that asserts "a host resolving into
 * 10.0.0.0/8 is refused" cannot depend on some real domain being configured that
 * way, and a delivery test running under Http::fake() must not perform a live
 * lookup either.
 */
interface HostResolver
{
    /**
     * Every A and AAAA record for the host, as printable IP strings.
     *
     * An IP literal resolves to itself. An empty list means the name does not
     * resolve — callers must treat that as "unsafe", never as "no objection".
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
