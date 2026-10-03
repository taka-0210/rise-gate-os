@php($annual = $snapshot['annual'] ?? [])
<div class="amp-sections">
    <section class="amp-section"><p class="amp-kicker">PERIOD</p><h2>{{ data_get($annual, 'period.declared_name') ?: data_get($annual, 'period.organization_name') }}</h2><p>{{ data_get($annual, 'period.declared_starts_on') ?: data_get($annual, 'period.organization_starts_on') }} – {{ data_get($annual, 'period.declared_ends_on') ?: data_get($annual, 'period.organization_ends_on') }}</p></section>
    <section class="amp-section"><p class="amp-kicker">POLICY / REQUIRED</p><div class="amp-prose">{{ data_get($annual, 'policy') }}</div></section>
    @if(filled(data_get($annual, 'purpose')))<section class="amp-section"><p class="amp-kicker">PURPOSE</p><div class="amp-prose">{{ data_get($annual, 'purpose') }}</div></section>@endif
    @if(filled(data_get($annual, 'background')))<section class="amp-section"><p class="amp-kicker">BACKGROUND</p><div class="amp-prose">{{ data_get($annual, 'background') }}</div></section>@endif
    @foreach(data_get($annual, 'themes', []) as $theme)
        <section class="amp-section"><p class="amp-kicker">THEME {{ $loop->iteration }}</p><h2 class="amp-prose">{{ $theme['statement'] }}</h2>@if(filled($theme['explanation'] ?? null))<p class="amp-prose">{{ $theme['explanation'] }}</p>@endif
            @foreach($theme['priorities'] ?? [] as $priority)<div class="amp-section amp-section--nested"><p class="amp-kicker">PRIORITY {{ $loop->iteration }}</p><h3 class="amp-prose">{{ $priority['statement'] }}</h3>@if(filled($priority['explanation'] ?? null))<p class="amp-prose">{{ $priority['explanation'] }}</p>@endif</div>@endforeach
        </section>
    @endforeach
    @foreach(data_get($annual, 'departments', []) as $department)
        <section class="amp-section"><p class="amp-kicker">DEPARTMENT</p><h2>{{ $department['group_name_at_approval'] }}</h2>@if(filled($department['introduction'] ?? null))<p class="amp-prose">{{ $department['introduction'] }}</p>@endif
            @foreach($department['statements'] ?? [] as $statement)<div class="amp-section amp-section--nested"><h3 class="amp-prose">{{ $statement['statement'] }}</h3>@if(filled($statement['explanation'] ?? null))<p class="amp-prose">{{ $statement['explanation'] }}</p>@endif</div>@endforeach
        </section>
    @endforeach
    @if(empty(data_get($annual, 'themes', [])) && empty(data_get($annual, 'departments', [])))<div class="amp-empty">Theme / Departmentは登録されていません。0件でも正式な方針として成立します。</div>@endif
</div>
