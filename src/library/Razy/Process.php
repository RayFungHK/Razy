<?php

namespace Razy;

class Process
{
    private $process = null;

    private array $pipes = [];

    public function __construct()
    {
    }

    public function start(string $path)
    {
        $descriptors = array(
            0 => array('pipe', 'r'),  // STDIN
            1 => array('pipe', 'w'),  // STDOUT
            2 => array('pipe', 'w')   // STDERR
        );

        $this->process = proc_open('php ' . $path, $descriptors, $this->pipes);
    }

    public function send(string $data): string
    {
        fwrite($this->pipes[0], "Your data here...");
        return stream_get_contents($this->pipes[1]);
    }
}
