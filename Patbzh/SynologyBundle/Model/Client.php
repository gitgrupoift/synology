<?php
namespace Patbzh\SynologyBundle\Model;

use Buzz\Message\Request;
use Buzz\Message\Form\FormRequest;
use Buzz\Message\Form\FormUpload;
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
            case 1100: return "Failed to create a folder. More information in <errors> object.";
            case 1101: return "The number of folders to the parent folder would exceed the system limitation.";
            // File upload
            case 1800: return "There is no Content-Length information in the HTTP header or the received size doesn’t match the value of Content-Length information in the HTTP header.";
            case 1801: return "Wait too long, no date can be received from client (Default maximum wait time is 3600 seconds).";
            case 1802: return "No filename information in the last part of file content.";
            case 1803: return "Upload connection is cancelled.";
            case 1804: return "Failed to upload too big file to FAT file system.";
            case 1805: return "Can’t overwrite or skip the existed file, if no overwrite parameter is given.";

        }
        return 'Unknown error code ('.$code.')';
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * Stop synology session
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     */
    public function logout() {
        $response = $this->request('auth.cgi', 'SYNO.API.Auth', 2, 'logout');
        return $response;
    }

    /**
     * Retrieve available synology apis
     *
     * @param string $filter (Optionnal - Default all) Appi list to filter
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
     */
    public function getDownloadStationInfo() {
        return $this->request('DownloadStation/info.cgi', 'SYNO.DownloadStation.Info', 1, 'getinfo', null);
    }

    /**
     * Get download station config
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * @throws PatbzhSynologyException In case synology api sends an error response
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
     * Get information on File API
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function getFileApiInfo() {
        return $this->request('FileStation/info.cgi', 'SYNO.FileStation.Info', 1, 'getinfo');
    }

    /**
     * Get shared folders
     *
     * @param integer $offset (Optionnal - Default 0) Number of skipped folders
     * @param integer $limit (Optionnal - Default 0) Number of shared folders
     * @param array $sortBy (Optionnal) Sort by name|user|group|mtime|atime|ctime|crtime|posix
     * @param string $sortOrder (Optionnal - Default asc) Sort order asc|desc
     * @param string $onlyWritable (Optionnal - Default false) true = Only writable | false = Writable & readable
     * @param array $additional (Optionnal) Additionnal information in real_path|size|owner|time|perm|mount_point_type|volume_status
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function getSharedFolders($offset=null, $limit=null, $sortBy=null, $sortOrder=null, $onlyWritable=null, $additional=null) {
        if(!is_null($offset) && !is_integer($offset)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($limit) && !is_integer($limit)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($onlyWritable) && !is_bool($onlyWritable)) throw new \InvalidArgumentException('$onlyWritable should be a boolean');
        if(!is_null($sortOrder) && !in_array($sortOrder, array('asc','desc'))) throw new \InvalidArgumentException('$sortOrder parameter should be one of this value asc|desc');
        if(!is_null($sortBy) && !is_array($sortBy)) throw new \InvalidArgumentException('$sortBy should be an array');

        if(!is_null($additional) && !is_array($additional)) throw new \InvalidArgumentException('$additional should be an array');

        if(!is_null($sortBy)) {
            foreach($sortBy as $value) {
                if(!is_string($value) && !in_array($sortBy, array('name','user','group','mtime','atime','ctime','crtime','posix'))) throw new \InvalidArgumentException('$sortBy parameter should contain this value name|user|group|mtime|atime|ctime|crtime|posix');
            }
        }
        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('real_path','size','owner','time','perm','mount_point_type','volume_status'))) throw new \InvalidArgumentException('$additional parameter should contain this value real_path|size|owner|time|perm|mount_point_type|volume_status');
            }
        }

        $params = array();
        if(isset($offset)) $params['offset'] = $offset;
        if(isset($limit)) $params['limit'] = $limit;
        if(isset($sortOrder)) $params['sort_order'] = $sortOrder;
        if(isset($onlyWritable) && $onlyWritable) $params['onlywritable'] = 'true';
        if(isset($onlyWritable) && !$onlyWritable) $params['onlywritable'] = 'false';

        if(isset($sortBy)) $params['sort_by'] = implode(',',$sortBy);
        if(isset($additional)) $params['additional'] = implode(',',$additional);

        return $this->request('FileStation/file_share.cgi', 'SYNO.FileStation.List', 1, 'list_share', $params);
    }

    /**
     * Get folder content
     *
     * @param string $path Path to list
     * @param integer $offset (Optionnal - Default 0) Number of skipped folders
     * @param integer $limit (Optionnal - Default 0) Number of shared folders
     * @param array $sortBy (Optionnal) Sort by name|user|group|mtime|atime|ctime|crtime|posix
     * @param string $sortOrder (Optionnal - Default asc) Sort order asc|desc
     * @param string $pattern (Optionnal) Pattern filter
     * @param string $filetype (Optionnal - Default all) Entry type dir|file|all
     * @param string $gotoPath (Optionnal) Max sub folder level to return
     * @param array $additional (Optionnal) Additionnal information in real_path|size|owner|time|perm|mount_point_type|type
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function getFolderContent($path, $offset=null, $limit=null, $sortBy=null, $sortOrder=null, $pattern=null, $filetype=null, $gotoPath=null, $additional=null) {
        if(!is_string($path)) throw new \InvalidArgumentException('$path should be a string');
        if(!is_null($pattern) && !is_string($pattern)) throw new \InvalidArgumentException('$pattern should be a string');
        if(!is_null($filetype) && !is_string($filetype) && !in_array($filetype, array('file','dir','all'))) throw new \InvalidArgumentException('$filetype should be one of this value file|dir|all');
        if(!is_null($gotoPath) && !is_string($gotoPath)) throw new \InvalidArgumentException('$gotoPath should be a string');
        if(!is_null($offset) && !is_integer($offset)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($limit) && !is_integer($limit)) throw new \InvalidArgumentException('$offset should be an integer');
        if(!is_null($sortOrder) && !in_array($sortOrder, array('asc','desc'))) throw new \InvalidArgumentException('$sortOrder parameter should be one of this value asc|desc');
        if(!is_null($sortBy) && !is_array($sortBy)) throw new \InvalidArgumentException('$sortBy should be an array');

        if(!is_null($additional) && !is_array($additional)) throw new \InvalidArgumentException('$additional should be an array');

        if(!is_null($sortBy)) {
            foreach($sortBy as $value) {
                if(!is_string($value) && !in_array($sortBy, array('name','user','group','mtime','atime','ctime','crtime','posix'))) throw new \InvalidArgumentException('$sortBy parameter should contain this value name|user|group|mtime|atime|ctime|crtime|posix');
            }
        }
        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('real_path','size','owner','time','perm','mount_point_type','type'))) throw new \InvalidArgumentException('$additional parameter should contain this value real_path|size|owner|time|perm|mount_point_type|type');
            }
        }

        $params = array();
        $params['folder_path'] = $path;
        if(isset($offset)) $params['offset'] = $offset;
        if(isset($limit)) $params['limit'] = $limit;
        if(isset($pattern)) $params['pattern'] = $pattern;
        if(isset($filetype)) $params['filetype'] = $filetype;
        if(isset($gotoPath)) $params['goto_path'] = $gotoPath;
        if(isset($sortOrder)) $params['sort_order'] = $sortOrder;
        if(isset($sortBy)) $params['sort_by'] = implode(',',$sortBy);
        if(isset($additional)) $params['additional'] = implode(',',$additional);

        return $this->request('FileStation/file_share.cgi', 'SYNO.FileStation.List', 1, 'list', $params);
    }

    /**
     * Get file info
     *
     * @param string $path Path to list
     * @param array $additional (Optionnal) Additionnal information in real_path|size|owner|time|perm|mount_point_type|type
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function getFileInfo($path, $additional=null) {
        if(!is_string($path)) throw new \InvalidArgumentException('$path should be a string');
        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('real_path','size','owner','time','perm','mount_point_type','type'))) throw new \InvalidArgumentException('$additional parameter should contain this value real_path|size|owner|time|perm|mount_point_type|type');
            }
        }
    
        $params = array();
        $params['path'] = $path;
        if(isset($additional)) $params['additional'] = implode(',',$additional);

        return $this->request('FileStation/file_share.cgi', 'SYNO.FileStation.List', 1, 'getinfo', $params);
    }

    /**
     * Upload file- Not working!!!! - 408 error code...
     *
     * IMPROVE....
     *
     * @param string $destFolderPath Target folder of the file
     * @param boolean $createParents Create parents folder if not exist
     * @param string $filePath Local file to upload
     * @param boolean $overwrite (Optionnal - Default null) null : Set error if file exists, true : overwrite file, false : skip upload if file exist
     * @param \DateTime $mtime (Optionnal) Set the modification time
     * @param \DateTime $crtime (Optionnal) Set the creation time
     * @param \DateTime $atime (Optionnal) Set the access time
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function uploadFile($destFolderPath, $createParents, $filePath, $overwrite=null, $mtime=null, $crtime=null, $atime=null) {
	if(!is_string($destFolderPath)) throw new \InvalidArgumentException('$destFolderPath should be a string');
	if(!is_string($filePath)) throw new \InvalidArgumentException('$filePath should be a string');
        if(!is_bool($createParents)) throw new \InvalidArgumentException('$createParents should be a boolean');
        if(!is_null($overwrite) && !is_bool($overwrite)) throw new \InvalidArgumentException('$overwrite should be a boolean');
        if(!is_null($mtime) && !($mtime instanceof \DateTime)) throw new \InvalidArgumentException('$mtime should be a \DateTime');
        if(!is_null($crtime) && !($crtime instanceof \DateTime)) throw new \InvalidArgumentException('$crtime should be a \DateTime');
        if(!is_null($atime) && !($atime instanceof \DateTime)) throw new \InvalidArgumentException('$atime should be a \DateTime');


        $additionalParams['dest_folder_path'] = $destFolderPath;
        if($createParents) $additionalParams['create_parents'] = 'true';
        if(!$createParents) $additionalParams['create_parents'] = 'false';
        if(!is_null($overwrite) && $createParents) $additionalParams['overwrite'] = 'true';
        if(!is_null($overwrite) && !$createParents) $additionalParams['overwrite'] = 'false';
        if(!is_null($mtime)) $additionalParams['mtime'] = $mtime->getTimestamp();
        if(!is_null($crtime)) $additionalParams['crtime'] = $crtime->getTimestamp();
        if(!is_null($atime)) $additionalParams['atime'] = $atime->getTimestamp();
//        $additionalParams['file'] = $file;

        $cgiPath = 'FileStation/api_upload.cgi';
        $apiName = 'SYNO.FileStation.Upload';
        $version=1;
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
        if(!$this->isLoggedIn()) $this->login();
 
        // Set "mandatory" parameters list
        $params = array(
            'api'=>$apiName,
            'version'=>$version,
            'method'=>'upload',
            );
        if($this->isLoggedIn()) $params['_sid'] = $this->getSessionId();

        // Add additionnal parameters
        if($additionalParams !== null) {
            $params = $params + $additionalParams;
        }

        // Set request
        $queryString = 'webapi/'.$cgiPath;
        $request = new FormRequest('POST', $queryString, $this->getBaseUrl());
        $request->addFields($params);
        $uploadedFile = new FormUpload($filePath);
        $uploadedFile->setContentType('application/octet-stream');
        $request->setField('file', $uploadedFile);
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

    /**
     * Upload file using FTP - Same host default port
     *
     * @param string $destFolderPath Target folder of the file
     * @param string $filePath Local file to upload
     *
     * @return boolean Upload success
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function uploadFileUsingFtp($destFolderPath, $filePath) {
	if(!is_string($destFolderPath)) throw new \InvalidArgumentException('$destFolderPath should be a string');
	if(!is_string($filePath)) throw new \InvalidArgumentException('$filePath should be a string');

        $url_array = parse_url($this->getBaseUrl());
        // Mise en place d'une connexion basique
        $conn_id = ftp_connect($url_array['host']);

        // Identification avec un nom d'utilisateur et un mot de passe
        $login_result = ftp_login($conn_id, $this->getUser(), $this->getPassword());

        // Charge un fichier
        $file_result = ftp_put($conn_id, $destFolderPath.'/'.basename($filePath), $filePath, FTP_ASCII);

        // Fermeture de la connexion
        ftp_close($conn_id);

        return $file_result;
    }

    /**
     * Download file - Not working (Not a json response :/)
     *
     * @param string $path Path to download
     * @param string $mode (Optionnal - default download) Download system in open|download
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function downloadFile($path, $mode='download') {
        if(!is_string($path)) throw new \InvalidArgumentException('$path should be a string');
	if(!is_string($mode) && !in_array($mode, array('open','download'))) throw new \InvalidArgumentException('$mode parameter should contain this value open|download');
    
        $params = array();
        $params['path'] = $path;
        $params['mode'] = $mode;

        return $this->request('FileStation/file_download.cgi', 'SYNO.FileStation.Download', 1, 'download', $params);
    }

    /**
     * Create a folder
     *
     * @param string $name Folder name to create
     * @param string $path Path to create folder in
     * @param boolean $forceParent (Optionnal - default false) Create parents folder if not exist
     * @param array $additional (Optionnal) Additionnal information in real_path|size|owner|time|perm|type
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function createFolder($name, $path, $forceParent=null, $additional=null) {
        if(!is_string($name)) throw new \InvalidArgumentException('$name should be a string');
        if(!is_string($path)) throw new \InvalidArgumentException('$path should be a string');
        if(!is_null($forceParent) && !is_bool($forceParent)) throw new \InvalidArgumentException('$forceParent should be a boolean');

        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('real_path','size','owner','time','perm','type'))) throw new \InvalidArgumentException('$additional parameter should contain this value real_path|size|owner|time|perm|type');
            }
        }

        $params = array();
        $params['name'] = $name;
        $params['folder_path'] = $path;
        if($forceParent) $additionalParams['force_parent'] = 'true';
        if(!$forceParent) $additionalParams['force_parent'] = 'false';
        if(isset($additional)) $params['additional'] = implode(',',$additional);

        return $this->request('FileStation/file_crtfdr.cgi', 'SYNO.FileStation.CreateFolder', 1, 'create', $params);
    }

    /**
     * Rename folder
     *
     * @param string $name Folder name to create
     * @param string $path Path to create folder in
     * @param array $additional (Optionnal) Additionnal information in real_path|size|owner|time|perm|type
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function renameFolder($name, $path, $additional=null) {
        if(!is_string($name)) throw new \InvalidArgumentException('$name should be a string');
        if(!is_string($path)) throw new \InvalidArgumentException('$path should be a string');

        if(!is_null($additional)) {
            foreach($additional as $value) {
                if(!is_string($value) && !in_array($value, array('real_path','size','owner','time','perm','type'))) throw new \InvalidArgumentException('$additional parameter should contain this value real_path|size|owner|time|perm|type');
            }
        }

        $params = array();
        $params['name'] = $name;
        $params['path'] = $path;
        if(isset($additional)) $params['additional'] = implode(',',$additional);

        return $this->request('FileStation/file_rename.cgi', 'SYNO.FileStation.Rename', 1, 'rename', $params);
    }

    /**
     * Rename folder
     *
     * @param string $srcPath Source folder
     * @param string $destPath Destination folder
     * @param boolean $move (Optionnal - default false) Case null/false : copy, true : move
     * @param boolean $overwrite (Optionnal) Case null : error code, true : overwrite, false : skipped if exists
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhSynologyException In case synology api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function copyOrMoveFile($srcPath, $destPath, $move=null, $overwrite=null) {
        if(!is_string($srcPath)) throw new \InvalidArgumentException('$srcPath should be a string');
        if(!is_string($destPath)) throw new \InvalidArgumentException('$destPath should be a string');
        if(!is_null($overwrite) && !is_bool($overwrite)) throw new \InvalidArgumentException('$overwrite should be a boolean');
        if(!is_null($move) && !is_bool($move)) throw new \InvalidArgumentException('$move should be a boolean');

        $params = array();
        $params['path'] = $srcPath;
        $params['dest_folder_path'] = $destPath;
        if($move) $params['remove_src'] = 'true';
        if(!$move) $params['remove_src'] = 'false';
        if($overwrite) $params['overwrite'] = 'true';
        if(!$overwrite) $params['overwrite'] = 'false';

        return $this->request('FileStation/file_MVCP.cgi', 'SYNO.FileStation.CopyMove', 1, 'start', $params);
    }
}
