<?php

namespace FintechSystems\YodleeApi;

use Carbon\Carbon;
use App\Models\User;
use Firebase\JWT\JWT;
use App\Models\Account;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Facades\App\Services\AccountService;
use FintechSystems\YodleeApi\Contracts\BankingProvider;

class YodleeApi implements BankingProvider
{
	public $storagePath       = '/yodlee/';

	private $privateKeyFilename = 'private.pem';

	private $api_url;
	private $api_key;

	public function __construct(Array $client)
	{		
		$this->api_url = $client['api_url'];
		$this->api_key = $client['api_key'];
	}

	public function apiGet($endpoint)
	{
		ray("Yodlee apiGet endpoint: " . $this->api_url . $endpoint);
		
		$token = $this->generateJwtToken();
		
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => $this->api_url . '/' . $endpoint,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'Api-Version: 1.1',
				'Authorization: Bearer ' . $token,
				'Cobrand-Name: xxx', // REDACTED
				'Content-Type: application/json'
			),
		));

		$response = curl_exec($curl);

		ray($response);

		curl_close($curl);

		return $response;
	}

	/**
	 * Compare the latest import with the data in the system, and if the latest import
	 * doesn't contain a specific yodlee transaction ID, then mark it as deleted
	 * in the main dataset.
	 */
	private function deletePendingTransactions($accountId)
	{
		$file = $accountId . ".json";

		$json = json_decode(Storage::disk('local')->get($this->storagePath . $file));

		$transactions = $json->transaction;

		dd($transactions);
	}

	public function generateAPIKey($url, $cobrandArray, $publicKey)
	{
		$curl = curl_init();

		$publicKey = preg_replace('/\n/', '', $publicKey);

		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => '{
        		"publicKey": "' . $publicKey . '"
  			}',
			CURLOPT_HTTPHEADER => [
				'Api-Version: 1.1',
				'Authorization: cobSession=' . $cobrandArray['cobSession'],
				'Cobrand-Name: ' . $cobrandArray['cobrandName'],
				'Content-Type: application/json'
			],
		));

		$response = curl_exec($curl);
		
		curl_close($curl);

		$object = json_decode($response);

		// If a key doesn't exist after the API call, then just return the response which should contain the error
		return ($object->apiKey['0']->key ?? $response);
	}
	
	/**
	 * Generate a JWT token from the private key. Used in almost all requests.
	 */
	public function generateJwtToken()
	{
		$api_key    = $_ENV['YODLEE_API_KEY'];
		$username   = $_ENV['YODLEE_USERNAME'];
		$privateKey = file_get_contents(__DIR__ . '/../' . $this->privateKeyFilename);
		
		$payload = [
			"iss" => $api_key,
			"iat" => time(),
			"exp" => time() + 1800,
			'sub' => $username,
		];

		return JWT::encode($payload, $privateKey, 'RS512');
	}

	public function getAccounts($jwt_token)
	{
		return json_decode($this->apiGet($jwt_token, 'accounts'));
	}

	public function getApiKeys() {		
		return $this->apiGet('auth/apiKey');	
	}
	
	public function getCobSession($url, $cobrandArray)
	{
		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => '{
        "cobrand":      {
          "cobrandLogin": "' . $cobrandArray['cobrandLogin'] . '",
          "cobrandPassword": "' . $cobrandArray['cobrandPassword'] . '"
         }
    }',
			CURLOPT_HTTPHEADER => array(
				'Api-Version: 1.1',
				'Cobrand-Name: ' . $cobrandArray['cobrandName'],
				'Content-Type: application/json',
				'Cookie: JSESSIONID=xxx' // REDACTED
			),
		));

		$response = curl_exec($curl);

		curl_close($curl);

		$object = json_decode($response);

		ray($object);

		return $object->session->cobSession;
	}

	public function getProviderAccounts() {		
		return $this->apiGet('providerAccounts');
	}

	public function getProviders() {		
		return $this->apiGet('providers');
	}
	
	private function getTransactions($jwt_token)
	{
		return json_decode($this->apiGet($jwt_token, 'transactions?fromDate=2020-08-01'));
	}

	private function getTransactionsByAccount($jwt_token, $accountId, $fromDate = null)
	{
		$fromDate == null ? $fromDate = Carbon::now()->subDays(90)->format('Y-m-d') : $fromDate = $fromDate;
		return json_decode($this->apiGet($jwt_token, "transactions?account_id=$accountId&fromDate=$fromDate"));
	}

	/**
	 * Import accounts from Yodlee by reading the local accounts.json file outputted to disk
	 * Only update fields directly from the Yodlee source, which should exclude the user's
	 * assigned name and nickname.
	 */
	public function importAccounts()
	{
		$json = json_decode(Storage::disk('local')->get($this->storagePath . 'accounts.json'));

		$accounts = $json->account;

		foreach ($accounts as $account) {
			Account::updateOrCreate(
				[
					'yodlee_account_id' => $account->id
				],
				[
					'user_id'             => 3,
					'container'           => $account->CONTAINER,
					'provider_account_id' => $account->providerAccountId,
					'name'                => $account->accountName,
					'number'              => $account->accountNumber,
					'balance'             => $account->balance->amount,
					'available_balance'   => $account->availableBalance->amount ?? null,
					'current_balance'     => $account->currentBalance->amount ?? null,
					'currency'            => $account->balance->currency,
					'provider_id'         => $account->providerId,
					'provider_name'       => $account->providerName,
					'type'                => $account->accountType,
					'display_name'        => $account->displayedName ?? null,
					'classification'      => $account->classification ?? null,
					'interest_rate'       => $account->interestRateType ?? null,
					'yodlee_dataset_name' => $account->dataset[0]->name,
					'yodlee_updated_at'   => Carbon::parse($account->dataset[0]->lastUpdated)->format('Y-m-d H:i:s'),
				]
			);

			$message = "Imported $account->CONTAINER account $account->accountName #$account->accountNumber with account balance of {$account->balance->amount} from $account->providerName for tentant 3\n";

			Log::info($message);
			echo $message;
			ray($message)->green();
		}
	}

	public function importTransactions($file = null)
	{
		$file == null ? $file = 'transactions.json' : $file = $file;
		$json = json_decode(Storage::disk('local')->get($this->storagePath . $file));

		$transactions = $json->transaction;

		AccountService::import($transactions, 3);

		//		\Facades\AccountService::import($transactions, 3);
	}

	public function refreshAccounts()
	{
		$user = User::whereEmail('xxx') // REDACTED
			->first();

		$jwtToken = $this->generateJwtToken();

		// $jwtToken = Crypt::generateJWTToken($this->api_key, $user->yodlee_username);

		ray("In Yodlee refreshAccounts(), a jwtToken was generated and it's " . $jwtToken);

		$accounts = $this->getAccounts($jwtToken);

		$message = "Retrieved " . count($accounts->account) . " accounts for $user->email tenant $user->id\n";

		Log::info($message);
		echo $message;
		ray($message)->green();

		Storage::put($this->storagePath . 'accounts.json', json_encode($accounts));
	}

	/**
	 * Calls the Yodlee API and retrieves transactions for a specific account up to
	 * 90 days prior. The resultant output is stored on disk where it will
	 * typically be processed by an import command.
	 */
	public function refreshTransactionsByAccount($accountId)
	{
		$user = User::whereEmail('xxx') // REDACTED
			->first();

		$userJwtToken = $this->generateJWTToken();
		// $userJwtToken = Crypt::generateJWTToken($this->api_key, $user->yodlee_username);

		$transactions = $this->getTransactionsByAccount($userJwtToken, $accountId);

		Storage::put("$this->storagePath$accountId.json", json_encode($transactions));

		$message = "Retrieved " . count($transactions->transaction) . " transactions for $user->email tenant $user->id\n";

		Log::info($message);
		echo $message;
		ray($message)->green();
	}

	public function refreshTransactions($fromDate = null)
	{
		$user = User::whereEmail('xxx') // REDACTED
			->first();

		$userJwtToken = $this->generateJwtToken();		

		$fromDate == null ? $fromDate = Carbon::now()->subDays(90)->format('Y-m-d') : $fromDate = $fromDate;

		$transactions = $this->getTransactions($userJwtToken, $fromDate);

		Storage::put($this->storagePath . 'transactions.json', json_encode($transactions));

		$message = "Retrieved " . count($transactions->transaction) . " transactions for $user->email from date $fromDate tenant $user->id\n";

		Log::info($message);
		echo $message;
		ray($message)->green();
	}
}