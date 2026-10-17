<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; margin: 0; }
        h1 { font-size: 16px; color: #1e3a5f; margin: 0 0 6px; }
        .meta { font-size: 9px; color: #555; margin-bottom: 10px; }
        .summary { margin-bottom: 12px; }
        .summary span { display: inline-block; margin-right: 14px; margin-bottom: 4px; }
        .page-block { width: 100%; page-break-after: always; }
        .page-block:last-child { page-break-after: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        th { background: #1e3a5f; color: #fff; font-size: 9px; }
        tr:nth-child(even) td { background: #f8fafc; }
    </style>
</head>
<body>
    @if(empty($rowPages))
        <h1>{{ ucwords($title) }}</h1>
        <div class="meta">Period: {{ $periodLabel }}</div>
        <div class="meta">No data rows for this period.</div>
    @else
    @foreach($rowPages as $pageIndex => $pageRows)
        <div class="page-block">
            @if($pageIndex === 0)
                <h1>{{ ucwords($title) }}</h1>
                <div class="meta">Period: {{ $periodLabel }}</div>

                @if(!empty($summary))
                    <div class="summary">
                        @foreach($summary as $item)
                            <span><strong>{{ $item['label'] ?? '' }}:</strong> {{ $item['value'] ?? '' }}</span>
                        @endforeach
                    </div>
                @endif

                @foreach($metaLines as $line)
                    <div class="meta">{{ $line }}</div>
                @endforeach
            @endif

            <table>
                <thead>
                    <tr>
                        @foreach($columns as $column)
                            <th>{{ $column['label'] ?? $column['key'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($pageRows as $row)
                        <tr>
                            @foreach($columns as $column)
                                @php $value = $row[$column['key']] ?? ''; @endphp
                                <td>{{ is_scalar($value) || $value === null ? ($value ?? '') : json_encode($value) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
    @endif
</body>
</html>
