<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MemoryService;
use Illuminate\Console\Command;

class MemoryRepairCommand extends Command
{
    protected $signature = 'memory:repair {--user_id= : Repair only one user} {--no-promote : Skip recall-based promotion while repairing}';

    protected $description = 'Repair L3 long-term memory using the current admission policy';

    public function __construct(private readonly MemoryService $memoryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = (int) ($this->option('user_id') ?: 0);
        $shouldPromote = ! (bool) $this->option('no-promote');

        $users = $userId > 0
            ? User::query()->where('id', $userId)->get()
            : User::query()->where('is_active', true)->get();

        if ($users->isEmpty()) {
            $this->warn('No user found to repair memory.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $result = $this->memoryService->repairUserMemory((int) $user->id, $shouldPromote);
            $reasons = collect((array) ($result['reason_counts'] ?? []))
                ->map(fn ($count, $reason) => $reason.':'.$count)
                ->implode(', ');

            $this->info(sprintf(
                'User %d repaired, active=%d, deactivated=%d, reactivated=%d, updated=%d, promoted=%d, expired_l2=%d%s',
                $user->id,
                (int) ($result['fact_count'] ?? 0),
                (int) ($result['deactivated_count'] ?? 0),
                (int) ($result['reactivated_count'] ?? 0),
                (int) ($result['updated_count'] ?? 0),
                (int) ($result['promoted_count'] ?? 0),
                (int) (($result['cleanup']['expired_count'] ?? 0)),
                $reasons !== '' ? ', reasons='.$reasons : ''
            ));
        }

        return self::SUCCESS;
    }
}
