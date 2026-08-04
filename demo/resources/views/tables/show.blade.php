@extends('layouts.app')

@section('content')
    <div class="breadcrumb"><a href="{{ route('tables.index') }}">← Tables</a></div>
    <h1>{{ $label }}</h1>

    @if ($records->isEmpty())
        <p>Aucun enregistrement.</p>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($records as $record)
                    <tr>
                        @foreach ($columns as $column)
                            <td>
                                @if ($loop->first)
                                    <a class="row-link" href="{{ route('tables.record', [$table, $record->sys_id]) }}">{{ $record->display($column) }}</a>
                                @else
                                    {{ $record->display($column) }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="pagination">
        @if ($page > 1)
            <a href="{{ route('tables.show', ['table' => $table, 'page' => $page - 1]) }}">← Page précédente</a>
        @endif
        <span>Page {{ $page }}</span>
        @if ($hasMore)
            <a href="{{ route('tables.show', ['table' => $table, 'page' => $page + 1]) }}">Page suivante →</a>
        @endif
    </div>
@endsection
