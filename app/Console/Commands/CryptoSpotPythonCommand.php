<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

abstract class CryptoSpotPythonCommand extends Command
{
    protected function runPythonScript(array $arguments): int
    {
        $pythonBinary = (string) env('CRYPTOSPOT_PYTHON_BINARY', 'python');
        $pythonDir = $this->resolvePythonDir();
        $command = array_merge([$pythonBinary], $arguments);
        $startedAt = now();
        $start = microtime(true);

        $this->line('Started at: '.$startedAt->toIso8601String());
        $this->line('Command: '.$this->formatCommandForOutput($command));
        $this->line('Working directory: '.$pythonDir);

        Log::info('CryptoSpot scheduled command started.', [
            'artisan_command' => $this->getName(),
            'command' => $this->formatCommandForOutput($command),
            'working_directory' => $pythonDir,
            'started_at' => $startedAt->toIso8601String(),
        ]);

        $process = new Process($command, $pythonDir);
        $process->setTimeout(null);
        $process->run();

        $stdout = trim($process->getOutput());
        $stderr = trim($process->getErrorOutput());
        $exitCode = $process->getExitCode() ?? self::FAILURE;
        $duration = round(microtime(true) - $start, 3);

        if ($stdout !== '') {
            $this->line('STDOUT:');
            $this->line($stdout);
        }

        if ($stderr !== '') {
            $this->line('STDERR:');
            $this->line($stderr);
        }

        $this->line('Exit code: '.$exitCode);
        $this->line('Duration seconds: '.$duration);

        $context = [
            'artisan_command' => $this->getName(),
            'command' => $this->formatCommandForOutput($command),
            'working_directory' => $pythonDir,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
            'duration_seconds' => $duration,
        ];

        if ($exitCode === self::SUCCESS) {
            Log::info('CryptoSpot scheduled command finished.', $context);
        } else {
            Log::error('CryptoSpot scheduled command failed.', $context);
        }

        return $exitCode;
    }

    private function resolvePythonDir(): string
    {
        $configured = (string) env('CRYPTOSPOT_PYTHON_DIR', 'python');

        if ($configured === '') {
            return base_path('python');
        }

        if (str_starts_with($configured, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) === 1) {
            return $configured;
        }

        return base_path($configured);
    }

    private function formatCommandForOutput(array $command): string
    {
        return implode(' ', array_map(static fn ($part) => escapeshellarg((string) $part), $command));
    }
}
