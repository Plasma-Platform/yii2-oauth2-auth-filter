# yii2-oauth2-auth-filter

[![version][version-badge]][CHANGELOG]

Yii2 module to check validity of oauth2 token and scope access in microservices



Installation
------------

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

Either run

```
composer require indigerd/yii2-oauth2-auth-filter "1.2.8"
```

or add

```
"indigerd/yii2-oauth2-auth-filter": "1.2.8"
```

to the require section of your `composer.json` file.


Usage
-----
```
'authfilter' => [
    'class' => 'indigerd\oauth2\authfilter\Module',
    'authServerUrl' => Yii::getAlias('@serviceAuthUrl'),
    'clientId' => getenv('AUTH_CLIENT_ID'),
    'clientSecret' => getenv('AUTH_CLIENT_SECRET'),
    'cache' => 'cache',
    'cacheTtl' => (int)getenv('AUTH_CACHE_TTL'),
    'namespace' => 'webhooks',
],
```


[CHANGELOG]: ./CHANGELOG.md
[version-badge]: https://img.shields.io/badge/version-1.2.8-blue.svg
