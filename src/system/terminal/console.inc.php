<?php

namespace Razy;

return function (string $distCode = '') {
    $this->writeLineLogging('{@s:ub}Console Menu', true);

    $distCode = trim($distCode);
    if (!$distCode) {
        $this->writeLineLogging('{@c:red}[Error] Missing parameter: distributor code.', true);

        return;
    }

    $message = 'Binding to distributor `' . $distCode . '`... ';

    $distributors = Application::GetDistributors();

    if (isset($distributors[$distCode])) {
        $this->writeLineLogging($message . '{@c:green}Success', true);

        $command = null;
        do {
            if (!$command) {
                $this->writeLineLogging('input `help` for command list.', true);
            } else {
                $argv       = [$distCode];
                $executable = true;

                switch ($command) {
                    case 'help':
                    case 'list':
                        break;

                    default:
                        $this->writeLineLogging('{@c:red}[Error] Unknown command `' . $command . '`.', true);
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
        $this->writeLineLogging($message . '{@c:green}Failed', true);
        $this->writeLineLogging('{@c:red}[Error] distributor `' . $distCode . ' is not registered.', true);
    }
};
