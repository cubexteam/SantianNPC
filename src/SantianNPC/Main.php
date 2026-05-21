<?php
namespace SantianNPC;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\entity\Villager;
use pocketmine\entity\Human;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\Item;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\network\mcpe\protocol\MobEquipmentPacket;

class Main extends PluginBase implements Listener {

    public function onEnable() {
        Entity::registerEntity(SantianDevVillager::class, true);
        Entity::registerEntity(SantianDevHuman::class, true);
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args) {
        if ($command->getName() === "npc") {
            if (!$sender instanceof Player) {
                $sender->sendMessage("Команда только для игроков");
                return true;
            }

            if (!isset($args[0])) {
                $sender->sendMessage("§cИспользование: /npc spawn <human|villager> <name> <cmd>");
                $sender->sendMessage("§cИли: /npc del");
                return true;
            }

            if ($args[0] === "del") {
                $stick = Item::get(Item::STICK, 0, 1);
                $stick->setCustomName("§r§cУдаление NPC");
                
                $ench = Enchantment::getEnchantment(12);
                if($ench){
                    $ench->setLevel(1);
                    $stick->addEnchantment($ench);
                }

                $stick->setNamedTagEntry(new StringTag("NpcRemover", "true"));
                
                $sender->getInventory()->addItem($stick);
                $sender->sendMessage("§aВы получили предмет для удаления NPC.");
                return true;
            }

            if ($args[0] === "spawn") {
                if (!isset($args[3])) {
                    $sender->sendMessage("§cИспользование: /npc spawn <type> <name> <cmd>");
                    $sender->sendMessage("§7Типы: human (игрок), villager (житель)");
                    return true;
                }

                $typeArg = strtolower($args[1]);
                $name = str_replace("_", " ", $args[2]);

                array_shift($args); 
                array_shift($args); 
                array_shift($args);
                
                $cmdToRun = implode(" ", $args);
                if (substr($cmdToRun, 0, 1) === "/") {
                    $cmdToRun = substr($cmdToRun, 1);
                }

                $entityClass = null;
                switch ($typeArg) {
                    case "villager":
                    case "житель":
                        $entityClass = "SantianDevVillager";
                        break;
                    case "human":
                    case "player":
                    case "игрок":
                        $entityClass = "SantianDevHuman";
                        break;
                    default:
                        $sender->sendMessage("§cНеверный тип. Используйте: human, villager");
                        return true;
                }

                $this->spawnNPC($sender, $entityClass, $name, $cmdToRun);
                $sender->sendMessage("§aNPC '$name' успешно создан!");
                return true;
            }
        }
        return false;
    }

    public function spawnNPC(Player $player, $className, $name, $cmd) {
        $nbt = new CompoundTag("", [
            new ListTag("Pos", [
                new DoubleTag("", $player->getX()),
                new DoubleTag("", $player->getY()),
                new DoubleTag("", $player->getZ())
            ]),
            new ListTag("Motion", [
                new DoubleTag("", 0),
                new DoubleTag("", 0),
                new DoubleTag("", 0)
            ]),
            new ListTag("Rotation", [
                new FloatTag("", $player->getYaw()),
                new FloatTag("", $player->getPitch())
            ]),
            new StringTag("NpcCommand", $cmd)
        ]);

        if ($className === "SantianDevVillager") {
            $nbt->Profession = new IntTag("Profession", 1);
        }

        if ($className === "SantianDevHuman") {
            $nbt->Skin = new CompoundTag("Skin", [
                new StringTag("Data", $player->getSkinData()),
                new StringTag("Name", $player->getSkinId())
            ]);
        }

        $fullClass = "SantianNPC\\" . $className;
        $entity = Entity::createEntity($className, $player->getLevel(), $nbt);
        
        if($entity instanceof NPCInterface){
            $entity->setNameTag($name);
            $entity->setNameTagVisible(true);
            $entity->setNameTagAlwaysVisible(true);
            $entity->spawnToAll();
        }
    }

    public function onDamage(EntityDamageEvent $event) {
        $entity = $event->getEntity();

        if ($entity instanceof NPCInterface) {
            $event->setCancelled(true);

            if ($entity->fireTicks > 0) {
                $entity->extinguish();
            }

            if ($event instanceof EntityDamageByEntityEvent) {
                $damager = $event->getDamager();
                if ($damager instanceof Player) {
                    
                    $item = $damager->getInventory()->getItemInHand();
                    if($item->getId() === Item::STICK && $item->getNamedTagEntry("NpcRemover") !== null){
                        $entity->close();
                        $damager->sendMessage("§cNPC был удален.");
                        return;
                    }

                    if (!$damager->isSneaking()) {
                        $cmd = $entity->getCommandStr();
                        if ($cmd !== "") {
                            $this->getServer()->dispatchCommand($damager, $cmd);
                        }
                    }
                }
            }
        }
    }
}

interface NPCInterface {
    public function getCommandStr();
}

trait NpcTrait {
    public $commandStr = "";
    private $isHoldingItem = false;

    public function setupNpc() {
        if(isset($this->namedtag->NpcCommand)){
            $val = $this->namedtag["NpcCommand"];
            $this->commandStr = is_object($val) ? $val->getValue() : (string)$val;
        }
    }

    public function saveNBT() {
        parent::saveNBT();
        $this->namedtag->NpcCommand = new StringTag("NpcCommand", $this->commandStr);
        $this->namedtag->Scale = new FloatTag("Scale", 1.3);
    }

    public function getCommandStr() {
        return $this->commandStr;
    }

    public function getName(): string {
        return "SantianDevNPC";
    }

    public function checkPlayersAround() {
        $near = false;
        
        foreach ($this->getViewers() as $player) {
            if ($player->distance($this) <= 5) {
                $near = true;
                break;
            }
        }

        if ($near && !$this->isHoldingItem) {
            $this->holdItem(true);
            $this->isHoldingItem = true;
        } 
        elseif (!$near && $this->isHoldingItem) {
            $this->holdItem(false);
            $this->isHoldingItem = false;
        }
    }

    public function holdItem($active) {
        $item = $active ? $this->getNpcItem() : Item::get(0);
        
        $pk = new MobEquipmentPacket();
        $pk->eid = $this->getId();
        $pk->item = $item;
        $pk->slot = 0;
        $pk->selectedSlot = 0;

        foreach ($this->getViewers() as $player) {
            $player->dataPacket($pk);
        }
    }
}

class SantianDevVillager extends Villager implements NPCInterface {
    use NpcTrait;

    public function initEntity() {
        parent::initEntity();
        $this->setScale(1.3);
        $this->setupNpc();
    }

    public function onUpdate($currentTick) {
        if($this->closed){ return false; }
        
        if ($currentTick % 10 === 0) {
            $this->checkPlayersAround();
        }
        
        return parent::onUpdate($currentTick);
    }

    public function getNpcItem() {
        return Item::get(388, 0, 1);
    }
}

class SantianDevHuman extends Human implements NPCInterface {
    use NpcTrait;

    public function initEntity() {
        parent::initEntity();
        $this->setScale(1.3);
        $this->setupNpc();
    }

    public function onUpdate($currentTick) {
        if($this->closed){ return false; }
        
        if ($currentTick % 10 === 0) {
            $this->checkPlayersAround();
        }
        
        return parent::onUpdate($currentTick);
    }

    public function getNpcItem() {
        return Item::get(276, 0, 1);
    }
}
