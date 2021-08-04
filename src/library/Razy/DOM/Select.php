<?php

/*
 * This file is part of Razy v0.4.
 *
 * (c) Ray Fung <hello@rayfung.hk>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Razy\DOM;

use Closure;
use Razy\DOM;
use Razy\Error;

class Select extends DOM
{
	/**
	 * Select constructor.
	 */
	public function __construct(string $id = '')
	{
		parent::__construct($id);
		$this->setTag('select');
	}

	/**
	 * Get the value.
	 *
	 * @return mixed The value of the control
	 */
	public function getValue()
	{
		foreach ($this->nodes as $node) {
			if ($node instanceof DOM) {
				if ($node->hasAttribute('selected')) {
					return $node->getAttribute('selected');
				}
			}
		}

		return null;
	}

	/**
	 * Set the value.
	 *
	 * @param mixed $value The value of the control
	 *
	 * @throws \Razy\Error
	 *
	 * @return self Chainable
	 */
	public function setValue(string $value): DOM
	{
		foreach ($this->nodes as $node) {
			if ($node instanceof DOM) {
				if ($node->getAttribute('value') == $value) {
					$node->setAttribute('selected', 'selected');
				} else {
					$node->removeAttribute('selected');
				}
			}
		}

		return $this;
	}

	/**
	 * @param bool $enable
	 *
	 * @throws \Razy\Error
	 *
	 * @return $this
	 */
	public function isMultiple(bool $enable): Select
	{
		if ($enable) {
			$this->setAttribute('multiple', 'multiple');
		} else {
			$this->removeAttribute('multiple');
		}

		return $this;
	}

	/**
	 * Apply a bulk of options by given array.
	 *
	 * @throws \Razy\Error
	 *
	 * @return $this
	 */
	public function applyOptions(array $dataset, Closure $convertor = null): self
	{
		foreach ($dataset as $key => $value) {
			$option = $this->addOption();
			if ($convertor) {
				call_user_func($convertor, $option, $key, $value);
			} else {
				if (is_string($value)) {
					$option->setText($value)->setAttribute('value', $key);
				} else {
					throw new Error('The option value must be a string');
				}
			}
		}

		return $this;
	}

	/**
	 * Add and append an option DOM.
	 *
	 * @throws \Razy\Error
	 */
	public function addOption(string $label = '', string $value = ''): DOM
	{
		$option = new DOM();
		$option->setTag('option')->setText($label)->setAttribute('value', $value);
		$this->append($option);

		return $option;
	}
}
