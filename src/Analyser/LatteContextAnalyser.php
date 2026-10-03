<?php

declare(strict_types=1);

namespace Efabrica\PHPStanLatte\Analyser;

use Composer\InstalledVersions;
use Efabrica\PHPStanLatte\LatteContext\Collector\AbstractLatteContextCollector;
use Efabrica\PHPStanLatte\Temp\TempDirResolver;
use Exception;
use InvalidArgumentException;
use Nette\Utils\FileSystem;
use Nette\Utils\Json;
use PhpParser\Node;
use PhpParser\Node\Stmt\TraitUse;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\File\FileHelper;
use PHPStan\Parser\Parser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\RuleErrorBuilder;
use RuntimeException;
use Throwable;
use function basename;
use function class_exists;
use function count;
use function file_exists;
use function get_class;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_encode;
use function md5;
use function microtime;
use function sha1;
use function sprintf;
use const JSON_OBJECT_AS_ARRAY;
use const PHP_VERSION_ID;

final class LatteContextAnalyser
{
    private ScopeFactory $scopeFactory;

    private NodeScopeResolver $nodeScopeResolver;

    private Parser $parser;

    private TypeStringResolver $typeStringResolver;

    private ReflectionProvider $reflectionProvider;

    private FileHelper $fileHelper;

    private LatteContextCollectorRegistry $collectorRegistry;

    private string $tmpDir;

    private string $installedVersionsCacheKey;

    /** @var array<string, string> */
    private array $fileHashes = [];

    /** @var array<string, LatteContextData> */
    private array $failedFileResults = [];

    private ?LatteContextProfiler $profiler;

    /**
     * @param AbstractLatteContextCollector[] $collectors
     */
    public function __construct(
        ScopeFactory $scopeFactory,
        NodeScopeResolver $nodeScopeResolver,
        ReflectionProvider $reflectionProvider,
        FileHelper $fileHelper,
        Parser $parser,
        TypeStringResolver $typeStringResolver,
        TempDirResolver $tempDirResolver,
        array $collectors,
        bool $debugMode = false
    ) {
        $this->scopeFactory = $scopeFactory;
        $this->nodeScopeResolver = clone $nodeScopeResolver;
        $this->reflectionProvider = $reflectionProvider;
        $this->fileHelper = $fileHelper;
        // $this->nodeScopeResolver->setAnalysedFiles(null); TODO when changes in PHPStan are merged
        $this->parser = $parser;
        $this->typeStringResolver = $typeStringResolver;
        $this->collectorRegistry = new LatteContextCollectorRegistry($collectors);
        $this->tmpDir = $tempDirResolver->resolveCollectorDir();
        $this->installedVersionsCacheKey = class_exists(InstalledVersions::class)
            ? (string)json_encode(InstalledVersions::getAllRawData())
            : '';
        $profileSetting = getenv('PHPSTAN_LATTE_PROFILE');
        $this->profiler = $profileSetting !== false && $profileSetting !== '' && $profileSetting !== '0'
            ? new LatteContextProfiler()
            : null;
        if (file_exists($this->tmpDir) && $debugMode) {
            FileSystem::delete($this->tmpDir);
        }
    }

    /**
     * @param string[] $files
     */
    public function analyseFiles(array $files): LatteContextData
    {
        if ($this->profiler === null) {
            return $this->analyseFilesInternal($files);
        }

        $this->profiler->enter();
        $startedAt = microtime(true);
        try {
            return $this->analyseFilesInternal($files);
        } finally {
            $this->profiler->recordDuration('analyseFiles', $startedAt);
            $this->profiler->leave();
        }
    }

    /**
     * @param string[] $files
     */
    private function analyseFilesInternal(array $files): LatteContextData
    {
        $errors = [];
        $collectedData = [];
        $processedFiles = [];
        $counter = 0;

        $this->nodeScopeResolver->setAnalysedFiles($files); // TODO when changes in PHPStan are merged

        do {
            if ($counter++ > 100) {
                throw new RuntimeException('Infinite loop detected in LatteContextAnalyser.');
            }
            $relatedFiles = [];
            foreach ($files as $file) {
                $fileResult = $this->failedFileResults[$file] ?? $this->loadLatteContextDataFromCache($file);
                if ($fileResult === null) {
                    if ($this->profiler !== null) {
                        $this->profiler->increment('diskCacheMiss');
                    }
                    $analysisStartedAt = $this->profiler === null ? 0.0 : microtime(true);
                    $fileResult = $this->analyseFile($file);
                    if ($this->profiler !== null) {
                        $this->profiler->recordDuration('analyseFile', $analysisStartedAt);
                    }
                    if ($fileResult->getErrors() === []) {
                        $cacheWriteStartedAt = $this->profiler === null ? 0.0 : microtime(true);
                        $this->saveLatteContextDataToCache($file, $fileResult);
                        if ($this->profiler !== null) {
                            $this->profiler->recordDuration('cacheWrite', $cacheWriteStartedAt);
                        }
                    } else {
                        $this->failedFileResults[$file] = $fileResult;
                    }
                } elseif ($fileResult->getErrors() === [] && $this->profiler !== null) {
                    $this->profiler->increment('diskCacheHit');
                }
                foreach ($fileResult->getErrors() as $error) {
                    $errors[] = $error;
                }
                if ($fileResult->getAllCollectedData() !== []) {
                    foreach ($fileResult->getAllCollectedData() as $collectedItem) {
                        $collectedData[] = $collectedItem;
                    }
                    foreach ($fileResult->getProcessedFiles() as $processedFile) {
                        $processedFiles[$processedFile] = true;
                    }
                    foreach ($fileResult->getRelatedFiles() as $relatedFile) {
                        $relatedFiles[$relatedFile] = true;
                    }
                }
            }
            $files = [];
            foreach ($relatedFiles as $relatedFile => $_) {
                if (!isset($processedFiles[$relatedFile])) {
                    $files[] = $relatedFile;
                }
            }
        } while (count($files) > 0);

        return new LatteContextData($collectedData, $errors);
    }

    public function analyseFile(string $file): LatteContextData
    {
        $fileErrors = [];
        $fileCollectedData = [];
        if (is_file($file)) {
            try {
                $parserNodes = $this->parser->parseFile($file);
                $nodeCallback = function (Node $node, Scope $scope) use ($file, &$fileErrors, &$fileCollectedData): void {
                    // TODO when changes in PHPStan are merged
                    if ($node instanceof TraitUse) {
                        $this->nodeScopeResolver->setAnalysedFiles($this->getTraitFiles($node));
                    }
                    $collectors = $this->collectorRegistry->getCollectorsForNode($node);
                    foreach ($collectors as $collector) {
                        try {
                            $collectedData = $collector->collectData($node, $scope);
                        } catch (Throwable $e) {
                            $fileErrors[] = RuleErrorBuilder::message(get_class($collector) . ' error: ' . $e->getMessage())
                                ->identifier('latte.collectorError')
                                ->file($file)
                                ->line($node->getLine())
                                ->build();
                            continue;
                        }
                        if ($collectedData === null || $collectedData === []) {
                            continue;
                        }
                        foreach ($collectedData as $collectedItem) {
                            $fileCollectedData[] = $collectedItem;
                        }
                    }
                };
                $scope = $this->scopeFactory->create(ScopeContext::create($file));
                $this->nodeScopeResolver->processNodes($parserNodes, $scope, $nodeCallback);
            } catch (Throwable $e) {
                $fileErrors[] = RuleErrorBuilder::message('LatteContextAnalyser error: ' . $e->getMessage())
                    ->identifier('latte.failed')
                    ->file($file)
                    ->build();
            }
        } elseif (is_dir($file)) {
            $fileErrors[] = RuleErrorBuilder::message(sprintf('File %s is a directory.', $file))
                ->identifier('latte.fileError')
                ->file($file)
                ->build();
        } else {
            $fileErrors[] = RuleErrorBuilder::message(sprintf('File %s does not exist.', $file))
                ->identifier('latte.fileError')
                ->file($file)
                ->build();
        }
        return new LatteContextData($fileCollectedData, $fileErrors);
    }

    /**
     * TODO when changes in PHPStan are merged
     * @return string[]
     */
    private function getTraitFiles(TraitUse $node): array
    {
        $files = [];
        foreach ($node->traits as $trait) {
            $traitName = (string)$trait;
            if (!$this->reflectionProvider->hasClass($traitName)) {
                continue;
            }
            $traitReflection = $this->reflectionProvider->getClass($traitName);
            $traitFileName = $traitReflection->getFileName();
            if ($traitFileName !== null) {
                $files[] = $this->fileHelper->normalizePath($traitFileName);
            }
        }
        return $files;
    }

    /**
     * @param AbstractLatteContextCollector[] $collectors
     */
    public function withCollectors(array $collectors): self
    {
        $clone = clone $this;
        $clone->collectorRegistry = new LatteContextCollectorRegistry($collectors);
        return $clone;
    }

    private function cacheFilename(string $file): string
    {
        $cacheKey = md5($file . PHP_VERSION_ID . $this->installedVersionsCacheKey);
        return $this->tmpDir . basename($file) . '.' . $cacheKey . '.json';
    }

    private function saveLatteContextDataToCache(string $file, LatteContextData $fileResult): void
    {
        if (!is_dir($this->tmpDir)) {
            Filesystem::createDir($this->tmpDir, 0777);
        }

        $cacheFile = $this->cacheFilename($file);

        try {
            $data = $fileResult->jsonSerialize();
        } catch (InvalidArgumentException $e) {
            // Cannot serialize data, skip caching
            if (is_file($cacheFile)) {
                FileSystem::delete($cacheFile);
            }
            return;
        }

        $cacheData = [
            'file' => $file,
            'fileHash' => $this->getFileHash($file),
            'data' => $data,
        ];
        foreach ($fileResult->getRelatedFiles() as $relatedFile) {
            $cacheData['dependencies'][] = [
                'file' => $relatedFile,
                'fileHash' => $this->getFileHash($relatedFile),
            ];
        }
        Filesystem::write($cacheFile, Json::encode($cacheData));
    }

    private function loadLatteContextDataFromCache(string $file): ?LatteContextData
    {
        $analysedFile = $file;
        $cacheFile = $this->cacheFilename($file);
        if (!is_file($cacheFile)) {
            return $this->recordCacheMiss($analysedFile, $cacheFile, 'not-found');
        }

        try {
            $cacheData = Json::decode(Filesystem::read($cacheFile), JSON_OBJECT_AS_ARRAY);
        } catch (Exception $e) {
            FileSystem::delete($cacheFile);
            return $this->recordCacheMiss($analysedFile, $cacheFile, 'invalid-json');
        }

        if (!is_array($cacheData) || !isset($cacheData['file'], $cacheData['fileHash'], $cacheData['data'])) {
            FileSystem::delete($cacheFile);
            return $this->recordCacheMiss($analysedFile, $cacheFile, 'invalid-structure');
        }

        $file = $cacheData['file'];
        $fileHash = $cacheData['fileHash'];

        if (!is_string($file) || !is_string($fileHash)) {
            FileSystem::delete($cacheFile);
            return $this->recordCacheMiss($analysedFile, $cacheFile, 'invalid-file-hash');
        }

        // Check if the file has changed since the cache was created
        if ($this->getFileHash($file) !== $fileHash) {
            return $this->recordCacheMiss($analysedFile, $cacheFile, 'file-hash-changed');
        }

        if (isset($cacheData['dependencies']) && is_array($cacheData['dependencies'])) {
            foreach ($cacheData['dependencies'] as $dependency) {
                if (!is_array($dependency) || !isset($dependency['file'], $dependency['fileHash'])) {
                    return $this->recordCacheMiss($analysedFile, $cacheFile, 'invalid-dependency');
                }
                $dependencyFile = $dependency['file'];
                $dependencyFileHash = $dependency['fileHash'];
                if (!is_string($dependencyFile) || !is_string($dependencyFileHash)) {
                    return $this->recordCacheMiss($analysedFile, $cacheFile, 'invalid-dependency-hash');
                }
                if (!is_file($dependencyFile)) {
                    return $this->recordCacheMiss($analysedFile, $cacheFile, 'dependency-not-found');
                }
                // Check if the dependency file has changed since the cache was created
                if ($this->getFileHash($dependencyFile) !== $dependencyFileHash) {
                    return $this->recordCacheMiss($analysedFile, $cacheFile, 'dependency-hash-changed');
                }
            }
        }

        $data = $cacheData['data'];
        if (!is_array($data)) {
            FileSystem::delete($cacheFile);
            return $this->recordCacheMiss($analysedFile, $cacheFile, 'invalid-data');
        }

        try {
            return LatteContextData::fromJson($data, $this->typeStringResolver);
        } catch (Exception $e) {
            FileSystem::delete($cacheFile);
            return $this->recordCacheMiss($analysedFile, $cacheFile, 'invalid-collected-data');
        }
    }

    private function recordCacheMiss(string $file, string $cacheFile, string $reason): null
    {
        if ($this->profiler !== null) {
            $this->profiler->recordCacheMiss($file, $cacheFile, $reason);
        }

        return null;
    }

    private function getFileHash(string $file): string
    {
        if (!isset($this->fileHashes[$file])) {
            $this->fileHashes[$file] = sha1(FileSystem::read($file));
        }

        return $this->fileHashes[$file];
    }
}
