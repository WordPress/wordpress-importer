<?php

namespace WordPress\ByteStream\ByteTransformer;

/**
 * A reader that computes a checksum of the bytes read.
 */
class ChecksumTransformer implements ByteTransformer {

	private $hash_context;
	private $checksum;
	private $flush_hash;
	private $binary_output;

	public function __construct( string $encoding = 'sha1', $options = array() ) {
		$this->hash_context  = hash_init( $encoding );
		$this->flush_hash    = $options['flush_hash'] ?? false;
		$this->binary_output = $options['binary_output'] ?? false;
	}

	public function filter_bytes( string $bytes ) {
		hash_update( $this->hash_context, $bytes );

		return $bytes;
	}

	public function flush(): string {
		if ( $this->flush_hash ) {
			return $this->get_hash();
		}

		return '';
	}

	public function get_hash(): string {
		if ( $this->hash_context ) {
			$this->checksum     = hash_final( $this->hash_context, $this->binary_output );
			$this->hash_context = null;
		}

		return $this->checksum;
	}
}
