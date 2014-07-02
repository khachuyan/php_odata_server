<?php

require_once('odata_server.php');

class odata_server_worker extends odata_server{
    
    public $fields_int = array('fields_prefix_1');
    public $fields_boolean = array();
    public $fields_double = array();
    public $fields_date = array();
    public $fields_string = array();
    
    public function items($headers, $urls, $params){
        
        print_r($headers);
        print_r($urls);
        print_r($params);
        
        $this->set_feed_params('feed name', 'fields_prefix');
        
        $data = array();
        
        $data[] = array('1' => 44356,    'test' => 'ghg');
        $data[] = array('1' => 3563456,  'test' => 'sg');
        $data[] = array('1' => 234,      'test' => 'yrhyyh');
        $data[] = array('1' => 456578,   'test' => '658k578k');
        
        return $data;
    }
    
    public function translate_name($name){
        
        switch($name){
            
            case "1" : return 'First Row';
                case "test" : return 'Second row';
                
            default : return $name;
        }
    }
    
}

$odata_server_worker = new odata_server_worker('Test OData','http://', 'localhost', 4112, 0, TRUE);
$odata_server_worker->start();

?>