<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Night audit summary</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.5;">
    <h2 style="color: #1e3a5f;">Night audit — {{ $hotel->name_en }}</h2>

    <p>Date: <strong>{{ $run->audit_date->toDateString() }}</strong></p>

    <p>
        @if($run->exceptions_count > 0)
            <span style="color:#b91c1c; font-weight:bold;">{{ $run->exceptions_count }} exception(s) need a look.</span>
        @else
            <span style="color:#15803d; font-weight:bold;">No exceptions — everything reconciled cleanly.</span>
        @endif
    </p>

    <table style="border-collapse: collapse; margin: 16px 0;">
        <tr>
            <td style="padding:4px 12px 4px 0; color:#666;">Cash collected (net of refunds)</td>
            <td style="padding:4px 0; font-weight:bold;">{{ number_format($run->metrics['cash_collected'] ?? 0, 2) }}</td>
        </tr>
        <tr>
            <td style="padding:4px 12px 4px 0; color:#666;">Guests overdue for checkout</td>
            <td style="padding:4px 0; font-weight:bold;">{{ $run->metrics['overdue_checkouts_count'] ?? 0 }}</td>
        </tr>
        <tr>
            <td style="padding:4px 12px 4px 0; color:#666;">Books balanced today</td>
            <td style="padding:4px 0; font-weight:bold;">{{ ($run->exceptions['trial_balance_unbalanced'] ?? false) ? 'NO — needs review' : 'Yes' }}</td>
        </tr>
        <tr>
            <td style="padding:4px 12px 4px 0; color:#666;">ZATCA invoices needing resend</td>
            <td style="padding:4px 0; font-weight:bold;">{{ $run->metrics['zatca_pending_count'] ?? 0 }}</td>
        </tr>
    </table>

    @if(!empty($run->exceptions['overdue_checkouts']))
        <h3 style="color:#1e3a5f; font-size:15px;">Overdue checkouts</h3>
        <ul>
            @foreach($run->exceptions['overdue_checkouts'] as $item)
                <li>Reservation #{{ $item['reservation_id'] }} — {{ $item['guest'] }} — room {{ $item['room'] ?? '—' }} — {{ $item['days_overdue'] }} day(s) overdue</li>
            @endforeach
        </ul>
    @endif

    @if(!empty($run->exceptions['zatca_pending']))
        <h3 style="color:#1e3a5f; font-size:15px;">ZATCA invoices needing resend</h3>
        <ul>
            @foreach($run->exceptions['zatca_pending'] as $item)
                <li>Invoice {{ $item['invoice_number'] }} (reservation #{{ $item['reservation_id'] }}) — status: {{ $item['status'] }}</li>
            @endforeach
        </ul>
    @endif

    <p style="font-size: 12px; color: #666; margin-top: 20px;">
        This is an automated nightly reconciliation — it does not block any check-in, check-out, or edit. Review the flagged items at your convenience.
    </p>
</body>
</html>
