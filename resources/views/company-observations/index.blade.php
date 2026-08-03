@extends('layouts.app', ['title' => '気付き・観察 - Company OS'])

@section('content')
    <section class="stack">
        <div class="page-header">
            <div>
                <div class="meta">Company OS / OBSERVATION FIRST</div>
                <h1>気付き・観察</h1>
                <p>「あれ？」と気付いた変化を、まだ問題や改善と決めずに会社のObservationとして残します。</p>
            </div>
        </div>

        @if (session('status'))
            <div class="card" style="border-color:#8bc5a1;background:#f1faf4;">{{ session('status') }}</div>
        @endif

        <div class="grid" style="align-items:start;">
            <div class="stack">
                @if($canContribute)
                    <form class="panel stack" method="POST" action="{{ route('company-observations.store') }}">
                        @csrf
                        <div>
                            <div class="meta">AWARENESS → OBSERVATION</div>
                            <h2>変化を記録する</h2>
                            <p>ここでは事実だけを記録します。原因や改善案は、次のCompany Dialogueで考えます。</p>
                        </div>

                        <div class="field">
                            <label for="title">何に気付きましたか</label>
                            <input id="title" name="title" value="{{ old('title') }}" maxlength="255" required placeholder="例：同じ内容の問い合わせが増えている">
                            @error('title') <div class="error">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="body">観察した事実</label>
                            <textarea id="body" name="body" rows="5" required placeholder="見たこと、聞いたこと、数値の変化を、そのまま記録します。">{{ old('body') }}</textarea>
                            @error('body') <div class="error">{{ $message }}</div> @enderror
                        </div>

                        <div class="grid">
                            <div class="field">
                                <label for="occurred_on">いつ起きましたか</label>
                                <input id="occurred_on" name="occurred_on" type="date" value="{{ old('occurred_on', now('Asia/Tokyo')->toDateString()) }}">
                                @error('occurred_on') <div class="error">{{ $message }}</div> @enderror
                            </div>
                            <div class="field">
                                <label for="source_type">どこから気付きましたか</label>
                                <select id="source_type" name="source_type" required>
                                    @foreach($sourceTypes as $value => $label)
                                        <option value="{{ $value }}" @selected(old('source_type') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('source_type') <div class="error">{{ $message }}</div> @enderror
                            </div>
                        </div>

                        <div class="field">
                            <label for="source_name">情報源の名前（任意）</label>
                            <input id="source_name" name="source_name" value="{{ old('source_name') }}" maxlength="255" placeholder="例：○○様、月次会議">
                            @error('source_name') <div class="error">{{ $message }}</div> @enderror
                        </div>

                        <div class="field">
                            <label for="source_note">出所についての補足（任意）</label>
                            <textarea id="source_note" name="source_note" rows="2">{{ old('source_note') }}</textarea>
                            @error('source_note') <div class="error">{{ $message }}</div> @enderror
                        </div>

                        <div class="actions">
                            <button type="submit">Observationとして記録</button>
                        </div>
                    </form>
                @endif

                <section class="panel stack">
                    <div>
                        <div class="meta">OBSERVATIONS</div>
                        <h2>会社で観察された変化</h2>
                    </div>
                    @forelse($observations as $observation)
                        <a class="card" href="{{ route('company-observations.show', $observation) }}" @if($selectedObservation?->is($observation)) aria-current="page" style="border-color:var(--accent);" @endif>
                            <div class="meta">
                                {{ $observation->observed_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }}
                                ・{{ $sourceTypes[$observation->source_type] ?? $observation->source_type }}
                                ・{{ $importanceLabels[$observation->importance] ?? $observation->importance }}
                            </div>
                            <h3>{{ $observation->title }}</h3>
                            <p>{{ \Illuminate\Support\Str::limit($observation->body, 120) }}</p>
                            <div class="meta">Sense {{ $observation->senses_count }}件 / Improvement {{ $observation->improvements_count }}件</div>
                        </a>
                    @empty
                        <p>Observationはまだありません。会社で変化に気付いたとき、最初の一件を記録してください。</p>
                    @endforelse
                </section>
            </div>

            <aside class="stack">
                @if($selectedObservation)
                    <section class="panel stack">
                        <div>
                            <div class="meta">OBSERVATION / FACT</div>
                            <h2>{{ $selectedObservation->title }}</h2>
                            <p>{{ $selectedObservation->body }}</p>
                        </div>
                        <div class="card">
                            <div class="meta">SOURCE</div>
                            <p>
                                {{ $sourceTypes[$selectedObservation->source_type] ?? $selectedObservation->source_type }}
                                @if($selectedObservation->source_name) / {{ $selectedObservation->source_name }} @endif
                            </p>
                            <p class="meta">
                                発生日 {{ $selectedObservation->occurred_on?->format('Y/m/d') ?? '不明' }}<br>
                                観察 {{ $selectedObservation->observed_at->timezone('Asia/Tokyo')->format('Y/m/d H:i') }} JST<br>
                                観察者 {{ $selectedObservation->observer?->name ?? '不明' }}
                            </p>
                        </div>
                    </section>

                    @if($canContribute)
                        <form class="panel stack" method="POST" action="{{ route('company-observations.respond', $selectedObservation) }}">
                            @csrf
                            <div>
                                <div class="meta">COMPANY DIALOGUE</div>
                                <h2>この変化は、会社にとって何を意味しますか？</h2>
                            </div>
                            <div class="card" style="background:#f4f8fa;">
                                <div class="meta">WHY NOW?</div>
                                <p>会社の変化として記録されました。まだ意味や改善が決まっていないため、今ここで確認します。</p>
                            </div>

                            <div class="field">
                                <label for="importance">今の判断</label>
                                <select id="importance" name="importance" required>
                                    <option value="important" @selected(old('importance', $selectedObservation->importance) === 'important')>重要な変化として考える</option>
                                    <option value="watching" @selected(old('importance', $selectedObservation->importance) === 'watching')>経過観察する</option>
                                    <option value="unclear" @selected(old('importance', $selectedObservation->importance) === 'unclear')>まだ判断できない</option>
                                    <option value="not_now" @selected(old('importance', $selectedObservation->importance) === 'not_now')>今は扱わない</option>
                                </select>
                                @error('importance') <div class="error">{{ $message }}</div> @enderror
                            </div>

                            <div class="field">
                                <label for="interpretation">意味付け・解釈</label>
                                <textarea id="interpretation" name="interpretation" rows="4" placeholder="例：問い合わせ内容の変化は、顧客ニーズが変わっている兆候かもしれない">{{ old('interpretation') }}</textarea>
                                @error('interpretation') <div class="error">{{ $message }}</div> @enderror
                            </div>

                            <div class="field">
                                <label for="hypothesis">仮説（任意）</label>
                                <textarea id="hypothesis" name="hypothesis" rows="3">{{ old('hypothesis') }}</textarea>
                                @error('hypothesis') <div class="error">{{ $message }}</div> @enderror
                            </div>

                            <div class="field">
                                <label for="next_observation">まだ判断できない場合、次に何を観察しますか</label>
                                <textarea id="next_observation" name="next_observation" rows="3">{{ old('next_observation', $selectedObservation->next_observation) }}</textarea>
                                @error('next_observation') <div class="error">{{ $message }}</div> @enderror
                            </div>

                            <button type="submit">回答を記録</button>
                        </form>
                    @endif

                    @foreach($selectedObservation->senses as $sense)
                        <section class="panel stack">
                            <div>
                                <div class="meta">SENSE / {{ strtoupper($sense->status) }}</div>
                                <h2>このObservationの意味</h2>
                                <p>{{ $sense->interpretation }}</p>
                                @if($sense->hypothesis)<p><strong>仮説：</strong>{{ $sense->hypothesis }}</p>@endif
                            </div>

                            @forelse($sense->improvements as $improvement)
                                <div class="card" style="border-color:#8bc5a1;background:#f1faf4;">
                                    <div class="meta">COMPANY IMPROVEMENT / {{ strtoupper($improvement->status) }}</div>
                                    <h3>{{ $improvement->title }}</h3>
                                    <p>{{ $improvement->desired_state }}</p>
                                </div>
                            @empty
                                @if($canContribute && $sense->status === \App\Models\CompanySense::STATUS_SUPPORTED)
                                    <form class="stack" method="POST" action="{{ route('company-observations.improvements.store', [$selectedObservation, $sense]) }}">
                                        @csrf
                                        <div>
                                            <div class="meta">SENSE → IMPROVEMENT</div>
                                            <h3>この気付きを、どんな変化へ育てますか？</h3>
                                        </div>
                                        <div class="field">
                                            <label for="improvement_title_{{ $sense->id }}">改善の名前</label>
                                            <input id="improvement_title_{{ $sense->id }}" name="title" value="{{ old('title') }}" required maxlength="255" placeholder="例：顧客ニーズの変化をサービスへ反映する">
                                            @error('title') <div class="error">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="field">
                                            <label for="desired_state_{{ $sense->id }}">目指す状態</label>
                                            <textarea id="desired_state_{{ $sense->id }}" name="desired_state" rows="4" required>{{ old('desired_state') }}</textarea>
                                            @error('desired_state') <div class="error">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="field">
                                            <label for="expected_effect_{{ $sense->id }}">期待する変化（任意）</label>
                                            <textarea id="expected_effect_{{ $sense->id }}" name="expected_effect" rows="3">{{ old('expected_effect') }}</textarea>
                                            @error('expected_effect') <div class="error">{{ $message }}</div> @enderror
                                        </div>
                                        <div class="field">
                                            <label for="priority_{{ $sense->id }}">優先度</label>
                                            <select id="priority_{{ $sense->id }}" name="priority">
                                                @foreach($priorities as $value => $label)<option value="{{ $value }}" @selected(old('priority', \App\Models\CompanyImprovement::PRIORITY_NORMAL) === $value)>{{ $label }}</option>@endforeach
                                            </select>
                                            @error('priority') <div class="error">{{ $message }}</div> @enderror
                                        </div>
                                        <button type="submit">Company Improvementとして育てる</button>
                                    </form>
                                @endif
                            @endforelse
                        </section>
                    @endforeach

                    @if($selectedObservation->next_observation)
                        <section class="card">
                            <div class="meta">NEXT OBSERVATION</div>
                            <h3>次に観察すること</h3>
                            <p>{{ $selectedObservation->next_observation }}</p>
                        </section>
                    @endif
                @else
                    <section class="panel stack">
                        <div class="meta">COMPANY DIALOGUE</div>
                        <h2>Observationを選択してください</h2>
                        <p>観察した事実を開くと、意味付けとImprovementへつなぐ対話が始まります。</p>
                    </section>
                @endif
            </aside>
        </div>
    </section>
@endsection
