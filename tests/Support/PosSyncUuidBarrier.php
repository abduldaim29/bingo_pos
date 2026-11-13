<?php

namespace Tests\Support;

use RuntimeException;

final class PosSyncUuidBarrier
{
    public static function waitForPeer(
        string $directory,
        string $workerId,
        int $participants = 2,
        int $timeoutSeconds = 20
    ): void {
        if (! is_dir($directory)) {
            throw new RuntimeException('The synchronization barrier directory is missing.');
        }

        $safeWorkerId = preg_replace('/[^A-Za-z0-9_-]/', '', $workerId);
        if ($safeWorkerId === '') {
            throw new RuntimeException('The synchronization barrier worker ID is invalid.');
        }

        $marker = $directory.DIRECTORY_SEPARATOR.'ready-'.$safeWorkerId;
        $handle = fopen($marker, 'x');
        if ($handle === false) {
            throw new RuntimeException('Unable to signal synchronization barrier readiness.');
        }

        try {
            $written = 0;
            while ($written < strlen($safeWorkerId)) {
                $length = fwrite($handle, substr($safeWorkerId, $written));
                if ($length === false || $length === 0) {
                    throw new RuntimeException('Unable to write the synchronization barrier marker.');
                }

                $written += $length;
            }
        } finally {
            fclose($handle);
        }

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $readyMarkers = glob($directory.DIRECTORY_SEPARATOR.'ready-*');
            if ($readyMarkers !== false && count($readyMarkers) >= $participants) {
                return;
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for concurrent sync workers.');
            }

            usleep(10_000);
        } while (true);
    }
}
