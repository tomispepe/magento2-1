<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Tools\SampleData\Module\Sales\Setup\Order;

use Magento\Framework\Object;

/**
 * Class Processor
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Processor
{
    /**
     * @var \Magento\Framework\Registry
     */
    protected $coreRegistry;

    /**
     * @var \Magento\Framework\Phrase\Renderer\CompositeFactory
     */
    protected $rendererCompositeFactory;

    /**
     * @var \Magento\Sales\Model\AdminOrder\CreateFactory
     */
    protected $createOrderFactory;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var \Magento\Framework\DB\TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \Magento\Sales\Model\Service\OrderFactory
     */
    protected $serviceOrderFactory;

    /**
     * @var \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoaderFactory
     */
    protected $shipmentLoaderFactory;

    /**
     * @var \Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoaderFactory
     */
    protected $creditmemoLoaderFactory;

    /**
     * @var \Magento\Tools\SampleData\Helper\StoreManager
     */
    protected $storeManager;

    /**
     * @var \Magento\Tools\SampleData\ObserverManager
     */
    protected $observerManager;

    /**
     * @param \Magento\Framework\Registry $coreRegistry
     * @param \Magento\Framework\Phrase\Renderer\CompositeFactory $rendererCompositeFactory
     * @param \Magento\Sales\Model\AdminOrder\CreateFactory $createOrderFactory
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerFactory
     * @param \Magento\Backend\Model\Session\QuoteFactory $sessionQuoteFactory
     * @param \Magento\Framework\DB\TransactionFactory $transactionFactory
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Sales\Model\Service\OrderFactory $serviceOrderFactory
     * @param \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoaderFactory $shipmentLoaderFactory
     * @param \Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoaderFactory $creditmemoLoaderFactory
     * @param \Magento\Tools\SampleData\Helper\StoreManager $storeManager
     * @param \Magento\Tools\SampleData\ObserverManager $observerManager
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Registry $coreRegistry,
        \Magento\Framework\Phrase\Renderer\CompositeFactory $rendererCompositeFactory,
        \Magento\Sales\Model\AdminOrder\CreateFactory $createOrderFactory,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerFactory,
        \Magento\Backend\Model\Session\QuoteFactory $sessionQuoteFactory,
        \Magento\Framework\DB\TransactionFactory $transactionFactory,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Sales\Model\Service\OrderFactory $serviceOrderFactory,
        \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoaderFactory $shipmentLoaderFactory,
        \Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoaderFactory $creditmemoLoaderFactory,
        \Magento\Tools\SampleData\Helper\StoreManager $storeManager,
        \Magento\Tools\SampleData\ObserverManager $observerManager
    ) {
        $this->coreRegistry = $coreRegistry;
        $this->rendererCompositeFactory = $rendererCompositeFactory;
        $this->createOrderFactory = $createOrderFactory;
        $this->customerRepository = $customerFactory;
        $this->sessionQuoteFactory = $sessionQuoteFactory;
        $this->transactionFactory = $transactionFactory;
        $this->orderFactory = $orderFactory;
        $this->serviceOrderFactory = $serviceOrderFactory;
        $this->shipmentLoaderFactory = $shipmentLoaderFactory;
        $this->creditmemoLoaderFactory = $creditmemoLoaderFactory;
        $this->storeManager = $storeManager;
        $this->observerManager = $observerManager;
    }

    /**
     * @param array $orderData
     * @return void
     */
    public function createOrder($orderData)
    {
        $this->setPhraseRenderer();
        if (!empty($orderData)) {
            $orderCreateModel = $this->processQuote($orderData);
            if (!empty($orderData['payment'])) {
                $orderCreateModel->setPaymentData($orderData['payment']);
                $orderCreateModel->getQuote()->getPayment()->addData($orderData['payment']);
            }
            $customer = $this->customerRepository->get(
                $orderData['order']['account']['email'],
                $this->storeManager->getWebsiteId()
            );
            $orderCreateModel->getQuote()->setCustomer($customer);
            $orderCreateModel->getSession()->setCustomerId($customer->getId());
            $order = $orderCreateModel
                ->importPostData($orderData['order'])
                ->createOrder();
            $orderItem = $this->getOrderItemForTransaction($order);
            $this->invoiceOrder($orderItem);
            $this->shipOrder($orderItem);
            if ($orderData['refund'] === "yes") {
                $this->refundOrder($orderItem);
            }
            $registryItems = [
                'rule_data',
                'currently_saved_addresses',
                'current_invoice',
                'current_shipment',
            ];
            $this->unsetRegistryData($registryItems);
        }
    }

    /**
     * @param array $data
     * @return \Magento\Sales\Model\AdminOrder\Create
     */
    protected function processQuote($data = [])
    {
        $orderCreateModel = $this->createOrderFactory->create(
            ['quoteSession' => $this->sessionQuoteFactory->create()]
        );
        if (!empty($data['order'])) {
            $orderCreateModel->importPostData($data['order']);
        }
        $orderCreateModel->getBillingAddress();
        $orderCreateModel->setShippingAsBilling(true);
        if (!empty($data['add_products'])) {
            $orderCreateModel->addProducts($data['add_products']);
        }
        $orderCreateModel->collectShippingRates();
        if (!empty($data['payment'])) {
            /** @var \Magento\Quote\Model\Quote\Payment $payment */
            $payment = $orderCreateModel->getQuote()->getPayment();
            $payment->addData($data['payment']);
            $payment->setQuote($orderCreateModel->getQuote());
        }
        $orderCreateModel->initRuleData()->saveQuote();
        return $orderCreateModel;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return mixed
     */
    protected function getOrderItemForTransaction(\Magento\Sales\Model\Order $order)
    {
        $order->getItemByQuoteItemId($order->getQuoteId());
        foreach ($order->getItemsCollection() as $item) {
            if (!$item->isDeleted() && !$item->getParentItemId()) {
                return $item;
            }
        }
        return null;
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $orderItem
     * @return void
     */
    protected function invoiceOrder(\Magento\Sales\Model\Order\Item $orderItem)
    {
        $invoiceData = [$orderItem->getId() => $orderItem->getQtyToInvoice()];
        $invoice = $this->createInvoice($orderItem->getOrderId(), $invoiceData);
        if ($invoice) {
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);
            $invoiceTransaction = $this->transactionFactory->create()
                ->addObject($invoice)
                ->addObject($invoice->getOrder());
            $invoiceTransaction->save();
        }
    }

    /**
     * @param int $orderId
     * @param array $invoiceData
     * @return bool
     */
    protected function createInvoice($orderId, $invoiceData)
    {
        $order = $this->orderFactory->create()->load($orderId);
        if (!$order) {
            return false;
        }
        $invoice = $this->serviceOrderFactory->create(['order' => $order])
            ->prepareInvoice($invoiceData);
        return $invoice;
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $orderItem
     * @return void
     */
    protected function shipOrder(\Magento\Sales\Model\Order\Item $orderItem)
    {
        $shipmentLoader = $this->shipmentLoaderFactory->create();
        $shipmentData = [$orderItem->getId() => $orderItem->getQtyToShip()];
        $shipmentLoader->setOrderId($orderItem->getOrderId());
        $shipmentLoader->setShipment($shipmentData);
        $shipment = $shipmentLoader->load();
        if ($shipment) {
            $shipment->register();
            $shipment->getOrder()->setIsInProcess(true);
            $shipmentTransaction = $this->transactionFactory->create()
                ->addObject($shipment)
                ->addObject($shipment->getOrder());
            $shipmentTransaction->save();
        }
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $orderItem
     * @return void
     */
    protected function refundOrder(\Magento\Sales\Model\Order\Item $orderItem)
    {
        $creditmemoLoader = $this->creditmemoLoaderFactory->create();
        $creditmemoLoader->setOrderId($orderItem->getOrderId());
        $creditmemoLoader->setCreditmemo($this->getCreditmemoData($orderItem));
        $creditmemo = $creditmemoLoader->load();
        if ($creditmemo && $creditmemo->isValidGrandTotal()) {
            $creditmemo->setOfflineRequested(true);
            $creditmemo->register();
            $creditmemoTransaction = $this->transactionFactory->create()
                ->addObject($creditmemo)
                ->addObject($creditmemo->getOrder());
            $creditmemoTransaction->save();
        }
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $orderItem
     * @return array
     */
    public function getCreditmemoData(\Magento\Sales\Model\Order\Item $orderItem)
    {
        $data = [$orderItem->getId() => $orderItem->getQtyToRefund()];
        foreach ($this->observerManager->getObservers() as $observer) {
            if (is_callable([$observer, 'getCreditmemoData'])) {
                $params = new Object([
                    'order_item' => $orderItem,
                    'credit_memo' => $data
                ]);
                $data = $observer->getCreditmemoData($params);
            }
        }

        return $data;
    }

    /**
     * Set phrase renderer
     * @return void
     */
    protected function setPhraseRenderer()
    {
        \Magento\Framework\Phrase::setRenderer($this->rendererCompositeFactory->create());
    }

    /**
     * Unset registry item
     * @param array|string $unsetData
     * @return void
     */
    protected function unsetRegistryData($unsetData)
    {
        if (is_array($unsetData)) {
            foreach ($unsetData as $item) {
                $this->coreRegistry->unregister($item);
            }
        } else {
            $this->coreRegistry->unregister($unsetData);
        }
    }
}
