<?php

namespace indigerd\oauth2\authfilter\filter;

use indigerd\oauth2\authfilter\Module;
use Yii;
use yii\base\Action;
use yii\base\ActionFilter;
use yii\helpers\Inflector;
use yii\web\ForbiddenHttpException;

class AuthFilter extends ActionFilter
{
    public $scopesCallBack;

    /**
     * @param array $tokenInfo
     * @return indigerd\oauth2\authfilter\identity\AuthServerIdentity
     */
    public function authenticate(array $tokenInfo)
    {
        $user = Yii::$app->getUser();
        $user->identityClass = 'indigerd\oauth2\authfilter\identity\AuthServerIdentity';
        $identity = $user->loginByAccessToken($tokenInfo['access_token'], $tokenInfo);
        return $identity;
    }

    /**
     * @return \yii\base\Module|null
     */
    private function getAuthFileterModule()
    {
        /** @var Module $server */
        return \Yii::$app->getModule(
            isset(\Yii::$app->params['authFileterModuleName'])
                ? \Yii::$app->params['authFilterModuleName']
                : 'authfilter'
        );
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action)
    {
        /** @var Action $action */
        $server = $this->getAuthFileterModule();

        $tokenInfo = $server->validateRequest(\Yii::$app->request);
        $this->authenticate($tokenInfo);
        if (!is_callable($this->scopesCallBack)) {
            $this->scopesCallBack = [$this, 'validateScopes'];
        }
        if (call_user_func($this->scopesCallBack, $action, $tokenInfo)) {
            return true;
        } else {
            throw new ForbiddenHttpException;
        }
    }

    /**
     * @param string $namespace
     * @param string $controller
     * @param string $action
     * @return string
     */
    public function getScope(string $namespace, string $controller, string $action = ''): string
    {
        $divider = '.';
        return trim(implode($divider, [$namespace, $controller, $action]), $divider);
    }

    /**
     * @param Action $action
     * @param array $tokenInfo
     * @return bool
     */
    public function validateScopes(Action $action, array $tokenInfo)
    {
        if (empty($tokenInfo['scopes']) || !is_array($tokenInfo['scopes'])) {
            return false;
        }
        $controllerId = Inflector::pluralize($action->controller->id);
        $actionId = (string)$action->id;
        $server = $this->getAuthFileterModule();
        $namespace = (string)$server->namespace;

        foreach ($tokenInfo['scopes'] as $scope => $scopeDetails) {
            if ($scope === $this->getScope($namespace, $controllerId, '')) {
                return true;
            }

            if ($scope === $this->getScope($namespace, $controllerId, $actionId)) {
                return true;
            }
        }

        return false;
    }
}