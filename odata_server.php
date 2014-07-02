<?php

class odata_server{
    
    public $fields_int;
    public $fields_boolean;
    public $fields_double;
    public $fields_date;
    public $fields_string;
    
    private $_debug;
    private $_cache_time;
    private $_port;
    private $_name;
    private $_socket;
    private $_connect;
    private $_protocol;
    private $_server;
    private $_url;
    private $_feed_name;
    private $_feed_fields_prefix;
	
    function __construct($name, $protocol, $server_address, $port, $cache_time = 0, $debug = FALSE){
        
	$this->_console_log('INFO', 'Applying settings');
		
	error_reporting(E_ALL);
	date_default_timezone_set('Europe/Moscow');
	ini_set('memory_limit', '4000M');
	set_error_handler(array($this, '_err_handler'));
	
        $this->_name = $name;	
        $this->_port = $port;
        $this->_debug = $debug;
        $this->_cache_time = $cache_time;
        
	$this->_protocol = $protocol;
        $this->_server = $server_address;
        $this->_url = $this->_protocol.$this->_server;
	
        $this->_initialize();
        
    }
    public function start(){
            
        $this->_console_log('INFO', 'Starting OData server on port '. $this->_port);
            
        while ($this->_connect = stream_socket_accept($this->_socket, -1)) {
    
            $request = fread($this->_connect, 100000);
            
            $headers = $this->_parse_raw_http_headers($request);
            $urls = $this->_parse_raw_http_get($request);
            $params = $this->_parse_raw_http_params($request);
            
	    $this->_console_log('DEBUG', 'Receive request');
	    
	    if($this->_cache_time > 0){
		
		$folder_name = implode('_', $urls).implode('_', $params);
		
		if(is_dir('./cache/'.$folder_name)){
		    
		    $files = scandir('./cache/'.$folder_name);
		    $cache_file_name = '';
		    
		    foreach($files as $file){
			if(in_array($file, array('.', '..'))) continue;
			$cache_diff = time() - intval($file);
			
			if($cache_diff <= $this->_cache_time){
			    
			    $cache_file_name = './cache/'.$folder_name.'/'.$file;
			    
			    break;
			}else{
			    unlink('./cache/'.$folder_name.'/'.$file);
			}
			
		    }
		    
		    if(empty($cache_file_name)){
			$items = $this->items($headers, $urls, $params);
			$data = $this->_result($this->_feed_name, $items, $this->_feed_fields_prefix);
			
			file_put_contents('./cache/'.$folder_name.'/'.time(), $data);
			
			$this->_console_log('INFO', 'Items load, and update CACHE');
		    }else{
			$data = file_get_contents($cache_file_name);	    
			$this->_console_log('INFO', 'Items load from CACHE ('.$cache_diff.')');    	    
		    }
		    
		}else{
		    
		    mkdir('./cache/'.$folder_name);
		    
		    $items = $this->items($headers, $urls, $params);
		    $data = $this->_result($this->_feed_name, $items, $this->_feed_fields_prefix);
		    
		    file_put_contents('./cache/'.$folder_name.'/'.time(), $data);
		    
		    $this->_console_log('INFO', 'Items load, and generate CACHE');
		}
		
	    }else{
		$this->_console_log('INFO', 'Items load from USER');	
		$items = $this->items($headers, $urls, $params);
		$data = $this->_result($this->_feed_name, $items, $this->_feed_fields_prefix);
	    }
	    
            fwrite($this->_connect, "HTTP/1.0 200 OK\r\nConnection:Keep-Alive\r\nContent-Length:".strlen($data)."\r\nContent-Type:text/xml\r\nKeep-Alive:timeout=5, max=99\r\nServer:Apache/2.4.6 (Debian)\r\n\r\n");        
            fwrite($this->_connect, $data);
	    
	    $this->_console_log('DEBUG', 'Response sent');
	    
            fclose($this->_connect);
        }
            
        fclose($this->_socket);
    }
    public function set_feed_params($name, $fields_prefix = ''){
	$this->_feed_name = $name;
	$this->_feed_fields_prefix = $fields_prefix;
	return $this;
    }
    private function _result($id, $items, $prefix = '', $nulled = FALSE){
        
        $dom = new domDocument("1.0", "utf-8");
        $dom->preserveWhiteSpace = false;
        
        $dom->formatOutput = true;
        $root = $dom->createElement("feed");
        
        $root->setAttribute('xml:base', $this->_url.'/'.$id);
        $root->setAttribute('xmlns:d','http://schemas.microsoft.com/ado/2007/08/dataservices');
        $root->setAttribute('xmlns:m','http://schemas.microsoft.com/ado/2007/08/dataservices/metadata');
        $root->setAttribute('xmlns','http://www.w3.org/2005/Atom');
        
        $title = $dom->createElement("title", 'SocialDataHub '.$id);
        $title->setAttribute('type', 'text');
        $root->appendChild($title);
        
        $_id = $dom->createElement("id", $this->_url.'/'.$id);
        $root->appendChild($_id);
        
        $updated = $dom->createElement("updated", date("Y-m-d\TH:i:s\Z",time()));
        $root->appendChild($updated);
        
        $link = $dom->createElement("link");
        $link->setAttribute('rel', 'self');
        $link->setAttribute('title', $id);
        $link->setAttribute('href', $id);
        $root->appendChild($link);
        
	$i = 0;
        $fields = array();
        
        if($nulled){
            foreach($items as $item){
                foreach($item as $key => $value){
                    $fields[$key] = 1;
                }
            }
        }
        
        foreach($items as $item){
            
            if($nulled){
                foreach($fields as $field => $one){
                    if(!in_array($field, array_keys($item))) $item[$field] = 'null';
                }
            }
            
            if(!isset($item['id'])){ $item['id'] = $i; $i++;}
            $entry = $dom->createElement("entry");
            
            $item_id = $dom->createElement("id", $this->_url.'/'.$id.'/'.$item['id']);
            $entry->appendChild($item_id);
            
            $item_title = $dom->createElement("title", $item['id']);
            $item_title->setAttribute('type', 'text');
            $entry->appendChild($item_title);
            
            $item_updated = $dom->createElement("updated", date("Y-m-d\TH:i:s\Z",time()));
            $entry->appendChild($item_updated);
            
            $author = $dom->createElement("author");
                $name = $dom->createElement("name");
                $author->appendChild($name);    
            $entry->appendChild($author);
            
            $item_link = $dom->createElement("link");
            $item_link->setAttribute('rel', 'edit');
            $item_link->setAttribute('title', $id);
            $item_link->setAttribute('href', $id.'('.$item['id'].')');
            $entry->appendChild($item_link);
            
            $item_category = $dom->createElement("category");
            $item_category->setAttribute('term', $id);
            $item_category->setAttribute('scheme', 'http://schemas.microsoft.com/ado/2007/08/dataservices/scheme');
            $entry->appendChild($item_category);
            
            $item_content = $dom->createElement("content");
            $item_content->setAttribute('type', 'application/xml');
            $properties = $dom->createElement("m:properties");
                
                //GENERATE CONTENT
                foreach($item as $key => $value){
                    
                    $key = $prefix.$key;
                    if(!is_array($value) && !in_array($key, array('mongo_documents__account_id','mongo_profiles_friends','mongo_profiles_timezone','mongo_profiles_history','mongo_profiles_updated','mongo_profiles__id','mongo_documents_image','mongo_documents_twitter_user','mongo_documents_twitter_user_id','mongo_documents_twitter_status_id','mongo_documents_objects','mongo_documents_aggregated','mongo_documents_links','mongo_documents_analysis','mongo_documents_timestamp','mongo_documents__id','socialcrm_statistic_intraday_replay_timestamp','socialcrm_statistic_intraday_replay_id','socialcrm_accounts_aggregated_id','socialcrm_accounts_aggregated_account_id','socialcrm_accounts_links_objects_account_id','socialcrm_accounts_aggregated_timestamp','socialcrm_accounts_aggregated_last_account_id','socialcrm_accounts_official','socialcrm_accounts_user_id','socialcrm_accounts_links_objects_user','socialcrm_accounts_links_objects_object_id','socialcrm_accounts_links_objects_id','socialcrm_accounts_aggregated_last_timestamp','socialcrm_accounts_agent_id','socialcrm_accounts_verify','socialcrm_accounts_password','socialcrm_accounts_auditory_filled','socialcrm_accounts_auditory_researched','socialcrm_accounts_crawler_here','socialcrm_accounts_profiler_here','socialcrm_accounts_solr_id','socialcrm_accounts_updated_time','socialcrm_accounts_using','socialcrm_accounts_reference','socialcrm_accounts_ref_type','socialcrm_accounts_links_objects_account_id','socialcrm_objects_project','socialcrm_objects_parent','socialcrm_objects_index_c','socialcrm_objects_user','socialcrm_objects_links_users_user_id','socialcrm_objects_links_users_object_id','socialcrm_users__date_last_vizit','socialcrm_users__date_created','socialcrm_users__date_updated','socialcrm_users_avatar','socialcrm_users_banned','socialcrm_users_object','socialcrm_users_phone','socialcrm_users_many_child','socialcrm_users_recommend_phone','socialcrm_users_recommend','socialcrm_users_kvartira','socialcrm_users_stroenie','socialcrm_users_build','socialcrm_users_street','socialcrm_users_city','socialcrm_users_username','socialcrm_users_access','socialcrm_users_password'))){
                        
                        $field = "d:".str_replace(" ", "_", $this->translate_name($key));
                        
                        //if(in_array($key, array('id','name','surname','email','socialcrm_objects_id','socialcrm_objects_name','type','socialcrm_accounts_type','socialcrm_accounts_username','dynamic','value','timestamp','official','object_type','socialcrm_accounts_aggregated_last_type','socialcrm_accounts_aggregated_last_account_id', 'accounts_deleted','socialcrm_accounts_deleted'))) continue;
                        
                        if(is_int($value) || in_array($key, $this->fields_int)){
                            $tmp = $dom->createElement($field, $value);
                            $tmp->setAttribute('m:type', 'Edm.Int32');
                        }elseif(in_array($key, $this->fields_date)){
                            $tmp = $dom->createElement($field, date("Y-m-d\TH:i:s\Z",strtotime($value)));
                            $tmp->setAttribute('m:type', 'Edm.DateTimeOffset');
                        }elseif($key == 'id'){
                            $tmp = $dom->createElement($field, $value);
                            //$tmp->setAttribute('m:type', 'Edm.Guid');
                        }elseif(in_array($key, $this->fields_boolean)){
                            $tmp = $dom->createElement($field, strtr($value, array('yes' => 'true', 'no' => 'false', 'up' => 'true', 'down' => 'false', '1' => 'true', '0' => 'false',)));
                            $tmp->setAttribute('m:type', 'Edm.Boolean');
                        }elseif(in_array($key, $this->fields_double)){
                            $tmp = $dom->createElement($field, $value);
                            $tmp->setAttribute('m:type', 'Edm.Double');
                        }elseif(in_array($key, $this->fields_string)){
                            $tmp = $dom->createElement($field, $value);
                            $tmp->setAttribute('m:type', 'Edm.String');
                        }else{
                            $cdata = $dom->createCDATASection($value);
                            $tmp = $dom->createElement($field);
                            $tmp->appendChild($cdata);
                        }
                        
                        $properties->appendChild($tmp);       
                    }
                    continue;
                }
                
                $item_content->appendChild($properties);
            $entry->appendChild($item_content);
            
            $root->appendChild($entry);
            $i++;
            
        }
        
        $dom->appendChild($root);
        
        return $dom->saveXML();
    }
    //EVENTS
    
    public function items($headers, $urls, $params){
	return array();
    }
    public function translate_names($name){
	return $name;
    }
    
    //
    private function _parse_raw_http_get($request){
        
        if($request != '/'){
            $request = substr($request, 4, strpos($request, " HTTP") - 4);
	    
	    if(substr_count($request, "?") > 0){
		$request = substr($request, 0, strpos($request, "?"));
	    }else{
		$request = substr($request, 0);
	    }
            $urls = array_filter(explode('/', $request));
            
            return $urls;
        }else{
            return array();
        }
    }
    private function _parse_raw_http_params($request){
        
	if(substr_count($request, "?") > 0){
	
	    $params = array();
	    $request = substr($request, 4, strpos($request, " HTTP") - 4);
	    $request = substr($request, strpos($request, "?") + 1);
	    
	    if(empty($request)) return array();
	    
	    $pairs = explode('&', $request);
	    foreach($pairs as $pair) {
		$part = explode('=', $pair);
		$param = str_replace('?', '', strtolower(substr($part[0], strpos($part[0], "?"))));
		$value = isset($part[1]) ? urldecode($part[1]) : null;
		$params[$param] = $value;
	    }
	    
	    return $params;
	}else{
	    return array();
	}
    }
    private function _parse_raw_http_headers($request){
    
        $headers = array();
        $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $request));
        foreach( $fields as $field ) {
            if( preg_match('/([^:]+): (.+)/m', $field, $match) ) {
                $match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
                if( isset($headers[$match[1]]) ) {
                    $headers[$match[1]] = array($headers[$match[1]], $match[2]);
                } else {
                    $headers[$match[1]] = trim($match[2]);
                }
            }
        }
        return $headers;
    }
    private function _initialize(){
            
        $this->_console_log('DEBUG', 'Initialization');
	
	$this->_feed_name = 'test feed';
	
        $this->_console_log('DEBUG', 'Checking paths');
        
        if(!is_dir('./logs')) mkdir('./logs');
	if($this->_cache_time > 0 && !is_dir('./cache')) mkdir('./cache');
        
        $this->_console_log('DEBUG', 'Creating socket');
        
        $this->_socket = stream_socket_server("tcp://0.0.0.0:".$this->_port, $errno, $errstr);
        
        if (!$this->_socket){
                $this->_console_log('ERROR', 'Error creating socket: '."$errstr ($errno)\n", TRUE);
        }
        
    }
    private function _console_log($level = 'INFO', $message, $die = FALSE){
    
	if(!$this->_debug) return false;
	switch($level){
		    
	    case 'DEBUG' : $color = 33; break;
	    case 'INFO' : $color = 32; break;
	    case 'WARNING' : $color = 31; break;
	    case 'ERROR' : $color = 31; break;
		    
            default : $color = 32; break;
	}
		
	print("\x1b[0;".$color."m");
	print("[".$level."]\t".date('Y-m-d H:i:s', time())." ".$message.PHP_EOL);
	if($die) exit();
    }
    private function _err_handler($errno, $errmsg, $filename, $linenum) {
	if($this->_debug) $this->_console_log('ERROR', $errno.':'.$errmsg.':'.$filename.':'.$linenum);
	file_put_contents('./logs/error.log', date('Y-m-d H:i:s').' [ERROR] '.$errno.':'.$errmsg.':'.$filename.':'.$linenum.PHP_EOL, FILE_APPEND);
    }
}

?>