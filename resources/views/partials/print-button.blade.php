{{-- Le bouton "طباعة" d'une page : ouvre sa page d'impression dans un nouvel onglet, la liste reste ou elle etait. $href ; $label pour un autre texte. --}}
<a class="btn print-btn" href="{{ $href }}" target="_blank" data-key="Alt+P" title="{{ __('print.print') }} (Alt+P)">@include('partials.app-icon', ['name' => 'print']){{ $label ?? __('print.print') }}</a>
