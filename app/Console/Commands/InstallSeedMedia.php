<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

#[Signature('app:install-seed-media {--zip=seed-media.zip : Path to the media zip (relative to project root)} {--force : Overwrite existing objects}')]
#[Description('Extract the media zip and upload its contents to the configured filesystem disk (MinIO/S3)')]
class InstallSeedMedia extends Command
{
    public function handle(): int
    {
        $zipPath = base_path((string) $this->option('zip'));

        if (! is_file($zipPath)) {
            $this->error("Zip not found at {$zipPath}");
            $this->line('Download seed-media.zip from the shared Google Drive and drop it in the project root.');

            return self::FAILURE;
        }

        $disk = config('filesystems.default');
        $force = (bool) $this->option('force');

        $this->info("Zip: {$zipPath}");
        $this->info("Target disk: [{$disk}]");

        $tempDir = $this->extractZip($zipPath);

        try {
            $files = $this->collectFiles($tempDir);

            if ($files === []) {
                $this->warn('No files found inside the zip under seed/.');

                return self::FAILURE;
            }

            $this->uploadFiles($files, $tempDir, $disk, $force);
        } finally {
            $this->cleanupDirectory($tempDir);
        }

        $this->info('Done. Manifest paths should now resolve against the storage disk.');

        return self::SUCCESS;
    }

    private function extractZip(string $zipPath): string
    {
        $tempDir = storage_path('app/seed-media-extract-'.bin2hex(random_bytes(4)));

        if (! mkdir($tempDir, 0755, true) && ! is_dir($tempDir)) {
            throw new RuntimeException("Failed to create temp dir {$tempDir}");
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath);

        if ($result !== true) {
            $this->cleanupDirectory($tempDir);

            throw new RuntimeException("Failed to open zip (code {$result})");
        }

        $this->info('Extracting '.$zip->numFiles.' entries...');
        $zip->extractTo($tempDir);
        $zip->close();

        return $tempDir;
    }

    /**
     * @return array<int, string>
     */
    private function collectFiles(string $root): array
    {
        $seedDir = $root.'/seed';

        if (! is_dir($seedDir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($seedDir, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<int, string>  $files
     */
    private function uploadFiles(array $files, string $root, string $disk, bool $force): void
    {
        $total = count($files);
        $this->info("Uploading {$total} files...");

        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $uploaded = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $relative = ltrim(str_replace($root, '', $file), '/');

            if (! $force && Storage::disk($disk)->exists($relative)) {
                $skipped++;
                $progress->advance();

                continue;
            }

            $handle = fopen($file, 'rb');
            Storage::disk($disk)->put($relative, $handle);

            if (is_resource($handle)) {
                fclose($handle);
            }

            $uploaded++;
            $progress->advance();
        }

        $progress->finish();
        $this->newLine();
        $this->info("Uploaded: {$uploaded} / Skipped existing: {$skipped}");
    }

    private function cleanupDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($dir);
    }
}
