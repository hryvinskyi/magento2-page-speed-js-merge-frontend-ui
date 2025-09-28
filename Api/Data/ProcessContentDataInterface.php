<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\Data;

/**
 * Interface for process content data object
 *
 * Encapsulates all data needed for content processing
 */
interface ProcessContentDataInterface
{
    /**
     * Get file content to process
     *
     * @return string
     */
    public function getContent(): string;

    /**
     * Set file content to process
     *
     * @param string $content
     * @return $this
     */
    public function setContent(string $content): self;

    /**
     * Get original file path
     *
     * @return string
     */
    public function getFilePath(): string;

    /**
     * Set original file path
     *
     * @param string $filePath
     * @return $this
     */
    public function setFilePath(string $filePath): self;

    /**
     * Get merged file path
     *
     * @return string
     */
    public function getMergedFilePath(): string;

    /**
     * Set merged file path
     *
     * @param string $mergedFilePath
     * @return $this
     */
    public function setMergedFilePath(string $mergedFilePath): self;

    /**
     * Check if this is the first file in the merge group
     *
     * @return bool
     */
    public function isFirstFile(): bool;

    /**
     * Set whether this is the first file in the merge group
     *
     * @param bool $isFirstFile
     * @return $this
     */
    public function setIsFirstFile(bool $isFirstFile): self;

    /**
     * Check if this is the last file in the merge group
     *
     * @return bool
     */
    public function isLastFile(): bool;

    /**
     * Set whether this is the last file in the merge group
     *
     * @param bool $isLastFile
     * @return $this
     */
    public function setIsLastFile(bool $isLastFile): self;

    /**
     * Get current file index in the merge group
     *
     * @return int
     */
    public function getCurrentFileIndex(): int;

    /**
     * Set current file index in the merge group
     *
     * @param int $currentFileIndex
     * @return $this
     */
    public function setCurrentFileIndex(int $currentFileIndex): self;

    /**
     * Get total number of files in the merge group
     *
     * @return int
     */
    public function getTotalFiles(): int;

    /**
     * Set total number of files in the merge group
     *
     * @param int $totalFiles
     * @return $this
     */
    public function setTotalFiles(int $totalFiles): self;
}