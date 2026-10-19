<?php

namespace Pelican\AutoBackup\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;
use App\Models\Server;
use Pelican\AutoBackup\Models\BackupRun;
use Pelican\AutoBackup\Console\Commands\TickBackups;
use Pelican\AutoBackup\Contracts\PanelGateway;
use Pelican\AutoBackup\Services\PelicanGateway;

class AutoBackupProvider extends ServiceProvider
{
    public function register(): void { $this->app->bind(PanelGateway::class, PelicanGateway::class); }
    public function boot(): void
    {
        // rc.1 dashboard power actions do not all produce activity records.
        // Observe outgoing panel power requests as well as Wings activity records.
        Event::listen(RequestSending::class, function (RequestSending $event) {
            $request = $event->request;
            if ($request->method() !== 'POST' || $request->hasHeader('X-Auto-Backup-Run')) { return; }
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (!preg_match('~/api/servers/([a-f0-9-]+)/power$~i', $path ?? '', $match)) { return; }
            $server = Server::where('uuid', $match[1])->value('id');
            if ($server) {
                BackupRun::where('active_server_id', $server)->update(['manual_intervention_at' => now()]);
            }
        });
        $this->app->afterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(TickBackups::class)->everyMinute()->withoutOverlapping(5);
        });
    }
}
