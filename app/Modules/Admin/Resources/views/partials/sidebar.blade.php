{{-- Navigation. Each entry is hidden unless the signed-in user holds the
     permission its route requires, so AML simply is not in the menu for staff
     who cannot open it. --}}
@php
    /** @var \App\Modules\Identity\Contracts\UserSnapshot|null $me */
    $me = auth()->id() === null
        ? null
        : app(\App\Modules\Identity\Contracts\IdentityDirectory::class)->findUser((int) auth()->id());

    $can = static function (string ...$permissions) use ($me): bool {
        foreach ($permissions as $permission) {
            if ($me !== null && $me->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    };

    $current = request()->route()?->getName();
@endphp
<aside class="sidebar">
    <div class="brand">پنل عملیات پلتفرم</div>
    <nav>
        <a href="{{ route('admin.dashboard') }}" class="{{ $current === 'admin.dashboard' ? 'active' : '' }}">داشبورد</a>

        @if ($can('platform.kyc.review'))
            <div class="group-label">انطباق</div>
            <a href="{{ route('admin.kyc.index') }}" class="{{ str_starts_with((string) $current, 'admin.kyc') ? 'active' : '' }}">صف KYC</a>
        @endif

        @if ($can('platform.aml.manage'))
            <a href="{{ route('admin.aml.index') }}" class="{{ str_starts_with((string) $current, 'admin.aml') ? 'active' : '' }}">پرچم‌های AML</a>
        @endif

        @if ($can('platform.settlement.manage', 'platform.support.view'))
            <div class="group-label">تسویه و دفتر</div>
            <a href="{{ route('admin.settlements.index') }}" class="{{ str_starts_with((string) $current, 'admin.settlements') ? 'active' : '' }}">پایش تسویه</a>
        @endif

        @if ($can('platform.settlement.manage', 'platform.audit.view'))
            <a href="{{ route('admin.ledger.reconciliation') }}" class="{{ str_starts_with((string) $current, 'admin.ledger.recon') ? 'active' : '' }}">تطبیق دفتر</a>
        @endif

        @if ($can('platform.ledger.adjust'))
            <a href="{{ route('admin.ledger.adjustments.index') }}" class="{{ str_starts_with((string) $current, 'admin.ledger.adjustments') ? 'active' : '' }}">اصلاح دستی دفتر</a>
        @endif

        @if ($can('platform.support.view', 'platform.settlement.manage'))
            <div class="group-label">پرونده‌ها</div>
            <a href="{{ route('admin.disputes.index') }}" class="{{ str_starts_with((string) $current, 'admin.disputes') ? 'active' : '' }}">اختلافات</a>
        @endif

        @if ($can('platform.vault.manage', 'platform.support.view'))
            <a href="{{ route('admin.vaults.index') }}" class="{{ str_starts_with((string) $current, 'admin.vaults') ? 'active' : '' }}">موجودی خزانه</a>
        @endif

        @if ($can('platform.support.view'))
            <div class="group-label">اعضا</div>
            <a href="{{ route('admin.organizations.index') }}" class="{{ str_starts_with((string) $current, 'admin.organizations') ? 'active' : '' }}">سازمان‌ها</a>
        @endif

        @if ($can('platform.audit.view'))
            <div class="group-label">نظارت</div>
            <a href="{{ route('admin.audit.index') }}" class="{{ str_starts_with((string) $current, 'admin.audit') ? 'active' : '' }}">لاگ ممیزی</a>
        @endif

        @if ($can('platform.admin.all'))
            <a href="{{ route('admin.settings.index') }}" class="{{ str_starts_with((string) $current, 'admin.settings') ? 'active' : '' }}">تنظیمات</a>
        @endif
    </nav>
</aside>
