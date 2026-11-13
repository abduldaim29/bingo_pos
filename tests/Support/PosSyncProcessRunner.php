<?php

namespace Tests\Support;

use RuntimeException;
use Throwable;

final class PosSyncProcessRunner
{
    /**
     * @param  array<string, array<string, mixed>>  $jobs
     * @return array<string, array<string, mixed>>
     */
    public function run(
        array $jobs,
        string $workerScript,
        string $workingDirectory,
        string $outputDirectory,
        int $timeoutSeconds = 45
    ): array {
        if (! function_exists('proc_open')) {
            throw new RuntimeException('PHP proc_open is required for the concurrency test.');
        }

        $workers = [];
        $results = [];
        $primaryFailure = null;

        try {
            foreach ($jobs as $workerId => $input) {
                $stdoutPath = $outputDirectory.DIRECTORY_SEPARATOR.$workerId.'.stdout';
                $stderrPath = $outputDirectory.DIRECTORY_SEPARATOR.$workerId.'.stderr';
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, $workerScript],
                    [
                        0 => ['pipe', 'r'],
                        1 => ['file', $stdoutPath, 'w'],
                        2 => ['file', $stderrPath, 'w'],
                    ],
                    $pipes,
                    $workingDirectory,
                    null,
                    ['bypass_shell' => true]
                );

                if (! is_resource($process)) {
                    throw new RuntimeException("Unable to start sync worker {$workerId}.");
                }

                $workers[$workerId] = [
                    'process' => $process,
                    'stdin' => $pipes[0] ?? null,
                    'stdout_path' => $stdoutPath,
                    'stderr_path' => $stderrPath,
                    'finished' => false,
                    'exit_code' => null,
                ];

                $encodedInput = json_encode($input, JSON_THROW_ON_ERROR);
                $written = 0;
                $inputLength = strlen($encodedInput);

                while ($written < $inputLength) {
                    $chunkLength = fwrite($workers[$workerId]['stdin'], substr($encodedInput, $written));
                    if ($chunkLength === false || $chunkLength === 0) {
                        throw new RuntimeException("Unable to send input to sync worker {$workerId}.");
                    }

                    $written += $chunkLength;
                }

                fclose($workers[$workerId]['stdin']);
                $workers[$workerId]['stdin'] = null;
            }

            $deadline = microtime(true) + $timeoutSeconds;
            do {
                $allFinished = true;

                foreach ($workers as $workerId => &$worker) {
                    if ($worker['finished']) {
                        continue;
                    }

                    $status = proc_get_status($worker['process']);
                    if (! $status['running']) {
                        $worker['finished'] = true;
                        $worker['exit_code'] = (int) $status['exitcode'];
                        if ($worker['exit_code'] !== 0) {
                            throw new RuntimeException(
                                $this->workerFailureMessage($workerId, $worker)
                            );
                        }

                        continue;
                    }

                    $allFinished = false;
                }
                unset($worker);

                if ($allFinished) {
                    break;
                }

                if (microtime(true) >= $deadline) {
                    throw new RuntimeException($this->timeoutFailureMessage($workers));
                }

                usleep(10_000);
            } while (true);

            foreach ($workers as $workerId => &$worker) {
                $closeCode = proc_close($worker['process']);
                $worker['process'] = null;
                $exitCode = $worker['exit_code'];
                if ($exitCode === null || $exitCode < 0) {
                    $exitCode = $closeCode;
                }

                if ($exitCode !== 0) {
                    $worker['exit_code'] = $exitCode;
                    throw new RuntimeException($this->workerFailureMessage($workerId, $worker));
                }

                $stdout = file_get_contents($worker['stdout_path']);
                if ($stdout === false) {
                    throw new RuntimeException("Unable to read sync worker {$workerId} output.");
                }

                $result = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($result)) {
                    throw new RuntimeException("Sync worker {$workerId} returned invalid JSON.");
                }

                $results[$workerId] = $result;
            }
            unset($worker);
        } catch (Throwable $exception) {
            $primaryFailure = $exception;
        }

        try {
            $cleanupFailures = $this->cleanupWorkers($workers);
        } catch (Throwable $exception) {
            $cleanupFailures = ['Worker cleanup raised an unexpected error: '.$exception->getMessage()];
        }
        if ($primaryFailure !== null) {
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    $primaryFailure->getMessage()
                    .PHP_EOL.'Worker cleanup failure(s): '.implode('; ', $cleanupFailures),
                    0,
                    $primaryFailure
                );
            }

            throw $primaryFailure;
        }

        if ($cleanupFailures !== []) {
            throw new RuntimeException('Worker cleanup failure(s): '.implode('; ', $cleanupFailures));
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $worker
     */
    private function workerFailureMessage(string $workerId, array $worker): string
    {
        $stderr = is_file($worker['stderr_path'])
            ? trim((string) file_get_contents($worker['stderr_path']))
            : '';

        return "Sync worker {$workerId} failed with exit code {$worker['exit_code']}."
            .($stderr === '' ? '' : PHP_EOL.$stderr);
    }

    /**
     * @param  array<string, array<string, mixed>>  $workers
     */
    private function timeoutFailureMessage(array $workers): string
    {
        $output = [];

        foreach ($workers as $workerId => $worker) {
            foreach (['stdout_path' => 'stdout', 'stderr_path' => 'stderr'] as $pathKey => $streamName) {
                $path = $worker[$pathKey];
                if (! is_file($path)) {
                    continue;
                }

                $contents = file_get_contents($path);
                if ($contents === false || trim($contents) === '') {
                    continue;
                }

                $contents = trim($contents);
                if (strlen($contents) > 8192) {
                    $contents = '[earlier output omitted]'.PHP_EOL.substr($contents, -8192);
                }

                $output[] = "{$workerId} {$streamName}:".PHP_EOL.$contents;
            }
        }

        return 'Timed out waiting for concurrent sync workers.'
            .($output === [] ? '' : PHP_EOL.implode(PHP_EOL, $output));
    }

    /**
     * @param  array<string, array<string, mixed>>  $workers
     * @return list<string>
     */
    private function cleanupWorkers(array &$workers): array
    {
        $failures = [];

        foreach ($workers as $workerId => &$worker) {
            if (! is_resource($worker['process'])) {
                continue;
            }

            try {
                if (proc_get_status($worker['process'])['running'] && ! proc_terminate($worker['process'], 15)) {
                    if (proc_get_status($worker['process'])['running']) {
                        $failures[] = "Unable to request graceful termination for {$workerId}.";
                    }
                }
            } catch (Throwable $exception) {
                $failures[] = "Unable to inspect or stop {$workerId}: {$exception->getMessage()}";
            }
        }
        unset($worker);

        if (! $this->waitForWorkersToExit($workers, 2, $failures)) {
            foreach ($workers as $workerId => &$worker) {
                if (! is_resource($worker['process'])) {
                    continue;
                }

                try {
                    if (proc_get_status($worker['process'])['running'] && ! proc_terminate($worker['process'], 9)) {
                        if (proc_get_status($worker['process'])['running']) {
                            $failures[] = "Unable to force-terminate {$workerId}.";
                        }
                    }
                } catch (Throwable $exception) {
                    $failures[] = "Unable to force-stop {$workerId}: {$exception->getMessage()}";
                }
            }
            unset($worker);

            if (! $this->waitForWorkersToExit($workers, 5, $failures)) {
                $failures[] = 'One or more sync workers remained running after bounded termination waits.';
            }
        }

        foreach ($workers as $workerId => &$worker) {
            if (is_resource($worker['stdin'])) {
                try {
                    fclose($worker['stdin']);
                } catch (Throwable $exception) {
                    $failures[] = "Unable to close {$workerId} input: {$exception->getMessage()}";
                }
                $worker['stdin'] = null;
            }

            if (is_resource($worker['process'])) {
                try {
                    $status = proc_get_status($worker['process']);
                    if ($status['running']) {
                        $failures[] = "{$workerId} was still running before process reaping.";
                        continue;
                    }

                    proc_close($worker['process']);
                    $worker['process'] = null;
                } catch (Throwable $exception) {
                    $failures[] = "Unable to reap {$workerId}: {$exception->getMessage()}";
                }
            }
        }
        unset($worker);

        return $failures;
    }

    /**
     * @param  array<string, array<string, mixed>>  $workers
     * @param  list<string>  $failures
     */
    private function waitForWorkersToExit(array &$workers, int $timeoutSeconds, array &$failures): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $allExited = true;

            foreach ($workers as $workerId => &$worker) {
                if (! is_resource($worker['process'])) {
                    continue;
                }

                try {
                    $status = proc_get_status($worker['process']);
                } catch (Throwable $exception) {
                    $failures[] = "Unable to check {$workerId} termination: {$exception->getMessage()}";
                    $allExited = false;
                    continue;
                }

                if ($status['running']) {
                    $allExited = false;
                    continue;
                }

                $worker['finished'] = true;
                if ($worker['exit_code'] === null && $status['exitcode'] >= 0) {
                    $worker['exit_code'] = (int) $status['exitcode'];
                }
            }
            unset($worker);

            if ($allExited) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(10_000);
        } while (true);
    }
}
