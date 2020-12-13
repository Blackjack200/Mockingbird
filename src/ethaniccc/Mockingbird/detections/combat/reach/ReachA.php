<?php

namespace ethaniccc\Mockingbird\detections\combat\reach;

use ethaniccc\Mockingbird\detections\Detection;
use ethaniccc\Mockingbird\user\User;
use ethaniccc\Mockingbird\utils\boundingbox\AABB;
use ethaniccc\Mockingbird\utils\SizedList;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;

class ReachA extends Detection{

    private $appendingMove = false;

    public function __construct(string $name, ?array $settings){
        parent::__construct($name, $settings);
        $this->vlThreshold = 20;
    }

    public function handle(DataPacket $packet, User $user) : void{
        if($packet instanceof InventoryTransactionPacket && $user->win10 && !$user->player->isCreative() && !$this->appendingMove && $packet->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY && $packet->trData->actionType === InventoryTransactionPacket::USE_ITEM_ON_ENTITY_ACTION_ATTACK){
            if($user->tickData->targetLocationHistory->getLocations()->full()){
                // wait for the next PlayerAuthInputPacket from the client
                $this->appendingMove = true;
            }
        } elseif($packet instanceof PlayerAuthInputPacket && $this->appendingMove){
            $locations = $user->tickData->targetLocationHistory->getLocationsRelativeToTime($user->tickData->currentTick - floor($user->transactionLatency / 50), 2);
            // 40 is the max location history size in the tick data
            $distances = new SizedList(40);
            foreach($locations as $location){
                $AABB = AABB::fromPosition($location)->expand(0.1, 0.1, 0.1);
                // add the distance from the "to" position to the AABB
                $distances->add($AABB->distanceFromVector($packet->getPosition()));
                // add the distance from the "from" position to the AABB
                $distances->add($AABB->distanceFromVector($user->moveData->lastLocation->add(0, 1.62, 0)));
            }
            $distance = $distances->minOrElse(-1);
            if($distance !== -1){
                if($distance > $this->getSetting("max_reach")){
                    $this->preVL += 1.5;
                    // sometimes the preVL can reach to 4, I put 4.1 here as I haven't seen the preVL (on localhost testing) go above 4
                    if($this->preVL >= 4.1){
                        $this->preVL = min($this->preVL, 9);
                        $this->fail($user, "dist=$distance");
                    }
                } else {
                    $this->reward($user, 0.9995);
                    $this->preVL = max($this->preVL - 0.75, 0);
                }
            }
            if($this->isDebug($user)){
                $user->sendMessage("dist=$distance buff={$this->preVL}");
            }
            $this->appendingMove = false;
        }
    }

}