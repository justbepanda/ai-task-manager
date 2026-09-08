<div class="d-flex flex-wrap align-items-center gap-2">
    <span>{{ $sender }}</span>
    @if (! empty($editUrl))
        <a href="{{ $editUrl }}" class="btn btn-link btn-sm p-0 align-baseline">{{ $editLabel }}</a>
    @endif
</div>
