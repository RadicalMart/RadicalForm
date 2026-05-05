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
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Plugin\System\RadicalForm\Helper\RadicalFormHelper;

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
		$get   = $input->get->getArray();

		$l = Uri::getInstance();

		$http = parse_url($l->toString());
		parse_str($http['query'], $output);
		if (isset($output['page']))
		{
			unset($output['page']);
		}
		$currentURL = $http["path"] . '?' . http_build_query($output);


		$params      = $this->form->getData()->get("params");
		$site_offset = Factory::getApplication()->get('offset'); //get offset of joomla time like asia/kolkata

		$log_path = str_replace('\\', '/', $app->get('log_path'));

		$page = '';
		if (isset($get['page']))
		{
			if ($get['page'] == "0")
			{
				$page = '';
			}
			else
			{
				$page = $get['page'] . ".";
			}
		}

		$logFiles = '<ul class="nav nav-tabs historytable">';
		if ($page)
		{
			$logFiles .= " <li class='nav-item'><a href='{$currentURL}&page=0#attrib-list' class='nav-link' >plg_system_radicalform.php</a> </li>";
		}
		else
		{
			$logFiles .= "<li class='nav-item active'><a  class='nav-link active' aria-current='page' >plg_system_radicalform.php</a></li> ";
		}

		foreach (glob($log_path . "/*.plg_system_radicalform.php") as $filename)
		{
			$currentNumber = strstr(pathinfo($filename, PATHINFO_BASENAME), ".", true);
			if ($currentNumber == $page)
			{
				$logFiles .= "<li class='nav-item active'><a  class='nav-link active' aria-current='page' >" . pathinfo($filename, PATHINFO_BASENAME) . "</a></li> ";
			}
			else
			{
				$logFiles .= "<li class='nav-item'><a href='{$currentURL}&page={$currentNumber}#attrib-list' class='nav-link' >" . pathinfo($filename, PATHINFO_BASENAME) . "</a></li> ";
			}
		}
		$logFiles .= '</ul>';

		$data = RadicalFormHelper::getCSV($log_path . '/' . $page . 'plg_system_radicalform.php', "\t");
		if (count($data) > 0)
		{
			for ($i = 0; $i < 6; $i++)
			{
				if (count($data[$i]) < 4 || $data[$i][0][0] == '#')
				{
					unset($data[$i]);
				}
			}
		}
		$data                 = array_reverse($data);
		$cnt                  = count($data);
		$warningAboutRotation = $this->getLogRotationWarning();

		if ($cnt)
		{
			$html = "<p class='firstEntry'>" . Text::_('PLG_RADICALFORM_HISTORY_SIZE') . "<strong>" . filesize($log_path . '/' . $page . 'plg_system_radicalform.php') . "</strong> " . Text::_('PLG_RADICALFORM_HISTORY_BYTE') . $warningAboutRotation . "</p>";
			$html .= "<p class='historytable'><button class='btn btn-danger' id='historyclear'>" . Text::sprintf('PLG_RADICALFORM_HISTORY_CLEAR', $page . "plg_system_radicalform.php") .
				"</button> <button class='btn btn-outline-danger' id='numberclear'>" . Text::_('PLG_RADICALFORM_HISTORY_NUMBER_CLEAR') .
				"</button> <span class='pull-right float-end'><a href='index.php?option=com_ajax&plugin=radicalform&format=raw&group=system&admin=4&page=" . (($page == "") ? "0" : strstr($page, ".", true)) . "' class='btn btn-outline-primary' id='exportcsv'>" . Text::sprintf('PLG_RADICALFORM_EXPORT_CSV', $page . "plg_system_radicalform.php") .
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
			$html .= '</tr></thead><tbody>';
			foreach ($data as $i => $item)
			{
				$json = json_decode($item[2], true);
				if (is_array($json))
				{
					// new format of log file
					$json_result = json_last_error() === JSON_ERROR_NONE;

					$itog      = "";
					$extrainfo = "<div class='rfMarginTop muted small'>";
					if ($params->hiddeninfo)
					{
						if (isset($json["url"]))
						{
							$extrainfo .= Text::_('PLG_RADICALFORM_URL') . '<b>' . $json["url"] . "</b><br>";
						}
						if (isset($json["reffer"]))
						{
							$extrainfo .= Text::_('PLG_RADICALFORM_REFFER') . '<b>' . $json["reffer"] . "</b><br>";
						}
						if (isset($json["resolution"]))
						{
							$extrainfo .= Text::_('PLG_RADICALFORM_RESOLUTION') . '<b>' . $json["resolution"] . "</b><br>";
						}
						if (isset($json["pagetitle"]))
						{
							$extrainfo .= Text::_('PLG_RADICALFORM_PAGETITLE') . '<b>' . $json["pagetitle"] . "</b><br>";
						}
						if (isset($json["rfUserAgent"]))
						{
							$extrainfo .= Text::_('PLG_RADICALFORM_USERAGENT') . '<b>' . $json["rfUserAgent"] . "</b><br>";
						}
						if (isset($json["rf-time"]))
						{
							$extrainfo .= Text::_('PLG_RADICALFORM_USER_TIME') . '<b>' . $json["rf-time"] . "</b><br>";
						}
						if (isset($json["rf-duration"]))
						{
							$extrainfo .= Text::sprintf('PLG_RADICALFORM_FORM_DURATION', $json["rf-duration"]);
						}

					}
					$extrainfo .= "</div>";
					if (isset($json["url"]))
					{
						unset($json["url"]);
					}
					if (isset($json["rf-duration"]))
					{
						unset($json["rf-duration"]);
					}
					if (isset($json["rf-time"]))
					{
						unset($json["rf-time"]);
					}
					if (isset($json["reffer"]))
					{
						unset($json["reffer"]);
					}
					if (isset($json["resolution"]))
					{
						unset($json["resolution"]);
					}
					if (isset($json["pagetitle"]))
					{
						unset($json["pagetitle"]);
					}
					if (isset($json["rfUserAgent"]))
					{
						unset($json["rfUserAgent"]);
					}
					$latestNumber = "";
					if (isset($json["rfLatestNumber"]))
					{
						$latestNumber = $json["rfLatestNumber"];
						unset($json["rfLatestNumber"]);
					}

					if (isset($params->showtarget) && $params->showtarget)
					{
						if (isset($json["rfTarget"]) && (!empty($json["rfTarget"])))
						{
							$target = "<td>" . Text::_($json["rfTarget"]) . "</td>";
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
							$formid = "<td>" . Text::_($json["rfFormID"]) . "</td>";
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


					foreach ($json as $key => $record)
					{
						if (is_array($record))
						{
							$record = implode($params->glue, $record);
						}
						$itog .= Text::_($key) . ": <b>" . $record . "</b><br />";
					}

					$jdate    = Factory::getDate($item[0]);
					$timezone = new \DateTimeZone($site_offset);
					$jdate->setTimezone($timezone);

					$html .= '<tr class="row' . ($i % 2) . '">' .
						'<td>' . $latestNumber . '</td>' .
						'<td class="nowrap">' . $jdate->format('H:i:s', true) . '<br><span class="muted">' . $jdate->format('d.m.Y', true) . '</span></td>' .
						$target .
						$formid .
						'<td><a href="http://whois.domaintools.com/' . $item[1] . '" target="_blank">' . $item[1] . '</a></td>';
					if (isset($item[3]) && in_array($item[3], ["WARNING", "ERROR"], true))
					{
						$warningTitle   = isset($json["rfAntiSpam"]) ? Text::_('PLG_RADICALFORM_ANTISPAM') . ': ' . $json["rfAntiSpam"] : $item[3];
						$warningTitle   = $item[3] === "ERROR" && isset($json["message"]) ? $item[3] . ': ' . $json["message"] : $warningTitle;
						$warningContent = ($json_result ? $itog : htmlspecialchars($item[2])) . $extrainfo;
						$html           .= '<td style="max-width: 700px; overflow: hidden; color: #9f2620;"><details><summary style="cursor: pointer; color: #9f2620;">' . htmlspecialchars($warningTitle) . '</summary><div class="rfMarginTop">' . $warningContent . '</div></details></td>' .
							'</tr>';
					}
					else
					{
						$html .= '<td style="max-width: 700px; overflow: hidden;">' . ($json_result ? '' . $itog . '' : htmlspecialchars($item[2])) . $extrainfo . '</td>' .
							'</tr>';
					}
				}

			}
			$html .= '</tbody></table>';
		}
		else
		{
			$html = "{$logFiles}<p class='firstEntry'>{$warningAboutRotation}</p>" . '<div class="historytable"><div class="alert alert-info  alert-dismissible show">' . Text::sprintf('PLG_RADICALFORM_HISTORY_EMPTY', $page . "plg_system_radicalform.php") . '</div></div>';
		}

		$html = preg_replace('/(?<!a href=\'|\")(?<!src=\"|\')((http)+(s)?:\/\/[^<>\s]+)(?<![\.,:])/i', "<a href='$0' target='_blank'>$0</a>", $html);

		return $html;
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
}
