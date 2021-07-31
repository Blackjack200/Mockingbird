<?php

namespace ethaniccc\Mockingbird\utils;

use ethaniccc\Mockingbird\user\User;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;

class PacketUtils{

    public static function playerAuthToMovePlayer(PlayerAuthInputPacket $packet, User $user) : MovePlayerPacket{
        $movePk = new MovePlayerPacket();
        $movePk->entityRuntimeId = $user->player->getId();
        $movePk->mode = MovePlayerPacket::MODE_NORMAL;
        $movePk->position = $packet->getPosition();
        $movePk->pitch = $packet->getPitch();
        $movePk->yaw = $packet->getYaw();
        $movePk->headYaw = $packet->getHeadYaw();
        $movePk->onGround = true;
	    $protocol = GlobalItemTypeDictionary::getDictionaryProtocol($user->player->getNetworkSession()->getProtocolId());
	    $movePk->encode(PacketSerializer::encoder(new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary($protocol))));
	    return $movePk;
    }

}