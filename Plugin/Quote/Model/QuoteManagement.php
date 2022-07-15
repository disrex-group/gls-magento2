<?php
/**
 *
 *          ..::..
 *     ..::::::::::::..
 *   ::'''''':''::'''''::
 *   ::..  ..:  :  ....::
 *   ::::  :::  :  :   ::
 *   ::::  :::  :  ''' ::
 *   ::::..:::..::.....::
 *     ''::::::::::::''
 *          ''::''
 *
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to servicedesk@tig.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@tig.nl for more information.
 *
 * @copyright   Copyright (c) Total Internet Group B.V. https://tig.nl/copyright
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */

namespace TIG\GLS\Plugin\Quote\Model;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Quote\Model\QuoteManagement as MagentoQuoteManagement;
use TIG\GLS\Model\Config\Provider\Carrier;
use TIG\GLS\Service\DeliveryOptions\Services as ServicesService;

class QuoteManagement
{
    /** @var CartRepositoryInterface $cartRepository */
    private $cartRepository;

    /** @var OrderRepositoryInterface $orderRepository */
    private $orderRepository;

    /** @var ServicesService $services */
    private $services;

    /** @var Carrier $carrier */
    private $carrier;

    /**
     * QuoteManagement constructor.
     *
     * @param CartRepositoryInterface $cartRepository
     */
    public function __construct(
        CartRepositoryInterface $cartRepository,
        OrderRepositoryInterface $orderRepository,
        ServicesService $services,
        Carrier $carrier
    ) {
        $this->cartRepository  = $cartRepository;
        $this->orderRepository = $orderRepository;
        $this->services = $services;
        $this->carrier = $carrier;
    }

    public function beforeSubmit(
        MagentoQuoteManagement $subject,
                        $quote,
                        $data = []
    ) {
        /* @var $quote \Magento\Quote\Model\Quote */
        $payment         = $quote->getPayment()->getMethod();
        if(trim($payment) == 'channable') {
            $isChannableOrder = true; // it is a channable order
        } else {
            $isChannableOrder = false;
        }
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress  = $quote->getBillingAddress();
        $deliveryOption  = $shippingAddress->getGlsDeliveryOption();

        if (!$deliveryOption) {
            if($this->carrier->getAllowChannableOrderService() && $isChannableOrder) {
                $deliveryOption = $this->getDeliveryOptionsForApiOrder($shippingAddress, $billingAddress);
                $deliveryOption = json_decode($deliveryOption);
                $type           = $deliveryOption->type;

                if (!isset($deliveryOption) || !$deliveryOption) {
                    // nothing
                } else {
                    if (!isset($deliveryOption->deliveryAddress)) {
                        $deliveryOption->deliveryAddress = $this->mapDeliveryAddress($shippingAddress, $billingAddress);
                        $shippingAddress->setGlsDeliveryOption(json_encode($deliveryOption));
                    }
                    $shippingAddress->setGlsDeliveryOption(json_encode($deliveryOption));
                    if ($type == Carrier::GLS_DELIVERY_OPTION_PARCEL_SHOP_LABEL) {
                        $this->changeShippingAddress($deliveryOption->details, $shippingAddress);
                    }
                }
            }
        }

        return [$quote, $data];
    }

    /**
     * @param $subject
     * @param $cartId
     *
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    // @codingStandardsIgnoreLine
    public function beforePlaceOrder($subject, $cartId)
    {
        $isChannableOrder = false; // is it a channable order
        $quote           = $this->cartRepository->getActive($cartId);
        $payment         = $quote->getPayment()->getMethod();
        if(trim($payment) == 'channable') {
            $isChannableOrder = true; // it is a channable order
        }
        $shippingAddress = $quote->getShippingAddress();
        $billingAddress  = $quote->getBillingAddress();
        $deliveryOption  = $shippingAddress->getGlsDeliveryOption();
        $apiOrder = false;

        if (!$deliveryOption) {
            if($this->carrier->getAllowApiOrderService() || ($this->carrier->getAllowChannableOrderService() && $isChannableOrder)) {
                $deliveryOption = $this->getDeliveryOptionsForApiOrder($shippingAddress, $billingAddress);
                if (!isset($deliveryOption) || !$deliveryOption) {
                    return;
                }
                $apiOrder = true;
            } else {
                return;
            }
        }

        $deliveryOption = json_decode($deliveryOption);
        $type           = $deliveryOption->type;

        if (!isset($deliveryOption->deliveryAddress)) {
            $deliveryOption->deliveryAddress = $this->mapDeliveryAddress($shippingAddress, $billingAddress);
            $shippingAddress->setGlsDeliveryOption(json_encode($deliveryOption));
        }
        if($apiOrder) {
            $shippingAddress->setGlsDeliveryOption(json_encode($deliveryOption));
        }

        if ($type == Carrier::GLS_DELIVERY_OPTION_PARCEL_SHOP_LABEL) {
            $this->changeShippingAddress($deliveryOption->details, $shippingAddress);
        }
    }

    /**
     * getDeliveryOptionsForApiOrder
     *
     * If order is placed through Magento API and auto-select is enabled
     * or it is a channable order and auto-select is enabled
     * order gets first available delivery time.
     *
     * @param $shippingAddress
     * @return false|string
     */
    public function getDeliveryOptionsForApiOrder($shippingAddress, $billingAddress)
    {
        $countryCode = $languageCode = $shippingAddress->getCountryId();
        $postcode = $shippingAddress->getPostcode();
        $services = $this->services->getDeliveryOptions($countryCode, 'NL', $postcode);
        $deliveryOptions = (isset($services['deliveryOptions'])) ? $services['deliveryOptions'] : null;
        if(isset($deliveryOptions)) {
            // filter options to avoid saterday service and other disabeld options
            if(is_array($deliveryOptions) && count($deliveryOptions) > 1) {
                $deliveryOptions = $this->filterDeliveryOptions($deliveryOptions);
            }
            $deliveryAddress = $this->mapDeliveryAddress($shippingAddress, $billingAddress);
            $autoSelectDelivery = $deliveryOptions[0];
            $autoSelectDelivery['isService'] = false;
            $autoSelectDelivery['hasSubOptions'] = false;
            $autoSelectDelivery['fee'] = null;
            $autoSelectDeliveryResultsArray = array(
                'type' => 'deliveryService',
                'details' => $autoSelectDelivery,
                'deliveryAddress' => $deliveryAddress
            );
            return json_encode($autoSelectDeliveryResultsArray);
        } else {
            return null;
        }
    }

    /**
     * filterDeliveryOptions
     *
     * Filter options for auto-select
     *
     * @param $options
     * @return array
     */
    private function filterDeliveryOptions(&$options)
    {
        $isExpressServicesActive = $this->carrier->isExpressParcelActive();
        $isSaturdayServiceActive = $this->carrier->isSaturdayServiceActive();
        $options = array_filter(
            $options,
            function ($details) use ($isExpressServicesActive, $isSaturdayServiceActive) {
                // Always allow BusinessParcel (the default service)
                return !isset($details['service'])
                    // Allow SaturdayService if active.
                    || ($isSaturdayServiceActive
                        && ($details['service'] == CarrierConfig::GLS_DELIVERY_OPTION_SATURDAY_LABEL))
                    // Allow Express Delivery Services if active.
                    || ($isExpressServicesActive
                        && ($details['service'] == CarrierConfig::GLS_DELIVERY_OPTION_EXPRESS_LABEL));
            }
        );
        $options = array_values($options);

        return $options;
    }

    /**
     * We're saving the DeliveryAddress in the format required by GLS API, so we
     * can always provide it in the same way for either service type.
     *
     * @param $shipping
     *
     * @return object
     */
    public function mapDeliveryAddress($shipping, $billing)
    {
        $company = $shipping->getCompany();
        $shippingName = $shipping->getName();
        if(strlen($shippingName) > 30) {
            $shippingName = substr($shippingName, 0, 30);
        }
        $phone = $shipping->getTelephone();
        if(isset($phone) && strlen($phone) > 15) {
            $phone = str_replace(' ','', $phone);
            if(strlen($phone) > 15) {
                $phone = substr($phone,0,15);
            }
        }
        if(isset($company) && strlen($company) > 2) {
            if(strlen($company) > 30) {
                $company = substr($company, 0, 30);
            }
            return (object)[
                'name1' => $company,
                'street' => $shipping->getStreetLine(1),
                'houseNo' => substr($shipping->getStreetLine(2), 0, 10),
                'name2' => $shippingName,
                'name3' => $shipping->getStreetLine(3),
                'countryCode' => $shipping->getCountryId(),
                'zipCode' => $shipping->getPostcode(),
                'city' => $shipping->getCity(),
                // If Shipping Address is same as Billing Address, Email is only saved in Billing.
                'email' => $shipping->getEmail() ?: $billing->getEmail(),
                'phone' => $phone ?: '+00000000000',
                'addresseeType' => $shipping->getCompany() ? 'b' : 'p'
            ];
        } else {
            return (object)[
                'name1' => $shippingName,
                'street' => $shipping->getStreetLine(1),
                'houseNo' => substr($shipping->getStreetLine(2), 0, 10),
                'name2' => $shipping->getStreetLine(2),
                'name3' => $shipping->getStreetLine(3),
                'countryCode' => $shipping->getCountryId(),
                'zipCode' => $shipping->getPostcode(),
                'city' => $shipping->getCity(),
                // If Shipping Address is same as Billing Address, Email is only saved in Billing.
                'email' => $shipping->getEmail() ?: $billing->getEmail(),
                'phone' => $phone ?: '+00000000000',
                'addresseeType' => $shipping->getCompany() ? 'b' : 'p'
            ];
        }
    }

    /**
     * @param $newAddress
     * @param $shippingAddress
     *
     * @return mixed
     */
    private function changeShippingAddress($newAddress, $shippingAddress)
    {
        $shippingAddress->setStreet($newAddress->street . ' ' . $newAddress->houseNo);
        $shippingAddress->setCompany($newAddress->name);
        $shippingAddress->setPostcode($newAddress->zipcode);
        $shippingAddress->setCity($newAddress->city);
        $shippingAddress->setCountryId($newAddress->countryCode);

        return $shippingAddress;
    }

    /**
     * @param $subject
     * @param $orderId
     * @param $quoteId
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    // @codingStandardsIgnoreLine
    public function afterPlaceOrder($subject, $orderId, $quoteId)
    {
        $order = $this->orderRepository->get($orderId);

        if ($order->getGlsDeliveryOption()) {
            return $orderId;
        }

        $quote          = $this->cartRepository->get($quoteId);
        $address        = $quote->getShippingAddress();
        $deliveryOption = $address->getGlsDeliveryOption();

        if (!$deliveryOption) {
            return $orderId;
        }

        $order->setGlsDeliveryOption($deliveryOption);
        $order->save();

        return $orderId;
    }
}
