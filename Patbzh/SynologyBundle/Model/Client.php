<?php
namespace Patbzh\SynologyBundle\Model;

use Buzz\Message\Request;
use Buzz\Message\Response;
use Patbzh\SynologyBundle\Exception\PatbzhSynologyException;

/**
 * Limited client API to make requests to Synology - DSM 4.3 version (FileStation management)
 *
 * @author Patrick Coustans <patrick.coustans@gmail.com>
 */
class Client
{
    /**********************
     Attributes definition
    **********************/ 
    private $httpClient;
    private $baseUrl;
    private $user;
    private $password;
    private $sessionName;
    private $isValidatedQueries;

    protected $sessionId=null;
    protected $availableApis=array();

    const TORRENT_SEARCH_ARTIFICIAL_SCORE_IMPROVEMENT=1000000;

    /**********************
     Getters & setters
    **********************/ 
    public function getHttpClient() {
        return $this->httpClient;
    }

    public function setHttpClient($httpClient) {
        $this->httpClient = $httpClient;
        return $this;
    }

    public function getBaseUrl() {
        return $this->baseUrl;
    }

    public function setBaseUrl($baseUrl) {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    public function getUser() {
        return $this->user;
    }

    public function setUser($user) {
        $this->user = $user;
        return $this;
    }

    public function getPassword() {
        return $this->password;
    }

    public function setPassword($password) {
        $this->password = $password;
        return $this;
    }

    public function getSessionId() {
        return $this->sessionId;
    }

    public function setSessionId($sessionId) {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getSessionName() {
        return $this->sessionName;
    }

    public function setSessionName($sessionName) {
        $this->sessionName = $sessionName;
        return $this;
    }

    public function getIsQueriesValidated() {
        return $this->isValidatedQueries;
    }

    public function setIsQueriesValidated($isValidatedQueries) {
        $this->isValidatedQueries = $isValidatedQueries;
        return $this;
    }

    public function getAvailableApis() {
        return $this->availableApis;
    }

    /**********************
     Utility functions
    **********************/ 
    protected function isLoggedIn() {
        if(is_null($this->sessionId)) return false;
        return true;
    }

    /**
     * Retrieve error message based on error code
     *
     * @param integer $code Error code
     *
     * @return string Error message
     */
    protected function getErrorMessage($code) {
        switch($code) {
            case 100 : return 'Unknown error';
            case 101 : return 'Invalid parameter';
            case 102 : return 'The requested API does not exist';
            case 103 : return 'The requested method does not exist';
            case 104 : return 'The requested version does not support the functionality';
            case 105 : return 'The logged in session does not have permission';
            case 106 : return 'Session timeout';
            case 107 : return 'Session interrupted by duplicate login';
            // File station specific error codes
            case 400 : return "Invalid parameter of file operation";
            case 401 : return "Unknown error of file operation";
            case 402 : return "System is too busy";
            case 403 : return "Invalid user does this file operation";
            case 404 : return "Invalid group does this file operation";
            case 405 : return "Invalid user and group does this file operation";
            case 406 : return "Can’t get user/group information from the account server";
            case 407 : return "Operation not permitted";
            case 408 : return "No such file or directory";
            case 409 : return "Non-supported file system";
            case 410 : return "Failed to connect internet-based file system (ex: CIFS)";
            case 411 : return "Read-only file system";
            case 412 : return "Filename too long in the non-encrypted file system";
            case 413 : return "Filename too long in the encrypted file system";
            case 414 : return "File already exists";
            case 415 : return "Disk quota exceeded";
            case 416 : return "No space left on device";
            case 417 : return "Input/output error";
            case 418 : return "Illegal name or path";
            case 419 : return "Illegal file name";
            case 420 : return "Illegal file name on FAT file system";
            case 421 : return "Device or resource busy";
            case 599 : return "No such task of the file operation";
        }
        return 'Unknown error code';
    }

    /**
     * Generic request manager to betaseries API
     *
     * @param string $cgiPath Request path 
     * @param string $apiName Api name
     * @param string $version Api version
     * @param string $method Method
     * @param array $additionalParams (Optionnal - default null) Other params for the request
     * @param boolean $isLoginRequired (Optionnal - default true) Indicates if login is required or not - Normally login is not required for login and info requests
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    protected function request($cgiPath, $apiName, $version, $method, $additionalParams=null, $isLoginRequired=true) {
        // Check Available APIS
        if($this->getIsQueriesValidated() && !in_array($apiName, array('SYNO.API.Auth','SYNO.API.Info')) && !isset($this->availableApis[$apiName])) {
            $this->retrieveAvailableApis($apiName);

            // Validate API information
            $calledApiInformation = $this->availableApis[$apiName];
            if(is_null($calledApiInformation)) throw new \RuntimeException('Unavailable API');
            if($cgiPath != $calledApiInformation['ApiPath']) throw new \RuntimeException('CGI Path problem [Requested: '.$cgiPath.'][ServerVersion: '.$calledApiInformation['ApiPath'].']');
            if($calledApiInformation['MinVersion']>$version) throw new \RuntimeException('API Version issue [Requested: '.$version.'][MinVersion: '.$calledApiInformation['MinVersion'].']');
            if($calledApiInformation['MaxVersion']<$version) throw new \RuntimeException('API Version issue [Requested: '.$version.'][MaxVersion: '.$calledApiInformation['MaxVersion'].']');
        }

        // Login if required
        if($isLoginRequired && !$this->isLoggedIn()) $this->login();

        // Set "mandatory" parameters list
        $params = array(
            'api'=>$apiName,
            'version'=>$version,
            'method'=>$method,
            );

        // Add additionnal parameters
        if($additionalParams !== null) {
	    $params = $params + $additionalParams;
        }

        // Add "Session id" if logged in
        if($this->isLoggedIn()) $params['_sid'] = $this->getSessionId();

        // Set request
        $queryString = 'webapi/'.$cgiPath.'?'.http_build_query($params);
        $request = new Request('GET', $queryString, $this->getBaseUrl());
	$response = new Response();

        // Handle request
        $this->httpClient->send($request, $response);
        // Check response
        $parsedResponse = json_decode($response->getContent(), true);
        // Throw exception in case of error
        if($parsedResponse['success'] !== true) {
            throw new PatbzhSynologyException($this->getErrorMessage($parsedResponse['error']['code']).' ('.$parsedResponse['error']['code'].')', $parsedResponse['error']['code']);
        }

        // Return json_decoded response
        return $parsedResponse;
    }

    /**********************
     Synology request
    **********************/ 

    /**********************
     General methods
    **********************/ 

    /**
     * Create a synology "sid"
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    public function login() {
        $params['account'] = $this->getUser();
        $params['passwd'] = $this->getPassword();
        $params['session'] = $this->getSessionName();
        $params['format'] = 'sid';

        $response = $this->request('auth.cgi', 'SYNO.API.Auth', 2, 'login', $params, false);

        $this->setSessionId($response['data']['sid']);
    }

    /**
     * Retrieve available synology apis
     *
     * @param string $filter (Optionnal - Default all) Appi list to filter
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function retrieveAvailableApis($filter='all') {
        if(!is_string($filter)) throw new \InvalidArgumentException('$filter should be a string');

        $response = $this->request('query.cgi', 'SYNO.API.Info', 1, 'query', array('query'=>$filter), false);

        foreach($response['data'] as $apiName=>$data) {
            $this->availableApis[$apiName] = array(
                'ApiName'=>$apiName,
                'ApiPath'=>$data['path'],
                'MinVersion'=>$data['minVersion'],
                'MaxVersion'=>$data['maxVersion'],
                );
        }
    }

    /**********************
     Download API
    **********************/ 

    /**
     * List current download tasks
     *
     * @param integer $offset (Optionnal - Default 0) Beginning task on the requested record 
     * @param integer $limit (Optionnal - Default -1) Number of records requested: “-1” means to list all tasks
     * @param array $additional (Optionnal) Additionnal information in detail|transfer|file|tracker|peer
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function getDownloadList($offset=null, $limit=null, $additional=null) {
        if(!is_null($offset) && !is_integer($offset)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($limit) && !is_integer($limit)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($additional) && !is_array($additional)) throw new \InvalidArgumentException('$additional should be an array');

        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('detail','transfer','file','tracker','peer'))) throw new \InvalidArgumentException('$additional parameter should be one of this value detail|transfer|file|tracker|peer');
            }
	}

        $params = array();
        if(isset($offset)) $params['offset'] = $offset;
        if(isset($limit)) $params['limit'] = $limit;
        if(isset($additional)) $params['additional'] = implode(',',$additional);
        
        return $this->request('DownloadStation/task.cgi', 'SYNO.DownloadStation.Task', 1, 'list', $params);
    }

    /**
     * Get information on current downloads
     *
     * @param array $id Tasks id
     * @param array $additional (Optionnal) Additionnal information in detail|transfer|file|tracker|peer
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function getDownloadInfo($id, $additional=null) {
        if(!is_null($additional) && !is_array($additional)) throw new \InvalidArgumentException('$additional should be an array');
        if(!is_null($id) && !is_array($id)) throw new \InvalidArgumentException('$id should be an array');

        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('detail','transfer','file','tracker','peer'))) throw new \InvalidArgumentException('$additional parameter should be one of this value detail|transfer|file|tracker|peer');
            }
	}

        $params = array();
        if(isset($id)) $params['id'] = implode(',',$id);
        if(isset($additional)) $params['additional'] = implode(',',$additional);
        
        return $this->request('DownloadStation/task.cgi', 'SYNO.DownloadStation.Task', 1, 'getinfo', $params);
    }

    /**
     * Get download station info
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    public function getDownloadStationInfo() {
        return $this->request('DownloadStation/info.cgi', 'SYNO.DownloadStation.Info', 1, 'getinfo', null);
    }

    /**
     * Get download station config
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    public function getDownloadStationConfig() {
        return $this->request('DownloadStation/info.cgi', 'SYNO.DownloadStation.Info', 1, 'getconfig', null);
    }

    /**
     * UNTESTED - Set download station config
     *
     * @param array $config Configuration changes
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    public function setDownloadStationConfig($parameters) {
        return $this->request('DownloadStation/info.cgi', 'SYNO.DownloadStation.Info', 1, 'setserverconfig', $parameters);
    }

    /**
     * Search using extratorrent.cc ('DownloadStation/btsearch.cgi'/'SYNO.DownloadStation.BTSearch' undocumented)
     *
     * @param string $text Text to search
     * @param string $filter (Optionnal) Artificially improve score if torrent file name contains $filter text
     * @param integer $minSize (Optionnal - Default 0) Minimum file size
     * @param integer $maxSize (Optionnal - Defatul 1073741824=1G) Maximum file size
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    public function searchDownload($text, $filter=null, $minSize=0, $maxSize=1073741824) {
        if(!is_string($text)) throw new \InvalidArgumentException('$text should be a string');
        if(!is_null($filter) && !is_string($filter)) throw new \InvalidArgumentException('$filter should be a string');
        if(!is_null($minSize) && !is_integer($minSize)) throw new \InvalidArgumentException('$minSize should be an integer');
        if(!is_null($maxSize) && !is_integer($maxSize)) throw new \InvalidArgumentException('$maxSize should be an integer');

        // Send request to extratorrent in order to torrent file
        $request = new Request('GET', '/rss.xml?type=search&search='.urlencode($text), 'http://extratorrent.cc');
	$response = new Response();

        // Handle request and check results
        $this->httpClient->send($request, $response);

        $xml = new \SimpleXMLElement($response->getContent());

        $items = $xml->xpath('/rss/channel/item');

        $availableTorrents = array();
        foreach($items as $item) {
            if($item->size < $minSize || $item->size > $maxSize) continue;
            $score = (int)$item->seeders;
            $url = (string)$item->enclosure['url'];
            if(!is_null($filter) && preg_match('/'.$filter.'/', $url)) $score+=self::TORRENT_SEARCH_ARTIFICIAL_SCORE_IMPROVEMENT;
            $availableTorrents[$score] = array(
                'uri'=>$url,
                'size'=>(int)$item->size,
                'seeders'=>(int)$item->seeders,
                'leechers'=>(int)$item->leechers,
            );
        }
        // Sort by seeders desc
        krsort($availableTorrents);

        $selectedItem = current($availableTorrents);

        return $this->addDownloadTask($selectedItem['uri']);
    }

    /**
     * Add a download task based on a torrent uri (zip with password not managed)
     *
     * @param string $uri URI of the file
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function addDownloadTask($uri) {
        if(!is_string($uri)) throw new \InvalidArgumentException('$uri should be a string');

        $params = array();
        $params['uri'] = $uri;

        return $this->request('DownloadStation/task.cgi', 'SYNO.DownloadStation.Task', 1, 'create', $params);
    }

    /**
     * Remove tasks
     *
     * @param array $id Tasks id
     * @param boolean $forceComplete (Optionnal - Default false) Move uncompleted download files
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function removeTasks($id, $forceComplete=false) {
        if(!is_array($id)) throw new \InvalidArgumentException('$id should be an array');
        if(!is_bool($forceComplete)) throw new \InvalidArgumentException('$forceComplete should be a boolean');

        $params = array();
        $params['id'] = implode(',',$id);
        if($forceComplete) $params['force_complete'] = 'true';
        if(!$forceComplete) $params['force_complete'] = 'false';
        
        return $this->request('DownloadStation/task.cgi', 'SYNO.DownloadStation.Task', 1, 'delete', $params);
    }

    /**
     * Remove finished tasks or tasks in error or seeding tasks
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    public function removeFinishedTasks() {
        $list = $this->getDownloadList();
        $finishedTasks = array();
        foreach($list['data']['tasks'] as $task) {
            if($task['status'] == 'finished' || $task['status'] == 'error' || $task['status'] == 'seeding') {
                $finishedTasks[] = $task['id'];
            }
        }
        if(empty($finishedTasks)) return null;
        return $this->removeTasks($finishedTasks);
    }

    /**********************
     File API
    **********************/ 

    /**
     * List current download tasks
     *
     * @param integer $offset (Optionnal - Default 0) Beginning task on the requested record 
     * @param integer $limit (Optionnal - Default -1) Number of records requested: “-1” means to list all tasks
     * @param array $additional (Optionnal) Additionnal information in detail|transfer|file|tracker|peer
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function getDownloadList($offset=null, $limit=null, $additional=null) {
        if(!is_null($offset) && !is_integer($offset)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($limit) && !is_integer($limit)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($additional) && !is_array($additional)) throw new \InvalidArgumentException('$additional should be an array');

        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('detail','transfer','file','tracker','peer'))) throw new \InvalidArgumentException('$additional parameter should be one of this value detail|transfer|file|tracker|peer');
            }
	}

        $params = array();
        if(isset($offset)) $params['offset'] = $offset;
        if(isset($limit)) $params['limit'] = $limit;
        if(isset($additional)) $params['additional'] = implode(',',$additional);
        
        return $this->request('DownloadStation/task.cgi', 'SYNO.DownloadStation.Task', 1, 'list', $params);
    }
}
