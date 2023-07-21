<?php

declare(strict_types=1);

/*
 * This file is part of the box project.
 *
 * (c) Kevin Herrera <kevin@herrera.io>
 *     Théo Fidry <theo.fidry@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace KevinGH\Box\Tasks;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use function KevinGH\Box\FileSystem\file_contents;

/**
 * @implements Task<void, BoxTask, ?array>
 */
final class ProcessorTask implements Task
{
    public function __construct(
        private readonly string $file
    ) {
    }

    /**
     * Executed when running the Task in a worker.
     */
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $cache = InitTask::$cache;

        // Keep the fully qualified call here since this function may be executed without the right autoloading
        // mechanism
        \KevinGH\Box\register_aliases();
        if (true === \KevinGH\Box\is_parallel_processing_enabled()) {
            \KevinGH\Box\register_error_handler();
        }

        $contents = file_contents($this->file);

        $local = ($cache->mapFile)($this->file);

        $processedContents = $cache->compactors->compact($local, $contents);

        return [$local, $processedContents, $cache->compactors->getScoperSymbolsRegistry()];
    }
}
