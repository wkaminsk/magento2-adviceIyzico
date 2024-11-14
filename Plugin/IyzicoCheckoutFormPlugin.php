<?php

namespace Riskified\AdviceIyzico\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Registry;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Riskified\Decider\Model\Api\Api;
use Riskified\Advice\Model\Builder\Advice as AdviceBuilder;
use Riskified\Decider\Model\Api\Log as Logger;
use Riskified\Advice\Model\Request\Advice as AdviceRequest;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Riskified\Decider\Model\Api\Order as OrderApi;

class IyzicoCheckoutFormPlugin
{
    const XML_ADVISE_ENABLED = 'riskified/riskified_advise_process/enabled';
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;
    /**
     * @var Registry
     */
    protected $registry;
    /**
     * @var AdviceBuilder
     */
    protected $adviceBuilder;
    /**
     * @var AdviceRequest
     */
    protected $adviceRequest;
    /**
     * @var Api
     */
    protected $api;
    /**
     * @var Logger
     */
    protected $logger;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $session;
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;
    /**
     * @var OrderApi
     */
    protected $apiOrderLayer;
    /**
     * @var CartRepositoryInterface
     */
    protected $cartRespository;
    /**
     * @var OrderFactory
     */
    protected $orderFactory;
    /**
     * @var QuoteIdMaskFactory
     */
    protected $quoteIdMaskFactory;
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;
    public function __construct(
        JsonFactory $resultJsonFactory,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Checkout\Model\Session $session,
        ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Api\CartRepositoryInterface $cartRespository,
        OrderFactory $orderFactory,
        AdviceBuilder $adviceBuilder,
        AdviceRequest $adviceRequest,
        OrderApi $orderApi,
        Registry $registry,
        Logger $logger,
        Api $api
    ) {
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->adviceBuilder = $adviceBuilder;
        $this->adviceRequest = $adviceRequest;
        $this->cartRespository = $cartRespository;
        $this->orderFactory = $orderFactory;
        $this->scopeConfig = $scopeConfig;
        $this->apiOrderLayer = $orderApi;
        $this->registry = $registry;
        $this->request = $request;
        $this->session = $session;
        $this->logger = $logger;
        $this->api = $api;
    }
    /**
     * @param $buttonList
     *
     * @return mixed
     */
    public function beforeDispatch(
        \Iyzico\Iyzipay\Controller\Request\IyzicoCheckoutForm\Interceptor $subject
    ) {
        $payload = $this->request->getContent();

        parse_str($payload, $params);
        $cartId = $params['iyziQuoteId'];

        $params['quote_id'] = $params['iyziQuoteId'];
        $params['gateway'] = 'iyzipay';
        $params['last4'] = '';

        if (is_numeric($cartId)) {
            $quoteId = $cartId;
        } else {
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($cartId, 'masked_id');
            $quoteId = $quoteIdMask->getQuoteId();
        }

        $quote = $this->cartRespository->get($quoteId);

        if (!$quote || !$quote->getId()) {
            return $this->resultJsonFactory->create()->setData(['status' => 9999, 'message' => "Quote does not exists"]);
        }

        if (!$quote->getCustomerEmail()) {
            $quote->setCustomerEmail($params['iyziQuoteEmail']);
        }

        $this->api->initSdk($quote);
        $this->adviceBuilder->build($params);
        $callResponse = $this->adviceBuilder->request();

        $this->logger->log(json_encode($callResponse));

        if ($callResponse->checkout?->action != 'proceed') {
            throw new \Exception("Prevent order");
        }
    }
}
