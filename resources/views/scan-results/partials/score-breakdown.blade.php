<details class="details-panel">
    <summary>Details</summary>
    <div class="details-grid">
        <div>
            <strong>Selection reason</strong>
            <p>{{ $result->selection_reason ?: 'Not selected' }}</p>
        </div>
        <div>
            <strong>Prefilter reason</strong>
            <p>{{ $result->prefilter_reason ?: 'Not available' }}</p>
        </div>
    </div>

    <strong>Score breakdown</strong>
    <pre>{{ json_encode($result->score_breakdown ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>

    <details>
        <summary>Raw payload</summary>
        <pre>{{ json_encode($result->raw_payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </details>
</details>
