@extends('layouts.app')

{{-- En-tete et pied des impressions d'une branche (BranchController::printHeader) : ce que Gescomm garde dans GC032. --}}
@php
    $H = __('print.header');
@endphp
@section('title', __('print.header.title', ['name' => $branch->name]).' — '.__('common.app_name'))

@push('head')
<style>
    .page-title { font-size: 20px; margin: 0 0 6px; }
    .ph-form { display: grid; gap: 14px; max-width: 720px; }
    .ph-form label { display: grid; gap: 6px; font-weight: 600; }
    .ph-form textarea { width: 100%; padding: 8px 10px; font: inherit; font-weight: 400; }
    .ph-actions { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
@endpush

@push('keys')
    @include('partials.key', ['keys' => [__('common.keys.esc')], 'run' => 'Escape', 'label' => __('common.keys.back')])
@endpush

@section('content')
<h1 class="page-title">{{ __('print.header.title', ['name' => $branch->name]) }}</h1>
<p class="muted small">{{ $H['help'] }}</p>
<form class="card ph-form" method="post" action="{{ route('branches.print-header', $branch) }}">
    @csrf @method('put')
    <label>{{ $H['lines'] }}
        <textarea name="print_header" rows="5" maxlength="600" dir="auto">{{ old('print_header', $branch->print_header) }}</textarea>
    </label>
    <label>{{ $H['footer'] }}
        <textarea name="print_footer" rows="2" maxlength="400" dir="auto">{{ old('print_footer', $branch->print_footer) }}</textarea>
    </label>
    <div class="ph-actions">
        <button class="primary" type="submit">{{ __('common.save') }}</button>
        <a class="btn" href="{{ route('branches.index') }}" data-key="Escape">{{ $H['back'] }}</a>
    </div>
</form>
@endsection
