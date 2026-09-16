<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#22d3ee">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icon.svg">
    {{-- Applies the remembered light/dark choice before the first paint, so the
         dark shell never flashes on the way to a light screen. --}}
    <script>
        (function () {
            try {
                var light = window.localStorage.getItem('cargoflow.theme') === 'light';

                document.documentElement.classList.toggle('theme-light', light);
                document.documentElement.dataset.theme = light ? 'light' : 'dark';
                document.querySelector('meta[name="theme-color"]').setAttribute('content', light ? '#ffffff' : '#020617');
            } catch (error) {
                // No stored preference available; the dark default stands.
            }
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="bg-slate-950 font-sans antialiased">
    @inertia
</body>
</html>
