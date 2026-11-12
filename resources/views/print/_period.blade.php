{{-- La periode d'une impression : du ... au ... ; les autres reglages de la page restent dans l'adresse. $window : PrintPeriod::read --}}
<form method="get" action="{{ url()->current() }}">
    @foreach (request()->except(['from', 'to', 'auto']) as $name => $value)
        @if (is_string($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
    @endforeach
    <label>{{ __('print.from') }} <input type="date" name="from" value="{{ $window['from'] }}"></label>
    <label>{{ __('print.to') }} <input type="date" name="to" value="{{ $window['to'] ?? App\Models\Scopes\ExerciseScope::now()->toDateString() }}"></label>
    <button type="submit">{{ __('print.apply') }}</button>
</form>
