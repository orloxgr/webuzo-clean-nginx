<?php
// Isolated integration harness only. Production uses LocalService's fixed, root-owned socket.
$stream = stream_socket_client('unix://' . $argv[1], $number, $message, 3);
if (!$stream) { exit(1); }
stream_set_timeout($stream, 20);
fwrite($stream, json_encode(['v' => 1, 'op' => $argv[2]]) . "\n");
$response = fgets($stream, 8193);
fclose($stream);
if (!$response) { exit(2); }
echo $response;
