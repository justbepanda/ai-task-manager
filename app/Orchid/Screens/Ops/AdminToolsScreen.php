<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Ops;

use App\Domain\Ops\AdminArtisanCatalog;
use App\Domain\Ops\RunAdminArtisanAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use RuntimeException;

class AdminToolsScreen extends Screen
{
    public function query(): iterable
    {
        return [
            'tools' => AdminArtisanCatalog::actions(),
            'lastOutput' => session('admin_tools_last_output'),
            'lastSummary' => session('admin_tools_last_summary'),
        ];
    }

    public function name(): ?string
    {
        return 'Сервисные команды';
    }

    public function description(): ?string
    {
        return 'Те же artisan-команды, что раньше запускались из терминала: синхронизация, переобработка, фильтры, follow-up, метрики, очистка БД.';
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Журнал')->icon('bs.journal-text')->route('platform.activity-logs'),
            Link::make('Обзор')->icon('bs.speedometer2')->route('platform.main'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('orchid.ops.admin-tools'),
        ];
    }

    public function run(Request $request, RunAdminArtisanAction $runner)
    {
        $validated = $request->validate([
            'tool_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $result = $runner->handle($validated['tool_id']);
        } catch (RuntimeException $e) {
            Toast::error($e->getMessage());

            return redirect()->route('platform.admin-tools');
        }

        if (! empty($result['requires_relogin'])) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Toast::warning('БД очищена и засеяна. Войдите снова: developer@kelnik.ru / developer');

            return redirect()->to('/'.trim((string) config('platform.prefix', 'admin'), '/').'/login');
        }

        session([
            'admin_tools_last_output' => $result['output'],
            'admin_tools_last_summary' => $result['summary'],
        ]);

        if ($result['ok']) {
            Toast::info($result['summary']);
        } else {
            Toast::error($result['summary']);
        }

        return redirect()->route('platform.admin-tools');
    }
}
