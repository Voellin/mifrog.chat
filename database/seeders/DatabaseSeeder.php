<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Department;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        Department::query()->updateOrCreate(
            ['feishu_department_id' => 'root'],
            ['name' => 'Default Department']
        );

        AdminUser::query()->updateOrCreate(
            ['username' => env('MIFROG_ADMIN_USER', 'admin')],
            [
                'display_name' => 'Mifrog Admin',
                'password' => Hash::make(env('MIFROG_ADMIN_PASSWORD', 'ChangeMe123!')),
                'is_active' => true,
            ]
        );

        Setting::write('default_monthly_quota_tokens', (int) env('MIFROG_DEFAULT_MONTHLY_QUOTA', 0));
        Setting::write('retention_days', 180);
        if (! Setting::read('admin_api_token')) {
            Setting::write('admin_api_token', bin2hex(random_bytes(24)));
        }
    }
}
