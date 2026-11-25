<?php

/*
 * @package   RadicalForm
 * @version   __DEPLOY_VERSION__
 * @author    Vladimir Eliseev aka Progreccor - https://progreccor.ru
 * @copyright Copyright (c) 2025 Progreccor. All rights reserved.
 * @license   GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link      https://progreccor.ru
 */

namespace Joomla\Plugin\System\RadicalForm\Field\Radicalform;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Plugin\System\RadicalForm\Helper\FormMessage;
use Joomla\Plugin\System\RadicalForm\Helper\RadicalFormHelper;
use Joomla\Plugin\System\RadicalForm\Helper\UploadStorage;

class HistoryField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var  string
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected $type = 'radicalform_history';

	/**
	 * Method to render the field without the default Joomla label column.
	 *
	 * @param   array  $options  Options to be passed into the rendering of the field
	 *
	 * @return  string  The field input markup
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function renderField($options = [])
	{
		return '<div class="radicalform-history">' . $this->getInput() . '</div>';
	}

	/**
	 * Method to get the field input markup.
	 *
	 * @return  string  The field input markup.
	 *
	 * @since   1.0.0
	 */
	public function getInput()
	{
		$app   = Factory::getApplication();
		$input = $app->getInput();

		$l = Uri::getInstance();

		$http = parse_url($l->toString());
		parse_str($http['query'], $output);
		if (isset($output['page']))
		{
			unset($output['page']);
		}
		if (isset($output['log']))
		{
			unset($output['log']);
		}
		$currentURL = $http["path"] . '?' . http_build_query($output);


		$params      = $this->form->getData()->get("params");
		$site_offset = Factory::getApplication()->get('offset'); //get offset of joomla time like asia/kolkata

		$log_path = str_replace('\\', '/', $app->get('log_path'));

        $page       = RadicalFormHelper::getHistoryLogPage($input->get->get('page', '0', 'raw'));
        $pageNumber = (string) $page;
        $logType    = $input->get->get('log', 'messages', 'raw') === 'spam' ? 'spam' : 'messages';
        $logLabel   = $this->getHistoryLogLabel($logType, (string) ($page ?? 0));
        $downloadPath = trim((string) ($params->downloadpath ?? ''), '/');
        $downloadPrefix = $logType === 'messages' && $downloadPath !== '' ? rtrim(Uri::root(), '/') . '/' . $downloadPath . '/' : '';
        $fileDelimiter = (string) ($params->delimiter ?? '<br />');
        $downloadSecret = (string) $app->get('secret', '');

		$logFiles = '<ul class="nav nav-tabs historytable">';
		foreach ($this->getHistoryLogTabs($log_path) as $logTab)
		{
			if ($logTab['log'] === $logType && $logTab['page'] === $pageNumber)
			{
				$logFiles .= "<li class='nav-item active'><a  class='nav-link active' aria-current='page' >" . htmlspecialchars($logTab['label'], ENT_QUOTES, 'UTF-8') . "</a></li> ";
			}
			else
			{
                $tabUrl = FormMessage::escape("{$currentURL}&page={$logTab['page']}&log={$logTab['log']}#attrib-list");
                $logFiles .= "<li class='nav-item'><a href='{$tabUrl}' class='nav-link' >" . FormMessage::escape($logTab['label']) . "</a></li> ";
			}
		}
		$logFiles .= '</ul>';

        $logFilePath        = $page === null ? null : RadicalFormHelper::getHistoryLogPath($log_path, $logType, $page);
        $escapedLogFilePath = htmlspecialchars($logFilePath ?? '', ENT_QUOTES, 'UTF-8');
        $historyReadWarning = $page === null
            ? $this->getHistoryFileWarning(Text::_('PLG_RADICALFORM_HISTORY_INVALID_ARCHIVE'))
            : '';
        $logFileSize        = 0;
        $data               = [];
        if ($logFilePath !== null)
		{
            $logFileSize = filesize($logFilePath);
			if (is_readable($logFilePath))
			{
				$data = RadicalFormHelper::getCSV($logFilePath, "\t");
			}
			else
			{
				$historyReadWarning = $this->getHistoryFileWarning(
					Text::sprintf('PLG_RADICALFORM_HISTORY_FILE_NOT_READABLE', $escapedLogFilePath)
				);
			}
		}
		$rawDataCount = count($data);
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
        if ($historyReadWarning === '' && $logFileSize > 0 && count($data) === 0)
		{
			$historyReadWarning = $this->getHistoryFileWarning(
				$rawDataCount > 0
					? Text::sprintf('PLG_RADICALFORM_HISTORY_FILE_NO_VALID_ROWS', $escapedLogFilePath)
					: Text::sprintf('PLG_RADICALFORM_HISTORY_FILE_NO_READABLE_ROWS', $escapedLogFilePath)
			);
		}
		$data                 = array_reverse($data);
		$cnt                  = count($data);
        $warningAboutRotation = $this->getLogRotationWarning();
		$pluginsInfo          = $this->getEnabledRadicalFormPluginsInfo();
		$showHiddenInfo       = isset($params->hiddeninfo) && $params->hiddeninfo;

		if ($cnt)
		{
			$formToken = Session::getFormToken();
			$logSizeLabel = $logType === 'spam' ? Text::_('PLG_RADICALFORM_SPAM_HISTORY_SIZE') : Text::_('PLG_RADICALFORM_HISTORY_SIZE');
            $html = "<p class='firstEntry'>" . $logSizeLabel . "<strong>" . $logFileSize . "</strong> " . Text::_('PLG_RADICALFORM_HISTORY_BYTE') . $warningAboutRotation . $pluginsInfo . "</p>";
			$html .= "<p class='historytable'><button class='btn btn-danger' id='historyclear' data-token='{$formToken}'>" . Text::sprintf('PLG_RADICALFORM_HISTORY_CLEAR', $logLabel) .
				"</button>";
			if ($logType !== 'spam')
			{
				$html .= " <button class='btn btn-outline-danger' id='numberclear' data-token='{$formToken}'>" . Text::_('PLG_RADICALFORM_HISTORY_NUMBER_CLEAR') . "</button>";
			}
			$html .= " <span class='pull-right float-end'><a href='index.php?option=com_ajax&plugin=radicalform&format=raw&group=system&admin=4&page={$pageNumber}&log={$logType}' class='btn btn-outline-primary exportcsv'>" . Text::sprintf('PLG_RADICALFORM_EXPORT_CSV', $logLabel) .
				"</a></span></p>";
			$html .= "<br><br>" . $logFiles;


			$html .= '<table class="table table-striped table-bordered adminlist historytable" ><thead><tr>';
			$html .= "<th>#</th>";
			$html .= '<th width="">' . Text::_('PLG_RADICALFORM_HISTORY_TIME') . '</th>';
			if (isset($params->showtarget) && $params->showtarget)
			{
				$html .= '<th width="">' . Text::_('PLG_RADICALFORM_HISTORY_TARGET') . '</th>';
			}
			if (isset($params->showformid) && $params->showformid)
			{
				$html .= '<th width="">' . Text::_('PLG_RADICALFORM_HISTORY_FORMID') . '</th>';
			}
			$html .= '<th width="">' . Text::_('PLG_RADICALFORM_HISTORY_IP') . '</th>';
			$html .= '<th>' . Text::_('PLG_RADICALFORM_HISTORY_MESSAGE') . '</th>';
			if ($showHiddenInfo)
			{
				$html .= '<th>' . Text::_('PLG_RADICALFORM_HISTORY_EXTRA') . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ($data as $i => $item)
			{
				$json = json_decode($item[2], true);
				if (is_array($json))
				{
					// new format of log file
					$json_result = json_last_error() === JSON_ERROR_NONE;

					$itog      = "";
					$extrainfo = "";
					if ($showHiddenInfo)
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
						$extrainfo = "<div class='muted small'>";

						foreach ($extraFieldsMap as $key => $label)
						{
							if (!isset($json[$key]))
							{
								continue;
							}

                            $value = FormMessage::text($json[$key]);
                            $value = in_array($key, ['url', 'reffer'], true)
                                ? FormMessage::webLink($value, null, 'rf-history-file-link')
                                : FormMessage::escape($value);

							$extrainfo .= $label . '<b>' . $value . "</b><br>";
							unset($json[$key]);
						}
						$extrainfo .= "</div>";
					}

					foreach (['url', 'reffer', 'resolution', 'pagetitle', 'rfUserAgent', 'rf-time', 'rf-duration'] as $key)
					{
						if (isset($json[$key]))
						{
							unset($json[$key]);
						}
					}
					$latestNumber = $logType === 'spam' ? $i + 1 : "";
					if ($logType !== 'spam' && isset($json["rfLatestNumber"]))
					{
						$latestNumber = $json["rfLatestNumber"];
						unset($json["rfLatestNumber"]);
					}

					if (isset($params->showtarget) && $params->showtarget)
					{
						if (isset($json["rfTarget"]) && (!empty($json["rfTarget"])))
						{
                            $target = "<td>" . FormMessage::escape(Text::_(FormMessage::text($json["rfTarget"]))) . "</td>";
							unset($json["rfTarget"]);
						}
						else
						{
							$target = "<td></td>";
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

					if (isset($params->showformid) && $params->showformid)
					{
						if (isset($json["rfFormID"]) && (!empty($json["rfFormID"])))
						{
                            $formid = "<td>" . FormMessage::escape(Text::_(FormMessage::text($json["rfFormID"]))) . "</td>";
							unset($json["rfFormID"]);
						}
						else
						{
							$formid = "<td></td>";
						}
					}
					else
					{
						$formid = "";
					}


					$historyRecordGlue = isset($params->glue) ? (string) $params->glue : ', ';
					foreach ($json as $key => $record)
					{
                        $record = self::formatHistoryRecord($record, $historyRecordGlue, $fileDelimiter, $downloadPrefix, $downloadSecret);
						$itog .= $this->getTranslatedFieldName((string) $key) . ": <b>" . $record . "</b><br />";
					}

					$jdate    = Factory::getDate($item[0]);
					$timezone = new \DateTimeZone($site_offset);
					$jdate->setTimezone($timezone);

                    $ip = FormMessage::text($item[1]);
                    $ipLabel = filter_var($ip, FILTER_VALIDATE_IP)
                        ? FormMessage::webLink('http://whois.domaintools.com/' . $ip, $ip)
                        : FormMessage::escape($ip);
					$html .= '<tr class="row' . ($i % 2) . '">' .
                        '<td>' . FormMessage::escape(FormMessage::text($latestNumber)) . '</td>' .
						'<td class="nowrap">' . $jdate->format('H:i:s', true) . '<br><span class="muted">' . $jdate->format('d.m.Y', true) . '</span></td>' .
						$target .
						$formid .
                        '<td>' . $ipLabel . '</td>';
					if (isset($item[3]) && in_array($item[3], ["WARNING", "ERROR"], true))
					{
                        $warningTitle   = isset($json["rfAntiSpam"]) ? Text::_('PLG_RADICALFORM_ANTISPAM') . ': ' . FormMessage::text($json["rfAntiSpam"]) : $item[3];
                        $warningTitle   = isset($json["rfWarningMessage"]) ? $warningTitle . ': ' . FormMessage::text($json["rfWarningMessage"]) : $warningTitle;
                        $warningContent = $json_result ? $itog : FormMessage::escape($item[2]);
                        $html           .= '<td style="max-width: 500px; overflow: hidden; color: #9f2620;"><details><summary style="cursor: pointer; color: #9f2620;">' . FormMessage::escape($warningTitle) . '</summary><div class="rfMarginTop">' . $warningContent . '</div></details></td>' .
							($showHiddenInfo ? '<td style="max-width: 500px; overflow: hidden;">' . $extrainfo . '</td>' : '') .
							'</tr>';
					}
					else
					{
                        $html .= '<td style="max-width: 500px; overflow: hidden;">' . ($json_result ? $itog : FormMessage::escape($item[2])) . '</td>' .
							($showHiddenInfo ? '<td style="max-width: 500px; overflow: hidden;">' . $extrainfo . '</td>' : '') .
							'</tr>';
					}
				}

			}
			$html .= '</tbody></table>';
		}
		else
		{
			$emptyHistoryMessage = $historyReadWarning !== ''
				? $historyReadWarning
				: '<div class="historytable"><div class="alert alert-info alert-dismissible show">' . Text::sprintf('PLG_RADICALFORM_HISTORY_EMPTY', $logLabel) . '</div></div>';
			$html = "{$logFiles}<p class='firstEntry'>{$warningAboutRotation}{$pluginsInfo}</p>" . $emptyHistoryMessage;
		}

        return $html;
	}

    private static function formatHistoryRecord(mixed $record, string $glue, string $fileDelimiter, string $downloadPrefix, string $secret): string
    {
        if (is_string($record) && preg_match('~\Ahttps?://(.+)\z~i', $downloadPrefix, $prefixParts) === 1)
        {
            // Logs keep attachment values flattened for CSV and compatibility with existing records.
            $boundary = preg_quote($fileDelimiter, '~') . '(?i:https?)://' . preg_quote($prefixParts[1], '~');
            $parts = preg_split('~(' . $boundary . ')~', $record, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$record];
            $links = [];

            // Only the appended, recognized links use the configured attachment separator.
            while (count($parts) > 1)
            {
                $suffix = end($parts);
                if (!self::isHistoryAttachment($suffix, $secret))
                {
                    break;
                }

                array_pop($parts);
                $links[] = substr(array_pop($parts), strlen($fileDelimiter)) . $suffix;
            }

            if ($links !== [])
            {
                $links[] = implode('', $parts);

                return FormMessage::format(array_reverse($links), $fileDelimiter, FormMessage::LINKED_HTML, false);
            }
        }

        return FormMessage::format($record, $glue, FormMessage::LINKED_HTML);
    }

    private static function isHistoryAttachment(string $suffix, string $secret): bool
    {
        [$path, $query] = array_pad(explode('?', $suffix, 2), 2, null);
        $segments = explode('/', $path);
        if (count($segments) !== 3)
        {
            return false;
        }

        [$group, $folder, $name] = $segments;
        if (!preg_match('/\A' . UploadStorage::GROUP_PATTERN . '\z/', $group)
            || !UploadStorage::isSegment($folder) || !UploadStorage::isSegment($name))
        {
            return false;
        }

        if (!str_starts_with($group, 'v2-'))
        {
            return $query === null;
        }

        if ($secret === '' || $query === null || !preg_match('/\Asig=([a-f0-9]{64})\z/', $query, $matches))
        {
            return false;
        }

        $expected = hash_hmac('sha256', "radicalform.download/$group/$folder/$name", $secret);

        return hash_equals($expected, $matches[1]);
    }

	/**
	 * Returns warning markup for log file read issues.
	 *
	 * @param   string  $message  Warning message
	 *
	 * @return  string
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function getHistoryFileWarning(string $message): string
	{
		return '<div class="historytable"><div class="alert alert-warning alert-dismissible show">'
			. $message
			. '</div></div>';
	}

	private function getHistoryLogLabel(string $logType, string $page): string
	{
		$label = $logType === 'spam' ? Text::_('PLG_RADICALFORM_LOG_SPAM') : Text::_('PLG_RADICALFORM_LOG_MESSAGES');

		return $page === '0' ? $label : $label . ' #' . $page;
	}

    private function getHistoryLogTabs(string $logPath): array
    {
        $tabs = [];

        foreach (['messages', 'spam'] as $logType)
        {
            $pages = array_keys(RadicalFormHelper::getHistoryLogArchives($logPath, $logType));
            if ($logType === 'messages' || RadicalFormHelper::getHistoryLogPath($logPath, $logType) !== null)
            {
                array_unshift($pages, 0);
            }

            foreach ($pages as $page)
            {
                $index = (string) $page;
                $tabs[] = [
                    'log' => $logType,
                    'page' => $index,
                    'label' => $this->getHistoryLogLabel($logType, $index),
                ];
            }
        }

        return $tabs;
    }

	/**
	 * Returns a warning about the next Joomla scheduled log rotation.
	 *
	 * @return  string
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function getLogRotationWarning(): string
	{
		try
		{
			$db = Factory::getContainer()->get(DatabaseInterface::class);

			$query = $db->getQuery(true)
				->select($db->quoteName(['t.next_execution', 't.params']))
				->from($db->quoteName('#__scheduler_tasks') . ' AS ' . $db->quoteName('t'))
				->innerJoin(
					$db->quoteName('#__extensions') . ' AS ' . $db->quoteName('e')
					. ' ON ' . $db->quoteName('e.type') . ' = ' . $db->quote('plugin')
					. ' AND ' . $db->quoteName('e.folder') . ' = ' . $db->quote('task')
					. ' AND ' . $db->quoteName('e.element') . ' = ' . $db->quote('rotatelogs')
					. ' AND ' . $db->quoteName('e.enabled') . ' = 1'
				)
				->where($db->quoteName('t.type') . ' = ' . $db->quote('rotation.logs'))
				->where($db->quoteName('t.state') . ' = 1')
				->where($db->quoteName('t.next_execution') . ' IS NOT NULL')
				->order($db->quoteName('t.next_execution') . ' ASC');

			$db->setQuery($query, 0, 1);
			$task = $db->loadObject();
		}
		catch (\Throwable)
		{
			return '';
		}

		if (!$task)
		{
			return '';
		}

		$params     = json_decode($task->params, true);
		$logsToKeep = max(1, (int) ($params['logstokeep'] ?? 1));
		$daysLeft   = max(0, (int) floor((Factory::getDate($task->next_execution)->getTimestamp() - time()) / (3600 * 24)));

		return Text::sprintf('PLG_RADICALFORM_WARNING_ABOUT_ROTATION', $daysLeft, $logsToKeep);
	}

	/**
	 * Returns information about enabled RadicalForm plugins.
	 *
	 * @return  string
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function getEnabledRadicalFormPluginsInfo(): string
	{
		try
		{
			$db = Factory::getContainer()->get(DatabaseInterface::class);

			$query = $db->getQuery(true)
				->select($db->quoteName(['name', 'element', 'manifest_cache']))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('radicalform'))
				->where($db->quoteName('enabled') . ' = 1')
				->order($db->quoteName('ordering') . ' ASC');

			$db->setQuery($query);
			$plugins = $db->loadObjectList();
		}
		catch (\Throwable)
		{
			return '';
		}

		if (!$plugins)
		{
			return '';
		}

		$names = [];
		foreach ($plugins as $plugin)
		{
			$language = Factory::getApplication()->getLanguage();
			$language->load('plg_radicalform_' . $plugin->element, JPATH_ADMINISTRATOR);
			$language->load('plg_radicalform_' . $plugin->element . '.sys', JPATH_ADMINISTRATOR);
			$language->load('plg_radicalform_' . $plugin->element, JPATH_SITE);
			$language->load('plg_radicalform_' . $plugin->element . '.sys', JPATH_SITE);

			$manifest = json_decode($plugin->manifest_cache, true);
			$name     = $manifest['name'] ?? $plugin->name ?? $plugin->element;
			$name     = $language->hasKey($name) ? Text::_($name) : $plugin->element;

			$names[] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
		}

		return '<br>' . Text::_('PLG_RADICALFORM_ENABLED_PLUGINS') . ' <strong>' . implode('</strong>, <strong>', $names) . '</strong>';
	}

	/**
	 * Returns translated form field name.
	 *
	 * @param   string  $key  Field key
	 *
	 * @return  string
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function getTranslatedFieldName(string $key): string
	{
		$language = Factory::getApplication()->getLanguage();
		$language->load('override', JPATH_ADMINISTRATOR);
		$language->load('override', JPATH_SITE);

		foreach ([$key, strtoupper($key)] as $constant)
		{
			if ($language->hasKey($constant))
			{
				return htmlspecialchars(Text::_($constant), ENT_QUOTES, 'UTF-8');
			}
		}

		return htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
	}
}
