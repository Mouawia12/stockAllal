{{-- Un reglage de la barre d'impression : son nom, puis une pastille par valeur (un lien : ?param=valeur). --}}
<span class="choice" role="group" aria-label="{{ $label }}">
    {{ $label }}
    <span class="seg">
        @foreach ($options as $value => $text)
            <a href="{{ request()->fullUrlWithQuery([$param => $value, 'auto' => null]) }}" @class(['on' => (string) $current === (string) $value]) @if ((string) $current === (string) $value) aria-current="true" @endif>{{ $text }}</a>
        @endforeach
    </span>
</span>
