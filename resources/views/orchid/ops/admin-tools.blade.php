@php
    /** @var list<array<string, mixed>> $tools */
    $tools = $tools ?? [];
    $lastOutput = $lastOutput ?? null;
    $lastSummary = $lastSummary ?? null;
@endphp

<style>
    .admin-tools .tool-card {
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        padding: 1rem 1.25rem;
        margin-bottom: 0.85rem;
        background: #fff;
    }
    .admin-tools .tool-card h3 {
        font-size: 1.05rem;
        margin: 0 0 0.35rem;
    }
    .admin-tools .tool-cmd {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.8rem;
        color: #6b7280;
        margin-bottom: 0.5rem;
    }
    .admin-tools .tool-meta {
        color: #4b5563;
        font-size: 0.9rem;
        margin-bottom: 0.35rem;
    }
    .admin-tools .tool-when {
        color: #6b7280;
        font-size: 0.85rem;
        margin-bottom: 0.75rem;
    }
    .admin-tools .output-box {
        white-space: pre-wrap;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.8rem;
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 0.5rem;
        padding: 1rem;
        max-height: 22rem;
        overflow: auto;
    }
</style>

<div class="admin-tools bg-white rounded shadow-sm p-4">
    <p class="text-muted mb-3">
        Команды выполняются от имени текущего пользователя и пишутся в
        <a href="{{ route('platform.activity-logs') }}">Журнал</a>.
        Для части задач нужен работающий queue worker (если кнопка без «сейчас / без очереди»).
    </p>

    @if (is_string($lastSummary) && $lastSummary !== '')
        <div class="mb-4">
            <h3 class="h5">Последний результат</h3>
            <p class="mb-2">{{ $lastSummary }}</p>
            @if (is_string($lastOutput) && $lastOutput !== '')
                <div class="output-box">{{ $lastOutput }}</div>
            @endif
        </div>
    @endif

    @foreach ($tools as $tool)
        <section class="tool-card">
            <h3>{{ $tool['title'] }}</h3>
            <div class="tool-cmd">
                php artisan {{ $tool['command'] }}
                @foreach ($tool['options'] as $opt => $val)
                    @if ($val === true)
                        {{ $opt }}
                    @elseif ($val !== false && $val !== null && $val !== '')
                        {{ $opt }}={{ $val }}
                    @endif
                @endforeach
            </div>
            <p class="tool-meta mb-1">{{ $tool['description'] }}</p>
            <p class="tool-when"><strong>Когда:</strong> {{ $tool['when'] }}</p>

            <button type="submit"
                    class="btn {{ !empty($tool['danger']) ? 'btn-danger' : 'btn-primary' }}"
                    formaction="{{ route('platform.admin-tools', 'run') }}"
                    formmethod="post"
                    name="tool_id"
                    value="{{ $tool['id'] }}"
                    @if (!empty($tool['confirm']))
                        onclick="return confirm(@js($tool['confirm']))"
                    @endif
            >
                Запустить
            </button>
        </section>
    @endforeach
</div>
