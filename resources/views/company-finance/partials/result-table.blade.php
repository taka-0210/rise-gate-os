<table class="finance-table"><tbody>
@foreach(['period_number'=>'期','fiscal_year'=>'年度','net_sales'=>'売上高','cost_of_sales'=>'売上原価','gross_profit'=>'売上総利益','gross_profit_ratio'=>'粗利率','selling_general_admin_expenses'=>'販管費','operating_profit'=>'営業利益','operating_profit_ratio'=>'営業利益率','ordinary_profit'=>'経常利益','profit_before_tax'=>'税引前利益','net_income'=>'当期純利益'] as $key=>$label)
<tr><th>{{ $label }}</th><td>@if(str_ends_with($key,'ratio')){{ number_format((float)$row[$key]*100,1) }}% @elseif($key==='period_number'){{ $row[$key] }}期 @elseif($key==='fiscal_year'){{ $row[$key] }}年 @else{{ number_format($row[$key]) }}円 @endif</td></tr>
@endforeach</tbody></table>
<style>.finance-table{width:100%;border-collapse:collapse}.finance-table th,.finance-table td{padding:9px 12px;border-bottom:1px solid var(--line)}.finance-table th{text-align:left}.finance-table td{text-align:right;font-variant-numeric:tabular-nums}</style>
