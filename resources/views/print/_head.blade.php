{{--
    En-tete d'une impression : au debut la societe (Branch::printHeaderLines, saisis sur la page des branches), a la fin
    la piece : $title, $big (son numero), $sub (les lignes dessous). $letter : la branche dont l'en-tete s'imprime ;
    aucune (plusieurs branches sans la principale) = le nom de l'application.
--}}
@php
    $lh = $letter?->printHeaderLines() ?? [__('common.app_name')];
@endphp
<header class="doc-head">
    <div class="lh">
        <span class="lh-name"><bdi>{{ $lh[0] }}</bdi></span>
        @foreach (array_slice($lh, 1) as $line)
            <span class="lh-line"><bdi>{{ $line }}</bdi></span>
        @endforeach
    </div>
    <div class="doc-title">
        <p class="doc-kind">{{ $title }}</p>
        @isset($big)<p class="doc-no ltr">{{ $big }}</p>@endisset
        @foreach ($sub ?? [] as $line)
            @if ($line)<p class="doc-sub"><bdi>{{ $line }}</bdi></p>@endif
        @endforeach
    </div>
</header>
