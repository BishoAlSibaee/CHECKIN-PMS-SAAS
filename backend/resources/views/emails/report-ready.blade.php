<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report ready</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.5;">
    <h2 style="color: #1e3a5f;">Your hotel report is ready</h2>

    <p>Hello,</p>

    <p>
        The report <strong>{{ str_replace('-', ' ', $export->slug) }}</strong>
        for period <strong>{{ $periodLabel }}</strong> has been generated.
    </p>

    @if($export->row_count)
        <p>Total rows: <strong>{{ number_format($export->row_count) }}</strong></p>
    @endif

    <p>
        <a href="{{ $downloadUrl }}"
           style="display:inline-block;padding:10px 18px;background:#1e3a5f;color:#fff;text-decoration:none;border-radius:4px;">
            Download {{ $formatLabel }} report
        </a>
    </p>

    <p style="font-size: 12px; color: #666;">
        The {{ $formatLabel }} file is also attached to this email.
    </p>

    <p style="font-size: 12px; color: #666;">
        This link expires on {{ $export->expires_at?->format('Y-m-d H:i') ?? 'soon' }}.
        If you did not request this report, you can ignore this email.
    </p>
</body>
</html>
