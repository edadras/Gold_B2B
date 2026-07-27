@extends('admin::layouts.app')
@section('title', 'درخواست اصلاح دستی دفتر')

@section('content')
    <div class="panel">
        <h2>اطلاعات اصلاح</h2>
        <div class="body">
            <p class="hint danger">
                ثبت این فرم هیچ ثبتی در دفتر ایجاد نمی‌کند. تنها یک «درخواست» ساخته می‌شود که برای اجرا
                نیازمند تأیید مدیر پلتفرم — کاربری غیر از شما — است.
            </p>

            <form method="POST" action="{{ route('admin.ledger.adjustments.store') }}" enctype="multipart/form-data">
                @csrf

                <div class="field">
                    <label for="organization_id">شناسه سازمان</label>
                    <input id="organization_id" name="organization_id" type="number" min="1" value="{{ old('organization_id') }}" required>
                </div>

                <div class="field">
                    <label for="asset_type">دارایی</label>
                    <select id="asset_type" name="asset_type" required>
                        @foreach ($assets as $asset)
                            <option value="{{ $asset->value }}" @selected(old('asset_type') === $asset->value)>{{ $asset->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="amount">مقدار (عدد صحیح — منفی برای کاهش)</label>
                    <input id="amount" name="amount" type="number" step="1" value="{{ old('amount') }}" required>
                    <div class="hint">طلا بر حسب میلی‌گرم خالص و ریال بر حسب ریال. اعشار پذیرفته نمی‌شود.</div>
                </div>

                <div class="field">
                    <label for="offset_account">حساب طرف مقابل</label>
                    <select id="offset_account" name="offset_account" required>
                        @foreach ($offsets as $offset)
                            <option value="{{ $offset->value }}" @selected(old('offset_account') === $offset->value)>
                                {{ $offset->label() }} ({{ implode('، ', array_map(fn ($a) => $a->label(), $offset->assets())) }})
                            </option>
                        @endforeach
                    </select>
                    <div class="hint">برای حفظ بقای جرم الزامی است: طرف دیگر ثبت روی این حساب می‌نشیند.</div>
                </div>

                <div class="field">
                    <label for="reason">دلیل (اجباری و مفصل — دست‌کم ۵۰ نویسه)</label>
                    <textarea id="reason" name="reason" rows="5" minlength="50" required>{{ old('reason') }}</textarea>
                </div>

                <div class="field">
                    <label for="supporting_document">مستند پشتیبان</label>
                    <input id="supporting_document" name="supporting_document" type="file" required>
                </div>

                <button type="submit" class="primary">ثبت درخواست</button>
            </form>
        </div>
    </div>
@endsection
