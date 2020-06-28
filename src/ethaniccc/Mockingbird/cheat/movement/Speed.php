<?php

namespace ethaniccc\Mockingbird\cheat\movement;

use ethaniccc\Mockingbird\Mockingbird;
use ethaniccc\Mockingbird\cheat\Cheat;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\utils\TextFormat;

class Speed extends Cheat{

    private const MAX_ONGROUND = 3 / 10;
    private const MAX_INAIR = 1 / 2;
    private const SPEED_MULTIPLIER = 4 / 3;
    private $suspicionLevel = [];

    public function __construct(Mockingbird $plugin, string $cheatName, string $cheatType, bool $enabled = true){
        parent::__construct($plugin, $cheatName, $cheatType, $enabled);
    }

    public function onMove(PlayerMoveEvent $event) : void{

        $player = $event->getPlayer();
        $name = $player->getName();

        $from = $event->getFrom();
        $to = $event->getTo();

        $distX = ($to->x - $from->x);
        $distZ = ($to->z - $from->z);
        if($distX == 0 && $distZ == 0){
            return;
        } elseif($distX === 0 && $distZ !== 0){
            $distance = abs($distZ);
        } elseif($distZ === 0 && $distX !== 0){
            $distance = abs($distX);
        } else {
            // Let's say we have a right triangle and we need to find the slope
            // of the triangle - basiclly the Pythagorean Theorem.
            $distanceSquared = abs(($distX * $distX) + ($distZ * $distZ));
            $distance = sqrt($distanceSquared);
            // Sometimes this distance spikes due to lag around 2x the original speed.
            // Keep in mind to ignore those values.
        }
        $distance = round($distance, 2);
        if($player->isOnGround()){
            $expectedDistance = self::MAX_ONGROUND;
            if($player->getEffect(1) !== null){
                $expectedDistance *= self::SPEED_MULTIPLIER * $player->getEffect(1)->getEffectLevel();
            }
            $expectedDistance = round($expectedDistance, 2);
            if($distance > $expectedDistance){
                if($distance >= $expectedDistance * 1.15 && $distance <= $expectedDistance * 2.05){
                    //$this->getServer()->broadcastMessage("Check was cancelled due to a detected spike.");
                    return;
                }
                if(!isset($this->suspicionLevel[$name])) $this->suspicionLevel[$name] = 0;
                $this->suspicionLevel[$name] += 1;
                if($this->suspicionLevel[$name] >= 5){
                    $this->addViolation($name);
                    $data = [
                        "Distance" => $distance,
                        "Expected Distance" => $expectedDistance,
                        "Ping" => $player->getPing()
                    ];
                    $this->notifyStaff($name, $this->getName(), $data);
                    $this->suspicionLevel[$name] = 0;
                }
            } else {
                if(!isset($this->suspicionLevel[$name])) $this->suspicionLevel[$name] = 0;
                $this->suspicionLevel[$name] *= 0.75;
            }
        } else {
            $expectedDistance = self::MAX_INAIR;
            if($player->getEffect(1) !== null){
                $expectedDistance *= self::SPEED_MULTIPLIER * $player->getEffect(1)->getEffectLevel();
            }
            $expectedDistance = round($expectedDistance, 2);
            if($distance > $expectedDistance){
                if($distance >= $expectedDistance * 1.15 && $distance <= $expectedDistance * 2.05){
                    //$this->getServer()->broadcastMessage("Check was cancelled due to a detected spike.");
                    return;
                }
                if(!isset($this->suspicionLevel[$name])) $this->suspicionLevel[$name] = 0;
                $this->suspicionLevel[$name] += 1;
                if($this->suspicionLevel[$name] >= 5){
                    $this->addViolation($name);
                    $data = [
                        "VL" => $this->getCurrentViolations($name),
                        "Ping" => $player->getPing()
                    ];
                    $this->notifyStaff($name, $this->getName(), $data);
                    $this->suspicionLevel[$name] = 0;
                }
            } else {
                if(!isset($this->suspicionLevel[$name])) $this->suspicionLevel[$name] = 0;
                $this->suspicionLevel[$name] *= 0.75;
            }
        }
    }

}