<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Models;

use App\Modules\Risk\Domain\AmlRuleAction;
use App\Modules\Risk\Domain\AmlRuleCategory;
use App\Modules\Risk\Domain\FlagSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The stored configuration of a rule. Named AmlRuleModel because AmlRule is the
 * behaviour interface in Aml/.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $description
 * @property AmlRuleCategory $category
 * @property FlagSeverity $severity
 * @property AmlRuleAction $action
 * @property array<string, mixed> $parameters
 * @property list<string> $applies_to
 * @property int $evaluation_order
 * @property bool $is_active
 */
final class AmlRuleModel extends Model
{
    protected $table = 'aml_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'category' => AmlRuleCategory::class,
            'severity' => FlagSeverity::class,
            'action' => AmlRuleAction::class,
            'parameters' => 'array',
            'applies_to' => 'array',
            'evaluation_order' => 'int',
            'is_active' => 'bool',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function appliesTo(string $eventType): bool
    {
        return $this->applies_to === [] || in_array($eventType, $this->applies_to, true);
    }
}
