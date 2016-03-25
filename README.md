# Yunba Push Service
Use Restful APIs exposed by Yunba to implement message pushing service.

This service provides only the most basic features, and designated to be integrated into other project as infrastructure. 

```php
use Homer\Push\Yunba\Service as YunbaPushService;

$service = new YunbaPushService('appkey', 'secretkey');
// - or the full version
// $service = new YunbaPushService('appkey', 'secretkey', $optionsOfService, $instanceOfClient);

// push to topic
$service->pushToTopic('topic', 'message', $options);
$service->pushToTopicAsync('topic', 'message', $options);

// push to alias
$service->pushToAlias($alias_or_aliases, 'message', $options);

// check async topic message status
$service->checkAsyncTopicMessage('topic', 'message_id');
```
