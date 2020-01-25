<?php

namespace Flips;

use Sunra\PhpSimple\HtmlDomParser;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Cookie\CookieJar;


define('MAX_FILE_SIZE', 1200000);


class Flip
{
    private $_config;

    private $_cookieFile;

    private $_cookieJar;

    private $_client;

    /**
     * Сюда пишем сграбленные значения 
     */
    private $_listValue = [];

    public function __construct(array $config = [], $cookieFile = '') 
    {
        $this->_config = $config;

        if (empty($cookieFile))
        {
            $this->_cookieFile = __DIR__ . '/cookie';
        }
        else
        {
            $this->_cookieFile = $cookieFile;
        }

        $this->configureCookies();
        $this->configureDefault($this->_config);
    }

    public function startParcer(array $filter_param = [])
    {
        $this->getSvg();
        $viewstate_simple_page = $this->getViewState();   
        
        //Череда ajax запросов чтобы добраться до формы поиска
        $ajax1 = $this->postSelectBDajax1($viewstate_simple_page);
        $ajax2 = $this->postSelectBDajax2($ajax1);
        $this->postSelectBDajax3($ajax2);

        // Отправляем параметры запроса на сервер
        $result_search = $this->searchPostValue($ajax2, $filter_param);
    }
    /**
     * return normalisier array
     */
    public function getData() : array
    {
        return $this->_listValue;
    }

    /**
     * Авторизация пользователя
     */
    public function userAuth($login, $password)
    {
        $view_state = $this->getViewState();

        $this->postAuth($view_state, $login, $password);


    }
    
    private function configureCookies()
    {

        $this->_cookieJar = new FileCookieJar($this->_cookieFile, true);
    }

    /**
     * Доделать формирование кофнига позже. Пока как есть.
     * 
     */
    private function configureDefault(array $config = [])
    {   
        $options = [
            'headers' => [
                'User-Agent' => !empty($config['User-Agent']) ? $config['User-Agent'] : 'Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36',
            ],
            'cookies' => $this->_cookieJar,
            'proxy' => !empty($config['proxy']) ? $config['proxy'] : '',
            'allow_redirects' => true,
            'curl' => [ CURLOPT_SSL_VERIFYPEER => false ],
        ];

        $this->_client = new Client( $options );
    }

    /**
     * Метод просто ищет слово "Выйти" для проверки авторизаций.
     */
    public function checkAuth() : bool
    {               
        $response = $this->_client->request('GET', 'https://www1.fips.ru/iiss/db.xhtml');
        $body = $response->getBody()->getContents();
        $dom = HtmlDomParser::str_get_html($body);
        $isAuthDom = $dom->find("//*[@id='sidebarForm']/ul[2]/li/b/a")[0]->href;
        if ($isAuthDom == 'login.xhtml')
        {
            $isAuth = false;
        }
        else
        {
            $isAuth = true;
        }    

        return $isAuth;
    }

    /**
     * Медод нужен для получения ViewState формы для последующей отправки post запроса.
     * 
     */
    private function getViewState() : string
    {
        $view_state = '';
        $response = $this->_client->request('GET', 'https://www1.fips.ru/iiss/db.xhtml');
        $body = $response->getBody()->getContents();
        $dom = HtmlDomParser::str_get_html($body);
        $view_state = $dom->find('//*[@id="j_id1:javax.faces.ViewState:2"]')[0]->value;

        return $view_state;
    }

    private function postAuth($view_state, $login, $password)
    {
        $post = [
            'javax.faces.partial.ajax' => 'true',
            'javax.faces.source' => 'loginForm:j_idt89',
            'javax.faces.partial.execute' => 'loginForm:j_idt89',
            'javax.faces.partial.render' => 'loginErrorHidden',
            'javax.faces.behavior.event' =>	'action',
            'javax.faces.partial.event' => 'click',
            'loginForm' => 'loginForm',
            'loginForm:username' => $login,
            'loginForm:password' => $password,           
            'javax.faces.ViewState' => $view_state,//ViewState нужен при каждом запросе. Он всегда разный                      
        ];
        $response = $this->_client->request('POST', 'https://www1.fips.ru/iiss/login.xhtml', [
            'form_params' => $post,
            'headers'=> [
                'Origin' => 'https://www1.fips.ru',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Site'  => 'cross-site',
                'Sec-Fetch-Mode'  => 'navigate', 
                'Accept-Encoding' => 'gzip, deflate, br', 
                'Upgrade-Insecure-Requests' => '1', 
                'Cache-Control' => 'max-age=0',
                'Faces-Request' => 'partial/ajax',
                'Referer' => 'https://www1.fips.ru/iiss/login.xhtml',             
            ],    
        ]);

        echo 'break';
    }
    
    /**
     * Проверка присвоения кук
     */
    private function getSvg()
    {
        $response = $this->_client->request('GET', 'https://www1.fips.ru/resources/images/up-01.svg');
    }

    /**
    * Метод отправляет запрос с выбранными результатами фильтра БД (чекбоксы).  (Пока запрос выбирает все)
    */
    private function selectDataBase($view_state = '') : string
    {
        $view_state_new = '';

        $post = [
            'javax.faces.ViewState' => $view_state,//ViewState нужен при каждом запросе. Он всегда разный
            'db-selection-form:j_idt90' => 'перейти к поиску',
            'db-selection-form:dbsGrid8:0:dbsGrid8checkbox' => 'on',
            'db-selection-form:dbsGrid7:0:dbsGrid7checkbox' => 'on',
            'db-selection-form:dbsGrid6:0:dbsGrid6checkbox' => 'on',
            'db-selection-form:dbsGrid4:0:dbsGrid4checkbox' => 'on',
            'db-selection-form:dbsGrid3:0:dbsGrid3checkbox' => 'on',
            'db-selection-form:dbsGrid2:0:dbsGrid2checkbox' => 'on',
            'db-selection-form:dbsGrid1:5:dbsGrid1checkbox' => 'on',
            'db-selection-form:dbsGrid1:4:dbsGrid1checkbox' => 'on',
            'db-selection-form:dbsGrid1:3:dbsGrid1checkbox' => 'on',
            'db-selection-form:dbsGrid1:2:dbsGrid1checkbox' => 'on',
            'db-selection-form:dbsGrid1:1:dbsGrid1checkbox' => 'on',
            'db-selection-form:dbsGrid1:0:dbsGrid1checkbox' => 'on',
            'db-selection-form' => 'db-selection-form',                       
        ];

        $response = $this->_client->request('POST', 'https://www1.fips.ru/iiss/db.xhtml', [
            'form_params' => $post,
            'headers'=> [
                'Origin' => 'https://www1.fips.ru',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Site'  => 'cross-site',
                'Sec-Fetch-Mode'  => 'navigate', 
                'Accept-Encoding' => 'gzip, deflate, br', 
                'Upgrade-Insecure-Requests' => '1', 
                'Cache-Control' => 'max-age=0',              
            ],    
        ]);

        $body = $response->getBody()->getContents();

        $dom = HtmlDomParser::str_get_html($body);
        $view_state_new = $dom->find('//*[@id="j_id1:javax.faces.ViewState:2"]')[0]->value;

        return $view_state_new;
    }

     /**
     * Клик по выпадающему списку БД (ajax)
     */   
    private function postSelectBDajax1($view_state = '') : string
    {
        $view_state_new = '';
        $post = [
            'javax.faces.partial.ajax' => 'true',
            'javax.faces.source' => 'db-selection-form:j_idt134',
            'javax.faces.partial.execute' => 'db-selection-form:j_idt134',
            'javax.faces.partial.render' => 'db-selection-form:button-set4',
            'javax.faces.behavior.event' => 'click',
            'javax.faces.partial.event' => 'click',
            'db-selection-form' => 'db-selection-form',
            'javax.faces.ViewState' => '',
            'javax.faces.ViewState' => $view_state, 
        ];

        $response = $this->_client->request('POST', 'https://www1.fips.ru/iiss/db.xhtml', ['form_params' => $post]);
        $body = $response->getBody()->getContents();
        $xml = simplexml_load_string($body);
        
        $view_state_new = (string)$xml->changes->update[1];
        return $view_state_new;
    }
    /**
     * Клик по чек боксу базы данных (ajax)
     */
    private function postSelectBDajax2($view_state = '') : string 
    {
        $post = [
            'javax.faces.ViewState' => $view_state,//ViewState нужен при каждом запросе. Он всегда разный
            'javax.faces.partial.ajax' => true,
            'javax.faces.source' =>	'db-selection-form:dbsGrid4:0:dbsGrid4checkbox',
            'javax.faces.partial.execute' => 'db-selection-form:dbsGrid4:0:dbsGrid4checkbox',
            'javax.faces.partial.render'=>'db-selection-form:button-set4',
            'javax.faces.behavior.event' => 'change',
            'javax.faces.partial.event' => 'change',
            'db-selection-form'=> 'db-selection-form',
            'db-selection-form:dbsGrid4:0:dbsGrid4checkbox' => 'on',                       
        ];

        $response = $this->_client->request('POST', 'https://www1.fips.ru/iiss/db.xhtml', ['form_params' => $post]);
        $body = $response->getBody()->getContents();

        $xml = simplexml_load_string($body);
        $view_state_new = (string)$xml->changes->update[1];
        return $view_state_new;
    }

    /**
     * Нажатие на кнопку перейти к поиску (ajax) Редирект на 302 Ничего не возвращает. Перекидывает на форму поиска.
     */
    private function postSelectBDajax3($view_state = '')  
    {
        $post = [
            'db-selection-form'	=> 'db-selection-form',
            'db-selection-form:dbsGrid4:0:dbsGrid4checkbox' =>'on',
            'db-selection-form:j_idt150' => 'перейти к поиску',
            'javax.faces.ViewState' => $view_state,                   
        ];

        $response = $this->_client->request('POST', 'https://www1.fips.ru/iiss/db.xhtml', ['form_params' => $post]);
    }


    /**
     * send Post requests in form
     */
    private function searchPostValue($view_state = '', array $filter_param = []) : string
    {
        $view_state_new = '';
  
        $post = [
            'searchForm' => 'searchForm',
            'j_idt78' => 'drefuzzy',
            'j_idt81' => '2',//Уровень соответствия
            'javax.faces.ViewState' => $view_state,
            'j_idt92' => 'Поиск', 
            'j_idt89' => '',	
            'fields:9:j_idt109' => '',	
            'fields:8:j_idt109' => '',	
            'fields:7:j_idt109' => '',	
            'fields:6:j_idt109' => '',	
            'fields:5:j_idt109' => '',	
            'fields:4:j_idt109' => '',	
            'fields:3:j_idt109' => '',	
            'fields:22:j_idt109' => '',	
            'fields:20:j_idt109' => '',	
            'fields:2:j_idt109' => '',	
            'fields:16:j_idt109' => '',	
            'fields:15:j_idt109' => '',	
            'fields:14:j_idt109' => '',	
            'fields:13:j_idt109' => '',	
            'fields:12:j_idt109' => '',	
            'fields:11:j_idt109' => '',	
            'fields:10:j_idt109' => '',	
            'fields:1:j_idt109' => !empty($filter_param['Номер регистрации']) ? $filter_param['Номер регистрации'] : '', //Номер регистрации   
            'fields:0:j_idt109' => '',	                
        ];

        $response = $this->_client->request('POST', 'https://www1.fips.ru/iiss/search.xhtml', 
        [
            'form_params' => $post,
            'allow_redirects' => false,
            'headers'=> [
                'Origin' => 'https://www1.fips.ru',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Site'  => 'cross-site',
                'Sec-Fetch-Mode'  => 'navigate', 
                'Accept-Encoding' => 'gzip, deflate, br', 
                'Upgrade-Insecure-Requests' => '1', 
                'Cache-Control' => 'max-age=0',              
            ],    
        ]);

        $redirect1 = $this->_client->request('GET', 'http://www1.fips.ru/iiss/search_res.xhtml?faces-redirect=true', [
            'allow_redirects' => false,
            'headers'=> [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Accept-Encoding' => 'gzip, deflate', 
                'Upgrade-Insecure-Requests' => '1', 
                'Cache-Control' => 'max-age=0',              
            ]
        ]);

        $redirect2 = $this->_client->request('GET', 'https://www1.fips.ru/iiss/search_res.xhtml?faces-redirect=true', [
            'allow_redirects' => true,
            'headers'=> [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Site'  => 'cross-site',
                'Sec-Fetch-Mode'  => 'navigate', 
                'Accept-Encoding' => 'gzip, deflate, br', 
                'Upgrade-Insecure-Requests' => '1', 
                'Cache-Control' => 'max-age=0',
                'Host' => 'www1.fips.ru',
                'Connection' => 'keep-alive',              
            ]
        ]);

        $body = $redirect2->getBody()->getContents();

        $dom = HtmlDomParser::str_get_html($body);
        $view_state_new = $dom->find('//*[@id="j_id1:javax.faces.ViewState:2"]')[0]->value;

        //Вызываем метод чтобы получить все значения с первой страницы.

        $count = 0;
        do {
            $result = $this->savePageValue($body, $count);
            $body = $this->nextPage($result['view_state']);

            $max_pagination = isset($this->_config['max_pagination']) ? $this->_config['max_pagination'] : null;

            if (isset($max_pagination))
            {
                if ( $max_pagination == $count )
                {
                    $result['isNextPage'] = false;
                }

                $count++;
            }      
        } 
        while ($result['isNextPage']);

        return $view_state_new;
    }

    private function nextPage($view_state = '')
    {
        $view_state_new = '';
        $post = [
            'javax.faces.partial.ajax' => 'true',
            'javax.faces.source' => 'j_idt98:j_idt109',
            'javax.faces.partial.execute' => '@all', 
            'javax.faces.partial.render' => 'j_idt98',
            'j_idt98:j_idt109' => 'j_idt98:j_idt109',
            'j_idt98' => 'j_idt98',
            'j_idt98:j_idt115' => '', 
            'j_idt98:j_idt138' => '',
            'javax.faces.ViewState' => $view_state,  
        ];

        $response = $this->_client->request('POST', 'https://www1.fips.ru/iiss/search_res.xhtml', 
        [
            'form_params' => $post,
            'allow_redirects' => false,
            'headers'=> [
                'Origin' => 'https://www1.fips.ru',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'same-origin',
                'Sec-Fetch-User' => '?1',
                'Upgrade-Insecure-Requests' => '1',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Site'  => 'cross-site',
                'Sec-Fetch-Mode'  => 'navigate', 
                'Accept-Encoding' => 'gzip, deflate, br', 
                'Upgrade-Insecure-Requests' => '1', 
                'Cache-Control' => 'max-age=0',              
            ],    
        ]);

        $redirect1 = $this->_client->request('GET', 'https://www1.fips.ru/iiss/search_res.xhtml?faces-redirect=true', 
        [
            'allow_redirects' => false,
            'headers'=> [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Site'  => 'cross-site',
                'Sec-Fetch-Mode'  => 'navigate', 
                'Accept-Encoding' => 'gzip, deflate, br', 
                'Upgrade-Insecure-Requests' => '1', 
                'Cache-Control' => 'max-age=0',              
            ]
        ]);

        
        //$redirect2 = $this->_client->request('GET', 'https://www1.fips.ru/iiss/search_res.xhtml?faces-redirect=true', [
        //    'allow_redirects' => false,
        //    'headers'=> [
        //        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3',
        //        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        //        'Sec-Fetch-Site'  => 'cross-site',
        //        'Sec-Fetch-Mode'  => 'navigate', 
        //        'Accept-Encoding' => 'gzip, deflate, br', 
        //        'Upgrade-Insecure-Requests' => '1', 
        //        'Cache-Control' => 'max-age=0',              
        //    ]
        //]);
        

        $body = $redirect1->getBody()->getContents();

        $dom = HtmlDomParser::str_get_html($body);
        $view_state_new = $dom->find('//*[@id="j_id1:javax.faces.ViewState:2"]')[0]->value;

        return $body;
    }
    

    /**
     * Метод сохраняет значение полученных данных в переменную.
     * return array [bool, view_state] (есть ли дальше пагинация)
     */
    private function savePageValue($html, $pagination) : array
    {
        $isParce = false;
        $isNextPage = false;
        $result = ['isNextPage' => false, 'view_state' => ''];

        $dom = HtmlDomParser::str_get_html($html);

        $view_state_new = $dom->find('//*[@id="j_id1:javax.faces.ViewState:2"]')[0]->value;
        $result['view_state'] = $view_state_new;
        //Получаем строку таблицы
        $listRowDom = $dom->find('//*[@class="table"]/a');
        
        //Пишем в $_listValue новые значения массива строк.
        $listRow = [];
        $table = [];
        foreach ($listRowDom as $key => $item)
        {
            $item_html = $item->outertext;
            $rowDom = $item->find("div");
            foreach ($rowDom as $key_column=> $column)
            {
                if ($key_column == 3)
                {
                    $column->plaintext = 'Img';
                }
                $table[$key][] = $column->plaintext;
            }       
        }
        $this->_listValue ['Pagination-' . $pagination]= $table;
        //Узнаем есть ли переход на следующую страницу

        if (isset($dom->find('//*[@id="j_idt98:j_idt109"]')[0]))
        {
            $isNextPageDom = $dom->find('//*[@id="j_idt98:j_idt109"]')[0]; 
            if (isset($isNextPageDom->href))
            {
                $result['isNextPage'] = true;
            }
        }
        else
        {
            $result['isNextPage'] = false;
        }

        return $result;
    }
}


?>