<?php

namespace Joomla\Plugin\System\RadicalForm\Helper;

use Joomla\Filesystem\Exception\FilesystemException;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Path;

\defined('_JEXEC') or die;

final class UploadStorage
{
    public const GROUP_PATTERN = '(?:[1-9][0-9]{0,18}|v2-[a-f0-9]{64})';

    private const LOCK_FILE = '.radicalform-upload.lock';
    private const MUTATION_LOCK_FILE = '.radicalform-upload-mutation.lock';
    private const MUTATION_LOCK_PREFIX = '.radicalform-upload-mutation-';
    private const RECOUNT_FILE = '.radicalform-upload-recount';
    private const STATE_FILE = '.radicalform-upload-state.php';
    private const TEMP_STATE_FILE = '.radicalform-upload-state.tmp.php';
    private const TEMP_UPLOAD_FILE = '.radicalform-upload-in-progress';
    private const STATE_HEADER = "<?php http_response_code(404); exit; ?>\n";
    private const MAX_STATE_BYTES = 65536;
    private const MAX_TRACKED_MUTATIONS = 128;
    private const MAINTENANCE_INTERVAL = 900;
    private const UPLOADS_PER_CLIENT_PER_MINUTE = 60;
    private const UPLOADS_PER_STORAGE_PER_MINUTE = 300;
    private const MAX_FILES = 10000;
    private const MAX_DIRECTORIES = 20000;
    private const FREE_SPACE_RESERVE = 16777216;
    private const EMPTY_USAGE = ['bytes' => 0, 'files' => 0, 'directories' => 0];

    private string $root;
    private ?array $usage = null;

    private function __construct(string $root)
    {
        $this->root = $root;
    }

    public static function open(string $path): ?self
    {
        $root = $path !== '' && !str_contains($path, "\0") ? realpath($path) : false;

        // Joomla's filesystem methods must not rewrite the canonical storage path.
        return $root !== false && is_dir($root) && Path::clean($root) === $root ? new self($root) : null;
    }

    public static function isSegment(string $name): bool
    {
        return preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/', $name) === 1 && !str_ends_with($name, '.');
    }

    public function groupPath(string $group): ?string
    {
        if (!preg_match('/\A' . self::GROUP_PATTERN . '\z/', $group))
        {
            return null;
        }

        $name = 'rf-' . $group;
        $path = $this->join($this->root, $name);

        return !is_link($path) && !file_exists($path) ? $path : $this->directory([$name]);
    }

    public function directory(array $segments, bool $create = false): ?string
    {
        $directory = $this->root;

        foreach ($segments as $segment)
        {
            if (!self::isSegment($segment))
            {
                return null;
            }

            $candidate = $this->join($directory, $segment);

            if ($create && !is_link($candidate) && !file_exists($candidate))
            {
                // Another request may create this directory first. Check the resulting path either way.
                @mkdir($candidate);
            }

            $resolved = $this->resolveChild($directory, $candidate);

            if ($resolved === null || !is_dir($resolved))
            {
                return null;
            }

            $directory = $resolved;
        }

        return $directory;
    }

    public function file(string $directory, string $name, bool $allowNew = false): ?string
    {
        if (!self::isSegment($name) || !str_starts_with($directory, $this->join($this->root, ''))
            || realpath($directory) !== $directory)
        {
            return null;
        }

        $candidate = $this->join($directory, $name);

        if (is_link($candidate))
        {
            return null;
        }

        if ($allowNew && !file_exists($candidate))
        {
            return $candidate;
        }

        $resolved = $this->resolveChild($directory, $candidate);

        return $resolved !== null && is_file($resolved) ? $resolved : null;
    }

    public function admitUpload(string $client, int $fileCount): bool
    {
        if ($fileCount < 1 || $fileCount > self::UPLOADS_PER_CLIENT_PER_MINUTE)
        {
            return false;
        }

        return $this->withLock(function () use ($client, $fileCount): bool
        {
            $now = time();
            $state = $this->readState();
            $minute = intdiv($now, 60);
            if ($state['minute'] !== $minute)
            {
                $state['minute'] = $minute;
                $state['attempts'] = 0;
                $state['clients'] = [];
            }

            $clientAttempts = ($state['clients'][$client] ?? 0) + $fileCount;
            $attempts = $state['attempts'] + $fileCount;
            if ($attempts > self::UPLOADS_PER_STORAGE_PER_MINUTE || $clientAttempts > self::UPLOADS_PER_CLIENT_PER_MINUTE)
            {
                return false;
            }

            $state['attempts'] = $attempts;
            $state['clients'][$client] = $clientAttempts;

            $this->writeState($state);

            return true;
        });
    }

    /** Publish validated files with exclusive access to quota accounting. */
    public function uploadBatch(string $group, int $retentionSeconds, callable $upload): array
    {
        return $this->withLock(function () use ($group, $retentionSeconds, $upload): array
        {
            $now = time();
            $state = $this->readState();
            $this->recoverUntrackedMutations($state);
            $this->settleCompletedMutations($state);
            if (isset($state['mutations'][$group]))
            {
                $this->refreshGroupUsage($state, $group);
            }
            $usage = $this->beginQuotaChange($state);

            if ($usage === null || $state['checked'] > $now || $now - $state['checked'] >= self::MAINTENANCE_INTERVAL)
            {
                $temporaryUpload = $this->maintenancePath(self::TEMP_UPLOAD_FILE);
                if (is_file($temporaryUpload) && !@unlink($temporaryUpload))
                {
                    throw new \RuntimeException('Cannot remove an interrupted RadicalForm upload.');
                }
                $this->cleanExpired($now - $retentionSeconds, $state['mutations']);
                $state['checked'] = $now;
                $usage = self::EMPTY_USAGE;
                $this->measureDirectory($this->root, $usage, $state['mutations']);
            }

            $this->checkRecountRequired();
            $this->usage = $usage;
            try
            {
                $result = $upload();
                $state['usage'] = $this->usage;
                $this->applyGroupOperation($state, $group, $usage);
                $this->writeState($state);

                return $result;
            }
            finally
            {
                $this->usage = null;
            }
        });
    }

    /**
     * Keep quota accounting consistent while a legacy callback or layout can write to its group.
     *
     * The caller must run the returned closure in a finally block. A pending group's
     * baseline is the portion of the cached total already charged to that group.
     */
    public function beginGroupMutation(string $group): \Closure
    {
        if (!preg_match('/\A' . self::GROUP_PATTERN . '\z/', $group))
        {
            throw new \InvalidArgumentException('Invalid RadicalForm upload group.');
        }

        $lockName = $this->groupMutationLock($group);
        $handle = $this->acquireLock($lockName, LOCK_SH);
        try
        {
            $this->withLock(function () use ($group): void
            {
                $state = $this->readState();
                if (!isset($state['mutations'][$group]))
                {
                    if (count($state['mutations']) >= self::MAX_TRACKED_MUTATIONS)
                    {
                        throw new \RuntimeException('Too many concurrent RadicalForm upload writers.');
                    }
                    $state['mutations'][$group] = $this->measureGroup($group);
                    $this->writeState($state);
                }
            });
        }
        catch (\Throwable $error)
        {
            fclose($handle);
            throw $error;
        }

        return function () use ($handle, $group, $lockName): void
        {
            try
            {
                $this->withLock(function () use ($handle, $group, $lockName): void
                {
                    $state = $this->readState();
                    fclose($handle);
                    if (isset($state['mutations'][$group]) && !$this->hasActiveMutations($lockName))
                    {
                        $this->refreshGroupUsage($state, $group);
                        unset($state['mutations'][$group]);
                        $this->writeState($state);
                    }
                });
            }
            finally
            {
                if (is_resource($handle))
                {
                    fclose($handle);
                }
            }
        };
    }

    /** Arrange a fresh count after a callback that cannot register its group. */
    public function beginUntrackedMutation(): \Closure
    {
        $handle = $this->acquireLock(self::MUTATION_LOCK_FILE, LOCK_SH);
        try
        {
            if (!@touch($this->maintenancePath(self::RECOUNT_FILE)))
            {
                throw new \RuntimeException('Cannot mark the RadicalForm quota for reconciliation.');
            }
        }
        catch (\Throwable $error)
        {
            fclose($handle);
            throw $error;
        }

        return static function () use ($handle): void {
            fclose($handle);
        };
    }

    private function recoverUntrackedMutations(array &$state): void
    {
        $path = $this->maintenancePath(self::RECOUNT_FILE);
        if (!is_file($path))
        {
            return;
        }

        $lock = $this->openLockFile(self::MUTATION_LOCK_FILE);
        try
        {
            if (!flock($lock, LOCK_EX | LOCK_NB))
            {
                throw new \RuntimeException('A RadicalForm upload writer is still active.');
            }

            // Commit the invalidation before removing the marker, including after a crash.
            $state['usage'] = null;
            $this->writeState($state);
            if (!@unlink($path))
            {
                throw new \RuntimeException('Cannot clear the RadicalForm quota reconciliation marker.');
            }
        }
        finally
        {
            fclose($lock);
        }
    }

    private function checkRecountRequired(): void
    {
        // A callback may have started while a slow sweep held the quota lock.
        if (is_file($this->maintenancePath(self::RECOUNT_FILE)))
        {
            throw new \RuntimeException('RadicalForm upload changes require a fresh count.');
        }
    }

    private function beginQuotaChange(array &$state): ?array
    {
        $usage = $state['usage'];
        $state['usage'] = null;
        $this->writeState($state);

        return $usage;
    }

    private function settleCompletedMutations(array &$state): void
    {
        foreach (array_keys($state['mutations']) as $group)
        {
            if (!$this->hasActiveMutations($this->groupMutationLock($group)))
            {
                $this->refreshGroupUsage($state, $group);
                unset($state['mutations'][$group]);
            }
        }
    }

    private function refreshGroupUsage(array &$state, string $group): void
    {
        if ($state['usage'] === null)
        {
            return;
        }

        $before = $state['mutations'][$group];
        $after = $this->measureGroup($group);
        foreach ($before as $key => $count)
        {
            if ($state['usage'][$key] < $count)
            {
                $state['usage'] = null;
                break;
            }
            $state['usage'][$key] += $after[$key] - $count;
        }
        $state['mutations'][$group] = $after;
    }

    private function applyGroupOperation(array &$state, string $group, array $before): void
    {
        if (!isset($state['mutations'][$group]))
        {
            return;
        }

        $baseline = $state['mutations'][$group];
        foreach ($baseline as $key => $count)
        {
            $adjusted = $count + $state['usage'][$key] - $before[$key];
            if ($adjusted < 0)
            {
                $state['usage'] = null;
                return;
            }
            $baseline[$key] = $adjusted;
        }
        $state['mutations'][$group] = $baseline;
    }

    /** Return false on a write error, null when a quota would be exceeded. */
    public function store(string $group, string $field, string $name, string $source, int $size, int|float $maxBytes): ?bool
    {
        if ($this->usage === null || !self::isSegment($field) || !self::isSegment($name))
        {
            return false;
        }

        $groupName = 'rf-' . $group;
        $groupDirectory = $this->directory([$groupName]);
        $directory = $groupDirectory !== null ? $this->directory([$groupName, $field]) : null;
        $destination = $directory !== null ? $this->file($directory, $name, true) : null;
        if ($directory !== null && $destination === null)
        {
            return false;
        }

        $previousSize = $destination !== null && is_file($destination) ? $this->fileSize($destination) : null;
        $newDirectories = (int) ($groupDirectory === null) + (int) ($directory === null);
        if ($previousSize !== null && ($this->usage['files'] === 0 || $this->usage['bytes'] < $previousSize))
        {
            throw new \RuntimeException('RadicalForm upload state is out of date.');
        }

        $freeSpace = @disk_free_space($this->root);
        if ($this->usage['bytes'] - ($previousSize ?? 0) + $size >= $maxBytes
            || $this->usage['files'] + (int) ($previousSize === null) > self::MAX_FILES
            || $this->usage['directories'] + $newDirectories > self::MAX_DIRECTORIES
            || $freeSpace === false || $size + self::FREE_SPACE_RESERVE > $freeSpace)
        {
            return null;
        }

        $createdGroup = null;
        $createdDirectory = null;
        $temporary = null;
        $stored = false;
        try
        {
            if ($groupDirectory === null)
            {
                $createdGroup = $this->directory([$groupName], true);
                if ($createdGroup === null)
                {
                    return false;
                }
                $this->usage['directories']++;
            }

            if ($directory === null)
            {
                $createdDirectory = $this->directory([$groupName, $field], true);
                if ($createdDirectory === null)
                {
                    return false;
                }
                $directory = $createdDirectory;
                $this->usage['directories']++;
            }

            $destination = $this->file($directory, $name, true);
            if ($destination === null)
            {
                return false;
            }

            // Finish Joomla's move and permission checks before replacing an existing file.
            $temporary = $this->maintenancePath(self::TEMP_UPLOAD_FILE);
            if (!File::upload($source, $temporary) || !@rename($temporary, $destination))
            {
                return false;
            }

            $stored = true;
            $this->usage['bytes'] += $this->fileSize($destination) - ($previousSize ?? 0);
            $this->usage['files'] += (int) ($previousSize === null);

            return true;
        }
        catch (FilesystemException)
        {
            return false;
        }
        finally
        {
            if (!$stored)
            {
                $this->usage['directories'] -= $this->removeEmptyDirectories($createdDirectory, $createdGroup);
            }

            if ($temporary !== null && is_file($temporary) && !@unlink($temporary))
            {
                throw new \RuntimeException('Cannot remove an interrupted RadicalForm upload.');
            }
        }
    }

    public function delete(string $group, string $field, string $name): bool
    {
        return $this->withLock(function () use ($group, $field, $name): bool
        {
            $groupName = 'rf-' . $group;
            $groupDirectory = $this->directory([$groupName]);
            $directory = $groupDirectory !== null ? $this->directory([$groupName, $field]) : null;
            $file = $directory !== null ? $this->file($directory, $name) : null;
            if ($file === null)
            {
                return true;
            }

            $state = $this->readState();
            $this->recoverUntrackedMutations($state);
            $this->settleCompletedMutations($state);
            if (isset($state['mutations'][$group]))
            {
                $this->refreshGroupUsage($state, $group);
            }
            $size = $this->fileSize($file);
            $usage = $this->beginQuotaChange($state);

            $this->checkRecountRequired();
            $removed = @unlink($file);
            $removedDirectories = $removed ? $this->removeEmptyDirectories($directory, $groupDirectory) : 0;
            if ($usage !== null && $usage['files'] > 0 && $usage['bytes'] >= $size && $usage['directories'] >= $removedDirectories)
            {
                $state['usage'] = $usage;
                if ($removed)
                {
                    $state['usage']['files']--;
                    $state['usage']['bytes'] -= $size;
                    $state['usage']['directories'] -= $removedDirectories;
                    $this->applyGroupOperation($state, $group, $usage);
                }

                $this->writeState($state);
            }

            return $removed;
        });
    }

    private function removeEmptyDirectories(?string $directory, ?string $group): int
    {
        $removed = 0;
        foreach ([$directory, $group] as $path)
        {
            if ($path !== null && !is_link($path) && realpath($path) === $path && @rmdir($path))
            {
                $removed++;
            }
        }

        return $removed;
    }

    private function withLock(callable $operation): mixed
    {
        $handle = $this->acquireLock();
        try
        {
            return $operation();
        }
        finally
        {
            fclose($handle);
        }
    }

    /** @return resource */
    private function acquireLock(string $name = self::LOCK_FILE, int $mode = LOCK_EX)
    {
        $handle = $this->openLockFile($name);
        try
        {
            $deadline = hrtime(true) + 1_000_000_000;
            while (!flock($handle, $mode | LOCK_NB, $wouldBlock))
            {
                if (!$wouldBlock || hrtime(true) >= $deadline)
                {
                    throw new \RuntimeException('RadicalForm upload storage is busy.');
                }
                usleep(20_000);
            }

            return $handle;
        }
        catch (\Throwable $error)
        {
            fclose($handle);
            throw $error;
        }
    }

    /** @return resource */
    private function openLockFile(string $name)
    {
        $path = $this->maintenancePath($name);
        $handle = @fopen($path, 'c+b');
        if ($handle === false)
        {
            throw new \RuntimeException('Cannot open the RadicalForm upload lock.');
        }

        return $handle;
    }

    private function groupMutationLock(string $group): string
    {
        // Share a fixed set of locks so form submissions never leave a lock file per group.
        return self::MUTATION_LOCK_PREFIX . substr(hash('sha256', $group), 0, 2) . '.lock';
    }

    private function hasActiveMutations(string $name): bool
    {
        $handle = $this->openLockFile($name);
        try
        {
            if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock))
            {
                return false;
            }

            if (!$wouldBlock)
            {
                throw new \RuntimeException('Cannot check active RadicalForm upload writers.');
            }

            return true;
        }
        finally
        {
            fclose($handle);
        }
    }

    private function readState(): array
    {
        $path = $this->maintenancePath(self::STATE_FILE);
        $raw = is_file($path) ? @file_get_contents($path, false, null, 0, self::MAX_STATE_BYTES + 1) : false;
        $state = $raw !== false && strlen($raw) <= self::MAX_STATE_BYTES && str_starts_with($raw, self::STATE_HEADER)
            ? json_decode(substr($raw, strlen(self::STATE_HEADER)), true)
            : null;
        if (!is_array($state) || !is_int($state['checked'] ?? null) || !is_int($state['minute'] ?? null)
            || !is_int($state['attempts'] ?? null) || $state['attempts'] < 0
            || !is_array($state['clients'] ?? null))
        {
            return ['checked' => 0, 'usage' => null, 'minute' => 0, 'attempts' => 0, 'clients' => [], 'mutations' => []];
        }

        $attempts = 0;
        foreach ($state['clients'] as $client => $count)
        {
            if (!is_string($client) || strlen($client) !== 64 || !ctype_xdigit($client) || !is_int($count)
                || $count < 1 || $count > self::UPLOADS_PER_CLIENT_PER_MINUTE)
            {
                $attempts = -1;
                break;
            }
            $attempts += $count;
        }

        if ($attempts !== $state['attempts'] || $attempts > self::UPLOADS_PER_STORAGE_PER_MINUTE)
        {
            $state['minute'] = 0;
            $state['attempts'] = 0;
            $state['clients'] = [];
        }

        $mutations = $state['mutations'] ?? [];
        $state['mutations'] = [];
        if (array_key_exists('mutation', $state))
        {
            // The previous format did not save which group was changed.
            unset($state['mutation']);
            $state['usage'] = null;
        }
        if (is_array($mutations) && count($mutations) <= self::MAX_TRACKED_MUTATIONS)
        {
            foreach ($mutations as $group => $baseline)
            {
                if (preg_match('/\A' . self::GROUP_PATTERN . '\z/', (string) $group) && $this->validUsage($baseline))
                {
                    $state['mutations'][$group] = $baseline;
                }
            }
        }
        $state['usage'] = $state['mutations'] === $mutations && $this->validUsage($state['usage'] ?? null) ? $state['usage'] : null;

        return $state;
    }

    private function validUsage(mixed $usage): bool
    {
        return is_array($usage) && count($usage) === count(self::EMPTY_USAGE)
            && is_int($usage['bytes'] ?? null) && $usage['bytes'] >= 0
            && is_int($usage['files'] ?? null) && $usage['files'] >= 0
            && is_int($usage['directories'] ?? null) && $usage['directories'] >= 0;
    }

    private function writeState(array $state): void
    {
        $payload = self::STATE_HEADER . json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($payload) > self::MAX_STATE_BYTES)
        {
            throw new \RuntimeException('RadicalForm upload state exceeds its size limit.');
        }

        $path = $this->maintenancePath(self::STATE_FILE);
        $temporary = $this->maintenancePath(self::TEMP_STATE_FILE);
        if (is_file($temporary) && !@unlink($temporary))
        {
            throw new \RuntimeException('Cannot save the RadicalForm upload state.');
        }

        $handle = @fopen($temporary, 'xb');
        if ($handle === false)
        {
            throw new \RuntimeException('Cannot create the RadicalForm upload state.');
        }

        try
        {
            @chmod($temporary, 0600);
            $written = @fwrite($handle, $payload) === strlen($payload) && @fflush($handle);
        }
        finally
        {
            fclose($handle);
        }

        if (!$written || !@rename($temporary, $path))
        {
            throw new \RuntimeException('Cannot save the RadicalForm upload state.');
        }
    }

    private function maintenancePath(string $name): string
    {
        $path = $this->join($this->root, $name);
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && !is_file($path)))
        {
            throw new \RuntimeException('Invalid RadicalForm upload state path.');
        }

        return $path;
    }

    private function measureDirectory(string $directory, array &$usage, array $mutations = []): void
    {
        foreach ($mutations as $baseline)
        {
            foreach ($baseline as $key => $count)
            {
                $usage[$key] += $count;
            }
        }

        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry)
        {
            $name = $entry->getFilename();
            if ($entry->isLink() || ($directory === $this->root && ($this->isMaintenanceEntry($name)
                || (str_starts_with($name, 'rf-') && isset($mutations[substr($name, 3)])))))
            {
                continue;
            }

            $path = $this->resolveChild($directory, $entry->getPathname());
            if ($path === null)
            {
                continue;
            }

            if (is_dir($path))
            {
                $usage['directories']++;
                $this->measureDirectory($path, $usage);
            }
            elseif (is_file($path))
            {
                $usage['files']++;
                $usage['bytes'] += $this->fileSize($path);
            }
        }
    }

    private function isMaintenanceEntry(string $name): bool
    {
        return in_array($name, [self::LOCK_FILE, self::MUTATION_LOCK_FILE, self::RECOUNT_FILE,
            self::STATE_FILE, self::TEMP_STATE_FILE, self::TEMP_UPLOAD_FILE], true)
            || (str_starts_with($name, self::MUTATION_LOCK_PREFIX)
                && preg_match('/\A[0-9a-f]{2}\.lock\z/', substr($name, strlen(self::MUTATION_LOCK_PREFIX))) === 1);
    }

    private function measureGroup(string $group): array
    {
        clearstatcache(true);
        $usage = self::EMPTY_USAGE;
        $path = $this->resolveChild($this->root, $this->join($this->root, 'rf-' . $group));
        if ($path !== null && is_dir($path))
        {
            $usage['directories']++;
            $this->measureDirectory($path, $usage);
        }
        elseif ($path !== null && is_file($path))
        {
            $usage['files']++;
            $usage['bytes'] += $this->fileSize($path);
        }

        return $usage;
    }

    private function fileSize(string $file): int
    {
        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size === false)
        {
            throw new \RuntimeException('Cannot measure a RadicalForm upload file.');
        }

        return $size;
    }

    private function cleanExpired(int $cutoff, array $mutations): void
    {
        $lock = $this->openLockFile(self::MUTATION_LOCK_FILE);
        try
        {
            foreach (@scandir($this->root) ?: [] as $name)
            {
                if (!preg_match('/\Arf-' . self::GROUP_PATTERN . '\z/', $name) || isset($mutations[substr($name, 3)]))
                {
                    continue;
                }

                $directory = $this->directory([$name]);
                $modified = $directory !== null ? @filemtime($directory) : false;
                if ($modified !== false && $modified < $cutoff)
                {
                    // Only an untracked writer requires pausing cleanup for all groups.
                    if (!flock($lock, LOCK_EX | LOCK_NB))
                    {
                        return;
                    }
                    try
                    {
                        $this->removeDirectory($directory);
                    }
                    finally
                    {
                        flock($lock, LOCK_UN);
                    }
                }
            }
        }
        finally
        {
            fclose($lock);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory) || realpath($directory) !== $directory)
        {
            return;
        }

        foreach (@scandir($directory) ?: [] as $name)
        {
            if ($name === '.' || $name === '..')
            {
                continue;
            }

            $path = $this->resolveChild($directory, $this->join($directory, $name));
            if ($path === null)
            {
                continue;
            }

            if (is_dir($path))
            {
                $this->removeDirectory($path);
            }
            elseif (is_file($path))
            {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private function join(string $directory, string $name): string
    {
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    }

    private function resolveChild(string $directory, string $candidate): ?string
    {
        $path = !is_link($candidate) ? realpath($candidate) : false;

        return $path !== false && dirname($path) === $directory ? $path : null;
    }
}
