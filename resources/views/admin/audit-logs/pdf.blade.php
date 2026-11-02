<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10mm 8mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111; margin: 0; padding: 0; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { margin: 0 0 10px; color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
        th, td {
            border: 1px solid #ccc;
            padding: 2px 3px;
            vertical-align: top;
            text-align: left;
        }
        th { background: #f3f5f8; font-size: 8px; }
        .note { margin-top: 8px; color: #555; }
    </style>
</head>
<body>
    <h1>Audit History</h1>
    <p class="meta">
        Generated {{ $generatedAt }}<br>
        User: {{ $filters['user'] }} |
        Event: {{ $filters['event'] }} |
        From: {{ $filters['from'] }} |
        To: {{ $filters['to'] }}<br>
        Matching records: {{ $total }}
        @if($truncated)
            (PDF includes the latest {{ $limit }})
        @endif
    </p>
    @forelse($rowChunks as $chunk)
        <table>
            <thead>
                <tr>
                    <th style="width: 12%;">Date &amp; Time</th>
                    <th style="width: 12%;">User</th>
                    <th style="width: 8%;">Action</th>
                    <th style="width: 18%;">Activity Performed</th>
                    <th style="width: 8%;">Status</th>
                    <th style="width: 12%;">Field Changed</th>
                    <th style="width: 15%;">Previous Value</th>
                    <th style="width: 15%;">New Value</th>
                </tr>
            </thead>
            <tbody>
                @forelse($chunk as $row)
                    <tr>
                        <td>{{ $row['when'] }}</td>
                        <td>{{ $row['user'] }}</td>
                        <td>{{ $row['action'] }}</td>
                        <td>{{ $row['activity'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['fields'] }}</td>
                        <td>{{ $row['old'] }}</td>
                        <td>{{ $row['new'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">No audit history found for the current filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    @empty
        <table>
            <tbody>
                <tr>
                    <td>No audit history found for the current filters.</td>
                </tr>
            </tbody>
        </table>
    @endforelse
</body>
</html>
