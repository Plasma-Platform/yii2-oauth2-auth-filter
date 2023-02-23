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

    public $tokenRevokeEndpoint = 'oauth/revoke';

    /** @var  ClientInterface $httpClient */
    protected $httpClient;


    /** @var CacheInterface $cache */
    public $cache;

    /** @var int $cacheTtl */
    public $cacheTtl = 60;

    /** @var string $prefixCacheAccessToken */
    public $prefixCacheAccessToken = 'access_token_';

    /** @var string $namespace */
    public $namespace = '';

    /**
     * @param ClientInterface $httpClient
     *
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
    }

    /**
     * @param Request $request
     *
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
     *
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
            throw new HttpException(
                $response->statusCode,
                $error,
                $response->statusCode
            );
        }

        return $tokenInfo;
    }


    /**
     * @param Request $request
     *
     * @return array
     * @throws HttpException
     */
    public function validateRequest(Request $request)
    {
        if ($this->testMode) {
            return json_decode(TestHelper::getTokenInfo(true)->content, true);
        }
        $this->initCacheInstance();
        $accessToken = $this->determineAccessToken($request);
        $tokenFromCache = $this->getCacheValue($accessToken);
        if (!empty($tokenFromCache)) {
            return json_decode($tokenFromCache, true);
        }
        try {
            $url = rtrim($this->authServerUrl, '/') . '/' . ltrim(
                    $this->tokenInfoEndpoint,
                    '/'
                );
            $response = $this->httpClient->sendRequest(
                'GET',
                $url,
                [],
                [
                    'Authorization' => $accessToken,
                    'Accept' => 'application/json'
                ]
            );
        } catch (\Throwable $e) {
            throw new HttpException(
                503,
                'Authentication server not available',
                503
            );
        }

        $afterValidate = $this->validateAuthServerResponce($response);
        $this->setCacheValue($accessToken, json_encode($afterValidate), $this->cacheTtl);
        return $afterValidate;
    }

    private function initCacheInstance()
    {
        if ($this->cache !== false && $this->cache !== null && !($this->cache instanceof CacheInterface)) {
            try {
                $this->cache = Instance::ensure($this->cache, 'yii\caching\CacheInterface');
            } catch (InvalidConfigException $e) {
                Yii::warning('Unable to use cache for Auth filter: ' . $e->getMessage());
            }
        }
    }

    /**
     * @param $cacheKey
     * @return false|mixed|null
     */
    private function getCacheValue($cacheKey)
    {
        if ($this->cache instanceof CacheInterface) {
            return $this->cache->get($cacheKey);
        }
        return null;
    }

    /**
     * @param $key
     * @param $value
     * @param $ttl
     */
    private function setCacheValue($key, $value, $ttl)
    {
        if ($this->cache instanceof CacheInterface && (int)$ttl > 0) {
            $this->cache->set($key, $value, $ttl);
        }
    }

    /**
     * @param $key
     * @param $value
     * @param $ttl
     */
    private function deleteCacheValue($key)
    {
        if ($this->cache instanceof CacheInterface) {
            $this->cache->delete($key);
        }
    }

    /**
     * @param $username
     * @param $password
     * @param string $scope
     * @param false $rawResponse
     * @param string $grantType
     * @param false $useCache
     * @return array|mixed|string[]|Response
     * @throws HttpException
     * @throws InvalidConfigException
     */
    public function requestAccessToken(
        $username,
        $password,
        $scope = '',
        $rawResponse = false,
        $grantType = 'password',
        $useCache = false
    ) {
        if ($this->testMode) {
            return TestHelper::getTokenInfo($rawResponse);
        }
        if (empty($this->clientId)) {
            throw new InvalidConfigException('Client ID not configured');
        }
        if (empty($this->clientSecret)) {
            throw new InvalidConfigException('Client secret not configured');
        }
        $this->initCacheInstance();

        $requestParams = [
            'grant_type' => $grantType,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => $scope,
            'username' => $username,
            'password' => $password
        ];

        $cacheKey = $this->prefixCacheAccessToken . sha1(json_encode($requestParams));
        if ($useCache) {
            $cacheValue = $this->getCacheValue($cacheKey);
            if (!empty($cacheValue)) {
                return $rawResponse
                    ? (new Response(['content' => $cacheValue]))->setStatusCode(200)
                    : json_decode($cacheValue, true);
            }
        }

        try {
            $url = rtrim($this->authServerUrl, '/') . '/' . ltrim(
                    $this->tokenIssueEndpoint,
                    '/'
                );
            $response = $this->httpClient->sendRequest(
                'POST',
                $url,
                $requestParams,
                [
                    'Accept' => 'application/json'
                ]
            );
        } catch (\Throwable $e) {
            throw new HttpException(503, 'Authentication server not available');
        }

        if ($useCache) {
            $tokenData = json_decode($response->content, true);
            $cacheTtl = (int)($tokenData['expires_in'] ?? 0);
            $cacheTtl = $cacheTtl - 60;
            $this->setCacheValue($cacheKey, $response->content, $cacheTtl);
        }

        return $rawResponse ? $response : json_decode($response->content, true);
    }

    /**
     * @param string $refresh_token
     * @param string $scope
     * @param bool $rawResponse
     *
     * @return array|string
     * @throws HttpException
     * @throws InvalidConfigException
     */
    public function requestAccessByRefreshToken(
        $refresh_token,
        $scope = '',
        $rawResponse = false
    ) {
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
            $url = rtrim($this->authServerUrl, '/') . '/' . ltrim(
                    $this->tokenIssueEndpoint,
                    '/'
                );
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
        } catch (\Throwable $e) {
            throw new HttpException(503, 'Authentication server not available');
        }
        return $rawResponse ? $response : json_decode($response->content, true);
    }

    /**
     * @param string $access_token
     * @return void
     * @throws HttpException
     * @throws InvalidConfigException
     */
    public function revokeAccessToken(
        string $access_token
    ) {
        if ($this->testMode) {
            return;
        }
        if (empty($this->clientId)) {
            throw new InvalidConfigException('Client ID not configured');
        }
        if (empty($this->clientSecret)) {
            throw new InvalidConfigException('Client secret not configured');
        }
        try {
            $url = rtrim($this->authServerUrl, '/') . '/' . ltrim(
                    $this->tokenRevokeEndpoint,
                    '/'
                );
            $this->httpClient->sendRequest(
                'DELETE',
                $url,
                [],
                [
                    'Accept' => 'application/json',
                    'Authorization' => $access_token
                ]
            );
            $this->deleteCacheValue($access_token);
        } catch (\Throwable $e) {
            throw new HttpException(503, $e->getMessage());
        }
    }
}
