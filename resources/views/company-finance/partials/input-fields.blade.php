@php
$fields=['period_number'=>'期','fiscal_year'=>'決算年度（西暦）','net_sales'=>'売上高','cost_of_sales'=>'売上原価','selling_general_admin_expenses'=>'販売費及び一般管理費','non_operating_income'=>'営業外収益','non_operating_expenses'=>'営業外費用','interest_expense'=>'内：支払利息','extraordinary_income'=>'特別利益','extraordinary_losses'=>'特別損失','income_taxes'=>'法人税等'];
@endphp
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="input-grid">@foreach($fields as $name=>$label)<label class="{{ $name === 'interest_expense' ? 'sub-field' : '' }}"><span>{{ $label }}</span><input type="number" name="{{ $name }}" min="0" value="{{ old($name, data_get($values,$name,$name === 'interest_expense' ? '' : ($loop->index < 2 ? '' : 0))) }}" @if($name !== 'interest_expense') required @endif placeholder="{{ $name === 'interest_expense' ? '未入力の場合は簡易DSCR' : '' }}"></label>@endforeach</div>
<p class="field-help">支払利息は営業外費用の内数です。営業外費用の金額にはすでに支払利息を含めて入力してください。</p>
<style>.input-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.input-grid label{display:flex;flex-direction:column;gap:6px}.input-grid input{font-variant-numeric:tabular-nums}.input-grid .sub-field{margin-left:18px;padding-left:14px;border-left:3px solid #b7d5ce}.field-help{margin:12px 0 0;font-size:13px}.record-state{display:flex;align-items:center;gap:18px;margin-bottom:16px}.record-state span{color:var(--muted)}@media(max-width:700px){.input-grid{grid-template-columns:1fr}}
</style>
