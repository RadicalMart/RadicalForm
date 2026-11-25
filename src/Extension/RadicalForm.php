<?php
/*
 * @package   RadicalForm
 * @version   __DEPLOY_VERSION__
 * @author    Vladimir Eliseev aka Progreccor - https://progreccor.ru
 * @copyright Copyright (c) 2025 Progreccor. All rights reserved.
 * @license   GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link      https://progreccor.ru
 */

namespace Joomla\Plugin\System\RadicalForm\Extension;

// No direct access
\defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Mail;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Response\JsonResponse;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Plugins\Administrator\Model\PluginModel;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Filesystem\File;
use Joomla\Plugin\System\RadicalForm\Event\BeforeProcessRadicalFormEvent;
use Joomla\Plugin\System\RadicalForm\Helper\FormMessage;
use Joomla\Plugin\System\RadicalForm\Helper\MaxHelper;
use Joomla\Plugin\System\RadicalForm\Helper\RadicalFormHelper;
use Joomla\Plugin\System\RadicalForm\Helper\UploadStorage;
use Joomla\String\StringHelper;
use Joomla\CMS\HTML\HTMLHelper;

class RadicalForm extends CMSPlugin implements SubscriberInterface
{
	use DatabaseAwareTrait;

	/**
	 * Affects constructor behavior.
	 *
	 * @var  boolean
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected $autoloadLanguage = true;

	/**
	 * @var string
	 */
	private $logPath;

	/**
	 * @var string
	 */
	private const UTM_SESSION_KEY = 'radicalform.utm';

	private const JS_CHALLENGE_SESSION_KEY = 'radicalform.js_challenges';

	private const JS_PERMIT_SESSION_KEY = 'radicalform.js_permits';

	private const JS_CHALLENGE_TTL = 30;

	private const JS_PERMIT_TTL = 60;

	private const JS_CHALLENGE_DELAY_DEFAULT_MS = 350;

	private const JS_CHALLENGE_DELAY_MIN_MS = 100;

	private const JS_CHALLENGE_DELAY_MAX_MS = 1000;

	private const JS_CHALLENGE_DELAY_TOLERANCE_MS = 50;

	private const JS_CHALLENGE_MAX_ITEMS = 5;

	private const JS_CHALLENGE_OPERATIONS = 16;

	private const SPAM_LOG_VALUE_MAX_LENGTH = 4096;

	private const SPAM_LOG_ENTRY_MAX_BYTES = 65536;

    private const IMAGE_UPLOAD_MIME_TYPES = [
        'apng' => ['image/apng', 'image/png'],
        'avif' => ['image/avif'],
        'bmp' => ['image/bmp', 'image/x-bmp', 'image/x-ms-bmp'],
        'gif' => ['image/gif'],
        'heic' => ['image/heic', 'image/heic-sequence', 'image/heif', 'image/heif-sequence'],
        'heif' => ['image/heic', 'image/heic-sequence', 'image/heif', 'image/heif-sequence'],
        'ico' => ['image/vnd.microsoft.icon', 'image/x-icon', 'image/x-ico'],
        'jfif' => ['image/jpeg', 'image/pjpeg'],
        'jp2' => ['image/jp2'],
        'jpe' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jxl' => ['image/jxl'],
        'png' => ['image/png', 'image/apng'],
        'tif' => ['image/tiff', 'image/x-tiff'],
        'tiff' => ['image/tiff', 'image/x-tiff'],
        'webp' => ['image/webp'],
    ];

    /**
     * Field names used by Joomla to route com_ajax requests.
     *
     * @var string[]
     */
    private const RESERVED_FIELD_NAMES = [
        'option',
        'plugin',
        'group',
        'format',
        'module',
        'template',
        'method',
        'rfjsaction',
        'rfjschallenge',
        'rfjsproof',
        'rfjspermit'
    ];

	/**
	 * Max mail file size
	 *
	 * @var float|int
	 */
	protected $maxDirSize;

	/**
	 * Max storage time
	 *
	 * @var mixed
	 */
	protected mixed $maxStorageTime;

	/**
	 * Max directory size
	 *
	 * @var float|int
	 */
	protected int|float $maxStorageSize;

	/**
	 * Event dispatcher.
	 *
	 * @var DispatcherInterface
	 */
	private DispatcherInterface $dispatcher;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterRender'     => 'onAfterRender',
			'onAfterInitialise' => 'onAfterInitialise',
			'onAjaxRadicalform' => 'onAjaxRadicalform'
		];
	}

	/**
	 * @param   DispatcherInterface      $dispatcher  The object to observe -- event dispatcher.
	 * @param   array                    $config      An optional associative array of configuration settings.
	 * @param   CMSApplicationInterface  $app         The app
	 * @param   DatabaseInterface        $db          The db
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function __construct(DispatcherInterface $dispatcher, array $config, CMSApplicationInterface $app, DatabaseInterface $db)
	{
		parent::__construct($dispatcher, $config);

		$this->setApplication($app);
		$this->setDatabase($db);
		$this->dispatcher = $dispatcher;

		$this->maxDirSize     = $this->params->get('maxfile', 20) * 1048576;
		$this->maxStorageSize = $this->params->get('maxstorage', 1000) * 1048576;
		$this->logPath        = str_replace('\\', '/', $this->getApplication()->get('log_path')) . '/plg_system_radicalform.php';
		$this->maxStorageTime = $this->params->get('maxtime', 30);

		Log::addLogger(
			array(
				// Sets file name
				'text_file'         => 'plg_system_radicalform.php',
				// Sets the format of each line
				'text_entry_format' => "{DATETIME}\t{CLIENTIP}\t{MESSAGE}\t{PRIORITY}"
			),
			// Sets all but DEBUG log level messages to be sent to the file
			Log::ALL & ~Log::DEBUG,
			array('plg_system_radicalform')
		);

		Log::addLogger(
			array(
				// Sets file name
				'text_file'         => 'plg_system_radicalform_spam.php',
				// Sets the format of each line
				'text_entry_format' => "{DATETIME}\t{CLIENTIP}\t{MESSAGE}\t{PRIORITY}"
			),
			// Sets all but DEBUG log level messages to be sent to the file
			Log::ALL & ~Log::DEBUG,
			array('plg_system_radicalform_spam')
		);

		// if we have empty storage directory or wrong directory - try to fix it
		if (empty($this->params->get('uploadstorage')) || (!file_exists($this->params->get('uploadstorage'))))
		{
			$plugin = PluginHelper::getPlugin('system', 'radicalform');

			// set the directory to safe place

			/* @var PluginModel $model */
			$model = Factory::getApplication()->bootComponent('com_plugins')->getMVCFactory()->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
			$data  = $model->getItem($plugin->id);

			$data = (array) $data;
			if (empty($this->params->get('uploadstorage')))
			{
				// empty directory
				$data['params']['uploadstorage'] = JPATH_ROOT . '/images/radicalform' . $this->uniqidReal();
				mkdir($data['params']['uploadstorage']);
			}
			else
			{
				// if directory not exist
				//try to find it
				if (file_exists(JPATH_ROOT . '/images/' . basename($this->params->get('uploadstorage'))))
				{
					$data['params']['uploadstorage'] = JPATH_ROOT . '/images/' . basename($this->params->get('uploadstorage'));
				}
				else
				{
					// empty directory
					$data['params']['uploadstorage'] = JPATH_ROOT . '/images/radicalform' . $this->uniqidReal();
					mkdir($data['params']['uploadstorage']);
				}
			}

			$model->save($data);
		}
	}

	/**
	 * Method for create real unique id
	 *
	 * @param   int  $lenght
	 *
	 * @return string
	 *
	 * @throws \Random\RandomException
	 *
	 * @since   1.0.0
	 */
	public function uniqidReal($lenght = 23)
	{
		if (function_exists("random_bytes"))
		{
			$bytes = random_bytes(ceil($lenght / 2));
		}
		elseif (function_exists("openssl_random_pseudo_bytes"))
		{
			$bytes = openssl_random_pseudo_bytes(ceil($lenght / 2));
		}
		else
		{
			throw new \Exception("no cryptographically secure random function available");
		}

		return substr(bin2hex($bytes), 0, $lenght);
	}

	/**
	 * Make filename safe
	 *
	 * @param   string  $file  File path
	 *
	 * @return string
	 *
	 * @since   1.0.0
	 */
	public function makeSafe(string $file)
	{
		// Remove any trailing dots, as those aren't ever valid file names.
		$file = rtrim($file, '.');

		$regex = array('#\.{2,}#', '#[^A-Za-z0-9._-]#u', '#^\.#');

		$repl = array('.', '_', '');

		return trim(preg_replace($regex, $repl, $file));
	}

	/**
	 * Prevent spreadsheet applications from interpreting exported values as formulas.
	 *
	 * @param   mixed  $value  CSV cell value
	 *
	 * @return string
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function escapeCsvFormula($value)
	{
		$value = (string) $value;

		if (preg_match('/^[=+\-@\t\r]/', $value))
		{
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Check access to RadicalForm administrative operations.
	 *
	 * @return boolean
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	private function canManagePlugin()
	{
		return $this->getApplication()->isClient('administrator')
			&& $this->getApplication()->getIdentity()->authorise('core.manage', 'com_plugins')
			&& $this->getApplication()->getIdentity()->authorise('core.edit', 'com_plugins');
	}

	/**
	 * Method for return string bytes
	 *
	 * @param   int  $size_str  String size
	 *
	 * @return string
	 *
	 * @since   1.0.0
	 */
	private function return_bytes($size_str)
	{
		switch (substr($size_str, -1))
		{
			case 'M':
			case 'm':
				return (int) $size_str * 1048576;
			case 'K':
			case 'k':
				return (int) $size_str * 1024;
			default:
				return $size_str;
		}
	}

	/**
	 * Clear input array from extra info about user (like resolution and other info)
	 *
	 * @param   array  $input
	 *
	 * @return array Cleared input array - only values from  the form
	 *
	 * @since version
	 */
	private function clearInput(array $input)
	{
		$values = [
			'rfTarget',
			'url',
			'reffer',
			'resolution',
			'pagetitle',
			'rfUserAgent',
			'rfFormID',
			'rfLatestNumber',
			'uniq',
			'needToSendFiles',
			'rf-time',
			'rf-duration',
			'rfJsAction',
			'rfJsChallenge',
			'rfJsProof',
			'rfJsPermit'
		];

		foreach ($values as $value)
		{
			if (isset($input[$value]))
			{
				unset($input[$value]);
			}
		}

		if (isset($input[Session::getFormToken()]))
		{
			unset($input[Session::getFormToken()]);
		}

		return $input;
	}

	private function getJsChallengeMessage(): string
	{
		$message = trim((string) $this->params->get('js_challenge_message', ''));

		if ($message === '')
		{
			$message = 'PLG_RADICALFORM_JS_CHALLENGE_FAILED';
		}

		return Text::_($message);
	}

	private function getJsChallengeDelayMs(): int
	{
		$delay = (int) $this->params->get('js_challenge_delay_ms', self::JS_CHALLENGE_DELAY_DEFAULT_MS);

		return min(self::JS_CHALLENGE_DELAY_MAX_MS, max(self::JS_CHALLENGE_DELAY_MIN_MS, $delay));
	}

	private function getJsChallengeMinimumDelaySeconds(?int $delayMs = null): float
	{
		$delayMs ??= $this->getJsChallengeDelayMs();

		return max(0, ($delayMs - self::JS_CHALLENGE_DELAY_TOLERANCE_MS) / 1000);
	}

	private function getJsSessionItems(string $key, float $now): array
	{
		$session = $this->getApplication()->getSession();
		$items   = (array) $session->get($key, []);

		foreach ($items as $id => $item)
		{
			if (!is_array($item) || (float) ($item['expires_at'] ?? 0) < $now)
			{
				unset($items[$id]);
			}
		}

		return $items;
	}

	private function limitJsSessionItems(array $items): array
	{
		uasort($items, function ($first, $second) {
			return (float) ($first['created_at'] ?? 0) <=> (float) ($second['created_at'] ?? 0);
		});

		while (count($items) >= self::JS_CHALLENGE_MAX_ITEMS)
		{
			array_shift($items);
		}

		return $items;
	}

	private function checkJsChallengeRequestToken(): void
	{
		$session = $this->getApplication()->getSession();

		if ($session->isNew() || !Session::checkToken('post'))
		{
			$this->setErrorResponse(Text::_('JINVALID_TOKEN'), [], 403);
		}
	}

	private function createJsChallenge(): array
	{
		$this->checkJsChallengeRequestToken();

		$now        = microtime(true);
		$challenge  = bin2hex(random_bytes(16));
		$seed       = random_int(1, 0x7ffffffe);
		$value      = $seed;
		$operations = [];
		$delayMs    = $this->getJsChallengeDelayMs();

		for ($index = 0; $index < self::JS_CHALLENGE_OPERATIONS; $index++)
		{
			$type = random_int(0, 2);

			if ($type === 0)
			{
				$operand = random_int(1, 0x7ffffffe);
				$value        = ($value + $operand) & 0x7fffffff;
				$operations[] = ['add', $operand];
			}
			elseif ($type === 1)
			{
				$operand = random_int(1, 0x7ffffffe);
				$value        = ($value ^ $operand) & 0x7fffffff;
				$operations[] = ['xor', $operand];
			}
			else
			{
				$operand = random_int(3, 65535) | 1;
				$value        = ($value * $operand) & 0x7fffffff;
				$operations[] = ['mul', $operand];
			}
		}

		$session    = $this->getApplication()->getSession();
		$challenges = $this->limitJsSessionItems(
			$this->getJsSessionItems(self::JS_CHALLENGE_SESSION_KEY, $now)
		);
		$challenges[$challenge] = [
			'proof'         => (string) $value,
			'created_at'    => $now,
			'expires_at'    => $now + self::JS_CHALLENGE_TTL,
			'minimum_delay' => $this->getJsChallengeMinimumDelaySeconds($delayMs)
		];
		$session->set(self::JS_CHALLENGE_SESSION_KEY, $challenges);

		return [
			'challenge'        => $challenge,
			'seed'             => $seed,
			'operations'       => $operations,
			'minimumDelayMs'   => $delayMs,
			'expiresInSeconds' => self::JS_CHALLENGE_TTL
		];
	}

	private function solveJsChallenge(string $challenge, string $proof): array
	{
		$this->checkJsChallengeRequestToken();

		$now        = microtime(true);
		$session    = $this->getApplication()->getSession();
		$challenges = $this->getJsSessionItems(self::JS_CHALLENGE_SESSION_KEY, $now);
		$item       = $challenges[$challenge] ?? null;

		unset($challenges[$challenge]);
		$session->set(self::JS_CHALLENGE_SESSION_KEY, $challenges);

		if (
			!is_array($item)
			|| !ctype_digit($proof)
			|| ($now - (float) $item['created_at']) < (float) ($item['minimum_delay'] ?? $this->getJsChallengeMinimumDelaySeconds())
			|| !hash_equals((string) $item['proof'], $proof)
		)
		{
			$this->setErrorResponse($this->getJsChallengeMessage(), [], 403);
		}

		$permit     = bin2hex(random_bytes(32));
		$permitHash = hash('sha256', $permit);
		$permits    = $this->limitJsSessionItems(
			$this->getJsSessionItems(self::JS_PERMIT_SESSION_KEY, $now)
		);
		$permits[$permitHash] = [
			'created_at' => $now,
			'expires_at' => $now + self::JS_PERMIT_TTL
		];
		$session->set(self::JS_PERMIT_SESSION_KEY, $permits);

		return [
			'permit'           => $permit,
			'expiresInSeconds' => self::JS_PERMIT_TTL
		];
	}

	private function handleJsChallengeRequest(array $request): void
	{
		if (!$this->params->get('js_challenge_enabled', 0))
		{
			$this->setErrorResponse($this->getJsChallengeMessage(), [], 403);
		}

		$action = (string) ($request['action'] ?? '');

		if ($action === 'challenge')
		{
			$this->setResponse($this->createJsChallenge());
		}

		if ($action === 'solve')
		{
			$this->setResponse($this->solveJsChallenge(
				trim((string) ($request['challenge'] ?? '')),
				trim((string) ($request['proof'] ?? ''))
			));
		}

		$this->setErrorResponse($this->getJsChallengeMessage(), [], 400);
	}

	private function consumeJsPermit(string $permit): bool
	{
		if (!$this->params->get('js_challenge_enabled', 0))
		{
			return true;
		}

		$now        = microtime(true);
		$session    = $this->getApplication()->getSession();
		$permits    = $this->getJsSessionItems(self::JS_PERMIT_SESSION_KEY, $now);
		$permitHash = preg_match('/^[a-f0-9]{64}$/D', $permit) ? hash('sha256', $permit) : '';
		$valid      = $permitHash !== '' && isset($permits[$permitHash]);

		if ($valid)
		{
			unset($permits[$permitHash]);
		}

		$session->set(self::JS_PERMIT_SESSION_KEY, $permits);

		return $valid;
	}

    private function getReservedFieldNames(array ...$sources): array
    {
        $reserved = array_fill_keys(self::RESERVED_FIELD_NAMES, true);
        $found    = [];

        foreach ($sources as $source)
        {
            foreach (array_keys($source) as $name)
            {
                if (!is_string($name))
                {
                    continue;
                }

                $normalizedName = strtolower($name);

                if (isset($reserved[$normalizedName]))
                {
                    $found[$normalizedName] = true;
                }
            }
        }

        return array_keys($found);
    }

	private function getAntiSpamMessage()
	{
		$message = trim((string) $this->params->get('antispam_message', ''));

		if ($message === '')
		{
			$message = 'PLG_RADICALFORM_ANTISPAM_BLOCKED';
		}

		return Text::_($message);
	}

	private function isUtmDataValid(array $utmData): bool
	{
		if (empty($utmData['values']) || !is_array($utmData['values']))
		{
			return false;
		}

		$lifetime = max(0, (int) $this->params->get('track_utm_lifetime', 0));

		if ($lifetime === 0)
		{
			return true;
		}

		if (empty($utmData['created_at']))
		{
			return false;
		}

		return (time() - (int) $utmData['created_at']) <= ($lifetime * 60);
	}

	private function getAllowedUtmTags(): array
	{
		$tags = explode(',', (string) $this->params->get('track_utm_allowed_tags', 'utm_source,utm_medium,utm_campaign,utm_term,utm_content'));
		$tags = array_map('trim', $tags);
		$tags = array_filter($tags, function ($tag) {
			return preg_match('/^utm_[a-z0-9_]+$/i', $tag);
		});

		return array_values(array_unique($tags));
	}

	private function normalizeBeforeSendRejection($result)
	{
		if (is_object($result))
		{
			$result = (array) $result;
		}

		if (!is_array($result))
		{
			return null;
		}

		if (!array_key_exists('send', $result))
		{
			$isList = $result === [] || array_keys($result) === range(0, count($result) - 1);

			if ($isList)
			{
				foreach ($result as $item)
				{
					$rejection = $this->normalizeBeforeSendRejection($item);

					if ($rejection !== null)
					{
						return $rejection;
					}
				}
			}

			return null;
		}

		if ($result['send'] !== false)
		{
			return null;
		}

		$message = isset($result['message']) ? trim((string) $result['message']) : '';

		if ($message === '')
		{
			$message = 'PLG_RADICALFORM_PLUGIN_SEND_REJECTED';
		}

		$fields = [];

		if (isset($result['field']) && is_string($result['field']) && trim($result['field']) !== '')
		{
			$fields[] = trim($result['field']);
		}

		if (isset($result['fields']) && is_array($result['fields']))
		{
			foreach ($result['fields'] as $field)
			{
				if (is_string($field) && trim($field) !== '')
				{
					$fields[] = trim($field);
				}
			}
		}

		return [
			'message' => Text::_($message),
			'fields'  => array_values(array_unique($fields))
		];
	}

	private function splitAntiSpamLines($text)
	{
		$lines = preg_split("/\r\n|\n|\r/", (string) $text);

		if (!is_array($lines))
		{
			return [];
		}

		return $lines;
	}

	private function normalizeAntiSpamValue($value)
	{
		if (is_array($value))
		{
			$value = implode(', ', $value);
		}

		$value = trim(strip_tags((string) $value));

		return preg_replace('/\s+/u', ' ', $value);
	}

	private function buildAntiSpamPayload(array $input)
	{
		$payload = [];

		foreach ($this->clearInput($input) as $key => $value)
		{
			$normalized = $this->normalizeAntiSpamValue($value);

			if ($normalized !== '')
			{
				$payload[$key] = $normalized;
			}
		}

		if (isset($input['rfUserAgent']))
		{
			$normalized = $this->normalizeAntiSpamValue($input['rfUserAgent']);

			if ($normalized !== '')
			{
				$payload['rfUserAgent'] = $normalized;
			}
		}

		return $payload;
	}

	private function containsMatch($haystack, $needle)
	{
		if (function_exists('mb_stripos'))
		{
			return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
		}

		return stripos($haystack, $needle) !== false;
	}

	private function isValidIpv4($ip)
	{
		return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
	}

	private function ipv4ToBits($ip)
	{
		$packed = @inet_pton($ip);

		if ($packed === false || strlen($packed) !== 4)
		{
			return false;
		}

		$bits = '';

		for ($i = 0; $i < 4; $i++)
		{
			$bits .= str_pad(decbin(ord($packed[$i])), 8, '0', STR_PAD_LEFT);
		}

		return $bits;
	}

	private function isIpInIpv4Cidr($ip, $cidr)
	{
		$parts = explode('/', $cidr, 2);

		if (count($parts) !== 2)
		{
			return false;
		}

		$network = trim($parts[0]);
		$prefix  = trim($parts[1]);

		if (!$this->isValidIpv4($ip) || !$this->isValidIpv4($network) || !is_numeric($prefix))
		{
			return false;
		}

		$prefix = (int) $prefix;

		if ($prefix < 0 || $prefix > 32)
		{
			return false;
		}

		$ipBits      = $this->ipv4ToBits($ip);
		$networkBits = $this->ipv4ToBits($network);

		if ($ipBits === false || $networkBits === false)
		{
			return false;
		}

		return substr($ipBits, 0, $prefix) === substr($networkBits, 0, $prefix);
	}

	private function isBlacklistedIp($ip, $blacklist)
	{
		if (!$this->isValidIpv4($ip))
		{
			return false;
		}

		foreach ($this->splitAntiSpamLines($blacklist) as $line)
		{
			$line = trim($line);

			if ($line === '' || strpos($line, '#') === 0)
			{
				continue;
			}

			if (strpos($line, '/') !== false)
			{
				if ($this->isIpInIpv4Cidr($ip, $line))
				{
					return true;
				}

				continue;
			}

			if ($ip === $line)
			{
				return true;
			}
		}

		return false;
	}

	private function getContentRuleFields($fields)
	{
		$fields = trim((string) $fields);

		if ($fields === '' || $fields === '*')
		{
			return [];
		}

		$result = [];

		foreach (explode(',', $fields) as $field)
		{
			$field = trim($field);

			if ($field !== '')
			{
				$result[] = $field;
			}
		}

		return $result;
	}

	private function matchesContentRule(array $payload, array $rule)
	{
		$pattern = isset($rule['pattern']) ? trim((string) $rule['pattern']) : '';
		$mode    = isset($rule['mode']) ? trim((string) $rule['mode']) : 'contains';
		$fields  = isset($rule['fields']) ? $this->getContentRuleFields($rule['fields']) : [];

		if ($pattern === '')
		{
			return false;
		}

		foreach ($payload as $fieldName => $fieldValue)
		{
			if (!empty($fields) && !in_array($fieldName, $fields, true))
			{
				continue;
			}

			if ($mode === 'regex')
			{
				$match = @preg_match($pattern, $fieldValue);

				if ($match === 1)
				{
					return $fieldName;
				}

				continue;
			}

			if ($this->containsMatch($fieldValue, $pattern))
			{
				return $fieldName;
			}
		}

		return false;
	}

	private function checkAntiSpam(array $input)
	{
		$durationRanges = trim((string) $this->params->get('duration_range', ''));

		if ($durationRanges !== '')
		{
			if (!isset($input['rf-duration']) || !is_numeric($input['rf-duration']))
			{
				return 'duration';
			}

			$duration = (float) $input['rf-duration'];

			foreach (explode(',', $durationRanges) as $range)
			{
				$range = trim($range);

				if ($range === '')
				{
					continue;
				}

				if (!preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*-\s*(-?\d+(?:\.\d+)?)\s*$/', $range, $matches))
				{
					continue;
				}

				$min = (float) $matches[1];
				$max = (float) $matches[2];

				if ($min > $max)
				{
					$temp = $min;
					$min  = $max;
					$max  = $temp;
				}

				if ($duration >= $min && $duration <= $max)
				{
					return 'duration';
				}
			}
		}

		$ipBlacklist = trim((string) $this->params->get('ip_blacklist', ''));

		if ($ipBlacklist !== '')
		{
			$ip = trim((string) $this->getApplication()->getInput()->server->get('REMOTE_ADDR', ''));

			if ($this->isBlacklistedIp($ip, $ipBlacklist))
			{
				return 'ip';
			}
		}

		$contentRules = (array) $this->params->get('content_rules');

		if (!empty($contentRules))
		{
			$payload = $this->buildAntiSpamPayload($input);

			foreach ($contentRules as $rule)
			{
				$rule = (array) $rule;
				$fieldName = $this->matchesContentRule($payload, $rule);

				if ($fieldName !== false)
				{
					return 'content (' . $fieldName . ')';
				}
			}
		}

		return false;
	}

	private function logAntiSpamBlock(array $input, $reason)
	{
        $input['rfAntiSpam'] = $reason;
        $this->logSpamBlock($input);
	}

    private function logSpamBlock(array $entry, bool $trustedAntiFlood = false): bool
    {
        if (!$trustedAntiFlood)
        {
            foreach (array_keys($entry) as $key)
            {
                if (is_string($key) && str_starts_with(strtolower($key), 'rfantiflood'))
                {
                    unset($entry[$key]);
                }
            }
        }

        $entry = $this->prepareSpamLogEntry($entry);
        $logPath = (string) $this->getApplication()->get('log_path');

        return RadicalFormHelper::writeSpamLog($logPath, (int) $this->params->get('maxlogfile', 500000), function (bool $rotated) use ($entry): bool {
            try
            {
                if ($rotated)
                {
                    Log::add(json_encode(['message' => Text::_('PLG_RADICALFORM_ROTATE_HISTORY_BY_MAX_LOG')]), Log::NOTICE, 'plg_system_radicalform_spam');
                }
                Log::add($this->encodeSpamLogEntry($entry), Log::WARNING, 'plg_system_radicalform_spam');
                return true;
            }
            catch (\RuntimeException)
            {
                return false;
            }
        });
    }

	private function prepareSpamLogEntry(array $entry): array
	{
		$reservedFields = array_fill_keys(self::RESERVED_FIELD_NAMES, true);
		$serviceFields  = [
			'rfjsaction'     => true,
			'rfjschallenge'  => true,
			'rfjsproof'      => true,
			'rfjspermit'     => true,
			'rflogtruncated' => true,
		];

		foreach ($entry as $key => $value)
		{
			if (!is_string($key))
			{
				$entry[$key] = $this->truncateSpamLogValue($value);
				continue;
			}

			$normalizedKey = strtolower($key);

			if (
				$key === 'uniq'
				|| preg_match('/^[a-f0-9]{32}$/iD', $key)
				|| isset($reservedFields[$normalizedKey])
				|| isset($serviceFields[$normalizedKey])
			)
			{
				unset($entry[$key]);
				continue;
			}

			$entry[$key] = $this->truncateSpamLogValue($value);
		}

		$entry = array_filter($entry, function ($value) {
			return $value !== '';
		});

		return $this->limitSpamLogEntrySize($entry);
	}

	private function truncateSpamLogValue($value)
	{
		if (is_array($value))
		{
			foreach ($value as $key => $item)
			{
				$value[$key] = $this->truncateSpamLogValue($item);
			}

			return $value;
		}

		if (is_object($value))
		{
			return $this->truncateSpamLogValue((array) $value);
		}

		if (!is_string($value) || StringHelper::strlen($value) <= self::SPAM_LOG_VALUE_MAX_LENGTH)
		{
			return $value;
		}

		return StringHelper::substr($value, 0, self::SPAM_LOG_VALUE_MAX_LENGTH) . '…';
	}

	private function limitSpamLogEntrySize(array $entry): array
	{
		if (strlen($this->encodeSpamLogEntry($entry)) <= self::SPAM_LOG_ENTRY_MAX_BYTES)
		{
			return $entry;
		}

		$limitedEntry = ['rfLogTruncated' => true];
		$orderedEntry = [];

		foreach (['rfAntiSpam', 'rfWarningMessage'] as $priorityField)
		{
			if (array_key_exists($priorityField, $entry))
			{
				$orderedEntry[$priorityField] = $entry[$priorityField];
				unset($entry[$priorityField]);
			}
		}

		$orderedEntry += $entry;
		$encodedSize  = strlen($this->encodeSpamLogEntry($limitedEntry));

		foreach ($orderedEntry as $key => $value)
		{
			$encodedKey   = json_encode((string) $key, JSON_INVALID_UTF8_SUBSTITUTE);
			$encodedValue = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

			if (!is_string($encodedKey) || !is_string($encodedValue))
			{
				continue;
			}

			$additionalSize = 1 + strlen($encodedKey) + 1 + strlen($encodedValue);

			if ($encodedSize + $additionalSize <= self::SPAM_LOG_ENTRY_MAX_BYTES)
			{
				$limitedEntry[$key] = $value;
				$encodedSize       += $additionalSize;
			}
		}

		return $limitedEntry;
	}

	private function encodeSpamLogEntry(array $entry): string
	{
		$encoded = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);

		return is_string($encoded) ? $encoded : '{"rfLogTruncated":true}';
	}

	private function getAntiFloodMessage(): string
	{
		$message = trim((string) $this->params->get('antiflood_message', ''));

		if ($message === '')
		{
			$message = 'PLG_RADICALFORM_ANTIFLOOD_BLOCKED';
		}

		return Text::_($message);
	}

	private function getLogFilesByRecency(string $baseFileName): array
	{
		$logPath = str_replace('\\', '/', $this->getApplication()->get('log_path'));
		$files   = [];

		if (file_exists($logPath . '/' . $baseFileName))
		{
			$files[0] = $logPath . '/' . $baseFileName;
		}

		foreach (glob($logPath . '/*.' . $baseFileName) as $filename)
		{
			$page = strstr(basename($filename), '.', true);

			if ($page === false || !ctype_digit($page))
			{
				continue;
			}

			$files[(int) $page] = $filename;
		}

		ksort($files, SORT_NUMERIC);

		return array_values($files);
	}

	private function getLogTimestamp($value): ?int
	{
		$value = trim((string) $value);

		if ($value === '')
		{
			return null;
		}

		try
		{
			return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	private function isSentFormLogEntry($entry): bool
	{
		if (!is_array($entry) || !array_key_exists('rfLatestNumber', $entry))
		{
			return false;
		}

		$submissionFields = array_diff(array_keys($entry), ['rfLatestNumber', 'message', 'rfWarningMessage']);

		return $submissionFields !== [];
	}

	private function countSentForms(?int $burstStartedAt, ?int $dayStartedAt): array
	{
		$counts = [
			'burst' => 0,
			'daily' => 0
		];
		$cutoffs = array_filter([$burstStartedAt, $dayStartedAt], function ($value) {
			return $value !== null;
		});

		if ($cutoffs === [])
		{
			return $counts;
		}

		$oldestCutoff = min($cutoffs);

		foreach ($this->getLogFilesByRecency('plg_system_radicalform.php') as $file)
		{
			$newestTimestamp = null;

			foreach (RadicalFormHelper::getCSV($file, "\t") as $record)
			{
				if (count($record) < 3 || str_starts_with((string) $record[0], '#'))
				{
					continue;
				}

				$timestamp = $this->getLogTimestamp($record[0]);

				if ($timestamp === null)
				{
					continue;
				}

				$newestTimestamp = $newestTimestamp === null ? $timestamp : max($newestTimestamp, $timestamp);

				if ($timestamp < $oldestCutoff)
				{
					continue;
				}

				$entry = json_decode($record[2], true);

				if (!$this->isSentFormLogEntry($entry))
				{
					continue;
				}

				if ($burstStartedAt !== null && $timestamp >= $burstStartedAt)
				{
					$counts['burst']++;
				}

				if ($dayStartedAt !== null && $timestamp >= $dayStartedAt)
				{
					$counts['daily']++;
				}
			}

			if ($newestTimestamp !== null && $newestTimestamp < $oldestCutoff)
			{
				break;
			}
		}

		return $counts;
	}

	private function getSiteTimezone(): \DateTimeZone
	{
		try
		{
			return new \DateTimeZone((string) $this->getApplication()->get('offset', 'UTC'));
		}
		catch (\Throwable)
		{
			return new \DateTimeZone('UTC');
		}
	}

	private function formatAntiFloodDate(int $timestamp, string $format = DATE_ATOM): string
	{
		return (new \DateTimeImmutable('@' . $timestamp))
			->setTimezone($this->getSiteTimezone())
			->format($format);
	}

	private function sendAntiFloodAlert(array $block): void
	{
		$email = trim((string) $this->params->get('antiflood_alert_email', ''));

		if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false)
		{
			return;
		}

		$typeLabel = $block['type'] === 'daily'
			? Text::_('PLG_RADICALFORM_DAILY_BLOCKING')
			: Text::_('PLG_RADICALFORM_BURST_BLOCKING');
		$periodLabel = $block['type'] === 'daily'
			? Text::_('PLG_RADICALFORM_CURRENT_CALENDAR_DAY')
			: Text::sprintf('PLG_RADICALFORM_MINUTES', $block['period_minutes']);
		$site = Uri::root();
		$host = parse_url($site, PHP_URL_HOST) ?: $site;

		try
		{
			$mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
			$mailer->setSender([
				$this->getApplication()->get('mailfrom'),
				$this->getApplication()->get('fromname')
			]);
			$mailer->addRecipient($email);
			$mailer->setSubject(Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_SUBJECT', $host));
			$mailer->isHtml(false);
			$mailer->setBody(implode("\n", [
				Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_TYPE', $typeLabel),
				Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_COUNT', $block['count']),
				Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_LIMIT', $block['limit']),
				Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_PERIOD', $periodLabel),
				Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_STARTED', $this->formatAntiFloodDate($block['started_at'], 'd.m.Y H:i:s')),
				Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_UNTIL', $this->formatAntiFloodDate($block['blocked_until'], 'd.m.Y H:i:s')),
				Text::sprintf('PLG_RADICALFORM_ANTIFLOOD_ALERT_SITE', $site)
			]));

			if ($mailer->send() === false)
			{
				throw new \RuntimeException(Text::_('PLG_RADICALFORM_ANTIFLOOD_ALERT_SEND_FAILED'));
			}
		}
		catch (\Throwable $e)
		{
			$this->logSpamBlock([
				'rfAntiSpam'            => $typeLabel,
				'rfAntiFloodAlertError' => $e->getMessage()
            ], true);
		}
	}

    private function getAntiFloodLogEntry(array $block): array
    {
        return [
            'rfAntiSpam'               => $block['type'] === 'daily'
                ? Text::_('PLG_RADICALFORM_DAILY_BLOCKING')
                : Text::_('PLG_RADICALFORM_BURST_BLOCKING'),
            'rfAntiFlood'              => $block['type'],
            'rfAntiFloodCount'         => $block['count'],
            'rfAntiFloodLimit'         => $block['limit'],
            'rfAntiFloodPeriodMinutes' => $block['period_minutes'],
            'rfAntiFloodStartedAt'     => $this->formatAntiFloodDate($block['started_at']),
            'rfAntiFloodBlockedUntil'  => $this->formatAntiFloodDate($block['blocked_until']),
            'rfWarningMessage'         => Text::_('PLG_RADICALFORM_ANTIFLOOD_TRIGGERED')
        ];
    }

	private function checkAntiFlood(): ?array
	{
		$burstEnabled = (bool) $this->params->get('burst_block_enabled', 0);
		$dailyEnabled = (bool) $this->params->get('daily_block_enabled', 0);

		if (!$burstEnabled && !$dailyEnabled)
		{
			return null;
		}

		$burstLimit       = max(1, (int) $this->params->get('burst_max_submissions', 30));
		$observationMins  = max(1, (int) $this->params->get('burst_observation_minutes', 10));
		$blockMinutes     = max(1, (int) $this->params->get('burst_block_minutes', 60));
		$dailyLimit       = max(1, (int) $this->params->get('daily_max_submissions', 200));
		$nowDate          = new \DateTimeImmutable('now', $this->getSiteTimezone());
		$now              = $nowDate->getTimestamp();
		$dayStartedAt     = $nowDate->setTime(0, 0)->getTimestamp();
        $logPath = (string) $this->getApplication()->get('log_path');
        // Keep applying the configured thresholds if the saved state is unavailable.
        $blocks = RadicalFormHelper::getAntiFloodBlocks($logPath) ?? [];
        $activeBlock = null;
        foreach (['burst' => $burstEnabled, 'daily' => $dailyEnabled] as $type => $enabled)
        {
            $candidate = $blocks[$type] ?? null;
            if ($enabled && $candidate !== null && $candidate['blocked_until'] > ($activeBlock['blocked_until'] ?? $now))
            {
                $activeBlock = $candidate;
            }
        }
        if ($activeBlock !== null)
        {
            return $activeBlock;
        }

		$burstStartedAt = $burstEnabled ? $now - ($observationMins * 60) : null;
		$counts         = $this->countSentForms($burstStartedAt, $dailyEnabled ? $dayStartedAt : null);
		$block          = null;

		if ($dailyEnabled && $counts['daily'] >= $dailyLimit)
		{
			$block = [
				'type'           => 'daily',
				'count'          => $counts['daily'],
				'limit'          => $dailyLimit,
				'period_minutes' => 1440,
				'started_at'     => $now,
				'blocked_until'  => $nowDate->modify('+1 day')->setTime(0, 0)->getTimestamp()
			];
		}
		elseif ($burstEnabled && $counts['burst'] >= $burstLimit)
		{
			$block = [
				'type'           => 'burst',
				'count'          => $counts['burst'],
				'limit'          => $burstLimit,
				'period_minutes' => $observationMins,
				'started_at'     => $now,
				'blocked_until'  => $now + ($blockMinutes * 60)
			];
		}

        if ($block !== null && RadicalFormHelper::activateAntiFloodBlock($logPath, $block))
        {
            $this->logSpamBlock($this->getAntiFloodLogEntry($block), true);
            $this->sendAntiFloodAlert($block);
        }

		return $block;
	}

	private function rotateLogFile(string $baseFileName)
	{
		$logPath = str_replace('\\', '/', $this->getApplication()->get('log_path'));
		$files   = [];

		if (file_exists($logPath . '/' . $baseFileName))
		{
			$files[0] = $baseFileName;
		}

		foreach (glob($logPath . '/*.' . $baseFileName) as $filename)
		{
			$file = basename($filename);
			$page = strstr($file, '.', true);

			if ($page === false || !is_numeric($page))
			{
				continue;
			}

			$files[(int) $page] = $file;
		}

		krsort($files, SORT_NUMERIC);

		foreach ($files as $version => $file)
		{
			$rotatedFile = $version === 0
				? '1.' . $baseFileName
				: ($version + 1) . substr($file, strpos($file, '.'));

			try
			{
				File::move($logPath . '/' . $file, $logPath . '/' . $rotatedFile);
			}
			catch (\Throwable)
			{
			}
		}
	}

	private function getHistoryLogType(array $get): string
	{
		return isset($get['log']) && $get['log'] === 'spam' ? 'spam' : 'messages';
	}

	private function getHistoryLogCategory(string $logType): string
	{
		return $logType === 'spam' ? 'plg_system_radicalform_spam' : 'plg_system_radicalform';
	}

	/**
	 * Listener for the `onAfterRender` event.
	 *
	 * @throws  \Exception
	 *
	 * @since  1.0.0
	 */
	public function onAfterRender()
	{
		if ($this->getApplication()->isClient('administrator'))
		{
			return false;
		}

		// для всяких модальных окон, очищенных от постороннего мусора. себя мы тоже не выводим
		$input = $this->getApplication()->getInput();
		$data  = $input->getArray();
		if (isset($data['tmpl']) && $data['tmpl'] === 'component')
		{
			return false;
		}

		$body  = $this->getApplication()->getBody();
		$lnEnd = $this->getApplication()->getDocument()->_getLineEnd();

		if (strpos($body, 'rf-button-send') !== false)
		{
			$mtime       = filemtime(JPATH_SITE . HTMLHelper::_('script', 'plg_system_radicalform/script.min.js', ['relative' => true, 'pathOnly' => true]));
			$session     = $this->getApplication()->getSession();
			$lifeTime    = $session->getExpire();
			$refreshTime = $lifeTime <= 60 ? 45 : $lifeTime - 60;

			// The longest refresh period is one hour to prevent integer overflow.
			if ($refreshTime > 3600 || $refreshTime <= 0)
			{
				$refreshTime = 3600;
			}
			$jsParams = array(
				'DangerClass'         => $this->params->get('dangerclass'),
				'ErrorFile'           => $this->params->get('errorfile'),
				'thisFilesWillBeSend' => Text::_('PLG_RADICALFORM_THIS_FILES_WILL_BE_SEND'),
				'UploadDisabled'      => Text::_('PLG_RADICALFORM_UPLOAD_DISABLED'),
				'UploadEnabled'       => $this->params->get('uploadenabled', 0),
				'waitingForUpload'    => $this->params->get('waitingupload'),
				'WaitMessage'         => $this->params->get('rfWaitMessage'),
				'ErrorMax'            => Text::_('PLG_RADICALFORM_FILE_TO_LARGE_THAN_PHP_INI_ALLOWS'),
				'MaxSize'             => min($this->return_bytes(ini_get('post_max_size')),
					$this->return_bytes(ini_get("upload_max_filesize"))),
				'Base'                => Uri::base(true),
				'AfterSend'           => $this->params->get('aftersend'),
				'Jivosite'            => $this->params->get('jivosite'),
				'Verbox'              => $this->params->get('verbox'),
				'Subject'             => $this->params->get('rfSubject'),
				'KeepAlive'           => $this->params->get('keepalive'),
				'TokenExpire'         => $refreshTime * 1000,
                'DeleteFile'          => Text::_('JACTION_DELETE'),
				'DeleteColor'         => $this->params->get('buttondeletecolor', "#fafafa"),
				'DeleteBackground'    => $this->params->get('buttondeletecolorbackground', "#f44336"),
				'JsChallengeEnabled'  => (int) $this->params->get('js_challenge_enabled', 0),
				'JsChallengeMessage'  => $this->getJsChallengeMessage(),
                'ReservedFieldNames'   => self::RESERVED_FIELD_NAMES,
                'ReservedFieldMessage' => Text::_('PLG_RADICALFORM_RESERVED_FIELD_NAMES')
			);
			if ($this->params->get('insertip'))
			{
				$jsParams['IP'] = json_encode(array('ip' => $input->server->get('REMOTE_ADDR')));
			}
			$js = "<script src=\"" . HTMLHelper::_('script', 'plg_system_radicalform/script.min.js', ['relative' => true, 'pathOnly' => true]) . "?$mtime\" async></script>" . $lnEnd
				. "<script>"
				. "var RadicalForm=" . json_encode($jsParams) . ";";

			if (!empty($this->params->get('rfCall_0')))
			{
				$js .= "function rfCall_0(here, needReturn) { try { " . $this->params->get('rfCall_0') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
			}

			if (!empty($this->params->get('rfCall_1')))
			{
				$js .= "function rfCall_1(rfMessage, here) { try { " . $this->params->get('rfCall_1') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
			}

			if (!empty($this->params->get('rfCall_2')))
			{
				$js .= "function rfCall_2(rfMessage, here) { try { " . $this->params->get('rfCall_2') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
			}

			if (!empty($this->params->get('rfCall_3')))
			{
				$js .= "function rfCall_3(rfMessage, here) { try { " . $this->params->get('rfCall_3') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
			}

			if (!empty($this->params->get('rfCall_9on')))
			{
				// here we have individual code for rfCall_9
				$js .= "function rfCall_9(rfMessage, here) { try { " . $this->params->get('rfCall_9') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
			}
			else
			{
				// here we set standard output
				if (!empty($this->params->get('rfCall_2')))
				{
					$js .= "function rfCall_9(rfMessage, here) { try { " . $this->params->get('rfCall_2') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
				}
				elseif (!empty($this->params->get('rfCall_1')))
				{
					$js .= "function rfCall_9(rfMessage, here) { try { " . $this->params->get('rfCall_1') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
				}
				elseif (!empty($this->params->get('rfCall_3')))
				{
					$js .= "function rfCall_9(rfMessage, here) { try { " . $this->params->get('rfCall_3') . " } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
				}
				else
				{
					$js .= "function rfCall_9(rfMessage, here) { try { alert(rfMessage); } catch (e) { console.error('Radical Form JS Code: ', e); } }; ";
				}
			}
			$js .= " </script>" . $lnEnd;

			$body = str_replace("</body>", $js . "</body>", $body);
			$this->getApplication()->setBody($body);
		}
		else
		{
			if ($this->params->get('insertip'))
			{
				$js   = "<script>"
					. "var RadicalForm={"
					. "IP:{ip: '" . $input->server->get('REMOTE_ADDR') . "'} "
					. "}; </script>";
				$body = str_replace("</body>", $js . "</body>", $body);
				$this->getApplication()->setBody($body);
			}
		}

		return true;
	}

	/**
	 * Method for get directory size
	 *
	 * @param $path
	 *
	 * Directory size
	 *
	 * @return int
	 *
	 * @since version
	 */
    public function getDirectorySize($path)
    {
        $fileSize = 0;

        foreach (@scandir($path) ?: [] as $file)
        {
            $child = $path . '/' . $file;
            if ($file === '.' || $file === '..' || is_link($child))
            {
                continue;
            }

            $fileSize += is_dir($child) ? $this->getDirectorySize($child) : (@filesize($child) ?: 0);
        }

        return $fileSize;
    }

    private function getUploadStorage(): ?UploadStorage
    {
        return UploadStorage::open((string) $this->params->get('uploadstorage', ''));
    }

    private function beginExternalUploadMutation(UploadStorage $storage, string $group): ?\Closure
    {
        try
        {
            return $storage->beginGroupMutation($group);
        }
        catch (\Throwable)
        {
            try
            {
                return $storage->beginUntrackedMutation();
            }
            catch (\Throwable $error)
            {
                $this->logUploadAccountingError($error);
            }

            return null;
        }
    }

    private function finishExternalUploadMutation(?\Closure $finish): void
    {
        try
        {
            $finish?->__invoke();
        }
        catch (\Throwable $error)
        {
            $this->logUploadAccountingError($error);
        }
    }

    private function logUploadAccountingError(\Throwable $error): void
    {
        try
        {
            Log::add(json_encode([
                'rfWarningMessage' => 'RadicalForm upload accounting requires reconciliation',
                'error' => $error->getMessage(),
            ], JSON_INVALID_UTF8_SUBSTITUTE), Log::WARNING, 'plg_system_radicalform');
        }
        catch (\Throwable)
        {
            // Accounting diagnostics must not interrupt a submission.
        }
    }

    private function getUploadGroup(mixed $uniq): ?string
    {
        if ((!is_string($uniq) && !is_int($uniq)) || !preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $uniq))
        {
            return null;
        }

        // The browser ID is a correlation key. The directory belongs to the Joomla session.
        $sessionToken = $this->getApplication()->getSession()->getToken();

        return 'v2-' . hash_hmac('sha256', 'radicalform.upload.' . $uniq, $sessionToken);
    }

    private function processUploadedFiles(array $files, string $group): array
    {
        if ($files === [])
        {
            return [];
        }

        $storage = $this->getUploadStorage();
        if ($storage === null)
        {
            return ['error' => Text::_('PLG_RADICALFORM_ERROR_UPLOAD')];
        }

        $days = max(0, (int) $this->maxStorageTime);
        $retention = $days > intdiv(PHP_INT_MAX, 86400) ? PHP_INT_MAX : $days * 86400;
        $app = $this->getApplication();
        $address = $app->getInput()->server->get('REMOTE_ADDR', '', 'raw');
        $packedAddress = is_string($address) ? @inet_pton($address) : false;
        if ($packedAddress !== false && str_starts_with($packedAddress, str_repeat("\0", 10) . "\xff\xff"))
        {
            $packedAddress = substr($packedAddress, 12);
        }
        $client = hash_hmac('sha256', $packedAddress === false ? '' : substr($packedAddress, 0, 8), (string) $app->get('secret', ''));

        try
        {
            if (!$storage->admitUpload($client, count($files)))
            {
                return ['error' => Text::_('PLG_RADICALFORM_UPLOAD_RATE_LIMIT')];
            }

            $output = [];
            $preparedFiles = $this->prepareUploadedFiles($files, $output);
            if ($preparedFiles === [])
            {
                return $output;
            }

            return $storage->uploadBatch($group, $retention, fn () => $this->storeUploadedFiles($storage, $group, $preparedFiles, $output));
        }
        catch (\RuntimeException)
        {
            return ['error' => Text::_('PLG_RADICALFORM_ERROR_UPLOAD')];
        }
    }

    private function prepareUploadedFiles(array $files, array &$output): array
    {
        $preparedFiles = [];
        $language = $this->getApplication()->getLanguage();

        foreach ($files as $key => $file)
        {
            $key = $this->makeSafe(basename((string) $key));
            if (!UploadStorage::isSegment($key) || !isset($file['error']) || $file['error'] == UPLOAD_ERR_NO_FILE)
            {
                continue;
            }

            if ($file['error'])
            {
                $message = match ((int) $file['error']) {
                    UPLOAD_ERR_INI_SIZE => 'PLG_RADICALFORM_FILE_TO_LARGE_THAN_PHP_INI_ALLOWS',
                    UPLOAD_ERR_FORM_SIZE => 'PLG_RADICALFORM_FILE_TO_LARGE_THAN_HTML_FORM_ALLOWS',
                    UPLOAD_ERR_PARTIAL => 'PLG_RADICALFORM_ERROR_PARTIAL_UPLOAD',
                    default => null,
                };
                if ($message !== null)
                {
                    $output['error'] = Text::_($message);
                }
                continue;
            }

            if (!is_string($file['name'] ?? null) || $file['name'] === '' || !is_string($file['tmp_name'] ?? null)
                || !is_uploaded_file($file['tmp_name']) || !InputFilter::isSafeFile($file))
            {
                $output['error'] = Text::_('PLG_RADICALFORM_ERROR_WRONG_TYPE');
                continue;
            }

            $name = $this->makeSafe($language->transliterate($file['name']));
            $size = @filesize($file['tmp_name']);
            if (!UploadStorage::isSegment($name) || $size === false)
            {
                $output['error'] = Text::_('PLG_RADICALFORM_ERROR_UPLOAD');
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $expectedMimeTypes = self::IMAGE_UPLOAD_MIME_TYPES[$extension] ?? null;
            $mime = $size > 0 ? $this->mimetype($file['tmp_name']) : false;
            if (!$mime || str_starts_with($mime, 'text/') || str_contains($mime, 'svg')
                || $extension === 'svg' || $extension === 'svgz'
                || ($expectedMimeTypes !== null && !in_array($mime, $expectedMimeTypes, true)))
            {
                $output['error'] = Text::_('PLG_RADICALFORM_ERROR_WRONG_TYPE');
                continue;
            }

            $preparedFiles[] = ['field' => $key, 'name' => $name, 'source' => $file['tmp_name'], 'size' => $size];
        }

        return $preparedFiles;
    }

    private function storeUploadedFiles(UploadStorage $storage, string $group, array $files, array $output): array
    {
        foreach ($files as $file)
        {
            $saved = $storage->store($group, $file['field'], $file['name'], $file['source'], $file['size'], $this->maxStorageSize);
            if ($saved !== true)
            {
                $output['error'] = Text::_($saved === null ? 'PLG_RADICALFORM_TOO_MANY_UPLOADS' : 'PLG_RADICALFORM_ERROR_UPLOAD');
                continue;
            }

            $output['name'] = $file['name'];
            $output['key'] = $file['field'];
        }

        return $output;
    }

	/**
	 * OnAfterInitialise event
	 * Обрабатываем пути для выкачки файлов
	 *
	 * @since  1.0.0
	 */
	public function onAfterInitialise()
	{
		// Collect UTM parameters from query string and store in session
		if ($this->params->get('track_utm', 0))
		{
			$input   = $this->getApplication()->getInput();
			$session = $this->getApplication()->getSession();
			$values  = [];

			foreach ($this->getAllowedUtmTags() as $utm)
			{
				$value = $input->getString($utm);
				if ($value)
				{
					$values[$utm] = $value;
				}
			}

			if ($values)
			{
				$session->set(self::UTM_SESSION_KEY, [
					'created_at' => time(),
					'values'     => $values
				]);
			}
		}

        $downloadPath = $this->getDownloadPath();
        if ($downloadPath === '')
        {
            return;
        }

        $uri = Uri::getInstance();
        $path = $uri->getPath();
        $prefix = rtrim(Uri::root(true), '/') . '/' . $downloadPath . '/';
        if (!str_starts_with($path, $prefix))
        {
            return;
        }

        $segments = explode('/', substr($path, strlen($prefix)));
        if (count($segments) === 3)
        {
            [$group, $folder, $name] = $segments;
            $this->serveUploadedFile($group, $folder, $name, $uri->getVar('sig'));
        }

        $this->renderFileNotFound();
        $this->getApplication()->close(200);
	}

    private function getDownloadPath(): string
    {
        return trim((string) $this->params->get('downloadpath', ''), '/');
    }

    private function getDownloadSignature(string $group, string $folder, string $name): ?string
    {
        $secret = (string) $this->getApplication()->get('secret', '');

        return $secret !== '' ? hash_hmac('sha256', "radicalform.download/$group/$folder/$name", $secret) : null;
    }

	/**
	 * Check file mime type and return it
	 *
	 * @param   string  $filepath
	 *
	 * @return mixed|string
	 *
	 * @since 1.0.0
	 */
	private function mimetype(string $filepath)
	{
		if (function_exists('finfo_open'))
		{
			$finfo    = finfo_open(FILEINFO_MIME_TYPE);
			$mimetype = finfo_file($finfo, $filepath);
		}
		else
		{
			$mimetype = mime_content_type($filepath);
		}

		return $mimetype;
	}

    private function serveUploadedFile(string $group, string $folder, string $name, mixed $signature): void
    {
        if (!preg_match('/\A' . UploadStorage::GROUP_PATTERN . '\z/', $group)
            || !UploadStorage::isSegment($folder) || !UploadStorage::isSegment($name))
        {
            return;
        }

        // Numeric groups predate signatures. Keep their already-issued links readable.
        if (str_starts_with($group, 'v2-'))
        {
            $expected = $this->getDownloadSignature($group, $folder, $name);
            if ($expected === null || !is_string($signature) || !hash_equals($expected, $signature))
            {
                return;
            }
        }

        $storage = $this->getUploadStorage();
        $directory = $storage?->directory(['rf-' . $group, $folder]);
        $filepath = $directory !== null ? $storage->file($directory, $name) : null;
        if ($filepath === null)
        {
            return;
        }

        $mime = $this->mimetype($filepath);
        if (!$mime || str_starts_with($mime, 'text/') || str_contains($mime, 'svg'))
        {
            return;
        }

        $stream = @fopen($filepath, 'rb');
        if ($stream === false)
        {
            return;
        }

        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Expires: 0');
        header('Cache-Control: private, no-store');
        $stat = fstat($stream);
        if ($stat !== false)
        {
            header('Content-Length: ' . $stat['size']);
        }
        fpassthru($stream);
        fclose($stream);
        $this->getApplication()->close(200);
    }

	/**
	 * Delete uploaded by user file
	 *
	 * @param $name  - name of the file to delete
	 * @param $uniq  - uniq id for current form
	 *
	 * @return string - status of deleting file
	 *
	 * @since 1.0.0
	 */
	public function deleteUploadedFile($catalog, $name, $uniq)
	{
        $group = $this->getUploadGroup($uniq);
        if ($group === null || !is_string($catalog) || !is_string($name))
        {
            return 'ok';
        }

        try
        {
            $removed = $this->getUploadStorage()?->delete($group, $catalog, $name) ?? false;
        }
        catch (\RuntimeException)
        {
            $removed = false;
        }

        return $removed ? 'ok' : Text::_('PLG_RADICALFORM_ERROR_UPLOAD');
	}

	/**
	 * Render image for 404 and error file
	 *
	 * @since
	 */
	public function renderFileNotFound()
	{
		$filenotfound = JPATH_ROOT . HTMLHelper::_('image', 'plg_system_radicalform/filenotfound.svg', '', null, true, 1);
		header('HTTP/1.1 404 Not Found');
		header("Content-Type: " . $this->mimetype($filenotfound));
		header('Expires: 0');
		header('Cache-Control: no-cache');
		header("Content-Length: " . (string) (filesize($filenotfound)));
		echo file_get_contents($filenotfound);
	}

	/**
	 * OnAjaxRadicalform event
	 *
	 * @since  1.0.0
	 */
	public function onAjaxRadicalform()
	{
		$uri    = Uri::getInstance();
		$r      = $this->getApplication()->getInput();
		$input  = $r->post->getArray();
		$get    = $r->get->getArray();
		$files   = $r->files->getArray();
		$request = $this->getApplication()->isClient('administrator') ? array_merge($get, $input) : $get;
		$admin   = $request['admin'] ?? null;
		$jsRequest = [
			'action'    => $input['rfJsAction'] ?? '',
			'challenge' => $input['rfJsChallenge'] ?? '',
			'proof'     => $input['rfJsProof'] ?? ''
		];
		$jsPermit = trim((string) ($input['rfJsPermit'] ?? ''));

		unset($input['rfJsAction'], $input['rfJsChallenge'], $input['rfJsProof'], $input['rfJsPermit']);

		if ($jsRequest['action'] !== '')
		{
			$this->handleJsChallengeRequest($jsRequest);
		}

        $reservedFieldNames = $this->getReservedFieldNames($input, $files);

        if ($reservedFieldNames !== [])
        {
            $this->setErrorResponse(
                Text::sprintf('PLG_RADICALFORM_RESERVED_FIELD_NAMES', implode(', ', $reservedFieldNames)),
                ['fields' => $reservedFieldNames]
            );
        }

		$source  = $input;
		$logType = $this->getHistoryLogType($request);
        $page    = RadicalFormHelper::getHistoryLogPage($r->post->get('page', $r->get->get('page', '0', 'raw'), 'raw'));

		if (($get['file'] ?? null) === 'delete' && isset($input['deletefile'], $input['catalog'], $input['uniq']))
		{
			if (!Session::checkToken('post'))
			{
				$this->setErrorResponse(Text::_('JINVALID_TOKEN'), [], 403);
			}

            $result = $this->deleteUploadedFile($input['catalog'], $input['deletefile'], $input['uniq']);
            if ($result !== 'ok')
            {
                $this->setErrorResponse($result, [], 503);
            }
            $this->setResponse($result);
		}

		if (isset($input['gettoken']))
		{
			$this->setResponse(Session::getFormToken());
		}

        if ((string) ($get['file'] ?? '') === '1')
        {
            if (!$this->params->get('uploadenabled', 0))
            {
                $this->setResponse(['error' => Text::_('PLG_RADICALFORM_UPLOAD_DISABLED')]);
            }

            if ($this->getApplication()->getSession()->isNew() || !$this->getApplication()->getSession()->checkToken())
            {
                $this->setResponse(['error' => Text::_('PLG_RADICALFORM_INVALID_TOKEN')]);
            }

            $uploadGroup = $this->getUploadGroup($input['uniq'] ?? null);
            if ($uploadGroup === null)
            {
                $this->setResponse(['error' => Text::_('PLG_RADICALFORM_ERROR_UPLOAD')]);
            }

            $this->setResponse($this->processUploadedFiles($files, $uploadGroup));
        }

		if ($admin == 4 || $admin == 5)
		{
			// 5 зарезервировано для другого вида экспорта
			// это экспорт csv
			if (!$this->canManagePlugin())
			{
				$this->setErrorResponse(Text::_('JERROR_ALERTNOAUTHOR'), [], 403);
			}
            elseif ($page === null)
            {
                $this->setErrorResponse(Text::_('PLG_RADICALFORM_HISTORY_INVALID_ARCHIVE'), [], 400);
            }
			else
			{
				$site_offset = $this->getApplication()->get('offset'); //get offset of joomla time like asia/kolkata
				$jdate       = Factory::getDate('now');
				$timezone    = new \DateTimeZone($site_offset);
				$jdate->setTimezone($timezone);
				$filename = "rfexport_" . $jdate->format('d-m-Y_H-i_s', true) . ".csv";

				header("Content-disposition: attachment; filename={$filename}");
				header("Content-Type: text/csv");
				header('Expires: 0');
				header('Cache-Control: no-cache');
				$output = fopen('php://output', 'w');
				fwrite($output, "\xEF\xBB\xBF");
				$headers = [
					'#',
					Text::_('PLG_RADICALFORM_HISTORY_TIME')
				];
				if ($this->params->get('showtarget'))
				{
					$headers[] = Text::_('PLG_RADICALFORM_HISTORY_TARGET');
				}
				if ($this->params->get('showformid'))
				{
					$headers[] = Text::_('PLG_RADICALFORM_HISTORY_FORMID');
				}
				$headers[] = Text::_('PLG_RADICALFORM_HISTORY_IP');
				$headers[] = Text::_('PLG_RADICALFORM_HISTORY_MESSAGE');
				if ($this->params->get('hiddeninfo'))
				{
					$headers[] = Text::_('PLG_RADICALFORM_HISTORY_EXTRA');
				}
				fputcsv($output, array_map([$this, 'escapeCsvFormula'], $headers), ';', '"', '', "\r\n");

                $logPath = (string) $this->getApplication()->get('log_path');
                $logFile = RadicalFormHelper::getHistoryLogPath($logPath, $logType, $page);
                $data    = $logFile === null ? [] : RadicalFormHelper::getCSV($logFile, "\t");
				if (count($data) > 0)
				{
					for ($i = 0; $i < 6; $i++)
					{
						if (!isset($data[$i]))
						{
							continue;
						}

						if (count($data[$i]) < 4 || $data[$i][0][0] == '#')
						{
							unset($data[$i]);
						}
					}
				}
				$data = array_reverse($data);

				$cnt = count($data);
				if ($cnt > 0)
				{
					foreach ($data as $i => $item)
					{
						$json     = json_decode($item[2], true);
						$jdate    = Factory::getDate($item[0]);
						$timezone = new \DateTimeZone($site_offset);
						$jdate->setTimezone($timezone);

						$latestNumber = $logType === 'spam' ? $i + 1 : "";
						if ($logType !== 'spam' && isset($json["rfLatestNumber"]))
						{
							$latestNumber = $json["rfLatestNumber"];
							unset($json["rfLatestNumber"]);
						}

						if ($this->params->get('showtarget'))
						{
							if (isset($json["rfTarget"]) && (!empty($json["rfTarget"])))
							{
								$target = Text::_($json["rfTarget"]);
								unset($json["rfTarget"]);
							}
							else
							{
								$target = "";
								if (isset($json["rfTarget"]))
								{
									unset($json["rfTarget"]);
								}
							}
						}
						else
						{
							$target = "";
							if (isset($json["rfTarget"]))
							{
								unset($json["rfTarget"]);
							}
						}

						if ($this->params->get('showformid'))
						{
							if (isset($json["rfFormID"]) && (!empty($json["rfFormID"])))
							{
								$formid = Text::_($json["rfFormID"]);
								unset($json["rfFormID"]);
							}
							else
							{
								$formid = "";
							}
						}
						else
						{
							$formid = "";
						}

						$extrainfo = "";
						if ($this->params->get('hiddeninfo'))
						{
							$extraFieldsMap = [
								'url'         => Text::_('PLG_RADICALFORM_URL'),
								'reffer'      => Text::_('PLG_RADICALFORM_REFFER'),
								'resolution'  => Text::_('PLG_RADICALFORM_RESOLUTION'),
								'pagetitle'   => Text::_('PLG_RADICALFORM_PAGETITLE'),
								'rfUserAgent' => Text::_('PLG_RADICALFORM_USERAGENT'),
								'rf-time'     => Text::_('PLG_RADICALFORM_USER_TIME'),
								'rf-duration' => Text::_('PLG_RADICALFORM_FORM_DURATION')
							];
							$extraFields = [];

							foreach ($extraFieldsMap as $key => $label)
							{
								if (isset($json[$key]))
								{
									$extraFields[] = $label . $json[$key];
									unset($json[$key]);
								}
							}

							$extrainfo = implode("\n", $extraFields);
						}

						foreach (['url', 'reffer', 'resolution', 'pagetitle', 'rfUserAgent', 'rf-time', 'rf-duration'] as $key)
						{
							if (isset($json[$key]))
							{
								unset($json[$key]);
							}
						}

						$row = [
							$latestNumber,
							$jdate->format('H:i:s', true) . "\n" . $jdate->format('d.m.Y', true)
						];
						if ($this->params->get('showtarget'))
						{
							$row[] = $target;
						}
						if ($this->params->get('showformid'))
						{
							$row[] = $formid;
						}
						$row[] = $item[1];

						$message = "";
						if (is_array($json))
						{
							$delimiter = "";
							foreach ($json as $key => $record)
							{
								if (is_array($record))
								{
                                    $record = array_filter($record, 'is_array') === []
                                        ? implode(', ', $record)
                                        : json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
								}
								$message   .= $delimiter . Text::_($key) . ": " . $record;
								$delimiter = "\n";
							}
						}
						$row[] = $message;
						if ($this->params->get('hiddeninfo'))
						{
							$row[] = $extrainfo;
						}
						fputcsv($output, array_map([$this, 'escapeCsvFormula'], $row), ';', '"', '', "\r\n");
					}

				}

				fclose($output);
				$this->getApplication()->close(200);
			}
		}

		if ($admin == 1)
		{
			if (!$this->canManagePlugin())
			{
				$this->setErrorResponse(Text::_('JERROR_ALERTNOAUTHOR'), [], 403);
			}
			elseif (!Session::checkToken('post'))
			{
				$this->setErrorResponse(Text::_('JINVALID_TOKEN'), [], 403);
			}
			else
			{
				// тут проверка телеграма на предмет обновлений диалогов (ловим chat_id)
				$qv = "https://api.telegram.org/bot" . $this->params->get('telegramtoken') . "/getUpdates";
				$ch = curl_init();

				if ($this->params->get('proxy'))
				{
					$proxy = $this->params->get('proxylogin') . ":" . $this->params->get('proxypassword') . "@" . $this->params->get('proxyaddress') . ":" . $this->params->get('proxyport');

					curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
					curl_setopt($ch, CURLOPT_PROXY, $proxy);
				}

				curl_setopt($ch, CURLOPT_URL, $qv);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HEADER, 0);
                $result = curl_exec($ch);
                $curlCode = curl_errno($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $output = is_string($result) ? json_decode($result, true) : null;

                if ($curlCode !== 0 || $status !== 200 || ($output['ok'] ?? false) !== true || !is_array($output['result'] ?? null))
                {
                    $apiCode = $output['error_code'] ?? null;
                    $fallbackCode = $status >= 400 ? $status : 502;
                    $this->setResponse([
                        'ok' => false,
                        'error_code' => $curlCode ?: (is_int($apiCode) ? $apiCode : $fallbackCode),
                        'description' => Text::_('PLG_RADICALFORM_TELEGRAM_UPDATES_ERROR'),
                    ]);
                }

				$output  = $output["result"];
				$chatIDs = [];

				// проверяем все сообщения, присланные боту, и вытаскиваем оттуда chat_id
				foreach ($output as $chat)
				{
					$chatID = $chat["message"]["chat"]["id"];
					$name   = "";
					if (isset($chat["message"]["chat"]["username"]))
					{
						$name .= $chat["message"]["chat"]["username"];
					}
					if (isset($chat["message"]["chat"]["first_name"]) || isset($chat["message"]["chat"]["last_name"]))
					{
						$name .= " (";
					}
					if (isset($chat["message"]["chat"]["first_name"]))
					{
						$name .= $chat["message"]["chat"]["first_name"];
					}

					if (isset($chat["message"]["chat"]["last_name"]))
					{
						$name .= " " . $chat["message"]["chat"]["last_name"];
					}
					if (isset($chat["message"]["chat"]["first_name"]) || isset($chat["message"]["chat"]["last_name"]))
					{
						$name .= ")";
					}
					array_push($chatIDs, ["name" => $name, "chatID" => $chatID]);
				}

				$this->setResponse(['ok' => true, 'chatids' => $chatIDs]);
			}
		}

		if ($admin == 'maxconnection')
		{
			if (!$this->canManagePlugin())
			{
				$this->setErrorResponse(Text::_('JERROR_ALERTNOAUTHOR'), [], 403);
			}
			elseif (!Session::checkToken('post'))
			{
				$this->setErrorResponse(Text::_('JINVALID_TOKEN'), [], 403);
			}
			else
			{
				$token = trim((string) $this->params->get('maxtoken'));

				if ($token === '')
				{
					$this->setResponse([
						'ok'      => false,
						'tls'     => null,
						'message' => Text::_('PLG_RADICALFORM_MAX_CONNECTION_TOKEN_MISSING'),
					]);
				}

				$connection = MaxHelper::checkConnection($token);

				if ($connection['ok'])
				{
					$botName = htmlspecialchars((string) $connection['bot_name'], ENT_QUOTES, 'UTF-8');
					$message = Text::sprintf('PLG_RADICALFORM_MAX_CONNECTION_SUCCESS', $botName);
				}
				elseif ($connection['tls'] === false)
				{
					$error   = htmlspecialchars((string) ($connection['error'] ?? ''), ENT_QUOTES, 'UTF-8');
					$message = Text::sprintf('PLG_RADICALFORM_MAX_CONNECTION_TLS_ERROR', $error);
				}
				elseif ((int) ($connection['status_code'] ?? 0) === 401)
				{
					$message = Text::_('PLG_RADICALFORM_MAX_CONNECTION_TOKEN_ERROR');
				}
				elseif ($connection['tls'] === true)
				{
					$status  = (int) ($connection['status_code'] ?? 0);
					$error   = htmlspecialchars((string) ($connection['error'] ?? ''), ENT_QUOTES, 'UTF-8');
					$message = Text::sprintf('PLG_RADICALFORM_MAX_CONNECTION_API_ERROR', $status, $error);
				}
				else
				{
					$error   = htmlspecialchars((string) ($connection['error'] ?? ''), ENT_QUOTES, 'UTF-8');
					$message = Text::sprintf('PLG_RADICALFORM_MAX_CONNECTION_NETWORK_ERROR', $error);
				}

				$this->setResponse([
					'ok'      => (bool) $connection['ok'],
					'tls'     => $connection['tls'],
					'message' => $message,
				]);
			}
		}

		if ($admin == 'maxupdates')
		{
			if (!$this->canManagePlugin())
			{
				$this->setErrorResponse(Text::_('JERROR_ALERTNOAUTHOR'), [], 403);
			}
			elseif (!Session::checkToken('post'))
			{
				$this->setErrorResponse(Text::_('JINVALID_TOKEN'), [], 403);
			}
			else
			{
				$token     = trim((string) $this->params->get('maxtoken'));
				$session   = $this->getApplication()->getSession();
				$markerKey = 'radicalform.max.marker.' . md5($token);
				$marker    = (string) $session->get($markerKey, '');
				$updates   = MaxHelper::getUpdates($token, $marker !== '' ? $marker : null, $marker !== '' ? 30 : 0);

				if (isset($updates['status_code']) || isset($updates['error']))
				{
					$this->setResponse($updates);
				}

				if (!empty($updates['marker']))
				{
					$session->set($markerKey, (string) $updates['marker']);
				}

				$recipients = MaxHelper::extractRecipients($updates);

				$this->setResponse([
					'ok'         => true,
					'recipients' => $recipients,
					'marker'     => $updates['marker'] ?? '',
					'message'    => empty($recipients) && $marker === ''
						? Text::_('PLG_RADICALFORM_MAX_MARKER_INITIALIZED')
						: Text::_('PLG_RADICALFORM_MAX_NO_RECIPIENTS'),
				]);
			}
		}

		// here we try to load current logfile
		$site_offset = $this->getApplication()->get('offset'); //get offset of joomla time like asia/kolkata
        $logPath        = (string) $this->getApplication()->get('log_path');
        $currentLogFile = RadicalFormHelper::getHistoryLogPath($logPath, 'messages');

        $data = $currentLogFile === null ? [] : RadicalFormHelper::getCSV($currentLogFile, "\t");
		if (count($data) > 0)
		{
			for ($i = 0; $i < 6; $i++)
			{
                if (isset($data[$i]) && (count($data[$i]) < 4 || $data[$i][0][0] == '#'))
				{
					unset($data[$i]);
				}
			}
		}

		// here we get latest serial number from log file
		$latestNumber = 1;
		if (count($data) > 0)
		{
			$data = array_reverse($data);
			$json = json_decode($data[0][2], true);
			if (is_array($json))
			{
				if (isset($json['rfLatestNumber']))
				{
					$latestNumber = $json['rfLatestNumber'] + 1;
				}
			}
		}

		if ($admin == 2)
		{
			if (!$this->canManagePlugin())
			{
				$this->setErrorResponse(Text::_('JERROR_ALERTNOAUTHOR'), [], 403);
			}
			elseif (!Session::checkToken('post'))
			{
				$this->setErrorResponse(Text::_('JINVALID_TOKEN'), [], 403);
			}
            elseif ($page === null)
            {
                $this->setErrorResponse(Text::_('PLG_RADICALFORM_HISTORY_INVALID_ARCHIVE'), [], 400);
            }
			else
			{
                if ($logType === 'spam')
                {
                    if (!RadicalFormHelper::deleteSpamLog($logPath, $page))
                    {
                        $this->setErrorResponse(Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), [], 500);
                    }
                    $this->setResponse('ok');
                }

                $logFile = RadicalFormHelper::getHistoryLogPath($logPath, $logType, $page);
                if ($logFile !== null)
                {
                    if (!unlink($logFile))
                    {
                        $this->setErrorResponse(Text::_('JERROR_AN_ERROR_HAS_OCCURRED'), [], 500);
                    }

                    if ($page === 0 && $logType === 'messages')
                    {
                        $entry = ['rfLatestNumber' => $latestNumber, 'message' => Text::_('PLG_RADICALFORM_CLEAR_HISTORY')];
                        Log::add(json_encode($entry), Log::NOTICE, $this->getHistoryLogCategory($logType));
                    }
                }

				$this->setResponse('ok');
			}
		}

		if ($admin == 3)
		{
			if (!$this->canManagePlugin())
			{
				$this->setErrorResponse(Text::_('JERROR_ALERTNOAUTHOR'), [], 403);
			}
			elseif (!Session::checkToken('post'))
			{
				$this->setErrorResponse(Text::_('JINVALID_TOKEN'), [], 403);
			}
			else
			{
				// сбрасываем нумерацию
				$entry = ['rfLatestNumber' => 0, 'message' => Text::_('PLG_RADICALFORM_RESET_NUMBER')];
				Log::add(json_encode($entry), Log::NOTICE, 'plg_system_radicalform');

				$this->setResponse('ok');
			}
		}

		if (!isset($input['uniq']))
		{
			$entry                     = $input;
			$entry['rfWarningMessage'] = Text::_('PLG_RADICALFORM_INVALID_TOKEN');
			$entry['rfAntiSpam']       = 'invalid token';
			$this->logSpamBlock($entry);

			$this->setResponse(['error' => Text::_('PLG_RADICALFORM_INVALID_TOKEN')]);
		}

		if ($this->getApplication()->getSession()->isNew() || !$this->getApplication()->getSession()->checkToken())
		{
			$entry                     = $input;
			$entry['rfWarningMessage'] = Text::_('PLG_RADICALFORM_INVALID_TOKEN');
			$entry['rfAntiSpam']       = 'invalid token';
			$this->logSpamBlock($entry);

			$this->setResponse(Text::_('PLG_RADICALFORM_INVALID_TOKEN'));
		};

        if (!$this->consumeJsPermit($jsPermit))
		{
			$message = $this->getJsChallengeMessage();
			$entry                     = $input;
			$entry['rfWarningMessage'] = $message;
			$entry['rfAntiSpam']       = 'javascript challenge';
			$this->logSpamBlock($entry);

			$this->setResponse($message);
		}

		// Restore UTM parameters from session if not already present in submitted data
		if ($this->params->get('track_utm', 0))
		{
			$session = $this->getApplication()->getSession();
			$utmData = (array) $session->get(self::UTM_SESSION_KEY, []);
			$allowedUtmTags = $this->getAllowedUtmTags();

			if (!$this->isUtmDataValid($utmData))
			{
				$session->clear(self::UTM_SESSION_KEY);
			}
			else
			{
				foreach ((array) ($utmData['values'] ?? []) as $utm => $value)
				{
					if (in_array($utm, $allowedUtmTags, true) && $value && empty($input[$utm]))
					{
						$input[$utm] = $value;
					}
				}

				if (!empty($utmData['created_at']) && !empty($utmData['values']) && empty($input['utm_created_at']))
				{
					$siteOffset = $this->getApplication()->get('offset');
					$date       = Factory::getDate('@' . (int) $utmData['created_at']);
					$date->setTimezone(new \DateTimeZone($siteOffset));

					$input['utm_created_at'] = $date->format('Y-m-d H:i:s', true);
				}
			}
		}

        $uploadGroup = $this->getUploadGroup($input['uniq']);

		$antiSpamReason = $this->checkAntiSpam($input);

		if ($antiSpamReason !== false)
		{
			$this->logAntiSpamBlock($input, $antiSpamReason);

			$this->setResponse($this->getAntiSpamMessage());
		}

		if ($this->checkAntiFlood() !== null)
		{
			$this->setResponse($this->getAntiFloodMessage());
		}

		$mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
		$sender = array(
			$this->getApplication()->get('mailfrom'),
			$this->getApplication()->get('fromname')
		);

		$mailer->setSender($sender);

		if (isset($input["rfSubject"]) && (!empty($input["rfSubject"])))
		{
            $subject = FormMessage::text($input["rfSubject"]);
			unset($input["rfSubject"]);
		}
		else
		{
            $subject = FormMessage::text($this->params->get('rfSubject'));
		}

		$subjectInput = $input;
		$subjectInput['rfLatestNumber'] = $latestNumber;

		// Expression to search for (positions)
		$regex = '/{(.*?)}/i';

		// Find all instances of fields
		preg_match_all($regex, $subject, $matches, PREG_SET_ORDER);

		// No matches, skip this
			if ($matches)
			{
				foreach ($matches as $match)
				{
					if (isset($subjectInput[$match[1]]))
					{
						$set = $subjectInput[$match[1]];
						if (is_array($set))
						{
							$set = implode(", ", $set);
						}
					$subject = preg_replace("|$match[0]|", $set, $subject, 1);
				}
			}
		}

		$mailer->setSubject($subject);

        $storage = $this->getUploadStorage();
        $uploadDirectory = $uploadGroup !== null ? $storage?->groupPath($uploadGroup) : null;
        $trackUploadMutations = $uploadDirectory !== null && $this->params->get('uploadenabled', 0);
		$params = $this->params;
        $params->set('uploaddir', $uploadDirectory ?? '');
		$params->set('rfLatestNumber', $latestNumber);

        $attachmentParts = [];
        $fileDelimiter = (string) $this->params->get('delimiter', '<br />');

		// формируем поле загруженных файлов
		if ($this->params->get('uploadenabled', 0) && isset($input["needToSendFiles"]) && ($input["needToSendFiles"] == 1))
		{
            $folders = $uploadDirectory !== null ? @scandir($uploadDirectory) : false;
            if ($folders === false)
            {
                $this->setErrorResponse(Text::_('PLG_RADICALFORM_ERROR_UPLOAD'));
            }

            $downloadPath = $this->getDownloadPath();
            $downloadBase = rtrim(Uri::root(), '/') . '/' . $downloadPath;
            $totalSize = 0;

            foreach ($folders as $folder)
            {
                $directory = $storage->directory(['rf-' . $uploadGroup, $folder]);
                if ($directory === null)
                {
                    continue;
                }

                foreach (@scandir($directory) ?: [] as $name)
                {
                    $file = $storage->file($directory, $name);
                    if ($file === null)
                    {
                        continue;
                    }

                    $signature = $this->getDownloadSignature($uploadGroup, $folder, $name);
                    if ($signature === null || $downloadPath === '')
                    {
                        $this->setErrorResponse(Text::_('PLG_RADICALFORM_ERROR_UPLOAD'));
                    }

                    $link = "$downloadBase/$uploadGroup/$folder/$name?sig=$signature";
                    $attachmentParts[$folder] ??= isset($input[$folder]) ? [FormMessage::text($input[$folder])] : [];
                    $attachmentParts[$folder][] = $link;
                    $input[$folder] = implode($fileDelimiter, $attachmentParts[$folder]);

                    if ($this->params->get('attachfiles', 0))
                    {
                        $size = filesize($file);
                        if ($size !== false && $totalSize + $size < $this->maxDirSize)
                        {
                            $totalSize += $size;
                            $mailer->addAttachment($file);
                        }
                    }
                }
            }
		}

        $rejection = null;
		try
		{
			// вызов внешнего плагина
			PluginHelper::importPlugin('radicalform');

            $finishUploadMutation = $trackUploadMutations && (
                $this->getApplication()->getDispatcher()->getListeners('onBeforeSendRadicalForm')
                || $this->dispatcher->getListeners('onBeforeProcessRadicalForm')
            ) ? $this->beginExternalUploadMutation($storage, $uploadGroup) : null;
            try
            {
                $pluginResults = $this->getApplication()->triggerEvent('onBeforeSendRadicalForm', [$this->clearInput($input), &$input, $params]);
                $rejection = $this->normalizeBeforeSendRejection(array_values((array) $pluginResults));

                if ($rejection === null)
                {
                    $beforeProcessEvent = new BeforeProcessRadicalFormEvent('onBeforeProcessRadicalForm', [
                        'clearInput' => $this->clearInput($input),
                        'input'      => &$input,
                        'params'     => $params
                    ]);

                    $this->dispatcher->dispatch('onBeforeProcessRadicalForm', $beforeProcessEvent);
                    $rejection = $this->normalizeBeforeSendRejection(array_values((array) ($beforeProcessEvent['result'] ?? [])));
                }
            }
            finally
            {
                $this->finishExternalUploadMutation($finishUploadMutation);
            }
		}
		catch (\Throwable $e)
		{
			$entry = [
				'rfWarningMessage' => 'External RadicalForm plugin error',
				'error'            => $e->getMessage(),
				'file'             => $e->getFile(),
				'line'             => $e->getLine(),
				'form'             => isset($input['rfFormID']) ? (string) $input['rfFormID'] : '',
				'target'           => isset($input['rfTarget']) ? (string) $input['rfTarget'] : ''
			];

			Log::add(json_encode($entry), Log::ERROR, 'plg_system_radicalform');
		}

        if ($rejection !== null)
        {
            $this->setErrorResponse($rejection['message'], ['fields' => $rejection['fields']]);
        }

		unset($input["uniq"]);
		unset($input["needToSendFiles"]);
		unset($input[Session::getFormToken()]);
        $url        = $input["url"] ?? '';
        $resolution = $input["resolution"] ?? '';
        $ref        = $input["reffer"] ?? '';
        $pagetitle  = $input["pagetitle"] ?? '';
        $useragent  = $input["rfUserAgent"] ?? '';

		$rfTime     = isset($input["rf-time"]) ? $input["rf-time"] : '';
		$rfDuration = isset($input["rf-duration"]) ? $input["rf-duration"] : '';
        $formID     = Text::_(FormMessage::text($input["rfFormID"] ?? ''));

		if (file_exists($this->logPath))
		{
			if ($this->params->get('maxlogfile') < filesize($this->logPath))
			{
				$this->rotateLogFile('plg_system_radicalform.php');
				$entry = ['rfLatestNumber' => $latestNumber, 'message' => Text::_('PLG_RADICALFORM_ROTATE_HISTORY_BY_MAX_LOG')];
				Log::add(json_encode($entry), Log::NOTICE, 'plg_system_radicalform');
				$latestNumber++;
			}
		}

		$input['rfLatestNumber'] = $latestNumber;
		$input                   = array_filter($input, function ($value) {
			return $value !== '';
		}); // delete empty fields in input array

		$emailLogInput = $input;

		if (isset($input["rfTarget"]) && (!empty($input["rfTarget"])))
		{
			$target = $input["rfTarget"];
		}
		else
		{
			$target = false;
		}

		$input    = $this->clearInput($input);
		$mainbody = "";
		$subject  = StringHelper::strtoupper($subject);

        $messageTitle = $this->params->get('insertformid') ? $formID : $subject;
        $telegram = '<b>' . FormMessage::escape($messageTitle) . '</b><br /><br />';
        $textOutput = FormMessage::plain($messageTitle) . " \r\n \r\n";
        $glue = (string) $this->params->get('glue', '');

		foreach ($input as $key => $record)
		{
            $label = Text::_((string) $key);
            $parts = $attachmentParts[$key] ?? null;
            // Keep the field supplied by a hook if it changed the concatenated attachment value.
            $isAttachmentField = $parts !== null && $record === implode($fileDelimiter, $parts);
            $values = $isAttachmentField ? $parts : $record;
            $separator = $isAttachmentField ? $fileDelimiter : $glue;
            $escapedLabel = FormMessage::escape($label);
            $emailValue = FormMessage::format($values, $separator, FormMessage::HTML, !$isAttachmentField);

            if ($key === 'phone')
            {
                $phone = str_replace([' ', "\t", '(', ')', '.', '-'], '', trim(FormMessage::text($record, $glue)));
                if (preg_match('/\A\+?[0-9]+\z/', $phone))
                {
                    $emailValue = '<a href="tel:' . $phone . '">' . $emailValue . '</a>';
                }
            }

            $mainbody .= '<p>' . $escapedLabel . ': <strong>' . $emailValue . '</strong></p>';
            $telegramValue = FormMessage::format($values, $separator, FormMessage::MESSENGER, !$isAttachmentField);
            $telegram .= $escapedLabel . ': <b>' . $telegramValue . '</b><br />';
            $textOutput .= FormMessage::plain($label . ': ')
                . FormMessage::format($values, $separator, FormMessage::PLAIN, !$isAttachmentField) . " \r\n";
		}

		$header = "";
		if ($this->params->get('insertformid'))
		{
            $header = '<h2>' . FormMessage::escape($formID) . '</h2>';
		}

		if ($this->params->get('extendedinfo'))
		{
            $footer = '# <strong>' . FormMessage::escape(FormMessage::text($latestNumber)) . '</strong><br>';
			if ($formID)
			{
                $footer .= Text::_('PLG_RADICALFORM_FORMID') . '<strong>' . FormMessage::escape($formID) . '</strong><br>';
			}

            $ip = $this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '');
            $ipLabel = filter_var($ip, FILTER_VALIDATE_IP)
                ? FormMessage::webLink('http://whois.domaintools.com/' . $ip, $ip)
                : FormMessage::escape($ip);
            $footer .= Text::_('PLG_RADICALFORM_IP_ADDRESS') . '<strong>' . $ipLabel . '</strong><br>';
            $footer .= Text::_('PLG_RADICALFORM_URL') . FormMessage::escape(FormMessage::text($url)) . '<br />';
			if ($ref)
			{
                $referrer = FormMessage::text($ref);
                $footer .= Text::_('PLG_RADICALFORM_REFFER') . FormMessage::webLink($referrer, StringHelper::substr($referrer, 0, 64)) . '<br />';
			};

            $footerFields = [
                'PLG_RADICALFORM_PAGETITLE' => $pagetitle,
                'PLG_RADICALFORM_USERAGENT' => $useragent,
                'PLG_RADICALFORM_RESOLUTION' => $resolution,
                'PLG_RADICALFORM_USER_TIME' => $rfTime,
                'PLG_RADICALFORM_FORM_DURATION' => $rfDuration,
            ];
            foreach ($footerFields as $label => $value)
            {
                $footer .= Text::_($label) . '<strong>' . FormMessage::escape(FormMessage::text($value)) . '</strong><br />';
            }
		}
		else
		{
			$footer = "";
		}

		$path = PluginHelper::getLayoutPath('system', 'radicalform');

		// Render the email
        $defaultLayout = dirname(__DIR__, 2) . '/tmpl/default.php';
        $finishUploadMutation = $trackUploadMutations && realpath($path) !== realpath($defaultLayout)
            ? $this->beginExternalUploadMutation($storage, $uploadGroup)
            : null;
		ob_start();
        try
        {
            include $path;
        }
        finally
        {
            $this->finishExternalUploadMutation($finishUploadMutation);
        }
		$body = ob_get_clean();

		//execute custom code if we'll find it
		if ($this->params->get('customcodeon'))
		{
			$customcodes = (array) $this->params->get('customcodes');
			foreach ($customcodes as $customcode)
			{
				if ((($target !== false) && ($customcode->target == $target)) or
					(empty(trim($customcode->target)) && ($target === false))
				)
				{
					$template = Factory::getApplication()->getTemplate();
                    $templatePath = realpath(JPATH_THEMES . '/' . $template);
                    $layoutPrefix = $templatePath . DIRECTORY_SEPARATOR . 'html' . DIRECTORY_SEPARATOR . 'plg_system_radicalform' . DIRECTORY_SEPARATOR;
                    $layout = $customcode->layout ?? '';
                    $tPath = $templatePath !== false && is_string($layout) && $layout !== '' && !str_contains($layout, "\0")
                        ? realpath($layoutPrefix . $layout)
                        : false;

                    if ($tPath !== false && str_starts_with($tPath, $layoutPrefix) && is_file($tPath))
					{
                        $finishUploadMutation = $trackUploadMutations
                            ? $this->beginExternalUploadMutation($storage, $uploadGroup)
                            : null;
						$bufferLevel = ob_get_level();
						ob_start();

						try
						{
                                try
                                {
                                    include $tPath;
                                }
                                finally
                                {
                                    $this->finishExternalUploadMutation($finishUploadMutation);
                                }
							$output = ob_get_clean();

							if (trim($output) !== '')
							{
								$customLayoutLogInput = [
									'rfWarningMessage' => 'Custom layout produced unexpected output',
									'output'           => $output,
									'layoutPath'       => $tPath,
									'form'             => $formID,
									'target'           => $target !== false ? (string) $target : ''
								];

								Log::add(json_encode($customLayoutLogInput), Log::WARNING, 'plg_system_radicalform');
							}
						}
						catch (\Throwable $e)
						{
							while (ob_get_level() > $bufferLevel)
							{
								ob_end_clean();
							}

							$entry = [
								'rfWarningMessage' => 'Custom RadicalForm layout error',
								'error'            => $e->getMessage(),
								'file'             => $e->getFile(),
								'line'             => $e->getLine(),
								'form'             => $formID,
								'target'           => $target !== false ? (string) $target : ''
							];

							Log::add(json_encode($entry), Log::ERROR, 'plg_system_radicalform');
						}
					}
				}
			}
		}

		if ($this->params->get('telegram'))
		{
			$chatIDs = (array) $this->params->get('chatids');
			foreach ($chatIDs as $chatID)
			{
				$chatTarget = trim((string) ($chatID->target ?? ''));

				if (empty($chatID->chat_id))
				{
					continue;
				}

				if (
					(($target !== false) && ($chatTarget == $target)) or
					($chatTarget === '' && ($target === false))
				)
				{
					$url = "https://api.telegram.org/bot" . $this->params->get('telegramtoken') . "/sendMessage?"
						. http_build_query([
							'disable_web_page_preview' => true,
							'chat_id'                  => $chatID->chat_id,
							'parse_mode'               => 'HTML',
							'text'                     => str_replace("<br />", "\r\n", $telegram)
						]);

					$ch = curl_init();
					if ($this->params->get('proxy'))
					{
						$proxy = $this->params->get('proxylogin') . ":" . $this->params->get('proxypassword') . "@" . $this->params->get('proxyaddress') . ":" . $this->params->get('proxyport');
						curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
						curl_setopt($ch, CURLOPT_PROXY, $proxy);
					}

					curl_setopt($ch, CURLOPT_URL, "$url");
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
					curl_setopt($ch, CURLOPT_HEADER, 0);

					$telegramResponse = curl_exec($ch);
					$telegramStatus   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
					$telegramError    = curl_error($ch);
					$telegramResult   = is_string($telegramResponse)
						? json_decode($telegramResponse, true)
						: null;

					curl_close($ch);

					if (!(
						$telegramResponse !== false
						&& $telegramStatus >= 200
						&& $telegramStatus < 300
						&& is_array($telegramResult)
						&& ($telegramResult['ok'] ?? false) === true
					))
					{
						$telegramLogInput = [
							'rfWarningMessage' => 'Telegram API send error',
							'chat_id'          => (string) $chatID->chat_id,
							'status_code'      => $telegramStatus,
							'curl_error'       => $telegramError,
							'response'         => is_array($telegramResult)
								? $telegramResult
								: (string) $telegramResponse,
						];

						Log::add(json_encode($telegramLogInput), Log::WARNING, 'plg_system_radicalform');
					}
				}
			}
		}

		if ($this->params->get('max'))
		{
			$recipients = (array) $this->params->get('maxrecipients');
			foreach ($recipients as $recipient)
			{
				if (empty($recipient->recipient_id) || empty($recipient->recipient_type))
				{
					continue;
				}

				if (
					(($target !== false) && ($recipient->target == $target)) or
					(empty(trim($recipient->target)) && ($target === false))
				)
				{
					$maxResult = MaxHelper::sendMessage(
						(string) $this->params->get('maxtoken'),
						(string) $recipient->recipient_type,
						(string) $recipient->recipient_id,
						$telegram
					);

					if (isset($maxResult['status_code']) || isset($maxResult['error']))
					{
						$maxLogInput = [
							'rfWarningMessage' => Text::_('PLG_RADICALFORM_MAX_SEND_ERROR'),
							'recipient_type'   => (string) $recipient->recipient_type,
							'recipient_id'     => (string) $recipient->recipient_id,
							'response'         => $maxResult,
						];

						Log::add(json_encode($maxLogInput), Log::WARNING, 'plg_system_radicalform');
					}
				}
			}
		}

		$messageLogLevel = Log::NOTICE;

		if ($this->params->get('emailon'))
		{
			try
			{
				// if we need to send email
				$mailer->isHtml(true);
				$mailer->Encoding = 'base64';
				$mailer->setBody($body);

				$needToSendEmail = false;
				if (isset($target) && (!empty($target)))
				{
					// if we need to send it to alternative emails
					$emailalt = (array) $this->params->get('emailalt');
					foreach ($emailalt as $item)
					{
						if ($target == $item->target)
						{
							$mailer->addRecipient($item->email);
							$needToSendEmail = true;
						}
					}
				}
				else
				{
					//traditionally send
					$mailer->addRecipient($this->params->get('email'));
					if (!empty($this->params->get('emailcc')))
					{
						$mailer->addCc($this->params->get('emailcc'));
					}
					if (!empty($this->params->get('emailbcc')))
					{
						$mailer->addBcc($this->params->get('emailbcc'));
					}
					$needToSendEmail = true;
				}

				if ((!empty($this->params->get('replyto'))) && isset($input[$this->params->get('replyto')]))
				{
					$mailer->addReplyTo($input[$this->params->get('replyto')]);
				}

				if ($needToSendEmail)
				{
					$send = $mailer->send();

					if ($send === false)
					{
						$emailLogInput["rfWarningMessage"] = Text::_('PLG_RADICALFORM_MAIL_DISABLED');
						$messageLogLevel                   = Log::WARNING;
					}
				}
			}
			catch (\Throwable $e)
			{
				$emailLogInput["rfWarningMessage"] = $e->getMessage();
				$messageLogLevel                   = Log::WARNING;
			}
		}

		Log::add(json_encode($emailLogInput), $messageLogLevel, 'plg_system_radicalform');

		$this->setResponse(['ok', $textOutput]);

		return false;
	}

	/**
	 * Set a response method.
	 *
	 * @param   mixed  $data  Returned data
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected function setResponse($data)
	{
		$data = [$data];

		Factory::getApplication()->setHeader('Content-Type', 'application/json', true);
		echo new JsonResponse($data);
		Factory::getApplication()->close(200);
	}

	protected function setErrorResponse($message, array $data = [], $status = 200)
	{
		http_response_code($status);
		Factory::getApplication()->setHeader('Content-Type', 'application/json', true);
		echo new JsonResponse($data, $message, true);
		Factory::getApplication()->close($status);
	}
}
