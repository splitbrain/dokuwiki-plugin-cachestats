<?php

namespace dokuwiki\plugin\cachestats;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Recursively scans a directory and builds cache statistics keyed by extension.
 * Data includes counts, total size, duplicate counts, and age buckets.
 */
class FileStatistics
{
    private string $path;

    private const BUCKETS = ['<1d', '<1w', '<1m', '<3m', '<6m', '<1y', '>1y'];

    private array $result = [];

    private array $hashMap = []; // md5 => [ext, count]

    /**
     * @param string $path Absolute path to the cache directory
     */
    public function __construct(string $path)
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException("Path '$path' is not a valid directory.");
        }

        $this->path = rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * Walk the directory tree and return statistics keyed by extension.
     *
     * @param callable<int,SplFileInfo>|null $cb Optional callback to report progress
     * @return array<string, array>
     */
    public function collect(?callable $cb = null): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $now = time();
        $counter = 0;
        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }

            if($cb) $cb(++$counter, $fileInfo);

            $ext = strtolower($fileInfo->getExtension()) ?: '-';
            $path = $fileInfo->getPathname();
            $size = $fileInfo->getSize();
            $mtime = $fileInfo->getMTime();

            $this->initExtension($ext);

            $this->result[$ext]['count']++;
            $this->result[$ext]['size'] += $size;

            // group by modified time
            $group = $this->getModifiedGroup($now - $mtime);
            $this->result[$ext][$group]++;

            // handle duplicates by checksum
            $md5 = md5_file($path);
            if (isset($this->hashMap[$md5])) {
                $this->hashMap[$md5]['count']++;
            } else {
                $this->hashMap[$md5] = ['ext' => $ext, 'count' => 1];
            }
        }

        // summarize duplicates
        foreach ($this->hashMap as $hash => $info) {
            if ($info['count'] > 1) {
                $ext = $info['ext'];
                $this->initExtension($ext);
                $this->result[$ext]['dups'] += $info['count'] - 1;
            }
        }

        return $this->result;
    }

    /**
     * Map file age to a human-friendly bucket label.
     *
     * @param int $ageSeconds Age in seconds since last modification
     */
    private function getModifiedGroup(int $ageSeconds): string
    {
        $day = 86400;
        return match (true) {
            $ageSeconds < $day => '<1d',
            $ageSeconds < 7 * $day => '<1w',
            $ageSeconds < 30 * $day => '<1m',
            $ageSeconds < 90 * $day => '<3m',
            $ageSeconds < 180 * $day => '<6m',
            $ageSeconds < 365 * $day => '<1y',
            default => '>1y',
        };
    }

    /**
     * Ensure an extension has all expected keys initialized.
     *
     * @param string $ext Lowercased file extension (or 'no_extension')
     */
    private function initExtension(string $ext): void
    {
        if (isset($this->result[$ext])) {
            return;
        }

        $this->result[$ext] = [
            'count' => 0,
            'size' => 0,
            'dups' => 0,
        ];
        foreach (self::BUCKETS as $bucket) {
            $this->result[$ext][$bucket] = 0;
        }
    }
}
