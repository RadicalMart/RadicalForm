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

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

class StorageField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var  string
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected $type = 'radicalform_storage';

	/**
	 * Method to get the directory size.
	 *
	 * @param   string  $path  Directory path
	 *
	 * @return  int
	 *
	 * @since   1.0.0
	 */
	private function getDirectorySize(string $path)
	{
		$fileSize = 0;
		$dir      = scandir($path);

		foreach ($dir as $file)
		{
			if (($file != '.') && ($file != '..'))
			{
				$fullPath = $path . '/' . $file;

				if (is_link($fullPath))
				{
					continue;
				}

				if (is_dir($fullPath))
					$fileSize += $this->getDirectorySize($fullPath);
				else
					$fileSize += filesize($fullPath);
			}
		}

		return $fileSize;
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
		$totalfree = (int) (disk_free_space(JPATH_ROOT) / 1048576);
		$params    = $this->form->getData()->get("params");
		if (isset($params->maxstorage))
		{
			$totalstorage = $params->maxstorage;
		}
		else
		{
			$totalstorage = 1000;
		}

		$storagedirectory = (int) ($this->getDirectorySize($params->uploadstorage) / 1048576);
		$html             = '    <div class="progress" style="max-width: 800px"> <div class="bar" style="width: ' . (int) (($storagedirectory / $totalstorage) * 100) . '%;"></div> </div>';
		$total            = (int) (disk_total_space(JPATH_ROOT) / 1048576);

		$upfree = (int) ((($totalstorage - $storagedirectory) / $totalfree) * 100);
		// when the storage more than free space on the system
		if (($totalstorage - $storagedirectory) > $totalfree)
		{
			$html .= '<div class="alert alert-error" style="max-width: 750px;"> ' . Text::_('PLG_RADICALFORM_TOTALDANGER') . ' </div>';
		}

		$class = "progress-success";
		if ($upfree > 70)
		{
			$class = "progress-warning";
		}
		if ($upfree > 90)
		{
			$class = "progress-danger";
		}
		$html .= Text::sprintf('PLG_RADICALFORM_TOTALFREE', ($totalstorage - $storagedirectory), $totalfree) . '<div class="progress ' . $class . '" style="max-width: 800px"> <div class="bar" style="width: ' . $upfree . '%;"></div> </div>';

		return Text::sprintf('PLG_RADICALFORM_TOTALSIZE', $totalstorage, $storagedirectory) . $html;
	}

}
