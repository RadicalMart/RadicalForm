<?php
/*
 * @package   RadicalForm
 * @version   __DEPLOY_VERSION__
 * @author    Vladimir Eliseev aka Progreccor - https://progreccor.ru
 * @copyright Copyright (c) 2026 Progreccor. All rights reserved.
 * @license   GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link      https://progreccor.ru
 */

namespace Joomla\Plugin\System\RadicalForm\Helper;

\defined('_JEXEC') or die;

class MaxHelper
{
	private const API_URL = 'https://platform-api2.max.ru';
	private const CONNECT_TIMEOUT_SECONDS = 10;
	private const REQUEST_TIMEOUT_SECONDS = 20;

	public static function getUpdates(string $token, ?string $marker = null, int $timeout = 0): array
	{
		$query = [
				'types'   => 'message_created,bot_started,bot_added',
				'limit'   => 100,
				'timeout' => $timeout,
			];

		if ($marker !== null && $marker !== '')
		{
			$query['marker'] = $marker;
		}

		$url = self::API_URL . '/updates?' . http_build_query($query);

		$requestTimeout = max(self::REQUEST_TIMEOUT_SECONDS, $timeout + self::CONNECT_TIMEOUT_SECONDS + 5);

		return self::request('GET', $url, $token, null, $requestTimeout);
	}

	public static function checkConnection(string $token): array
	{
		$response = self::request('GET', self::API_URL . '/me', $token);

		if (!empty($response['curl_errno']))
		{
			$curlError      = (int) $response['curl_errno'];
			$sslVerifyError = (int) ($response['ssl_verify_result'] ?? 0);
			$tlsError       = $sslVerifyError !== 0 || in_array($curlError, [60, 77, 83], true);

			return [
				'ok'                => false,
				'tls'               => $tlsError ? false : null,
				'curl_errno'        => $curlError,
				'ssl_verify_result' => $sslVerifyError,
				'error'             => (string) ($response['error'] ?? ''),
			];
		}

		if (isset($response['status_code']))
		{
			return [
				'ok'          => false,
				'tls'         => true,
				'status_code' => (int) $response['status_code'],
				'error'       => (string) ($response['message'] ?? $response['error'] ?? ''),
			];
		}

		$name = trim((string) ($response['first_name'] ?? '') . ' ' . (string) ($response['last_name'] ?? ''));

		if ($name === '')
		{
			$name = (string) ($response['username'] ?? $response['name'] ?? $response['user_id'] ?? '');
		}

		return [
			'ok'       => true,
			'tls'      => true,
			'bot_name' => $name,
		];
	}

	public static function extractRecipients(array $updates): array
	{
		$recipients = [];
		$found      = [];

		foreach (($updates['updates'] ?? []) as $update)
		{
			$message = $update['message'] ?? [];

			if (isset($message['recipient']['chat_id']))
			{
				$type = 'chat_id';
				$id   = $message['recipient']['chat_id'];
				$name = isset($message['sender'])
					? self::formatUserName($message['sender'])
					: ($message['recipient']['title'] ?? 'Chat ' . $id);
			}
			elseif (isset($message['sender']['user_id']))
			{
				$type = 'user_id';
				$id   = $message['sender']['user_id'];
				$name = self::formatUserName($message['sender']);
			}
			elseif (isset($update['chat_id']))
			{
				$type = 'chat_id';
				$id   = $update['chat_id'];

				if (($update['update_type'] ?? '') === 'bot_added')
				{
					$name = 'Chat ' . $id;
				}
				else
				{
					$name = isset($update['user'])
						? self::formatUserName($update['user'])
						: 'Chat ' . $id;
				}
			}
			elseif (isset($update['user']['user_id']))
			{
				$type = 'user_id';
				$id   = $update['user']['user_id'];
				$name = self::formatUserName($update['user']);
			}
			else
			{
				continue;
			}

			$key = $type . ':' . $id;

			if (isset($found[$key]))
			{
				continue;
			}

			$found[$key] = true;

			$recipients[] = [
				'name' => $name,
				'type' => $type,
				'id'   => $id,
			];
		}

		return $recipients;
	}

	public static function sendMessage(string $token, string $recipientType, string $recipientId, string $text): array
	{
		if (!in_array($recipientType, ['user_id', 'chat_id'], true))
		{
			return ['error' => 'Wrong recipient type'];
		}

		$text = str_replace(["<br />", "<br>", "<br/>"], "\n", $text);

		$url  = self::API_URL . '/messages?' . http_build_query([
				$recipientType          => $recipientId,
				'disable_link_preview' => true,
			]);
		$body = [
			'text'   => $text,
			'format' => 'html',
		];

		return self::request('POST', $url, $token, $body);
	}

	private static function request(
		string $method,
		string $url,
		string $token,
		?array $body = null,
		int $timeout = self::REQUEST_TIMEOUT_SECONDS
	): array
	{
		$ch      = curl_init();
		$headers = [
			'Authorization: ' . trim($token),
			'Accept: application/json',
		];

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

		if ($method === 'POST')
		{
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		$code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error  = curl_error($ch);
		$errno  = curl_errno($ch);
		$verify = (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);

		curl_close($ch);

		if ($result === false)
		{
			return [
				'error'             => $error,
				'status_code'       => $code,
				'curl_errno'        => $errno,
				'ssl_verify_result' => $verify,
			];
		}

		$output = json_decode($result, true);

		if (!is_array($output))
		{
			return [
				'error'       => $result,
				'status_code' => $code,
			];
		}

		if ($code < 200 || $code >= 300)
		{
			$output['status_code'] = $code;
		}

		return $output;
	}

	private static function formatUserName(array $user): string
	{
		$name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

		if (!empty($user['username']))
		{
			return $name !== '' ? $name . ' (@' . $user['username'] . ')' : '@' . $user['username'];
		}

		return $name !== '' ? $name : 'User ' . ($user['user_id'] ?? '');
	}
}
