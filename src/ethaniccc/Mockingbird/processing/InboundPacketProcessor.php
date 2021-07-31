<?php

namespace ethaniccc\Mockingbird\processing;

use ethaniccc\Mockingbird\Mockingbird;
use ethaniccc\Mockingbird\packet\PlayerAuthInputPacket;
use ethaniccc\Mockingbird\user\User;
use ethaniccc\Mockingbird\utils\boundingbox\AABB;
use ethaniccc\Mockingbird\utils\boundingbox\Ray;
use ethaniccc\Mockingbird\utils\MathUtils;
use ethaniccc\Mockingbird\utils\PacketUtils;
use Exception;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\Cobweb;
use pocketmine\block\Ladder;
use pocketmine\block\Liquid;
use pocketmine\block\Transparent;
use pocketmine\block\UnknownBlock;
use pocketmine\block\Vine;
use pocketmine\block\Water;
use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Location;
use pocketmine\item\ItemIds;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\convert\GlobalItemTypeDictionary;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\PlayerActionPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\SetLocalPlayerAsInitializedPacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\network\PacketHandlingException;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class InboundPacketProcessor extends Processor {

	/** @var Vector3[] */
	private $postPendingTeleports = [];

	public function __construct() {
		$this->lastTime = microtime(true);
	}

	public function process(DataPacket $packet, User $user) : void {
		switch ($packet->pid()) {
			case PlayerAuthInputPacket::NETWORK_ID:
				/** @var PlayerAuthInputPacket $packet */
				if (!$user->loggedIn) {
					return;
				}
				$location = Location::fromObject($packet->getPosition()->subtract(0, 1.62, 0), $user->player->getWorld(), $packet->getYaw(), $packet->getPitch());
				// $user->locationHistory->addLocation($location);
				$user->moveData->lastLocation = $user->moveData->location;
				$user->moveData->location = $location;
				$user->moveData->lastYaw = $user->moveData->yaw;
				$user->moveData->lastPitch = $user->moveData->pitch;
				$user->moveData->yaw = fmod($location->yaw, 360);
				$user->moveData->pitch = fmod($location->pitch, 360);
				$hasMoved = $location->distanceSquared($user->moveData->lastLocation) > 0.0 || abs($user->moveData->pitch - $user->moveData->lastPitch) > 9E-6 || abs($user->moveData->yaw !== $user->moveData->lastYaw) > 9E-6;
				$user->moveData->isMoving = $hasMoved;
				unset($user->moveData->AABB);
				$user->moveData->AABB = AABB::from($user);
				$movePacket = PacketUtils::playerAuthToMovePlayer($packet, $user);
				if ($user->moveData->moveDelta->lengthSquared() > 0.0009) {
					if (count($user->outboundProcessor->pendingTeleports) !== 0) {
						foreach ($user->outboundProcessor->pendingTeleports as $teleport) {
							if ($user->moveData->location->distance($teleport) <= 2) {
								$user->timeSinceTeleport = 0;
								break;
							}
						}
					}
				}
				++$user->timeSinceTeleport;
				if ($user->timeSinceTeleport > 0 && $hasMoved) {
					$user->moveData->lastMoveDelta = $user->moveData->moveDelta;
					$user->moveData->moveDelta = $user->moveData->location->subtract($user->moveData->lastLocation->x, $user->moveData->lastLocation->y, $user->moveData->lastLocation->z)->asVector3();
					$user->moveData->lastYawDelta = $user->moveData->yawDelta;
					$user->moveData->lastPitchDelta = $user->moveData->pitchDelta;
					$user->moveData->yawDelta = abs($user->moveData->lastYaw - $user->moveData->yaw);
					$user->moveData->pitchDelta = abs($user->moveData->lastPitch - $user->moveData->pitch);
					$user->moveData->rotated = $user->moveData->yawDelta > 0 || $user->moveData->pitchDelta > 0;
					if ($user->moveData->rotated && $user->debugChannel === 'rotation') {
						$user->sendMessage('yawDelta=' . $user->moveData->yawDelta . ' pitchDelta=' . $user->moveData->pitchDelta);
					}
				} else {
					$user->moveData->lastMoveDelta = $user->moveData->moveDelta;
					$user->moveData->moveDelta = $user->zeroVector;
					$user->moveData->lastYawDelta = $user->moveData->yawDelta;
					$user->moveData->lastPitchDelta = $user->moveData->pitchDelta;
					$user->moveData->yawDelta = 0.0;
					$user->moveData->pitchDelta = 0.0;
					$user->moveData->rotated = false;
				}
				if ($user->mouseRecorder !== null && $user->mouseRecorder->isRunning && $user->moveData->yawDelta > 0) {
					$user->mouseRecorder->handleRotation($user->moveData->yawDelta, $user->moveData->pitchDelta);
					if ($user->mouseRecorder->getAdmin()->debugChannel === 'mouse-recorder') {
						$user->mouseRecorder->getAdmin()->sendMessage('The mouse recording is ' . TextFormat::BOLD . TextFormat::GOLD . round($user->mouseRecorder->getPercentage(), 4) . '%' . TextFormat::RESET . ' done!');
					}
					if ($user->mouseRecorder->isFinished()) {
						$user->mouseRecorder->finish($user);
					}
				}
				++$user->timeSinceDamage;
				++$user->timeSinceAttack;
				if ($user->player->isOnline()) {
					++$user->timeSinceJoin;
				} else {
					$user->timeSinceJoin = 0;
				}
				++$user->timeSinceMotion;
				if (!$user->player->isFlying()) {
					++$user->timeSinceStoppedFlight;
				} else {
					$user->timeSinceStoppedFlight = 0;
				}
				if ($user->isGliding || $user->player->isSpectator() || $user->player->isImmobile()) {
					$user->timeSinceStoppedGlide = 0;
				} else {
					++$user->timeSinceStoppedGlide;
				}
				// 27 is the hardcoded effect ID for slow falling (I think...?)
				if ($user->player->getEffects()->has(VanillaEffects::LEVITATION()) !== null) {
					$user->moveData->levitationTicks = 0;
				} else {
					++$user->moveData->levitationTicks;
				}
				if ($location->y > -39.5) {
					++$user->moveData->ticksSinceInVoid;
				} else {
					$user->moveData->ticksSinceInVoid = 0;
				}
				// 0.03 ^ 2
				if ($user->moveData->moveDelta->lengthSquared() > 0.0009) {
					$speed = $user->player->getAttributeMap()->get(5)->getValue();
					if ($user->debugChannel === 'speed') {
						$user->sendMessage('speed=' . $speed);
					}
					$liquids = 0;
					$cobweb = 0;
					$climb = 0;
					foreach ($user->player->getWorld()->getCollisionBlocks($user->player->getBoundingBox()->offsetCopy(0, $user->player->getEyeHeight(), 0)) as $block) {
						if ($block instanceof Liquid) {
							$liquids++;
						} elseif ($block instanceof Cobweb) {
							$cobweb++;
						} elseif ($block instanceof Ladder || $block instanceof Vine) {
							$climb++;
						}
					}
					if ($liquids > 0) {
						$user->moveData->liquidTicks = 0;
					} else {
						++$user->moveData->liquidTicks;
					}
					if ($cobweb > 0) {
						$user->moveData->cobwebTicks = 0;
					} else {
						++$user->moveData->cobwebTicks;
					}
					if ($climb > 0) {
						$user->moveData->climbableTicks = 0;
					} else {
						++$user->moveData->climbableTicks;
					}
					// debug for block AABB - (VERY RESOURCE INTENSIVE)
					if ($user->debugChannel === 'block-bb') {
						$expandedAABB = $user->moveData->AABB->clone()->expand(4, 4, 4);
						$distance = PHP_INT_MAX;
						$target = null;
						$ray = Ray::fromUser($user);
						$minX = (int) floor($expandedAABB->minX - 1);
						$minY = (int) floor($expandedAABB->minY - 1);
						$minZ = (int) floor($expandedAABB->minZ - 1);
						$maxX = (int) floor($expandedAABB->maxX + 1);
						$maxY = (int) floor($expandedAABB->maxY + 1);
						$maxZ = (int) floor($expandedAABB->maxZ + 1);
						for ($z = $minZ; $z <= $maxZ; ++$z) {
							for ($x = $minX; $x <= $maxX; ++$x) {
								for ($y = $minY; $y <= $maxY; ++$y) {
									$block = $user->player->getWorld()->getBlockAt($x, $y, $z);
									if ($block->getId() !== 0) {
										$AABB = AABB::fromBlock($block);
										if (($dist = $AABB->collidesRay($ray, 0, 7)) !== -69.0) {
											if ($dist < $distance) {
												$distance = $dist;
												$target = $block;
											}
										}
									}
								}
							}
						}
						if ($target instanceof Block) {
							$AABB = AABB::fromBlock($target);
							foreach ($AABB->getCornerVectors() as $cornerVector) {
								//$user->player->getWorld()->addParticle(new DustParticle($cornerVector, 0, 255, 255));
							}
						}
					}
				}
				// 0.03 ^ 2
				if ($user->moveData->moveDelta->lengthSquared() > 0.0009) {
					// should I be worried about performance here?
					$verticalBlocks = $user->player->getWorld()->getCollisionBlocks($user->moveData->AABB->expandedCopy(0.1, 0.2, 0.1));
					$horizontalBlocks = $user->player->getWorld()->getCollisionBlocks($user->moveData->AABB->expandedCopy(0.2, -0.1, 0.2));
					$ghostCollisions = 0;
					$user->moveData->ghostCollisions = [];
					$verticalAABB = $user->moveData->AABB->expandedCopy(0.1, 0.2, 0.1);
					foreach ($user->ghostBlocks as $block) {
						if (!$block->isTransparent() && AABB::fromBlock($block)->intersectsWith($verticalAABB, 0.0001)) {
							$ghostCollisions++;
							$user->moveData->ghostCollisions[] = $block;
							break;
						}
					}
					$user->moveData->onGround = count($verticalBlocks) !== 0 || $ghostCollisions > 0;
					if ($user->debugChannel === 'on-ground') {
						$user->sendMessage('onGround=' . var_export($user->moveData->onGround, true) . ' ghostCollisions=' . $ghostCollisions . ' pmmp=' . var_export($user->player->isOnGround(), true));
					}
					$user->moveData->verticalCollisions = $verticalBlocks;
					$user->moveData->horizontalCollisions = $horizontalBlocks;
					$user->moveData->isCollidedVertically = count($verticalBlocks) !== 0;
					$user->moveData->isCollidedHorizontally = count($horizontalBlocks) !== 0;
				}
				if ($user->moveData->onGround) {
					++$user->moveData->onGroundTicks;
					$user->moveData->offGroundTicks = 0;
					$user->moveData->lastOnGroundLocation = $location;
				} else {
					++$user->moveData->offGroundTicks;
					$user->moveData->onGroundTicks = 0;
				}
				if ($hasMoved) {
					$user->moveData->lastDirectionVector = $user->moveData->directionVector;
					try {
						$user->moveData->directionVector = MathUtils::directionVectorFromValues($user->moveData->yaw, $user->moveData->pitch);
					} catch (\ErrorException $e) {
						$user->moveData->directionVector = clone $user->zeroVector;
					}
				}
				$user->moveData->pressedKeys = [];
				if ($packet->getMoveVecZ() > 0) {
					$user->moveData->pressedKeys[] = 'W';
				} elseif ($packet->getMoveVecZ() < 0) {
					$user->moveData->pressedKeys[] = 'S';
				}
				if ($packet->getMoveVecX() > 0) {
					$user->moveData->pressedKeys[] = 'A';
				} elseif ($packet->getMoveVecX() < 0) {
					$user->moveData->pressedKeys[] = 'D';
				}
				// shouldHandle will be false if the player isn't near the teleport position
				if ($hasMoved) {
					// only handle if the move delta is greater than 0 so PlayerMoveEvent isn't spammed
					if ($user->debugChannel === 'onground') {
						$serverGround = $user->player->isOnGround() ? 'true' : 'false';
						$otherGround = $movePacket->onGround ? 'true' : 'false';
						$user->sendMessage('pmmp=' . $serverGround . ' mb=' . $otherGround);
					}
					$handler = $user->player->getNetworkSession()->getHandler();
					if ($handler !== null) {
						$handler->handleMovePlayer($movePacket);
					}
				}

				if ($packet->hasInputFlag(PlayerAuthInputFlags::START_SPRINTING)) {
					$user->isSprinting = true;

					$pk = new PlayerActionPacket;
					$pk->entityRuntimeId = $user->player->getId();
					$pk->action = PlayerActionPacket::ACTION_START_SPRINT;
					$pk->x = $location->x;
					$pk->y = $location->y;
					$pk->z = $location->z;
					$pk->face = $user->player->getHorizontalFacing();
					$handler = $user->player->getNetworkSession()->getHandler();
					if ($handler !== null) {
						$handler->handlePlayerAction($pk);
					}
				}
				if ($packet->hasInputFlag(PlayerAuthInputFlags::STOP_SPRINTING)) {
					$user->isSprinting = false;

					$pk = new PlayerActionPacket;
					$pk->entityRuntimeId = $user->player->getId();
					$pk->action = PlayerActionPacket::ACTION_STOP_SPRINT;
					$pk->x = $location->x;
					$pk->y = $location->y;
					$pk->z = $location->z;
					$pk->face = $user->player->getHorizontalFacing();
					$handler = $user->player->getNetworkSession()->getHandler();
					if ($handler !== null) {
						$handler->handlePlayerAction($pk);
					}
				}

				if ($packet->hasInputFlag(PlayerAuthInputFlags::START_SNEAKING)) {
					$user->isSneaking = true;

					$pk = new PlayerActionPacket;
					$pk->entityRuntimeId = $user->player->getId();
					$pk->action = PlayerActionPacket::ACTION_START_SNEAK;
					$pk->x = $location->x;
					$pk->y = $location->y;
					$pk->z = $location->z;
					$pk->face = $user->player->getHorizontalFacing();
					$handler = $user->player->getNetworkSession()->getHandler();
					if ($handler !== null) {
						$handler->handlePlayerAction($pk);
					}
				}
				if ($packet->hasInputFlag(PlayerAuthInputFlags::STOP_SNEAKING)) {
					$user->isSneaking = false;

					$pk = new PlayerActionPacket;
					$pk->entityRuntimeId = $user->player->getId();
					$pk->action = PlayerActionPacket::ACTION_STOP_SNEAK;
					$pk->x = $location->x;
					$pk->y = $location->y;
					$pk->z = $location->z;
					$pk->face = $user->player->getHorizontalFacing();
					$handler = $user->player->getNetworkSession()->getHandler();
					if ($handler !== null) {
						$handler->handlePlayerAction($pk);
					}
				}

				if ($packet->hasInputFlag(PlayerAuthInputFlags::START_JUMPING)) {
					$pk = new PlayerActionPacket;
					$pk->entityRuntimeId = $user->player->getId();
					$pk->action = PlayerActionPacket::ACTION_JUMP;
					$pk->x = $location->x;
					$pk->y = $location->y;
					$pk->z = $location->z;
					$pk->face = $user->player->getHorizontalFacing();
					$handler = $user->player->getNetworkSession()->getHandler();
					if ($handler !== null) {
						$handler->handlePlayerAction($pk);
					}
				}

				if ($packet->hasInputFlag(PlayerAuthInputFlags::START_GLIDING)) {
					//$user->player->setFlag(Player::DATA_FLAG_GLIDING, true);
					$user->isGliding = true;
				}
				if ($packet->hasInputFlag(PlayerAuthInputFlags::STOP_GLIDING)) {
					//$user->player->setGenericFlag(Player::DATA_FLAG_GLIDING, false);
					$user->isGliding = false;
				}

				if ($packet->hasInputFlag(PlayerAuthInputFlags::PERFORM_BLOCK_ACTIONS)) {
					foreach ($packet->getBlockActions() as $blockAction) {
						switch ($blockAction->actionType) {
							case PlayerActionPacket::ACTION_START_BREAK:
							case PlayerActionPacket::ACTION_ABORT_BREAK:
							case PlayerActionPacket::ACTION_CRACK_BREAK:
								$pk = new PlayerActionPacket;
								$pk->entityRuntimeId = $user->player->getId();
								$pk->action = $blockAction->actionType;
								$pk->x = $blockAction->x;
								$pk->y = $blockAction->y;
								$pk->z = $blockAction->z;
								$pk->face = $user->player->getHorizontalFacing();
								$handler = $user->player->getNetworkSession()->getHandler();
								if ($handler !== null) {
									$handler->handlePlayerAction($pk);
								}
						}
					}
				}

				if ($packet->hasInputFlag(PlayerAuthInputFlags::PERFORM_ITEM_INTERACTION)) {
					$pk = new InventoryTransactionPacket;
					$pk->requestId = $packet->getRequestId();
					$pk->requestChangedSlots = $packet->getRequestChangedSlots();
					$pk->trData = $packet->getTransactionData();
					$handler = $user->player->getNetworkSession()->getHandler();
					if ($handler !== null) {
						$handler->handleInventoryTransaction($pk);
					}
				}
				$user->tickProcessor->process($packet, $user);
				++$this->tickSpeed;
				// $user->testProcessor->process($packet, $user);
				break;
			case InventoryTransactionPacket::NETWORK_ID:
				/** @var InventoryTransactionPacket $packet */
				switch ($packet->trData->getTypeId()) {
					case InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY:
						switch ($packet->trData->getTypeId()) {
							case UseItemOnEntityTransactionData::ACTION_ATTACK:
								$user->hitData->attackPos = $packet->trData->getPlayerPos();
								$user->hitData->lastTargetEntity = $user->hitData->targetEntity;
								$user->hitData->targetEntity = $user->player->getWorld()->getEntity($packet->trData->getEntityRuntimeId());
								$user->hitData->inCooldown = Server::getInstance()->getTick() - $user->hitData->lastTick < 10;
								if (!$user->hitData->inCooldown) {
									$user->timeSinceAttack = 0;
									$user->hitData->lastTick = Server::getInstance()->getTick();
								}
								if ($user->hitData->targetEntity !== $user->hitData->lastTargetEntity) {
									$user->tickData->targetLocations = [];
									$user->outboundProcessor->pendingLocations = [];
								}
								break;
						}
						$this->handleClick($user);
						break;
					case InventoryTransactionPacket::TYPE_USE_ITEM:
						switch ($packet->trData->getActionType()) {
							case UseItemTransactionData::ACTION_CLICK_BLOCK:
								/** @var ItemStack $inHand */
								$inHand = $packet->trData->getItemInHand()->getItemStack();
								$clickedBlockPos = $packet->trData->getBlockPos();
								$blockClicked = $user->player->getWorld()->getBlock($clickedBlockPos, false, false);
								$block = BlockFactory::getInstance()->get($inHand->getId(), $inHand->getMeta());
								if ($inHand->getId() < 0) {
									// suck my...
									$block = new UnknownBlock($inHand->getId(), $inHand->getMeta());
								}
								$side = $clickedBlockPos->getSide($packet->trData->getFace());
								if ($block->canBePlaced() || $block instanceof UnknownBlock) {
									$placeable = true;
									$block->position($user->player->getWorld(), $side->x, $side->y, $side->z);
									$isGhostBlock = false;
									foreach ($user->ghostBlocks as $ghostBlock) {
										if ($ghostBlock->getPos()->distanceSquared($block->getPos()) === 0.0) {
											$isGhostBlock = true;
											break;
										}
									}
									if ($block->canBePlacedAt($blockClicked, ($packet->trData->getClickPos() ?? new Vector3(0, 0, 0)), $packet->trData->getFace(), true) && !$isGhostBlock) {
										$block->position($blockClicked->getPos()->getWorld(), $blockClicked->getPos()->x, $blockClicked->getPos()->y, $blockClicked->getPos()->z);
									} /* elseif($block->canBePlacedAt($blockClicked, ($packet->trData->clickPos ?? new Vector3(0, 0, 0)), $packet->trData->face, true) && $isGhostBlock){
                                        $user->sendMessage('ghost block placed on ghost block.');
                                    } */
									if ($block->isSolid()) {
										foreach ($block->getCollisionBoxes() as $BB) {
											if (count($user->player->getWorld()->getCollidingEntities($BB)) > 0) {
												$placeable = false; // an entity in a block
												break;
											}
										}
									}
									if ($placeable) {
										$user->placedBlocks[] = $block;
										$interactPos = $side->add($packet->trData->getClickPos());
										$distance = $interactPos->distance($user->moveData->location->add($user->isSneaking ? 1.54 : 1.62, 0, 0));
										if ($user->debugChannel === 'block-dist') {
											$user->sendMessage('dist=' . $distance);
										}
									}
								}
								if ($inHand->getId() === ItemIds::BUCKET && $inHand->getDamage() === 8) {
									$pos = $clickedBlockPos;
									$blockClicked = $user->player->getWorld()->getBlock($clickedBlockPos);
									// the block can't be replaced and the block relative to the face can also not be replaced
									// water-logging blocks by placing the water under the transparent block... idot stuff
									if (!$blockClicked->canBeReplaced() && !$user->player->getWorld()->getBlock($side)->canBeReplaced()) {
										$pos = $side;
									}
									$pk = new UpdateBlockPacket();
									$pk->x = $pos->x;
									$pk->y = $pos->y;
									$pk->z = $pos->z;
									$pk->blockRuntimeId = RuntimeBlockMapping::getInstance()->toRuntimeId(0 << 4, RuntimeBlockMapping::getMappingProtocol($user->player->getNetworkSession()->getProtocolId()));
									$pk->dataLayerId = UpdateBlockPacket::DATA_LAYER_LIQUID;
									foreach ($user->player->getWorld()->getPlayers() as $v) {
										$v->getNetworkSession()->sendDataPacket($pk);
									}
									$user->player->getNetworkSession()->sendDataPacket($pk);
								} elseif ($block instanceof Transparent && $user->player->getWorld()->getBlock($side, false, false) instanceof Water) {
									// reverse-waterlogging?
									$pk = new UpdateBlockPacket();
									$pos = $side;
									$pk->x = $pos->x;
									$pk->y = $pos->y;
									$pk->z = $pos->z;
									$pk->dataLayerId = UpdateBlockPacket::DATA_LAYER_LIQUID;
									$pk->blockRuntimeId = RuntimeBlockMapping::getInstance()->toRuntimeId(0 << 4, RuntimeBlockMapping::getMappingProtocol($user->player->getNetworkSession()->getProtocolId()));
									foreach ($user->player->getWorld()->getPlayers() as $v) {
										$v->getNetworkSession()->sendDataPacket($pk);
									}
									$user->player->getNetworkSession()->sendDataPacket($pk);
								}
								// TODO: Fix water-logging with doors.. what the actual fuck?
								// ^ at this rate I might just not fix to be honest.
								break;
						}
						break;
				}
				// $user->testProcessor->process($packet);
				break;
			case LevelSoundEventPacket::NETWORK_ID:
				/** @var LevelSoundEventPacket $packet */
				switch ($packet->sound) {
					case LevelSoundEventPacket::SOUND_ATTACK_NODAMAGE:
						$this->handleClick($user);
						break;
				}
				break;
			case NetworkStackLatencyPacket::NETWORK_ID:
				/** @var NetworkStackLatencyPacket $packet */
				if ($packet->timestamp === $user->latencyPacket->timestamp) {
					$user->responded = true;
					$user->transactionLatency = round((microtime(true) - $user->lastSentNetworkLatencyTime) * 1000, 0);
					if ($user->debugChannel === 'latency') {
						$user->sendMessage("pmmp={$user->player->getPing()} latency={$user->transactionLatency}");
					}
					/* $pk = new NetworkStackLatencyPacket();
					$pk->needResponse = true; $pk->timestamp = mt_rand(100000, 10000000) * 1000;
					$user->latencyPacket = $pk; */
					$user->latencyPacket->timestamp = mt_rand(1, 10000000) * 1000;
					$protocol = GlobalItemTypeDictionary::getDictionaryProtocol($user->player->getNetworkSession()->getProtocolId());
					$user->latencyPacket->encode(PacketSerializer::encoder(new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary($protocol))));
				} elseif ($packet->timestamp === $user->chunkResponsePacket->timestamp) {
					$user->hasReceivedChunks = true;
					if ($user->debugChannel === 'receive-chunk') {
						$user->sendMessage('received chunks');
					}
					$user->chunkResponsePacket->timestamp = mt_rand(10, 10000000) * 1000;
					$protocol = GlobalItemTypeDictionary::getDictionaryProtocol($user->player->getNetworkSession()->getProtocolId());
					$user->chunkResponsePacket->encode(PacketSerializer::encoder(new PacketSerializerContext(GlobalItemTypeDictionary::getInstance()->getDictionary($protocol))));
				} elseif (isset($user->outboundProcessor->pendingMotions[$packet->timestamp])) {
					$motion = $user->outboundProcessor->pendingMotions[$packet->timestamp];
					if ($user->debugChannel === 'get-motion') {
						$user->sendMessage('got ' . $packet->timestamp);
					}
					$user->timeSinceMotion = 0;
					$user->moveData->lastMotion = $motion;
					unset($user->outboundProcessor->pendingMotions[$packet->timestamp]);
				} elseif (isset($user->outboundProcessor->pendingLocations[$packet->timestamp])) {
					$location = $user->outboundProcessor->pendingLocations[$packet->timestamp];
					$user->tickData->targetLocations[$user->tickData->currentTick] = $location;
					$currentTick = $user->tickData->currentTick;
					$user->tickData->targetLocations = array_filter($user->tickData->targetLocations, function (int $tick) use ($currentTick) : bool {
						return $currentTick - $tick <= 4;
					}, ARRAY_FILTER_USE_KEY);
					if ($user->debugChannel === 'get-location') {
						$user->sendMessage('got ' . $packet->timestamp);
					}
					unset($user->outboundProcessor->pendingLocations[$packet->timestamp]);
				} elseif (isset($user->ghostBlocks[$packet->timestamp])) {
					$block = $user->ghostBlocks[$packet->timestamp];
					if ($user->debugChannel === 'ghost-block') {
						//$user->sendMessage('ghost block ' . $block->getId() . ' removed with (x=' . $block->getPos()->x . ' y=' . $block->getY() . ' z=' . $block->getZ() . ')');
					}
					unset($user->ghostBlocks[$packet->timestamp]);
				}
				// $user->testProcessor->process($packet);
				break;
			case LoginPacket::NETWORK_ID:
				/** @var LoginPacket $packet */
				try {
					[, $clientDataClaims,] = JwtUtils::parse($packet->clientDataJwt);
				} catch (JwtException $e) {
					throw PacketHandlingException::wrap($e);
				}
				$user->isDesktop = !in_array($clientDataClaims["DeviceOS"], [DeviceOS::AMAZON, DeviceOS::ANDROID, DeviceOS::IOS]);
				try {
					$data = $packet->chainDataJwt->chain;
					$parts = explode(".", $data['chain'][2]);
					$jwt = json_decode(base64_decode($parts[1]), true);
					$id = $jwt['extraData']['titleId'];
					$user->win10 = ($id === "896928775");
				} catch (Exception $e) {
				}
				break;
			case SetLocalPlayerAsInitializedPacket::NETWORK_ID:
				$user->loggedIn = true;
				if ($user->player->hasPermission('mockingbird.alerts') && Mockingbird::getInstance()->getConfig()->get('alerts_default')) {
					$user->alerts = true;
				}
				$user->player->getNetworkSession()->sendDataPacket($user->latencyPacket);
				$user->lastSentNetworkLatencyTime = microtime(true);
				$user->responded = false;
				break;
		}
    }

    private $clicks = [];
    private $lastTime;
    private $tickSpeed = 0;

    private function handleClick(User $user) : void{
        $currentTick = $user->tickData->currentTick;
        $this->clicks[] = $currentTick;
        $this->clicks = array_filter($this->clicks, function(int $t) use ($currentTick) : bool{
            return $currentTick - $t <= 20;
        });
        $user->clickData->cps = count($this->clicks);
        $clickTime = microtime(true) - $this->lastTime;
        $user->clickData->timeSpeed = $clickTime;
        $this->lastTime = microtime(true);
        $user->clickData->tickSpeed = $this->tickSpeed;
        if($user->clickData->tickSpeed <= 4){
            $user->clickData->tickSamples->add($user->clickData->tickSpeed);
        }
        if($clickTime < 0.2){
            $user->clickData->timeSamples->add($clickTime);
        }
        $this->tickSpeed = 0;
        if($user->mouseRecorder !== null && $user->mouseRecorder->isRunning && $user->moveData->yawDelta > 0){
            $user->mouseRecorder->handleClick();
        }
    }

}