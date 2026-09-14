<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #666; }
        .header { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; }
        th { background: #f2f2f2; text-transform: uppercase; font-size: 9px; letter-spacing: 0.05em; }
        .summary td { border: none; padding: 2px 7px 2px 0; }
        .unit-title { font-weight: bold; margin: 14px 0 4px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Manifest {{ $manifest->manifest_number }}</h1>
        <p class="muted">Version {{ $manifest->version }} &middot; Generated {{ $manifest->created_at->format('Y-m-d H:i') }}</p>
    </div>

    <table class="summary">
        <tr><td><strong>Master</strong></td><td>{{ $manifest->snapshot['master_number'] }}</td></tr>
        <tr><td><strong>Mode</strong></td><td>{{ strtoupper($manifest->snapshot['mode']) }}</td></tr>
        <tr><td><strong>Conveyance</strong></td><td>{{ $manifest->snapshot['conveyance'] }}</td></tr>
        <tr><td><strong>Route</strong></td><td>{{ $manifest->snapshot['origin'] }} &rarr; {{ $manifest->snapshot['destination'] }}</td></tr>
        <tr><td><strong>Total packages</strong></td><td>{{ $manifest->snapshot['package_count'] }}</td></tr>
        <tr><td><strong>Total weight</strong></td><td>{{ number_format($manifest->snapshot['weight_kg'], 3) }} kg</td></tr>
    </table>

    @foreach ($manifest->snapshot['load_units'] as $unit)
        <p class="unit-title">{{ ucfirst($unit['type']) }} {{ $unit['unit_number'] }}{{ $unit['seal_number'] ? ' &middot; Seal ' . $unit['seal_number'] : '' }} &mdash; {{ $unit['package_count'] }} package(s), {{ number_format($unit['weight_kg'], 3) }} kg</p>
        <table>
            <thead>
                <tr>
                    <th>Barcode</th>
                    <th>Shipment</th>
                    <th>Description</th>
                    <th>Weight (kg)</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($unit['packages'] as $package)
                    <tr>
                        <td>{{ $package['barcode'] }}</td>
                        <td>{{ $package['shipment_tracking_number'] }}</td>
                        <td>{{ $package['description'] ?? '—' }}</td>
                        <td>{{ number_format($package['weight_kg'], 3) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No packages loaded.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
</body>
</html>
