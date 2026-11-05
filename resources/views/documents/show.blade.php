@extends('layouts.app')

{{--
    Un bon de vente ou d'achat (DocumentController), comme le "Bon de Livraison" de Gescomm : le chemin de retour a la
    liste, puis le bon lui-meme (documents/_bon, le meme que l'apercu F2 de la liste).
--}}
@php
    $D = __('documents');
@endphp
@section('title', $D['bon'][$kind].' '.$document->number.' — '.__('common.app_name'))

@push('head')
@include('documents._bon-style')
<style>
    .crumbs { display: flex; align-items: center; gap: 8px; margin: 0 0 14px; color: var(--muted); font-size: 14px; }
    .crumbs a { color: var(--muted); }
    .crumbs a:hover { color: var(--accent); }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'run' => 'Escape', 'label' => __('common.keys.back')])
@endpush

@section('content')
<nav class="crumbs" aria-label="{{ $D['crumbs'] }}">
    {{-- Echap ou clic : retour a la liste d'ou l'on vient, avec sa recherche et sa periode (data-back, partials/keys) --}}
    <a href="{{ route("{$route}.index") }}" data-key="Escape" data-back>{{ $D['title'][$kind] }}</a>
    <span aria-hidden="true">/</span>
    <span class="num">{{ $document->number }}</span>
</nav>

@include('documents._bon')
@endsection
