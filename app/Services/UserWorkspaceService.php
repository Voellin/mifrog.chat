<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class UserWorkspaceService
{
    public function ensure(int $userId): array
    {
        $paths = $this->paths($userId);
        foreach ($paths as $path) {
            if (! File::isDirectory($path)) {
                File::makeDirectory($path, 0755, true);
            }
        }

        return $paths;
    }

    public function paths(int $userId): array
    {
        $root = $this->root($userId);

        return [
            'root' => $root,
            'sessions' => $root.'/sessions',
            'memory_root' => $root.'/memory',
            'memory_l2' => $root.'/memory/l2',
            'memory_l3' => $root.'/memory/l3',
            'memory_l3_versions' => $root.'/memory/l3/versions',
            'uploads_root' => $root.'/uploads',
            'uploads_raw' => $root.'/uploads/raw',
            'knowledge_root' => $root.'/knowledge',
            'knowledge_chunks' => $root.'/knowledge/chunks',
            'knowledge_meta' => $root.'/knowledge/meta',
        ];
    }

    public function root(int $userId): string
    {
        return storage_path('app/user_data/'.$userId);
    }
}

