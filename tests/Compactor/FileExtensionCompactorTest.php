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

namespace KevinGH\Box\Compactor;

use KevinGH\Box\UnsupportedMethodCall;
use PHPUnit\Framework\TestCase;

/**
 * @covers \KevinGH\Box\Compactor\FileExtensionCompactor
 *
 * @internal
 */
class FileExtensionCompactorTest extends TestCase
{
    public function test_it_does_not_support_files_with_unknown_extension(): void
    {
        $file = '/path/to/file.js';
        $contents = 'file contents';

        $expected = $contents;

        $compactor = new class([]) extends FileExtensionCompactor {
            protected function compactContent(string $contents): string
            {
                throw UnsupportedMethodCall::forMethod(self::class, __METHOD__);
            }
        };

        $actual = $compactor->compact($file, $contents);

        self::assertSame($expected, $actual);
    }

    public function test_it_supports_files_with_the_given_extensions(): void
    {
        $file = '/path/to/file.php';
        $contents = 'file contents';

        $expected = 'compacted contents';

        $compactor = new class($expected) extends FileExtensionCompactor {
            public function __construct(private readonly string $expected)
            {
                parent::__construct(['php']);
            }

            protected function compactContent(string $contents): string
            {
                return $this->expected;
            }
        };

        $actual = $compactor->compact($file, $contents);

        self::assertSame($expected, $actual);
    }
}
