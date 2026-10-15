<?php

declare(strict_types=1);

namespace ApiKit\Http;

class Response
{
    /** @var string */
    protected $content;

    /** @var int */
    protected $status;

    /** @var array<string, string> */
    protected $headers;

    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->content = $content;
        $this->status = $status;
        $this->headers = $headers;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        if ($this->status !== 204 && $this->status !== 304) {
            echo $this->content;
        }
    }
}
