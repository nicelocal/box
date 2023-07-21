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
use KevinGH\Box\Compactor\Compactors;
use KevinGH\Box\MapFile;

/** @implements Task<null, null, null> */
final class InitTask implements Task
{
    public static self $cache;

    public readonly string $cwd;

    public function __construct(
        public readonly MapFile $mapFile,
        public readonly Compactors $compactors
    ) {
        $this->cwd = getcwd();
    }

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        self::$cache = $this;

        return null;
    }
}
