{{-- What each access scope allows. Needs $scopes (KeyScopes::all()). --}}
<details class="scope-help">
    <summary>What each scope allows</summary>
    <dl class="scope-list">
        @foreach ($scopes as $scope)
            <dt>{{ $scope['label'] }}</dt>
            <dd>{{ $scope['summary'] }}</dd>
        @endforeach
    </dl>
    <p class="field-hint">Garage has no separate permission to overwrite or delete: a key that can write can do both. To keep files from being removed, hand out read-only keys.</p>
</details>
