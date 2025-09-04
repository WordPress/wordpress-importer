<?php

/**
 * Avoid name collisions if another instance of XMLProcessor is already loaded.
 */
if ( ! class_exists( 'WordPress\XML\XMLProcessor' ) ) {
	// ByteStream (exceptions first, then interfaces and implementations)
	require_once __DIR__ . '/ByteStream/class-bytestreamexception.php';
	require_once __DIR__ . '/ByteStream/class-notenoughdataexception.php';
	require_once __DIR__ . '/ByteStream/class-bytereadstream.php';
	require_once __DIR__ . '/ByteStream/class-basebytereadstream.php';
	require_once __DIR__ . '/ByteStream/class-filereadstream.php';

	// Encoding utilities used by XMLDecoder
	require_once __DIR__ . '/Encoding/utf8.php';

	// XML (data structures and exceptions before the processor)
	require_once __DIR__ . '/XML/class-xmlattributetoken.php';
	require_once __DIR__ . '/XML/class-xmlelement.php';
	require_once __DIR__ . '/XML/class-xmlunsupportedexception.php';
	require_once __DIR__ . '/XML/class-xmldecoder.php';
	require_once __DIR__ . '/XML/class-xmlprocessor.php';

	// Block Serialization Parser (used by some DataLiberation processors)
	require_once __DIR__ . '/BlockParser/class-wpblockparsererror.php';
	require_once __DIR__ . '/BlockParser/class-wpblockparserblock.php';
	require_once __DIR__ . '/BlockParser/class-wpblockparserframe.php';
	require_once __DIR__ . '/BlockParser/class-wpblockparser.php';

	// URL helpers and processors
	require_once __DIR__ . '/URL/class-wpurl.php';
	require_once __DIR__ . '/URL/functions.php';
	require_once __DIR__ . '/URL/class-urlintextprocessor.php';
	require_once __DIR__ . '/URL/public_suffix_list.php';

	// DataLiberation core types
	require_once __DIR__ . '/DataLiberation/class-importentity.php';

	// DataLiberation Block Markup processors
	require_once __DIR__ . '/DataLiberation/BlockMarkup/class-blockmarkupprocessor.php';
	require_once __DIR__ . '/DataLiberation/BlockMarkup/class-blockmarkupurlprocessor.php';
	require_once __DIR__ . '/DataLiberation/BlockMarkup/class-blockobject.php';

	// DataLiberation Entity Readers
	require_once __DIR__ . '/DataLiberation/EntityReader/entity-reader.php';
	require_once __DIR__ . '/DataLiberation/EntityReader/class-wxrentityreader.php';
}
