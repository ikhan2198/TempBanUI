<?php

namespace TempBanUI;

use pocketmine\plugin\PluginBase;
use pocketmine\Player;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;

use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ModalFormResponsePacket;

class Main extends PluginBase implements Listener {

	public $formCount = 0;
	public $forms = [];
	public $playerList = [];
	
    public function onEnable() {
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		@mkdir($this->getDataFolder());
		$this->db = new \SQLite3($this->getDataFolder() . "TempBanUI.db");
		$this->db->exec("CREATE TABLE IF NOT EXISTS banPlayers(player TEXT PRIMARY KEY, banTime INT);");
		
		$this->message = (new Config($this->getDataFolder() . "Message.yml", Config::YAML, array(
		
		"BroadcastBanMessage" => "§b{player} has been ban for §b{day} §dday/s, §b{hour} §dhour/s, §b{minute} §dminute/s.",
		"KickBanMessage" => "§dYou are ban for §b{day} §dday/s, §b{hour} §dhour/s, §b{minute} §dminute/s.",
		"LoginBanMessage" => "§dYou are still ban for §b{day} §dday/s, §b{hour} §dhour/s, §b{minute} §dminute/s, §b{second} §dsecond/s.",
		
		)))->getAll();
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label,array $args) : bool {
		switch($cmd->getName()){
			case "tban":
				if($sender instanceof Player) {
					if($sender->hasPermission("use.tban")){
						$form = $this->createCustomForm(function (Player $sender, array $data){
							$result = $data[0];
							if($result === null){
								return true;
							}
							$c = 0;
							foreach($this->playerList as $player){
								if($result == $c){
									$target = $player->getPlayer();	
									if($target instanceof Player){
										if($target->getName() == $sender->getName()){
											$player->sendMessage(TextFormat::RED . "You can't ban yourself");
											return true;
										}
										$now = time();
										$day = ($data[1] * 86400);
										$hour = ($data[2] * 3600);
										$min = ($data[3] * 60);
										$banTime = $now + $day + $hour + $min;
										$tempban = $this->db->prepare("INSERT OR REPLACE INTO banPlayers (player, banTime) VALUES (:player, :banTime);");
										$tempban->bindValue(":player", $target->getName());
										$tempban->bindValue(":banTime", $banTime);
										$result = $tempban->execute();
										$target->kick(str_replace(["{day}", "{hour}", "{minute}"], [$data[1], $data[2], $data[3]], $this->message["KickBanMessage"]));
										$this->getServer()->broadcastMessage(str_replace(["{player}", "{day}", "{hour}", "{minute}"], [$target->getName(), $data[1], $data[2], $data[3]], $this->message["BroadcastBanMessage"]));
										foreach($this->playerList as $player){
											unset($this->playerList[strtolower($player->getName())]);
										}
									}
								}
								$c++;
							}
						});
						foreach($this->getServer()->getOnlinePlayers() as $player){
							$player = $player->getPlayer();
							$this->playerList[strtolower($player->getName())] = $player;
							$list[] = $player->getName();
						}
						$form->setTitle(TextFormat::BOLD . "TEMPORARY BAN");
						$form->addDropdown("\nChoose player", $list);
						$form->addSlider("Day/s", 0, 30, 1);
						$form->addSlider("Hour/s", 0, 24, 1);
						$form->addSlider("Minute/s", 1, 60, 5);
						$form->sendToPlayer($sender);
					}
				}
				else{
					$sender->sendMessage(TextFormat::RED . "Use this Command in-game.");
					return true;
				}
			break;
		}
		return true;
    }
	
	public function onPlayerLogin(PlayerPreLoginEvent $event){
		$player = $event->getPlayer();
		$result = $this->db->query("SELECT * FROM banPlayers;");
		$array = $result->fetchArray(SQLITE3_ASSOC);	
		if (!empty($array)) {
			$result = $this->db->query("SELECT * FROM banPlayers;");
			$i = -1;
			while ($resultArr = $result->fetchArray(SQLITE3_ASSOC)) {
				$j = $i + 1;
				$banplayer = $resultArr['player'];
				if($player->getName() == $banplayer){
					$banInfo = $this->db->query("SELECT * FROM banPlayers WHERE player = '$banplayer';");
					$array = $banInfo->fetchArray(SQLITE3_ASSOC);
					if (!empty($array)) {
						$banTime = $array['banTime'];
						$now = time();
						if($banTime > $now){
							$remainingTime = $banTime - $now;
							$day = floor($remainingTime / 86400);
							$hourSeconds = $remainingTime % 86400;
							$hour = floor($hourSeconds / 3600);
							$minuteSec = $hourSeconds % 3600;
							$minute = floor($minuteSec / 60);
							$remainingSec = $minuteSec % 60;
							$second = ceil($remainingSec);
							$player->close("", str_replace(["{day}", "{hour}", "{minute}", "{second}"], [$day, $hour, $minute, $second], $this->message["LoginBanMessage"]));
						} else {
							$banInfo = $this->db->query("SELECT * FROM banPlayers WHERE player = '$banplayer';");
							$array = $banInfo->fetchArray(SQLITE3_ASSOC);
							if (!empty($array)) {
								$this->db->query("DELETE FROM banPlayers WHERE player = '$banplayer';");
							}
						}
					}
				}
				$i = $i + 1;
			}
		}
	}
	
	public function onPlayerQuit(PlayerQuitEvent $ev){
		$player = $ev->getPlayer();
		foreach($this->forms as $id => $form){
			if($form->isRecipient($player)){
				unset($this->forms[$id]);
				break;
			}
		}
	}
	
	public function createCustomForm(callable $function = null) : CustomForm {
		$this->formCount++;
        $form = new CustomForm($this->formCount, $function);
        $this->forms[$this->formCount] = $form;
        return $form;
    }
	
	public function onPacketReceived(DataPacketReceiveEvent $ev) : void {
		$pk = $ev->getPacket();
		if($pk instanceof ModalFormResponsePacket){
			$player = $ev->getPlayer();
			$formId = $pk->formId;
			$data = json_decode($pk->formData, true);
			if(isset($this->forms[$formId])){
				$form = $this->forms[$formId];
				if(!$form->isRecipient($player)){
					return;
				}
				$callable = $form->getCallable();
				if(!is_array($data)){
					$data = [$data];
				}
				if($callable !== null) {
					$callable($ev->getPlayer(), $data);
				}
				unset($this->forms[$formId]);
				$ev->setCancelled();
			}
		}
	}

}
