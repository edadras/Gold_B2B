<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Tests\Unit;

use App\Modules\Ledger\Domain\HashChainBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Invariant I10 at the unit level. No database: the builder is pure.
 */
final class HashChainBuilderTest extends TestCase
{
    private HashChainBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new HashChainBuilder;
    }

    #[Test]
    public function it_produces_a_sha256_hex_digest(): void
    {
        $hash = $this->builder->compute(null, '2026-07-27 09:10:00.123456', 5, -250000, 'RESERVE', 'order:44101', 750000);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function the_payload_is_the_documented_field_order(): void
    {
        $payload = $this->builder->payload(
            'abc',
            '2026-07-27 09:10:00.123456',
            5,
            -250000,
            'RESERVE',
            'order:44101',
            750000,
        );

        $this->assertSame(
            'abc|2026-07-27 09:10:00.123456|5|-250000|RESERVE|order:44101|750000',
            $payload,
        );
        $this->assertSame(hash('sha256', $payload), $this->builder->compute(
            'abc', '2026-07-27 09:10:00.123456', 5, -250000, 'RESERVE', 'order:44101', 750000,
        ));
    }

    #[Test]
    public function a_genesis_row_hashes_an_empty_previous_component(): void
    {
        $this->assertStringStartsWith(
            '|2026-07-27',
            $this->builder->payload(null, '2026-07-27 09:10:00.000000', 1, 1, 'DEPOSIT_GOLD', 'custody:1', 1),
        );
    }

    #[Test]
    public function it_is_deterministic(): void
    {
        $args = [null, '2026-07-27 09:10:00.000001', 9, 100, 'ROUNDING', 'trade:1', 100];

        $this->assertSame(
            $this->builder->compute(...$args),
            $this->builder->compute(...$args),
        );
    }

    /**
     * Every hashed field must actually change the digest, otherwise tampering
     * with that field would go undetected.
     */
    #[Test]
    public function changing_any_hashed_field_changes_the_hash(): void
    {
        $base = ['p0', '2026-07-27 09:10:00.000000', 5, -100, 'RESERVE', 'order:1', 900];
        $baseline = $this->builder->compute(...$base);

        $mutations = [
            'prev_hash' => ['p1', '2026-07-27 09:10:00.000000', 5, -100, 'RESERVE', 'order:1', 900],
            'created_at' => ['p0', '2026-07-27 09:10:00.000001', 5, -100, 'RESERVE', 'order:1', 900],
            'account_id' => ['p0', '2026-07-27 09:10:00.000000', 6, -100, 'RESERVE', 'order:1', 900],
            'amount' => ['p0', '2026-07-27 09:10:00.000000', 5, -101, 'RESERVE', 'order:1', 900],
            'entry_type' => ['p0', '2026-07-27 09:10:00.000000', 5, -100, 'RELEASE', 'order:1', 900],
            'reference' => ['p0', '2026-07-27 09:10:00.000000', 5, -100, 'RESERVE', 'order:2', 900],
            'balance_after' => ['p0', '2026-07-27 09:10:00.000000', 5, -100, 'RESERVE', 'order:1', 901],
        ];

        foreach ($mutations as $field => $args) {
            $this->assertNotSame($baseline, $this->builder->compute(...$args), "Hash ignores {$field}");
        }
    }

    /**
     * The separator must not be forgeable by moving characters between fields:
     * "a|b" and "ab" (or "a|b" split differently) must not collide.
     */
    #[Test]
    public function field_boundaries_cannot_be_shifted(): void
    {
        $this->assertNotSame(
            $this->builder->compute(null, '2026-07-27 09:10:00.000000', 1, 12, 'X', 'a:1', 3),
            $this->builder->compute(null, '2026-07-27 09:10:00.000000', 11, 2, 'X', 'a:1', 3),
        );
    }

    #[Test]
    public function it_verifies_an_intact_chain(): void
    {
        $rows = $this->chain([100, -40, 25]);

        $this->assertSame([], $this->builder->verify($rows));
    }

    #[Test]
    public function it_reports_a_row_whose_amount_was_tampered_with(): void
    {
        $rows = $this->chain([100, -40, 25]);
        $rows[1]['amount'] = -30;   // an UPDATE that should never have happened

        $this->assertSame([2], $this->builder->verify($rows));
    }

    #[Test]
    public function it_reports_a_row_whose_link_was_broken(): void
    {
        $rows = $this->chain([100, -40, 25]);
        $rows[2]['prev_hash'] = str_repeat('0', 64);

        $this->assertSame([3], $this->builder->verify($rows));
    }

    #[Test]
    public function one_bad_row_does_not_condemn_every_later_row(): void
    {
        $rows = $this->chain([100, -40, 25, 10, 5]);
        $rows[1]['amount'] = -30;

        $this->assertSame([2], $this->builder->verify($rows));
    }

    /**
     * Build a well-formed chain of rows for one account.
     *
     * @param  array<int, int>  $amounts
     * @return array<int, array<string, mixed>>
     */
    private function chain(array $amounts): array
    {
        $rows = [];
        $prev = null;
        $running = 0;

        foreach ($amounts as $index => $amount) {
            $running += $amount;
            $createdAt = sprintf('2026-07-27 09:10:00.%06d', $index);

            $hash = $this->builder->compute($prev, $createdAt, 7, $amount, 'MANUAL_ADJUSTMENT', 'adjustment:1', $running);

            $rows[] = [
                'id' => $index + 1,
                'prev_hash' => $prev,
                'row_hash' => $hash,
                'created_at' => $createdAt,
                'account_id' => 7,
                'amount' => $amount,
                'entry_type' => 'MANUAL_ADJUSTMENT',
                'reference' => 'adjustment:1',
                'balance_after' => $running,
            ];

            $prev = $hash;
        }

        return $rows;
    }
}
