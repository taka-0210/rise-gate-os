@php
$fields=[
['financial_institution','金融機関','text'],['management_number','管理番号','text'],['purpose','資金用途','text'],['executed_on','借入実行日','date'],['term_label','返済期間','text'],
['original_amount','当初借入額','number'],['current_balance','現在残高','number'],['monthly_principal_payment','月額元金返済','number'],['annual_interest_rate','年利（%）','number'],['recent_interest_amount','直近利息','number'],
['maturity_on','完済予定日','date'],['completed_on','完済日（実績）','date'],['guarantee_type','保証・区分','text'],['repayment_day','返済日','text'],['balance_as_of','残高基準日','date'],
];
@endphp
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<div class="loan-form-grid">
@foreach($fields as [$name,$label,$type])<label><span>{{ $label }}</span><input type="{{ $type }}" name="{{ $name }}" @if($type==='number') min="0" step="{{ $name==='annual_interest_rate'?'0.0001':'1' }}" @endif value="{{ old($name, data_get($values,$name) instanceof \Carbon\CarbonInterface ? data_get($values,$name)->format('Y-m-d') : data_get($values,$name, in_array($name,['original_amount','current_balance','monthly_principal_payment','recent_interest_amount'])?0:'')) }}" @required(in_array($name,['financial_institution','management_number','original_amount','current_balance','monthly_principal_payment','balance_as_of']))></label>@endforeach
<label><span>残高計算方式</span><select name="balance_projection_mode">@foreach(['amortizing'=>'元金返済（月額元金ずつ減少）','hold'=>'据置（残高を維持）','bullet'=>'期日一括（期日に0円）','revolving'=>'当座貸越（残高を維持）'] as $value=>$label)<option value="{{ $value }}" @selected(old('balance_projection_mode',data_get($values,'balance_projection_mode',data_get($values,'monthly_principal_payment',1)==0?'hold':'amortizing'))===$value)>{{ $label }}</option>@endforeach</select></label>
<label><span>金利区分</span><select name="interest_type"><option value="">未設定</option>@foreach(['fixed'=>'固定','variable'=>'変動','other'=>'その他'] as $value=>$label)<option value="{{ $value }}" @selected(old('interest_type',data_get($values,'interest_type'))===$value)>{{ $label }}</option>@endforeach</select></label>
<label><span>状態</span><select name="loan_status">@foreach(['active'=>'借入中','completed'=>'完済','planned'=>'実行予定'] as $value=>$label)<option value="{{ $value }}" @selected(old('loan_status',data_get($values,'loan_status','active'))===$value)>{{ $label }}</option>@endforeach</select></label>
<label class="wide"><span>備考</span><textarea name="notes" rows="3">{{ old('notes',data_get($values,'notes')) }}</textarea></label>
</div>
<style>.loan-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.loan-form-grid label{display:flex;flex-direction:column;gap:6px}.loan-form-grid .wide{grid-column:1/-1}@media(max-width:700px){.loan-form-grid{grid-template-columns:1fr}.loan-form-grid .wide{grid-column:auto}}</style>
