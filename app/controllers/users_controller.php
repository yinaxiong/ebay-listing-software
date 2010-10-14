<?
/**
 * todo:
 * - scrollbar appear when item detail expanded, width broken. [FIXED]
 * - use html5.
 * - unify json response to one code.
 * - i18n.
 * - scheduled additems by crontab.
 * - compress response body
 *
 */
class UsersController extends AppController {
	
	var $name = 'Users';    
	var $components = array('Auth', 'Email');
	//var $helpers = array('Cache');
	//var $cacheAction = null;
	
	var $user;
	var $accounts;
	var $userids;
	var $filter;
	
	function beforeFilter()
	{
		error_log($this->here);
		if (!empty($_POST)) error_log('POST:'.print_r($_POST,1));
		parent::beforeFilter();
		
        $this->Auth->allow('index', 'register', 'receivenotify');
		$this->Auth->loginRedirect = array('controller' => 'users', 'action' => 'home');
		$this->Auth->fields = array('username' => 'email',  'password' => 'password');
		
		if ($userauth = $this->Auth->user()) {
			
			$query['email'] = $userauth['User']['email'];
			$this->user = $this->mongo->ebay->users->findOne($query);
			$this->set('user', $this->user);
			
			//Configure::write('Config.language', $this->user['User']['language']);
		}
		
		return;
    }	

	function test()
	{
		
	}
	
	function receivenotify()
	{
		$xml = file_get_contents('php://input');
		
		$resfile = ROOT.'/app/tmp/apilogs/'.(9999999999-date("mdHis")).'.notify.xml';
		file_put_contents($resfile, $xml);
		chmod($resfile, 0777);
		
		$xml = preg_replace("/^.*<soapenv:Body>/s", "", $xml);
		$xml = preg_replace("/<\/soapenv:Body>.*$/s", "", $xml);
		$xmlobj = simplexml_load_string($xml);
		error_log($xmlobj->RecipientUserID
				  . ':'.$xmlobj->NotificationEventName
				  . ':'.$xmlobj->Item->ItemID);
		
		exit;
	}
	
	function index()
	{
		if (isset($this->user['email'])) {
			
			$sites = $this->sitedetails();
			foreach ($sites as $sitename => $siteid) {
				
				$hash[$sitename]['SiteID'] = $siteid;
				
				$hash[$sitename]['category']['name']          = array();
				$hash[$sitename]['category']['grandchildren'] = array();
				$hash[$sitename]['category']['features']      = array();
				
				$categorydata = $this->children($sitename, 0);
				$hash[$sitename]['category']['children'] = $categorydata['children'];
				if (isset($categorydata['name'])) {
					$hash[$sitename]['category']['name'] = $categorydata['name'];
				}
				
				/* Shipping */
				$hash[$sitename]['ShippingType'] = $this->getShippingType($sitename);
				
				// todo: get only frequentry used site by user.
				//if ($sitename != 'US') continue;
				
				
				$hash[$sitename]['ShippingServiceDetails']
					= $this->getShippingServiceDetails($sitename);
				
				$hash[$sitename]['ShippingPackageDetails']
					= $this->getShippingPackageDetails($sitename);
			}
			//$hash['shippingmap'] = $this->getshippingmap();
			
			$this->set('hash', $hash);
			$this->set('summary', $this->getsummary());
			$this->render('home');
		}
	}
	
	function home()
	{
        $this->redirect('/');
	}
	
	
	/**
	 * get summary data of items.
	 * todo: deleted items and empty trash function
	 * todo: use ebayuserid instead of accountid?
	 */
	function items()
	{
		$selling = $this->getsellingquery();
		
		$limit  = empty($_POST["limit"])  ? 10 : $_POST["limit"];
		$offset = empty($_POST["offset"]) ?  0 : $_POST["offset"];
		
		$query['UserID']['$in'] = array_keys($this->user['userids']);
		
		if (!empty($_POST['id']))     $query['_id']    = $_POST['id'];
		if (!empty($_POST['ItemID'])) $query['ItemID'] = $_POST['ItemID'];
		if (!empty($_POST['UserID'])) $query['UserID'] = $_POST["UserID"];
		if (!empty($_POST["Title"]))  $query['Title']  = new MongoRegex('/'.$_POST["Title"].'/');
		if (!empty($_POST['selling'])) $query = $query + $selling[$_POST['selling']];
		
		$fields['UserID'] = 1;
		$fields['ItemID'] = 1;
		$fields['Title'] = 1;
		$fields['Site'] = 1;
		$fields['StartPrice'] = 1;
		$fields['ListingDetails.ViewItemURL'] = 1;
		$fields['ListingDetails.EndTime'] = 1;
		$fields['PictureDetails.PictureURL'] = 1;
		$fields['SellingStatus.ListingStatus'] = 1;
		$fields['SellingStatus.CurrentPrice'] = 1;
		$fields['SellingStatus.CurrentPrice@currencyID'] = 1;
		$fields['status'] = 1;
		
		$count = $this->mongo->ebay->items->count($query);
		$cursor = $this->mongo->ebay->items->find($query, $fields)->limit($limit)->skip($offset)->sort(array("ListingDetails.EndTime" => -1));
		$tmparr = iterator_to_array($cursor);
		error_log('itemsres:'.print_r($tmparr,1));
		foreach ($tmparr as $id => $row) {
			
			/* startprice */
			$tmparr[$id]['StartPrice'] = number_format($row['StartPrice']['#text']);
			/*
			if (isset($row['SellingStatus']['CurrentPrice'])) {
				$tmparr[$id]['price'] =
					$this->currencysymbols($row['SellingStatus']['CurrentPrice@currencyID'])
					. number_format($row['SellingStatus']['CurrentPrice']);
			}
			*/
			
			/* endtime */
			if (isset($row['ListingDetails']['EndTime'])) {
				if (date('Y-m-d', strtotime($row['ListingDetails']['EndTime'])) == date('Y-m-d')) {
					$tmparr[$id]['endtime'] =
						date('H:i', strtotime($row['ListingDetails']['EndTime']));
				} else {
					$tmparr[$id]['endtime'] =
						date('M j', strtotime($row['ListingDetails']['EndTime']));
				}
			} else {
				$tmparr[$id]['endtime'] = '-';
			}

			
		}
		
		$data['cnt'] = $count;
		if (isset($tmparr)) $data['res'] = $tmparr;
		echo json_encode($data);
		
		exit;
	}
	
	
	/**
	 * get detail data of one item.
	 */
	function item()
	{
		//$mongo = new Mongo();
		
		$query['UserID']['$in'] = array_keys($this->user['userids']);
		$query['_id'] = new MongoID($_POST['id']);
		
		$item = $this->mongo->ebay->items->findOne($query);
		error_log(print_r($item,1));
		
		$item['_id'] = $_POST['id'];
		
		if (!empty($item['ShippingDetails']['ShippingType']))
			$item['shippingtype'] = $this->getshippingtypelabel
				($item['Site'], $item['ShippingDetails']['ShippingType']);
		
		$site = $item['Site'];
		$categoryid = $item['PrimaryCategory']['CategoryID'];
		if ($categoryid > 0) {
			//$row['categoryfeatures'] = $this->categoryfeatures($site, $categoryid);
			
			$categorypath = $this->categorypath($site, $categoryid);
			$item['categorypath'] = array_keys($categorypath);
		}
		
		//error_log(print_r($item,1));
		//error_log(json_encode($item));
		echo json_encode($item);
		
		exit;
		
		//$row['other']['site'] = $this->sitedetails();
		//$row['other']['shipping'] = $this->getshippingservice($row['Site']);
	}
	
	
	/**
	 * upload image file into web server.
	 * todo: various error check
	 */
	function upload()
	{
		if (isset($_FILES) && is_array($_FILES)) {
			foreach ($_FILES as $fname => $arr) {
				if ($arr['error'] != 0) continue;
				
				preg_match('/^PD_PURL_([\w]+)_([\d]+)$/', $fname, $matches);
				$id  = $matches[1];
				$num = $matches[2];
				
				preg_match('/([^\.]+)$/', $arr['name'], $matches);
				$ext = $matches[1];
				$savename = $fname.'_'.date('YmdHis').'.'.$ext;
				
				move_uploaded_file($arr['tmp_name'], ROOT.'/app/webroot/itemimg/'.$savename);
				
				$arrurl[$num] = 'http://localhost/itemimg/'.$savename;
				
				error_log($fname);
				
				$this->set('id', $id);
				$this->set('arrurl', $arrurl);
			}
		}
		
		$this->layout = null;
		
	}
	
	// todo: check accountid
	function getsellerlist($ebayuserid)
	{
		system(ROOT.'/shells/kickdaemon.sh getsellerlist '.$ebayuserid);
		exit;
	}
	
	function description($id)
	{
		$query['UserID']['$in'] = array_keys($this->user['userids']);
		$query['_id'] = new MongoID($id);
		
		$field['Description'] = 1;
		
		$item = $this->mongo->ebay->items->findOne($query, $field);
		//error_log('desc:'.print_r($item,1));
		
		echo '<html><body style="margin:0px; padding:0px;">';
		echo $item['Description'];
		echo '</body></html>';
		
		exit;
	}
	

	/**
	 * login
	 */
    function login() {
		
    }


	/**
	 * logout
	 */
    function logout() {
        $this->Auth->logout();
        $this->redirect('/');
    }
	

	/**
	 * register new user.
	 */
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
	
	
	/**
	 * callback from ebay oauth flow.
	 */
	function accept()
	{
		if ($user = $this->Auth->user()) {
			$query['email'] = $user['User']['email'];
			$values['$set']['userids.'.$_GET['username']] = $_GET;
			$this->mongo->ebay->users->update($query, $values);
		}
	}
	
	function reject()
	{
		
	}
	
	
	/**
	 * copy items
	 */
	function copy()
	{
		if (empty($_POST['id'])) return;
		
		foreach ($_POST['id'] as $str) {
			$ids[] = new MongoID($str);
		}
		
		$query['UserID']['$in'] = array_keys($this->user['userids']);
		$query['_id']['$in'] = $ids;
		$cursor = $this->mongo->ebay->items->find($query);
		$tmparr = iterator_to_array($cursor);
		foreach ($tmparr as $id => $row) {
			unset($row['ItemID']);
			unset($row['_id']); // todo: if set, is record overwritten?
			//error_log('copy:'.print_r($row,1));
			$this->mongo->ebay->items->insert($row);
		}
		
		$copycount = count($_POST['id']);
		
		$_POST = null;
		$_POST['limit'] = $copycount;
		$this->items();
		
		exit;
	}
	
	
	function save()
	{
		if (empty($_POST['id'])) return;
		
		$item = $_POST;
		
		$query['_id'] = new MongoID($_POST['id']);
		$set['$set'] = $item;
		$this->mongo->ebay->items->update($query, $set);
		
		$_POST = null;
		$_POST['id'] = $item['id'];
		
		$this->item();
		
		exit;
	}
	
	
	/**
	 * delete items. (move into trash)
	 */
	function delete()
	{
		if (empty($_POST['id'])) return;
		
		foreach ($_POST['id'] as $str) {
			$ids[] = new MongoID($str);
		}
		
		$mongo = new Mongo();
		
		$query['UserID']['$in'] = $this->userids;
		$query['_id']['$in'] = $ids;
		
		$set['$set']['deleted'] = 1;
		
		$mongo->ebay->items->update($query, $set, array('multiple' => 1));
		
		exit;
	}
	
	
	function relist()
	{
		if (empty($_POST['id'])) return;
		
		$time = date('YmdHis');
		
		foreach ($_POST['id'] as $str) {
			$ids[] = new MongoID($str);
		}
		
		$query['UserID']['$in'] = array_keys($this->user['userids']);
		$query['_id']['$in'] = $ids;
		$query['status']['$exists'] = false; // todo: try $exists, $ne:'', etc...
		$set['$set']['status'] = $time.'.(re)list';
		$this->mongo->ebay->items->update($query, $set, array('multiple' => 1));
		
		return;
	}
	
	
	/**
	 * get hierarchical path data of specific category and its parents.
	 */
	function categorypath($site, $categoryid)
	{
		eval('$coll = $this->mongo->ebay->Categories_'.$site.';');
		
		$path = null;
		
		$parentid = $categoryid;
		while (true) {
			$query = null;
			$query['CategoryID'] = $parentid;
			$row = $coll->findOne($query);
			
			$path[$row['CategoryID']] = $row['CategoryName'];
			
			if ($row['CategoryLevel'] == 1) break;
			$parentid = $row['CategoryParentID'];
		}
		if (is_array($path)) ksort($path);
		
		return $path;
	}
	
	function grandchildren($site, $pathstr)
	{
		$start = date('H:i:s');
		$data['name'] = array();
		$data['children'] = array();
		$data['grandchildren'] = array();
		$arrpath = explode('.', $pathstr);
		foreach ($arrpath as $i => $categoryid) {
			$data['grandchildren'][$categoryid] = 1;
			$p = $this->children($site, $categoryid);
			if (empty($p['children']) || $p['children'] == 'leaf') continue;
			foreach ($p['children'][$categoryid] as $i => $childid) {
				$c = $this->children($site, $childid);
				if (isset($c['children']) && is_array($c['children'])) {
					foreach ($c['children'] as $cid => $carr) {
						$data['children'][$cid] = $carr;
					}
				}
				if (isset($c['name'])) {
					foreach ($c['name'] as $cid => $cname) {
						$data['name'][$cid] = $cname;
					}
				}
			}
		}
		
		echo json_encode($data);
		$end = date('H:i:s');
		error_log('grandchildren '.$start.' '.$end);
		//error_log(print_r($data,1));
		exit;
	}
	
	function children($site, $categoryid)
	{
		eval('$coll = $this->mongo->ebay->Categories_'.$site.';');
		
		$data = null;
		if ($categoryid) {
			$query['CategoryParentID'] = $categoryid;
			$query['CategoryID']['$ne'] = $categoryid;
		} else {
			$query['CategoryLevel'] = "1";
		}
		$cursor = $coll->find($query);
		$rows = iterator_to_array($cursor);
		if (count($rows)) {
			foreach ($rows as $i => $row) {
				$data['children'][$categoryid][]  = $row['CategoryID'];
				$data['name'][$row['CategoryID']] = $row['CategoryName'];
				
				if (isset($row['LeafCategory']))
					$data['children'][$row['CategoryID']] = 'leaf';
			}
		} else {
			$data['children'] = 'leaf';
		}
		
		return $data;
	}
	
	function getShippingServiceDetails($sitename)
	{
		$xml = $this->readbz2xml(ROOT.'/data/apixml/ShippingServiceDetails/'.$sitename.'.xml.bz2');
		
		$xmlo = $xml->xpath("/ns:GeteBayDetailsResponse"
							. "/ns:ShippingServiceDetails"
							. "[ns:ValidForSellingFlow='true']");
		
		$arr = $this->xml2array($xmlo);
		foreach ($arr['ShippingServiceDetails'] as $i => $o) {
			$id = $o['ShippingService'];
			$arr2[$id] = $o;
		}
		//error_log(print_r($arr2,1));
		
		return $arr2;
	}
	
	function getShippingPackageDetails($sitename)
	{
		$xml = $this->readbz2xml(ROOT.'/data/apixml/ShippingPackageDetails/'.$sitename.'.xml.bz2');
		$arr = $this->xml2array($xml);
		if (isset($arr['ShippingPackageDetails'])) {
			return $arr['ShippingPackageDetails'];
		} else {
			return null;
		}
	}
	
	
	/**
	 *
	 * todo: inherit features from its all parents.
	 */
	function categoryfeatures($site, $categoryid=null)
	{
		$xml = $this->readbz2xml(ROOT.'/data/apixml/CategoryFeatures/'.$site.'.xml.bz2');
		$ns = $xml->getDocNamespaces();
		
		/* DuationSet */
		$xmlobj_ld = $xml->xpath('/ns:GetCategoryFeaturesResponse'
								 . '/ns:FeatureDefinitions'
								 . '/ns:ListingDurations'
								 . '/ns:ListingDuration');
		foreach ($xmlobj_ld as $i => $o) {
			$attr = $o->attributes();
			$setid = $attr['durationSetID'].'';
			$dur = $o->children($ns['']);
			
			$a = null;
			foreach ($dur as $j => $v) {
				$v = $v.''; // todo: cast string
				if (preg_match('/^Days_([\d]+)$/', $v, $matches)) {
					$a[$v] = $matches[1].' Days';
				} else if ($v == 'GTC') {
					$a[$v] = "Good 'Til Cancelled";
				}
			}
			$durationset[$setid] = $a;
		}
		//echo '<pre>'.print_r($durationset,1).'</pre>'; exit;
		
		/* SiteDefaults */
		$sdns = '/ns:GetCategoryFeaturesResponse/ns:SiteDefaults';
		
		$xmlobj_sd = $xml->xpath($sdns.'/ns:ListingDuration');
		foreach ($xmlobj_sd as $i => $o) {
			$attr = $o->attributes();
			$type = $attr['type'].'';
			$typedefault[$type] = $o.'';
		}
		
		$xmlobj_pm = $xml->xpath($sdns.'/ns:PaymentMethod');
		foreach ($xmlobj_pm as $i => $o) {
			if ($o.'' == 'CustomCode') continue;
			$arrpm[] = $o.'';
		}
		
		/* overwrite by child nodes */
		$path = null;
		if ($categoryid) {
			$path = $this->categorypath($site, $categoryid);
		}
		if (is_array($path)) {
			foreach ($path as $cid => $cname) {
				
				$cns = "/ns:GetCategoryFeaturesResponse/ns:Category[ns:CategoryID=".$cid."]";
				
				$ld = $xml->xpath($cns."/ns:ListingDuration");
				if ($ld) {
					foreach ($ld as $i => $o) {
						$attr = $o->attributes();
						$type = $attr['type'].'';
						$typedefault[$type] = $o.'';
					}
				}
				
				$pm = $xml->xpath($cns."/ns:PaymentMethod");
				if ($pm) {
					$tmppm = null;
					foreach ($pm as $i => $o) {
						if ($o.'' == 'CustomCode') continue;
						$tmppm[] = $o.'';
					}
					$arrpm = $tmppm;
				}
			}
		}
		
		/* result  */
		foreach ($typedefault as $type => $setid) {
			$data['ListingDuration'][$type] = $durationset[$setid];
		}
		// it's tricky!
		$data['ListingDuration']['Chinese'] =
			array('Days_1' => '1 Day') + $data['ListingDuration']['Chinese'];
		
		$data['PaymentMethod'] = $arrpm;
		//error_log(print_r($data,1));
		
		$res['features'][$categoryid] = $data;
		echo json_encode($res);
		exit;
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
	
	function apixml($dir, $site)
	{
		$xml = $this->readbz2xml(ROOT.'/data/apixml/'.$dir.'/'.$site.'.xml.bz2');
		echo '<pre>'.print_r($xml,1).'</pre>';
		exit;
	}

	function getsummary()
	{
		$mongo = new Mongo();
		
		$selling = $this->getsellingquery();
		
		//error_log('strt:'.date('H:i:s'));
		/* summary of all accounts */
		foreach ($selling as $name => $query) {
			$query['UserID']['$in'] = array_keys($this->user['userids']);
			$msummary['all'][$name] = $mongo->ebay->items->count($query);
		}
		
		/* each accounts */
		foreach ($this->user['userids'] as $userid => $userobj) {
			foreach ($selling as $name => $query) {
				$query['UserID'] = $userid;
				$msummary[$userid][$name] = $mongo->ebay->items->count($query);
			}
		}
		//error_log('done:'.date('H:i:s'));
		
		return $msummary;
	}
	
	// todo: check UK:courier?
	function getShippingType($site)
	{
		if ($site == 'US') {
			$data['domestic']['Flat']       = 'Flat: same cost to all buyers';
			$data['domestic']['Calculated'] = 'Calculated: Cost varies by buyer location';
			$data['domestic']['Freight']    = 'Freight: large items over 150 lbs.';
			$data['domestic']['NoShipping'] = 'No shipping: Local pickup only';
		} else {
			$data['domestic']['Calculated'] = 'Calculated: Cost varies by buyer location';
			$data['domestic']['Flat']       = 'Flat: same cost to all buyers';
			$data['domestic']['NoShipping'] = 'No shipping: Local pickup only';
		}
		
		$data['international']['Flat']       = 'Flat: same cost to all buyers';
		$data['international']['Calculated'] = 'Calculated: Cost varies by buyer location';
		$data['international']['NoShipping'] = 'No international shipping';
		
		if ($this->action == __FUNCTION__) {
			echo json_encode($data);
			exit;
		} else {
			return $data;
		}
	}
	
	// todo: reverse function
	function getshippingmap($type)
	{
		// todo: check "Freight" is only web?
		$data['Flat']['domestic']      = 'Flat';
		$data['Flat']['international'] = 'Flat';
		$data['Calculated']['domestic']      = 'Calculated';
		$data['Calculated']['international'] = 'Calculated';
		$data['FlatDomesticCalculatedInternational']['domestic']      = 'Flat';
		$data['FlatDomesticCalculatedInternational']['international'] = 'Calculated';
		$data['CalculatedDomesticFlatInternational']['domestic']      = 'Calculated';
		$data['CalculatedDomesticFlatInternational']['international'] = 'Flat';
		$data['FreightFlat']['domestic']      = 'Freight';
		$data['FreightFlat']['international'] = '???';
		
		if (!empty($type)) {
			return $data[$type];
		} else {
			return null;
		}
	}
	
	function getshippingtypelabel($site, $type)
	{
		$arrtype = $this->getShippingType($site);
		$map = $this->getshippingmap($type);
		
		$result['domestic'] = $arrtype['domestic'][$map['domestic']];
		$result['international'] = $arrtype['international'][$map['international']];
		
		return $result;
	}
}

?>
