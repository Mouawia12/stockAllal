{{--
    Menu "السنة المالية" de la barre du haut (App\Http\Middleware\ViewExercise) : l'exercice en cours, ou un exercice
    clos d'une branche dont le compte voit les ventes ; seulement s'il y en a. Un POST, comme le selecteur de branche.
    Ouverture / fermeture / fleches : details.drop dans layouts/app.
--}}
@php
    $exUser = auth()->user();
    $exChoices = $exUser && $exUser->can('sales.view') ? App\Models\Exercise::closedOf($exUser->salesBranchIds()) : collect();
    $exViewing = $viewingExercise ?? null;
    $exSeveral = $exChoices->pluck('branch_id')->unique()->count() > 1;
@endphp
@if ($exChoices->isNotEmpty())
    <details @class(['drop', 'exercise', 'picked' => $exViewing])>
        <summary title="{{ __('exercises.title') }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
            <span class="ex-label">{{ $exViewing ? $exViewing->name : __('exercises.current') }}</span>
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
        </summary>
        <form class="drop-menu ex-menu" method="post" action="{{ route('exercise') }}">
            @csrf
            <button type="submit" name="exercise_id" value="" @class(['on' => ! $exViewing])>
                <span>{{ __('exercises.current') }}</span>
                <span class="muted small">{{ __('exercises.current_help') }}</span>
            </button>
            <p class="ex-head muted small">{{ __('exercises.closed') }}</p>
            @foreach ($exChoices as $ex)
                <button type="submit" name="exercise_id" value="{{ $ex->id }}" @class(['on' => $exViewing?->id === $ex->id])>
                    <span>@if ($exSeveral)<span class="bdot" style="--bh: {{ $ex->branch->hue() }}"></span> @endif<b>{{ $ex->name ?? '#'.$ex->id }}</b>
                        @if ($exSeveral)<span class="muted small">{{ $ex->branch->name }}</span>@endif</span>
                    <span class="muted small num">@if ($ex->first_day && $ex->last_day){{ $ex->first_day->format('d/m/Y') }} → {{ $ex->last_day->format('d/m/Y') }}@endif</span>
                </button>
            @endforeach
        </form>
    </details>
    @once
    <style>
        /* comme le selecteur de branche (partials/branch-switch) ; un exercice clos ouvert se voit (accent) */
        .exercise summary { display: flex; align-items: center; gap: 6px; padding: 5px 10px; border: 1px solid var(--border);
            border-radius: 99px; color: var(--text); font-size: 14px; font-weight: 500; white-space: nowrap; max-width: 200px; }
        .exercise summary:hover { background: var(--row-hover); }
        .exercise[open] summary { border-color: var(--accent); }
        .exercise.picked summary { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 600; }
        .exercise summary svg { width: 17px; height: 17px; flex: none; color: var(--accent); }
        .exercise summary .chev { width: 14px; height: 14px; color: var(--muted); }
        .ex-label { overflow: hidden; text-overflow: ellipsis; }
        .exercise .drop-menu { min-width: 300px; max-height: 70vh; overflow-y: auto; }
        .ex-menu button { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; width: 100%; border: 0; background: none; text-align: start; padding: 8px 10px; border-radius: 8px; }
        .ex-menu button:hover { background: var(--row-hover); }
        .ex-menu button.on { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
        .ex-menu .ex-head { margin: 8px 10px 2px; }
        @media (max-width: 700px) { .ex-label { max-width: 80px; } }
    </style>
    @endonce
@endif
