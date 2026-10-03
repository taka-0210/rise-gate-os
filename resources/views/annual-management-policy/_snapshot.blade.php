@php($annual = $snapshot['annual'] ?? [])
<div class="amp-sections">
    @php($term = data_get($annual, 'period.organization_fiscal_term_number'))
    <section class="amp-reader-period"><p class="amp-kicker">PERIOD</p><h2>{{ $term ? '第'.$term.'期｜' : '' }}{{ data_get($annual, 'period.declared_name') ?: data_get($annual, 'period.organization_name') }}</h2><p>{{ data_get($annual, 'period.declared_starts_on') ?: data_get($annual, 'period.organization_starts_on') }} — {{ data_get($annual, 'period.declared_ends_on') ?: data_get($annual, 'period.organization_ends_on') }} / Asia/Tokyo</p></section>
    @if(filled(data_get($annual, 'purpose')))<section class="amp-section amp-section--purpose"><p class="amp-kicker">今期、何を実現したいのか</p><div class="amp-prose">{{ data_get($annual, 'purpose') }}</div></section>@endif
    @if(filled(data_get($annual, 'background')))<section class="amp-section"><p class="amp-kicker">この方針を定める背景</p><div class="amp-prose">{{ data_get($annual, 'background') }}</div></section>@endif
    <section class="amp-section amp-section--policy"><p class="amp-kicker">年度経営方針</p><div class="amp-prose amp-prose--policy">{{ data_get($annual, 'policy') }}</div></section>
    @foreach(data_get($annual, 'themes', []) as $theme)
        <section class="amp-section"><p class="amp-kicker">重点テーマ {{ $loop->iteration }}</p><h2 class="amp-prose">{{ $theme['statement'] }}</h2>@if(filled($theme['explanation'] ?? null))<p class="amp-prose">{{ $theme['explanation'] }}</p>@endif
            @foreach($theme['priorities'] ?? [] as $priority)<div class="amp-section amp-section--nested"><p class="amp-kicker">優先方針 {{ $loop->iteration }}</p><h3 class="amp-prose">{{ $priority['statement'] }}</h3>@if(filled($priority['explanation'] ?? null))<p class="amp-prose">{{ $priority['explanation'] }}</p>@endif</div>@endforeach
        </section>
    @endforeach
    @foreach(data_get($annual, 'departments', []) as $department)
        <section class="amp-section"><p class="amp-kicker">部署方針</p><h2>{{ $department['group_name_at_approval'] }}</h2>@if(filled($department['introduction'] ?? null))<p class="amp-prose">{{ $department['introduction'] }}</p>@endif
            @foreach($department['statements'] ?? [] as $statement)<div class="amp-section amp-section--nested"><h3 class="amp-prose">{{ $statement['statement'] }}</h3>@if(filled($statement['explanation'] ?? null))<p class="amp-prose">{{ $statement['explanation'] }}</p>@endif</div>@endforeach
        </section>
    @endforeach
    @if(empty(data_get($annual, 'themes', [])) && empty(data_get($annual, 'departments', [])))<div class="amp-empty">重点テーマ・部署方針は登録されていません。0件でも正式な方針として成立します。</div>@endif
</div>
