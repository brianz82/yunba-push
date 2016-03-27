<?php

namespace Homer\Push\Yunba;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Yunba's message-pushing service
 *
 * Reference: http://yunba.io/docs2/restful_Quick_Start/
 */
class Service
{
    /**
     * base url for pushing message
     * @var string
     */
    const PUSH_URL = 'http://rest.yunba.io:8080';

    const RESPONSE_PHRASES = [
        '0' => '正常',
        '1' => '参数错误',     // invalid parameters
        '2' => '内部服务错误',  // server internal error
        '3' => '应用不存在',    // no such app
        '4' => '超时',         // timeout
        '5' => '未知的别名',    // no such alias
    ];

    /**
     * http client
     * @var \GuzzleHttp\ClientInterface
     */
    private $client;

    /**
     * app key
     * @var string
     */
    private $appKey;

    /**
     * secret key
     * @var string
     */
    private $secretKey;

    /**
     * @var array
     */
    private $options;

    /**
     * @param string $appKey           app key (from Yunba portal)
     * @param string $secretKey        secret key (from Yunba portal)
     * @param array $options           options
     *                                 - url          yunba's url
     *                                 - alias_size   maximum #. of aliases in a batch
     * @param ClientInterface $client  http client
     */
    public function __construct($appKey, $secretKey, array $options = null, ClientInterface $client = null)
    {
        $this->appKey    = $appKey;
        $this->secretKey = $secretKey;
        $this->client    = $client ?: $this->createDefaultHttpClient();

        $this->options = array_merge([
            'url'        => self::PUSH_URL,
            'alias_size' => 800,
        ], $options ?: []);
    }

    /**
     * push message to a topic, and those who subscribed that topic will receive
     * the message (it follows the publish/subscribe pattern).
     *
     * Note: The message given will be pushed as a whole(even if it's an array). Call
     * this method multiple times if you want to push multiple messages.
     *
     * @param string $topic     topic to push message to
     * @param mixed $message    message to push
     * @param array $options    options for Yunba's 'publish' or 'publish_async' method
     *
     * @return string                 message id
     */
    public function pushToTopic($topic, $message, array $options = [])
    {
        return $this->doPushToTopic($topic, $message, $options, false);
    }

    /**
     * same as pushToTopic, but in async manner
     *
     * @param string $topic     topic to push message to
     * @param mixed $message    message to push.
     * @param array $options    options for Yunba's 'publish' or 'publish_async' method
     *
     * @return string           message id
     */
    public function pushToTopicAsync($topic, $message, array $options = [])
    {
        return $this->doPushToTopic($topic, $message, $options, true);
    }

    // internal implementation to push message to topic
    private function doPushToTopic($topic, $message, array $options = [], $async = true)
    {
        $this->ensureSubscriberAndMessageNoneEmpty($topic, $message);

        return $this->pushUnicastMessage($async ? $this->buildPublishAsyncPayload($topic, $message, $options)
                                                : $this->buildPublishPayload($topic, $message, $options));
    }


    /**
     * check status of messages that pushed via topic in async manner
     *
     * @param string $topic
     * @param string $messageId  id of the message being checked
     *
     * @return string            status of the message,
     */
    public function checkAsyncTopicMessage($topic, $messageId)
    {
        if (empty($topic)) {
            throw new \InvalidArgumentException('topic不能为空');
        }

        if (empty($messageId)) {
            throw new \InvalidArgumentException('messageId不能为空');
        }

        return $this->sendMessageCheckRequest($this->buildMessageCheckPayload($messageId, $topic));
    }

    /**
     * push message to alias or aliases
     *
     * @param string|array $alias  alias to push message to
     * @param mixed $message       message to push
     * @param array $options       options for Yunba's 'publish_to_alias' or 'publish_to_alias_batch' method
     *
     * @return string|array
     */
    public function pushToAlias($alias, $message, array $options = [])
    {
        $this->ensureSubscriberAndMessageNoneEmpty($alias, $message);

        if (is_string($alias)) {  // uni-cast
            return $this->pushUnicastMessage($this->buildPublishToAliasPayload($alias, $message, $options));
        }

        // multi-cast
        return $this->pushToAliases($alias, $message, $options);
    }

    /**
     * push message to aliases (multi-cast)
     *
     * @param array $aliases       aliases to push message to
     * @param mixed $message       message to push
     * @param array $options       options for Yunba's 'publish_to_alias' or 'publish_to_alias_batch' method
     *
     * @return string|array
     */
    public function pushToAliases(array $aliases, $message, array $options = []) {
        // Yunba advises that no more than 1000 aliases in a batch is preferred
        $maxAliasesPerBatch = $this->getMaxAliasesPerBatch();
        $numberOfBatch = intval(ceil(count($aliases) / $maxAliasesPerBatch));
        $offset = 0;

        $response = []; // the response of this multi-cast

        // send message to aliases in batch
        for ($batch = 0; $batch < $numberOfBatch; $batch++) {
            // find subscribers for each batch
            $aliasesPerBatch = array_slice($aliases, $offset, $maxAliasesPerBatch);
            $offset += $maxAliasesPerBatch;

            $batchResponse = $this->pushMulticastMessage($this->buildPublishToAliasBatchPayload($aliasesPerBatch, $message, $options));
            if (!empty($batchResponse)) {
                $response = $response + $batchResponse;
            }

            if (count($aliasesPerBatch) < $maxAliasesPerBatch) {
                break;
            }
        }

        return $response;
    }

    private function pushMulticastMessage(array $payload)
    {
        $response = $this->sendPayload($payload);
        if ($response) {
            return $this->parseMulticastResponse($response);
        }

        throw new \Exception('推送服务异常');
    }

    /**
     * push uni-cast message
     *
     * @param array $payload  payload to be pushed
     *
     * @return string         message id
     *
     * @throws \Exception
     */
    private function pushUnicastMessage(array $payload)
    {
        $response = $this->sendPayload($payload);

        if ($response) {
            return $this->parseUnicastResponse($response);
        }

        throw new \Exception('推送服务异常');
    }

    /**
     * send request for checking message
     *
     * @param array $payload  payload to be pushed
     *
     * @return string         message id
     *
     * @throws \Exception
     */
    private function sendMessageCheckRequest(array $payload)
    {
        $response = $this->sendPayload($payload);

        if ($response) {
            return $this->parseMessageCheckResponse($response);
        }

        throw new \Exception('推送服务异常');
    }

    private function sendPayload(array $payload)
    {
        return $this->client->request('POST', $this->getPushUrl(), [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($this->polishPayload($payload)),  // polish the payload first
        ]);
    }

    // add appKey and secretKey to the payload
    private function polishPayload(array $payload)
    {
        $payload['appkey'] = $this->appKey;
        $payload['seckey'] = $this->secretKey;

        return $payload;
    }

    /**
     * parse response
     *
     * @param Response $response
     * @return string              message id
     * @throws \Exception
     *
     */
    private function parseUnicastResponse(Response $response)
    {
        $this->ensureNormalResponse($response);

        $body = (string)$response->getBody();    // stored for exception handling
        $response = self::safeJsonDecode($body);
        if (!$response) {
            throw new \Exception(sprintf('推送服务异常,返回值异常: %s', $body), -1);
        }

        if ($response->status != 0) {
            throw new \Exception(array_get(self::RESPONSE_PHRASES, $response->status, sprintf('推送异常(%s)', $body)),
                                 $response->status);
        }

        return (string)$response->messageId;  // force the message id as string
    }

    private function parseMulticastResponse(Response $response)
    {
        $this->ensureNormalResponse($response);

        $body = (string)$response->getBody();    // stored for exception handling
        $response = self::safeJsonDecode($body, true);
        if (!$response) {
            throw new \Exception(sprintf('推送服务异常,返回值异常: %s', $body), -1);
        }

        if ($response['status'] != 0) {
            throw new \Exception(array_get(self::RESPONSE_PHRASES, $response['status'], sprintf('推送异常(%s)', $body)),
                $response['status']);
        }

        // results is an associative array, refer to Yunba's documentation for detail
        return $response['results'];
    }

    private function parseMessageCheckResponse(Response $response)
    {
        $this->ensureNormalResponse($response);

        $body = (string)$response->getBody();    // stored for exception handling
        $response = self::safeJsonDecode($body);
        if (!$response) {
            throw new \Exception(sprintf('服务异常,返回值异常: %s', $body), -1);
        }

        return $response->status;
    }


    // ensure that the response is normal (status code should be 200, etc)
    private function ensureNormalResponse(Response $response)
    {
        if ($response->getStatusCode() != 200) {
            throw new \Exception(sprintf('推送服务异常(Code: %s)', $response->getStatusCode()));
        }

    }

    // ensure that both subscriber and message involved during message pushing
    // must not be empty
    private function ensureSubscriberAndMessageNoneEmpty($subscriber, $message)
    {
        if (empty($subscriber)) {
            throw new \InvalidArgumentException('推送目标不能为空');
        }

        if (empty($message)) {
            throw new \InvalidArgumentException('推送信息不能为空');
        }
    }

    // build payload for 'publish' method
    private function buildPublishPayload($topic, $message, array $options = null)
    {
        return array_filter([
            'method' => 'publish',
            'topic'  => $topic,
            'msg'    => $message,
            'opts'   => $options,
        ]);
    }

    //build payload for 'publish_async' method
    private function buildPublishAsyncPayload($topic, $message, array $options = null)
    {
        return array_filter([
            'method' => 'publish_async',
            'topic'  => $topic,
            'msg'    => $message,
            'opts'   => $options,
        ]);
    }

    //build payload for 'publish_to_alias' method
    private function buildPublishToAliasPayload($alias, $message, array $options = null)
    {
        return array_filter([
            'method'  => 'publish_to_alias',
            'alias'   => $alias,
            'msg'     => $message,
            'opts'    => $options,
        ]);
    }

    //build payload for 'publish_to_alias_batch' method
    private function buildPublishToAliasBatchPayload($aliases, $message, array $options = null)
    {
        return array_filter([
            'method'   => 'publish_to_alias_batch',
            'aliases'  => (array)$aliases,
            'msg'      => $message,
            'opts'     => $options,
        ]);
    }

    //build payload for 'publish_async' method
    private function buildMessageCheckPayload($messageId, $topic = null)
    {
        return [
            'method'  => 'publish_check',
            'topic'   => $topic,
            'msg'     => $messageId,
        ];
    }

    private function getPushUrl()
    {
        return array_get($this->options, 'url');
    }

    private function getMaxAliasesPerBatch()
    {
        return array_get($this->options, 'alias_size');
    }

    /**
     * create default http client
     *
     * @return Client
     */
    private function createDefaultHttpClient()
    {
        return new Client();
    }

    /**
     * decode json in a safe way. by 'safe', it means that error will be silently ignored
     * and false is returned in that case.
     *
     * @param string $json the <i>json</i> string being decoded.
     * @param bool $assoc  when <b>TRUE</b>, returned objects will be converted into associative arrays.
     *
     * @return mixed       the decoded value or false on error.
     */
    private static function safeJsonDecode($json, $assoc = false)
    {
        $decoded = json_decode($json, $assoc);
        if ($decoded === null  && json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $decoded;
    }
}