@extends('admin::layouts.app')
@section('title', 'پرونده AML #'.$context->flag->id)
@php use App\Modules\Admin\Domain\Format; @endphp

@section('content')
    <div class="panel">
        <h2>پرچم</h2>
        <div class="body">
            <dl class="kv">
                <dt>قانون</dt><dd class="mono">{{ $context->flag->ruleName ?? $context->flag->ruleCode }}</dd>
                <dt>شدت</dt><dd><x-admin::status-badge :value="$context->flag->severity" :tone="Format::severityTone($context->flag->severity)" /></dd>
                <dt>وضعیت</dt><dd><x-admin::status-badge :value="$context->flag->status" :tone="Format::statusTone($context->flag->status)" /></dd>
                <dt>خلاصه</dt><dd class="wrap">{{ $context->flag->summary }}</dd>
                <dt>موضوع</dt><dd>{{ $context->flag->subjectType ?? '—' }} {{ $context->flag->subjectId ?? '' }}</dd>
                <dt>زمان</dt><dd class="mono">{{ $context->flag->raisedAt }}</dd>
            </dl>
        </div>
    </div>

    <div class="panel">
        <h2>زمینه بررسی</h2>
        <div class="body">
            <dl class="kv">
                <dt>سازمان</dt><dd>{{ $context->flag->organizationName ?? '#'.$context->flag->organizationId }}</dd>
                <dt>وضعیت عضو</dt><dd>{{ $context->organizationStatus ?? '—' }}</dd>
                <dt>سطح ریسک</dt><dd>{{ $context->organizationRiskLevel ?? '—' }}</dd>
                <dt>وضعیت انطباق</dt><dd>{{ $context->complianceState ?? '—' }}</dd>
                <dt>اختلاف باز</dt><dd class="num">{{ $context->openDisputeCount }}</dd>
                <dt>تسویه سررسیدگذشته</dt><dd class="num">{{ $context->overdueSettlementCount }}</dd>
                @foreach ($context->balances as $asset => $balance)
                    <dt>مانده {{ $asset }}</dt><dd class="num">{{ Format::thousands($balance) }}</dd>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="grid cols-2">
        <div class="panel">
            <h2>معاملات اخیر</h2>
            <div class="body">
                <x-admin::data-table
                    :columns="[['label' => 'کد'], ['label' => 'طرف مقابل', 'numeric' => true], ['label' => 'وزن (گرم)', 'numeric' => true], ['label' => 'مبلغ (ریال)', 'numeric' => true], ['label' => 'زمان']]"
                    :empty="$context->recentTrades === []">
                    @foreach ($context->recentTrades as $trade)
                        <tr>
                            <td class="mono">{{ $trade['trade_code'] ?? $trade['id'] }}</td>
                            <td class="num">{{ $trade['counterparty_id'] }}</td>
                            <td class="num">{{ Format::grams($trade['quantity_fine_mg']) }}</td>
                            <td class="num">{{ Format::rial($trade['gross_amount_rial']) }}</td>
                            <td class="mono">{{ $trade['executed_at'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
            </div>
        </div>

        <div class="panel">
            <h2>سایر پرچم‌های همین عضو</h2>
            <div class="body">
                <x-admin::data-table
                    :columns="[['label' => 'شدت'], ['label' => 'قانون'], ['label' => 'وضعیت'], ['label' => 'زمان']]"
                    :empty="$context->otherFlags === []">
                    @foreach ($context->otherFlags as $other)
                        <tr>
                            <td><x-admin::status-badge :value="$other->severity" :tone="Format::severityTone($other->severity)" /></td>
                            <td class="mono">{{ $other->ruleCode }}</td>
                            <td><x-admin::status-badge :value="$other->status" :tone="Format::statusTone($other->status)" /></td>
                            <td class="mono">{{ $other->raisedAt }}</td>
                        </tr>
                    @endforeach
                </x-admin::data-table>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>ثبت تصمیم</h2>
        <div class="body">
            <form method="POST" action="{{ route('admin.aml.decide', $context->flag->id) }}">
                @csrf
                <div class="field">
                    <label for="status">وضعیت جدید</label>
                    <select id="status" name="status" required>
                        @foreach ($decisionStatuses as $status)
                            <option value="{{ $status }}">{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="action_taken">اقدام انجام‌شده</label>
                    <input id="action_taken" name="action_taken" type="text" maxlength="255">
                </div>
                <div class="field">
                    <label for="notes">یادداشت تحلیل‌گر (اجباری)</label>
                    <textarea id="notes" name="notes" minlength="10" required></textarea>
                </div>
                <button type="submit" class="primary">ثبت</button>
            </form>
        </div>
    </div>
@endsection
