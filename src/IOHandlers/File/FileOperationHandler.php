<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\File;

use Generator;
use Hibla\EventLoop\EventLoop;
use Hibla\EventLoop\ValueObjects\FileOperation;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class FileOperationHandler
{
    private const CHUNK_SIZE = 8192;

    /**
     * @param callable|null $onOperationComplete Callback to notify when operation completes: fn(string $operationId): void
     */
    public function __construct(
        private $onOperationComplete = null
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function createOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): FileOperation {
        return new FileOperation($type, $path, $data, $callback, $options);
    }

    public function executeOperation(FileOperation $operation): bool
    {
        if ($operation->isCancelled()) {
            $this->completeOperation($operation);

            return false;
        }

        if ($this->shouldUseStreaming($operation)) {
            $this->executeStreamingOperation($operation);
        } else {
            $this->executeOperationSync($operation);
        }

        return true;
    }

    private function completeOperation(FileOperation $operation): void
    {
        if ($this->onOperationComplete !== null) {
            ($this->onOperationComplete)($operation->getId());
        }
    }

    private function shouldUseStreaming(FileOperation $operation): bool
    {
        $streamableOperations = ['read', 'write', 'copy'];

        if (! \in_array($operation->getType(), $streamableOperations, true)) {
            return false;
        }

        $options = $operation->getOptions();
        $useStreaming = $options['use_streaming'] ?? false;

        return is_scalar($useStreaming) && (bool) $useStreaming;
    }

    private function handleWriteFromGenerator(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            $this->completeOperation($operation);

            return;
        }

        $path = $operation->getPath();
        $generator = $operation->getData();
        $options = $operation->getOptions();

        if (! $generator instanceof Generator) {
            throw new InvalidArgumentException('Data must be a Generator for write_generator operation.');
        }

        $createDirs = $options['create_directories'] ?? false;
        if (\is_scalar($createDirs) && (bool) $createDirs) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                if (! mkdir($dir, 0755, true)) {
                    $operation->executeCallback("Failed to create directory: $dir");
                    $this->completeOperation($operation);

                    return;
                }
            }
        }

        $mode = 'wb';
        $flagsRaw = $options['flags'] ?? 0;
        if (is_numeric($flagsRaw) && (((int) $flagsRaw & FILE_APPEND) !== 0)) {
            $mode = 'ab';
        }

        $stream = @fopen($path, $mode);
        if ($stream === false) {
            $operation->executeCallback("Failed to open file for writing: $path");
            $this->completeOperation($operation);

            return;
        }

        stream_set_blocking($stream, false);
        stream_set_write_buffer($stream, 8192);

        $bytesWritten = 0;
        $currentChunk = '';
        $chunkOffset = 0;

        $this->scheduleGeneratorWrite(
            $operation,
            $stream,
            $generator,
            $bytesWritten,
            $currentChunk,
            $chunkOffset
        );
    }

    /**
     * @param resource $stream
     * @param-out string $currentChunk
     */
    private function scheduleGeneratorWrite(
        FileOperation $operation,
        $stream,
        Generator $generator,
        int &$bytesWritten,
        string &$currentChunk,
        int &$chunkOffset
    ): void {
        // Check for cancellation at the start of each iteration
        if ($operation->isCancelled()) {
            fclose($stream);
            $this->completeOperation($operation);

            return;
        }

        $chunksPerTick = 100;
        $chunksProcessed = 0;

        while ($chunksProcessed < $chunksPerTick) {
            // Check cancellation in the loop
            // @phpstan-ignore-next-line if.alwaysFalse
            if ($operation->isCancelled()) {
                fclose($stream);
                $this->completeOperation($operation);

                return;
            }

            if ($chunkOffset >= strlen($currentChunk)) {
                if (! $generator->valid()) {
                    fclose($stream);
                    $operation->executeCallback(null, $bytesWritten);
                    $this->completeOperation($operation);

                    return;
                }

                $chunkValue = $generator->current();

                if (! is_string($chunkValue)) {
                    fclose($stream);
                    $operation->executeCallback('Generator must yield string chunks');
                    $this->completeOperation($operation);

                    return;
                }

                $currentChunk = $chunkValue;
                $chunkOffset = 0;
                $generator->next();
            }

            $remainingData = substr($currentChunk, $chunkOffset);
            $written = @fwrite($stream, $remainingData);

            if ($written === false) {
                fclose($stream);
                $operation->executeCallback('Failed to write to file');
                $this->completeOperation($operation);

                return;
            }

            if ($written === 0) {
                $watcherId = EventLoop::getInstance()->addStreamWatcher(
                    $stream,
                    function () use ($operation, $stream, $generator, &$bytesWritten, &$currentChunk, &$chunkOffset, &$watcherId) {
                        if ($watcherId !== null) {
                            EventLoop::getInstance()->removeStreamWatcher($watcherId);
                        }
                        $this->scheduleGeneratorWrite(
                            $operation,
                            $stream,
                            $generator,
                            $bytesWritten,
                            $currentChunk,
                            $chunkOffset
                        );
                    },
                    StreamWatcher::TYPE_WRITE
                );

                return;
            }

            $bytesWritten += $written;
            $chunkOffset += $written;
            $chunksProcessed++;
        }

        EventLoop::getInstance()->nextTick(function () use ($operation, $stream, $generator, &$bytesWritten, &$currentChunk, &$chunkOffset) {
            $this->scheduleGeneratorWrite(
                $operation,
                $stream,
                $generator,
                $bytesWritten,
                $currentChunk,
                $chunkOffset
            );
        });
    }

    /**
     * Handle reading a file as a generator for memory-efficient streaming.
     */
    private function handleReadAsGenerator(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            $this->completeOperation($operation);

            return;
        }

        $path = $operation->getPath();
        $options = $operation->getOptions();

        if (! file_exists($path)) {
            $operation->executeCallback("File does not exist: $path");
            $this->completeOperation($operation);

            return;
        }

        if (! is_readable($path)) {
            $operation->executeCallback("File is not readable: $path");
            $this->completeOperation($operation);

            return;
        }

        $readMode = $options['read_mode'] ?? 'chunks';

        if ($readMode === 'lines') {
            $generator = $this->createLineGenerator($path, $options, $operation);
        } else {
            $generator = $this->createChunkGenerator($path, $options, $operation);
        }

        $operation->executeCallback(null, $generator);
        $this->completeOperation($operation);
    }

    /**
     * Create a generator that yields file chunks.
     *
     * @param  array<string,mixed>  $options  ['chunk_size'=>int, 'offset'=>int, 'length'=>int].
     */
    private function createChunkGenerator(string $path, array $options, FileOperation $operation): Generator
    {
        $chunkSizeRaw = $options['chunk_size'] ?? self::CHUNK_SIZE;
        $chunkSize = is_numeric($chunkSizeRaw) ? max(1, (int) $chunkSizeRaw) : self::CHUNK_SIZE;

        $offsetRaw = $options['offset'] ?? 0;
        $offset = is_numeric($offsetRaw) ? (int) $offsetRaw : 0;

        $lengthRaw = $options['length'] ?? null;
        $maxLength = is_numeric($lengthRaw) ? max(0, (int) $lengthRaw) : null;

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Failed to open file: $path");
        }

        if ($offset > 0) {
            fseek($stream, $offset);
        }

        $bytesRead = 0;
        $chunkCounter = 0;

        try {
            while (! feof($stream)) {
                if ($operation->isCancelled()) {
                    break;
                }

                $readSize = $chunkSize;
                if ($maxLength !== null) {
                    $remaining = $maxLength - $bytesRead;
                    if ($remaining <= 0) {
                        break;
                    }
                    $readSize = min($chunkSize, $remaining);
                }

                $chunk = fread($stream, $readSize);
                if ($chunk === false) {
                    throw new RuntimeException("Failed to read from file: $path");
                }

                if ($chunk === '') {
                    break;
                }

                $bytesRead += strlen($chunk);
                yield $chunk;

                $chunkCounter++;
                if ($chunkCounter % 10 === 0) {
                    EventLoop::getInstance()->addTimer(0, function () {});
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * Create a generator that yields file lines.
     *
     * @param  array<string,mixed>  $options
     */
    /**
     * Create a generator that yields file lines.
     *
     * @param  array<string,mixed>  $options
     */
    private function createLineGenerator(string $path, array $options, FileOperation $operation): Generator
    {
        $chunkSizeRaw = $options['chunk_size'] ?? self::CHUNK_SIZE;
        $chunkSize = is_numeric($chunkSizeRaw) ? max(1, (int) $chunkSizeRaw) : self::CHUNK_SIZE;

        $trim = $options['trim'] ?? false;
        $trim = \is_scalar($trim) && (bool) $trim;

        $skipEmpty = $options['skip_empty'] ?? false;
        $skipEmpty = \is_scalar($skipEmpty) && (bool) $skipEmpty;

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException("Failed to open file: $path");
        }

        $buffer = '';
        $lineCount = 0;

        try {
            while (! feof($stream)) {
                if ($operation->isCancelled()) {
                    break;
                }

                $chunk = fread($stream, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException("Failed to read from file: $path");
                }

                $buffer .= $chunk;

                // Process complete lines in buffer
                // Handle all line ending types: \r\n, \n, \r
                while (preg_match('/^(.*?)(\r\n|\n|\r)/', $buffer, $matches)) {
                    $line = $matches[1];  // Line content without line ending
                    $buffer = substr($buffer, strlen($matches[0]));  // Remove processed part

                    if ($trim) {
                        $line = trim($line);
                    }

                    if ($skipEmpty && $line === '') {
                        continue;
                    }

                    yield $line;
                    $lineCount++;

                    // Yield control periodically (every 100 lines)
                    if ($lineCount % 100 === 0) {
                        EventLoop::getInstance()->addTimer(0, function () {});
                    }
                }
            }

            // Yield any remaining content in buffer (last line without newline)
            if ($buffer !== '') {
                if ($trim) {
                    $buffer = trim($buffer);
                }

                if (! $skipEmpty || $buffer !== '') {
                    yield $buffer;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function executeStreamingOperation(FileOperation $operation): void
    {
        switch ($operation->getType()) {
            case 'read':
                $this->handleStreamingRead($operation);

                break;
            case 'write':
                $this->handleStreamingWrite($operation);

                break;
            case 'copy':
                $this->handleStreamingCopy($operation);

                break;
            default:
                $this->executeOperationSync($operation);
        }
    }

    private function handleStreamingRead(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            $this->completeOperation($operation);

            return;
        }

        $path = $operation->getPath();
        $options = $operation->getOptions();

        if (! file_exists($path)) {
            $operation->executeCallback("File does not exist: $path");
            $this->completeOperation($operation);

            return;
        }

        if (! is_readable($path)) {
            $operation->executeCallback("File is not readable: $path");
            $this->completeOperation($operation);

            return;
        }

        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            $operation->executeCallback("Failed to open file: $path");
            $this->completeOperation($operation);

            return;
        }

        // Handle offset
        $offsetRaw = $options['offset'] ?? 0;
        $offset = is_numeric($offsetRaw) ? (int) $offsetRaw : 0;
        if ($offset > 0) {
            fseek($stream, $offset);
        }

        $lengthRaw = $options['length'] ?? null;
        $length = is_numeric($lengthRaw) ? max(0, (int) $lengthRaw) : null;

        $content = '';
        $bytesRead = 0;

        $this->scheduleStreamRead($operation, $stream, $content, $bytesRead, $length);
    }

    /**
     * @param  resource  $stream
     */
    private function scheduleStreamRead(FileOperation $operation, $stream, string &$content, int &$bytesRead, ?int $maxLength): void
    {
        // Check for cancellation at the start of each iteration
        if ($operation->isCancelled()) {
            fclose($stream);
            $this->completeOperation($operation);

            return;
        }

        if (feof($stream) || ($maxLength !== null && $bytesRead >= $maxLength)) {
            fclose($stream);
            $operation->executeCallback(null, $content);
            $this->completeOperation($operation);

            return;
        }

        $chunkSize = self::CHUNK_SIZE;
        if ($maxLength !== null) {
            $chunkSize = min($chunkSize, $maxLength - $bytesRead);
        }

        if ($chunkSize <= 0) {
            fclose($stream);
            $operation->executeCallback(null, $content);
            $this->completeOperation($operation);

            return;
        }

        $chunk = fread($stream, $chunkSize);
        if ($chunk === false) {
            fclose($stream);
            $operation->executeCallback('Failed to read from file');
            $this->completeOperation($operation);

            return;
        }

        $content .= $chunk;
        $bytesRead += strlen($chunk);

        EventLoop::getInstance()->addTimer(0, function () use ($operation, $stream, &$content, &$bytesRead, $maxLength) {
            $this->scheduleStreamRead($operation, $stream, $content, $bytesRead, $maxLength);
        });
    }

    private function handleStreamingWrite(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            $this->completeOperation($operation);

            return;
        }

        $path = $operation->getPath();
        $data = $operation->getData();
        if (! is_scalar($data)) {
            $operation->executeCallback('Invalid data provided for writing. Must be a scalar value.');
            $this->completeOperation($operation);

            return;
        }
        $data = (string) $data;
        $options = $operation->getOptions();

        $createDirs = $options['create_directories'] ?? false;
        if (is_scalar($createDirs) && (bool) $createDirs) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                if (! mkdir($dir, 0755, true)) {
                    $operation->executeCallback("Failed to create directory: $dir");
                    $this->completeOperation($operation);

                    return;
                }
            }
        }

        $mode = 'wb';
        $flagsRaw = $options['flags'] ?? 0;
        if (is_numeric($flagsRaw) && (((int) $flagsRaw & FILE_APPEND) !== 0)) {
            $mode = 'ab';
        }

        $stream = @fopen($path, $mode);
        if ($stream === false) {
            $operation->executeCallback("Failed to open file for writing: $path");
            $this->completeOperation($operation);

            return;
        }

        $dataLength = strlen($data);
        $bytesWritten = 0;

        $this->scheduleStreamWrite($operation, $stream, $data, $bytesWritten, $dataLength);
    }

    /**
     * @param  resource  $stream
     */
    private function scheduleStreamWrite(FileOperation $operation, $stream, string $data, int &$bytesWritten, int $totalLength): void
    {
        // Check for cancellation at the start of each iteration
        if ($operation->isCancelled()) {
            fclose($stream);
            $this->completeOperation($operation);

            return;
        }

        if ($bytesWritten >= $totalLength) {
            fclose($stream);
            $operation->executeCallback(null, $bytesWritten);
            $this->completeOperation($operation);

            return;
        }

        $chunkSize = min(self::CHUNK_SIZE, $totalLength - $bytesWritten);
        $chunk = substr($data, $bytesWritten, $chunkSize);

        $written = fwrite($stream, $chunk);
        if ($written === false) {
            fclose($stream);
            $operation->executeCallback('Failed to write to file');
            $this->completeOperation($operation);

            return;
        }

        $bytesWritten += $written;

        EventLoop::getInstance()->addTimer(0, function () use ($operation, $stream, $data, &$bytesWritten, $totalLength) {
            $this->scheduleStreamWrite($operation, $stream, $data, $bytesWritten, $totalLength);
        });
    }

    private function handleStreamingCopy(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            $this->completeOperation($operation);

            return;
        }

        $sourcePath = $operation->getPath();
        $destinationPath = $operation->getData();

        if (! is_string($destinationPath) || $destinationPath === '') {
            $operation->executeCallback('Invalid destination path provided for copy.');
            $this->completeOperation($operation);

            return;
        }

        if (! file_exists($sourcePath)) {
            $operation->executeCallback("Source file does not exist: $sourcePath");
            $this->completeOperation($operation);

            return;
        }

        $sourceStream = @fopen($sourcePath, 'rb');
        if ($sourceStream === false) {
            $operation->executeCallback("Failed to open source file: $sourcePath");
            $this->completeOperation($operation);

            return;
        }

        $destStream = @fopen($destinationPath, 'wb');
        if ($destStream === false) {
            fclose($sourceStream);
            $operation->executeCallback("Failed to open destination file: {$destinationPath}");
            $this->completeOperation($operation);

            return;
        }

        $this->scheduleStreamCopy($operation, $sourceStream, $destStream);
    }

    /**
     * @param  resource  $sourceStream
     * @param  resource  $destStream
     */
    private function scheduleStreamCopy(FileOperation $operation, $sourceStream, $destStream): void
    {
        // Check for cancellation at the start of each iteration
        if ($operation->isCancelled()) {
            fclose($sourceStream);
            fclose($destStream);
            $this->completeOperation($operation);

            return;
        }

        if (feof($sourceStream)) {
            fclose($sourceStream);
            fclose($destStream);
            $operation->executeCallback(null, true);
            $this->completeOperation($operation);

            return;
        }

        $chunk = fread($sourceStream, self::CHUNK_SIZE);
        if ($chunk === false) {
            fclose($sourceStream);
            fclose($destStream);
            $operation->executeCallback('Failed to read from source file');
            $this->completeOperation($operation);

            return;
        }

        $written = fwrite($destStream, $chunk);
        if ($written === false) {
            fclose($sourceStream);
            fclose($destStream);
            $operation->executeCallback('Failed to write to destination file');
            $this->completeOperation($operation);

            return;
        }

        EventLoop::getInstance()->addTimer(0, function () use ($operation, $sourceStream, $destStream) {
            $this->scheduleStreamCopy($operation, $sourceStream, $destStream);
        });
    }

    private function executeOperationSync(FileOperation $operation): void
    {
        if ($operation->isCancelled()) {
            $this->completeOperation($operation);

            return;
        }

        try {
            switch ($operation->getType()) {
                case 'read':
                    $this->handleRead($operation);

                    break;
                case 'write':
                    $this->handleWrite($operation);

                    break;
                case 'write_generator':
                    $this->handleWriteFromGenerator($operation);

                    break;
                case 'read_generator':
                    $this->handleReadAsGenerator($operation);

                    break;
                case 'append':
                    $this->handleAppend($operation);

                    break;
                case 'delete':
                    $this->handleDelete($operation);

                    break;
                case 'exists':
                    $this->handleExists($operation);

                    break;
                case 'stat':
                    $this->handleStat($operation);

                    break;
                case 'mkdir':
                    $this->handleMkdir($operation);

                    break;
                case 'rmdir':
                    $this->handleRmdir($operation);

                    break;
                case 'copy':
                    $this->handleCopy($operation);

                    break;
                case 'rename':
                    $this->handleRename($operation);

                    break;
                default:
                    $availableTypes = [
                        'read',
                        'write',
                        'write_generator',
                        'read_generator',
                        'append',
                        'delete',
                        'exists',
                        'stat',
                        'mkdir',
                        'rmdir',
                        'copy',
                        'rename',
                    ];

                    throw new InvalidArgumentException(
                        "Unknown operation type: '{$operation->getType()}'. " .
                            'Available types: ' . implode(', ', $availableTypes)
                    );
            }
        } catch (Throwable $e) {
            throw $e;
        } finally {
            $this->completeOperation($operation);
        }
    }

    private function handleRead(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $options = $operation->getOptions();

        if (! file_exists($path)) {
            $operation->executeCallback("File does not exist: $path");
            $this->completeOperation($operation);

            return;
        }

        if (! is_readable($path)) {
            $operation->executeCallback("Permission denied: File is not readable: $path");
            $this->completeOperation($operation);

            return;
        }

        $offsetRaw = $options['offset'] ?? 0;
        $offset = is_numeric($offsetRaw) ? (int) $offsetRaw : 0;

        $lengthRaw = $options['length'] ?? null;
        $length = is_numeric($lengthRaw) ? max(0, (int) $lengthRaw) : null;

        if ($length !== null) {
            $content = @file_get_contents($path, false, null, $offset, $length);
        } else {
            $content = @file_get_contents($path, false, null, $offset);
        }

        if ($content === false) {
            $operation->executeCallback("Failed to read file: $path");
            $this->completeOperation($operation);

            return;
        }

        $operation->executeCallback(null, $content);
        $this->completeOperation($operation);
    }

    private function handleWrite(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $data = $operation->getData();
        $options = $operation->getOptions();

        $flagsRaw = $options['flags'] ?? 0;
        $flags = is_numeric($flagsRaw) ? (int) $flagsRaw : 0;

        $createDirs = $options['create_directories'] ?? false;
        if (is_scalar($createDirs) && (bool) $createDirs) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                $mkdirResult = @mkdir($dir, 0755, true);
                if ($mkdirResult === false) {
                    $operation->executeCallback("Permission denied: Failed to create directory: $dir");
                    $this->completeOperation($operation);

                    return;
                }
            }
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            $operation->executeCallback("Directory does not exist: $dir");
            $this->completeOperation($operation);

            return;
        }

        if (! is_writable($dir)) {
            $operation->executeCallback("Permission denied: Directory is not writable: $dir");
            $this->completeOperation($operation);

            return;
        }

        if (file_exists($path) && ! is_writable($path)) {
            $operation->executeCallback("Permission denied: File is not writable: $path");
            $this->completeOperation($operation);

            return;
        }

        $result = @file_put_contents($path, $data, $flags);

        if ($result === false) {
            $operation->executeCallback("Failed to write file: $path");
            $this->completeOperation($operation);

            return;
        }

        $operation->executeCallback(null, $result);
        $this->completeOperation($operation);
    }

    private function handleAppend(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $data = $operation->getData();

        $result = file_put_contents($path, $data, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            throw new RuntimeException("Failed to append to file: $path");
        }

        $operation->executeCallback(null, $result);
    }

    private function handleDelete(FileOperation $operation): void
    {
        $path = $operation->getPath();

        if (! file_exists($path)) {
            $operation->executeCallback("File does not exist: $path");
            $this->completeOperation($operation);

            return;
        }

        if (! is_file($path)) {
            $operation->executeCallback("Path is not a file: $path");
            $this->completeOperation($operation);

            return;
        }

        $result = @unlink($path);

        if ($result === false) {
            $operation->executeCallback("Failed to delete file: $path");
        } else {
            $operation->executeCallback(null, true);
        }

        $this->completeOperation($operation);
    }

    private function handleExists(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $exists = file_exists($path);

        $operation->executeCallback(null, $exists);
    }

    private function handleStat(FileOperation $operation): void
    {
        $path = $operation->getPath();

        if (! file_exists($path)) {
            $operation->executeCallback("File does not exist: $path");
            $this->completeOperation($operation);

            return;
        }

        $stat = @stat($path);

        if ($stat === false) {
            $operation->executeCallback("Failed to get file stats: $path");
            $this->completeOperation($operation);

            return;
        }

        $operation->executeCallback(null, $stat);
        $this->completeOperation($operation);
    }

    private function handleMkdir(FileOperation $operation): void
    {
        $path = $operation->getPath();
        $options = $operation->getOptions();

        $modeRaw = $options['mode'] ?? 0755;
        $mode = is_numeric($modeRaw) ? (int) $modeRaw : 0755;

        $recursiveRaw = $options['recursive'] ?? false;
        $recursive = is_scalar($recursiveRaw) ? (bool) $recursiveRaw : false;

        // Check if directory already exists
        if (is_dir($path)) {
            $operation->executeCallback(error: 'File or directory already exists');

            return;
        }

        $result = mkdir($path, $mode, $recursive);

        if ($result === false) {
            $operation->executeCallback(error: "Failed to create directory: $path");

            return;
        }

        $operation->executeCallback(null, true);
    }

    private function handleRmdir(FileOperation $operation): void
    {
        $path = $operation->getPath();

        if (! is_dir($path)) {
            $operation->executeCallback(error: 'File or directory not found');

            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);

        if (count($files) > 0) {
            $this->removeDirectoryRecursive(dir: $path, operation: $operation);
        } else {
            $result = rmdir($path);
            if ($result === false) {
                $operation->executeCallback(error: "Failed to remove directory: $path");

                return;
            }
        }

        if ($operation->isCancelled()) {
            return;
        }

        $operation->executeCallback(null, true);
    }

    private function removeDirectoryRecursive(string $dir, FileOperation $operation): void
    {
        if ($operation->isCancelled() || ! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            if ($operation->isCancelled()) {
                return;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectoryRecursive($path, $operation);
            } else {
                unlink($path);
            }
        }

        if ($operation->isCancelled()) {
            return;
        }

        rmdir($dir);
    }

    private function handleCopy(FileOperation $operation): void
    {
        $sourcePath = $operation->getPath();
        $destinationPath = $operation->getData();

        if (! is_string($destinationPath)) {
            $operation->executeCallback('Destination path for copy must be a string.');
            $this->completeOperation($operation);

            return;
        }

        if (! file_exists($sourcePath)) {
            $operation->executeCallback("Source file does not exist: $sourcePath");
            $this->completeOperation($operation);

            return;
        }

        if (! is_file($sourcePath)) {
            $operation->executeCallback("Source path is not a file: $sourcePath");
            $this->completeOperation($operation);

            return;
        }

        $result = @copy($sourcePath, $destinationPath);

        if ($result === false) {
            $operation->executeCallback("Failed to copy file from {$sourcePath} to {$destinationPath}");
        } else {
            $operation->executeCallback(null, true);
        }

        $this->completeOperation($operation);
    }

    private function handleRename(FileOperation $operation): void
    {
        $oldPath = $operation->getPath();
        $newPath = $operation->getData();

        if (! is_string($newPath)) {
            $operation->executeCallback(error: 'New path for rename must be a string.');

            return;
        }

        if (! file_exists($oldPath)) {
            $operation->executeCallback(error: 'File or directory not found');

            return;
        }

        if (file_exists($newPath)) {
            $operation->executeCallback(error: 'File or directory already exists');

            return;
        }

        $result = @rename($oldPath, $newPath);

        if ($result === false) {
            $operation->executeCallback(error: "Failed to rename file from {$oldPath} to {$newPath}");

            return;
        }

        $operation->executeCallback(null, true);
    }
}
