<?php

/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace ElephantIO\Engine\SocketIO;

use UnexpectedValueException;
use InvalidArgumentException;
use ElephantIO\Payload\Encoder;
use ElephantIO\EngineInterface;
use Exception;

/**
 * Implements the dialog with Socket.IO version 3.x
 */
class Version3X extends Version2X
{

    /** {@inheritDoc} */
    public function getName()
    {
        return 'SocketIO Version 3.X';
    }

    /** {@inheritDoc} */
    protected function getDefaultOptions()
    {
        $defaults = parent::getDefaultOptions();

        $defaults['version'] = 4;

        return $defaults;
    }

    /**
     * Upgrades the transport to WebSocket
     *
     * FYI:
     * Version "2" is used for the EIO param by socket.io v1
     * Version "3" is used by socket.io v2
     * Version "4" is used by socket.io v3/4
     */
    protected function upgradeTransport()
    {
        $query = [
            'sid'       => $this->session->id,
            'EIO'       => $this->options['version'],
            'transport' => static::TRANSPORT_WEBSOCKET
        ];

        $url = sprintf('/%s/?%s', trim($this->url['path'], '/'), http_build_query($query));

        $hash = sha1(uniqid(mt_rand(), true), true);

        if ($this->options['version'] !== 2) {
            $hash = substr($hash, 0, 16);
        }

        $key = base64_encode($hash);

        $origin = '*';
        $auth = null;
        $headers = isset($this->context['headers']) ? (array) $this->context['headers'] : [];

        foreach ($headers as $header) {
            $matches = [];

            if (preg_match('`^Origin:\s*(.+?)$`', $header, $matches)) {
                $origin = $matches[1];
                break;
            }
            if (strpos($header, 'Authorization: Bearer') !== false) {
                $auth = $header;
            }
        }

        $request = "GET {$url} HTTP/1.1\r\n"
            . "Host: {$this->url['host']}:{$this->url['port']}\r\n"
            . "Upgrade: WebSocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Origin: {$origin}\r\n";

        if ($auth) {
            $request .= $auth . "\r\n";
        }

        if (!empty($this->cookies)) {
            $request .= "Cookie: " . implode('; ', $this->cookies) . "\r\n";
        }

        $request .= "\r\n";

        fwrite($this->stream, $request);
        // cleaning up the stream
        while ('' !== trim(fgets($this->stream)));

        $this->write(EngineInterface::UPGRADE);

        // OPENING Connection
        $auth = isset($this->options['auth']) ? json_encode($this->options['auth']) : '';
        $this->write(EngineInterface::MESSAGE, static::OPEN . $this->namespace . $auth);

        $response = $this->read();

        if ($auth) {
            $this->checkAuth($response);
        }
    }

    private function checkAuth($response)
    {
        $code = substr($response, 0, 2);
        if ((int)$code == 44) {
            throw new Exception('Authentication failed');
        }
    }
}
