{{-- "How often" of a scheduled sync: a preset, or any number of minutes, hours or days.
     Optional $minutes: the interval to show (defaults to every 15 minutes). --}}
@php
    $shownMinutes = (int) ($minutes ?? 15);
    $shownPreset = old('interval', \App\Support\Intervals::preset($shownMinutes));
    [$shownEvery, $shownUnit] = \App\Support\Intervals::split($shownMinutes);
@endphp
<div class="field">
    <label for="interval">How often</label>
    <select id="interval" name="interval">
        @foreach (\App\Support\Intervals::PRESETS as $value => $label)
            <option value="{{ $value }}" @selected((string) $shownPreset === (string) $value)>{{ $label }}</option>
        @endforeach
        <option value="custom" @selected($shownPreset === 'custom')>Another interval…</option>
    </select>
</div>
<div class="field" data-show-if="interval=custom" @if ($shownPreset !== 'custom') hidden @endif>
    <label for="every">Every</label>
    <div class="input-group">
        <input type="number" id="every" name="every" value="{{ old('every', $shownEvery) }}" min="1" max="525600" step="1" inputmode="numeric" required>
        <select name="unit" aria-label="Unit">
            @foreach (array_keys(\App\Support\Intervals::UNITS) as $unit)
                <option value="{{ $unit }}" @selected(old('unit', $shownUnit) === $unit)>{{ $unit }}</option>
            @endforeach
        </select>
    </div>
</div>
