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
	private const API_URL = 'https://platform-api.max.ru';

	public static function getUpdates(string $token, ?string $marker = null, int $timeout = 0): array
	{
		$query = [
				'types'   => 'message_created,bot_started',
				'limit'   => 100,
				'timeout' => $timeout,
			];

		if ($marker !== null && $marker !== '')
		{
			$query['marker'] = $marker;
		}

		$url = self::API_URL . '/updates?' . http_build_query($query);

		return self::request('GET', $url, $token);
	}

	public static function getChats(string $token): array
	{
		$url = self::API_URL . '/chats?' . http_build_query(['count' => 100]);

		return self::request('GET', $url, $token);
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
				$name = isset($update['user'])
					? self::formatUserName($update['user'])
					: 'Chat ' . $id;
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

	public static function extractChats(array $chats): array
	{
		$recipients = [];

		foreach (($chats['chats'] ?? []) as $chat)
		{
			if (empty($chat['chat_id']))
			{
				continue;
			}

			$recipients[] = [
				'name' => $chat['title'] ?? 'Chat ' . $chat['chat_id'],
				'type' => 'chat_id',
				'id'   => $chat['chat_id'],
			];
		}

		return $recipients;
	}

	public static function mergeRecipients(array ...$recipientLists): array
	{
		$recipients = [];
		$found      = [];

		foreach ($recipientLists as $recipientList)
		{
			foreach ($recipientList as $recipient)
			{
				$key = $recipient['type'] . ':' . $recipient['id'];

				if (isset($found[$key]))
				{
					continue;
				}

				$found[$key] = true;
				$recipients[] = $recipient;
			}
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

	private static function request(string $method, string $url, string $token, ?array $body = null): array
	{
		$ch      = curl_init();
		$headers = [
			'Authorization: ' . $token,
			'Accept: application/json',
		];

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, 0);

		if ($method === 'POST')
		{
			$headers[] = 'Content-Type: application/json';
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
		}

		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		$code   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$error  = curl_error($ch);

		curl_close($ch);

		if ($result === false)
		{
			return [
				'error'       => $error,
				'status_code' => $code,
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
