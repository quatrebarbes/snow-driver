@extends('layouts.app')

@section('content')
    <div class="breadcrumb">
        <a href="{{ route('tables.index') }}">← Tables</a> /
        <a href="{{ route('tables.show', $table) }}">{{ $label }}</a>
    </div>

    @if (! $record)
        <div class="error-box">Aucun enregistrement {{ $sysId }} dans {{ $table }} (supprimé, ou droits insuffisants).</div>
    @else
        <h1>{{ $label }} — {{ $record->display('number') !== '' ? $record->display('number') : $sysId }}</h1>
        <dl>
            @foreach ($record->getAttributes() as $key => $value)
                <dt>{{ $key }}</dt>
                <dd>{{ $record->display($key) }}</dd>
            @endforeach
        </dl>
    @endif
@endsection
