{{--
    Une touche du bandeau du bas (partials/keys) : $keys = ce qui est ecrit sur les touches, $label = ce qu'elle fait.
    Avec $run (le data-key de l'element vise) : un bouton qui fait la meme chose que la touche.
    $global : touche de toutes les pages ; les autres sont aussi listees "في هذه الشاشة" dans la barre Ctrl+K.
--}}
@if (isset($run))
    <button type="button" class="k" data-run="{{ $run }}" @if ($global ?? false) title="{{ $label }}" @else data-page-key @endif>@foreach ($keys as $k)<kbd>{{ $k }}</kbd>@endforeach<span>{{ $label }}</span></button>
@else
    <span class="k" @if ($global ?? false) title="{{ $label }}" @else data-page-key @endif>@foreach ($keys as $k)<kbd>{{ $k }}</kbd>@endforeach<span>{{ $label }}</span></span>
@endif
