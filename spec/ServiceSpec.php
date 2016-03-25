<?php

namespace spec\Homer\Push\Yunba;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Spec for testing Yunba message pushing
 */
class ServiceSpec extends ObjectBehavior
{
    function let(ClientInterface $client)
    {
        $this->beAnInstanceOf(\Homer\Push\Yunba\Service::class, [
            'appKey',     // app key from Yunba
            'secretKey',  // secret key from Yunba
            null,
            $client,      // http client
        ]);
    }

    function it_pushes_message_to_topic(ClientInterface $client)
    {
        $client->request('POST', 'http://rest.yunba.io:8080', [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'method' => 'publish',
                    'topic'  => 'topic',
                    'msg'    => 'message',
                    'appkey' => 'appKey',
                    'seckey' => 'secretKey',
                ]),
            ]
        )->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/topic_push.json')));

        $this->pushToTopic('topic', 'message')->shouldBe('559963630116810752');
    }

    function it_pushes_message_to_topic_async(ClientInterface $client)
    {
        $client->request('POST', 'http://rest.yunba.io:8080', [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'method' => 'publish_async',
                    'topic'  => 'topic',
                    'msg'    => 'message',
                    'appkey' => 'appKey',
                    'seckey' => 'secretKey',
                ]),
            ]
        )->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/topic_push_async.json')));

        $this->pushToTopicAsync('topic', 'message')->shouldBe('560036485839986688');
    }

    function it_unicast(ClientInterface $client)
    {
        $client->request('POST', 'http://rest.yunba.io:8080', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'method' => 'publish_to_alias',
                'alias'  => 'alias',
                'msg'    => 'message',
                'appkey' => 'appKey',
                'seckey' => 'secretKey',
            ]),
        ])->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/alias_unicast.json')));

        $this->pushToAlias('alias', 'message')->shouldBe('559969719608676352');
    }

    function it_multicasts(ClientInterface $client)
    {
        $client->request('POST', 'http://rest.yunba.io:8080', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode([
                'method'  => 'publish_to_alias_batch',
                'aliases' => ['alias1', 'alias2'],
                'msg'     => 'message',
                'appkey'  => 'appKey',
                'seckey'  => 'secretKey',
            ]),
        ])->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/alias_multicast.json')));

        $results = $this->pushToAlias(['alias1', 'alias2'], 'message');
        $results->shouldHaveCount(2);

        $results->shouldHaveKeyWithContainedValue('alias1', [
            'status' => 5,
            'alias'  => '56f23eda4407a3cd028ad668-alias1',
        ]);

        $results->shouldHaveKeyWithContainedValue('alias2', [
            'status' => 0,
            'messageId' => '559971761798516736',
        ]);
    }

    function it_checks_async_topic_message_status(ClientInterface $client)
    {
        $client->request('POST', 'http://rest.yunba.io:8080', [
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => json_encode([
                    'method' => 'publish_check',
                    'topic'  => 'topic',
                    'msg'    => '559963630116810752',
                    'appkey' => 'appKey',
                    'seckey' => 'secretKey',
                ]),
            ]
        )->willReturn(new Response(200, [], file_get_contents(__DIR__ . '/data/message_check_done.json')));

        $this->checkAsyncTopicMessage('topic', '559963630116810752')->shouldBe(0);
    }


    /**
     * @inheritdoc
     */
    public function getMatchers()
    {
        return [
            'haveKeyWithContainedValue' => function ($subject, $key, array $needles) {
                if (!array_key_exists($key, $subject)) {
                    return false;
                }

                $haystack = $subject[$key];
                foreach ($needles as $needleKey => $value) {
                    if (!array_key_exists($needleKey, $haystack)) {
                        return false;
                    }

                    if ($haystack[$needleKey] != $value) {
                        return false;
                    }
                }

                return true;
            }
        ];
    }
}
