# Bakery алгоритм на PHP

Алгоритм коррекотной организации взаимодействия более двух процессов


## Псевдокод

```
while (some condition) { 
   choosing[i] = true;                    // doorway  
   number[i] = max(number[0], ...,        // doorway  
                   number[n-1]) + 1;      // doorway      
   choosing[i] = false;                   // doorway  
   for(j = 0; j < n; j++){ 
      while(choosing[j]); 
      while(number[j] != 0 && (number[j],j) < (number[i],i)); 
   } 
      critical section 
   number[i] = 0; 
      remainder section 
}
```
У процесса есть несколько участков выполнения, где нужно корректно организовывать взаимодействия между другими процессами. Используются:

* массив $number хранит значения каждого процесса. Самое маленькое значение процесса обслуживается самым первым.
* массив $choosing хранит bool-значения. False - процесс не находится в области __doorway__, true - процесс находится в области __doorway__
* массив state отображает состояние процесса на данный момент

## Doorway 

```php
$this->updateValFromMem($process_id, 'R', $this->state_id_shmop);
$this->updateValFromMem($process_id, 'true', $this->choosing_id_shmop);

sleep(rand(1, 10)); // симуляция атомарности операции записи

$this->updateValFromMem($process_id,
                        intval(max($this->readFromMem($this->number_id_shmop))) + 1,
                        $this->number_id_shmop);

sleep(rand(1, 10)); // симуляция атомарности операции чтения

$this->updateValFromMem($process_id, 'false', $this->choosing_id_shmop);
```

Эта область гарантирует, что во время записи нового значения процесса в массив `$number`, ни один другой процесс не будет пытаться считывать данные из данного массива. 

## Lock процессов

```php
for ($i = 0; $i < 3; $i++){

    while(filter_var($this->readFromMem($this->choosing_id_shmop)[$i], FILTER_VALIDATE_BOOLEAN)){}

    while(intval($this->readFromMem($this->number_id_shmop)[$i]) !== 0 &&
    $this->compare(
                    intval($this->readFromMem($this->number_id_shmop)[$i]), $i,
                    intval($this->readFromMem($this->number_id_shmop)[$process_id]), $process_id)){}
}

$this->updateValFromMem($process_id, 'C', $this->state_id_shmop);

```

Первое условие проверяет все процессы на нахождении в области doorway

Второе условие проверяет, если среди процессов есть процесс с значением `$number[$i] != 0` и `$number[$i] < $number[$process_id]`,`$process_id` - процесс в котором происходит проверка, `$i` - остальные процессы, включая текущий, то данный процесс (`$process_id`) попадает в бесконечный цикл, пока данный процесс не станет самым наименьшим по значению `$number` или пока все остальные процессы не отработают и не обнулятся.

После того как `while(false)`, текущий процесс попадает в критическую секцию и получает состояние 'C'.

## Shmop

Используется встроенная распределенная память:

* shmop_open(10, 'c', 0644, 50) - создает блок в распределенной памяти с размером 50 байт. Возвращает объект Shmop
* shmop_write(shmop_open(10, 'w', 0644, 50), "..", 0) - записывает строку в распр. память начиная с 0 отступа.
* shmop_read(shmop_open(10, 'a', 0644, 50), 0, 50) - считывает из блока памяти 50 байт (50 символов строки)

Чтобы записывать в уже существующий блок памяти, заполненный какой-либо строкой, нужно:
* `shmop_read()`, если извлекаете весь блок памяти (50 байт), а содержащая там строка занимает 10 байт, то нужно убрать NUL-значения и пробелы - `rtrim($str, "\0 ")`. Значения, хранищиеся в строке, записываются через запятую
* записать размер извлекенной строки
* перевести строку в массив `explode(",", $arr)` и присвоить значение определенному процессу
* перевести обратно в строку, значения которой записаны через запятую
* дополнить до размера старой строки строку, которую хотим вместо нее поместить с помощью `str_pad(string $str, $block_size - strlen($old_str))`

## Screens
Проверка состояний процессов каждые 5 секунд. Всего запущено 3 процесса и 5 итераций в каждом процессе для обновления состояний
![](https://github.com/Morody/bakeryAlgorithmPHP/blob/main/img/1.png)

![](https://github.com/Morody/bakeryAlgorithmPHP/blob/main/img/2.png)

![](https://github.com/Morody/bakeryAlgorithmPHP/blob/main/img/3.png)


![](https://github.com/Morody/bakeryAlgorithmPHP/blob/main/img/4.png)
