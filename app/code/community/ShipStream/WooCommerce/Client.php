<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magento.com for more information.
 *
 * php version 7.2.10
 *
 * @category  Varien
 * @package   Varien_Http
 * @author    Timo Webler <timo.webler@dkd.de>
 * @copyright 2024 Magento, Inc. (http://www.qono.com)
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License
 * @link      http://opensource.org/licenses/osl-3.0.php
 */

/**
 * HTTP CURL Adapter
 *
 * @category Varien
 * @package  Varien_Http
 * @author   Magento Core Team <core@magentocommerce.com>
 * @license  http://opensource.org/licenses/osl-3.0.php Open Software License
 * @link     http://opensource.org/licenses/osl-3.0.php
 */
class ShipStream_WooCommerce_Client
{
    /**
     * Constants
     */
    const ERROR_SESSION_EXPIRED = 5;

    /**
     * Parameters null or string
     *
     * @var null|string
     */
    protected ?string $sessionId;

    /**
     * Parameters array
     *
     * @var array
     */
    protected array $config = array();

    /**
     * Parameters string
     *
     * @var string
     */
    protected string $token;

    /**
     * WooCommerce API client constructor
     *
     * @param array $config configuration values
     *
     * @throws Exception
     */
    public function __construct(array $config)
    {
        foreach (array('base_url', 'consumer_key', 'consumer_secret') as $key) {
            if (empty($config[$key])) {
                Mage::throwException(
                    'Configuration parameter \'%s\' is required.', $key
                );
            }
        }

        $this->config = $config;

        // Generate the token
        $this->token = base64_encode(
            $config['consumer_key'] . ':' . $config['consumer_secret']
        );
    }

    /**
     * Make a request to the WooCommerce API
     *
     * @param string $endpoint Endpoint
     * @param string $method   Method
     * @param array  $params   Array
     *
     * @return mixed
     * @throws Exception
     */
    public function request(
        string $endpoint,
        string $method = 'POST',
        array $params = array()
    ) {
        $url = $this->config['base_url'] . $endpoint;
        $curl = curl_init();
        $headers = array(
            'Authorization: Basic ' . $this->_token,
            'Content-Type: application/json'
        );

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
            Mage::throwException('Request Error: ' . curl_error($curl));
        }

        curl_close($curl);

        return json_decode($response, true);
    }
}
