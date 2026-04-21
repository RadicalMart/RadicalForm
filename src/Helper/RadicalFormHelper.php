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

use Joomla\Filesystem\Path;

class RadicalFormHelper
{
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
			while (($data = fgetcsv($handle, 200000, $delimiter)) !== false)
			{
				$a[] = $data;
			}
			fclose($handle);
		}

		return $a;
	}
}
