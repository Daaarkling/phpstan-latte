<?php

declare (strict_types=1);

namespace Efabrica\PHPStanLatte\Analyser;

use Efabrica\PHPStanLatte\LatteContext\CollectedData\CollectedError;
use Efabrica\PHPStanLatte\LatteContext\CollectedData\CollectedLatteContextObject;
use Efabrica\PHPStanLatte\LatteContext\CollectedData\CollectedRelatedFiles;
use InvalidArgumentException;
use JsonSerializable;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;
use ReturnTypeWillChange;
use function array_unique;
use function array_values;
use function class_exists;
use function get_class;
use function is_array;

final class LatteContextData implements JsonSerializable
{
    /** @var list<IdentifierRuleError> */
    private array $errors;

    /** @var array<CollectedLatteContextObject> */
    private array $collectedData;

    /** @var array<class-string, CollectedLatteContextObject[]> */
    private array $collectedDataByType = [];

    private bool $collectedDataByTypeIndexComplete;

    /**
     * @param array<CollectedLatteContextObject> $collectedData
     * @param list<IdentifierRuleError> $errors
     */
    public function __construct(array $collectedData, array $errors, bool $lazyCollectedDataIndex = false)
    {
        $this->collectedData = $collectedData;
        $this->collectedDataByTypeIndexComplete = !$lazyCollectedDataIndex;
        $isList = true;
        $index = 0;
        foreach ($collectedData as $key => $collectedItem) {
            if (!$lazyCollectedDataIndex) {
                $this->collectedDataByType[get_class($collectedItem)][] = $collectedItem;
            }
            if ($key !== $index) {
                $isList = false;
            }
            $index++;
        }
        if (!$isList) {
            $this->collectedData = array_values($collectedData);
        }
        $this->errors = $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function getCollectedErrors(): array
    {
        $errors = [];
        foreach ($this->getCollectedData(CollectedError::class) as $collectedError) {
            $errors[] = RuleErrorBuilder::message($collectedError->getMessage())
                ->identifier('latte.error')
                ->file($collectedError->getFile())
                ->line($collectedError->getLine() ?? -1)
                ->build();
        }
        return $errors;
    }

    /**
     * @return array<CollectedLatteContextObject>
     */
    public function getAllCollectedData(): array
    {
        return $this->collectedData;
    }

    /**
     * @template T of CollectedLatteContextObject
     * @param class-string<T> $type
     * @return T[]
     */
    public function getCollectedData(string $type): array
    {
        if (!$this->collectedDataByTypeIndexComplete && !isset($this->collectedDataByType[$type])) {
            $collectedData = [];
            foreach ($this->collectedData as $collectedItem) {
                if (get_class($collectedItem) === $type) {
                    $collectedData[] = $collectedItem;
                }
            }
            $this->collectedDataByType[$type] = $collectedData;
        }

        /** @var T[] */
        return $this->collectedDataByType[$type] ?? [];
    }

    /**
     * @return string[]
     */
    public function getProcessedFiles(): array
    {
        $processedFiles = [];
        foreach ($this->getCollectedData(CollectedRelatedFiles::class) as $collectedRelatedFile) {
            $processedFiles[] = $collectedRelatedFile->getProcessedFile();
        }
        return array_unique($processedFiles);
    }

    /**
     * @return string[]
     */
    public function getRelatedFiles(): array
    {
        $relatedFiles = [];
        foreach ($this->getCollectedData(CollectedRelatedFiles::class) as $collectedRelatedFile) {
            foreach ($collectedRelatedFile->getRelatedFiles() as $relatedFile) {
                $relatedFiles[] = $relatedFile;
            }
        }
        return array_unique($relatedFiles);
    }

    /**
     * @return array<string, mixed>
     */
    #[ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        $data = [];
        foreach ($this->collectedData as $collectedItem) {
            $data[] = [
                'class' => get_class($collectedItem),
                'data' => $collectedItem->jsonSerialize(),
            ];
        }
        return [
            'items' => $data,
        ];
    }

    /**
     * @param array{items: array{class: class-string<CollectedLatteContextObject>, data: mixed}[]} $data
     */
    public static function fromJson(
        array $data,
        TypeStringResolver $typeStringResolver,
        bool $lazyCollectedDataIndex = false,
    ): self {
        $collectedData = [];
        foreach ($data['items'] as $item) {
            $class = $item['class'];
            if (!class_exists($class) || !is_array($item['data'])) {
                throw new InvalidArgumentException("Cannot deserialize collected data, class $class not found or data is not array");
            }
            $collectedData[] = $class::fromJson($item['data'], $typeStringResolver);
        }
        return new self($collectedData, [], $lazyCollectedDataIndex);
    }
}
