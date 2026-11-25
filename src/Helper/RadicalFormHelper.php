<?php
/*
 * @package   pkg_radicalmart_import
 * @version   __DEPLOY_VERSION__
 * @author    Dmitriy Vasyukov - https://fictionlabs.ru
 * @copyright Copyright (c) 2024 Fictionlabs. All rights reserved.
 * @license   GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link      https://fictionlabs.ru/
 */

namespace Joomla\Plugin\System\RadicalForm\Helper;

defined('_JEXEC') or die;

class RadicalFormHelper
{
    private const SPAM_ARCHIVE_LIMIT = 50;
    private const SPAM_LOG_LOCK = '.radicalform-spam-log.lock';
    private const ANTIFLOOD_LOCK = '.radicalform-antiflood.lock';
    private const ANTIFLOOD_STATE = '.radicalform-antiflood.php';
    private const STATE_HEADER = "<?php http_response_code(404); exit; ?>\n";

    public static function getHistoryLogPage(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value))
        {
            return null;
        }

        $page = (int) $value;

        return $page >= 0 && (string) $page === (string) $value ? $page : null;
    }

    public static function getHistoryLogFileName(string $logType, int $page = 0): string
    {
        $file = $logType === 'spam' ? 'plg_system_radicalform_spam.php' : 'plg_system_radicalform.php';

        return $page === 0 ? $file : $page . '.' . $file;
    }

    public static function getHistoryLogPath(string $logPath, string $logType, int $page = 0): ?string
    {
        $root = $logPath === '' ? false : realpath($logPath);
        if ($root === false || !is_dir($root))
        {
            return null;
        }

        $file = $root . DIRECTORY_SEPARATOR . self::getHistoryLogFileName($logType, $page);
        if (is_link($file))
        {
            return null;
        }

        $resolved = realpath($file);

        return $resolved !== false && dirname($resolved) === $root && is_file($resolved) ? $resolved : null;
    }

    /** Numbered Joomla archives, newest first. */
    public static function getHistoryLogArchives(string $logPath, string $logType): array
    {
        $suffix = '.' . self::getHistoryLogFileName($logType);
        $archives = [];
        foreach ($logPath === '' ? [] : (@scandir($logPath) ?: []) as $name)
        {
            if (!str_ends_with($name, $suffix))
            {
                continue;
            }

            $page = self::getHistoryLogPage(substr($name, 0, -strlen($suffix)));
            $path = $page > 0 ? self::getHistoryLogPath($logPath, $logType, $page) : null;
            if ($path !== null)
            {
                $archives[$page] = $path;
            }
        }

        ksort($archives, SORT_NUMERIC);
        return $archives;
    }

    public static function writeSpamLog(string $logPath, int $maxSize, callable $append): bool
    {
        return self::withSpamLogLock($logPath, static function (string $root) use ($maxSize, $append): bool {
            $archives = self::getHistoryLogArchives($root, 'spam');
            if (!self::pruneSpamArchives($archives))
            {
                return false;
            }

            $active = $root . DIRECTORY_SEPARATOR . self::getHistoryLogFileName('spam');
            clearstatcache(true, $active);
            if (!file_exists($active) && !is_link($active))
            {
                return $append(false);
            }

            $path = self::getHistoryLogPath($root, 'spam');
            $size = $path !== null ? @filesize($path) : false;
            if ($size === false)
            {
                return false;
            }
            if ($size <= max(0, $maxSize))
            {
                return $append(false);
            }

            // Joomla uses archive 1 for the newest file. Move older files first.
            foreach (array_reverse($archives, true) as $page => $source)
            {
                if ($page === PHP_INT_MAX || !self::moveSpamArchive($source, $root, $page + 1))
                {
                    return false;
                }
            }
            if (!self::moveSpamArchive($path, $root, 1))
            {
                return false;
            }

            $archives = self::getHistoryLogArchives($root, 'spam');
            return self::pruneSpamArchives($archives) && $append(true);
        });
    }

    public static function deleteSpamLog(string $logPath, int $page): bool
    {
        return self::withSpamLogLock($logPath, static function (string $root) use ($page): bool {
            $file = self::getHistoryLogPath($root, 'spam', $page);
            return $file === null || @unlink($file);
        });
    }

    private static function moveSpamArchive(string $source, string $root, int $page): bool
    {
        $destination = $root . DIRECTORY_SEPARATOR . self::getHistoryLogFileName('spam', $page);
        clearstatcache(true, $source);
        clearstatcache(true, $destination);

        return is_file($source) && !is_link($source) && !file_exists($destination) && !is_link($destination)
            && @rename($source, $destination);
    }

    private static function pruneSpamArchives(array &$archives): bool
    {
        foreach (array_slice($archives, self::SPAM_ARCHIVE_LIMIT, null, true) as $page => $path)
        {
            clearstatcache(true, $path);
            if (is_link($path) || (!@unlink($path) && file_exists($path)))
            {
                return false;
            }
            unset($archives[$page]);
        }
        return true;
    }

    private static function withSpamLogLock(string $logPath, callable $action): bool
    {
        $root = self::prepareLogDirectory($logPath);
        if ($root === null)
        {
            return false;
        }

        return self::withLogLock($root, self::SPAM_LOG_LOCK, static fn () => $action($root)) === true;
    }

    public static function getAntiFloodBlocks(string $logPath): ?array
    {
        $root = self::prepareLogDirectory($logPath);
        if ($root === null)
        {
            return null;
        }

        $path = $root . DIRECTORY_SEPARATOR . self::ANTIFLOOD_STATE;
        $canRecover = false;
        $blocks = self::readAntiFloodState($path, $canRecover);
        if ($blocks !== null || !$canRecover || !is_writable($root))
        {
            return $blocks;
        }

        $blocks = self::withLogLock($root, self::ANTIFLOOD_LOCK, static function () use ($root, $path): ?array {
            $canRecover = false;
            $blocks = self::readAntiFloodState($path, $canRecover);
            if ($blocks !== null || !$canRecover)
            {
                return $blocks;
            }

            $blocks = self::readBlocksFromHistory($root, time());
            if ($blocks !== null)
            {
                self::writeAntiFloodState($path, $blocks);
            }

            return $blocks;
        });

        // Another request may have saved the state while we were waiting for the lock.
        return $blocks ?? self::readAntiFloodState($path);
    }

    /** True only when a new block is saved, so one request sends the alert. */
    public static function activateAntiFloodBlock(string $logPath, array $block): bool
    {
        $root = self::prepareLogDirectory($logPath);
        if ($root === null)
        {
            return false;
        }

        return self::withLogLock($root, self::ANTIFLOOD_LOCK, static function () use ($root, $block): bool {
            $path = $root . DIRECTORY_SEPARATOR . self::ANTIFLOOD_STATE;
            $blocks = self::readAntiFloodState($path);
            if ($blocks === null || ($blocks[$block['type']]['blocked_until'] ?? 0) > time())
            {
                return false;
            }

            $blocks[$block['type']] = $block;
            return self::writeAntiFloodState($path, $blocks);
        }) === true;
    }

    private static function readAntiFloodState(string $path, bool &$canRecover = false): ?array
    {
        clearstatcache(true, $path);
        $canRecover = false;
        if (is_link($path))
        {
            return null;
        }
        if (!file_exists($path))
        {
            $canRecover = true;
            return null;
        }

        $data = is_file($path) ? @file_get_contents($path, false, null, 0, 4097) : false;
        if ($data === false)
        {
            return null;
        }
        $canRecover = true;
        if (strlen($data) > 4096 || !str_starts_with($data, self::STATE_HEADER))
        {
            return null;
        }

        $blocks = json_decode(substr($data, strlen(self::STATE_HEADER)), true);
        if (!is_array($blocks))
        {
            return null;
        }

        foreach ($blocks as $type => $block)
        {
            if (!in_array($type, ['daily', 'burst'], true) || !is_array($block) || ($block['type'] ?? null) !== $type)
            {
                return null;
            }
            foreach (['count', 'limit', 'period_minutes', 'started_at', 'blocked_until'] as $key)
            {
                if (!is_int($block[$key] ?? null) || $block[$key] < 1)
                {
                    return null;
                }
            }
        }
        return $blocks;
    }

    private static function writeAntiFloodState(string $path, array $blocks): bool
    {
        $temporary = $path . '.tmp.php';
        if (is_link($path) || is_link($temporary) || (file_exists($temporary) && !is_file($temporary)))
        {
            return false;
        }
        if (is_file($temporary) && !@unlink($temporary))
        {
            return false;
        }
        $handle = @fopen($temporary, 'xb');
        if ($handle === false)
        {
            return false;
        }

        try
        {
            @chmod($temporary, 0600);
            $buffer = self::STATE_HEADER . json_encode($blocks, JSON_THROW_ON_ERROR);
            $written = @fwrite($handle, $buffer) === strlen($buffer) && @fflush($handle);
        }
        finally
        {
            fclose($handle);
        }

        return $written && @rename($temporary, $path);
    }

    private static function readBlocksFromHistory(string $root, int $now): ?array
    {
        if (!is_readable($root))
        {
            return null;
        }

        $files = self::getHistoryLogArchives($root, 'spam');
        $active = self::getHistoryLogPath($root, 'spam');
        if ($active !== null)
        {
            $files[0] = $active;
        }

        $blocks = [];
        foreach ($files as $file)
        {
            $handle = @fopen($file, 'rb');
            if ($handle === false)
            {
                clearstatcache(true, $file);
                if (!file_exists($file))
                {
                    continue;
                }
                return null;
            }

            try
            {
                while (($row = fgetcsv($handle, 200000, "\t", '"', '\\')) !== false)
                {
                    if (count($row) < 4 || $row[3] !== 'WARNING' || !str_contains((string) $row[2], 'rfAntiFloodBlockedUntil'))
                    {
                        continue;
                    }
                    $block = self::readHistoryBlock($row[2], $now);
                    if ($block !== null && $block['blocked_until'] > ($blocks[$block['type']]['blocked_until'] ?? 0))
                    {
                        $blocks[$block['type']] = $block;
                    }
                }
                if (!feof($handle))
                {
                    return null;
                }
            }
            finally
            {
                fclose($handle);
            }
        }
        return $blocks;
    }

    private static function readHistoryBlock(string $json, int $now): ?array
    {
        $entry = json_decode($json, true);
        // Old rejection handlers always overwrite rfAntiSpam with one of these markers.
        if (!is_array($entry) || count($entry) !== 8 || !is_string($entry['rfAntiSpam'] ?? null)
            || in_array($entry['rfAntiSpam'], ['invalid token', 'javascript challenge'], true)
            || !is_string($entry['rfWarningMessage'] ?? null) || $entry['rfWarningMessage'] === ''
            || !in_array($entry['rfAntiFlood'] ?? null, ['daily', 'burst'], true))
        {
            return null;
        }

        foreach (['rfAntiFloodCount', 'rfAntiFloodLimit', 'rfAntiFloodPeriodMinutes'] as $key)
        {
            if (!is_int($entry[$key] ?? null) || $entry[$key] < 1)
            {
                return null;
            }
        }

        $started = self::parseHistoryDate($entry['rfAntiFloodStartedAt'] ?? null);
        $until = self::parseHistoryDate($entry['rfAntiFloodBlockedUntil'] ?? null);
        if ($started === null || $until === null || $started > $now || $until <= $now || $until <= $started
            || $entry['rfAntiFloodCount'] < $entry['rfAntiFloodLimit'])
        {
            return null;
        }

        return [
            'type' => $entry['rfAntiFlood'],
            'count' => $entry['rfAntiFloodCount'],
            'limit' => $entry['rfAntiFloodLimit'],
            'period_minutes' => $entry['rfAntiFloodPeriodMinutes'],
            'started_at' => $started,
            'blocked_until' => $until,
        ];
    }

    private static function parseHistoryDate(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}\z/', $value))
        {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $date !== false && $date->format(DATE_ATOM) === $value ? $date->getTimestamp() : null;
    }

    private static function prepareLogDirectory(string $logPath): ?string
    {
        if ($logPath === '' || str_contains($logPath, "\0"))
        {
            return null;
        }
        if (!is_dir($logPath))
        {
            @mkdir($logPath, 0755, true);
        }
        $root = realpath($logPath);
        return $root !== false && is_dir($root) ? $root : null;
    }

    private static function withLogLock(string $root, string $name, callable $action): mixed
    {
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || (file_exists($path) && !is_file($path)))
        {
            return null;
        }
        $lock = @fopen($path, 'c+b');
        if ($lock === false)
        {
            return null;
        }

        try
        {
            $deadline = hrtime(true) + 150_000_000;
            while (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock))
            {
                if (!$wouldBlock || hrtime(true) >= $deadline)
                {
                    return null;
                }
                usleep(10_000);
            }

            return $action();
        }
        finally
        {
            fclose($lock);
        }
    }

	/**
	 * Method to get data from csv file.
	 *
	 * @param   string  $file       File path
	 * @param   string  $delimiter  Csv delimiter
	 *
	 * @return  array
	 *
	 * @since   1.0.0
	 */
	public static function getCSV(string $file, $delimiter = ';')
	{
		$a = [];

		if (file_exists($file) && ($handle = fopen($file, 'r')) !== false)
		{
			while (($data = fgetcsv($handle, 200000, $delimiter, '"', '\\')) !== false)
			{
				$a[] = $data;
			}
			fclose($handle);
		}

		return $a;
	}
}
