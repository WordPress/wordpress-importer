<?php

declare( strict_types=1 );

namespace WordPressImporter\Rowbot\URL\Tests;

use WordPressImporter\Psr\Log\LoggerInterface;
use WordPressImporter\Psr\Log\LoggerTrait;
use Stringable;

final class ValidationErrorLogger implements LoggerInterface {
	use LoggerTrait;

	/**
	 * @var mixed[]
	 */
	private $messages;

	/**
	 * @param  string|Stringable  $message
	 */
	public function log( $level, $message, array $context = [] ): void {
		$this->messages[] = [ $level, $message, $context ];
	}
}
