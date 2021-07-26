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

        if ($this->options['version'] === 2) {
            $query['use_b64'] = $this->options['use_b64'];
        }

        $url = sprintf('/%s/?%s', trim($this->url['path'], '/'), http_build_query($query));

        $hash = sha1(uniqid(mt_rand(), true), true);

        if ($this->options['version'] !== 2) {
            $hash = substr($hash, 0, 16);
        }

        $key = base64_encode($hash);

        $origin = '*';
        $headers = isset($this->context['headers']) ? (array) $this->context['headers'] : [];

        foreach ($headers as $header) {
            $matches = [];

            if (preg_match('`^Origin:\s*(.+?)$`', $header, $matches)) {
                $origin = $matches[1];
                break;
            }
        }

        $request = "GET {$url} HTTP/1.1\r\n"
            . "Host: {$this->url['host']}:{$this->url['port']}\r\n"
            . "Upgrade: WebSocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Origin: {$origin}\r\n";

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

    /** {@inheritDoc} */
    public function emit($event, array $args)
    {
        $namespace = $this->namespace;

        if ('' !== $namespace) {
            $namespace .= ',';
        }
        return $this->write(EngineInterface::MESSAGE, static::EVENT . $namespace, ['event' => $event, 'args' => $args]);
    }


    /** {@inheritDoc} */
    public function write($code, $message = null, $args = [])
    {
        if (!is_resource($this->stream)) {
            return;
        }

        if (!is_int($code) || 0 > $code || 6 < $code) {
            throw new InvalidArgumentException('Wrong message type when trying to write on the socket');
        }

        if ((int)substr($message, 0, 1) == static::EVENT && isset($args['args']['file']) && isset($args['args']['content'])) {
            $bytes = $this->fwrite_stream($code . $message, $args);
        } else {
            $json_data = isset($args['event']) && isset($args['args']) ? json_encode([$args['event'], $args['args']]) : null;
            $payload = new Encoder($code . $message . $json_data, Encoder::OPCODE_TEXT, true);
            $bytes = fwrite($this->stream, (string) $payload);
        }
        // wait a little bit of time after this message was sent
        usleep((int) $this->options['wait']);

        return $bytes;
    }

    /**
     * Writing large file content to stream
     * @param string $codeWithMessage
     * @param array $args
     * @return int
     */
    public function fwrite_stream($codeWithMessage, $args)
    {

        $chunkSize = 500000;
        $isLastChunk = false;

        $strings = str_split($args['args']['content'], $chunkSize);
        $count = 0;

        $args['args']['content'] = $strings[$count];

        if (!isset($strings[$count + 1])) {
            $args['args']['isLastChunk'] = true;
        }

        if (isset($args['args']['isLastFile'])) {
            $isLastFile = true;
            unset($args['args']['isLastFile']);
        }

        $payload = new Encoder($codeWithMessage . json_encode(
            [
                $args['event'],
                $args['args']

            ]
        ), Encoder::OPCODE_TEXT, true);

        fwrite($this->stream, (string)$payload);

        $count++;
        while (isset($strings[$count])) {
            if (!isset($strings[$count + 1])) {
                $isLastChunk = true;
            }

            $fileInfos = [
                'content' => $strings[$count],
                'file' => $args['args']['file']
            ];

            if ($isLastChunk) {
                $fileInfos['isLastChunk'] = $isLastChunk;
                if (isset($isLastFile)) {
                    $fileInfos['isLastFile'] = $isLastFile;
                }
            }

            $payload = new Encoder($codeWithMessage . json_encode(
                [
                    $args['event'],
                    $fileInfos
                ]
            ), Encoder::OPCODE_TEXT, true);

            fwrite($this->stream, (string)$payload);

            $count++;
        }

        return $count * $chunkSize;
    }
}
