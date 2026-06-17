@php
    $eventBadge = fn ($value) => match ($value) {
        'ENTRY_TRIGGERED' => 'badge-blue', 'TP1_HIT' => 'badge-green', 'TP2_HIT' => 'badge-green', 'SL_HIT' => 'badge-red', 'TRAILING_STARTED', 'TRAILING_UPDATED', 'TRAILING_STOP_HIT' => 'badge-purple', 'EXPIRED' => 'badge-gray', 'CLOSED' => 'badge-green', 'CANCELLED', 'ERROR' => 'badge-red', default => 'badge-gray',
    };
    $fmt = fn ($value, $decimals = 8) => $value === null || $value === '' ? '-' : rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $pct = fn ($value) => $value === null || $value === '' ? '-' : number_format((float) $value, 2) . '%';
@endphp
@if ($events->isEmpty())
    <p>No trade events yet.</p>
@else
    <div class="table-wrap">
        <table class="table scanner-table">
            <thead><tr><th>Event time</th><th>Event type</th><th>Event price</th><th>Trigger price</th><th>P&amp;L %</th><th>Previous status</th><th>New status</th><th>Message</th><th>Payload</th></tr></thead>
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td class="nowrap">{{ $event->event_time?->format('Y-m-d H:i:s') ?: '-' }}</td>
                        <td><span class="badge {{ $eventBadge($event->event_type) }}">{{ $event->event_type }}</span></td>
                        <td>{{ $fmt($event->event_price) }}</td>
                        <td>{{ $fmt($event->trigger_price) }}</td>
                        <td class="{{ (float) $event->pnl_percent > 0 ? 'text-green' : ((float) $event->pnl_percent < 0 ? 'text-red' : '') }}">{{ $pct($event->pnl_percent) }}</td>
                        <td>{{ $event->previous_status ?: '-' }}</td>
                        <td>{{ $event->new_status ?: '-' }}</td>
                        <td>{{ $event->message ?: '-' }}</td>
                        <td><details class="details-panel"><summary>Raw payload</summary><pre>{{ json_encode($event->raw_payload, JSON_PRETTY_PRINT) ?: '-' }}</pre></details></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
