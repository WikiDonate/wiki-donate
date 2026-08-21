<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>WikiDonate</title>
        @php
            $hotFile = public_path('hot');
            $manifestPath = public_path('build/.vite/manifest.json');
        @endphp

        @if ($hotFile && file_exists($hotFile))
            @php
                $devServer = trim(file_get_contents($hotFile));
            @endphp
            <script type="module" src="{{ $devServer }}/resources/js/app.js"></script>
        @elseif (file_exists($manifestPath))
            @php
                $manifest = json_decode(file_get_contents($manifestPath), true);
                $entry = $manifest['resources/js/app.js'] ?? null;
            @endphp
            @if ($entry)
                @foreach (($entry['css'] ?? []) as $css)
                    <link rel="stylesheet" href="/build/{{ $css }}" />
                @endforeach
                <script type="module" src="/build/{{ $entry['file'] }}"></script>
            @endif
        @else
            <script type="module" src="http://localhost:5173/resources/js/app.js"></script>
        @endif
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
