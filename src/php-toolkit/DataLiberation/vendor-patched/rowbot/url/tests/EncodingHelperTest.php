<?php

declare( strict_types=1 );

namespace WordPressImporter\Rowbot\URL\Tests;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use WordPressImporter\Rowbot\URL\Support\EncodingHelper;

class EncodingHelperTest extends TestCase {
	public function testReplacementAndUtf16EncodingsGetForcedToUtf8( string $encoding, string $outputEncoding ): void {
		self::assertSame( $outputEncoding, EncodingHelper::getOutputEncoding( $encoding ) );
	}
}
