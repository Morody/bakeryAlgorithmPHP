<?php

namespace Project;

use Symfony\Component\Process\Process;

class MultiProcessing
{
    /**
     * @var array
     */
    private array $pullProcess;



    public function __construct()
    {

        for ($i = 0; $i < 5; $i++)
        {
            $this->initProcess(__DIR__ . '/bakery.php');
        }
    

        while(true)
        {
            sleep(5);

            $this->checkFinish();
        }

    }

    private function initProcess($script): Process
    {
        $process = new Process(['php', $script]);
        $process->start();

        $this->pullProcess[$process->getPid() . ':' . $script] = $process;
        return $process;
    }

    private function checkFinish(): void
    {
        if(empty($this->pullProcess))
        {
            return;
        }

        foreach($this->pullProcess as $nameProcess => $process){
            if ($process instanceof Process && $process -> isTerminated())
            {
                echo '#FINISHED: ' . $nameProcess . ' ' . $process ->getOutput() . PHP_EOL;
                unset($this->pullProcess[$nameProcess]);
            }
        }
    }

}

?>