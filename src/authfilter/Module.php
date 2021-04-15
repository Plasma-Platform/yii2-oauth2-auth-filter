<?php

namespace indigerd\oauth2\authfilter;

use indigerd\oauth2\authfilter\client\ClientInterface;
use indigerd\oauth2\authfilter\components\TestHelper;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use yii\di\Instance;
use yii\web\HttpException;
use yii\web\Request;
use yii\web\Response;

class Module extends \yii\base\Module
{
    /**
     * @var string $authServerUrl Url of oauth2 authentication service
     */
    public $authServerUrl;

    /**
     * @var string $clientId Client id of your application
     */
    public $clientId;

    /**
     * @var string $clientSecret Client secret of your application
     */
    public $clientSecret;

    /**
     * @var string $tokenKey Name of get|post variable used for access token
     */
    public $tokenKey = 'access_token';

    /**
     * @var string $httpClientClass Class used for interaction with oauth2 auth service via http
     */
    public $httpClientClass = 'indigerd\oauth2\authfilter\client\Curl';

    /**
     * @var bool $testMode Used for tests for not to send requests to auth service
     */
    public $testMode = false;

    public $tokenInfoEndpoint = 'oauth/tokeninfo';

    public $tokenIssueEndpoint = 'oauth/token';

    /** @var  ClientInterface $httpClient */
    protected $httpClient;


    /** @var yii\caching\MemCache $cache */
    public $cache;

    /** @var int $cacheTtl */
    public $cacheTtl = 60;


    /**
     * @param ClientInterface $httpClient
     * @return $this
     */
    public function setHttpClient(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    /**
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();
        if (empty($this->authServerUrl)) {
            throw new InvalidConfigException('Auth server url not configured');
        }
        $this->setHttpClient(new $this->httpClientClass);

        if ($this->cache !== false && $this->cache !== null) {
            try {
                $this->cache = Instance::ensure($this->cache, 'yii\caching\CacheInterface');
            } catch (InvalidConfigException $e) {
                Yii::warning('Unable to use cache for URL manager: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param Request $request
     * @return string
     * @throws HttpException
     */
    public function determineAccessToken(Request $request)
    {
        if ($request->getHeaders()->get('Authorization') !== null) {
            $accessToken = $request->getHeaders()->get('Authorization');
        } else {
            $accessToken = $request->isGet
                ? $request->get($this->tokenKey)
                : $request->post($this->tokenKey);
        }

        if (empty($accessToken)) {
            throw new HttpException(
                400,
                'The request is missing a required parameter, includes an invalid parameter value, includes a parameter more than once, or is otherwise malformed. Check the "access token" parameter.',
                400
            );
        }

        return $accessToken;
    }

    /**
     * @param Response $response
     * @return array
     * @throws HttpException
     */
    public function validateAuthServerResponce(Response $response)
    {
        $tokenInfo = json_decode($response->content, true);
        if (null === $tokenInfo) {
            throw new HttpException(500, null, 500);
        }
        if ($response->statusCode != 200) {
            $error = !empty($tokenInfo['error'])
                ? $tokenInfo['error']
                : 'Invalid access token';
            throw new HttpException($response->statusCode, $error, $response->statusCode);
        }

        return $tokenInfo;
    }


    /**
     * @param Request $request
     * @return array
     * @throws HttpException
     */
    public function validateRequest(Request $request)
    {
        if ($this->testMode) {
            return json_decode(TestHelper::getTokenInfo()->content, true);
        }
        $accessToken = $this->determineAccessToken($request);

        if ($this->cache instanceof CacheInterface) {
            $tokenFromCache = $this->cache->get($accessToken);
            if (!empty($tokenFromCache)) {
                return json_decode($tokenFromCache, true);
            }
        }
        try {
            $url = rtrim($this->authServerUrl, '/') . '/' . ltrim($this->tokenInfoEndpoint, '/');
            $response = $this->httpClient->sendRequest(
                'GET',
                $url,
                [],
                [
                    'Authorization' => $accessToken,
                    'Accept' => 'application/json'
                ]
            );
        } catch (\Exception $e) {
            throw new HttpException(503, 'Authentication server not available', 503);
        }

        $afterValidate = $this->validateAuthServerResponce($response);

        if ($this->cache instanceof CacheInterface) {
            $this->cache->set($accessToken, json_encode($afterValidate), $this->cacheTtl);
        }
        return $afterValidate;
    }

    /**
     * @param string $username
     * @param string $password
     * @param string $scope
     * @param bool $rawResponse
     * @return array|string
     * @throws HttpException
     * @throws InvalidConfigException
     */
    public function requestAccessToken($username, $password, $scope = '', $rawResponse = false, $grantType = 'password')
    {
        if ($this->testMode) {
            return TestHelper::getTokenInfo($rawResponse);
        }
        if (empty($this->clientId)) {
            throw new InvalidConfigException('Client ID not configured');
        }
        if (empty($this->clientSecret)) {
            throw new InvalidConfigException('Client secret not configured');
        }
        try {
            $url = rtrim($this->authServerUrl, '/') . '/' . ltrim($this->tokenIssueEndpoint, '/');
            $response = $this->httpClient->sendRequest(
                'POST',
                $url,
                [
                    'grant_type' => $grantType,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => $scope,
                    'username' => $username,
                    'password' => $password
                ],
                [
                    'Accept' => 'application/json'
                ]
            );
        } catch (\Exception $e) {
            throw new HttpException(503, 'Authentication server not available');
        }
        return $rawResponse ? $response : json_decode($response->content, true);
    }

    /**
     * @param string $refresh_token
     * @param string $scope
     * @param bool $rawResponse
     * @return array|string
     * @throws HttpException
     * @throws InvalidConfigException
     */
    public function requestAccessByRefreshToken($refresh_token, $scope = '', $rawResponse = false)
    {
        if ($this->testMode) {
            return TestHelper::getTokenInfo();
        }
        if (empty($this->clientId)) {
            throw new InvalidConfigException('Client ID not configured');
        }
        if (empty($this->clientSecret)) {
            throw new InvalidConfigException('Client secret not configured');
        }
        try {
            $url = rtrim($this->authServerUrl, '/') . '/' . ltrim($this->tokenIssueEndpoint, '/');
            $response = $this->httpClient->sendRequest(
                'POST',
                $url,
                [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refresh_token,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => $scope,
                ],
                [
                    'Accept' => 'application/json'
                ]
            );
        } catch (\Exception $e) {
            throw new HttpException(503, 'Authentication server not available');
        }
        return $rawResponse ? $response : json_decode($response->content, true);
    }
}
