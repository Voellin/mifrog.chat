<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Lightweight sandbox for executing Skill scripts with per-user isolation.
 *
 * Architecture:
 *   - Each MiFrog user gets a dedicated Linux user (mf_sandbox_{user_id})
 *   - Scripts run under that user via `sudo su mf_sandbox_X -s /bin/bash -c ...`
 *   - Execution is time-limited via `timeout` and priority-lowered via `nice`
 *   - Each run gets a fresh tmpdir under storage/app/sandbox/{user_id}/{run_id}/
 *   - Tmpdir is cleaned up after execution
 *
 * Zero external dependencies — uses only Linux built-ins (useradd, su, timeout, nice).
 */
class SkillSandboxService
{
    /** Maximum execution time in seconds. */
    private const TIMEOUT_SECONDS = 30;

    /** Maximum output size in bytes. */
    private const MAX_OUTPUT_BYTES = 65536;

    /** Sandbox base directory (relative to storage_path()). */
    private const SANDBOX_DIR = 'app/sandbox';

    /**
     * Execute a script inside the sandbox.
     *
     * @param  User    $user       The MiFrog user (determines sandbox Linux user)
     * @param  string  $script     Script content (bash, python, etc.)
     * @param  string  $interpreter  The interpreter to use (bash or python3 only; php is not supported)
     * @param  array   $env        Extra environment variables
     * @param  string  $runId      Unique run identifier for tmpdir isolation
     * @param  array   $inputFiles Files to copy into sandbox: ['filename' => 'content']
     * @return array{exit_code: int, stdout: string, stderr: string, timed_out: bool}
     */
    public function execute(
        User $user,
        string $script,
        string $interpreter = 'bash',
        array $env = [],
        string $runId = '',
        array $inputFiles = []
    ): array {
        if ($runId === '') {
            $runId = 'run_' . bin2hex(random_bytes(8));
        }

        $sandboxUser = $this->ensureSandboxUser($user);
        $workDir = $this->prepareWorkDir($user, $runId, $sandboxUser);

        try {
            // Write script to workdir
            $ext = $this->interpreterExtension($interpreter);
            $scriptPath = $workDir . '/entry' . $ext;
            file_put_contents($scriptPath, $script);
            chmod($scriptPath, 0755);

            // Write input files
            foreach ($inputFiles as $filename => $content) {
                $safeName = basename((string) $filename);
                if ($safeName !== '' && $safeName !== '.' && $safeName !== '..') {
                    file_put_contents($workDir . '/' . $safeName, $content);
                }
            }

            // Fix ownership so sandbox user can read/write
            $this->shellExec(sprintf(
                'sudo chown -R %s:%s %s',
                escapeshellarg($sandboxUser),
                escapeshellarg($sandboxUser),
                escapeshellarg($workDir)
            ));

            // Build the execution command
            $interpreterPath = $this->resolveInterpreter($interpreter);
            $envPrefix = $this->buildEnvPrefix($env, $workDir);

            $innerCmd = sprintf(
                'cd %s && %s %s %s 2>&1',
                escapeshellarg($workDir),
                $envPrefix,
                escapeshellarg($interpreterPath),
                escapeshellarg($scriptPath)
            );

            // Wrap with timeout + nice + su for isolation
            $fullCmd = sprintf(
                'sudo timeout --signal=KILL %d nice -n 15 su %s -s /bin/bash -c %s 2>&1',
                self::TIMEOUT_SECONDS,
                escapeshellarg($sandboxUser),
                escapeshellarg($innerCmd)
            );

            Log::info('[SkillSandbox] Executing', [
                'user_id' => $user->id,
                'sandbox_user' => $sandboxUser,
                'interpreter' => $interpreter,
                'work_dir' => $workDir,
                'run_id' => $runId,
            ]);

            $result = $this->shellExecWithStatus($fullCmd);

            // Truncate output if too large
            if (strlen($result['stdout']) > self::MAX_OUTPUT_BYTES) {
                $result['stdout'] = substr($result['stdout'], 0, self::MAX_OUTPUT_BYTES)
                    . "\n... [output truncated at " . self::MAX_OUTPUT_BYTES . " bytes]";
            }

            $timedOut = $result['exit_code'] === 137; // SIGKILL from timeout

            Log::info('[SkillSandbox] Completed', [
                'user_id' => $user->id,
                'run_id' => $runId,
                'exit_code' => $result['exit_code'],
                'timed_out' => $timedOut,
                'output_bytes' => strlen($result['stdout']),
            ]);

            return [
                'exit_code' => $result['exit_code'],
                'stdout' => $result['stdout'],
                'stderr' => $result['stderr'] ?? '',
                'timed_out' => $timedOut,
            ];

        } finally {
            // Always clean up the workdir
            $this->cleanupWorkDir($workDir, $sandboxUser);
        }
    }

    /**
     * Ensure a dedicated Linux user exists for this MiFrog user.
     */
    public function ensureSandboxUser(User $user): string
    {
        $username = 'mf_sandbox_' . (int) $user->id;

        // Check if user already exists
        $check = $this->shellExec(sprintf('id %s 2>&1', escapeshellarg($username)));
        if (str_contains($check, 'uid=')) {
            return $username;
        }

        // Create the sandbox user with no login shell, no home directory access outside sandbox
        $homeDir = storage_path(self::SANDBOX_DIR . '/' . $user->id);
        if (!is_dir($homeDir)) {
            mkdir($homeDir, 0755, true);
        }

        $this->shellExec(sprintf(
            'sudo useradd --system --no-create-home --home-dir %s --shell /bin/bash %s 2>&1 || true',
            escapeshellarg($homeDir),
            escapeshellarg($username)
        ));

        // Verify creation
        $verify = $this->shellExec(sprintf('id %s 2>&1', escapeshellarg($username)));
        if (!str_contains($verify, 'uid=')) {
            throw new RuntimeException("Failed to create sandbox user: {$username}");
        }

        return $username;
    }

    /**
     * Prepare an isolated working directory for this run.
     */
    private function prepareWorkDir(User $user, string $runId, string $sandboxUser): string
    {
        $baseDir = storage_path(self::SANDBOX_DIR . '/' . $user->id);
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        $workDir = $baseDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $runId);
        if (is_dir($workDir)) {
            $this->shellExec(sprintf('sudo rm -rf %s', escapeshellarg($workDir)));
        }

        mkdir($workDir, 0755, true);

        return $workDir;
    }

    /**
     * Clean up the working directory after execution.
     */
    private function cleanupWorkDir(string $workDir, string $sandboxUser): void
    {
        if ($workDir === '' || !is_dir($workDir)) {
            return;
        }

        try {
            $this->shellExec(sprintf('sudo rm -rf %s 2>&1', escapeshellarg($workDir)));
        } catch (\Throwable $e) {
            Log::warning('[SkillSandbox] Cleanup failed', [
                'work_dir' => $workDir,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveInterpreter(string $interpreter): string
    {
        return match (strtolower(trim($interpreter))) {
            'python', 'python3' => '/usr/bin/python3',
            'bash', 'sh' => '/bin/bash',
            default => throw new RuntimeException(
                "Unsupported sandbox interpreter: {$interpreter}. Only bash/sh and python3 are allowed."
            ),
        };
    }

    private function interpreterExtension(string $interpreter): string
    {
        return match (strtolower(trim($interpreter))) {
            'python', 'python3' => '.py',
            default => '.sh',
        };
    }

    private function buildEnvPrefix(array $env, string $workDir): string
    {
        $vars = array_merge([
            'HOME' => $workDir,
            'TMPDIR' => $workDir,
            'LANG' => 'en_US.UTF-8',
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
        ], $env);

        $parts = [];
        foreach ($vars as $key => $value) {
            $safeKey = preg_replace('/[^A-Za-z0-9_]/', '', (string) $key);
            if ($safeKey !== '') {
                $parts[] = $safeKey . '=' . escapeshellarg((string) $value);
            }
        }

        return 'env ' . implode(' ', $parts);
    }

    private function shellExec(string $command): string
    {
        $output = shell_exec($command);
        return $output === null ? '' : $output;
    }

    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function shellExecWithStatus(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit_code' => -1, 'stdout' => '', 'stderr' => 'Failed to start process'];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }
}
