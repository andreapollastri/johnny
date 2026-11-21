{{-- The state of a transfer as a badge: always a word, never colour alone. --}}
@php
    $transferBadge = match (true) {
        $transfer->status === \App\Models\Transfer::COMPLETED => 'badge-ok',
        $transfer->status === \App\Models\Transfer::FAILED => 'badge-err',
        $transfer->status === \App\Models\Transfer::RUNNING => '',
        default => 'badge-muted',
    };
@endphp
<span class="badge {{ $transferBadge }}">
    @if ($transfer->status === \App\Models\Transfer::RUNNING)
        <span class="spinner" aria-hidden="true"></span>
    @endif
    <span data-t="label">{{ $transfer->statusLabel() }}</span>
</span>
