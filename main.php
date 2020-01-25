<?php

require_once('Flip.php');

use Flips\Flip;

require __DIR__ . '/vendor/autoload.php';

$filter_param = [
    'Номер регистрации' => '>1000',             
];
//91.243.188.184:7951:rp3090830:oGF1p7i9If
//proxy example http://username:password@192.168.16.1:10
//http://rp3090830:oGF1p7i9If@91.243.188.184:7951
$config = [
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.108 Safari/537.36',
    'proxy' => '',
    //'proxy' => '127.0.0.1:8888', //for debug
    'max_pagination' => 5,//нужна больше для отладки, чтобы не останавливать вручную             
];

$list_value = [];

$parcer = new Flip($config, '');

//авторизация
$isAuth = $parcer->checkAuth();
if (!$isAuth)
{
    $parcer->userAuth('login', 'password');
}
else 
{
    $parcer->startParcer($filter_param);
    //получаем массив значений
    $table = $parcer->getData();
    
    //Просто разворачиваем список "Пагинаций"
    
    foreach ($table as $value)
    {
        foreach ($value as $item)
        {
            $list_value[] = $item;
        }
    }
}


echo "<pre>";
print_r( $list_value);
echo "</pre>";





echo 'break';


?>