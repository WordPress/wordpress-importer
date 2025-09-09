<?php

/**
 * Avoid name collisions if another instance of XMLProcessor is already loaded.
 */
if ( ! class_exists( 'WordPress\XML\XMLProcessor' ) ) {
	require_once __DIR__ . '/ByteStream/class-bytereadstream.php';
	require_once __DIR__ . '/ByteStream/class-basebytereadstream.php';
	require_once __DIR__ . '/ByteStream/class-filereadstream.php';
	require_once __DIR__ . '/ByteStream/class-bytestreamexception.php';
	require_once __DIR__ . '/ByteStream/class-notenoughdataexception.php';
	require_once __DIR__ . '/XML/class-xmldecoder.php';
	require_once __DIR__ . '/XML/class-xmlattributetoken.php';
	require_once __DIR__ . '/XML/class-xmlelement.php';
	require_once __DIR__ . '/XML/class-xmlunsupportedexception.php';
	require_once __DIR__ . '/XML/class-xmlprocessor.php';
	require_once __DIR__ . '/DataLiberation/EntityReader/interface-entity-reader.php';
	require_once __DIR__ . '/DataLiberation/EntityReader/class-wxrentityreader.php';
	require_once __DIR__ . '/Encoding/utf8-decoder.php';
	require_once __DIR__ . '/Encoding/utf8-encoder.php';
	require_once __DIR__ . '/DataLiberation/class-importentity.php';
}
