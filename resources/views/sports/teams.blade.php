@if ($match['teams'])
    <div class="teams">
        @foreach ($match['teams'] as $team)
            @if ($loop->last)<span class="versus" aria-label="對">VS</span>@endif
            <div class="team">
                <span class="badge-wrap">
                    <span class="badge-fallback" aria-hidden="true">{{ $team['initials'] }}</span>
                    @if ($team['badge'])
                        <img class="team-badge" src="{{ $team['badge'] }}" data-fallback="{{ $team['badge_fallback'] }}" alt="{{ $team['label'] }}隊徽" width="56" height="56" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                    @endif
                </span>
                <div class="team-name"><strong>{{ $team['label'] }}</strong>@if ($team['label'] !== $team['name'])<span lang="en">{{ $team['name'] }}</span>@endif</div>
            </div>
        @endforeach
    </div>
@else
    <div class="event-title"><strong>{{ $match['translated_title'] }}</strong>@if ($match['translated_title'] !== $match['title'])<span lang="en">{{ $match['title'] }}</span>@endif</div>
@endif
