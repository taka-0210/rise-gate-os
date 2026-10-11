@foreach($row['sources'] as $citation)
@if(in_array($citation['type'], \App\Services\AiCommon\AiCommonManagementContext::TYPES, true))
<p style="overflow-wrap:anywhere">参照元：{{ $citation['data']['title'] }} ／ {{ $citation['data']['period']['organization_name'] ?? '' }} ／ {{ $citation['data']['content']['group_name_at_approval'] ?? '' }} ／ Revision {{ $citation['data']['revision_no'] }} <a href="{{ $citation['data']['source_url'] }}">正式版の出典</a></p>
@if(isset($citation['data']['supplemental_period_metadata']))
<p>期番号補足：第{{ $citation['data']['supplemental_period_metadata']['fiscal_term_number'] }}期 ／ 情報源：正式期間DB Version {{ $citation['data']['supplemental_period_metadata']['period_version'] }}。現在の期間情報であり、承認済みSnapshot・方針本文の一部ではありません。</p>
@endif
@endif
@endforeach
