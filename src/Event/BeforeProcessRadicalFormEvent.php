<?php

/*
 * @package   RadicalForm
 * @version   __DEPLOY_VERSION__
 * @author    Vladimir Eliseev aka Progreccor - https://progreccor.ru
 * @copyright Copyright (c) 2025 Progreccor. All rights reserved.
 * @license   GNU/GPL license: http://www.gnu.org/copyleft/gpl.html
 * @link      https://progreccor.ru
 */

namespace Joomla\Plugin\System\RadicalForm\Event;

defined('_JEXEC') or die;

use Joomla\CMS\Event\AbstractImmutableEvent;
use Joomla\CMS\Event\Result\ResultAware;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Event\Result\ResultTypeMixedAware;

class BeforeProcessRadicalFormEvent extends AbstractImmutableEvent implements ResultAwareInterface
{
	use ResultAware;
	use ResultTypeMixedAware;

	public function __construct($name, array $arguments = [])
	{
		parent::__construct($name, $arguments);

		if (!array_key_exists('clearInput', $this->arguments))
		{
			throw new \BadMethodCallException("Argument 'clearInput' of event {$name} is required but has not been provided");
		}

		if (!array_key_exists('input', $this->arguments))
		{
			throw new \BadMethodCallException("Argument 'input' of event {$name} is required but has not been provided");
		}

		if (!array_key_exists('params', $this->arguments))
		{
			throw new \BadMethodCallException("Argument 'params' of event {$name} is required but has not been provided");
		}

		if (array_key_exists('input', $arguments))
		{
			$this->arguments['input'] = &$arguments['input'];
		}
	}

	public function getClearInput(): array
	{
		return (array) $this->arguments['clearInput'];
	}

	public function &getInput(): array
	{
		return $this->arguments['input'];
	}

	public function getParams()
	{
		return $this->arguments['params'];
	}

	protected function onSetClearInput(array $value): array
	{
		return $value;
	}

	protected function onSetInput(array $value): array
	{
		return $value;
	}
}
