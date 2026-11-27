<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GlobalNewsScraper\Exception\ExportException;
use GlobalNewsScraper\Exception\InvalidInputException;

/**
 * Atomic file writing: the content goes into a temporary file next to the target, which then takes its name. The
 * target is therefore either complete or untouched.
 *
 * @internal
 */
final class FileWriter
{
    public static function write(string $path, string $content, bool $overwrite): void
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('#\A[a-z][a-z0-9+.\-]*://#i', $path) === 1) {
            throw new InvalidInputException('The path must be a local file path');
        }

        if (is_dir($path)) {
            throw new ExportException("Cannot write '{$path}': it is a directory");
        }

        if (! $overwrite && self::exists($path)) {
            throw self::alreadyExists($path);
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new ExportException("Cannot write '{$path}': the directory does not exist");
        }

        $temporary = $directory.DIRECTORY_SEPARATOR.'.export-'.bin2hex(random_bytes(8)).'.tmp';

        try {
            // An overwritten file keeps its permissions
            $permissions = $overwrite && is_file($path) ? @fileperms($path) : false;

            self::create($temporary, $path, $content, $permissions === false ? null : $permissions & 0777);
            $overwrite ? self::replace($temporary, $path) : self::publish($temporary, $path);
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function create(string $temporary, string $path, string $content, ?int $permissions): void
    {
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new ExportException("Cannot write '{$path}': the directory is not writable");
        }

        // Set before writing, so the content is never readable by more users than the target was
        if ($permissions !== null) {
            @chmod($temporary, $permissions);
        }

        $written = @fwrite($handle, $content);
        $complete = $written === strlen($content) && fflush($handle);

        if (! fclose($handle) || ! $complete) {
            throw new ExportException("Cannot write '{$path}': writing failed, the disk may be full");
        }
    }

    private static function replace(string $temporary, string $path): void
    {
        if (! @rename($temporary, $path)) {
            throw new ExportException("Cannot write '{$path}': the file could not be replaced");
        }
    }

    /** Gives the file its name without ever replacing another one, also one created in the meantime. */
    private static function publish(string $temporary, string $path): void
    {
        // A hard link fails when the name is taken. The temporary name is removed by the caller.
        if (@link($temporary, $path)) {
            return;
        }

        // File systems without hard links: claim the name first, then replace the empty file
        $claim = @fopen($path, 'xb');

        if ($claim === false) {
            throw self::exists($path)
                ? self::alreadyExists($path)
                : new ExportException("Cannot write '{$path}': the file could not be created");
        }

        fclose($claim);

        if (! @rename($temporary, $path)) {
            @unlink($path);

            throw new ExportException("Cannot write '{$path}': the file could not be created");
        }
    }

    private static function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return file_exists($path) || is_link($path);
    }

    private static function alreadyExists(string $path): ExportException
    {
        return new ExportException("'{$path}' already exists: pass overwrite: true to replace it");
    }
}
