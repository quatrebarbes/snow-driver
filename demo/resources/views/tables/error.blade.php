@extends('layouts.app')

@section('content')
    <div class="breadcrumb">
        <a href="{{ route('tables.index') }}">← Tables</a> /
        <a href="{{ route('tables.show', $table) }}">{{ $label }}</a>
    </div>

    <h1>Erreur ServiceNow</h1>

    <div class="error-box">
        <p><strong>{{ class_basename($exception) }}</strong></p>
        <p>{{ $exception->getMessage() }}</p>
        @if (method_exists($exception, 'serviceNowDetail') && $exception->serviceNowDetail())
            <p><em>{{ $exception->serviceNowDetail() }}</em></p>
        @endif
    </div>

    <p>Cette page illustre la hiérarchie d'exceptions dédiées du driver (EX-119, EX-120, EX-126, EX-130) : aucune erreur ServiceNow n'est masquée par un résultat vide silencieux.</p>
@endsection
