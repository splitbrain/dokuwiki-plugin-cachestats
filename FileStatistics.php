<?php

namespace dokuwiki\plugin\cachestats;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Class FileStatistics
 *
 * Recursively scans a directory and collects:
 *  - number of files per file extension
 *  - duplicate files (based on MD5 checksum) per file extension
 *  - size of files summed up per extension
 *  - number of files per extension grouped by last modified date
 *  - total number of files
 *  - total size of all files
 */
class FileStatistics
{
    private string $path;

    /** @var string[] */
    private array $buckets = ['<1d', '<1w', '<1m', '<3m', '<6m', '<1y', '>1y'];

    private array $stats = [
        'extensions' => [],
        'duplicates' => [],
        'sizes' => [],
        'modified_groups' => [],
        'total_files' => 0,
        'total_size' => 0,
    ];

    private array $hashMap = []; // md5 => [ext, count]

    public function __construct(string $path)
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException("Path '$path' is not a valid directory.");
        }

        $this->path = rtrim($path, DIRECTORY_SEPARATOR);
    }

    public function collect(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $now = time();

        foreach ($iterator as $fileInfo) {
            /** @var SplFileInfo $fileInfo */
            if (!$fileInfo->isFile()) {
                continue;
            }

            $this->stats['total_files']++;
            $ext = strtolower($fileInfo->getExtension()) ?: 'no_extension';
            $path = $fileInfo->getPathname();
            $size = $fileInfo->getSize();
            $mtime = $fileInfo->getMTime();

            // size aggregated per extension
            $this->stats['sizes'][$ext] = ($this->stats['sizes'][$ext] ?? 0) + $size;
            $this->stats['total_size'] += $size;

            // count per extension
            $this->stats['extensions'][$ext] = ($this->stats['extensions'][$ext] ?? 0) + 1;

            // group by modified time
            $group = $this->getModifiedGroup($now - $mtime);
            $this->stats['modified_groups'][$ext][$group] =
                ($this->stats['modified_groups'][$ext][$group] ?? 0) + 1;

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
                $this->stats['duplicates'][$info['ext']] =
                    ($this->stats['duplicates'][$info['ext']] ?? 0) + ($info['count'] - 1);
            }
        }

        return $this->buildResult();
    }

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
     * Combine collected sub statistics into a single result array keyed by extension
     */
    private function buildResult(): array
    {
        $keys = array_unique(
            array_merge(
                array_keys($this->stats['extensions']),
                array_keys($this->stats['sizes']),
                array_keys($this->stats['duplicates']),
                array_keys($this->stats['modified_groups'])
            )
        );

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = [
                'count' => $this->stats['extensions'][$key] ?? 0,
                'size' => $this->stats['sizes'][$key] ?? 0,
                'dups' => $this->stats['duplicates'][$key] ?? 0,
            ];
            foreach ($this->buckets as $bucket) {
                $result[$key][$bucket] = $this->stats['modified_groups'][$key][$bucket] ?? 0;
            }
        }

        return $result;
    }
}
