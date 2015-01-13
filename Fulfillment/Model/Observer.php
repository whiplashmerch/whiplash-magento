<?php
/**
* Whiplash
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@magentocommerce.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Please do not edit or add to this file if you wish to upgrade
* Magento or this extension to newer versions in the future.
* Whiplash developers (Whiplasher's) give their best to conform to
* "non-obtrusive, best Magento practices" style of coding.
* However, Whiplash does not guarantee functional accuracy of
* specific extension behavior. Additionally we take no responsibility
* for any possible issue(s) resulting from extension usage.
* We reserve the full right not to provide any kind of support for our free extensions.
* Thank you for your understanding.
*
* @category Whiplash
* @package Fulfillment
* @author James Marks <james@whiplashmerch.com> based on the work of Marko Martinović <marko.martinovic@inchoo.net>
* @copyright Copyright (c) Whiplash Merch (http://whiplashmerch.com/)
* @license http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
*/

class Whiplash_Fulfillment_Model_Observer extends Varien_Object
{

	protected function getStoreId()
	{
		$storeId = Mage::app()->getStore()->getId();

		if($storeId == 0) // If the store id is 0 then we are in the admin area
		{
			// We want to make sure that whiplash is enabled for the requested store, if there is a requested store
			$storeId = Mage::app()->getRequest()->getParam('store', Mage::getStoreConfig('whiplash/api/default_store_to_update')); //storeId will contain either a store id, or the "Default Store [id] to Update" if no specific store was requested
		}

		return $storeId;
	}

	protected function isEnabledForStoreId($storeId)
	{
		return Mage::getStoreConfig('whiplash/api/enabled',$storeId);
	}

    protected function init_whiplash()
    {
        // Set the API credentials
        $api_key = Mage::getStoreConfig('whiplash/api/key'); // Whiplash Sandbox on Testing Server
        $api_version = Mage::getStoreConfig('whiplash/api/version'); // OPTIONAL: Leave this blank to use the most recent API
		$storeId = $this->getStoreId();
		$test = Mage::getStoreConfig('whiplash/api/test_mode'); // OPTIONAL: If test is true, this will use your sandbox account

		// Include the Whiplash lib and initialize
        $ExternalLibPath=Mage::getBaseDir('code') . DS . 'community' . DS . 'Whiplash' . DS . 'Fulfillment'. DS . 'lib' . DS .'WhiplashApi.php';
        require_once ($ExternalLibPath);
        $api = new WhiplashApi($api_key, $api_version, $storeId, $test);
        return $api;
    }

    public function update_or_create_item($observer)
    // Creates or updates an item in Whiplash
    {
		if($this->isEnabledForStoreId($this->getStoreId()))
		{
			$api = $this->init_whiplash();
			$_product = $observer->getProduct();
			if($_product->getTypeID() == 'simple'){

				$parentProduct = Mage::getResourceSingleton('catalog/product_type_configurable')
					->getParentIdsByChild($_product->getId());

				if(is_array($parentProduct) && !empty($parentProduct))
				{
					$parentProduct = Mage::getModel('catalog/product')->load(array_pop($parentProduct));
					if($parentProduct && $parentProduct->getId())
					{
						$image = $parentProduct->getMediaConfig()->getMediaUrl($parentProduct->getData('image'));
					}
				}

				// Get the expected quantity
				$quantity = Mage::getModel('cataloginventory/stock_item')->loadByProduct($_product)->getQty();

				$item = array(
					'sku'                   => $_product->getSku(),
					'title'                 => $_product->getName(),
					'exp_quantity'          => $quantity,
					'image_originator_url'  => $image?$image:$_product->getMediaConfig()->getMediaUrl($_product->getData('image')),
					'price'                 => $_product->getPrice(),
					'wholesale_cost'        => $_product->getCost(),
					'originator_id'         => $_product->getId()
					);

				// Check if the item exists in Whiplash
				$whiplash_item = $api->get_item_by_originator($_product->getId());
				if (!$whiplash_item){
				// We didn't get an item back, create it
					$api->create_item($item);            }
				// Item exists in Whiplash, so update it
				else {
					$api->update_item($whiplash_item->id,$item);
				}
			} // Failed type == simple test, ignore item
        }
    }


    public function create_order($observer)
    // Creates an order and order_items in Whiplash
    {
		$api = $this->init_whiplash();
		$_order = $observer->getEvent()->getInvoice()->getOrder();
		if($this->isEnabledForStoreId($_order->getStoreId()))
		{
			$_shippingAddress = $_order->getShippingAddress();
			$_shippingMethod = $_shippingAddress->getAddressShippingMethod();

			// Translate magento fields for whiplash
			$_shipping_name = $_shippingAddress->getFirstname() . " " . $_shippingAddress->getLastname();
			$order_attributes = array(
					'shipping_name'         => $_shipping_name,
					'shipping_company'      => $_shippingAddress->getCompany(),
					'shipping_address_1'    => $_shippingAddress->getStreetFull(), // All address lines gets truncated into 1
					'shipping_city'         => $_shippingAddress->getCity(),
					'shipping_state'        => $_shippingAddress->getRegion(),
					'shipping_zip'          => $_shippingAddress->getPostcode(),
					'shipping_country'      => $_shippingAddress->getCountry_id(),
					'shipping_phone'		=> $_shippingAddress->getTelephone(),
					'email'                 => $_order->getCustomerEmail(),
					'originator_id'         => $_order->getRealOrderId(),
					'order_orig'            => $_order->getRealOrderId(),
					'req_ship_method_text'  => $_order->getShippingMethod(),
					'req_ship_method_price' => $_order->getShippingAmount(),
					'order_items_attributes' => array()
				);

			// Add the order_items
			$items = $_order->getAllVisibleItems();
			$i = 0;
			foreach ($items as $itemId => $item)
				{
					if ($item->getQtyOrdered() > 0){
						// There are master items on the invoice, we only want the 'real' items
						$whiplash_item = $api->get_items_by_sku($item->getSku()); // This is an array; we want to the first result
						// Find the id of the whiplash item
						if ( is_array($whiplash_item) ) {
							$whiplash_item = $whiplash_item[0];
							$order_attributes['order_items'][$i] = array('quantity' => $item->getQtyOrdered(), 'item_id' => $whiplash_item->id, 'originator_id' => $item->getId(), 'price' => $item->getRowTotalInclTax()/$item->getQtyOrdered());
							$i += 1;
						}
					}
				}
			// Post to Whiplash
			$order = $api->create_order($order_attributes);
		}
    }

    // public function update_order($observer){
    // Not currently supported. This could overwrite intentional changes in Whiplash. Possibly see which side is newer to allow it through?

    //     // Updates the address and shipping method in Whiplash

    //     // Translate magento fields for whiplash
    //     $api = $this->init_whiplash();
    //     $_order = $observer->getOrder();
    //     $_shippingAddress = $_order->getShippingAddress();
    //     $_shippingMethod = $_shippingAddress->getAddressShippingMethod();
    //     $_shipping_name = $_shippingAddress->getFirstname() . " " . $_shippingAddress->getLastname();
    //     $order_attributes = array(
    //             'shipping_name'         => $_shipping_name,
    //             'shipping_company'      => $_shippingAddress->getCompany(),
    //             'shipping_address_1'    => $_shippingAddress->getStreetFull(), // All address lines gets truncated into 1
    //             'shipping_city'         => $_shippingAddress->getCity(),
    //             'shipping_state'        => $_shippingAddress->getRegion(),
    //             'shipping_zip'          => $_shippingAddress->getPostcode(),
    //             'shipping_country'      => $_shippingAddress->getCountry_id(),
    //             'email'                 => $_order->getCustomerEmail(),
    //             'originator_id'         => $_order->getEntity_id(), 
    //             'order_orig'            => $_order->getRealOrderId(),
    //             'req_ship_method_text'  => $_order->getShipping_method(),
    //             'req_ship_method_price' => $_order->getShipping_amount()
    //         );

    //     // Find the Whiplash order
    //     $whiplash_order = $api->get_order_by_originator($_order->getEntity_id());

    //     // Post to Whiplash
    //     $order = $api->update_order($whiplash_order->id, $order_attributes); 


    // }

}
