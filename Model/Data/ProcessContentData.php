<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\Data;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\Data\ProcessContentDataInterface;

/**
 * Process content data object implementation
 *
 * Encapsulates all data needed for content processing following SOLID principles
 */
class ProcessContentData implements ProcessContentDataInterface
{
    private string $content = '';
    private string $filePath = '';
    private string $mergedFilePath = '';
    private bool $isFirstFile = false;
    private bool $isLastFile = false;
    private int $currentFileIndex = 0;
    private int $totalFiles = 0;

    /**
     * @inheritDoc
     */
    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @inheritDoc
     */
    public function setContent(string $content): ProcessContentDataInterface
    {
        $this->content = $content;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * @inheritDoc
     */
    public function setFilePath(string $filePath): ProcessContentDataInterface
    {
        $this->filePath = $filePath;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getMergedFilePath(): string
    {
        return $this->mergedFilePath;
    }

    /**
     * @inheritDoc
     */
    public function setMergedFilePath(string $mergedFilePath): ProcessContentDataInterface
    {
        $this->mergedFilePath = $mergedFilePath;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isFirstFile(): bool
    {
        return $this->isFirstFile;
    }

    /**
     * @inheritDoc
     */
    public function setIsFirstFile(bool $isFirstFile): ProcessContentDataInterface
    {
        $this->isFirstFile = $isFirstFile;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function isLastFile(): bool
    {
        return $this->isLastFile;
    }

    /**
     * @inheritDoc
     */
    public function setIsLastFile(bool $isLastFile): ProcessContentDataInterface
    {
        $this->isLastFile = $isLastFile;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCurrentFileIndex(): int
    {
        return $this->currentFileIndex;
    }

    /**
     * @inheritDoc
     */
    public function setCurrentFileIndex(int $currentFileIndex): ProcessContentDataInterface
    {
        $this->currentFileIndex = $currentFileIndex;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTotalFiles(): int
    {
        return $this->totalFiles;
    }

    /**
     * @inheritDoc
     */
    public function setTotalFiles(int $totalFiles): ProcessContentDataInterface
    {
        $this->totalFiles = $totalFiles;
        return $this;
    }
}