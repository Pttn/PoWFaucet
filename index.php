<?php // (c) 2022 Pttn - Riecoin faucet showing how one can integrate the PoWc and replace Captchas
/*ini_set("display_errors", "1");
error_reporting(-1);*/

class RiecoinRPC {
	private $credentials;
	private $url;
	private $id = 0;
	
	public function __construct($daemonConf) {
		$this->credentials = $daemonConf['rpcuser'] . ':' . $daemonConf['rpcpassword'];
		$this->url = 'http://' . $daemonConf['rpcip'] . ':' . $daemonConf['rpcport'] . '/wallet/' . $daemonConf['walletname'];
	}
	
	public function __call($method, $params) {
		$this->id++; // The ID should be unique for each call
		$request = json_encode(array(
			'method' => $method,
			'params' => $params,
			'id'	 => $this->id
		));
		$curl = curl_init($this->url);
		curl_setopt_array($curl, array(
			CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
			CURLOPT_USERPWD        => $this->credentials,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_HTTPHEADER     => array('Content-type: application/json'),
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $request
		));
		$response = curl_exec($curl);
		$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$curlError = curl_error($curl);
		curl_close($curl);
		if (!empty($curlError))
			throw new Exception($curlError);
		$jsonResponse = json_decode($response, true);
		if (isset($jsonResponse['error']['message']))
			throw new Exception($jsonResponse['error']['message']);
		if ($status !== 200)
			throw new Exception($method . ' call failed with HTTP code ' . $status);
		return $jsonResponse['result'];
	}
}

class RiecoinFaucet {
	private readonly RiecoinRPC $riecoinRPC;
	private readonly array $server;
	private readonly string $token;
	public readonly array $account;
	
	public readonly array $supportedServers;
	public readonly float $faucetAmount;
	public readonly float $powcCost;
	public readonly float $balance;
	public $loginError;
	
	public function __construct() {
		$this->faucetAmount = 0.1;
		$this->powcCost = 0.5;
		$this->riecoinRPC = new RiecoinRPC(array(
			'rpcuser' => 'user',
			'rpcpassword' => 'pass',
			'rpcip' => '127.0.0.1',
			'rpcport' => 28332,
			'walletname' => 'Faucet'));
		$this->supportedServers = array(
			'Stelo.xyz' => array(
				'apiUrl' => 'https://stelo.xyz/Mining/Api',
				'address' => 'ric1qstel092jqp8ucz94kzcklx7r4sh7n9c79gukkf'),
		);
		try {
			$this->balance = $this->riecoinRPC->getbalance();
		}
		catch (Exception $e) {
			$this->balance = 0.;
		}
		if (!empty($_COOKIE['server']) && !empty($_COOKIE['token'])) {
			if ($this->checkServerAndToken($_COOKIE['server'], $_COOKIE['token']) === true) {
				try {
					$server = $this->supportedServers[$_COOKIE['server']];
					$account = $this->sendRequest($server['apiUrl'], array(
						'method' => 'getPoWInfo',
						'token' => $_COOKIE['token'],
					));
					if (!isset($account['username'], $account['registrationTime'], $account['powc'], $account['addresses'], $account['blocks']))
						throw new Exception('Got invalid PoW data from the server');
					if (strlen($account['username']) > 20 || !ctype_alnum($account['username']))
						throw new Exception('Got invalid username from the server');
					if (!is_numeric($account['powc']))
						throw new Exception('Got invalid PoWc amount from the server');
					if (!is_array($account['addresses']))
						throw new Exception('Got invalid address list from the server');
					else {
						foreach ($account['addresses'] as &$address) {
							if ($this->checkRiecoinAddress($address) !== true)
								throw new Exception('Got invalid address list from the server');
						}
						if (count($account['addresses']) < 1)
							throw new Exception('Got empty address list from the server');
					}
					$blocks = array();
					if (!is_array($account['blocks']))
						throw new Exception('Got invalid block hash list from the server');
					else {
						foreach ($account['blocks'] as &$blockHash) {
							if (strlen($blockHash) != 64 || !ctype_xdigit($blockHash))
								throw new Exception('Got invalid block hash list from the server');
							$block = $this->riecoinRPC->getblock($blockHash);
							$coinbase = $this->riecoinRPC->getrawtransaction($block['tx'][0], true);
							if (($coinbase['vout'][0]['scriptPubKey']['address'] ?? '') === $server['address']
							 && ($coinbase['blocktime'] ?? 0) > time() - 604800)
								$blocks[] = $blockHash;
						}
					}
					$account['blocks'] = $blocks;
					$this->account = $account;
					$this->server = $server;
					$this->token = $_COOKIE['token'];
				}
				catch (Exception $e) {
					$this->account = array() ;
					$this->loginError = $e->getMessage();
				}
			}
		}
	}
	
	private function sendRequest($url, $fields) {
		$curlHandle = curl_init();
		curl_setopt($curlHandle, CURLOPT_URL, $url);
		curl_setopt($curlHandle, CURLOPT_POST, count($fields));
		curl_setopt($curlHandle, CURLOPT_POSTFIELDS, http_build_query($fields));
		curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curlHandle, CURLOPT_TIMEOUT, 5);
		$result = curl_exec($curlHandle);
		if (curl_error($curlHandle) !== '')
			throw new Exception(curl_error($curlHandle));
		curl_close($curlHandle);
		$result = json_decode($result, true);
		if (!is_array($result))
			throw new Exception('Malformed response from server');
		if (!array_key_exists('result', $result) || !array_key_exists('error', $result))
			throw new Exception('Malformed response from server');
		if ($result['error'] !== null)
			throw new Exception($result['error']);
		return $result['result'];
	}
	
	private function checkServerAndToken($server, $token) {
		if (!isset($this->supportedServers[$server]))
			return 'Unsupported Server!';
		if (!ctype_xdigit($token) || strlen($token) != 64)
			return 'The token must be a hexadeximal string of 64 digits!';
		return true;
	}
	
	private function checkRiecoinAddress($address) {
		try {
			if (strlen($address) > 128)
				throw new Exception('Invalid Riecoin address');
			if (!ctype_alnum($address))
				throw new Exception('Invalid Riecoin address');
			$getaddressinfoResponse = $this->riecoinRPC->getaddressinfo($address);
			if (($getaddressinfoResponse['address'] ?? null) !== $address)
				throw new Exception('Invalid Riecoin address');
			if (($getaddressinfoResponse['iswitness'] ?? null) !== true)
				throw new Exception('Not a Bech32 address');
			return true;
		}
		catch (Exception $e) {
			return $e->getMessage();
		}
	}
	
	public function loggedIn() {return isset($this->account['username'], $this->server, $this->token);}
	public function login($server, $token) {
		$status = $this->checkServerAndToken($server, $token);
		if ($status !== true) {
			$this->loginError = $status;
			return $status;
		}
		$expiration = time() + 31556952;
		setcookie('server', $server, $expiration, '/', '', false, true);
		setcookie('token', $token, $expiration, '/', '', false, true);
		return true;
	}
	
	public function claim($address) {
		try {
			if ($this->loggedIn() !== true)
				throw new Exception('Not logged in');
			if (!in_array($address, $this->account['addresses'], true))
				throw new Exception('Address not associated to user');
			$status = $this->sendRequest($this->server['apiUrl'], array(
				'method' => 'consumePoWc',
				'token' => $_COOKIE['token'],
				'amount' => $this->powcCost,
				'comment' => 'Riecoin Faucet Claim'
			));
			if ($status !== true)
				throw new Exception($status);
			$this->riecoinRPC->sendtoaddress($address, $this->faucetAmount);
			return true;
		}
		catch (Exception $e) {
			return $e->getMessage();
		}
	}
}

$faucet = new RiecoinFaucet();
if (isset($_POST['removeToken'])) {
	setcookie('server', '', time() - 1, '/', '', false, true);
	setcookie('token', '', time() - 1, '/', '', false, true);
	header('Location: .');
}
if (!empty($_POST['server']) && !empty($_POST['token'])) {
	if ($faucet->login($_POST['server'], $_POST['token']) === true)
		header('Location: .');
}
?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8"/>
		<style>
			* {
				color: #C0C0C0;
				background: none;
			}
			body {
				background: #000000;
			}
			.errorMessage {
				background-color: rgba(255, 0, 0, 0.25);
				padding: 8px;
			}
			.successMessage {
				background-color: rgba(0, 255, 0, 0.25);
				padding: 8px;
			}
			table {
				border-collapse: collapse;
			}
			td {
				border: 1px solid rgba(255, 255, 255, 0.5);
				padding: 4px;
			}
			td input, td button {
				width: 100%;
				box-sizing: border-box;
			}
		</style>
		<link rel="shortcut icon" href="Riecoin.svg"/>
		<title>PoW Faucet</title>
	</head>
	<body>
		<header><h1 style="font-size: 3em; margin: 0;">PoW Faucet</h1></header>
		<hr>
		<main>
			<p>Welcome to our Riecoin Faucet!</p>
			<p>You can claim some coins here at any time and even multiple times, provided that you have enough PoW Credits in your account.</p>
			<p><b>Current rate</b>: <?php echo $faucet->faucetAmount;?> RIC for <?php echo $faucet->powcCost;?> PoWc in each claim</p>
			<p><b>Faucet balance</b>: <?php echo $faucet->balance;?> RIC</p>
			<h2>Claim some RIC</h2>
<?php
if ($faucet->balance < $faucet->faucetAmount) {
	echo '<p class="errorMessage">Sorry, the faucet does not have enough funds for more claims, please come back later.</p>';
}
else {
	if (!$faucet->loggedIn()) {
		if (isset($faucet->loginError))
			echo '<p class="errorMessage">Authentication failed, try again or use another token: ' . $faucet->loginError . '</p>';
?>
				<p>Please select a supported PoW Server and provide a token. Then, if you have enough PoWc, you can claim some coins.<br>
				If it is the first time you are using this, please read below.</p>
				<form method="post">
					<table>
						<tr>
							<td>PoW Server</td>
							<td><select style="width: 512px;" name="server">
<?php
								foreach ($faucet->supportedServers as $name => $data)
									echo '<option value="' . $name . '">' . $name . '</option>';
?>
							</select></td>
						</tr>
						<tr>
							<td>Token</td>
							<td><input style="width: 512px;" name="token"/></td>
						</tr>
						<tr>
							<td colspan="2" align="center"><button>Submit</button></td>
						</tr>
					</table>
				</form>
				<p>By submitting, you accept that we create two cookies to store the server and the token in order to not ask for these again.</p>
				<h3>How does this work?</h3>
				<p>You need to create an account in a supported PoW Server, and then mine some RIC in order to increase your available PoW Credits. 1 PoWc corresponds to the work done to find 1 Riecoin Block, and they expire after 7 days.</p>
				<p>It will initially take some time to accumulate enough PoWc, but then, by contributing consistently to the Riecoin Network and finding interesting prime numbers, you can generate your PoWc without effort in the background while earning RIC, and consume it as Captcha replacement, which tend to become increasingly difficult and annoying due to Artificial Intelligence advances.</p>
				<p>Our faucet show that the power of PoW is a suitable replacement to Captchas.</p>
<?php
	}
	else {
		echo '<p>Hello ' . $faucet->account['username'] . '! You have ' . $faucet->account['powc'] . ' PoWc available.</p>';
		echo '<p>You found ' . count($faucet->account['blocks']) . ' block(s) during the last 7 days.</p>';
		if ($faucet->powcCost > $faucet->account['powc']) {
			echo "<p>You currently don't have enough PoWc for a Faucet Claim.</p>";
		}
		else {
			if (isset($_POST['address'])) {
				$status = $faucet->claim($_POST['address']);
				if ($status === true)
					echo '<p class="successMessage">Claim successful! Your RIC will soon arrive to your wallet.</p>';
				else
					echo '<p class="errorMessage">' . $status . '</p>';
			}
			echo '<p>Click on the button to receive ' . $faucet->faucetAmount . ' RIC with the given address.</p>';
			echo '<p>This will consume ' . $faucet->powcCost . ' PoWc.</p>'; ?>
			<form method="post">
				<table>
					<tr>
						<td><label for="address">Riecoin Address</label></td>
						<td><select name="address" style="width: 384px;">
<?php
							foreach ($faucet->account['addresses'] as $address)
								echo '<option value="' . $address . '">' . $address . '</option>';
?>
						</select></td>
					</tr>
					<tr>
						<td colspan="2" align="center"><button>Claim!</button></td>
					</tr>
				</table>
			</form>
			
			<form method="post"><input type="hidden" name="removeToken" value="removeToken">
				<p><button>Use another account/token</button></p>
			</form>
<?php
		}
	}
}
?>
		</main>
		<hr>
		<footer>(c) 2022 - Pttn | <a href="http://riecoin.dev/">Riecoin.dev</a> | <a href="http://forum.riecoin.dev/">Forum</a></footer>
	</body>
</html>
