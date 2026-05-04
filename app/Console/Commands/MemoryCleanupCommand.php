<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MemoryService;
use Illuminate\Console\Command;

class MemoryCleanupCommand extends Command
{
    protected $signature = 'memory:cleanup {--user_id= : Cleanup only one user}';

    protected $description = 'Expire L2 memory entries whose TTL has elapsed';

    public function __construct(private readonly MemoryService $memoryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = (int) ($this->option('user_id') ?: 0);

        $users = $userId > 0
            ? User::query()->where('id', $userId)->get()
            : User::query()->where('is_active', true)->get();

        if ($users->isEmpty()) {
            $this->warn('No user found to cleanup memory.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $result = $this->memoryService->cleanupUserMemory((int) $user->id);

            $this->info(sprintf(
                'User %d cleaned, checked=%d, expired=%d',
                $user->id,
                (int) ($result['checked_count'] ?? 0),
                (int) ($result['expired_count'] ?? 0)
            ));
        }

        return self::SUCCESS;
    }
}
