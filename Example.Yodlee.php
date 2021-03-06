<?php

require __DIR__.'/vendor/autoload.php';

use FintechSystems\LaravelApiHelpers\Api;
use Firebase\JWT\JWT;

class Yodlee
{
    public static function generateRSAKey()
    {
        $config = [
            'digest_alg' => 'sha512',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $res = openssl_pkey_new($config);
        // Get private key
        openssl_pkey_export($res, $privkey);
        // Get public key
        $pubkey = openssl_pkey_get_details($res);
        $pubkey = $pubkey['key'];
        $rsa_key = [
            'public_key' => $pubkey,
            'private_key' => $privkey,
        ];

        return $rsa_key;
    }

    public static function getCobSession($url, $cobrandArray)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
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
          "cobrandLogin": "'.$cobrandArray['cobrandLogin'].'",
          "cobrandPassword": "'.$cobrandArray['cobrandPassword'].'"
         }
    }',
            CURLOPT_HTTPHEADER => [
                'Api-Version: 1.1',
                'Cobrand-Name: '.$cobrandArray['cobrandName'],
                'Content-Type: application/json',
                'Cookie: JSESSIONID=xxx', // REDACTED
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        $object = json_decode($response);

        $cobSession = $object->session->cobSession;

        return $cobSession;
    }

    /**
     * Simplified generate API key that directly works with the existing public_key
     * instead of an array.
     */
    public static function generateAPIKey2($url, $cobrandArray, $public_key)
    {
        $curl = curl_init();

        $public_key = preg_replace('/\n/', '', $public_key);
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{
        "publicKey": "'.$public_key.'"
  }',
            CURLOPT_HTTPHEADER => [
                'Api-Version: 1.1',
                'Authorization: cobSession='.$cobrandArray['cobSession'],
                'Cobrand-Name: '.$cobrandArray['cobrandName'],
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        echo "\n".$response."\n";
        curl_close($curl);
        $object = json_decode($response);

        return $object->apiKey['0']->key;
    }

    public static function generateAPIKey($url, $cobrandArray, $rsa_key)
    {
        $curl = curl_init();

        $public_key = $rsa_key['public_key'];
        $public_key = preg_replace('/\n/', '', $public_key);
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => '{
        "publicKey": "'.$public_key.'"
  }',
            CURLOPT_HTTPHEADER => [
                'Api-Version: 1.1',
                'Authorization: cobSession='.$cobrandArray['cobSession'],
                'Cobrand-Name: '.$cobrandArray['cobrandName'],
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        echo "\n".$response."\n";
        curl_close($curl);
        $object = json_decode($response);

        return $object->apiKey['0']->key;
    }

    public static function generateJWTToken($api_key, $private_key_file, $username = null)
    {
        $keyfile = $private_key_file;

        $key = file_get_contents($keyfile);

        if ($key === false) {
            throw new Exception("Private key file ($keyfile): no such file or directory");
        }

        $payload = [
            'iss' => $api_key,
            'iat' => time(),
            'exp' => time() + 1800,
        ];

        if (null != ($username)) {
            $payload['sub'] = $username;
        }

        return $jwt = JWT::encode($payload, $key, 'RS512');
    }

    public static function getUserAccounts($jwt_token, $accounts_url)
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $accounts_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Api-Version: 1.1',
                'Authorization: Bearer '.$jwt_token,
                'Cobrand-Name: xxx', // REDACTED
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($curl);
        $object = json_decode($response);

        curl_close($curl);

        return $object->account;
    }

    public static function getTransactions($jwt_token, $transactions_url)
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $transactions_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Api-Version: 1.1',
                'Cobrand-Name: xxx', // REDACTED
                'Authorization: Bearer '.$jwt_token,
            ],
        ]);

        $response = curl_exec($curl);
        curl_close($curl);
        $object = json_decode($response);

        return $object;
    }
}
