<?php

namespace App\Services;

class VideoProbeService
{
    public function getDurationSeconds(string $absolutePath): ?float
    {
        if (! file_exists($absolutePath)) {
            return null;
        }

        $command = sprintf(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($absolutePath),
        );

        $output = trim((string) @shell_exec($command));

        return is_numeric($output) ? (float) $output : null;
    }
}
