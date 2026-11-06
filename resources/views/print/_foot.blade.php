{{-- Bas de l'impression : le pied de la societe ($letter) et quand elle a ete imprimee ($by : le nom de qui imprime, sur les etats internes). --}}
<footer class="doc-foot">
    <div class="lines">
        @foreach ($letter?->printFooterLines() ?? [] as $line)
            <span><bdi>{{ $line }}</bdi></span>
        @endforeach
    </div>
    <div class="stamp">
        @if ($by ?? false)
            {{ __('print.printed_by', ['date' => now()->format('d/m/Y'), 'time' => now()->format('H:i'), 'name' => auth()->user()->name]) }}
        @else
            {{ __('print.printed_at', ['date' => now()->format('d/m/Y'), 'time' => now()->format('H:i')]) }}
        @endif
    </div>
</footer>
