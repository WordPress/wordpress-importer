<?php

namespace WordPress\HttpClient\Transport;

interface TransportInterface {

	public function event_loop_tick(): bool;
}
