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

            $this->helper->log("Fetching boxes", '/var/log/ecobliss.log');
            // This is how the info should be fetched using the X-COM system.
            // $json = $order->getPackingDetails();

            // This is the example json
            $json = file_get_contents("app/code/Wuunder/packing-details.json");
            $packingDetail = json_decode($json, true);

            // Find largest of the boxes and the total number to be sent
            $numBoxes = count($packingDetail['boxes']);
            $this->helper->log('The number of boxes is: ' . $numBoxes, '/var/log/ecobliss.log');

            $biggestBox = 0;
            foreach ($packingDetail['boxes'] as $box)
            {
                $size = (int)$box["length"] + ((int)$box["width"]*2) + ((int)$box["depth"]*2);
                // $this->helper->log('Size of box: ' . $size, '/var/log/ecobliss.log');
                if ($size > $biggestBox)
                {
                    $biggestBox = $size;
                    // $this->helper->log('This one is bigger', '/var/log/ecobliss.log');
                    $boxDimensions = array('length' => (int)($box["length"]*.1),
                                           'width'  => (int)($box["width"]*.1),
                                           'height' => (int)($box["depth"]*.1),
                                           // For Wuunder the weight should be in grams, not sure if these values are kilo's? If so: *1000
                                           'weight' => $box["weight"]);
                }
            }
            $this->helper->log('Biggest box found', '/var/log/ecobliss.log');

            // Add the dimensions from the biggest box here, in the webhook we'll take these values from the response and only take the number of boxes from the DB
            // Combine wuunder info and order data
            $this->helper->log('Building Wuunder Data', '/var/log/ecobliss.log');
            $wuunderData = $this->buildWuunderData($infoArray, $order, $boxDimensions);
            $header = $this->helper->curlRequest($wuunderData, $apiUrl, $apiKey, true);

            // Get redirect url from header
            preg_match("!\r\n(?:Location|URI): *(.*?) *\r\n!i", $header, $matches);
            $redirect_url = $matches[1];

            // Create or update wuunder_shipment
            $this->saveWuunderShipment($orderId, $redirect_url, "testtoken", $numBoxes);
        }
        return $redirect_url;
    }

    private function saveWuunderShipment($orderId, $bookingUrl, $bookingToken, $numBoxes)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $wuunderShipment = $objectManager->create('Wuunder\Wuunderconnector\Model\WuunderShipment');
        $wuunderShipment->load($this->getRequest()->getParam('order_id') , 'order_id');
        $wuunderShipment->setOrderId($orderId);
        $wuunderShipment->setBookingUrl($bookingUrl);
        $wuunderShipment->setBookingToken($bookingToken);
        $wuunderShipment->setBoxesOrder($numBoxes);
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

    private function buildWuunderData($infoArray, $order, $boxDimensions)
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

        $customerAdr = array(
            'business' => $company,
            'email_address' => ($order->getCustomerEmail() !== '' ? $order->getCustomerEmail() : $shippingAddress->getEmail()),
            'family_name' => $lastname,
            'given_name' => $firstname,
            'locality' => $shippingAddress->getCity(),
            'phone_number' => $infoArray['phone_number'],
            'street_name' => $streetName,
            'house_number' => $houseNumber,
            'zip_code' => $shippingAddress->getPostcode(),
            'country' => $shippingAddress->getCountryId()
        );

        $webshopAdr = array(
            'business' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/company'),
            'email_address' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/email'),
            'family_name' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/lastname'),
            'given_name' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/firstname'),
            'locality' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/city'),
            'phone_number' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/phone'),
            'street_name' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/streetname'),
            'house_number' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/housenumber'),
            'zip_code' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/zipcode'),
            'country' => $this->scopeConfig->getValue('wuunder_wuunderconnector/general/country')
        );

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

        return array(
            'description' => $infoArray['description'],
            'personal_message' => $infoArray['personal_message'],
            'picture' => $image,
            'customer_reference' => $order->getIncrementId(),
            'delivery_address' => $customerAdr,
            'pickup_address' => $webshopAdr,
            'preferred_service_level' => $preferredServiceLevel,
            'source' => array("product" => "Magento 2 extension", "version" => array("build" => "1.0.5", "plugin" => "1.0")),
            'length' => $boxDimensions["length"],
            'width'  => $boxDimensions["width"],
            'height' => $boxDimensions["height"],
            'weight' => $boxDimensions["weight"]
        );
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
