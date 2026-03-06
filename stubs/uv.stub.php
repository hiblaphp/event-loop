<?php

/** @return \UVSignal */
function uv_signal_init(\UVLoop $loop): \UVSignal {}

/** @return int */
function uv_signal_start(\UVSignal $handle, callable $callback, int $signal): int {}

/** @return \UVPoll */
function uv_poll_init_socket(\UVLoop $loop, mixed $socket): \UVPoll {}