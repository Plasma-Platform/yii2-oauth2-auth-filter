<?php

namespace indigerd\oauth2\authfilter\components;

use yii\web\Response;
use Yii;

class TestHelper
{
    public static function getTokenInfo($rawResponse = false)
    {
        $tokenInfo = [
            'owner_id'     => '1',
            'owner_type'   => 'user',
            'access_token' => 'token',
            'client_id'    => '1',
            'scopes'       => ''
        ];
        if (Yii::$app->getRequest()->getHeaders()->get('X-Token-info') !== null) {
            $tokenInfo = array_merge($tokenInfo, json_decode(Yii::$app->getRequest()->getHeaders()->get('X-Token-info'), true));
        }
        if($rawResponse==false){
            return $tokenInfo;
        }
        $response = new Response();
        $response->setStatusCode(200);
        $response->content = json_encode($tokenInfo);
        return $response;
    }
}
