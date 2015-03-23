<?php
/*
Copyright (c) 201!, Rafael Bedia <dcat|at|trillinux.org>
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:
    * Redistributions of source code must retain the above copyright
      notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright
      notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of the organization nor the
      names of its contributors may be used to endorse or promote products
      derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/

define('VERSION', 'DKAC/Enticing-Enumon');
define('DATA_DIR', 'data');
define('UPDATE_WAIT', 55 * 60); // 55 minutes between updates
define('URL_STORE_COUNT', 20);
define('HOST_STORE_COUNT', 200);
define('HOST_EXPIRATION', 45 * 60); // 45 minutes

$request = array();
$response = array();

$networks = array('gnutella2');

getParamIsSet('get');
getParamIsSet('update');
getParamIsSet('ping');
getParam('ip');
getParam('url');
getParam('client');
getParam('net');

if ($request['get'] || $request['update'] || $request['ping']) {
	header('Content-type: text/plain');
	if ($request['client'] == '') {
		sendError('No client');
	}
	$request['net'] = validateNet($request['net']);

	$hosts = new Nuggets($request['net'], 'hosts', 'h', HOST_STORE_COUNT);
	$urls = new Nuggets($request['net'], 'urls', 'u', URL_STORE_COUNT);

	# add a fake host to work around a Shareaza bug
	if ($hosts->size() == 0) {
		$hosts->addHost('1.1.1.1:6346');
	}

	if ($request['ping']) {
		$ret = doPing();
		$response = array_merge($response, $ret);
	}
	if ($request['get']) {
		$ret = doGet();
		$response = array_merge($response, $ret);
	}
	if ($request['update']) {
		$iplist = new IPList();
		if (!$iplist->isTooEarly($_SERVER['REMOTE_ADDR'])) {
			$ret = doUpdate($request['url'], $request['ip']);
			$response = array_merge($response, $ret);
		} else {
			$response = array(createLine('i', 'update', 'WARNING', 
				'Returned too soon'));
		}
		$iplist->addIP($_SERVER['REMOTE_ADDR']);
		$iplist->storeIPs();
	}
	sendResponse($response);
} else if (isset($_GET['source'])) {
	header('Content-type: text/plain');
	readfile(__FILE__);
} else {
	// redirect to human page
?>
This is <?php echo VERSION; ?>. <a href="?source">Source</a>
<?php
}

function doPing() {
	$response = array();
	$response[] = createLine('i', 'pong', VERSION);
	return $response;
}

function doGet() {
	global $hosts, $urls;
	$response = $hosts->getList();
	$response = array_merge($response, $urls->getList());
	return $response;
}

function doUpdate($url, $ip) {
	global $hosts, $urls;
	$ret = array();
	$url = rawurldecode($url);
	$res = validateUrl($url);
	if ($res === true) {
		$urls->addHost($url);
		$ret[] = createLine('i', 'update', 'OK');
	} else if ($res != '') {
		$ret[] = createLine('i', 'update', 'WARNING', $res);
	}
	$ip = rawurldecode($ip);
	$res = validateIP($ip);
	if ($res === true) {
		$hosts->addHost($ip);
		$ret[] = createLine('i', 'update', 'OK');
	} else if ($res != '') {
		$ret[] = createLine('i', 'update', 'WARNING', $res);
	}
	return $ret;
}

class Nuggets {
	private $list;
	private $net;
	private $type;
	private $prefix;
	private $size;
	private $file;

	function __construct($net, $type, $prefix, $size) {
		$this->list = array();
		$this->net = $net;
		$this->type = $type;
		$this->prefix = $prefix;
		$this->size = $size;
		$this->file = DATA_DIR . "/{$this->net}_{$this->type}.dat";
		$this->loadHosts();
	}

	function addHost($address) {
		$nugget = new Nugget($address, time(), $this->prefix);
		if (!$this->inList($nugget)) {
			$this->list[] = $nugget;
			$this->storeHosts();
		}
	}

	function inList($toCheck) {
		foreach ($this->list as $nugget) {
			if ($nugget->equals($toCheck)) {
				return true;
			}
		}
		return false;
	}

	function getList() {
		$response = array();
		foreach ($this->list as $host) {
			$response[] = $host->toClient();
		}
		return $response;
	}

	function storeHosts() {
		$store = array();
		$max_hosts = $this->size;
		if ($this->prefix == 'u') {
			for ($i = count($this->list) - 1; $i >= 0 && count($store) < $max_hosts; $i--) {
				$store[] = $this->list[$i]->toDat() . "\n";
			}
		} else {
			// The host list will be allowed to grow as large as necessary but 
			// hosts will be expired after a time
			$now = time();
			for ($i = count($this->list) - 1; $i >= 0 && count($store) < $max_hosts; $i--) {
				if ($now - $this->list[$i]->date < HOST_EXPIRATION) {
					$store[] = $this->list[$i]->toDat() . "\n";
				}
			}
		}
		file_put_contents($this->file, array_reverse($store));
	}

	function loadHosts() {
		if (!file_exists($this->file)) return;
		$lines = file($this->file, FILE_IGNORE_NEW_LINES);
		foreach ($lines as $line) {
			$parts = explode('|', $line);
			$this->list[] = new Nugget($parts[0], $parts[1], $this->prefix);
		}
	}

	function size() {
		return count($this->list);
	}
}

class Nugget {
	public $data;
	public $date;
	public $prefix;
	function __construct($data, $date, $prefix) {
		$this->data = $data;
		$this->date = $date;
		$this->prefix = $prefix;
	}

	function equals($nugget) {
		if ( $this->data == $nugget->data ) return true;
		return false;
	}

	function toDat() {
		return createLine($this->data, $this->date);
	}

	function toClient() {
		return createLine($this->prefix, $this->data, time()-$this->date);
	}
}

class IPList {
	private $list;
	private $file;

	function __construct() {
		$this->list = array();
		$this->file = DATA_DIR . "/iplist.dat";
		$this->loadIPs();
	}

	function addIP($ip) {
		$this->list[$ip] = time();
	}

	function isTooEarly($ip) {
		if (isset($this->list[$ip])) {
			$then = $this->list[$ip];
			if (time() - $then < UPDATE_WAIT) {
				return true;
			}
		}
		return false;
	}

	function storeIPs() {
		$store = array();
		$now = time();
		foreach ($this->list as $ip => $date) {
			if ($now - $date < UPDATE_WAIT) {
				$store[] = "$ip|$date\n";
			}
		}
		file_put_contents($this->file, $store);
	}

	function loadIPs() {
		if (!file_exists($this->file)) return;
		$lines = file($this->file, FILE_IGNORE_NEW_LINES);
		foreach ($lines as $line) {
			$parts = explode('|', $line);
			$this->list[$parts[0]] = $parts[1];
		}
	}

}

function validateUrl(&$url) {
	if ($url == '') return '';
	if (!preg_match('/^http:\/\/(?P<domain>[-a-z0-9.]+)(?::(?P<port>\\d+))?' .
		'(?P<file>\/[-a-z0-9+&@#\/%=~_|!:,.;]*)?/D', $url)) {
		return 'Invalid URL';	
	}
	if (stripos($url, 'nyud.net') !== false) {
		return 'Rejected URL';
	}
	if (stripos($url, 'nyucd.net') !== false) {
		return 'Rejected URL';
	}
	if (stripos($url, 'nonexiste.net') !== false) {
		return 'Rejected URL';
	}
	if (stripos($url, 'spearforensics.com') !== false) {
		return 'Rejected URL';
	}
	if (stripos($url, 'divergentlogic.net') !== false) {
		return 'Rejected URL';
	}
	if (stripos($url, 'gofoxy.net') !== false) {
		return 'Rejected URL';
	}
	$url = preg_replace('/(?:default|index)\\.' .
		'(?:aspx?|cfm|cgi|htm|html|jsp|php)$/D', '', $url);
	return true;
}

function validateIP($host) {
	if ($host == '') return '';
	$parts = explode(':', $host, 2);
	if (count($parts) != 2) return 'Invalid IP';
	$ip = $parts[0];
	$port = $parts[1];
	if (filter_var($ip, FILTER_VALIDATE_IP, 
		FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE) === false) 
		return 'Invalid IP';
	if ($ip != $_SERVER['REMOTE_ADDR']) return "Query IP doesn't match client IP";
	if (preg_match("/[^0-9]/", $port)) {
		return 'Invalid port';
	}
	$flags = array("options"=>array("min_range"=>1, "max_range"=>65535));
	if (filter_var($port, FILTER_VALIDATE_INT, $flags) === false) return 'Invalid port';
	return true;
}

function validateNet($net) {
	global $networks;
	if ($net == '') {
		sendError('No network');
	}
	$net = strtolower($net);
	// supported network?
	if (!in_array($net, $networks)) {
		sendError('Unsupported network');
	}
	return $net;
}

function sendResponse($response) {
	if (count($response) == 0) {
		echo createLine('i', 'nothing');
	} else {
		echo implode("\n", $response);
	}
}

function sendError($message) {
	die("ERROR $message\n");
}

function createLine($type) {
	$args = func_get_args();
	return implode('|', $args);
}

function getParamIsSet($name) {
	global $request;
	$request[$name] = isset($_GET[$name]);
}

function getParam($name) {
	global $request;
	if (isset($_GET[$name])) {
		$request[$name] = $_GET[$name];
	} else {
		$request[$name] = '';
	}
}

?>
