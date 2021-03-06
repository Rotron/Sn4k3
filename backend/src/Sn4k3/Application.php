<?php

namespace Sn4k3;

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use Sn4k3\Socket\WebSocket;
use Thruway\ClientSession;

class Application
{
    const CROSSBAR_WEBSOCKET_PORT = 7777;
    const CROSSBAR_WEBSOCKET_PATH = 'sn4k3';
    const CROSSBAR_WEBSOCKET_HOST = '127.0.0.1';

    const EVENT_INCOMING_ACTION = 'action';
    const EVENT_INCOMING_JOIN = 'join';
    const EVENT_OUTGOING_TICK = 'tick';

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var Game
     */
    private $game;

    /**
     * @var WebSocket
     */
    private $webSocket;

    public function __construct()
    {
        $host = getenv('CROSSBAR_HOST') ?: self::CROSSBAR_WEBSOCKET_HOST;

        // If host is not an IP address, get the IP address instead of the hostname.
        // Else, the client tries to resolve the name by DNS and it may not work with Docker.
        if (!preg_match('~^\d+(?:\.\d+){3}$~', $host)) {
            $host = gethostbyname($host);
        }

        $this->loop = Factory::create();
        $this->game = new Game($this->loop);
        $this->webSocket = new WebSocket($host, self::CROSSBAR_WEBSOCKET_PORT, self::CROSSBAR_WEBSOCKET_PATH, $this->loop);

        $this->listenIncomingMessages();
        $this->broadcastTick();
        $this->listenForNewPlayers();

        $this->webSocket->start();
        $this->game->run();
        $this->loop->run();
    }

    /**
     * Handles the "INCOMING_ACTION" event from browser.
     */
    private function listenIncomingMessages()
    {
        $promise = $this->webSocket->promiseSession();

        $promise->then(function (ClientSession $session) {
            $session->subscribe(self::EVENT_INCOMING_ACTION, function ($_, $args) {
                if (!isset($args->playerName)) {
                    return;
                }

                $player = $this->game->getPlayerByName($args->playerName);

                $event = new Event($player, $args->direction, $args->pressed);

                $this->game->addEvent($event);
            });
        });
    }

    /**
     * Handles the "JOIN" event received from browser.
     */
    private function listenForNewPlayers()
    {
        $this->webSocket->promiseSession()->then(function (ClientSession $session) {
            $session->subscribe(self::EVENT_INCOMING_JOIN, function($_, $args) {
                if (!isset($args->playerName) || strlen($args->playerName) < 3) {
                    return;
                }

                $this->game->initializePlayer($args->playerName);
            });
        });
    }

    /**
     * Sends the "TICK" event to the browser.
     */
    private function broadcastTick()
    {
        $this->game->on(Game::EVENT_TICK, function () {
            $data = Serializer::serializeGame($this->game);
            if ($session = $this->webSocket->getSession()) {
                $session->publish(self::EVENT_OUTGOING_TICK, null, $data);
            }
        });
    }
}
