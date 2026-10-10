@foreach($row['sources'] as $citation)
@if(in_array($citation['type'], \App\Services\AiCommon\AiCommonManagementContext::TYPES, true))
<p style="overflow-wrap:anywhere">参照元：{{ $citation['data']['title'] }} ／ {{ $citation['data']['period']['organization_name'] ?? '' }} ／ {{ $citation['data']['content']['group_name_at_approval'] ?? '' }} ／ Revision {{ $citation['data']['revision_no'] }} <a href="{{ $citation['data']['source_url'] }}">正式版の出典</a></p>
@endif
@endforeach
