<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Controller\Webhook;

use Blokbay\Payment\Model\Api;
use Blokbay\Payment\Model\Applier;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Webhook endpoint https://your-store/blokbay/webhook/index (POST JSON, header Blokbay-Signature).
 *
 * No form key: the request comes from the Blokbay server and is authenticated by the HMAC signature.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var Http
     */
    private $request;

    /**
     * @var RawFactory
     */
    private $raw;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var Applier
     */
    private $applier;

    /**
     * @var Json
     */
    private $json;

    /**
     * @param Http $request
     * @param RawFactory $raw
     * @param Api $api
     * @param Applier $applier
     * @param Json $json
     */
    public function __construct(Http $request, RawFactory $raw, Api $api, Applier $applier, Json $json)
    {
        $this->request = $request;
        $this->raw = $raw;
        $this->api = $api;
        $this->applier = $applier;
        $this->json = $json;
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $res = $this->raw->create()->setHeader('Content-Type', 'text/plain');
        $body = (string) $this->request->getContent();
        $signature = (string) $this->request->getHeader('Blokbay-Signature');
        if (!$this->api->verifySignature($body, $signature, $this->api->secret('webhook_secret'))) {
            return $res->setHttpResponseCode(400)->setContents('bad signature');
        }
        try {
            $event = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            $event = null;
        }
        if (!is_array($event) || empty($event['type'])) {
            return $res->setHttpResponseCode(400)->setContents('bad payload');
        }
        $publicKey = trim($this->api->conf('public_key'));
        if ($publicKey !== '' && ($event['account'] ?? '') !== $publicKey) {
            return $res->setHttpResponseCode(400)->setContents('other account');
        }
        if ($event['type'] === 'ping') {
            return $res->setContents('pong');
        }
        $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : [];
        return $res->setContents(empty($data['id']) ? 'ok' : $this->applier->apply($data));
    }
}
