<?php

namespace Razy;

return function (string $distCode = '') {
    $this->writeLine('{@s:ub}Console Menu', true);

    $distCode = trim($distCode);
    if (!$distCode) {
        $this->writeLine('{@c:red}[Error] Missing parameter: distributor code.', true);

        return;
    }

    $message = 'Binding to distributor `' . $distCode . '`... ';

    $distributors = Application::GetDistributors();

    if (isset($distributors[$distCode])) {
        $this->writeLine($message . '{@c:green}Success', true);

        $command = null;
        do {
            if (!$command) {
                $this->writeLine('input `help` for command list.', true);
            } else {
                $argv       = [$distCode];
                $executable = true;

                switch ($command) {
                    case 'help':
                    case 'list':
                        break;

                    default:
                        $this->writeLine('{@c:red}[Error] Unknown command `' . $command . '`.', true);
                        $executable = false;
                }

                if ($executable) {
                    executeTerminal('console/' . $command, $argv);
                    echo PHP_EOL;
                }
            }
            echo $this->format('{@c:green}' . $distCode . '{@reset} > ');
        } while (($command = trim($this->read())) !== 'exit');
    } else {
        $this->writeLine($message . '{@c:green}Failed', true);
        $this->writeLine('{@c:red}[Error] distributor `' . $distCode . ' is not registered.', true);
    }
};
