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

namespace KevinGH\Box\RequirementChecker;

use function array_key_exists;

/**
 * @private
 */
final class PackageInfo
{
    public const EXTENSION_REGEX = '/^ext-(?<extension>.+)$/';

    private const POLYFILL_MAP = [
        'paragonie/sodium_compat' => 'libsodium',
        'phpseclib/mcrypt_compat' => 'mcrypt',
    ];

    private const SYMFONY_POLYFILL_REGEX = '/symfony\/polyfill-(?<extension>.+)/';

    public function __construct(private readonly array $packageInfo)
    {
    }

    public function getName(): string
    {
        return $this->packageInfo['name'];
    }

    public function getRequiredPhpVersion(): ?string
    {
        return $this->packageInfo['require']['php'] ?? null;
    }

    public function hasRequiredPhpVersion(): bool
    {
        return null !== $this->getRequiredPhpVersion();
    }

    /**
     * @return list<string>
     */
    public function getRequiredExtensions(): array
    {
        return self::parseExtensions($this->packageInfo['require'] ?? []);
    }

    public function getPolyfilledExtension(): ?string
    {
        // TODO: should read the "provide" section instead.
        $name = $this->packageInfo['name'];

        if (array_key_exists($name, self::POLYFILL_MAP)) {
            return self::POLYFILL_MAP[$name];
        }

        if (1 !== preg_match(self::SYMFONY_POLYFILL_REGEX, $name, $matches)) {
            return null;
        }

        $extension = $matches['extension'];

        return str_starts_with($extension, 'php') ? null : $extension;
    }

    /**
     * @param array<string, string> $constraints
     *
     * @return list<string>
     */
    public static function parseExtensions(array $constraints): array
    {
        $extensions = [];

        foreach ($constraints as $package => $constraint) {
            if (preg_match(self::EXTENSION_REGEX, $package, $matches)) {
                $extensions[] = $matches['extension'];
            }
        }

        return $extensions;
    }
}
