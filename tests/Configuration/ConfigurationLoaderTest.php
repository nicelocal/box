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

namespace KevinGH\Box\Configuration;

use KevinGH\Box\Json\JsonValidationException;
use KevinGH\Box\Test\FileSystemTestCase;
use function KevinGH\Box\FileSystem\dump_file;
use function KevinGH\Box\FileSystem\touch;

/**
 * @covers \KevinGH\Box\Configuration\ConfigurationLoader
 *
 * @internal
 */
class ConfigurationLoaderTest extends FileSystemTestCase
{
    private ConfigurationLoader $loader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loader = new ConfigurationLoader();
    }

    public function test_it_can_load_a_configuration(): void
    {
        touch('index.php');
        dump_file('box.json.dist', '{}');

        self::assertInstanceOf(
            Configuration::class,
            $this->loader->loadFile('box.json.dist'),
        );
    }

    public function test_it_can_load_a_configuration_without_a_file(): void
    {
        touch('index.php');

        self::assertInstanceOf(
            Configuration::class,
            $this->loader->loadFile(null),
        );
    }

    public function test_it_cannot_load_an_invalid_config_file(): void
    {
        touch('index.php');
        dump_file('box.json.dist', '{"foo": "bar"}');

        $this->expectException(JsonValidationException::class);

        $this->loader->loadFile('box.json.dist');
    }
}
