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
    /** @inheritDoc */
    protected function setup(Options $options)
    {
        $options->setHelp('Collect statistics about the cache directory.');

        $options->registerOption('json', 'Output results in JSON format', 'j', false);
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

        if ($options->getOpt('json')) {
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
            $result[$key] = [
                'count' => $stats['extensions'][$key] ?? 0,
                'size' => $stats['sizes'][$key] ?? 0,
                'dups' => $stats['duplicates'][$key] ?? 0,
            ];
        }

        // sort with preserved keys
        uasort($result, function ($a, $b) use ($sort) {
            return $b[$sort] <=> $a[$sort];
        });

        if ($options->getOpt('json')) {
            echo json_encode($result, JSON_PRETTY_PRINT);
            return 0;
        }

        $tr = new TableFormatter($this->colors);
        echo $tr->format(
            ['*', 10, 10, 10],
            ['Extension', 'File Count', 'Total Size (bytes)', 'Duplicate Files'],
            ['', '', '', '']
        );

        foreach ($result as $ext => $data) {
            echo $tr->format(
                ['*', 10, 10, 10],
                [
                    $ext,
                    sprintf("% 10s", number_format($data['count'])),
                    sprintf("% 10s", filesize_h($data['size'])),
                    sprintf("% 10s", number_format($data['dups']))
                ],
                ['', '', '', '']
            );
        }

        return 0;
    }
}
