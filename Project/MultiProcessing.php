<?php
require_once 'C:\Users\User\Desktop\Multi\vendor\autoload.php';


use Symfony\Component\Process\Process;
use Project\Bakery;

class MultiProcessing
{
    private array $pullProcess;

    public int $num_proc = 3; # кол-во процессов
    public int $iter_per_proc = 5; # итераций в процессе

    /*
        определяем индентификаторы для shmop, чтобы иметь к ним доступ для чтения и записи в распред. память
    */
    public int $id_shmop_number = 10; 
    public int $id_shmop_choosing = 11;
    public int $id_shmop_state = 12;
    public int $id_shmop_counter = 13;
    


    public function __construct()
    {
        shmop_open($this->id_shmop_counter, 'c', 0644, 5);    // инициализируем блок в распределенной памяти для каждого хранилища
        shmop_open($this->id_shmop_number, "c", 0644, 50);    // для счетчика - 5 байт
        shmop_open($this->id_shmop_choosing, "c", 0644, 50);  // для массивов - 50 байт
        shmop_open($this->id_shmop_state, "c", 0644, 50);     //

        /*заполняем массивы начальными значениями
        * размер = кол-во процессов
        */

        $number = array_fill(0, $this->num_proc, 0);
        $choosing = array_fill(0, $this->num_proc, 'false'); 
        $state = array_fill(0, $this->num_proc, '_');
        

        /* записываем начальное значение для счетчика количества входа процессов в критическую секцию
        */
        shmop_write(shmop_open($this->id_shmop_counter, 'w', 0644, 5), 0, 0);    
        
        //записываем ранее созданные массивы в виде строки в распред. память
        shmop_write(shmop_open($this->id_shmop_number, "w", 0644, 50), implode(',', $number), 0);
        shmop_write(shmop_open($this->id_shmop_choosing, "w", 0644, 50), implode(',', $choosing), 0);   
        shmop_write(shmop_open($this->id_shmop_state, "w", 0644, 50), implode(',', $state), 0);


        $scripts = [
            __DIR__ . "/bakery.php",
            __DIR__ . "/bakery.php",
            __DIR__ . "/bakery.php"]; # три процесса
        
        $n = 0;
        foreach($scripts as $script){
            $this->initProcess($script, $n); // инициализируем три процесса
            $n++;
        }

        while(true)
        {
            sleep(5);

            $this->checkFinish(); // проверяем завершенность процессов

            echo $this->generateASCII() . PHP_EOL;
        }

    }

    private function initProcess($script, $id_proc): Process
    {
        /* инициализируем процесс
        * передаем в процесс исполняемый скрипт и переменные окружения через $_SERVER
        *
        */
        $process = new Process(['php', $script], null, ['id_proc' => $id_proc, 'id_shmop_number' => $this->id_shmop_number,
                                                        'id_shmop_choosing' => $this->id_shmop_choosing, 'id_shmop_state' => $this->id_shmop_state,
                                                        'id_shmop_counter' => $this->id_shmop_counter, 'iter_per_proc' => $this->iter_per_proc, 'num_proc' => $this->num_proc]);
        $process->start(); # запускаем процессы асинхронно

        $this->pullProcess[$process->getPid()] = $process; // помещаем процессы в пул процессов
        return $process;
    }

    public function readFromMem(int $shm_id)
    {
        return explode(",", rtrim(shmop_read(shmop_open($shm_id, 'w', 0644, 50), 0, 50), "\0 "));
    }

    // вывод состояния всех процессов в системе на данный момент
    function generateASCII(): string
    {

        $state_symbols = ['R' => 'Requesting', 'C' => 'In Critical Section', '_' => 'Waiting'];
        $ascii_art = PHP_EOL . 'System State: ' . PHP_EOL;

        for ($i = 0; $i < $this->num_proc; $i++){
            $ascii_art .= "Process {$i}: {$state_symbols[$this->readFromMem($this->id_shmop_state)[$i]]} " . " | Number: " . $this->readFromMem($this->id_shmop_number)[$i] . PHP_EOL;
        }

        return $ascii_art;
    }

    private function checkFinish(): void
    {
        if(empty($this->pullProcess)) // Проверяем завершенность всех процессов
        {
            /*
            * закрываем блоки в распред. памяти
            * в Win 10 с этим проблемы
            */
            if(shmop_delete(shmop_open($this->id_shmop_number, 'w', 0644, 50))){
                echo "Shmop number: delete" . PHP_EOL;
            }
            if(shmop_delete(shmop_open($this->id_shmop_choosing, 'w', 0644, 50))){
                echo "Shmop choosing: delete". PHP_EOL;
            }
            if(shmop_delete(shmop_open($this->id_shmop_state, 'w', 0644, 50))){
                echo "Shmop state: delete" . PHP_EOL;
            }
            if(shmop_delete(shmop_open($this->id_shmop_counter, 'w', 0644, 5))){
                echo "Shmop counter: delete" . PHP_EOL;
            }

            $id_read_counter = shmop_open($this->id_shmop_counter, 'w', 0644, 5);

            echo "Final counter value: " . intval(rtrim(shmop_read($id_read_counter, 0, 5), "\0 ")) . PHP_EOL;

            // проверка конечного значения 'счетчика процесса' * 'итераций в процессе'
            assert(intval(rtrim(shmop_read($id_read_counter, 0, 5), "\0 ")) == $this->num_proc * $this->iter_per_proc, "Final counter value does not match expected");
            return;
        }
        foreach($this->pullProcess as $nameProcess => $process){
            if ($process instanceof Process && $process -> isTerminated())
            {
                echo '#FINISHED: ' . $nameProcess . PHP_EOL; // вывод содержимого каждого из процессов
                unset($this->pullProcess[$nameProcess]);
            }
        }

    }

}

new MultiProcessing(); // запуск

?>