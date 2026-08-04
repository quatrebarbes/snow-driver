@extends('layouts.app')

@section('content')
    <h1>Tables ServiceNow</h1>
    <p>Sélectionnez une table pour parcourir ses enregistrements au travers du driver <code>quatrebarbes/snow-driver</code>.</p>

    <div class="card-grid">
        @foreach ($tables as $name => $meta)
            <div class="card">
                <a href="{{ route('tables.show', $name) }}">{{ $meta['label'] }}</a>
                <div><small>{{ $name }}</small></div>
            </div>
        @endforeach
    </div>
@endsection
