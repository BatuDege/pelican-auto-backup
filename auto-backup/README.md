# Auto Backup for Pelican

**Automated, server-specific backups for Pelican Panel.** Schedule backups, warn players, run preparation commands, optionally stop and restart a server, and automatically rotate old backups. Backups are created through Pelican/Wings and appear in Pelican's normal **Backups** tab.

> **Compatibility:** v1.0.0 targets **Pelican Panel 1.0.0-rc.1** (plugin API 1, PHP 8.4+, Laravel 13, Filament 5). Other versions, including beta38, are not supported by this release. Use a Wings version compatible with your Panel.

## Installation

1. Download **`auto-backup-1.0.0.zip`** from Releases (not GitHub's source-code ZIP).
2. In Pelican, go to **Admin → Plugins → Import**, upload the ZIP and select **Install**. Verify that the plugin is enabled.
3. From your Pelican installation directory, restart the queue worker:
   ```bash
   php artisan queue:restart
   ```
4. Open the server's **Auto Backup** page, adjust settings, **Save settings**, then enable its schedule. Automatic backups are **disabled by default**.

No separate installer, API key or changes to Pelican core files are required.

## How it works

At the scheduled time, the plugin prepares a backup. Optional player warnings are sent **before** the scheduled time; preparation commands then run, followed by a configurable delay. Unless **Live backup** is enabled, the plugin waits for the server to shut down before creating a backup. The server is restarted according to the configured success/failure rules **only if the plugin itself confirmed the shutdown**. A server that was already offline remains offline.

After a **successful** new backup, old successful backups **created by this plugin** are removed according to the retention setting, oldest first. Locked backups and manually created backups are never automatically deleted. A backup failure does not trigger retention cleanup. Use **Run now** for a manual plugin run; it uses your **saved** settings.

## Settings

| Setting | Description / default |
| --- | --- |
| Schedule | Disabled by default; daily, weekly, every X hours or advanced 5-field cron |
| Timezone | Default **Europe/Berlin**; choose your server's timezone |
| Live backup | **Off** — shut down before backup by default |
| Warning commands | Optional timed console messages, e.g. Minecraft `say` |
| Preparation commands | Optional commands before backup, e.g. `save-all flush` |
| Delay after commands | **5 seconds** |
| Shutdown/status timeout | **120 seconds** |
| Backup timeout | **7200 seconds (2 hours)** |
| Start timeout | **180 seconds** |
| Restart after success | **On**, but only after a confirmed plugin shutdown |
| Restart after failure | **Off** |
| Auto backups to keep | **7** |
| Backup name | `Auto Backup {date}` |

Console commands generally should **not** include a leading `/` unless the game requires it. Available placeholders: `{time}`, `{server}`, `{date}`, `{backup_name}`. For example, a Minecraft warning 60 seconds beforehand can use `say Backup in {time} seconds.`

The plugin includes run history and native **Lock / Unlock / Delete** actions. Clearing failed history entries removes history visibility, **not** backup files. Interrupted or uncertain runs may need manual review before they can be released.

## Required Pelican configuration

### 1. Scheduler and queue

Pelican's **Laravel scheduler must run every minute**, and a functioning **asynchronous queue worker** must be running (`sync` is not supported). The worker needs PHP `pcntl`; queue `retry_after` must exceed **60 seconds** (90 seconds is suitable). Use a persistent cache with atomic locks (database/Redis; file cache on a single Panel host), **not** `array`. There is no extra cron job per game server.

### 2. Allow enough backup slots

Pelican must have **one free slot to create the next backup before deleting an old one**. Configure each server's backup limit in the **Panel admin area**:

```text
Minimum backup slots = auto backups to keep + other backups + 1 free slot
```

Example: keeping **7** auto backups plus **2** manual backups requires **10 slots**. Locked backups also occupy slots; more slots may be necessary. If storage slots are full, the plugin aborts **before** shutting the server down.

### 3. Check Pelican's backup rate limit

On **Pelican 1.0.0-rc.1**, the default is **2 backup creations per 600 seconds per server**. Manual backups, Pelican schedules and this plugin share the limit. To change it, edit the **Panel's `.env`** (example):

```dotenv
BACKUP_THROTTLE_LIMIT=10
BACKUP_THROTTLE_PERIOD=600
```

`BACKUP_THROTTLE_PERIOD` is in seconds. After editing, from the Panel directory run:

```bash
php artisan config:clear
php artisan queue:restart
```

If your installation uses cached configuration, use `php artisan config:cache` **instead of** `config:clear`, then restart the queue. The plugin never overrides these limits itself.

### 4. Server permissions

Users need `backup.read` and `schedule.read` to view the plugin. Creating/running configurations additionally requires `backup.create`, `backup.delete` and `schedule.update`, plus relevant `control.stop`, `control.start` or `control.console` permissions depending on enabled actions.

## Important notes

- **Offline backups are the default**; live backup consistency depends on the game/application.
- The plugin **never force-kills** the server and does not automatically restart after ambiguous failures or manual power intervention.
- Ensure that backups can actually be **restored** before relying on a schedule.
- Complete or resolve active runs before updating or uninstalling. Uninstalling preserves settings, history and existing backup files.

**Community plugin.** Developed with AI assistance from OpenAI Codex; not an official Pelican or OpenAI product.
