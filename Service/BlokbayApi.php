<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Service;

/**
 * Blokbay API client and webhook signature check. No EC-CUBE classes: unit-testable on its own.
 */
class BlokbayApi
{
    const DEFAULT_BASE = 'https://blokbay.com';
    const TOLERANCE = 300;
    const MIN_AMOUNT = '1.00';

    /** @var string */
    private $secretKey;
    /** @var string */
    private $base;
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{code: int, body: string} */
    private $transport;

    public function __construct($secretKey, $base = self::DEFAULT_BASE, $transport = null)
    {
        $this->secretKey = trim((string) $secretKey);
        $base = trim((string) $base);
        $this->base = rtrim($base !== '' ? $base : self::DEFAULT_BASE, '/');
        $this->transport = $transport;
    }

    /** USD amount with 2 decimals, as a string ("249.90"). */
    public static function amount($value)
    {
        return number_format(round((float) $value, 2), 2, '.', '');
    }

    /** Secret key format: sk_live_ + letters and digits (Blokbay issues 48 hex characters). Same rule as the other Blokbay plugins. */
    public static function isSecretKey($v)
    {
        return (bool) preg_match('/^sk_live_[0-9A-Za-z]{20,100}$/', (string) $v);
    }

    public static function isPublicKey($v)
    {
        return (bool) preg_match('/^pk_live_[A-Za-z0-9]{8,64}$/', (string) $v);
    }

    public static function isWebhookSecret($v)
    {
        return (bool) preg_match('/^whsec_[A-Za-z0-9]{16,128}$/', (string) $v);
    }

    /** https only, http allowed only for localhost / 127.0.0.1 (local tests). */
    public static function isApiBase($v)
    {
        $v = trim((string) $v);
        if ($v === '') {
            return true;
        }
        $p = parse_url($v);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['query'])) {
            return false;
        }
        if ($p['scheme'] === 'https') {
            return true;
        }

        return $p['scheme'] === 'http' && in_array($p['host'], ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * @param array<string, string> $data
     *
     * @return array<string, mixed>
     */
    public function createPayment(array $data, $idempotencyKey = '')
    {
        return $this->request('POST', '/api/v1/payments', $data, (string) $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function getPayment($id)
    {
        if (!preg_match('/^gp_[a-z0-9]+$/', (string) $id)) {
            return ['error' => 'bad_id'];
        }

        return $this->request('GET', '/api/v1/payments/'.$id);
    }

    /**
     * @param array<string, string>|null $data
     *
     * @return array<string, mixed>
     */
    private function request($method, $path, $data = null, $idempotencyKey = '')
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'Blokbay-ECCUBE/1.0',
        ];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = substr($idempotencyKey, 0, 100);
        }
        $body = $data === null ? null : (string) json_encode($data);
        $res = $this->transport !== null
            ? call_user_func($this->transport, $method, $this->base.$path, $headers, $body)
            : self::curlTransport($method, $this->base.$path, $headers, $body);
        $json = json_decode((string) $res['body'], true);
        if (!is_array($json)) {
            return ['error' => 'bad_response', '_code' => (int) $res['code']];
        }
        $json['_code'] = (int) $res['code'];

        return $json;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{code: int, body: string}
     */
    private static function curlTransport($method, $url, array $headers, $body)
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k.': '.$v;
        }
        $c = curl_init($url);
        if ($c === false) {
            return ['code' => 0, 'body' => ''];
        }
        curl_setopt_array($c, [
            CURLOPT_CUSTOMREQUEST => $method !== '' ? $method : 'GET',
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);

        return ['code' => $code, 'body' => is_string($out) ? $out : ''];
    }

    /**
     * Checks the Blokbay-Signature header: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the signing secret>.
     * Signatures older or newer than 5 minutes are refused (replay protection).
     */
    public static function verifySignature($rawBody, $header, $secret, $now = null)
    {
        $rawBody = (string) $rawBody;
        $header = (string) $header;
        $secret = (string) $secret;
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            $p = explode('=', trim($kv), 2);
            if (count($p) === 2) {
                $parts[$p[0]] = $p[1];
            }
        }
        if (empty($parts['t']) || empty($parts['v1']) || !ctype_digit($parts['t'])) {
            return false;
        }
        $now = $now === null ? time() : (int) $now;
        if (abs($now - (int) $parts['t']) > self::TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts['t'].'.'.$rawBody, $secret);

        return hash_equals($expected, $parts['v1']);
    }
}
