<?php
/**
 * @file MiniPaviCli.php
 * @author Jean-arthur SILVE <contact@minipavi.fr>
 * @version 1.1 Novembre 2023
 *
 * Communication avec la passerelle MiniPavi
 *
 * 11/02/2024 : Redirection vers émulateur Minitel si appel direct depuis un navigateur
 *
 */
 
namespace MiniPavi;
 
const VERSION = '1.0';
define('VDT_LEFT', chr(0x08));
define('VDT_RIGHT', chr(0x09));
define('VDT_DOWN', chr(0x0A));
define('VDT_UP', chr(0x0B));
define('VDT_CR', chr(0x0D));
define('VDT_CRLF', chr(0x0D).chr(0x0A));
define('VDT_CLR', chr(0x0C));
define('VDT_G0', chr(0x0F));
define('VDT_G1', chr(0x0E));
define('VDT_G2', chr(0x19));
define('VDT_POS', chr(0x1F));
define('VDT_REP', chr(0x12));
define('VDT_CURON', chr(0x11));
define('VDT_CUROFF', chr(0x14));
define('VDT_CLRLN', chr(0x18));
define('VDT_SZNORM', chr(0x1B).chr(0x4C));
define('VDT_SZDBLH', chr(0x1B).chr(0x4D));
define('VDT_SZDBLW', chr(0x1B).chr(0x4E));
define('VDT_SZDBLHW', chr(0x1B).chr(0x4F));
define('VDT_TXTBLACK', chr(0x1B).'@');
define('VDT_TXTRED', chr(0x1B).'A');
define('VDT_TXTGREEN', chr(0x1B).'B');
define('VDT_TXTYELLOW', chr(0x1B).'C');
define('VDT_TXTBLUE', chr(0x1B).'D');
define('VDT_TXTMAGENTA', chr(0x1B).'E');
define('VDT_TXTCYAN', chr(0x1B).'F');
define('VDT_TXTWHITE', chr(0x1B).'G');
define('VDT_BGBLACK', chr(0x1B).'P');
define('VDT_BGRED', chr(0x1B).'Q');
define('VDT_BGGREEN', chr(0x1B).'R');
define('VDT_BGYELLOW', chr(0x1B).'S');
define('VDT_BGBLUE', chr(0x1B).'T');
define('VDT_BGMAGENTA', chr(0x1B).'U');
define('VDT_BGCYAN', chr(0x1B).'V');
define('VDT_BGWHITE', chr(0x1B).'W');
define('VDT_BLINK', chr(0x1B).'H');
define('VDT_FIXED', chr(0x1B).'I');
define('VDT_STOPUNDERLINE', chr(0x1B).'Y');
define('VDT_STARTUNDERLINE', chr(0x1B).'Z');
define('VDT_FDNORM', chr(0x1B).'\\');
define('VDT_FDINV', chr(0x1B).']');

define('PRO_MIN',chr(0x1B).chr(0x3A).chr(0x69).chr(0x45));
define('PRO_MAJ',chr(0x1B).chr(0x3A).chr(0x6A).chr(0x45));
define('PRO_LOCALECHO_OFF',chr(0x1B).chr(0x3B).chr(0x60).chr(0x58).chr(0x51));
define('PRO_LOCALECHO_ON',chr(0x1B).chr(0x3B).chr(0x61).chr(0x58).chr(0x51));


// Touche de fonctione acceptables pour une saisie utilisateur
define('MSK_SOMMAIRE', 1);
define('MSK_ANNULATION', 2);
define('MSK_RETOUR', 4);
define('MSK_REPETITION', 8);
define('MSK_GUIDE', 16);
define('MSK_CORRECTION', 32);
define('MSK_SUITE', 64);
define('MSK_ENVOI', 128);


class MiniPaviCli {
	
	static public $uniqueId='';		// Identifiant unique de la connexion
	static public $remoteAddr='';	// IP de l'utilisateur ou "CALLFROM xxxx" (xxx = numéro tel) si accès par téléphone
	static public $content=array();	// Contenu saisi
	static public $fctn='';			// Touche de fonction utilisée (ou CNX ou FIN)
	static public $urlParams='';	// Paramètres fournis lors de l'appel à l'url du service
	static public $context='';		// 65000 caractres libres d'utilisation et rappellés à chaque accès.
	static public $typeSocket;		// Type de connexion ('websocket' ou 'other')
	
	/*************************************************
	// Reçoit les données envoyées depuis MiniPavi
	**************************************************/
	
	static function start() {
		if (strpos(@$_SERVER['HTTP_USER_AGENT'],'MiniPAVI') === false) {
			$currentUrl = (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
			$redirectUrl = 'http://www.minipavi.fr/emulminitel/indexws.php?url='.urlencode('wss://go.minipavi.fr:8181?url='.$currentUrl);
			header("Location: $redirectUrl");
			exit;
		}
		$rawPostData = file_get_contents("php://input");
		try {
			$requestData = json_decode($rawPostData,false,5,JSON_THROW_ON_ERROR);
				
			self::$uniqueId = @$requestData->PAVI->uniqueId;
			self::$remoteAddr = @$requestData->PAVI->remoteAddr;
			self::$typeSocket = @$requestData->PAVI->typesocket;
			self::$content = @$requestData->PAVI->content;
			self::$context = @$requestData->PAVI->context;
			self::$fctn = @$requestData->PAVI->fctn;
			if (isset($requestData->URLPARAMS))
				self::$urlParams = @$requestData->URLPARAMS;

		}  catch (Exception $e) {
			throw new Exception('Erreur json decode '.$e->getMessage());
		}
	}
	
	/*************************************************
	// Envoi des données à MiniPavi 
	// content: contenu de la page vdt
	// next: prochaine url à être appellée après saisie de l'utilisateur (ou déconnexion)
	// echo: active l'echo
	// commande: envoi une commande à MiniPavi
	// directcall: appel directement la prochaine url sans attendre une action utilisateur (limité à 1 utilisation à la fois)
	**************************************************/
	
	static function send($content,$next,$context='',$echo=true,$cmd=null,$directCall=false) {
		$rep['version']=VERSION;
		$rep['content']=@base64_encode($content);
		$rep['context']=mb_convert_encoding(mb_substr($context,0,65000),'UTF-8');
		if ($echo)	$rep['echo']='on';
		else $rep['echo']='off';
		if ($directCall)	$rep['directcall']='yes';
		else $rep['directcall']='no';
		
		$rep['next']=$next;
		
		if ($cmd && is_array($cmd))
			$rep = array_merge($rep,$cmd);
		$rep = json_encode($rep);
		trigger_error("[MiniPaviCli] ".$rep);
		echo $rep."\n";
	}
	
	/*************************************************
	// Cré une commande 'InputTxt' de saisie d'une ligne, validée par Envoi
	// posX: position X du champs de saisie
	// posY: position Y du champs de saisie
	// length: longueur du champs de saisie
	// validWith: valide la saisie uniquement avec les touches de fonctions indiquées
	// cursor: active l'affichage du curseur
	// spaceChar: caractère utilisée pour afficher le champs de saisie
	// char: caractère à afficher à chaque saisie (à la place du caractère saisi)
	// preFill: texte de pré-remplissage
	**************************************************/
	
	static function createInputTxtCmd($posX=1,$posY=1,$length=1,$validWith=MSK_ENVOI,$cursor=true,$spaceChar=' ',$char='',$preFill='') {
		$posX = (int)$posX;
		$posY = (int)$posY;
		$length = (int)$length;
		
		if ($posX<1 || $posX>40)
			$posX=1;
		if ($posY<0 || $posY>24)
			$posY=1;
		$maxLength = 41 - $posX;
		if ($length<1 || $length>$maxLength)
			$length=1;
		
		if (isset($preFill) && mb_strlen($preFill)>$length)
			$preFill = mb_substr($preFill,0,$length);
		else if (!isset($preFill)) $preFill='';
		
		$cmd=array();
		$cmd['COMMAND']['name']='InputTxt';
		$cmd['COMMAND']['param']['x']=$posX;
		$cmd['COMMAND']['param']['y']=$posY;
		$cmd['COMMAND']['param']['l']=$length;
		$cmd['COMMAND']['param']['char']=$char;
		$cmd['COMMAND']['param']['spacechar']=$spaceChar;
		$cmd['COMMAND']['param']['prefill']=$preFill;
		if ($cursor)
			$cmd['COMMAND']['param']['cursor']='on';
		else $cmd['COMMAND']['param']['cursor']='off';
		$cmd['COMMAND']['param']['validwith']=(int)$validWith;
		return $cmd;
	}

	
	/*************************************************
	// Cré une commande 'InputMsg' de saisie d'une ligne, validée par n'mporte quelle touche de fonction (sauf Annulation et Correction)
	// posX: position X de la zone de saisie
	// posY: position Y de la zone de saisie
	// w: longueur de la zone de saisie
	// h: hauteur de la zone de saisie
	// validWith: valide la saisie uniquement avec les touches de fonctions indiquées	
	// cursor: active l'affichage du curseur
	// spaceChar: caractère utilisée pour afficher la zone de saisie
	// preFill: tableau du texte de pré-remplissage de chaque ligne
	**************************************************/
	
	static function createInputMsgCmd($posX=1,$posY=1,$width=1,$height=1,$validWith=MSK_ENVOI,$cursor=true,$spaceChar=' ',$preFill=array()) {
		$posX = (int)$posX;
		$posY = (int)$posY;
		$width = (int)$width;
		$height = (int)$height;
		if (!is_array($preFill))
			$preFill = array();
		
		if ($posX<1 || $posX>40)
			$posX=1;
		if ($posY<1 || $posY>24)
			$posY=1;
		
		$maxWidth = 41 - $posX;
		if ($width<1 || $width>$maxWidth)
			$width=$maxWidth;
		
		$maxHeight = 25 - $posY;
		if ($height<1 || $height>$maxHeight)
			$height=$maxHeight;
		if (is_array($preFill) && count($preFill)>0) {
			array_splice($preFill, $height);
			foreach($preFill as $numLine=>$line) {
				$preFill[$numLine] = mb_substr($line,0,$width);
			}
		}
		
		$cmd=array();
		$cmd['COMMAND']['name']='InputMsg';
		$cmd['COMMAND']['param']['x']=$posX;
		$cmd['COMMAND']['param']['y']=$posY;
		$cmd['COMMAND']['param']['w']=$width;
		$cmd['COMMAND']['param']['h']=$height;
		$cmd['COMMAND']['param']['spacechar']=$spaceChar;
		$cmd['COMMAND']['param']['prefill']=$preFill;
		if ($cursor)
			$cmd['COMMAND']['param']['cursor']='on';
		else $cmd['COMMAND']['param']['cursor']='off';
		$cmd['COMMAND']['param']['validwith']=(int)$validWith;
		return $cmd;
	}


	
	/*************************************************
	// Envoi un message push en ligne "0" aux utilisateurs désignés
	// tMessage: tableau des messages à envoyer
	// tUniqueId: tableau des identifiants uniques des destinataires
	**************************************************/
	
	static function createPushServiceMsgCmd($tMessage=array(),$tUniqueId=array()) {
		if (!is_array($tMessage) || count($tMessage)<1)
			return false;
		if (!is_array($tUniqueId) || count($tUniqueId)<1)
			return false;
		$cmd=array();
		$cmd['COMMAND']['name']='PushServiceMsg';
		$cmd['COMMAND']['param']['message'] = array();
		$cmd['COMMAND']['param']['uniqueids'] = array();
		$k=0;
		foreach($tMessage as $key=>$message) {
			$cmd['COMMAND']['param']['message'][$k]=$message;
			$cmd['COMMAND']['param']['uniqueids'][$k]=$tUniqueId[$key];
			$k++;
		}
	
		return $cmd;
	}


	/*************************************************
	// Demande d'appel par MiniPavi d'une url
	// à un instant donné
	// tUrl: tableau des url à appeller
	// tTime: tableau des timestamp
	**************************************************/

	static function createBackgroundCallCmd($tUrl=array(),$tTime=array()) {
		if (!is_array($tUrl) || count($tUrl)<1)
			return false;
		if (!is_array($tTime) || count($tTime)<1)
			return false;
		$cmd=array();
		$cmd['COMMAND']['name']='BackgroundCall';
		$cmd['COMMAND']['param']['url'] = array();
		$cmd['COMMAND']['param']['time'] = array();
		$k=0;
		foreach($tUrl as $key=>$url) {
			$cmd['COMMAND']['param']['url'][$k]=$url;
			$cmd['COMMAND']['param']['time'][$k]=$tTime[$key];
			$k++;
		}
	
		return $cmd;
	}


	
	/*************************************************
	// Positionne le curseur de l'utilisateur
	**************************************************/

	static function setPos($col,$line) {
		// col : de 1 a 40
		// line : de 0 à 24
		return VDT_POS.chr(64+$line).chr(64+$col);
	}

	
	/*************************************************
	// Ecrit en ligne 0 puis le curseur revient à la position courante
	**************************************************/
	
	static function writeLine0($txt) {
		$txt = self::toG2($txt);
		$vdt = self::setPos(1,0).$txt.VDT_CLRLN."\n";
		return $vdt;
	}

	

	/*************************************************
	// Efface totalement l'écran
	**************************************************/

	static function clearScreen() {
			$vdt = self::setPos(1,0).' '.self::repeatChar(' ',39).self::setPos(1,1).VDT_CLR.VDT_CUROFF;
			return $vdt;
		
	}

	
	/*************************************************
	// Repète num fois le caractère char
	**************************************************/

	static function repeatChar($char,$num) {
		return $char.VDT_REP.chr(63+$num);
	}
	
	/*************************************************
	// Ecrit un texte centré, précédé des attributs $attr
	**************************************************/	
	
	static function writeCentered($line,$text,$attr='') {
		$vdt = self::setPos(ceil((40-mb_strlen($text))/2),$line);
		$vdt.= $attr.self::toG2($text);
		return $vdt;
	}

	/*************************************************
	// Conversion de caractères spéciaux
	**************************************************/

	static function toG2($str) {
		$str=mb_ereg_replace("’","'", $str);
		$str=preg_replace('/[\x00-\x1F\x81\x8D\x8F\x90\x9D]/', ' ', $str);

		$tabAcc=array('/é/','/è/','/à/','/ç/','/ê/','/É/','/È/','/À/','/Ç/','/Ê/',
		'/β/','/ß/','/œ/','/Œ/','/ü/','/û/','/ú/','/ù/','/ö/','/ô/','/ó/','/ò/','/ï/','/î/','/í/','/ì/','/ë/','/ä/',
		'/â/','/á/','/£/','/°/','/±/','/←/','/↑/','/→/','/↓/','/¼/','/½/','/¾/','/Â/');
		
		$tabG2=array(VDT_G2.chr(0x42).'e',
		VDT_G2.chr(0x41).'e',
		VDT_G2.chr(0x41).'a',
		VDT_G2.chr(0x4B).chr(0x63),
		VDT_G2.chr(0x43).'e',
		VDT_G2.chr(0x42).'E',
		VDT_G2.chr(0x41).'E',
		VDT_G2.chr(0x41).'A',
		VDT_G2.chr(0x4B).chr(0x63),
		VDT_G2.chr(0x43).'E',
		VDT_G2.chr(0x7B),		
		VDT_G2.chr(0x7B),		
		VDT_G2.chr(0x7A),		
		VDT_G2.chr(0x6A),		
		VDT_G2.chr(0x48).chr(0x75),		
		VDT_G2.chr(0x43).chr(0x75),		
		VDT_G2.chr(0x42).chr(0x75),		
		VDT_G2.chr(0x41).chr(0x75),		
		VDT_G2.chr(0x48).chr(0x6F),		
		VDT_G2.chr(0x43).chr(0x6F),		
		VDT_G2.chr(0x42).chr(0x6F),		
		VDT_G2.chr(0x41).chr(0x6F),		
		VDT_G2.chr(0x48).chr(0x69),		
		VDT_G2.chr(0x43).chr(0x69),		
		VDT_G2.chr(0x42).chr(0x69),		
		VDT_G2.chr(0x41).chr(0x69),		
		VDT_G2.chr(0x48).chr(0x65),		
		VDT_G2.chr(0x48).chr(0x61),		
		VDT_G2.chr(0x43).chr(0x61),		
		VDT_G2.chr(0x42).chr(0x61),
		VDT_G2.chr(0x23),		
		VDT_G2.chr(0x30),		
		VDT_G2.chr(0x31),		
		VDT_G2.chr(0x2C),		
		VDT_G2.chr(0x2D),		
		VDT_G2.chr(0x2E),		
		VDT_G2.chr(0x2F),		
		VDT_G2.chr(0x3C),		
		VDT_G2.chr(0x3D),		
		VDT_G2.chr(0x3E),
		VDT_G2.chr(0x43).'A'
		);
		
		return preg_replace($tabAcc, $tabG2, $str);	
	}
	
}
?>