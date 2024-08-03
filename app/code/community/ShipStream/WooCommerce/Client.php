<?php

/**
 * WooCommerce API Client
 */
class ShipStream_WooCommerce_Client
{
    const ERROR_SESSION_EXPIRED = 5;

    /** @var null|string */
    protected $_sessionId;

    /** @var array */
    protected $_config = array();

    /** @var string */
    protected $_token;

    /**
     * WooCommerce API client constructor
     *
     * @param array $config
     * @throws Exception
     */
    public function __construct(array $config)
    {
        foreach (['base_url', 'consumer_key', 'consumer_secret'] as $key) {
            if (empty($config[$key])) {
                throw new Exception(sprintf('Configuration parameter \'%s\' is required.', $key));
            }
        }
        $this->_config = $config;

        // Generate the token
        $this->_token = base64_encode($config['consumer_key'] . ':' . $config['consumer_secret']);
    }

    /**
     * Make a request to the WooCommerce API
     *
     * @param string $endpoint
     * @param string $method
     * @param array $params
     * @return mixed
     * @throws Exception
     */
    public function request($endpoint, $method = 'POST', $params = [])
    {
        $url = $this->_config['base_url'] . $endpoint;
        $curl = curl_init();
        $headers = [
            'Authorization: Basic ' . $this->_token,
            'Content-Type: application/json'
        ];

        switch ($method) {
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
                break;
            case 'PUT':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
                break;
            case 'DELETE':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            default:
                if (!empty($params)) {
                    $url .= '?' . http_build_query($params);
                }
        }

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        // Bypass SSL verification
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($curl);

        if (curl_errno($curl)) {
            throw new Exception('Request Error: ' . curl_error($curl));
        }

        curl_close($curl);

        return json_decode($response, true);
    }
}
