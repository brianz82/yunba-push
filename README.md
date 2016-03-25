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

## API
### construct
`__construct($appKey, $secretKey, array $options = null, ClientInterface $client = null)`

* ``$appKey``  app key (from Yunba portal)
* ``$secretKey``  secret key (from Yunba portal)
* ``$options``    some configurations, including:
	* ``url``      (optional)yunba's url, default to 'http://rest.yunba.io:8080'
	* ``alias_size``   (optional) maximum #. of aliases in a batch. Yunba suggests that no more than 1000 aliases in a batch is preferred. default to ``800``.
* $client  http client

### push message to topic

You can push a topic, and those who subscribed that topic will receive the message (it follows the publish/subscribe pattern). You can do that in either asynchrounous or synchrounous manner.

`pushToTopic($topic, $message, array $options = [])`
`pushToTopicAsync($topic, $message, array $options = [])`

* ``$topic``  topic to push message to
* ``$message``    message to push
* ``$options``    options for Yunba's 'publish' or 'publish_async' method
* ``return string``  message id

 Note: The message given will be pushed as a whole, even if it's an array. Call this method multiple times if you want to push multiple messages. For example, ``pushToTopic($topic, ['message1', 'message2'])`` won't push two messages to the topic, but one message with its content as '["message1","message2"]', which is just a plain JSON string.


### push message to alias

This is how Yunba sends uni- and multi-cast messages(while pushing message to topic sends broadcast).

``pushToAlias($alias, $message, array $options = [])``

* ``$alias``  alias to push message to
* ``$message`` message to push
* ``$options``  options for Yunba's 'publish_to_alias' or 'publish_to_alias_batch' method
* ``return string|array`` depending on whether you're pushing message to one or more aliases

### check async topic message

Check status of messages that pushed via topic in async manner.

* ``$topic``  the topic
* ``$messageId``  id of the message being checked
* ``return string``  status of the message. Refer to Yunba's doc for detail.

### Reference
[Yunba's Restful Quick Start](http://yunba.io/docs2/restful_Quick_Start)
