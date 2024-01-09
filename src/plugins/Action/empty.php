<?php

use Razy\Action\Plugin;

/**
 * Trim and not allow empty string
 * Error code:  *_not_empty
 */
return new class extends Plugin {
	public function onValidate(mixed &$value, mixed $compare = null): bool
	{
		$value = trim($value);
		if (!$value) {
			$this->reject('not_empty');
			return false;
		}

		return true;
	}
};