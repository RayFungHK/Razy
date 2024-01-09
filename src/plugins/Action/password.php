<?php

use Razy\Action;
use Razy\Action\Plugin;

/**
 * Check password
 * Error code:  *_length_too_short
 *              *_verify_not_match
 *              *_previous_not_match
 */
return new class extends Plugin
{
	private int $length = 6;
	public function setup(int $length): self
	{
		$this->length = $length;

		return $this;
	}

	public function onValidate(mixed &$value, mixed $compare = null): bool
	{
		$value = trim($value);
		$passwordVerify = $this->getValue($this->getName() . '_verify');
		if (Action::TYPE_CREATE === $this->getType() || (Action::TYPE_EDIT === $this->getType() && $value)) {
			if (strlen($value) < $this->length) {
				$this->reject('length_too_short');
				return false;
			}

			if ($value !== $passwordVerify) {
				$this->reject('verify_not_match');
				return false;
			}

			if (Action::TYPE_EDIT === $this->getType() && $value !== $compare) {
				$this->reject('previous_not_match');
				return false;
			}
		}

		$value = md5($value);
		return true;
	}

	public function onSubmit(array &$parameters): array
	{
		if (Action::TYPE_CREATE !== $this->getType() && !$parameters['password']) {
			unset($parameters['password']);
		}

		return $parameters;
	}
};