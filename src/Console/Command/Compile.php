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

namespace KevinGH\Box\Console\Command;

use Amp\CompositeException;
use Fidry\Console\Command\Command;
use Fidry\Console\Command\CommandAware;
use Fidry\Console\Command\CommandAwareness;
use Fidry\Console\Command\Configuration as CommandConfiguration;
use Fidry\Console\ExitCode;
use Fidry\Console\Input\IO;
use Humbug\PhpScoper\Symbol\SymbolsRegistry;
use KevinGH\Box\Amp\FailureCollector;
use KevinGH\Box\Box;
use KevinGH\Box\Compactor\Compactor;
use KevinGH\Box\Composer\ComposerConfiguration;
use KevinGH\Box\Composer\ComposerOrchestrator;
use KevinGH\Box\Composer\IncompatibleComposerVersion;
use KevinGH\Box\Configuration\Configuration;
use KevinGH\Box\Console\Logger\CompilerLogger;
use KevinGH\Box\Console\MessageRenderer;
use KevinGH\Box\MapFile;
use KevinGH\Box\Phar\CompressionAlgorithm;
use KevinGH\Box\RequirementChecker\DecodedComposerJson;
use KevinGH\Box\RequirementChecker\DecodedComposerLock;
use KevinGH\Box\RequirementChecker\RequirementsDumper;
use KevinGH\Box\StubGenerator;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Webmozart\Assert\Assert;
use function array_map;
use function array_shift;
use function count;
use function decoct;
use function explode;
use function file_exists;
use function filesize;
use function implode;
use function is_callable;
use function is_string;
use function KevinGH\Box\bump_open_file_descriptor_limit;
use function KevinGH\Box\check_php_settings;
use function KevinGH\Box\disable_parallel_processing;
use function KevinGH\Box\FileSystem\chmod;
use function KevinGH\Box\FileSystem\dump_file;
use function KevinGH\Box\FileSystem\make_path_absolute;
use function KevinGH\Box\FileSystem\make_path_relative;
use function KevinGH\Box\FileSystem\remove;
use function KevinGH\Box\FileSystem\rename;
use function KevinGH\Box\format_size;
use function KevinGH\Box\format_time;
use function memory_get_peak_usage;
use function memory_get_usage;
use function microtime;
use function putenv;
use function Safe\getcwd;
use function sprintf;
use function var_export;
use const KevinGH\Box\BOX_ALLOW_XDEBUG;
use const PHP_EOL;

/**
 * @private
 */
final class Compile implements CommandAware
{
    use CommandAwareness;

    public const NAME = 'compile';

    private const HELP = <<<'HELP'
        The <info>%command.name%</info> command will compile code in a new PHAR based on a variety of settings.
        <comment>
          This command relies on a configuration file for loading
          PHAR packaging settings. If a configuration file is not
          specified through the <info>--config|-c</info> option, one of
          the following files will be used (in order): <info>box.json</info>,
          <info>box.json.dist</info>
        </comment>
        The configuration file is actually a JSON object saved to a file. For more
        information check the documentation online:
        <comment>
          https://github.com/humbug/box
        </comment>
        HELP;

    private const DEBUG_OPTION = 'debug';
    private const NO_PARALLEL_PROCESSING_OPTION = 'no-parallel';
    private const NO_RESTART_OPTION = 'no-restart';
    private const DEV_OPTION = 'dev';
    private const NO_CONFIG_OPTION = 'no-config';
    private const WITH_DOCKER_OPTION = 'with-docker';
    private const COMPOSER_BIN_OPTION = 'composer-bin';
    private const ALLOW_COMPOSER_COMPOSER_CHECK_FAILURE_OPTION = 'allow-composer-check-failure';

    private const DEBUG_DIR = '.box_dump';

    public function __construct(private string $header)
    {
    }

    public function getConfiguration(): CommandConfiguration
    {
        return new CommandConfiguration(
            self::NAME,
            '🔨  Compiles an application into a PHAR',
            self::HELP,
            [],
            [
                new InputOption(
                    self::DEBUG_OPTION,
                    null,
                    InputOption::VALUE_NONE,
                    'Dump the files added to the PHAR in a `'.self::DEBUG_DIR.'` directory',
                ),
                new InputOption(
                    self::NO_PARALLEL_PROCESSING_OPTION,
                    null,
                    InputOption::VALUE_NONE,
                    'Disable the parallel processing',
                ),
                new InputOption(
                    self::NO_RESTART_OPTION,
                    null,
                    InputOption::VALUE_NONE,
                    'Do not restart the PHP process. Box restarts the process by default to disable xdebug and set `phar.readonly=0`',
                ),
                new InputOption(
                    self::DEV_OPTION,
                    null,
                    InputOption::VALUE_NONE,
                    'Skips the compression step',
                ),
                new InputOption(
                    self::NO_CONFIG_OPTION,
                    null,
                    InputOption::VALUE_NONE,
                    'Ignore the config file even when one is specified with the --config option',
                ),
                new InputOption(
                    self::WITH_DOCKER_OPTION,
                    null,
                    InputOption::VALUE_NONE,
                    'Generates a Dockerfile',
                ),
                new InputOption(
                    self::COMPOSER_BIN_OPTION,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Composer binary to use',
                ),
                new InputOption(
                    self::ALLOW_COMPOSER_COMPOSER_CHECK_FAILURE_OPTION,
                    null,
                    InputOption::VALUE_NONE,
                    'To continue even if an unsupported Composer version is detected',
                ),
                ConfigOption::getOptionInput(),
                ChangeWorkingDirOption::getOptionInput(),
            ],
        );
    }

    public function execute(IO $io): int
    {
        if ($io->getOption(self::NO_RESTART_OPTION)->asBoolean()) {
            putenv(BOX_ALLOW_XDEBUG.'=1');
        }

        $debug = $io->getOption(self::DEBUG_OPTION)->asBoolean();

        if ($debug) {
            $io->setVerbosity(OutputInterface::VERBOSITY_DEBUG);
        }

        check_php_settings($io);

        if ($io->getOption(self::NO_PARALLEL_PROCESSING_OPTION)->asBoolean()) {
            disable_parallel_processing();
            $io->writeln(
                '<info>[debug] Disabled parallel processing</info>',
                OutputInterface::VERBOSITY_DEBUG,
            );
        }

        ChangeWorkingDirOption::changeWorkingDirectory($io);

        $io->writeln($this->header);

        $config = $io->getOption(self::NO_CONFIG_OPTION)->asBoolean()
            ? Configuration::create(null, new stdClass())
            : ConfigOption::getConfig($io, true);
        $config->setComposerBin(self::getComposerBin($io));
        $path = $config->getOutputPath();

        $logger = new CompilerLogger($io);

        $startTime = microtime(true);

        $logger->logStartBuilding($path);

        $this->removeExistingArtifacts($config, $logger, $debug);

        // Adding files might result in opening a lot of files. Either because not parallelized or when creating the
        // workers for parallelization.
        // As a result, we bump the file descriptor to an arbitrary number to ensure this process can run correctly
        $restoreLimit = bump_open_file_descriptor_limit(2048, $io);

        try {
            $box = $this->createPhar($config, $logger, $io, $debug);
        } finally {
            $restoreLimit();
        }

        self::correctPermissions($path, $config, $logger);

        self::logEndBuilding($config, $logger, $io, $box, $path, $startTime);

        if ($io->getOption(self::WITH_DOCKER_OPTION)->asBoolean()) {
            return $this->generateDockerFile($io);
        }

        return ExitCode::SUCCESS;
    }

    private function createPhar(
        Configuration $config,
        CompilerLogger $logger,
        IO $io,
        bool $debug,
    ): Box {
        $box = Box::create($config->getTmpOutputPath());

        self::checkComposerVersion($config, $logger, $io);

        $box->startBuffering();

        self::registerReplacementValues($config, $box, $logger);
        self::registerCompactors($config, $box, $logger);
        self::registerFileMapping($config, $box, $logger);

        // Registering the main script _before_ adding the rest if of the files is _very_ important. The temporary
        // file used for debugging purposes and the Composer dump autoloading will not work correctly otherwise.
        $main = self::registerMainScript($config, $box, $logger);

        $check = self::registerRequirementsChecker($config, $box, $logger);

        self::addFiles($config, $box, $logger, $io);

        self::registerStub($config, $box, $main, $check, $logger);
        self::configureMetadata($config, $box, $logger);

        self::commit($box, $config, $logger);

        self::checkComposerFiles($box, $config, $logger);

        if ($debug) {
            $box->getPhar()->extractTo(self::DEBUG_DIR, null, true);
        }

        self::configureCompressionAlgorithm(
            $config,
            $box,
            $io->getOption(self::DEV_OPTION)->asBoolean(),
            $io,
            $logger,
        );

        self::signPhar($config, $box, $config->getTmpOutputPath(), $io, $logger);

        if ($config->getTmpOutputPath() !== $config->getOutputPath()) {
            rename($config->getTmpOutputPath(), $config->getOutputPath());
        }

        return $box;
    }

    private static function getComposerBin(IO $io): ?string
    {
        $composerBin = $io->getOption(self::COMPOSER_BIN_OPTION)->asNullableNonEmptyString();

        return null === $composerBin ? null : make_path_absolute($composerBin, getcwd());
    }

    private function removeExistingArtifacts(Configuration $config, CompilerLogger $logger, bool $debug): void
    {
        $path = $config->getOutputPath();

        if ($debug) {
            remove(self::DEBUG_DIR);

            dump_file(
                self::DEBUG_DIR.'/.box_configuration',
                ConfigurationExporter::export($config),
            );
        }

        if (false === file_exists($path)) {
            return;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            sprintf(
                'Removing the existing PHAR "%s"',
                $path,
            ),
        );

        remove($path);
    }

    private static function checkComposerVersion(
        Configuration $config,
        CompilerLogger $logger,
        IO $io,
    ): void {
        if (!$config->dumpAutoload()) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                'Skipping the Composer compatibility check: the autoloader is not dumped',
            );

            return;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            'Checking Composer compatibility',
        );

        try {
            ComposerOrchestrator::checkVersion(
                $config->getComposerBin(),
                $io,
            );

            $logger->log(
                CompilerLogger::CHEVRON_PREFIX,
                'Supported version detected',
            );
        } catch (IncompatibleComposerVersion $incompatibleComposerVersion) {
            if ($io->getOption(self::ALLOW_COMPOSER_COMPOSER_CHECK_FAILURE_OPTION)->asBoolean()) {
                $logger->log(
                    CompilerLogger::CHEVRON_PREFIX,
                    'Warning! Incompatible composer version detected: '.$incompatibleComposerVersion->getMessage(),
                );

                return; // Swallow the exception
            }

            throw $incompatibleComposerVersion;
        }
    }

    private static function registerReplacementValues(Configuration $config, Box $box, CompilerLogger $logger): void
    {
        $values = $config->getReplacements();

        if (0 === count($values)) {
            return;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            'Setting replacement values',
        );

        foreach ($values as $key => $value) {
            $logger->log(
                CompilerLogger::PLUS_PREFIX,
                sprintf(
                    '%s: %s',
                    $key,
                    $value,
                ),
            );
        }

        $box->registerPlaceholders($values);
    }

    private static function registerCompactors(Configuration $config, Box $box, CompilerLogger $logger): void
    {
        $compactors = $config->getCompactors();

        if (0 === count($compactors)) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                'No compactor to register',
            );

            return;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            'Registering compactors',
        );

        $logCompactors = static function (Compactor $compactor) use ($logger): void {
            $compactorClassParts = explode('\\', $compactor::class);

            if (str_starts_with($compactorClassParts[0], '_HumbugBox')) {
                // Keep the non prefixed class name for the user
                array_shift($compactorClassParts);
            }

            $logger->log(
                CompilerLogger::PLUS_PREFIX,
                implode('\\', $compactorClassParts),
            );
        };

        array_map($logCompactors, $compactors->toArray());

        $box->registerCompactors($compactors);
    }

    private static function registerFileMapping(Configuration $config, Box $box, CompilerLogger $logger): void
    {
        $fileMapper = $config->getFileMapper();

        self::logMap($fileMapper, $logger);

        $box->registerFileMapping($fileMapper);
    }

    private static function addFiles(Configuration $config, Box $box, CompilerLogger $logger, IO $io): void
    {
        $logger->log(CompilerLogger::QUESTION_MARK_PREFIX, 'Adding binary files');

        $count = count($config->getBinaryFiles());

        $box->addFiles($config->getBinaryFiles(), true);

        $logger->log(
            CompilerLogger::CHEVRON_PREFIX,
            0 === $count
                ? 'No file found'
                : sprintf('%d file(s)', $count),
        );

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            sprintf(
                'Auto-discover files? %s',
                $config->hasAutodiscoveredFiles() ? 'Yes' : 'No',
            ),
        );
        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            sprintf(
                'Exclude dev files? %s',
                $config->excludeDevFiles() ? 'Yes' : 'No',
            ),
        );
        $logger->log(CompilerLogger::QUESTION_MARK_PREFIX, 'Adding files');

        $count = count($config->getFiles());

        self::addFilesWithErrorHandling($config, $box, $io);

        $logger->log(
            CompilerLogger::CHEVRON_PREFIX,
            0 === $count
                ? 'No file found'
                : sprintf('%d file(s)', $count),
        );
    }

    private static function addFilesWithErrorHandling(Configuration $config, Box $box, IO $io): void
    {
        try {
            $box->addFiles($config->getFiles(), false);

            return;
        } catch (CompositeException $ampFailure) {
            // Continue
        }

        // This exception is handled a different way to give me meaningful feedback to the user
        $io->error([
            'An Amp\Parallel error occurred. To diagnostic if it is an Amp error related, you may try again with "--no-parallel".',
            'Reason(s) of the failure:',
            ...FailureCollector::collectReasons($ampFailure),
        ]);

        throw $ampFailure;
    }

    private static function registerMainScript(Configuration $config, Box $box, CompilerLogger $logger): ?string
    {
        if (false === $config->hasMainScript()) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                'No main script path configured',
            );

            return null;
        }

        $main = $config->getMainScriptPath();

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            sprintf(
                'Adding main file: %s',
                $main,
            ),
        );

        $localMain = $box->addFile(
            $main,
            $config->getMainScriptContents(),
        );

        $relativeMain = make_path_relative($main, $config->getBasePath());

        if ($localMain !== $relativeMain) {
            $logger->log(
                CompilerLogger::CHEVRON_PREFIX,
                $localMain,
            );
        }

        return $localMain;
    }

    private static function registerRequirementsChecker(Configuration $config, Box $box, CompilerLogger $logger): bool
    {
        if (false === $config->checkRequirements()) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                'Skip requirements checker',
            );

            return false;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            'Adding requirements checker',
        );

        $checkFiles = RequirementsDumper::dump(
            new DecodedComposerJson($config->getDecodedComposerJsonContents() ?? []),
            new DecodedComposerLock($config->getDecodedComposerLockContents() ?? []),
            $config->getCompressionAlgorithm(),
        );

        foreach ($checkFiles as $fileWithContents) {
            [$file, $contents] = $fileWithContents;

            $box->addFile('.box/'.$file, $contents, true);
        }

        return true;
    }

    private static function registerStub(
        Configuration $config,
        Box $box,
        ?string $main,
        bool $checkRequirements,
        CompilerLogger $logger,
    ): void {
        if ($config->isStubGenerated()) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                'Generating new stub',
            );

            $stub = self::createStub($config, $main, $checkRequirements, $logger);

            $box->getPhar()->setStub($stub);

            return;
        }

        if (null !== ($stub = $config->getStubPath())) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                sprintf(
                    'Using stub file: %s',
                    $stub,
                ),
            );

            $box->registerStub($stub);

            return;
        }

        $aliasWasAdded = $box->getPhar()->setAlias($config->getAlias());

        Assert::true(
            $aliasWasAdded,
            sprintf(
                'The alias "%s" is invalid. See Phar::setAlias() documentation for more information.',
                $config->getAlias(),
            ),
        );

        $box->getPhar()->setDefaultStub($main);

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            'Using default stub',
        );
    }

    private static function configureMetadata(Configuration $config, Box $box, CompilerLogger $logger): void
    {
        if (null !== ($metadata = $config->getMetadata())) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                'Setting metadata',
            );

            if (is_callable($metadata)) {
                $metadata = $metadata();
            }

            $logger->log(
                CompilerLogger::MINUS_PREFIX,
                is_string($metadata) ? $metadata : var_export($metadata, true),
            );

            $box->getPhar()->setMetadata($metadata);
        }
    }

    private static function commit(Box $box, Configuration $config, CompilerLogger $logger): void
    {
        $message = $config->dumpAutoload()
            ? 'Dumping the Composer autoloader'
            : 'Skipping dumping the Composer autoloader';

        $logger->log(CompilerLogger::QUESTION_MARK_PREFIX, $message);

        $composerBin = $config->getComposerBin();
        $excludeDevFiles = $config->excludeDevFiles();
        $io = $logger->getIO();

        $box->endBuffering(
            $config->dumpAutoload()
                ? static fn (SymbolsRegistry $symbolsRegistry, string $prefix) => ComposerOrchestrator::dumpAutoload(
                    $symbolsRegistry,
                    $prefix,
                    $excludeDevFiles,
                    $composerBin,
                    $io,
                )
                : null,
        );
    }

    private static function checkComposerFiles(Box $box, Configuration $config, CompilerLogger $logger): void
    {
        $message = $config->excludeComposerFiles()
            ? 'Removing the Composer dump artefacts'
            : 'Keep the Composer dump artefacts';

        $logger->log(CompilerLogger::QUESTION_MARK_PREFIX, $message);

        if ($config->excludeComposerFiles()) {
            $box->removeComposerArtefacts(
                ComposerConfiguration::retrieveVendorDir(
                    $config->getDecodedComposerJsonContents() ?? [],
                ),
            );
        }
    }

    private static function configureCompressionAlgorithm(
        Configuration $config,
        Box $box,
        bool $dev,
        IO $io,
        CompilerLogger $logger,
    ): void {
        $algorithm = $config->getCompressionAlgorithm();

        if (CompressionAlgorithm::NONE === $algorithm) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                'No compression',
            );

            return;
        }

        if ($dev) {
            $logger->log(CompilerLogger::QUESTION_MARK_PREFIX, 'Dev mode detected: skipping the compression');

            return;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            sprintf(
                'Compressing with the algorithm "<comment>%s</comment>"',
                $algorithm->name,
            ),
        );

        $restoreLimit = bump_open_file_descriptor_limit(count($box), $io);

        try {
            $extension = $box->compress($algorithm);

            if (null !== $extension) {
                $logger->log(
                    CompilerLogger::CHEVRON_PREFIX,
                    sprintf(
                        '<info>Warning: the extension "%s" will now be required to execute the PHAR</info>',
                        $extension,
                    ),
                );
            }
        } catch (RuntimeException $exception) {
            $io->error($exception->getMessage());

            // Continue: the compression failure should not result in completely bailing out the compilation process
        } finally {
            $restoreLimit();
        }
    }

    private static function signPhar(
        Configuration $config,
        Box $box,
        string $path,
        IO $io,
        CompilerLogger $logger,
    ): void {
        // Sign using private key when applicable
        remove($path.'.pubkey');

        $key = $config->getPrivateKeyPath();

        if (null === $key) {
            $box->getPhar()->setSignatureAlgorithm(
                $config->getSigningAlgorithm(),
            );

            return;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            'Signing using a private key',
        );

        $passphrase = $config->getPrivateKeyPassphrase();

        if ($config->promptForPrivateKey()) {
            if (false === $io->isInteractive()) {
                throw new RuntimeException(
                    sprintf(
                        'Accessing to the private key "%s" requires a passphrase but none provided. Either '
                        .'provide one or run this command in interactive mode.',
                        $key,
                    ),
                );
            }

            $question = new Question('Private key passphrase');
            $question->setHidden(false);
            $question->setHiddenFallback(false);

            $passphrase = $io->askQuestion($question);

            $io->writeln('');
        }

        $box->signUsingFile($key, $passphrase);
    }

    private static function correctPermissions(string $path, Configuration $config, CompilerLogger $logger): void
    {
        if (null !== ($chmod = $config->getFileMode())) {
            $logger->log(
                CompilerLogger::QUESTION_MARK_PREFIX,
                sprintf(
                    'Setting file permissions to <comment>%s</comment>',
                    '0'.decoct($chmod),
                ),
            );

            chmod($path, $chmod);
        }
    }

    private static function createStub(
        Configuration $config,
        ?string $main,
        bool $checkRequirements,
        CompilerLogger $logger,
    ): string {
        $shebang = $config->getShebang();
        $bannerPath = $config->getStubBannerPath();
        $bannerContents = $config->getStubBannerContents();

        if (null !== $shebang) {
            $logger->log(
                CompilerLogger::MINUS_PREFIX,
                sprintf(
                    'Using shebang line: %s',
                    $shebang,
                ),
            );
        } else {
            $logger->log(
                CompilerLogger::MINUS_PREFIX,
                'No shebang line',
            );
        }

        if (null !== $bannerPath) {
            $logger->log(
                CompilerLogger::MINUS_PREFIX,
                sprintf(
                    'Using custom banner from file: %s',
                    $bannerPath,
                ),
            );
        } elseif (null !== $bannerContents) {
            $logger->log(
                CompilerLogger::MINUS_PREFIX,
                'Using banner:',
            );

            $bannerLines = explode("\n", $bannerContents);

            foreach ($bannerLines as $bannerLine) {
                $logger->log(
                    CompilerLogger::CHEVRON_PREFIX,
                    $bannerLine,
                );
            }
        }

        return StubGenerator::generateStub(
            $config->getAlias(),
            $bannerContents,
            $main,
            $config->isInterceptFileFuncs(),
            $shebang,
            $checkRequirements,
        );
    }

    private static function logMap(MapFile $fileMapper, CompilerLogger $logger): void
    {
        $map = $fileMapper->getMap();

        if (0 === count($map)) {
            return;
        }

        $logger->log(
            CompilerLogger::QUESTION_MARK_PREFIX,
            'Mapping paths',
        );

        foreach ($map as $item) {
            foreach ($item as $match => $replace) {
                if ('' === $match) {
                    $match = '(all)';
                    $replace .= '/';
                }

                $logger->log(
                    CompilerLogger::MINUS_PREFIX,
                    sprintf(
                        '%s <info>></info> %s',
                        $match,
                        $replace,
                    ),
                );
            }
        }
    }

    private static function logEndBuilding(
        Configuration $config,
        CompilerLogger $logger,
        IO $io,
        Box $box,
        string $path,
        float $startTime,
    ): void {
        $logger->log(
            CompilerLogger::STAR_PREFIX,
            'Done.',
        );
        $io->newLine();

        MessageRenderer::render($io, $config->getRecommendations(), $config->getWarnings());

        $io->comment(
            sprintf(
                'PHAR: %s (%s)',
                $box->count() > 1 ? $box->count().' files' : $box->count().' file',
                format_size(
                    filesize($path),
                ),
            )
            .PHP_EOL
            .'You can inspect the generated PHAR with the "<comment>info</comment>" command.',
        );

        $io->comment(
            sprintf(
                '<info>Memory usage: %s (peak: %s), time: %s<info>',
                format_size(memory_get_usage()),
                format_size(memory_get_peak_usage()),
                format_time(microtime(true) - $startTime),
            ),
        );
    }

    private function generateDockerFile(IO $io): int
    {
        $input = new StringInput('');
        $input->setInteractive(false);

        return $this->getDockerCommand()->execute(
            new IO($input, $io->getOutput()),
        );
    }

    private function getDockerCommand(): Command
    {
        return $this->getCommandRegistry()->findCommand(GenerateDockerFile::NAME);
    }
}
