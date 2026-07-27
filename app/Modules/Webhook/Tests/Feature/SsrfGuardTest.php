<?php

declare(strict_types=1);

namespace App\Modules\Webhook\Tests\Feature;

use App\Modules\Webhook\Application\OutboundUrlGuard;
use App\Modules\Webhook\Application\WebhookRegistrar;
use App\Modules\Webhook\Domain\Exceptions\UnsafeWebhookUrlException;
use App\Modules\Webhook\Domain\IpRangePolicy;
use App\Modules\Webhook\Domain\WebhookEventType;
use App\Modules\Webhook\Infrastructure\Models\Webhook;
use App\Modules\Webhook\Infrastructure\Models\WebhookDelivery;
use App\Modules\Webhook\Tests\WebhookTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The security control of the whole feature — see OutboundUrlGuard for the
 * reasoning. This suite is the executable version of that docblock.
 */
final class SsrfGuardTest extends WebhookTestCase
{
    private WebhookRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();

        $this->registrar = $this->app->make(WebhookRegistrar::class);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function refusedUrls(): array
    {
        return [
            'loopback by name' => ['http://localhost/hook', 'the local machine'],
            'loopback by name over https' => ['https://localhost/hook', 'the local machine'],
            'loopback by address' => ['https://127.0.0.1/hook', 'loopback'],
            'cloud metadata service' => ['http://169.254.169.254/latest/meta-data/', 'link-local'],
            'private RFC1918 /8' => ['http://10.0.0.1/hook', 'private'],
            'private RFC1918 /12' => ['https://172.16.5.4/hook', 'private'],
            'private RFC1918 /16' => ['https://192.168.1.1/hook', 'private'],
            'carrier-grade NAT' => ['https://100.64.0.1/hook', 'NAT'],
            'plain http' => ['http://accounting.example.com/hook', 'HTTPS'],
            'ipv6 loopback' => ['https://[::1]/hook', 'loopback'],
            'ipv4-mapped ipv6 loopback' => ['https://[::ffff:127.0.0.1]/hook', 'loopback'],
            'unique local ipv6' => ['https://[fd00::1]/hook', 'unique local'],
            'internal suffix' => ['https://accounting.internal/hook', 'internal network'],
            'mdns suffix' => ['https://printer.local/hook', 'internal network'],
            'single label host' => ['https://intranet/hook', 'fully qualified'],
            'credentials in url' => ['https://user:pass@accounting.example.com/hook', 'credentials'],
            'fragment in url' => ['https://accounting.example.com/hook#x', 'fragment'],
            'non http scheme' => ['gopher://accounting.example.com/hook', 'not supported'],
            'file scheme' => ['file:///etc/passwd', 'not absolute'],
            'internal service port' => ['https://accounting.example.com:6379/hook', 'internal service port'],
            'broadcast' => ['https://255.255.255.255/hook', 'reserved'],
            'unspecified' => ['https://0.0.0.0/hook', 'this network'],
        ];
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function unsafe_destinations_are_refused_at_registration(string $url, string $expectedReason): void
    {
        try {
            $this->registrar->register(184, $url, [WebhookEventType::TRADE_EXECUTED->value]);
            self::fail(sprintf('%s should have been refused', $url));
        } catch (UnsafeWebhookUrlException $e) {
            self::assertStringContainsStringIgnoringCase($expectedReason, $e->reason);
            self::assertSame('WEBHOOK_URL_NOT_ALLOWED', $e->errorCode());
        }

        self::assertSame(0, Webhook::query()->count());
    }

    #[Test]
    public function an_ordinary_https_endpoint_is_accepted(): void
    {
        $this->hosts->set('accounting.example.com', ['93.184.216.34']);

        $issued = $this->registrar->register(
            184,
            'https://accounting.example.com/goldb2b/hook',
            [WebhookEventType::TRADE_EXECUTED->value],
        );

        self::assertSame('https://accounting.example.com/goldb2b/hook', $issued->webhook->getAttribute('url'));
    }

    /**
     * The case an IP-literal blocklist alone would miss: a perfectly ordinary
     * hostname whose A record points inside the operator's network.
     */
    #[Test]
    public function a_public_name_resolving_into_a_private_range_is_refused(): void
    {
        $this->hosts->set('hook.member-example.com', ['10.1.2.3']);

        $this->expectException(UnsafeWebhookUrlException::class);

        $this->registrar->register(
            184,
            'https://hook.member-example.com/hook',
            [WebhookEventType::TRADE_EXECUTED->value],
        );
    }

    /** A public A record plus a loopback AAAA record is still unsafe. */
    #[Test]
    public function every_resolved_address_must_be_public_not_merely_the_first(): void
    {
        $this->hosts->set('dual.member-example.com', ['93.184.216.34', '::1']);

        $this->expectException(UnsafeWebhookUrlException::class);

        $this->registrar->register(
            184,
            'https://dual.member-example.com/hook',
            [WebhookEventType::TRADE_EXECUTED->value],
        );
    }

    /**
     * ─────────────────────────── DNS REBINDING ───────────────────────────
     *
     * The attack that makes a registration-time-only check worthless: register a
     * hostname that resolves somewhere public, then change the zone. Nothing
     * about the stored row changes.
     *
     * The guard re-resolves before every attempt, so the delivery is refused and
     * recorded as a failure rather than posted to the metadata service.
     */
    #[Test]
    public function a_host_that_rebinds_after_registration_is_refused_at_delivery_time(): void
    {
        Http::fake();

        $this->hosts->set('rebind.member-example.com', ['93.184.216.34']);

        $issued = $this->registrar->register(
            184,
            'https://rebind.member-example.com/hook',
            [WebhookEventType::TRADE_EXECUTED->value],
        );

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::factory()
            ->forWebhook((int) $issued->webhook->getKey())
            ->create();

        // The attacker flips the zone. TTL one second; nothing we store changes.
        $this->hosts->set('rebind.member-example.com', ['169.254.169.254']);

        $this->attemptDelivery((int) $delivery->getKey());

        Http::assertNothingSent();

        $delivery->refresh();
        self::assertSame(1, $delivery->attempts);
        self::assertStringContainsString('destination refused', (string) $delivery->last_error);
        self::assertStringContainsString('169.254.169.254', (string) $delivery->last_error);
    }

    /** A host that stops resolving entirely fails closed at delivery time. */
    #[Test]
    public function an_unresolvable_host_is_refused_at_delivery_time(): void
    {
        Http::fake();

        $this->hosts->set('gone.member-example.com', ['93.184.216.34']);

        $issued = $this->registrar->register(
            184,
            'https://gone.member-example.com/hook',
            [WebhookEventType::TRADE_EXECUTED->value],
        );

        $this->hosts->set('gone.member-example.com', []);

        /** @var WebhookDelivery $delivery */
        $delivery = WebhookDelivery::factory()->forWebhook((int) $issued->webhook->getKey())->create();

        $this->attemptDelivery((int) $delivery->getKey());

        Http::assertNothingSent();
        self::assertStringContainsString('does not resolve', (string) $delivery->refresh()->last_error);
    }

    /**
     * The local-development escape hatch. Both halves must agree: the switch AND
     * the host being named.
     */
    #[Test]
    public function the_allowlist_relaxes_the_rules_only_for_hosts_it_names(): void
    {
        $guard = $this->app->make(OutboundUrlGuard::class);

        config()->set('goldb2b.webhook.allow_insecure_destinations', true);
        config()->set('goldb2b.webhook.insecure_host_allowlist', ['host.docker.internal']);

        self::assertTrue($guard->isRegistrable('http://host.docker.internal:9000/hook'));

        // Not on the list: still refused, switch or no switch.
        self::assertFalse($guard->isRegistrable('http://localhost/hook'));
        self::assertFalse($guard->isRegistrable('http://169.254.169.254/'));

        // Switch off: the allowlist alone exempts nothing.
        config()->set('goldb2b.webhook.allow_insecure_destinations', false);
        self::assertFalse($guard->isRegistrable('http://host.docker.internal:9000/hook'));
    }

    #[Test]
    public function the_address_table_classifies_the_documented_ranges(): void
    {
        foreach ([
            '127.0.0.1', '10.255.255.255', '172.16.0.1', '172.31.255.255',
            '192.168.0.1', '169.254.169.254', '100.64.0.1', '0.0.0.0',
            '224.0.0.1', '255.255.255.255', '::1', 'fe80::1', 'fc00::1',
            '::ffff:10.0.0.1', '2002:0a00:0001::1',
        ] as $blocked) {
            self::assertFalse(IpRangePolicy::isPublic($blocked), $blocked.' must be blocked');
        }

        foreach ([
            '93.184.216.34', '8.8.8.8', '172.32.0.1', '172.15.255.255',
            '2606:4700:4700::1111',
        ] as $allowed) {
            self::assertTrue(IpRangePolicy::isPublic($allowed), $allowed.' must be allowed');
        }
    }

    /** A URL with whitespace or a control character is a smuggling attempt. */
    #[Test]
    public function control_characters_in_a_url_are_refused(): void
    {
        $guard = $this->app->make(OutboundUrlGuard::class);

        self::assertFalse($guard->isRegistrable("https://accounting.example.com/hook\r\nX-Injected: 1"));
        self::assertFalse($guard->isRegistrable('https://accounting.example.com/ hook'));
    }
}
