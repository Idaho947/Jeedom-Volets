<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
class Volets extends eqLogic {
	public static $_Gestions=array('Manuel','Jour','Nuit','Meteo','Absent','Azimut');
	public static function deamon_info() {
		$return = array();
		$return['log'] = 'Volets';
		$return['launchable'] = 'ok';
		$return['state'] = 'nok';
		foreach(eqLogic::byType('Volets') as $Volet){
			if($Volet->getIsEnable()){
				$listener = listener::byClassAndFunction('Volets', 'pull', array('Volets_id' => $Volet->getId()));
				if (!is_object($listener))
					return $return;
				if ($Volet->getConfiguration('Jour')){
					$cron = cron::byClassAndFunction('Volets', 'ActionJour', array('Volets_id' => $Volet->getId()));
					if (!is_object($cron)) 	
						return $return;
				}
				if ($Volet->getConfiguration('Nuit')){
					$cron = cron::byClassAndFunction('Volets', 'ActionNuit', array('Volets_id' => $Volet->getId()));
					if (!is_object($cron)) 	
						return $return;
				}
			}
		}
		$return['state'] = 'ok';
		return $return;
	}
	public static function deamon_start($_debug = false) {
		log::remove('Volets');
		self::deamon_stop();
		$deamon_info = self::deamon_info();
		if ($deamon_info['launchable'] != 'ok') 
			return;
		if ($deamon_info['state'] == 'ok') 
			return;
		foreach(eqLogic::byType('Volets') as $Volet)
			$Volet->StartDemon();
	}
	public static function deamon_stop() {	
		foreach(eqLogic::byType('Volets') as $Volet){
			$listener = listener::byClassAndFunction('Volets', 'pull', array('Volets_id' => $Volet->getId()));
			if (is_object($listener))
				$listener->remove();
			$cron = cron::byClassAndFunction('Volets', 'ActionJour', array('Volets_id' => $Volet->getId()));
			if (is_object($cron)) 	
				$cron->remove();
			$cron = cron::byClassAndFunction('Volets', 'ActionNuit', array('Volets_id' => $Volet->getId()));
			if (is_object($cron)) 	
				$cron->remove();
		}
	}
	public static function pull($_option) {
		$Volet = Volets::byId($_option['Volets_id']);
		if (is_object($Volet) && $Volet->getIsEnable()) {
			$Event = cmd::byId($_option['event_id']);
			if(is_object($Event)){
				switch($Event->getlogicalId()){
					case 'azimuth360':
						log::add('Volets','info',$Volet->getHumanName().' : Mise à jour de la position du soleil');	
						$Volet->ActionAzimute($_option['value']);
					break;
					case $Volet->getConfiguration('TypeDay'):
						log::add('Volets','info',$Volet->getHumanName().' : Replanification de l\'ouverture au lever du soleil');
						$DayStart=$_option['value'];
						if($Volet->getConfiguration('DayMin') != '' && $DayStart < $Volet->getConfiguration('DayMin'))
						   $DayStart=$Volet->getConfiguration('DayMin');
						$timstamp=$Volet->CalculHeureEvent($DayStart,'DelaisDay');
						$Schedule=date("i",$timstamp) . ' ' . date("H",$timstamp) . ' * * * *';
						$cron = $Volet->CreateCron($Schedule, 'ActionJour');
					break;
					case $Volet->getConfiguration('TypeNight'):
						log::add('Volets','info',$Volet->getHumanName().' : Replanification de la fermeture au coucher du soleil');
						$NightStart=$_option['value'];
						if($Volet->getConfiguration('NightMax') != '' && $NightStart > $Volet->getConfiguration('NightMax'))
						   $NightStart=$Volet->getConfiguration('NightMax');
						$timstamp=$Volet->CalculHeureEvent($DayStart,'$NightStart');	
						$timstamp=$Volet->CalculHeureEvent($_option['value'],'DelaisNight');
						$Schedule=date("i",$timstamp) . ' ' . date("H",$timstamp) . ' * * * *';
						$cron = $Volet->CreateCron($Schedule, 'ActionNuit');
					break;
					default:
						if ($Event->getId() == str_replace('#','',$Volet->getConfiguration('RealState'))){
							log::add('Volets','info',$Volet->getHumanName().' : Changement de l\'état réel du volet');
							if($_option['value'] < $Volet->getConfiguration("SeuilRealState"))
								$State='open';
							else
								$State='close';
							$cache = cache::byKey('Volets::ChangeState::'.$Volet->getId());
							if($cache->getValue(false)){
								log::add('Volets','info',$Volet->getHumanName().' : Le changement d\'état est autorisé');
								$Volet->checkAndUpdateCmd('hauteur',$_option['value']);
								//if($Volet->getCmd(null,'position')->execCmd() == $State)
									cache::set('Volets::ChangeState::'.$Volet->getId(),false, 0);
							}else{
								if($Volet->getConfiguration('Manuel')){
									if($Volet->getCmd(null,'position')->execCmd() == $State){
										message::add('succes','Un evenement manuel identique a ce qu\'attend le plugin a été détécté sur le volet '.$Volet->getHumanName().' La gestion a été activé');
										$Volet->checkAndUpdateCmd('gestion','Jour');
										//$Volet->checkAndUpdateCmd('isArmed',true);									
									}else{
										message::add('danger','Un evenement manuel a été détécté sur le volet '.$Volet->getHumanName().' La gestion a été désactivé');
										$Volet->checkAndUpdateCmd('gestion','Manuel');
										//$Volet->checkAndUpdateCmd('isArmed',false);
									}
								}
                     				       }
						}
						if ($Event->getId() == str_replace('#','',$Volet->getConfiguration('cmdPresent'))){
							log::add('Volets','info',$Volet->getHumanName().' : Mise à jour de la présence');	
							$Volet->ActionAbsent($_option['value']);
						}
					break;
				}
			}
		}
	}
	public function AutorisationAction($Evenement) {   
		if ($this->getIsEnable() && $this->getCmd(null,'isArmed')->execCmd()){
			$Mode = $this->getCmd(null,'gestion')->execCmd();
			switch($Evenement){
				case 'Jour':
					if ($this->getConfiguration('Jour'))
						return true;
				case 'Nuit':
					if ($this->getConfiguration('Nuit'))
						return true;
				break;
				case 'Absent':
					if ($this->getConfiguration('Absent')
					    && $Mode != "Nuit" )
						return true;
				break;
				case 'Meteo':					
					if ($this->getConfiguration('Meteo')
					    && $Mode != "Nuit" 
					    && $Mode != "Absent")
						return true;
				break;
				case 'Azimut':
					if ($this->getConfiguration('Azimut')
					    && $Mode != "Nuit" 
					    && $Mode != "Absent" 
					    && $Mode != "Meteo")
						return true;
				break;
			}
		}
		return false;
	}
	public function CheckOtherGestion($Gestion) {   
		$Saison=$this->getSaison();
		switch($Gestion){
			case 'Jour':
				if ($this->getConfiguration('Absent')){	
					$Commande=cmd::byId(str_replace('#','',$this->getConfiguration('cmdPresent')));
					if(is_object($Commande) && $Commande->execCmd() == false){
						log::add('Volets', 'info', $this->getHumanName().'[Gestion '.$Gestion.'] : Il n\'y a personne dans la maison la gestion Absent prend le relais');
						$this->CheckActions('Absent','close',$Saison);
						return false;
					}
				}
			case 'Absent':
				if ($this->getConfiguration('Meteo')){
					$Evenement=$this->checkCondition('close',$Saison,'Meteo');   		
					if($Evenement != false && $Evenement == "close")
						log::add('Volets', 'info', $this->getHumanName().'[Gestion '.$Gestion.'] : Il n\'y a personne dans la maison la gestion Meteo prend le relais');
						$this->CheckActions('Meteo',$Evenement,$Saison);
						return false;
				}
			case 'Meteo':	
				if ($this->getConfiguration('Azimut')){
					$heliotrope=eqlogic::byId($this->getConfiguration('heliotrope'));
					if(is_object($heliotrope)){
						$Azimut=$heliotrope->getCmd(null,'azimuth360')->execCmd();
						if($this->ActionAzimute($Azimut) !== false){
							log::add('Volets', 'info', $this->getHumanName().'[Gestion '.$Gestion.'] : Il n\'y a personne dans la maison la gestion Azimut prend le relais');
							return false;
						}
					}
				}
		}
		return true;
	}
	public static function ActionJour($_option) {    
		$Volet = Volets::byId($_option['Volets_id']);
		if (is_object($Volet) && $Volet->AutorisationAction('Jour')){	
			log::add('Volets', 'info', $Volet->getHumanName().'[Gestion Jour] : Exécution de la gestion du lever du soleil');
			$Saison=$Volet->getSaison();
			$Evenement=$Volet->checkCondition('open',$Saison,'Jour');
			if( $Evenement!= false){
				$Volet->checkAndUpdateCmd('gestion','Jour');
				if(!$Volet->CheckOtherGestion('Jour'))
					return;
				$Volet->CheckActions('Jour',$Evenement,$Saison);
			}else{
				log::add('Volets', 'info',$Volet->getHumanName().'[Gestion Jour] : Replanification de l\'évaluation des conditions d\'ouverture au lever du soleil');
				$timstamp=$Volet->CalculHeureEvent(date('Hi'),'DelaisEval');
				$Schedule=date("i",$timstamp) . ' ' . date("H",$timstamp) . ' * * * *';
				$cron = $Volet->CreateCron($Schedule, 'ActionJour', array('Volets_id' => intval($Volet->getId())));
			}
		}
	}
	public static function ActionNuit($_option) {
		$Volet = Volets::byId($_option['Volets_id']);
		if (is_object($Volet) && $Volet->AutorisationAction('Nuit')){
			log::add('Volets', 'info',$Volet->getHumanName().'[Gestion Nuit] : Exécution de la gestion du coucher du soleil ');
			$Saison=$Volet->getSaison();
			$Evenement=$Volet->checkCondition('close',$Saison,'Nuit');
			if( $Evenement!= false){
				$Volet->CheckActions('Nuit',$Evenement,$Saison);
			}else{
				log::add('Volets', 'info', $Volet->getHumanName().'[Gestion Nuit] : Replanification de l\'évaluation des conditions de fermeture au coucher du soleil');
				$timstamp=$Volet->CalculHeureEvent(date('Hi'),'DelaisEval');
				$Schedule=date("i",$timstamp) . ' ' . date("H",$timstamp) . ' * * * *';
				$cron = $Volet->CreateCron($Schedule, 'ActionNuit', array('Volets_id' => intval($Volet->getId())));
			}
		}
	}
	public static function ActionMeteo($_option) {
		$Volet = Volets::byId($_option['Volets_id']);
		if (is_object($Volet) && $Volet->AutorisationAction('Meteo')){
			log::add('Volets', 'info',$Volet->getHumanName().'[Gestion Meteo] : Exécution de la gestion météo');
			$Saison=$Volet->getSaison();
			$Evenement=$Volet->checkCondition('close',$Saison,'Meteo');   		
			if($Evenement== false && $Volet->getCmd(null,'gestion')->execCmd()=='Meteo'){
				if(!$Volet->CheckOtherGestion('Meteo'))
					return;
				$Evenement=$Volet->checkCondition('open',$Saison,'Meteo');   	
			} 
			if($Evenement != false)
				$Volet->CheckActions('Meteo',$Evenement,$Saison);
			return $Evenement;
		}
	}
  	public function ActionAbsent($Etat) {
		if ($this->AutorisationAction('Absent')){
			$Saison=$this->getSaison();
			if($Etat)
				$Evenement='open';
			else
				$Evenement='close';
			$Evenement=$this->checkCondition($Evenement,$Saison,'Absent');
			if( $Evenement!= false ){
				if($Evenement!= 'close' ){
					if(!$this->CheckOtherGestion('Absent'))
						return;	
				}
				$this->CheckActions('Absent',$Evenement,$Saison);
			}
		}
	}
	public function ActionAzimute($Azimut) {
		$Saison=$this->getSaison();
		$Evenement=$this->SelectAction($Azimut,$Saison);
		if ($this->AutorisationAction('Azimut') && $Evenement != false){
			$Evenement=$this->checkCondition($Evenement,$Saison,'Azimut');
			if( $Evenement!= false){
				$this->CheckActions('Azimut',$Evenement,$Saison);
			}
		}
		return $Evenement;
	}	
	public function CheckAngle($Azimut) {
		$Droite=$this->getConfiguration('Droite');
		$Gauche=$this->getConfiguration('Gauche');
		$Centre=$this->getConfiguration('Centre');
		$AngleCntDrt=$this->getConfiguration('AngleDroite');
		$AngleCntGau=$this->getConfiguration('AngleGauche');
		if(!is_numeric($AngleCntDrt)&&!is_numeric($AngleCntGau)){
			if(is_array($Droite)&&is_array($Centre)&&is_array($Gauche)){
				$AngleCntDrt=$this->getAngle(
					$Centre['lat'],
					$Centre['lng'],
					$Droite['lat'],
					$Droite['lng']);
				$AngleCntGau=$this->getAngle(
					$Centre['lat'],
					$Centre['lng'],
					$Gauche['lat'],
					$Gauche['lng']);
				$this->setConfiguration('AngleDroite',$AngleCntDrt);
				$this->setConfiguration('AngleGauche',$AngleCntGau);
				$this->save();
			}else{
				log::add('Volets','debug',$this->getHumanName().'[Gestion Azimut] : Les coordonnées GPS de l\'angle d\'exposition au soleil de votre fenêtre sont mal configurées');
				return false;	
			}
		}
		$result=false;
		if ($AngleCntDrt < $AngleCntGau){
			if($AngleCntDrt <= $Azimut && $Azimut <= $AngleCntGau)
				$result= true;
		}else{
			if($AngleCntDrt <= $Azimut && $Azimut <= 360)
				$result= true;
			if(0 <= $Azimut && $Azimut <= $AngleCntGau)
				$result= true;
		}		
		log::add('Volets','info',$this->getHumanName().'[Gestion Azimut] : L\'azimut ' . $Azimut . '° est compris entre : '.$AngleCntDrt.'°  et '.$AngleCntGau.'° => '.$this->boolToText($result));
		return $result;
	}	
	public function getSaison() {
		$isInWindows=$this->getCmd(null,'isInWindows');		if(!is_object($isInWindows))
			return false;
		if($isInWindows->execCmd()){
			log::add('Volets','debug',$this->getHumanName().' : Le plugin est configuré en mode hiver');
			return 'hiver';
		}else{
			log::add('Volets','debug',$this->getHumanName().' : Le plugin est configuré en mode été');
			return 'été';
		}
		return false;
	}	
	public function SelectAction($Azimut,$saison) {
		$Action=false;
		if($this->CheckAngle($Azimut)){
			$this->checkAndUpdateCmd('state',true);
			log::add('Volets','info',$this->getHumanName().'[Gestion Azimut] : Le soleil est dans la fenêtre');
			if($saison =='hiver')
				$Action='open';
			else
				$Action='close';
		}else{
			$this->checkAndUpdateCmd('state',false);
			log::add('Volets','info',$this->getHumanName().'[Gestion Azimut] : Le soleil n\'est pas dans la fenêtre');
			if($saison == 'été')
				$Action='open';
			else
				$Action='close';
		}
		return $Action;
	}
	public function CheckActions($Gestion,$Evenement,$Saison){
		if($Evenement == 'open')
			$Hauteur=100;
		elseif($Evenement == 'close' && $Saison == 'hiver')
			$Hauteur=0;
		else		
			$Hauteur=$this->checkAltitude();
      		$ActualGestion=$this->getCmd(null,'gestion')->execCmd();
		if($ActualGestion != "Manuel"){
			log::add('Volets','info',$this->getHumanName().'[Gestion '.$Gestion.'] : Autorisation d\'executer les actions');
			foreach($this->getConfiguration('action') as $Cmd){	
				if (!$this->CheckValid($Cmd,$Evenement,$Saison,$Gestion))
					continue;
				if($this->getPosition() != $Evenement 
				   || $this->getCmd(null,'gestion')->execCmd() != $Gestion
				   || ($this->getCmd(null,'gestion')->execCmd() == 'Azimut' 
				      	&& $this->getCmd(null,'hauteur')->execCmd() != $Hauteur 
					&& array_search('#Hauteur#', $Cmd['options'])!== false))
					$this->ExecuteAction($Cmd,'Azimut',$Hauteur);
			}
			if ($Evenement == 'open' && $Gestion != 'Azimut')
				$Gestion = 'Jour';
			$this->checkAndUpdateCmd('gestion',$Gestion);
			$this->checkAndUpdateCmd('hauteur',$Hauteur);
			cache::set('Volets::ChangeState::'.$this->getId(),true, 0);
		}
		$this->setPosition($Evenement);
	}
	public function ExecuteAction($cmd,$Gestion,$Hauteur=0){		
		try {
			$options = array();
			if (isset($cmd['options'])) 
				$options = $cmd['options'];
			scenarioExpression::createAndExec('action', $cmd['cmd'], $options);
		} catch (Exception $e) {
			log::add('Volets', 'error',$this->getHumanName().'[Gestion '.$Gestion.'] : '. __('Erreur lors de l\'exécution de ', __FILE__) . $action['cmd'] . __('. Détails : ', __FILE__) . $e->getMessage());
		}
		$Commande=cmd::byId(str_replace('#','',$cmd['cmd']));
		if(is_object($Commande)){
			$options=null;
			if(isset($cmd['options'])){
				$options=$cmd['options'];
				$key = array_search('#Hauteur#', $options);
				if($key !== false)
                			$options[$key]=str_replace('#Hauteur#',$Hauteur,$options[$key]);
			}
			log::add('Volets','debug',$this->getHumanName().'[Gestion '.$Gestion.'] : Exécution de '.$Commande->getHumanName().' ('.json_encode($options).')');
			$Commande->execute($options);
		}
	}
	public function CalculHeureEvent($HeureStart, $delais) {
		if(strlen($HeureStart)==3)
			$Heure=substr($HeureStart,0,1);
		else
			$Heure=substr($HeureStart,0,2);
		$Minute=floatval(substr($HeureStart,-2));
		if($this->getConfiguration($delais)!='')
			$Minute+=floatval($this->getConfiguration($delais));
		while($Minute>=60){
			$Minute-=60;
			$Heure+=1;
		}
		return mktime($Heure,$Minute);
	}
	public function CreateCron($Schedule, $logicalId) {
		$cron =cron::byClassAndFunction('Volets', $logicalId, array('Volets_id' => $this->getId()));
			if (!is_object($cron)) {
				$cron = new cron();
				$cron->setClass('Volets');
				$cron->setFunction($logicalId);
				$cron->setOption(array('Volets_id' => $this->getId()));
				$cron->setEnable(1);
				$cron->setDeamon(0);
				$cron->setSchedule($Schedule);
				$cron->save();
			}else{
				$cron->setSchedule($Schedule);
				$cron->save();
			}
		return $cron;
	}
	public function CheckValid($Element,$Evenement,$Saison,$Gestion){
		if(array_search($Evenement, $Element['evaluation']) === false)
			return false;
		if(array_search($Saison, $Element['saison']) === false)
			return false;
		if(array_search($Gestion, $Element['TypeGestion']) === false)
			return false;		
		if (isset($Element['enable']) && $Element['enable'] == 0)
			return false;
		return true;
	}
	public function checkCondition($Evenement,$Saison,$Gestion){		
		foreach($this->getConfiguration('condition') as $Condition){
			if (!$this->CheckValid($Condition,$Evenement,$Saison,$Gestion))
				continue;
			if (!$this->EvaluateCondition($Condition,$Gestion)){
				if($Condition['Inverse']){
					log::add('Volets','info',$this->getHumanName().'[Gestion '.$Gestion.'] : La condition inverse l\'état du volet');
					if($Evenement == 'close')
						$Evenement='open';
					else
						$Evenement='close';
					if ($this->_inverseCondition){
						$this->_inverseCondition=false;
						return false;
					}
					$this->_inverseCondition=true;
					return $this->checkCondition($Evenement,$Saison,$Gestion);
				}
				log::add('Volets','info',$this->getHumanName().'[Gestion '.$Gestion.'] : Les conditions ne sont pas remplies');
				return false;
			}
		}
		log::add('Volets','info',$this->getHumanName().'[Gestion '.$Gestion.'] : Les conditions sont remplies pour '.$Evenement);
		return $Evenement;
	}
	public function boolToText($value){
		if (is_bool($value)) {
			if ($value) 
				return __('Vrai', __FILE__);
			else 
				return __('Faux', __FILE__);
		} else 
			return $value;
	}
	public function EvaluateCondition($Condition,$Gestion){
		$_scenario = null;
		$expression = scenarioExpression::setTags($Condition['expression'], $_scenario, true);
		$message = __('Evaluation de la condition : [', __FILE__) . trim($expression) . '] = ';
		$result = evaluate($expression);
		$message .=$this->boolToText($result);
		log::add('Volets','info',$this->getHumanName().'[Gestion '.$Gestion.'] : '.$message);
		if(!$result)
			return false;		
		return true;
	}
	public function getAngle($latitudeOrigine,$longitudeOrigne, $latitudeDest,$longitudeDest) { 
		$rlongitudeOrigne = deg2rad($longitudeOrigne); 
		$rlatitudeOrigine = deg2rad($latitudeOrigine); 
		$rlongitudeDest = deg2rad($longitudeDest); 
		$rlatitudeDest = deg2rad($latitudeDest); 
		$longDelta = $rlongitudeDest - $rlongitudeOrigne; 
		$y = sin($longDelta) * cos($rlatitudeDest); 
		$x = (cos($rlatitudeOrigine)*sin($rlatitudeDest)) - (sin($rlatitudeOrigine)*cos($rlatitudeDest)*cos($longDelta)); 
		$angle = rad2deg(atan2($y, $x)); 
		if ($angle < 0) { 

			$angle += 360; 
		}
		return floatval($angle % 360);
	}
	public function checkAltitude() { 
		$heliotrope=eqlogic::byId($this->getConfiguration('heliotrope'));
		if(is_object($heliotrope)){
			$Altitude =$heliotrope->getCmd(null,'altitude');
			if(!is_object($Altitude))
				return false;
			if (!$heliotrope->getConfiguration('zenith', '')) {
			    $zenith = '90.58';
			} else {
			    $zenith = $heliotrope->getConfiguration('zenith', '');
			}
			$Hauteur=round($Altitude->execCmd()*100/$zenith);
			log::add('Volets','info',$this->getHumanName().'[Gestion Altitude] : L\'altitude actuel est a '.$Hauteur.'% par rapport au zenith');
			return $Hauteur;
		}
		return false;
	}
	public function StartDemon() {
		if($this->getIsEnable()){
			$heliotrope=eqlogic::byId($this->getConfiguration('heliotrope'));
			if(is_object($heliotrope)){
				$listener = listener::byClassAndFunction('Volets', 'pull', array('Volets_id' => $this->getId()));
				if (!is_object($listener))
				    $listener = new listener();
				$listener->setClass('Volets');
				$listener->setFunction('pull');
				$listener->setOption(array('Volets_id' => $this->getId()));
				$listener->emptyEvent();				
				if ($this->getConfiguration('RealState') != '')
					$listener->addEvent($this->getConfiguration('RealState'));
				if ($this->getConfiguration('Azimut'))
					$listener->addEvent($heliotrope->getCmd(null,'azimuth360')->getId());
				if ($this->getConfiguration('Absent'))
					$listener->addEvent($this->getConfiguration('cmdPresent'));
				if ($this->getConfiguration('Jour')){
					$sunrise=$heliotrope->getCmd(null,$this->getConfiguration('TypeDay'));
					if(!is_object($sunrise))
						return false;
					$listener->addEvent($sunrise->getId());
					$DayStart=$sunrise->execCmd();
					if($this->getConfiguration('DayMin') != '' && $DayStart < $this->getConfiguration('DayMin'))
						   $DayStart=$this->getConfiguration('DayMin');
					$DelaisDay=$this->CalculHeureEvent($DayStart,'DelaisDay');
					if(mktime() > $DelaisDay)
						$this->checkAndUpdateCmd('gestion','Jour');
					$Schedule=date("i",$DelaisDay) . ' ' . date("H",$DelaisDay) . ' * * * *';
					$cron = $this->CreateCron($Schedule, 'ActionJour', array('Volets_id' => intval($this->getId())));
				}	
				if ($this->getConfiguration('Nuit')){
					$sunset=$heliotrope->getCmd(null,$this->getConfiguration('TypeNight'));
					if(!is_object($sunset))
						return false;
					$listener->addEvent($sunset->getId());
					$NightStart=$sunset->execCmd();
					if($this->getConfiguration('NightMax') != '' && $NightStart > $this->getConfiguration('NightMax'))
						   $NightStart=$this->getConfiguration('NightMax');
					$DelaisNight=$this->CalculHeureEvent($NightStart,'DelaisNight');
					if(mktime() > $DelaisNight)
						$this->checkAndUpdateCmd('gestion','Nuit');
					$Schedule=date("i",$DelaisNight) . ' ' . date("H",$DelaisNight) . ' * * * *';
					$cron = $this->CreateCron($Schedule, 'ActionNuit', array('Volets_id' => intval($this->getId())));
				}
				if ($this->getConfiguration('Meteo'))
					$cron = $this->CreateCron('* * * * * *', 'ActionMeteo', array('Volets_id' => intval($this->getId())));
				$listener->save();	
				$this->CheckOtherGestion('Jour');
			}
		}
	}
	public function AddCommande($Name,$_logicalId,$Type="info", $SubType='binary',$visible,$Template='') {
		$Commande = $this->getCmd(null,$_logicalId);
		if (!is_object($Commande))
		{
			$Commande = new VoletsCmd();
			$Commande->setId(null);
			$Commande->setName($Name);
			$Commande->setIsVisible($visible);
			$Commande->setLogicalId($_logicalId);
			$Commande->setEqLogic_id($this->getId());
		}
		$Commande->setType($Type);
		$Commande->setSubType($SubType);
   		$Commande->setTemplate('dashboard',$Template );
		$Commande->setTemplate('mobile', $Template);
		$Commande->save();
		return $Commande;
	}
	public function setPosition($Evenement) {
		$this->checkAndUpdateCmd('position',$Evenement);
	}
	public function getPosition() {
		return $this->getCmd(null,'position')->execCmd();
	}
	public function preSave() {
		/*if($this->getConfiguration('heliotrope') == "")
			throw new Exception(__('Impossible d\'enregister, la configuration de l\'equipement heliotrope n\'existe pas', __FILE__));
		$heliotrope=eqlogic::byId($this->getConfiguration('heliotrope'));
		if(is_object($heliotrope)){	
			if($heliotrope->getConfiguration('geoloc') == "")
				throw new Exception(__('Impossible d\'enregister, la configuration  heliotrope n\'est pas correcte', __FILE__));
			$geoloc = geotravCmd::byEqLogicIdAndLogicalId($heliotrope->getConfiguration('geoloc'),'location:coordinate');
			if(is_object($geoloc) && $geoloc->execCmd()='')	
				throw new Exception(__('Impossible d\'enregister, la configuration de  "Localisation et trajet" (geotrav) n\'est pas correcte', __FILE__));
		}*/
	}
	public function postSave() {
		$this->AddCommande("Hauteur du volet","hauteur","info", 'numeric',true);
		$this->AddCommande("Gestion Active","gestion","info", 'string',true);
		$state=$this->AddCommande("Position du soleil","state","info", 'binary',true,'sunInWindows');
		$this->checkAndUpdateCmd('state',false);
		$isInWindows=$this->AddCommande("Etat mode","isInWindows","info","binary",false,'isInWindows');
		$inWindows=$this->AddCommande("Mode","inWindows","action","select",true,'inWindows');
		$inWindows->setConfiguration('listValue','1|Hivers;0|Eté');
		$inWindows->setValue($isInWindows->getId());
		$inWindows->save();
		$isArmed=$this->AddCommande("Etat activation","isArmed","info","binary",false,'lock');
		$this->checkAndUpdateCmd('isArmed',true);
		$Armed=$this->AddCommande("Activer","armed","action","other",true,'lock');
		$Armed->setValue($isArmed->getId());
		$Armed->setConfiguration('state', '1');
		$Armed->setConfiguration('armed', '1');
		$Armed->save();
		$Released=$this->AddCommande("Désactiver","released","action","other",true,'lock');
		$Released->setValue($isArmed->getId());
		$Released->save();
		$Released->setConfiguration('state', '0');
		$Released->setConfiguration('armed', '1');
		$Position=$this->AddCommande("Etat du volet","position","info","string",false);
		$VoletState=$this->AddCommande("Position du volet","VoletState","action","select",true,'volet');
		$VoletState->setConfiguration('listValue','open|Ouvert;close|Fermé');
		$VoletState->setDisplay('title_disable', 1);
		$VoletState->setValue($Position->getId());
		$VoletState->save();
		/*$Commande=cmd::byId(str_replace('#','',$this->getConfiguration('RealState')));
		if(is_object($Commande))
			$this->checkAndUpdateCmd('position',$Commande->execCmd());*/
		self::deamon_stop();
	}	
	public function postRemove() {
		$listener = listener::byClassAndFunction('Volets', 'pull', array('Volets_id' => $this->getId()));
		if (is_object($listener))
			$listener->remove();
		$cron = cron::byClassAndFunction('Volets', 'ActionJour', array('Volets_id' => $this->getId()));
		if (is_object($cron)) 	
			$cron->remove();
		$cron = cron::byClassAndFunction('Volets', 'ActionNuit', array('Volets_id' => $this->getId()));
		if (is_object($cron)) 	
			$cron->remove();
		$cron = cron::byClassAndFunction('Volets', 'ActionMeteo', array('Volets_id' => $this->getId()));
		if (is_object($cron)) 	
			$cron->remove();
	}
}
class VoletsCmd extends cmd {
    	public function execute($_options = null) {
		$Listener=cmd::byId(str_replace('#','',$this->getValue()));
		if (is_object($Listener)) {	
			switch($this->getLogicalId()){
				case 'armed':
					$Listener->event(true);
					$this->getEqLogic()->StartDemon();
				break;
				case 'released':
					$Listener->event(false);
					$PullListener = listener::byClassAndFunction('Volets', 'pull', array('Volets_id' => $this->getEqLogic()->getId()));
					if (is_object($PullListener))
						$PullListener->remove();
					$cron = cron::byClassAndFunction('Volets', 'ActionJour', array('Volets_id' => $this->getEqLogic()->getId()));
					if (is_object($cron)) 	
						$cron->remove();
					$cron = cron::byClassAndFunction('Volets', 'ActionNuit', array('Volets_id' => $this->getEqLogic()->getId()));
					if (is_object($cron)) 	
						$cron->remove();
					$cron = cron::byClassAndFunction('Volets', 'ActionMeteo', array('Volets_id' => $this->getEqLogic()->getId()));
					if (is_object($cron)) 	
						$cron->remove();
				break;
				case 'VoletState':
				case 'inWindows':
					$Listener->event($_options['select']);
				break;
			}
			$Listener->setCollectDate(date('Y-m-d H:i:s'));
			$Listener->save();
		}
	}
}
?>
