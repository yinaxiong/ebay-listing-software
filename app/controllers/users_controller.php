<?
class UsersController extends AppController {
	
    var $name = 'Users';    
    var $components = array('Auth', 'Email');
	
	var $r;
	var $user;
	var $accounts;
	
	function beforeFilter() {
		
		error_log($this->action.' beforeFilter:'.print_r($_POST,1));
		
        $this->Auth->allow('index', 'register');
		$this->Auth->loginRedirect = array('controller' => 'users', 'action' => 'home');
		$this->Auth->fields = array('username' => 'email', 
									'password' => 'password');
		
		$this->user = $this->Auth->user();
		
		$this->set('user', $this->Auth->user());
		
		if (isset($this->user['User']['userid'])) {
			$this->accounts = $this->getAccounts($this->user['User']['userid']);
		}
		$this->set('accounts', $this->accounts);
		
		error_log($this->action.':'.print_r($_POST,1));
    }	
	
	function index()
	{
		if (isset($this->user['User']['userid'])) {
			$this->render('home');
		}
	}
	
	function getAccounts($userid)
	{
		$hash = null;
		
		$sql = "SELECT * FROM accounts"
			. " WHERE userid = ".$userid
			. " ORDER BY ebayuserid";
		$res = $this->User->query($sql);
		foreach ($res as $i => $arr) {
			$a = $arr['accounts'];
			$hash[$a['accountid']] = $a;
		}
		
		return $hash;
	}
	
	function home()
	{
		
	}
	
	function items()
	{
		$userid = $this->user['User']['userid'];
		
		/* check post parameters */
		$sql_filter = null;
		$sql_filter[] = "userid = ".$userid;
		
		if (!empty($_POST["id"]))
			$sql_filter[] = "id = '".mysql_real_escape_string($_POST["id"])."'";
		
		if (!empty($_POST["accountid"]))
			$sql_filter[] = "accountid = '".mysql_real_escape_string($_POST["accountid"])."'";
		
		if (!empty($_POST["Title"]))
			$sql_filter[] = "Title LIKE '%".mysql_real_escape_string($_POST["Title"])."%'";
		
		$sql_selling['Scheduled'] = "ListingDetails_StartTime > NOW()";
		$sql_selling['Active']    = "ListingDetails_EndTime > Now()";
		$sql_selling['Sold']      = "SellingStatus_QuantitySold > 0";
		$sql_selling['Unsold']    = "ListingDetails_EndTime < Now()"
			. " AND SellingStatus_QuantitySold = 0";
		$sql_selling['Other']     = "ItemID IS NULL";
		if (!empty($_POST['selling'])) 
			$sql_filter[] = $sql_selling[$_POST['selling']];
		
		$limit  = empty($_POST["limit"])  ? 10 : $_POST["limit"];
		$offset = empty($_POST["offset"]) ?  0 : $_POST["offset"];
		
		/* create sql statement */
		$sql = "SELECT SQL_CALC_FOUND_ROWS"
			. " accounts.ebayuserid,"
			. " items.id,"
			. " items.ItemID,"
			. " items.ListingDetails_EndTime,"
			. " items.ListingDetails_ViewItemURL,"
			. " items.Title,"
			. " items.PictureDetails_PictureURL,"
			. " items.StartPrice"
			. " FROM items"
			. " JOIN accounts USING (accountid)"
			. " WHERE ".implode(" AND ", $sql_filter);
		
		$sql .= " ORDER BY ListingDetails_EndTime ASC, id DESC";
		
		$sql .= " LIMIT ".$limit." OFFSET ".$offset;
		
		$res = $this->User->query($sql);
		error_log($sql);
		
		/* count total records */
		$res_cnt = $this->User->query("SELECT FOUND_ROWS() AS cnt");
		$cnt = $res_cnt[0][0]['cnt'];
		
		/* modify result records */
		foreach ($res as $idx => $row) {
			
			$id = $row['items']['id'];
			$item = $row['items'];
			$item['ebayuserid'] = $row['accounts']['ebayuserid'];
			
			/* ListingDetails_EndTime */
			if (isset($item['ListingDetails_EndTime'])) {
				if (date('Y-m-d', strtotime($item['ListingDetails_EndTime'])) == date('Y-m-d')) {
					$item['ListingDetails_EndTime'] =
						date('H:i', strtotime($item['ListingDetails_EndTime']));
				} else {
					$item['ListingDetails_EndTime'] =
						date('M j', strtotime($item['ListingDetails_EndTime']));
				}
			} else {
				$item['ListingDetails_EndTime'] = '-';
			}
			
			/* StartPrice */
			$item['StartPrice'] = number_format($item['StartPrice']);
			
			
			/* add to rows */
			$items[] = $item;
		}
		
		$data['cnt'] = $cnt;
		$data['res'] = $items;
		
		print json_encode($data);
		error_log(print_r($data,1));
		
		exit;
	}
	
	function item()
	{
		// todo: check userid and accountid
		$sql = "SELECT * FROM items WHERE id = ".$_POST['id'];
		$res = $this->User->query($sql);
		
		print json_encode($res[0]['items']);
		
		exit;
	}
	
	function description($id)
	{
		//$id = $_POST['itemid'];
		$sql = "SELECT description FROM items WHERE id = ".$id;
		$res = $this->User->query($sql);
		$html = $res[0]['items']['description'];
		
		echo $html;
		
		exit;
	}
	
    function login() {
		
    }
	
    function logout() {
        $this->Auth->logout();
        $this->redirect('/');
    }
	
	function register() {
		
		if (!empty($this->data)) {
			
			$this->User->create();
			if ($this->User->save($this->data)) {
				
				// send signup email containing password to the user
				$this->Email->from = "a@a.a";
				$this->Email->to   = "fd3s.boost@gmail.com";
				$this->Email->subject = 'testmail';
				$this->Email->send('test message 1234');
				
				$this->Auth->login($this->data);
				
				$this->redirect("home");
			}		
			
		}
	}

	function accept()
	{
		if ($user = $this->Auth->user()) {
			$sql_insert = "INSERT INTO accounts"
				. " (userid, ebayuserid, ebaytoken, ebaytokenexp, created)"
				. " VALUES ("
				. " ".$user['User']['userid'].","
				. " '".mysql_real_escape_string($_GET["username"])."',"
				. " '".mysql_real_escape_string($_GET["ebaytkn"])."',"
				. " '".mysql_real_escape_string($_GET["tknexp"])."',"
				. " NOW())";
			$res = $this->User->query($sql_insert);
		}
	}
	
	function reject()
	{
		
	}
	
	function copy()
	{
		if (empty($_POST['item'])) return;
		
		// todo: list copy column names
		$sql_copy = "INSERT INTO items ("
			. " accountid, title, description, categoryid, "
			. " startprice"
			. " ) SELECT"
			. " accountid, title, description, categoryid, "
			. " startprice"
			. " FROM items"
			. " WHERE itemid IN (".implode(",", $_POST['item']).")"
			. " ORDER BY itemid";
		$res = $this->User->query($sql_copy);
		
		// todo: separate by userid
		$sql = "SELECT itemid, ebayuserid, title"
			 . " FROM items"
			 . " JOIN accounts USING (accountid)"
			 . " ORDER BY itemid DESC"
			 . " LIMIT ".count($_POST['item']);
		$res = $this->User->query($sql);
		
		print json_encode($res);
		
		exit;
	}
	
	function update()
	{
		if (empty($_POST['id'])) return;
		
		$id = $_POST['id'];
		
		$sqlcol = null;
		foreach ($_POST as $k => $v) {
			$sqlcol[] = $k." = '".mysql_real_escape_string($v)."'";
		}
		
		$sql_update = "UPDATE items"
			. " SET ".implode(", ", $sqlcol)
			. " WHERE id = ".$id;
		error_log($sql_update);
		$res = $this->User->query($sql_update);
		
		$_POST = null;
		$_POST['id'] = $id;
		
		$this->items();
		
		exit;
	}
	
	function edit($itemid)
	{
	  if (!preg_match('/^[\d]+$/', $itemid)) return null;
	  
	  $arr = null;
	  foreach ($_POST as $k => $v) {
		$arr[] = $k." = '".mysql_real_escape_string($v)."'";
	  }
	  if (is_array($arr)) {
		$sql_update = "UPDATE items"
		  . " SET ".implode(', ', $arr)
		  . " WHERE itemid = ".$itemid;
		$res = $this->User->query($sql_update);
		
	  }
	  exit;
	}
	
	function delete()
	{
		if (empty($_POST['item'])) return;
		
		$sql = "DELETE FROM items"
			. " WHERE itemid IN (".implode(",", $_POST['item']).")";
		$res = $this->User->query($sql);
		
		exit;
	}
	
	function getitem($accountid, $ebayitemid)
	{
		$h = null;
		$h['RequesterCredentials']['eBayAuthToken'] = $this->accounts[$accountid]['ebaytoken'];
		$h['ItemID'] = $ebayitemid;
		
		$gio = $this->callapi('GetItem', $h);
		
		/*
		foreach ($gio->Item->children() as $o) {
			echo $o->getName()."[".$o."]<br>";
			//print_r($o);
		}
		*/
		
		$this->xml2arr($gio->Item, $arr, '');
		
		echo '<pre>';
		print_r($arr);
		print_r($gio->Item);
		echo '</pre>';
		
		exit;
	}
	
	function arr2xml(&$arr, $depth, $val)
	{
		if (count($depth) > 1) {
			$here = array_shift($depth);
			//$this->arr2xml($arr, $depth, $val);
			//return;
			$res[$here] = $this->arr2xml($arr, $depth, $val);
			return $res;
		} else {
			$here = $depth[0];
			//$arr[$here] = $val;
			//return;
			$res[$here] = htmlspecialchars($val);
			return $res;
		}
	}
	
	function xml2arr($xml, &$arr, $path)
	{
		foreach ($xml->children() as $child) {
			if (count($child->children())) {
				if ($path) {
					$this->xml2arr($child, $arr, $path.".".$child->getName());
				} else {
					$this->xml2arr($child, $arr, $child->getName());
				}
			} else {
				if ($path) {
					$arr[$path.".".$child->getName()] = $child.'';
				} else {
					$arr[$child->getName()] = $child.'';
				}
			}
		}
	}
	
	function additems($ids=null)
	{
		if (empty($ids) && isset($_POST['id'])) {
			
			// If called from browser, kick background process and exit
			$cmd = 'PATH=/usr/local/php/bin'
				. ' '.ROOT.'/cake/console/cake'
				. ' -app '.ROOT.'/app daemon'
				. ' additems '.implode(',', $_POST['id'])
				. ' > /dev/null &';
			system($cmd);
			error_log($cmd);
			exit;
			
		} else if (empty($ids)) {
			return;
		}
		error_log('additems:'.implode(',', $ids));
		
		// read item data from database
		$sql = "SELECT *"
			. " FROM items"
			. " JOIN accounts USING (accountid)"
			. " WHERE id IN (".implode(",", $ids).")";
		$res = $this->User->query($sql);
		foreach ($res as $i => $arr) {
			
			// todo: just for debug
			$arr['items']['Title'] = "(".$arr['items']['id'].")".$arr['items']['Title'];
			
			// todo: cut string in another way
			$arr['items']['Title'] = substr($arr['items']['Title'], 0, 55);
			
			$accountid = $arr['items']['accountid'];
			$itemdata[$accountid]['accounts'] = $arr['accounts'];
			$itemdata[$accountid]['items'][]  = $arr['items'];
		}
		
		// todo: replace with callapi method
		$headers['X-EBAY-API-COMPATIBILITY-LEVEL'] = EBAY_COMPATLEVEL;
		$headers['X-EBAY-API-DEV-NAME']  = EBAY_DEVID;
		$headers['X-EBAY-API-APP-NAME']  = EBAY_APPID;
		$headers['X-EBAY-API-CERT-NAME'] = EBAY_CERTID;
		$headers['X-EBAY-API-CALL-NAME'] = 'AddItems';
		$headers['X-EBAY-API-SITEID']    = 0;
		
		$url = EBAY_SERVERURL;
		
		$pool = new HttpRequestPool();
		
		$seqidx = 0;
		foreach ($itemdata as $accountid => $hash) {
			
			$chunked = array_chunk($hash['items'], 5);
			foreach ($chunked as $chunkedidx => $items) {
				
				// build xml
				$h = null;
				$h['RequesterCredentials']['eBayAuthToken'] = $hash['accounts']['ebaytoken'];
				$h['Version']       = EBAY_COMPATLEVEL;
				$h['ErrorLanguage'] = 'en_US';
				$h['WarningLevel']  = 'High';
				
				foreach ($items as $i => $arr) {
					$h['AddItemRequestContainer'][$i]['MessageID'] = ($i+1);
					$h['AddItemRequestContainer'][$i]['Item'] = $this->xml_item($arr);
				}
				
				$xml_request = '<?xml version="1.0" encoding="utf-8" ?>'."\n"
					. '<AddItemsRequest xmlns="urn:ebay:apis:eBLBaseComponents">'."\n"
					. $this->xml($h, 1)
					. '</AddItemsRequest>'."\n";
				
				file_put_contents('/var/www/dev.xboo.st/app/tmp/apilogs/'
								  . date("mdHis").'.AddItems.request.'
								  . $hash['accounts']['ebayuserid'].'.'.$chunkedidx.'.xml',
								  $xml_request);
				
				$r = null;
				$r = new HttpRequest();
				$r->setHeaders($headers);
				$r->setMethod(HttpRequest::METH_POST);
				$r->setUrl($url);
				$r->setRawPostData($xml_request);
				$pool->attach($r);
				
				$seqmap[$seqidx]['accountid'] = $accountid;
				$seqmap[$seqidx]['chunkeidx'] = $chunkedidx;
				$seqidx++;
			}
		}
		
		/* execute api call */
		$pool->send();
		
		$ridx = 0;
		foreach ($pool as $r) {
			
			$accountid = $seqmap[$ridx]['accountid'];
			
			$xml_response = $r->getResponseBody();
			
			file_put_contents('/var/www/dev.xboo.st/app/tmp/apilogs/'
							  . date("mdHis").'.AddItems.response.'.$ridx.'.xml',
							  $xml_response);
			
			$xmlobj = simplexml_load_string($xml_response);
			foreach ($xmlobj->AddItemResponseContainer as $i => $obj) {
			  
				$idx = ($seqmap[$ridx]['chunkeidx'] * 5) + ($obj->CorrelationID - 1);
				
				if (isset($obj->ItemID)) {
					
					$h = null;
					$h['RequesterCredentials']['eBayAuthToken'] =
						$itemdata[$accountid]['accounts']['ebaytoken'];
					$h['ItemID'] = $obj->ItemID;
					$gio = $this->callapi('GetItem', $h);
					
					$j = null;
					$j['ebayitemid']  = $obj->ItemID;
					$j['starttime']   = $obj->StartTime;
					$j['endtime']     = $obj->EndTime;
					$j['viewitemurl'] = $gio->Item->ListingDetails->ViewItemURL;
					
					$sql_u = null;
					foreach ($j as $f => $v) {
						$sql_u[] = $f." = '".mysql_real_escape_string($v)."'";
					}			  
					
					$sql = "UPDATE items"
						. " SET ".implode(', ', $sql_u)
						. " WHERE itemid = ".$itemdata[$accountid]['items'][$idx]['itemid'];
					
					error_log($sql);
					$this->User->query($sql);
					
					$result = null;
					$result['ebayitemid'] = $obj->ItemID;
					$result['starttime']  = $obj->StartTime;
					$result['endtime']    = $obj->EndTime;
					
					$itemdata[$accountid]['items'][$idx]['result'] = $result;
					
				} else if (isset($obj->Errors)) {
					
					$itemdata[$accountid]['items'][$idx]['errors'] = $obj->Errors->LongMessage;
					
				} else {
					
					$itemdata[$accountid]['items'][$idx]['exception']  = '*** ?????? ***';
					
				}
				
			}
			
			$itemdata[$accountid]['response'] = $xmlobj;
			
			$ridx++;
		}
		
		return $itemdata;
	}
	
	function xml($arr, $depth=0)
	{
		$xml = "";
		foreach ($arr as $k => $v) {
			if (is_array($v)) {
				if (array_key_exists(0, $v)) {
					foreach ($v as $j => $tmp) {
						$xml .= str_repeat("\t", $depth)."<".$k.">\n";
						$xml .= $this->xml($tmp, ($depth+1));
						$xml .= str_repeat("\t", $depth)."</".$k.">\n";
					}
				} else {
					$xml .= str_repeat("\t", $depth)."<".$k.">\n";
					$xml .= $this->xml($v, ($depth+1));
					$xml .= str_repeat("\t", $depth)."</".$k.">\n";
				}
			} else {
				$xml .= str_repeat("\t", $depth)."<".$k.">".$v."</".$k.">"."\n";
			}
		}
		
		return $xml;
	}
	
	function xml_item($i)
	{
		$xml = array();
		foreach ($i as $col => $val) {
			if (preg_match('/(^[a-z]|ListingDetails|CategoryName|ItemID)/', $col)) {
				continue;
			}
			if ($val == '') {
				continue;
			}
			$depth = explode('_', $col);
			$xml = array_merge_recursive($xml, $this->arr2xml($arr, $depth, $val));
		}
		
		return $xml;
	}
	
	function getitemcols()
	{
		$sql = "DESC items;";
		$res = $this->User->query($sql);
		foreach ($res as $i => $row) {
			$f[$row['COLUMNS']['Field']] = $row;
		}
		
		return $f;
	}
	
	// todo: authorize login user or daemon process
	function getsellerlist($ebayuserid, $userid=null)
	{
		$sql = "SELECT * FROM accounts"
			. " WHERE ebayuserid = '".mysql_real_escape_string($ebayuserid)."'";
		$res = $this->User->query($sql);
		$account = $res[0]['accounts'];
		
		$colnames = $this->getitemcols();
		
		$h = null;
		$h['RequesterCredentials']['eBayAuthToken'] = $account['ebaytoken'];
		//$h['GranularityLevel'] = 'Fine'; // Coarse, Medium, Fine
		$h['DetailLevel'] = 'ItemReturnDescription';
		//$h['DetailLevel'] = 'ReturnAll';
		$h['StartTimeFrom'] = '2010-04-01 00:00:00';
		$h['StartTimeTo']   = date('Y-m-d H:i:s');
		$h['Pagination']['EntriesPerPage'] = 200;
		$h['Sort'] = 1;
		if ($userid) {
			$h['UserID'] = $userid;
		}
		
		$xmlobj = $this->callapi('GetSellerList', $h);
		
		foreach ($xmlobj->ItemArray->Item as $idx => $o) {
			
			$this->xml2arr($o, $arr, '');
			foreach ($arr as $c => $v) {
				$c = str_replace('.','_',$c);
				if (isset($colnames[$c])) {
					//if ($c == 'TimeLeft') $v = $this->duration2str($v);
					$i[$c] = "'".mysql_real_escape_string($v)."'";
				}
			}
			echo '<pre>';
			print_r($arr);
			echo '</pre>';
			
			/* SELECT */
			// todo: catch INSERT/UPDATE query result.
			$res = $this->User->query("SELECT id FROM items WHERE ItemID = ".$i['ItemID']);
			if (!empty($res[0]['items']['id'])) {
				
				/* UPDATE */
				$sql_updates = null;
				foreach ($i as $f => $v) {
					$sql_updates[] = $f." = ".$v;
				}
				$sql_update = "UPDATE items"
					. " SET ".implode(",",$sql_updates)
					. " WHERE ItemID = ".$i['ItemID'];
				$res = $this->User->query($sql_update);
				
			} else {
				
				$i['created']   = "NOW()";
				$i['accountid'] = $account['accountid'];
				
				/* INSERT */
				$sql_insert = "INSERT INTO items"
					. " (".implode(",", array_keys($i)).")"
					. " VALUES"
					. " (".implode(",", array_values($i)).")";
				$res = $this->User->query($sql_insert);
			}
		}
		
		exit;
	}
	
	function category($id=null)
	{
	  
	}
	
	function getcategories()
	{
		$sql = "SELECT * FROM accounts"
			. " WHERE ebayuserid = 'testuser_tokyo'";
		$res = $this->User->query($sql);
		$account = $res[0]['accounts'];
		
		$h = null;
		$h['RequesterCredentials']['eBayAuthToken'] = $account['ebaytoken'];
		$h['CategorySiteID'] = 0;
		$h['DetailLevel'] = 'ReturnAll';
		
		$xmlobj = $this->callapi("GetCategories", $h);
		
		foreach ($xmlobj->CategoryArray->Category as $i => $o) {
			$line[] = "("
					. $o->CategoryID.","
					. $o->CategoryLevel.","
					. "'".mysql_real_escape_string($o->CategoryName)."',"
					. $o->CategoryParentID
					. ")";
		}
		
		$sql = "INSERT INTO categories (id, level, name, parentid)"
		  . " VALUES ".implode(',', $line);
		$res = $this->User->query($sql);
		
		print '<pre>';
		print_r($line);
		print_r($xmlobj);		
		print '</pre>';
		exit;
	}
	
	function callapi($call, $xmldata)
	{
		/* prepare */
		$headers['X-EBAY-API-COMPATIBILITY-LEVEL'] = EBAY_COMPATLEVEL;
		$headers['X-EBAY-API-DEV-NAME']  = EBAY_DEVID;
		$headers['X-EBAY-API-APP-NAME']  = EBAY_APPID;
		$headers['X-EBAY-API-CERT-NAME'] = EBAY_CERTID;
		$headers['X-EBAY-API-CALL-NAME'] = $call;
		$headers['X-EBAY-API-SITEID']    = 0;
		
		$url = EBAY_SERVERURL;
		
		$this->r = new HttpRequest();
		$this->r->setHeaders($headers);
		
		/* request */
		$xml_request = '<?xml version="1.0" encoding="utf-8" ?>'."\n"
			. '<'.$call.'Request xmlns="urn:ebay:apis:eBLBaseComponents">'."\n"
			. $this->xml($xmldata, 1)
			. '</'.$call.'Request>'."\n";
		
		file_put_contents('/var/www/dev.xboo.st/app/tmp/apilogs/'
						  . date("mdHis").'.'.$call.'.request.xml',
						  $xml_request);

		/* response */
		$xml_response = $this->post($url, $xml_request);
		$xmlobj = simplexml_load_string($xml_response);
		
		file_put_contents('/var/www/dev.xboo.st/app/tmp/apilogs/'
						  . date("mdHis").'.'.$call.'.response.xml',
						  $xml_response);
		
		return $xmlobj;
	}
	
	function get($url)
	{
		$this->r->setMethod(HttpRequest::METH_GET);
		$this->r->setUrl($url);
		$this->r->send();
		
		$html = $this->r->getResponseBody();
		
		return $html;
	}
	
	function post($url, $postdata)
	{
		$this->r->setMethod(HttpRequest::METH_POST);
		$this->r->setUrl($url);
		$this->r->setRawPostData($postdata);
		$this->r->send();
		
		$html = $this->r->getResponseBody();
		
		return $html;
	}
	
	
	/**
	 * RefURL: http://dev-forums.ebay.com/thread.jspa?threadID=500003564
	 */
	function duration2str($time, $rounding = TRUE) {
		
        preg_match("/P" .
				   "(?:(?P<year>[0-9]*)Y)?" . 
				   "(?:(?P<month>[0-9]*)M)?" .
				   "(?:(?P<day>[0-9]*)D)?" .
				   "(?:T" .
				   "(?:(?P<hour>[0-9]*)H)?" .
				   "(?:(?P<minute>[0-9]*)M)?" .
				   "(?:(?P<second>[0-9\.]*)S)?" .
				   ")?/s", $time, $d);
		
		$str = ($d['year']-0)
			. '-'.($d['month']-0)
			. '-'.($d['day']-0)
			. ' '.($d['hour']-0)
			. ':'.($d['minute']-0)
			. ':'.($d['second']-0);
		
        return $str;
    }
}
?>
