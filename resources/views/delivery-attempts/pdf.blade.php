<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica', sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .muted { color: #666; }
        .header { border-bottom: 2px solid #111; padding-bottom: 10px; margin-bottom: 16px; }
        table.summary td { border: none; padding: 2px 7px 2px 0; }
        .signature-box { margin-top: 20px; }
        .signature-box img { max-width: 260px; max-height: 120px; border: 1px solid #ccc; padding: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Proof of Delivery</h1>
        <p class="muted">{{ $attempt->assignment->shipment->tracking_number }} &middot; Delivered {{ $attempt->attempted_at->format('Y-m-d H:i') }}</p>
    </div>

    <table class="summary">
        <tr><td><strong>Shipment</strong></td><td>{{ $attempt->assignment->shipment->tracking_number }}</td></tr>
        <tr><td><strong>Driver</strong></td><td>{{ $attempt->assignment->driver->user->name }}</td></tr>
        <tr><td><strong>Recipient</strong></td><td>{{ $attempt->recipient_name ?? 'Not recorded' }}</td></tr>
        <tr><td><strong>Delivered at</strong></td><td>{{ $attempt->attempted_at->format('Y-m-d H:i') }}</td></tr>
        @if ($attempt->notes)
            <tr><td><strong>Notes</strong></td><td>{{ $attempt->notes }}</td></tr>
        @endif
    </table>

    @if ($signatureDataUri)
        <div class="signature-box">
            <p class="muted">Recipient signature</p>
            <img src="{{ $signatureDataUri }}" alt="Recipient signature">
        </div>
    @endif
</body>
</html>
