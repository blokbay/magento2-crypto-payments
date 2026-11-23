<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Blokbay API client (POST /api/v1/payments, GET /api/v1/payments/:id) and webhook signature check.
 */
class Api
{
    public const DEFAULT_BASE = 'https://blokbay.com';
    public const TOLERANCE = 300;

    /**
     * @var ScopeConfigInterface
     */
    private $scope;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var Curl
     */
    private $curl;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param ScopeConfigInterface $scope
     * @param EncryptorInterface $encryptor
     * @param Curl $curl
     * @param Json $json
     */
    public function __construct(ScopeConfigInterface $scope, EncryptorInterface $encryptor, Curl $curl, Json $json)
    {
        $this->scope = $scope;
        $this->encryptor = $encryptor;
        $this->curl = $curl;
        $this->json = $json;
    }

    /**
     * Configuration value of the payment method.
     *
     * @param string $key
     * @return string
     */
    public function conf(string $key): string
    {
        return (string) $this->scope->getValue('payment/blokbay/' . $key, ScopeInterface::SCOPE_WEBSITE);
    }

    /**
     * Secret key or webhook secret.
     *
     * The fields use the Encrypted backend model, so the config reader already returns them decrypted.
     * Only a value still in the encrypted form ("0:3:...") is decrypted here.
     *
     * @param string $key
     * @return string
     */
    public function secret(string $key): string
    {
        $value = trim($this->conf($key));
        if (preg_match('/^\d+:\d+:\S+$/', $value)) {
            $value = trim((string) $this->encryptor->decrypt($value));
        }
        return $value;
    }

    /**
     * Currencies accepted by the Blokbay API, with their decimals (JPY has none). The buyer pays in crypto at the current
     * exchange rate; amount and currency come back in the payment and in webhooks exactly as sent.
     */
    public const CURRENCIES = ['USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'JPY' => 0, 'CAD' => 2, 'AUD' => 2, 'SGD' => 2, 'HKD' => 2, 'AED' => 2];

    /**
     * @param string|null $currency
     * @return bool
     */
    public static function supported($currency): bool
    {
        return isset(self::CURRENCIES[strtoupper((string) $currency)]);
    }

    /**
     * Amount with the decimals of the currency, as a string ("249.90", JPY "25000").
     *
     * @param float|string|null $value
     * @param string $currency
     * @return string
     */
    public function amount($value, string $currency = 'USD'): string
    {
        $dec = self::CURRENCIES[strtoupper($currency)] ?? 2;
        return number_format(round((float) $value, $dec), $dec, '.', '');
    }

    /**
     * Idempotency key of a payment: store fingerprint (hash of the store address), increment id, order creation time
     * and amount. Two stores on the same Blokbay account, or a reinstalled store whose increment ids start again,
     * never get the payment of another order.
     *
     * @param string $storeUrl
     * @param string $incrementId
     * @param string $createdAt
     * @param string $amount
     * @return string
     */
    public function idempotencyKey(string $storeUrl, string $incrementId, string $createdAt, string $amount, string $currency = 'USD'): string
    {
        $store = substr(hash('sha256', strtolower(rtrim($storeUrl, '/'))), 0, 16);
        $created = preg_replace('/\D/', '', $createdAt);
        $cur = strtoupper($currency);
        return substr('mg-' . $store . '-' . $incrementId . '-' . $created . '-' . $amount . ($cur === 'USD' ? '' : '-' . $cur), 0, 100);
    }

    /**
     * True when the payment returned by the API belongs to this order (same reference, amount and currency).
     *
     * @param array $payment
     * @param string $reference
     * @param string $amount
     * @return bool
     */
    public function matchesOrder(array $payment, string $reference, string $amount, string $currency = 'USD'): bool
    {
        return (string) ($payment['reference'] ?? '') === $reference
            && (string) ($payment['amount'] ?? '') === $amount
            && ($payment['currency'] ?? '') === strtoupper($currency);
    }

    /**
     * Creates a payment.
     *
     * @param array $data
     * @param string $idempotencyKey
     * @return array
     */
    public function createPayment(array $data, string $idempotencyKey): array
    {
        return $this->request('POST', '/api/v1/payments', $data, $idempotencyKey);
    }

    /**
     * Reads a payment.
     *
     * @param string $id
     * @return array
     */
    public function getPayment(string $id): array
    {
        if (!preg_match('/^gp_[a-z0-9]+$/', $id)) {
            return ['error' => 'bad_id'];
        }
        return $this->request('GET', '/api/v1/payments/' . $id);
    }

    /**
     * Sends a request to the API and decodes the JSON answer.
     *
     * @param string $method
     * @param string $path
     * @param array|null $data
     * @param string $idempotencyKey
     * @return array
     */
    private function request(string $method, string $path, ?array $data = null, string $idempotencyKey = ''): array
    {
        $base = rtrim($this->conf('base_url') ?: self::DEFAULT_BASE, '/');
        $headers = [
            'Authorization' => 'Bearer ' . $this->secret('secret_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = substr($idempotencyKey, 0, 100);
        }
        $this->curl->setHeaders($headers);
        $this->curl->setOptions([CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
        if ($method === 'POST') {
            $this->curl->post($base . $path, (string) $this->json->serialize($data));
        } else {
            $this->curl->get($base . $path);
        }
        try {
            $decoded = $this->json->unserialize((string) $this->curl->getBody());
        } catch (\InvalidArgumentException $e) {
            $decoded = null;
        }
        return is_array($decoded) ? $decoded : ['error' => 'bad_response'];
    }

    /**
     * Checks the Blokbay-Signature header: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">.
     *
     * @param string $raw
     * @param string $header
     * @param string $secret
     * @param int|null $now
     * @return bool
     */
    public function verifySignature(string $raw, string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            $kv = explode('=', trim($pair), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }
        if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $parts['t']) > self::TOLERANCE) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $parts['t'] . '.' . $raw, $secret), $parts['v1']);
    }
}
