<?php

$size = 128;
$string = 'something';

$shm = shmop_open(10, "c", 0644, 100);
shmop_write($shm, 'some', 0);

$shm_data = shmop_read($shm, 0, 10);

echo $shm_data;
//class Bakery{
//}
?>