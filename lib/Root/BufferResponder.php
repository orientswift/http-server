<?php

namespace Aerys\Root;

use Amp\Success;
use Amp\Failure;
use Aerys\Responder;
use Aerys\ResponderStruct;
use Aerys\ClientGoneException;

class BufferResponder implements Responder {
    private $fileEntry;
    private $headerLines;
    private $bodyBuffer;
    private $reactor;
    private $socket;
    private $writeWatcher;
    private $mustClose;
    private $isWriteWatcherEnabled;
    private $buffer;
    private $bufferSize;
    private $promisor;

    public function __construct(FileEntry $fileEntry, array $headerLines) {
        $this->fileEntry = $fileEntry;
        $this->headerLines = $headerLines;
        $this->bodyBuffer = $fileEntry->buffer;
    }

    /**
     * Prepare the Responder
     *
     * @param Aerys\ResponderStruct $responderStruct
     */
    public function prepare(ResponderStruct $responderStruct) {
        $this->reactor = $responderStruct->reactor;
        $this->socket = $responderStruct->socket;
        $this->writeWatcher = $responderStruct->writeWatcher;
        $this->mustClose = $mustClose = $responderStruct->mustClose;

        $headerLines = $this->headerLines;

        if ($mustClose) {
            $headerLines[] = 'Connection: close';
        } else {
            $headerLines[] = 'Connection: keep-alive';
            $headerLines[] = "Keep-Alive: {$responderStruct->keepAlive}";
        }

        $headerLines[] = "Date: {$responderStruct->httpDate}";

        if ($responderStruct->serverToken) {
            $headerLines[] = "Server: {$responderStruct->serverToken}";
        }

        $request = $responderStruct->request;
        $method = $request['REQUEST_METHOD'];
        $protocol = $request['SERVER_PROTOCOL'];
        $headers = implode("\r\n", $headerLines);
        $body = ($method === 'HEAD') ? '' : $this->bodyBuffer;
        $this->buffer = "HTTP/{$protocol} 200 OK\r\n{$headers}\r\n\r\n{$body}";
    }

    /**
     * Write the prepared response
     *
     * @return \Amp\Promise A Promise that resolves upon write completion
     */
    public function write() {
        $bytesWritten = @fwrite($this->socket, $this->buffer);

        if ($bytesWritten === strlen($this->buffer)) {
            goto write_complete;
        } elseif ($bytesWritten !== false) {
            goto write_incomplete;
        } else {
            goto write_error;
        }

        write_complete: {
            if ($this->isWriteWatcherEnabled) {
                $this->reactor->disable($this->writeWatcher);
                $this->isWriteWatcherEnabled = false;
            }

            return $this->promisor
                ? $this->promisor->succeed($this->mustClose)
                : new Success($this->mustClose);
        }

        write_incomplete: {
            $this->buffer = substr($this->buffer, $bytesWritten);

            if (!$this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = true;
                $this->reactor->enable($this->writeWatcher);
            }

            return $this->promisor ?: ($this->promisor = new Future($this->reactor));
        }

        write_error: {
            if ($this->isWriteWatcherEnabled) {
                $this->isWriteWatcherEnabled = false;
                $this->reactor->disable($this->writeWatcher);
            }

            $error = new ClientGoneException(
                'Write failed: destination stream went away'
            );

            return $this->promisor ? $this->promisor->fail($error) : new Failure($error);
        }
    }
}