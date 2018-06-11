<?php

namespace Wuunder\Wuunderconnector\Controller\adminhtml\Index;

use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result;
use Magento\Framework\Controller\ResultFactory;
use \Wuunder\Wuunderconnector\Helper\Data;

class Label extends \Magento\Framework\App\Action\Action
{
    protected $_resultPageFactory;
    protected $orderRepository;
    protected $_productloader;
    protected $scopeConfig;
    protected $_storeManager;
    protected $HelperBackend;

    public function __construct(Context $context, \Magento\Framework\View\Result\PageFactory $resultPageFactory, \Magento\Sales\Api\OrderRepositoryInterface $orderRepository, \Magento\Catalog\Model\ProductFactory $_productloader, \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig, \Magento\Store\Model\StoreManagerInterface $storeManager, \Magento\Backend\Helper\Data $HelperBackend, Data $helper)
    {
        $this->helper = $helper;
        $this->_resultPageFactory = $resultPageFactory;
        $this->orderRepository = $orderRepository;
        $this->_productloader = $_productloader;
        $this->scopeConfig = $scopeConfig;
        $this->_storeManager = $storeManager;
        $this->HelperBackend = $HelperBackend;
        parent::__construct($context);
    }

    public function execute()
    {
        $this->helper->log("executed");
        $redirect_url = $this->processOrderInfo();
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setUrl($redirect_url);
        return $resultRedirect;
    }

    private function processOrderInfo()
    {
        $orderId = $this->getRequest()->getParam('orderId');
        $redirect_url = $this->HelperBackend->getUrl('sales/order');
        if (!$this->wuunderShipmentExists($orderId)) {
            $infoArray = $this->getOrderInfo($orderId);
            // Fetch order
            $order = $this->orderRepository->get($orderId);

            // Get configuration
            $test_mode = $this->scopeConfig->getValue('wuunder_wuunderconnector/general/testmode');
            $booking_token = uniqid();
            $infoArray['booking_token'] = $booking_token;
            $redirect_url = urlencode($this->HelperBackend->getUrl('sales/order'));
            $webhook_url = urlencode($this->_storeManager->getStore()->getBaseUrl() . 'wuunder/index/webhook/order_id/' . $orderId);

            if ($test_mode == 1) {
                $apiUrl = 'https://api-staging.wearewuunder.com/api/bookings?redirect_url=' . $redirect_url . '&webhook_url=' . $webhook_url;
                $apiKey = $this->scopeConfig->getValue('wuunder_wuunderconnector/general/api_key_test');
            } else {
                $apiUrl = 'https://api.wearewuunder.com/api/bookings?redirect_url=' . $redirect_url . '&webhook_url=' . $webhook_url;
                $apiKey = $this->scopeConfig->getValue('wuunder_wuunderconnector/general/api_key_live');
            }

            // Combine wuunder info and order data
            $wuunderData = $this->buildWuunderData($infoArray, $order);
            $connector = new Wuunder\Connector($apiKey);

            $header = $this->helper->curlRequest($wuunderData, $apiUrl, $apiKey, true);

            // Get redirect url from header
            preg_match("!\r\n(?:Location|URI): *(.*?) *\r\n!i", $header, $matches);
            $redirect_url = $matches[1];

            // Create or update wuunder_shipment
            $this->saveWuunderShipment($orderId, $redirect_url, "testtoken");
        }
        return $redirect_url;
    }

    private function saveWuunderShipment($orderId, $bookingUrl, $bookingToken)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $wuunderShipment = $objectManager->create('Wuunder\Wuunderconnector\Model\WuunderShipment');
        $wuunderShipment->load($this->getRequest()->getParam('order_id') , 'order_id');
        $wuunderShipment->setOrderId($orderId);
        $wuunderShipment->setBookingUrl($bookingUrl);
        $wuunderShipment->setBookingToken($bookingToken);
        $wuunderShipment->save();
    }

    private function wuunderShipmentExists($orderId)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $wuunderShipment = $objectManager->create('Wuunder\Wuunderconnector\Model\WuunderShipment');
        $wuunderShipment->load($orderId , 'order_id');
        $shipmentData = $wuunderShipment->getData();

        return (bool)$shipmentData;
    }

    private function getOrderInfo($orderId)
    {
        $messageField = 'personal_message';

        $order = $this->orderRepository->get($orderId);
        $shippingAdr = $order->getShippingAddress();

        $shipmentDescription = "";
        foreach ($order->getAllItems() as $item) {
            $product = $this->_productloader->create()->load($item->getProductId());
            $shipmentDescription .= $product->getName() . " ";
        }

        $phonenumber = trim($shippingAdr->getTelephone());
        // Set default values
        if ((substr($phonenumber, 0, 1) == '0') && ($shippingAdr->getCountryId() == 'NL')) {
            // If NL and phonenumber starting with 0, replace it with +31
            $phonenumber = '+31' . substr($phonenumber, 1);
        }

        return array(
            'reference' => $orderId,
            'description' => $shipmentDescription,
            $messageField => '',
            'phone_number' => $phonenumber,
        );
    }

    private function buildWuunderData($infoArray, $order)
    {
        $this->helper->log("Building data object for api.");
        $shippingAddress = $order->getShippingAddress();

        $shippingLastname = $shippingAddress->getLastname();

        $streetAddress = $shippingAddress->getStreet();
        if (count($streetAddress) > 1) {
            $streetName = $streetAddress[0];
            $houseNumber = $streetAddress[1];
        } else {
            $streetAddress = $this->addressSplitter($streetAddress[0]);
            $streetName = $streetAddress['streetName'];
            $houseNumber = $streetAddress['houseNumber'] . $shippingAddress['houseNumberSuffix'];
        }

        // Fix DPD parcelshop first- and lastname override fix
        $firstname = $shippingAddress->getFirstname();
        $lastname = $shippingLastname;
        $company = $shippingAddress->getCompany();

        $deliveryAddress = new \Wuunder\Api\Config\AddressConfig();
        $deliveryAddress->setBusiness($company);
        $deliveryAddress->setEmailAddress(($order->getCustomerEmail() !== '' ? $order->getCustomerEmail() : $shippingAddress->getEmail()));
        $deliveryAddress->setFamilyName($lastname);
        $deliveryAddress->setGivenName($firstname);
        $deliveryAddress->setLocality($shippingAddress->getCity());
        $deliveryAddress->setStreetName($streetName);
        $deliveryAddress->setHouseNumber($houseNumber);
        $deliveryAddress->setZipCode($shippingAddress->getPostcode());
        $deliveryAddress->setPhoneNumber($infoArray['phone_number']);
        $deliveryAddress->setCountry($shippingAddress->getCountryId());
        if(!$deliveryAddress->validate())
        {
            $this->helper->log("Invalid pickup address. There are mistakes or missing fields.");
            return $deliveryAddress;
        }

        $pickupAddress = new \Wuunder\Api\Config\AddressConfig();
        $pickupAddress->setBusiness($this->scopeConfig->getValue('wuunder_wuunderconnector/general/company'));
        $pickupAddress->setEmailAddress($this->scopeConfig->getValue('wuunder_wuunderconnector/general/email'));
        $pickupAddress->setFamilyName($this->scopeConfig->getValue('wuunder_wuunderconnector/general/lastname'));
        $pickupAddress->setGivenName($this->scopeConfig->getValue('wuunder_wuunderconnector/general/firstname'));
        $pickupAddress->setLocality($this->scopeConfig->getValue('wuunder_wuunderconnector/general/city'));
        $pickupAddress->setStreetName($this->scopeConfig->getValue('wuunder_wuunderconnector/general/streetname'));
        $pickupAddress->setHouseNumber($this->scopeConfig->getValue('wuunder_wuunderconnector/general/housenumber'));
        $pickupAddress->setZipCode($this->scopeConfig->getValue('wuunder_wuunderconnector/general/zipcode'));
        $pickupAddress->setPhoneNumber($this->scopeConfig->getValue('wuunder_wuunderconnector/general/phone'));
        $pickupAddress->setCountry($this->scopeConfig->getValue('wuunder_wuunderconnector/general/country'));
        if(!$pickupAddress->validate())
        {
            $this->helper->log("Invalid pickup address. There are mistakes or missing fields.");
            return $pickupAddress;
        }

        // Load product image for first ordered item
        $image = null;
        $orderedItems = $order->getAllVisibleItems();
        if (count($orderedItems) > 0) {
            foreach ($orderedItems AS $orderedItem) {
                $_product = $this->_productloader->create()->load($orderedItem->getProductId());
                $imageUrl = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA) . 'catalog/product' . $_product->getImage();
                try {
                    if (!empty($_product->getImage())) {
                        $data = @file_get_contents($imageUrl);
                        if ($data) {
                            $base64Image = base64_encode($data);
                        } else {
                            $base64Image = null;
                        }
                    } else {
                        $base64Image = null;
                    }
                } catch (Exception $e) {
                    $base64Image = null;
                }
                if (!is_null($base64Image)) {
                    // Break after first image
                    $image = $base64Image;
                    break;
                }
            }
        }

        $preferredServiceLevel = null;
        $usedShippingMethod = $order->getShippingMethod();
        for ($i = 1; $i < 5; $i++) {
            if ($this->scopeConfig->getValue('wuunder_wuunderconnector/advanced/filtermapping_' . $i . '_carrier') === $usedShippingMethod) {
                $preferredServiceLevel = $this->scopeConfig->getValue('wuunder_wuunderconnector/advanced/filtermapping_' . $i . '_filter');
                break;
            }
        }

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        $version = $productMetadata->getVersion();

        $bookingConfig = new \Wuunder\Api\Config\BookingConfig();
//        $bookingConfig->setWebhookUrl($webhookUrl);
//        $bookingConfig->setRedirectUrl($redirectUrl);
        $bookingConfig->setDescription($infoArray['description']);
        $bookingConfig->setKind(null);
//        $bookingConfig->setValue($value ? $value : 0);
//        $bookingConfig->setLength(round($dimensions[0]));
//        $bookingConfig->setWidth(round($dimensions[1]));
//        $bookingConfig->setHeight(round($dimensions[2]));
//        $bookingConfig->setWeight($totalWeight ? $totalWeight : 0);
        $bookingConfig->setPreferredServiceLevel($preferredServiceLevel);
        $bookingConfig->setSource(array("product" => "Magento 2 extension", "version" => array("build" => "1.0.5", "plugin" => "1.0"), "platform" => array("name" => "Magento", "build" => $version)));
        $bookingConfig->setDeliveryAddress($deliveryAddress);
        $bookingConfig->setPickupAddress($pickupAddress);

        return $bookingConfig;
    }

    private function addressSplitter($address)
    {
        if (!isset($address)) {
            return false;
        }

        // Pregmatch pattern, dutch addresses
        $pattern = '#^([a-z0-9 [:punct:]\']*) ([0-9]{1,5})([a-z0-9 \-/]{0,})$#i';

        preg_match($pattern, $address, $addressParts);

        $result['streetName'] = isset($addressParts[1]) ? $addressParts[1] : $address;
        $result['houseNumber'] = isset($addressParts[2]) ? $addressParts[2] : "";
        $result['houseNumberSuffix'] = (isset($addressParts[3])) ? $addressParts[3] : '';

        return $result;
    }
}
