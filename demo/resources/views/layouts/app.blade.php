<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f5f6f8; color: #1c1e21; }
        header { background: #16324f; color: #fff; padding: 1rem 1.5rem; }
        header a { color: #fff; text-decoration: none; font-weight: 600; font-size: 1.1rem; }
        main { padding: 1.5rem; max-width: 1100px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #e2e5ea; text-align: left; font-size: 0.9rem; }
        th { background: #eef1f5; }
        tr:hover td { background: #f8fafc; }
        a.row-link { color: #16324f; text-decoration: none; }
        a.row-link:hover { text-decoration: underline; }
        .card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem; }
        .card { background: #fff; border: 1px solid #e2e5ea; border-radius: 8px; padding: 1rem; }
        .card a { color: #16324f; font-weight: 600; text-decoration: none; }
        .card small { color: #667; }
        .error-box { background: #fdecea; border: 1px solid #f5c2c0; color: #7a1f1a; padding: 1rem; border-radius: 8px; }
        .pagination { margin-top: 1rem; display: flex; gap: 0.75rem; align-items: center; font-size: 0.9rem; }
        .breadcrumb { margin-bottom: 1rem; font-size: 0.9rem; }
        .breadcrumb a { color: #16324f; }
        dl { background: #fff; border-radius: 8px; padding: 1rem; }
        dt { font-weight: 600; color: #445; margin-top: 0.5rem; }
        dd { margin: 0 0 0.25rem 0; word-break: break-word; }
    </style>
</head>
<body>
    <header>
        <a href="{{ route('tables.index') }}">{{ config('app.name') }}</a>
    </header>
    <main>
        @yield('content')
    </main>
</body>
</html>
