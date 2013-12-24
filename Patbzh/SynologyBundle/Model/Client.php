<?php
namespace Patbzh\SynologyBundle\Model;

use Buzz\Message\Request;
use Buzz\Message\Response;
use Patbzh\SynologyBundle\Exception\PatbzhSynologyException;

class Client
{
    /**********************
     Attributes definition
    **********************/ 
    private $httpClient;
    private $baseUrl;

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

    /**
     * Generic request manager to betaseries API
     *
     * @param string $queryString Query string of the request
     * @param string $method Http method of the request
     * @param array $params Array containing param list
     *
     * @return array Response of the request ("json_decoded")
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     */
    protected function request($queryString, $method, $params=null) {
        $queryStringComplement = '';
        if($params !== null) {
            $queryStringComplement = '?'.http_build_query($params);
        }
        $request = new Request($method, $queryString.$queryStringComplement, self::BETA_SERIES_BASE_URL);
        $request->setHeaders(array(
            'Accept'=>'text/json',
            'X-BetaSeries-Version'=>$this->getApiVersion(),
            'X-BetaSeries-Key'=>$this->getApiKey(),
            'X-BetaSeries-Token'=>$this->getOauthUserToken(),
            'User-agent'=>$this->getUserAgent(),
            ));
	$response = new Response();

        $this->httpClient->send($request, $response);
        $parsedResponse = json_decode($response->getContent(), true);
        if($response->getStatusCode()==400) {
            throw new PatbzhBetaseriesException($parsedResponse['errors'][0]['text'].' ('.$parsedResponse['errors'][0]['code'].')', $parsedResponse['errors'][0]['code']);
        }

        return $parsedResponse;
    }

    public function test() {
        //$this->request('shows/display', 'GET', array('id'=>1));
        //return $this->request('members/auth', 'POST', array('login'=>'patbzh', 'password'=>md5('rohp9178'),));
        return $this->request('members/search', 'GET', array('login'=>'patbzh',));
    }

    /**********************
     Comment part: http://www.betaseries.com/api/methodes/comments
    **********************/ 

    /**
     * UNTESTED - Send a comment
     *
     * @param string $type Element type in list: episode|show|member|movie
     * @param integer $id Element id
     * @param string $text Comment
     * @param integer $in_reply_to (Optionnal) Initial comment id in case of reply
     *
     * @return array...
     *
     * @throws PatbzhBetaseriesException In case betaseries api sends an error response
     * @throws \\InvalidArgumentException
     */
    public function sendComment($type, $id, $text, $in_reply_to = null) {
        // Parameters validation
        if(!in_array($type, array('episode','show','member','movie'))) throw new \InvalidArgumentException('$type parameter should be one of this value episode|show|member|movie');
        if(!is_integer($id)) throw new \InvalidArgumentException('$id should be an integer');
        if(!is_string($text)) throw new \InvalidArgumentException('$text should be a string');
        if(!is_null($in_reply_to) && !is_integer($in_reply_to)) throw new \InvalidArgumentException('$in_reply_to should be an integer');

        // Setting parameters
        $params = array(
            'type'=>$type,
            'id'=>$id,
            'text'=>$text,
            );
        if(isset($in_reply_to)) $params['in_reply_to'] = $in_reply_to;

        // Making Betaseries request
        return $this->request('comments/comment', 'POST', $params);
    }
}
