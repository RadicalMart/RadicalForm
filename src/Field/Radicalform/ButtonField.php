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

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;

\defined('_JEXEC') or die;

class ButtonField extends FormField
{
	/**
	 * The form field type.
	 *
	 * @var  string
	 *
	 * @since  __DEPLOY_VERSION__
	 */
	protected $type = 'radicalform_button';

	/**
	 * Method to get the field input markup.
	 *
	 * @return  string  The field input markup.
	 *
	 * @since   1.0.0
	 */
	function getInput()
	{
		// Load assets
		/** @var \Joomla\CMS\WebAsset\WebAssetManager $assets */
		$assets = Factory::getApplication()->getDocument()->getWebAssetManager();
		$assets->getRegistry()->addExtensionRegistryFile('plg_system_radicalform');
		$assets->usePreset('plg_system_radicalform.config');

		$resultId = $this->element['resultid'] ?: 'radicalformresult';

		return "<button onclick=\"\" id='" . $this->element['id'] . "' class=\"btn btn-secondary control-group\"><span class=\"icon-refresh\"></span>" . Text::_($this->element['value']) . "</button><div id=\"" . $resultId . "\"></div>";
	}

}
