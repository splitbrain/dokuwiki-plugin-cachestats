<?php

use dokuwiki\plugin\cachestats\FileStatistics;
use splitbrain\phpcli\Options;
use splitbrain\phpcli\TableFormatter;

/**
 * DokuWiki Plugin cachestats (CLI Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class cli_plugin_cachestats extends \dokuwiki\Extension\CLIPlugin
{
    /** @var string[] */
    private array $buckets = ['<1d', '<1w', '<1m', '<3m', '<6m', '<1y', '>1y'];

    /** @inheritDoc */
    protected function setup(Options $options)
    {
        $options->setHelp('Collect statistics about the cache directory.');

        $options->registerOption('format', 'Output format: table|json|csv', 'f', 'table');
        $options->registerOption('sort', 'Sort by count|size|dups', 's', false);
    }

    /** @inheritDoc */
    protected function main(Options $options)
    {
        global $conf;

        $sort = $options->getOpt('sort', 'size');
        if (!in_array($sort, ['count', 'size', 'dups'])) {
            $this->error("Invalid sort option '$sort'. Allowed are: count, size, dups.");
            return 1;
        }

        $format = $options->getOpt('format', 'table');
        if (!in_array($format, ['table', 'json', 'csv'])) {
            $this->error("Invalid format option '$format'. Allowed are: table, json, csv.");
            return 1;
        }

        if ($format === 'json') {
            fprintf(STDERR, 'Collecting cache statistics from ' . $conf['cachedir'] . "…\n");
        } else {
            $this->info('Collecting cache statistics from ' . $conf['cachedir'] . '…');
        }

        $stats = (new FileStatistics($conf['cachedir']))->collect();

        // for debugging
        print_r($stats);

        $keys = array_unique(
            array_merge(
                array_keys($stats['extensions']),
                array_keys($stats['sizes']),
                array_keys($stats['duplicates'])
            )
        );
        $result = [];
        foreach ($keys as $key) {
            $modified = [];
            foreach ($this->buckets as $bucket) {
                $modified[$bucket] = $stats['modified_groups'][$key][$bucket] ?? 0;
            }
            $result[$key] = [
                'count' => $stats['extensions'][$key] ?? 0,
                'size' => $stats['sizes'][$key] ?? 0,
                'dups' => $stats['duplicates'][$key] ?? 0,
                'modified' => $modified,
            ];
        }

        // sort with preserved keys
        uasort($result, function ($a, $b) use ($sort) {
            return $b[$sort] <=> $a[$sort];
        });

        match ($format) {
            'json' => $this->print_json($result),
            'csv' => $this->print_csv($result),
            default => $this->print_table($result),
        };
        return 0;
    }

    /**
     * Output statistics as JSON
     */
    private function print_json(array $result): void
    {
        echo json_encode($result, JSON_PRETTY_PRINT);
    }

    /**
     * Output statistics as CSV
     */
    private function print_csv(array $result): void
    {
        $handle = fopen('php://output', 'w');
        if ($handle === false) {
            $this->error('Could not open output for CSV.');
            return;
        }

        $header = array_merge(
            ['extension', 'count', 'total_size_bytes', 'duplicate_files'],
            $this->buckets
        );
        fputcsv($handle, $header);

        foreach ($result as $ext => $data) {
            $row = [
                $ext,
                $data['count'],
                $data['size'],
                $data['dups']
            ];
            foreach ($this->buckets as $bucket) {
                $row[] = $data['modified'][$bucket];
            }
            fputcsv($handle, $row);
        }
    }

    /**
     * Output statistics as table
     */
    private function print_table(array $result): void
    {
        $tr = new TableFormatter($this->colors);
        $columns = array_merge(['*', 7, 10, 7], array_fill(0, count($this->buckets), 5));
        $headers = array_merge(
            ['Extension', 'File Count', 'Total Size (bytes)', 'Duplicate Files'],
            $this->buckets
        );
        echo $tr->format(
            $columns,
            $headers,
            ['', '', '', '']
        );

        foreach ($result as $ext => $data) {
            $row = [
                $ext,
                sprintf("% 7s", number_format($data['count'])),
                sprintf("% 10s", filesize_h($data['size'])),
                sprintf("% 7s", number_format($data['dups']))
            ];
            foreach ($this->buckets as $bucket) {
                $row[] = sprintf("% 5s", number_format($data['modified'][$bucket]));
            }
            echo $tr->format(
                $columns,
                $row,
                ['', '', '', '']
            );
        }
    }
}
