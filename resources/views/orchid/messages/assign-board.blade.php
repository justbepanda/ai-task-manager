@php
    /** @var \App\Domain\Projects\DTO\AssignmentBoard|null $board */
    $board = $board ?? null;
    $groups = $board?->groups ?? [];
    $unmatched = $board?->unmatched ?? [];
    $attachAction = route('platform.messages.assign', 'attachToProject');
    $createAction = route('platform.messages.assign', 'createAndAttach');
@endphp

{{--
  Orchid wraps the screen in #post-form. Nested <form> is invalid HTML and breaks Turbo
  (spinner never stops). Submit via formaction on buttons; enable only one group fields.
--}}

<style>
    .assign-board h3 { margin-top: 1.5rem; margin-bottom: 0.5rem; }
    .assign-board .meta { color: #6c757d; margin-bottom: 0.75rem; }
    .assign-board .suggest-table { width: 100%; margin-bottom: 0.75rem; }
    .assign-board .suggest-table th,
    .assign-board .suggest-table td { padding: 0.35rem 0.5rem; vertical-align: top; border-bottom: 1px solid #eee; }
    .assign-board .badge-ambiguous {
        display: inline-block;
        font-size: 0.75rem;
        padding: 0.1rem 0.4rem;
        border-radius: 0.25rem;
        background: #fff3cd;
        color: #856404;
        margin-left: 0.35rem;
    }
    .assign-board .badge-new {
        display: inline-block;
        font-size: 0.75rem;
        padding: 0.1rem 0.4rem;
        border-radius: 0.25rem;
        background: #d1e7dd;
        color: #0f5132;
        margin-left: 0.35rem;
        font-weight: 600;
    }
    .assign-board .reasons { color: #6c757d; font-size: 0.85rem; }
    .assign-board .group-actions { margin-bottom: 1.5rem; }
    .assign-board .empty { color: #6c757d; padding: 1rem 0; }
</style>

<div class="assign-board bg-white rounded shadow-sm p-4">
    @if ($groups === [] && $unmatched === [])
        <p class="empty mb-0">Нет нераспределённых писем для предложений (или все в «Игнорируемые»).</p>
    @endif

    @foreach ($groups as $index => $group)
        @php
            $groupId = $group->isNew ? 'group-new-'.$index : 'group-'.$group->project->id;
            $formAction = $group->isNew ? $createAction : $attachAction;
        @endphp
        <section class="assign-group" data-assign-group="{{ $groupId }}">
            <h3>
                {{ $group->title }}
                @if ($group->isNew)
                    <span class="badge-new">создать</span>
                @endif
            </h3>
            <p class="meta">
                @if ($group->isNew)
                    Проекта ещё нет — письма сгруппированы по теме Redmine.
                    @if ($group->suggestedAliases !== [])
                        Алиасы: {{ implode(', ', array_slice($group->suggestedAliases, 0, 6)) }}
                    @endif
                @else
                    Уже в проекте: {{ $group->existingCount }}
                    {{ $group->existingCount === 1 ? 'письмо' : 'писем' }}
                    |
                    <a href="{{ route('platform.projects.edit', $group->project) }}">открыть проект</a>
                @endif
            </p>

            @if ($group->isNew)
                <input type="hidden" name="name" value="{{ $group->suggestedName }}" data-assign-field="{{ $groupId }}" disabled>
                @foreach ($group->suggestedAliases as $alias)
                    <input type="hidden" name="aliases[]" value="{{ $alias }}" data-assign-field="{{ $groupId }}" disabled>
                @endforeach
            @else
                <input type="hidden" name="project_id" value="{{ $group->project->id }}" data-assign-field="{{ $groupId }}" disabled>
            @endif

            <p class="mb-2 fw-semibold">
                {{ $group->isNew ? 'Письма для нового проекта:' : 'Предложения к добавлению:' }}
            </p>
            <table class="suggest-table">
                <thead>
                    <tr>
                        <th style="width:2rem">
                            <input type="checkbox"
                                   class="form-check-input"
                                   data-assign-select-all="{{ $groupId }}"
                                   title="Выбрать все"
                                   aria-label="Выбрать все"
                                   checked>
                        </th>
                        <th>#</th>
                        <th>Дата</th>
                        <th>Отправитель</th>
                        <th>Тема</th>
                        <th>Причина / score</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group->suggestions as $suggestion)
                        @php $m = $suggestion->message; @endphp
                        <tr>
                            <td>
                                <input type="checkbox"
                                       class="form-check-input"
                                       name="message_ids[]"
                                       value="{{ $m->id }}"
                                       data-assign-field="{{ $groupId }}"
                                       checked
                                       disabled>
                            </td>
                            <td>
                                <a href="{{ route('platform.messages.view', $m) }}">#{{ $m->id }}</a>
                                @if ($suggestion->ambiguous)
                                    <span class="badge-ambiguous">неоднозначно</span>
                                @endif
                            </td>
                            <td>{{ optional($m->received_at)->format('d.m.Y H:i') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit((string) $m->sender, 40) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit((string) $m->subject, 60) }}</td>
                            <td class="reasons">
                                {{ number_format($suggestion->score, 2) }} — {{ implode(', ', $suggestion->reasons) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="group-actions">
                <button type="submit"
                        class="btn {{ $group->isNew ? 'btn-success' : 'btn-primary' }}"
                        formaction="{{ $formAction }}"
                        formmethod="post"
                        data-assign-submit="{{ $groupId }}">
                    @if ($group->isNew)
                        Создать «{{ $group->suggestedName }}» и привязать выбранные
                    @else
                        Привязать выбранные к этому проекту
                    @endif
                </button>
            </div>
        </section>
    @endforeach

    <section class="assign-group" data-assign-group="group-unmatched">
        <h3>Без подходящего проекта</h3>
        @if ($unmatched === [])
            <p class="empty">Нет писем без кандидата.</p>
        @else
            <table class="suggest-table">
                <thead>
                    <tr>
                        <th style="width:2rem">
                            <input type="checkbox"
                                   class="form-check-input"
                                   data-assign-select-all="group-unmatched"
                                   title="Выбрать все"
                                   aria-label="Выбрать все">
                        </th>
                        <th>#</th>
                        <th>Дата</th>
                        <th>Отправитель</th>
                        <th>Тема</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($unmatched as $m)
                        <tr>
                            <td>
                                <input type="checkbox"
                                       class="form-check-input"
                                       name="message_ids[]"
                                       value="{{ $m->id }}"
                                       data-assign-field="group-unmatched"
                                       disabled>
                            </td>
                            <td><a href="{{ route('platform.messages.view', $m) }}">#{{ $m->id }}</a></td>
                            <td>{{ optional($m->received_at)->format('d.m.Y H:i') }}</td>
                            <td>{{ \Illuminate\Support\Str::limit((string) $m->sender, 40) }}</td>
                            <td>{{ \Illuminate\Support\Str::limit((string) $m->subject, 60) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mb-3" style="max-width: 28rem;">
                <label class="form-label" for="new-project-name">Название нового проекта</label>
                <input type="text"
                       id="new-project-name"
                       name="name"
                       class="form-control"
                       maxlength="255"
                       placeholder="Например: Интернет-магазин А"
                       data-assign-field="group-unmatched"
                       disabled>
            </div>

            <div class="group-actions">
                <button type="submit"
                        class="btn btn-success"
                        formaction="{{ $createAction }}"
                        formmethod="post"
                        data-assign-submit="group-unmatched">
                    Создать проект и привязать выбранные
                </button>
            </div>
        @endif
    </section>
</div>

<script>
(function () {
    function fields(groupId) {
        return document.querySelectorAll('[data-assign-field="' + groupId + '"]');
    }

    function prepareGroup(groupId) {
        document.querySelectorAll('[data-assign-field]').forEach(function (el) {
            el.disabled = true;
        });
        fields(groupId).forEach(function (el) {
            el.disabled = false;
        });
    }

    document.querySelectorAll('[data-assign-select-all]').forEach(function (master) {
        master.addEventListener('change', function () {
            var group = master.getAttribute('data-assign-select-all');
            // Temporarily enable so user can toggle visible state, then keep enabled for UX
            fields(group).forEach(function (el) {
                if (el.type === 'checkbox' && el.name === 'message_ids[]') {
                    el.disabled = false;
                    el.checked = master.checked;
                }
            });
        });
    });

    // Allow toggling checkboxes before submit
    document.querySelectorAll('[data-assign-field]').forEach(function (el) {
        if (el.type === 'checkbox' && el.name === 'message_ids[]') {
            el.disabled = false;
        }
    });

    document.querySelectorAll('[data-assign-submit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            prepareGroup(btn.getAttribute('data-assign-submit'));
            btn.focus();
        });
    });
})();
</script>
