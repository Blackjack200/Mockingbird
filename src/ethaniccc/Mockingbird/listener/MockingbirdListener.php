<?php

namespace ethaniccc\Mockingbird\listener;

use ethaniccc\Mockingbird\Mockingbird;
use ethaniccc\Mockingbird\packet\PlayerAuthInputPacket;
use ethaniccc\Mockingbird\user\User;
use ethaniccc\Mockingbird\user\UserManager;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\Server;

class MockingbirdListener implements Listener {
	/** @var LoginPacket[] */
	private array $packets = [];

	public function __construct() {
		Server::getInstance()->getPluginManager()->registerEvents($this, Mockingbird::getInstance());
	}

	public function onPlayerJoin(PlayerJoinEvent $event) : void {
		$pk = $this->packets[spl_object_hash($event->getPlayer()->getNetworkSession())];
		unset($this->packets[spl_object_hash($event->getPlayer()->getNetworkSession())]);
		$user = new User($event->getPlayer());
		UserManager::getInstance()->register($user);
		$user->inboundProcessor->process($pk, $user);
		foreach ($user->detections as $check) {
			if ($check->enabled) {
				$check->handleReceive($pk, $user);
			}
		}
	}

	/** @priority HIGHEST */
	public function onPacket(DataPacketReceiveEvent $event) : void {
		$packet = $event->getPacket();
		$player = $event->getOrigin()->getPlayer();
		if ($packet instanceof LoginPacket) {
			$this->packets[spl_object_hash($event->getOrigin())] = $packet;
		}
		if ($player !== null) {
			if ($packet instanceof PlayerAuthInputPacket) {
				$event->cancel();
			}


			$user = UserManager::getInstance()->get($player);
			if ($user !== null) {
				if ($user->debugChannel === 'clientpk' && !in_array(get_class($packet), [PlayerAuthInputPacket::class, NetworkStackLatencyPacket::class])) {
					$user->sendMessage(get_class($packet));
				}
				if ($user->isPacketLogged) {
					$user->packetLog[] = $packet;
				}
				$user->inboundProcessor->process($packet, $user);
				foreach ($user->detections as $check) {
					if ($check->enabled) {
						$check->handleReceive($packet, $user);
					}
				}
			}
		}
	}

	/** @priority HIGHEST */
	public function onPacketSend(DataPacketSendEvent $event) : void {
		$packets = $event->getPackets();
		foreach ($packets as $packet) {
			foreach ($event->getTargets() as $target) {
				$player = $target->getPlayer();
				if ($player === null) {
					continue;
				}
				$user = UserManager::getInstance()->get($player);
				if ($packet instanceof StartGamePacket) {
					$packet->playerMovementSettings = new PlayerMovementSettings(PlayerMovementType::SERVER_AUTHORITATIVE_V2_REWIND, 20, false);
				}
				if ($user !== null) {
					$user->outboundProcessor->process($packet, $user);
				}
			}
		}
	}

	// I hate it here
	public function onTransaction(InventoryTransactionEvent $event) : void {
		$user = UserManager::getInstance()->get($event->getTransaction()->getSource());
		if ($user !== null) {
			foreach ($user->detections as $detection) {
				if ($detection->enabled) {
					$detection->handleEvent($event, $user);
				}
			}
		}
	}

	public function onLeave(PlayerQuitEvent $event) : void {
		$player = $event->getPlayer();
		UserManager::getInstance()->unregister($player);
	}

}