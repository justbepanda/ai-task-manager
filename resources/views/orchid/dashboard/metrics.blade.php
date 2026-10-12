<div class="mb-3">
    <div class="row mb-2 g-3 g-mb-4">
        @foreach($metrics as $key => $metric)
            @php
                $value = is_array($metric) ? ($metric['value'] ?? 0) : $metric;
                $url = is_array($metric) ? ($metric['url'] ?? null) : null;
                $diff = is_array($metric) ? ($metric['diff'] ?? null) : null;
                $active = is_array($metric) && ! empty($metric['active']);
            @endphp
            <div class="col">
                @if(is_string($url) && $url !== '')
                    <a href="{{ $url }}" class="p-4 bg-white rounded shadow-sm h-100 d-flex flex-column text-decoration-none text-reset dashboard-metric-card{{ $active ? ' is-active' : '' }}">
                        <small class="text-muted d-block mb-1">{{ __($key) }}</small>
                        <p class="h3 text-body-emphasis fw-light mt-auto mb-0">
                            {{ $value }}
                            @if($diff !== null && (float) $diff !== 0.0)
                                <small class="small {{ (float) $diff < 0 ? 'text-danger' : 'text-success' }}">
                                    {{ round((float) $diff, 2) }} %
                                </small>
                            @endif
                        </p>
                    </a>
                @else
                    <div class="p-4 bg-white rounded shadow-sm h-100 d-flex flex-column">
                        <small class="text-muted d-block mb-1">{{ __($key) }}</small>
                        <p class="h3 text-body-emphasis fw-light mt-auto mb-0">
                            {{ $value }}
                        </p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>

<style>
    a.dashboard-metric-card:hover {
        box-shadow: 0 0.35rem 0.85rem rgba(0, 0, 0, 0.08) !important;
        outline: 1px solid rgba(13, 110, 253, 0.35);
    }
    a.dashboard-metric-card.is-active {
        outline: 2px solid rgba(13, 110, 253, 0.55);
        box-shadow: 0 0.35rem 0.85rem rgba(13, 110, 253, 0.12) !important;
    }
</style>
