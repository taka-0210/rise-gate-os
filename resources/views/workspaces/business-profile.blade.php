@extends('layouts.app', ['title' => '事業者情報 - '.$workspace->name])

@section('content')
<section class="stack">
    <div><div class="meta">{{ $workspace->name }} / 帳票の発行元</div><h1>事業者情報</h1><p>見積書・納品書・請求書・お客様向け資料に使う会社情報を管理します。</p></div>
    @if(session('status'))<div class="panel">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('workspace-business-profile.update') }}" enctype="multipart/form-data" class="stack">@csrf @method('PUT')
        <section class="card stack"><div><h2>会社情報</h2><p class="meta">帳票に表示する正式な発行元情報です。</p></div><div class="profile-grid">
            <div class="field"><label>法人名・正式名称</label><input name="legal_name" value="{{ old('legal_name',$profile?->legal_name) }}" @disabled(!$canManage)></div>
            <div class="field"><label>屋号・表示名</label><input name="trade_name" value="{{ old('trade_name',$profile?->trade_name) }}" @disabled(!$canManage)></div>
            <div class="field"><label>郵便番号</label><input name="postal_code" value="{{ old('postal_code',$profile?->postal_code) }}" @disabled(!$canManage)></div>
            <div class="field span-2"><label>住所</label><input name="address_line1" value="{{ old('address_line1',$profile?->address_line1) }}" @disabled(!$canManage)></div>
            <div class="field span-2"><label>建物名など</label><input name="address_line2" value="{{ old('address_line2',$profile?->address_line2) }}" @disabled(!$canManage)></div>
            <div class="field"><label>電話番号</label><input name="phone" value="{{ old('phone',$profile?->phone) }}" @disabled(!$canManage)></div>
            <div class="field"><label>メールアドレス</label><input type="email" name="email" value="{{ old('email',$profile?->email) }}" @disabled(!$canManage)></div>
            <div class="field"><label>代表者役職</label><input name="representative_title" value="{{ old('representative_title',$profile?->representative_title) }}" @disabled(!$canManage)></div>
            <div class="field"><label>代表者名</label><input name="representative_name" value="{{ old('representative_name',$profile?->representative_name) }}" @disabled(!$canManage)></div>
            <div class="field span-2"><label>適格請求書発行事業者登録番号</label><input name="invoice_registration_number" placeholder="T1234567890123" value="{{ old('invoice_registration_number',$profile?->invoice_registration_number) }}" @disabled(!$canManage)></div>
        </div></section>
        <section class="card stack"><div><h2>ロゴ・印章</h2><p class="meta">PNG・JPG・WebP、各5MBまで。透過PNGがおすすめです。</p></div><div class="media-grid">
            <div class="field"><label>会社ロゴ</label>@if($profile?->logo_path)<img class="profile-media" src="{{ route('workspace-business-profile.media','logo') }}" alt="登録済み会社ロゴ"><label class="remove"><input type="checkbox" name="remove_logo" value="1">登録済みロゴを削除</label>@endif<input type="file" name="logo" accept="image/png,image/jpeg,image/webp" @disabled(!$canManage)></div>
            <div class="field"><label>印章</label>@if($profile?->seal_path)<img class="profile-media seal-preview" src="{{ route('workspace-business-profile.media','seal') }}" alt="登録済み印章"><label class="remove"><input type="checkbox" name="remove_seal" value="1">登録済み印章を削除</label>@endif<input type="file" name="seal" accept="image/png,image/jpeg,image/webp" @disabled(!$canManage)></div>
        </div></section>
        <section class="card stack"><div><h2>主振込口座</h2><p class="meta">請求書へ表示する標準の振込先です。将来の複数口座にも対応できる構造です。</p></div><div class="profile-grid">
            <div class="field"><label>金融機関名</label><input name="bank_name" value="{{ old('bank_name',$bankAccount?->bank_name) }}" @disabled(!$canManage)></div>
            <div class="field"><label>支店名</label><input name="branch_name" value="{{ old('branch_name',$bankAccount?->branch_name) }}" @disabled(!$canManage)></div>
            <div class="field"><label>口座種別</label><select name="account_type" @disabled(!$canManage)>@foreach($accountTypes as $value=>$label)<option value="{{ $value }}" @selected(old('account_type',$bankAccount?->account_type??'ordinary')===$value)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label>口座番号</label><input name="account_number" value="{{ old('account_number',$bankAccount?->account_number) }}" @disabled(!$canManage)></div>
            <div class="field span-2"><label>口座名義</label><input name="account_holder" value="{{ old('account_holder',$bankAccount?->account_holder) }}" @disabled(!$canManage)></div>
        </div></section>
        <section class="card"><div class="field"><label>帳票共通備考</label><textarea name="document_note" rows="4" @disabled(!$canManage)>{{ old('document_note',$profile?->document_note) }}</textarea></div></section>
        @if($canManage)<div class="actions"><button type="submit">事業者情報を保存</button></div>@else<div class="panel">OwnerまたはAdminのみ編集できます。</div>@endif
    </form>
</section>
<style>.profile-grid,.media-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.span-2{grid-column:1/-1}.profile-media{display:block;max-width:260px;max-height:120px;margin:8px 0;padding:8px;border:1px solid var(--line);border-radius:8px;background:#fff;object-fit:contain}.seal-preview{width:110px;height:110px}.remove{display:flex;align-items:center;gap:7px;margin:7px 0;color:var(--muted);font-size:12px}.remove input{width:auto}@media(max-width:700px){.profile-grid,.media-grid{grid-template-columns:1fr}.span-2{grid-column:auto}}</style>
@endsection
