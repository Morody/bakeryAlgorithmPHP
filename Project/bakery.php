<?php

namespace Project;

require_once 'C:\Users\User\Desktop\Multi\vendor\autoload.php';
class Bakery{

    public int $number_id_shmop;
    public int $choosing_id_shmop;
    public int $state_id_shmop;
    public int $num_proc;

    public function __construct($number_id_shmop, $choosing_id_shmop, $state_id_shmop, $num_proc)
    {
        $this->number_id_shmop = $number_id_shmop;
        $this->choosing_id_shmop = $choosing_id_shmop;
        $this->state_id_shmop = $state_id_shmop;
        $this->num_proc = $num_proc;
    }

    /*
    * обновление данных в памяти
    */
    public function updateValFromMem(int $process_id, string $val, int $shm_id): void
    {
        $outFromMem = $this->readFromMem($shm_id);
        $len_str = strlen(implode(",", $outFromMem));
        $outFromMem[$process_id] = $val;
        shmop_write(shmop_open($shm_id, 'w', 0644, 50), str_pad(implode(",", $outFromMem), 50 - $len_str), 0);
    }

    # считывание данных из память
    public function readFromMem(int $shm_id)
    {
        return explode(",", rtrim(shmop_read(shmop_open($shm_id, 'w', 0644, 50), 0, 50), "\0 "));
    }

    /*
    * функция для сравнения кортежа как в Python ((a1, b1) < (a2, b2) | a1 < a2 or a1 == a2 and b1 < b2) 
    */
    public function compare($num_one, $one, $num_two, $two): bool
    {
        if ($num_one < $num_two){
            return true;
        } else if ($num_one > $num_two){
            return false;
        } else {
            if ($one < $two){
                return true;
            } else {
                return false;
            }
        }
    }
    /*
    *
    * здесь определяется взаимоисключение процессов и определяется какой процесс отправится в критическую секцию
    */
    public function lock(int $process_id)
    {
        // начало doorway
        $this->updateValFromMem($process_id, 'R', $this->state_id_shmop);
        $this->updateValFromMem($process_id, 'true', $this->choosing_id_shmop);
        sleep(rand(1, 10)); // симуляция атомарности операции записи
        $this->updateValFromMem($process_id, intval(max($this->readFromMem($this->number_id_shmop))) + 1, $this->number_id_shmop);
        sleep(rand(1, 10)); // симуляция атомарности операции чтения
        $this->updateValFromMem($process_id, 'false', $this->choosing_id_shmop);
        // конец doorway

        /* пока все процессы не будут иметь значения в массиве 'choosing' - false
        * т.е. все процессы ждут пока каждый из них не выйдут из doorway
        * процессы, которые имеют значения в массиве 'number' - 0 и не имеют наименьшего значения, ожидают своей очереди
        * 
        */
        for ($i = 0; $i < $this->num_proc; $i++){
            while(filter_var($this->readFromMem($this->choosing_id_shmop)[$i], FILTER_VALIDATE_BOOLEAN)){}
            while(intval($this->readFromMem($this->number_id_shmop)[$i]) !== 0
                    && $this->compare(intval($this->readFromMem($this->number_id_shmop)[$i]), $i, intval($this->readFromMem($this->number_id_shmop)[$process_id]), $process_id)){}
        }

        $this->updateValFromMem($process_id, 'C', $this->state_id_shmop); // процесс находится в критической секции

    }

    // процесс выходит из критической секции, состояние 'choosing' и значение 'number' обнуляются
    public function unlock(int $process_id)
    {
        $this->updateValFromMem($process_id, '_', $this->state_id_shmop);
        $this->updateValFromMem($process_id, 0, $this->number_id_shmop);
    }
    

}

// симуляция действий процесса в критической секции
function criticalSection(int $shm_counter, int $process_id)
{
    $id_read_counter = shmop_open($shm_counter, 'w', 0644, 5);
    $shared_counter = intval(rtrim(shmop_read($id_read_counter, 0, 5), "\0 ")); // читается счетчик из распр. памяти
    $len_str = strlen(rtrim(shmop_read($id_read_counter, 0, 5), "\0 ")); // вычисляется его длина строки перед изменением
    $old_value = $shared_counter;
    $new_value = $old_value + 1; // увеличиваем счетчик 
    $shared_counter = $new_value;
    shmop_write($id_read_counter, str_pad(strval($shared_counter), 5 - $len_str), 0); // записываем обновленный счетчик в распр. память
    
    echo "Process {$process_id} is working..." . PHP_EOL;       // симулируем работу процесса
    sleep(rand(0, 10));                                         //     
    echo "Process {$process_id} finished working!" . PHP_EOL;   //         
    assert($shared_counter == $new_value, "Mutual exclusion violated"); // проверка условия взаимоисключения процессов из крит. секции 
}

# точка распределения задачи на процессы
function processFunction(Bakery $lock, int $shm_counter, int $process_id)
{
    $iter_per_proc = $_SERVER['iter_per_proc'];

    for ($i = 0; $i < $iter_per_proc; $i++){ # 5 итераций на каждый процесс

        $lock->lock($process_id); # определяет какой процесс попадет в критическую секцию, блокировка остальных процессов

        criticalSection($shm_counter, $process_id); # после выхода из блокировки процесс получает возможность попасть в крит. секцию

        $lock->unlock($process_id); # после выхода из крит. секции значения 'choosing' и 'number' обнуляется

        sleep(1); # пауза между итерациями
    }
}

/*
*
* Создаем объект lock с id в shmop с общими блоками хранения в распред. памяти
* инициализируем алгоритм функцией processFunction с счетчиком количества входа процессов в критическую секцию и количеством процессов
* выводим состояние системы после заврешения каждого из процессов
*/
$lock = new Bakery($_SERVER['id_shmop_number'], $_SERVER['id_shmop_choosing'], $_SERVER['id_shmop_state'], $_SERVER['num_proc']); 
processFunction($lock, $_SERVER['id_shmop_counter'], $_SERVER['id_proc']);
?>