<?php

/**
 * Avoid name collisions if another instance of XMLProcessor is already loaded.
 */
if ( ! class_exists( 'WordPress\XML\XMLProcessor' ) ) {
	require_once __DIR__ . '/class-bytereadstream.php';
	require_once __DIR__ . '/class-basebytereadstream.php';
	require_once __DIR__ . '/class-filereadstream.php';
	require_once __DIR__ . '/class-bytestreamexception.php';
	require_once __DIR__ . '/class-notenoughdataexception.php';
	require_once __DIR__ . '/class-xmldecoder.php';
	require_once __DIR__ . '/class-xmlattributetoken.php';
	require_once __DIR__ . '/class-xmlelement.php';
	require_once __DIR__ . '/class-xmlunsupportedexception.php';
	require_once __DIR__ . '/class-xmlprocessor.php';
	require_once __DIR__ . '/entity-reader.php';
	require_once __DIR__ . '/class-wxrentityreader.php';
	require_once __DIR__ . '/utf8.php';
	require_once __DIR__ . '/class-importentity.php';
}
