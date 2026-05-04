<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class FeishuSyncUsersCommand extends Command
{
    protected $signature = 'feishu:sync-users';

    protected $description = 'Sync departments and users from Feishu (basic MVP implementation)';

    public function handle(): int
    {
        $cfg = Setting::read('feishu', []);
        $appId = Arr::get($cfg, 'app_id');
        $appSecret = Arr::get($cfg, 'app_secret');

        if (! $appId || ! $appSecret) {
            $this->warn('Feishu app config is empty, skip sync.');

            return self::SUCCESS;
        }

        // MVP: keep this command lightweight and safe.
        // Full sync can be expanded with department/user pagination endpoints.
        Department::query()->updateOrCreate(
            ['feishu_department_id' => 'root'],
            ['name' => 'Default Department']
        );

        User::query()->whereNull('department_id')->update([
            'department_id' => Department::query()->where('feishu_department_id', 'root')->value('id'),
        ]);

        $this->info('Feishu sync completed (MVP baseline).');

        return self::SUCCESS;
    }
}

