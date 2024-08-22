<?php
/**
 * 2007-2020 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
class Cart extends CartCore
{
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public static $wkIdProductAttribute;
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public function getPackageList($flush = false)
    {
        if (!Module::isEnabled('preorder')) {
            return parent::getPackageList($flush);
        }
        $cache_key = (int) $this->id . '_' . (int) $this->id_address_delivery;
        if (_PS_VERSION_ > '1.7.2.4') {
            if (isset(static::$cachePackageList[$cache_key])
            && static::$cachePackageList[$cache_key] !== false
            && !$flush) {
                return static::$cachePackageList[$cache_key];
            }
        } else {
            static $cache = [];
            if (isset($cache[$cache_key]) && $cache[$cache_key] !== false && !$flush) {
                return $cache[$cache_key];
            }
        }
        $product_list = $this->getProducts($flush);
        $warehouse_count_by_address = [];
        $stock_management_active = Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT');
        foreach ($product_list as &$product) {
            if ((int) $product['id_address_delivery'] == 0) {
                $product['id_address_delivery'] = (int) $this->id_address_delivery;
            }
            if (!isset($warehouse_count_by_address[$product['id_address_delivery']])) {
                $warehouse_count_by_address[$product['id_address_delivery']] = [];
            }
            $product['warehouse_list'] = [];
            if ($stock_management_active
                && (int) $product['advanced_stock_management'] == 1) {
                $warehouse_list = Warehouse::getProductWarehouseList(
                    $product['id_product'],
                    $product['id_product_attribute'],
                    $this->id_shop
                );
                if (count($warehouse_list) == 0) {
                    $warehouse_list = Warehouse::getProductWarehouseList(
                        $product['id_product'],
                        $product['id_product_attribute']
                    );
                }
                $warehouse_in_stock = [];
                $manager = StockManagerFactory::getManager();
                foreach ($warehouse_list as $key => $warehouse) {
                    $product_real_quantities = $manager->getProductRealQuantities(
                        $product['id_product'],
                        $product['id_product_attribute'],
                        [$warehouse['id_warehouse']],
                        true
                    );
                    if ($product_real_quantities > 0 || Pack::isPack((int) $product['id_product'])) {
                        $warehouse_in_stock[] = $warehouse;
                    }
                }
                if (!empty($warehouse_in_stock)) {
                    $warehouse_list = $warehouse_in_stock;
                    $product['in_stock'] = true;
                } else {
                    $product['in_stock'] = false;
                }
            } else {
                $warehouse_list = [0 => ['id_warehouse' => 0]];
                $product['in_stock'] = StockAvailable::getQuantityAvailableByProduct(
                    $product['id_product'],
                    $product['id_product_attribute']
                ) > 0;
            }
            foreach ($warehouse_list as $warehouse) {
                $product['warehouse_list'][$warehouse['id_warehouse']] = $warehouse['id_warehouse'];
                if (!isset($warehouse_count_by_address[$product['id_address_delivery']][$warehouse['id_warehouse']])) {
                    $warehouse_count_by_address[$product['id_address_delivery']][$warehouse['id_warehouse']] = 0;
                }
                ++$warehouse_count_by_address[$product['id_address_delivery']][$warehouse['id_warehouse']];
            }
        }
        unset($product);
        arsort($warehouse_count_by_address);
        $grouped_by_warehouse = [];
        foreach ($product_list as &$product) {
            if (!isset($grouped_by_warehouse[$product['id_address_delivery']])) {
                $grouped_by_warehouse[$product['id_address_delivery']] = [
                    'in_stock' => [],
                    'out_of_stock' => [],
                ];
            }
            $product['carrier_list'] = [];
            $id_warehouse = 0;
            foreach ($warehouse_count_by_address[$product['id_address_delivery']] as $id_war => $val) {
                if (array_key_exists((int) $id_war, $product['warehouse_list'])) {
                    Cart::$wkIdProductAttribute = $product['id_product_attribute'];
                    $product['carrier_list'] = array_replace(
                        $product['carrier_list'],
                        Carrier::getAvailableCarrierList(
                            new Product($product['id_product']),
                            $id_war,
                            $product['id_address_delivery'],
                            null,
                            $this
                        )
                    );
                    if (!$id_warehouse) {
                        $id_warehouse = (int) $id_war;
                    }
                }
            }
            if (!isset($grouped_by_warehouse[$product['id_address_delivery']]['in_stock'][$id_warehouse])) {
                $grouped_by_warehouse[$product['id_address_delivery']]['in_stock'][$id_warehouse] = [];
                $grouped_by_warehouse[$product['id_address_delivery']]['out_of_stock'][$id_warehouse] = [];
            }
            if (!$this->allow_seperated_package) {
                $key = 'in_stock';
            } else {
                $key = $product['in_stock'] ? 'in_stock' : 'out_of_stock';
                $product_quantity_in_stock = StockAvailable::getQuantityAvailableByProduct(
                    $product['id_product'],
                    $product['id_product_attribute']
                );
                if ($product['in_stock'] && $product['cart_quantity'] > $product_quantity_in_stock) {
                    $out_stock_part = $product['cart_quantity'] - $product_quantity_in_stock;
                    $product_bis = $product;
                    $product_bis['cart_quantity'] = $out_stock_part;
                    $product_bis['in_stock'] = 0;
                    $product['cart_quantity'] -= $out_stock_part;
                    $grouped_by_warehouse[$product['id_address_delivery']]['out_of_stock'][$id_warehouse][] =
                    $product_bis;
                }
            }
            if (empty($product['carrier_list'])) {
                $product['carrier_list'] = [0 => 0];
            }
            $grouped_by_warehouse[$product['id_address_delivery']][$key][$id_warehouse][] = $product;
        }
        unset($product);
        $grouped_by_carriers = [];
        foreach ($grouped_by_warehouse as $id_address_delivery => $products_in_stock_list) {
            if (!isset($grouped_by_carriers[$id_address_delivery])) {
                $grouped_by_carriers[$id_address_delivery] = [
                    'in_stock' => [],
                    'out_of_stock' => [],
                ];
            }
            foreach ($products_in_stock_list as $key => $warehouse_list) {
                if (!isset($grouped_by_carriers[$id_address_delivery][$key])) {
                    $grouped_by_carriers[$id_address_delivery][$key] = [];
                }
                foreach ($warehouse_list as $id_warehouse => $product_list) {
                    if (!isset($grouped_by_carriers[$id_address_delivery][$key][$id_warehouse])) {
                        $grouped_by_carriers[$id_address_delivery][$key][$id_warehouse] = [];
                    }
                    foreach ($product_list as $product) {
                        $package_carriers_key = implode(',', $product['carrier_list']);
                        if (!isset($grouped_by_carriers[
                            $id_address_delivery
                        ][$key][$id_warehouse][$package_carriers_key])) {
                            $grouped_by_carriers[$id_address_delivery][$key][$id_warehouse][$package_carriers_key] =
                            [
                                'product_list' => [],
                                'carrier_list' => $product['carrier_list'],
                                'warehouse_list' => $product['warehouse_list'],
                            ];
                        }
                        $grouped_by_carriers[$id_address_delivery][$key][$id_warehouse][$package_carriers_key]['product_list'][] = $product;
                    }
                }
            }
        }
        $package_list = [];
        foreach ($grouped_by_carriers as $id_address_delivery => $products_in_stock_list) {
            if (!isset($package_list[$id_address_delivery])) {
                $package_list[$id_address_delivery] = [
                    'in_stock' => [],
                    'out_of_stock' => [],
                ];
            }
            foreach ($products_in_stock_list as $key => $warehouse_list) {
                if (!isset($package_list[$id_address_delivery][$key])) {
                    $package_list[$id_address_delivery][$key] = [];
                }
                $carrier_count = [];
                foreach ($warehouse_list as $id_warehouse => $products_grouped_by_carriers) {
                    foreach ($products_grouped_by_carriers as $data) {
                        foreach ($data['carrier_list'] as $id_carrier) {
                            if (!isset($carrier_count[$id_carrier])) {
                                $carrier_count[$id_carrier] = 0;
                            }
                            ++$carrier_count[$id_carrier];
                        }
                    }
                }
                arsort($carrier_count);
                foreach ($warehouse_list as $id_warehouse => $products_grouped_by_carriers) {
                    if (!isset($package_list[$id_address_delivery][$key][$id_warehouse])) {
                        $package_list[$id_address_delivery][$key][$id_warehouse] = [];
                    }
                    foreach ($products_grouped_by_carriers as $data) {
                        foreach ($carrier_count as $id_carrier => $rate) {
                            if (array_key_exists($id_carrier, $data['carrier_list'])) {
                                if (!isset($package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier])) {
                                    $package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier] = [
                                        'carrier_list' => $data['carrier_list'],
                                        'warehouse_list' => $data['warehouse_list'],
                                        'product_list' => [],
                                    ];
                                }
                                $package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['carrier_list'] =
                                    array_intersect(
                                        $package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['carrier_list'],
                                        $data['carrier_list']
                                    );
                                $package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['product_list'] =
                                    array_merge(
                                        $package_list[$id_address_delivery][$key][$id_warehouse][$id_carrier]['product_list'],
                                        $data['product_list']
                                    );
                                break;
                            }
                        }
                    }
                }
            }
        }
        $final_package_list = [];
        foreach ($package_list as $id_address_delivery => $products_in_stock_list) {
            if (!isset($final_package_list[$id_address_delivery])) {
                $final_package_list[$id_address_delivery] = [];
            }
            foreach ($products_in_stock_list as $key => $warehouse_list) {
                foreach ($warehouse_list as $id_warehouse => $products_grouped_by_carriers) {
                    foreach ($products_grouped_by_carriers as $data) {
                        $final_package_list[$id_address_delivery][] = [
                            'product_list' => $data['product_list'],
                            'carrier_list' => $data['carrier_list'],
                            'warehouse_list' => $data['warehouse_list'],
                            'id_warehouse' => $id_warehouse,
                        ];
                    }
                }
            }
        }
        if (_PS_VERSION_ > '1.7.2.4') {
            static::$cachePackageList[$cache_key] = $final_package_list;
        } else {
            $cache[$cache_key] = $final_package_list;
        }
        return $final_package_list;
    }
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public function checkQuantities($returnProductOnFailure = false)
    {
        if (Configuration::isCatalogMode() && !defined('_PS_ADMIN_DIR_')) {
            return false;
        }
        foreach ($this->getProducts() as $product) {
            if (!$this->allow_seperated_package
                && !$product['allow_oosp']
                && StockAvailable::dependsOnStock($product['id_product'])
                && $product['advanced_stock_management']
                && (bool) Context::getContext()->customer->isLogged()
                && ($delivery = $this->getDeliveryOption())
                && !empty($delivery)
            ) {
                $product['stock_quantity'] = StockManager::getStockByCarrier(
                    (int) $product['id_product'],
                    (int) $product['id_product_attribute'],
                    $delivery
                );
            }
            if (!$product['active']
                || !$product['available_for_order']
                || (!$product['allow_oosp'] && $product['stock_quantity'] < $product['cart_quantity'])
            ) {
                return $returnProductOnFailure ? $product : false;
            }
            if (!$product['allow_oosp']) {
                $productQuantity = Product::getQuantity(
                    $product['id_product'],
                    $product['id_product_attribute'],
                    null,
                    $this,
                    $product['id_customization']
                );
                if ($productQuantity < 0) {
                    return $returnProductOnFailure ? $product : false;
                }
            }
            if (Module::isEnabled('preorder')) {
                include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
                $context = Context::getContext();
                $idCustomer = Context::getContext()->cart->id_customer;
                $preorderObj = new PreOrderProduct();
                $existingPreorderProduct = $preorderObj->getExistingActivePreOrderProduct(
                    $product['id_product'],
                    $product['id_product_attribute']
                );
                if ($existingPreorderProduct) {
                    if (Configuration::get('PS_GUEST_CHECKOUT_ENABLED')) {
                        if (!Configuration::get('WK_GUEST_PREORDER_ENABLED')) {
                            if ($existingPreorderProduct['is_preorder'] == 1
                                && $existingPreorderProduct['payment_type'] != 1) {
                                if (!$idCustomer) {
                                    return $returnProductOnFailure ? $product : false;
                                } else {
                                    $defaultGroupId = Customer::getDefaultGroupId($idCustomer);
                                    if ($defaultGroupId == Configuration::get('PS_GUEST_GROUP')) {
                                        return $returnProductOnFailure ? $product : false;
                                    }
                                }
                            }
                        }
                    }
                    $remainingQty = $existingPreorderProduct['maxquantity'] -
                    $existingPreorderProduct['prebooked_quantity'];
                    if ($product['cart_quantity'] > $remainingQty) {
                        return $returnProductOnFailure ? $product : false;
                    }
                }
                if ($preorderObj->getExistingPreOrderByProductId($product['id_product'])) {
                    $availableQuantity = StockAvailable::getQuantityAvailableByProduct(
                        $product['id_product'],
                        $product['id_product_attribute']
                    );
                    $existingPreorderProductData = $preorderObj->getExistingPreOrderProduct(
                        $product['id_product'],
                        $product['id_product_attribute']
                    );
                    if ($existingPreorderProductData) {
                        $objPreorderCustomer = new PreorderProductCustomer();
                        $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                            $product['id_product'],
                            $product['id_product_attribute'],
                            Context::getContext()->customer->id,
                            Context::getContext()->shop->id
                        );
                        if ($checkCookie) {
                            $completePreorder = 1;
                        }
                        if (isset($completePreorder)) {
                            $preorderCustomerObj = new PreorderProductCustomer();
                            $existingCustomerPreorder = $preorderCustomerObj->getCustomerPreOrderByIdPIdCIdO(
                                (int) $idCustomer,
                                $product['id_product'],
                                $product['id_product_attribute'],
                                $checkCookie['old_order_id']
                            );
                            unset($completePreorder);
                            if ($existingCustomerPreorder) {
                                $wkAllowedQty = (int) ($existingCustomerPreorder['quantity'] -
                                $existingCustomerPreorder['complete_qty']);
                                if (!$existingCustomerPreorder['preorder_complete']
                                && ($wkAllowedQty >= $product['cart_quantity'])) {
                                } else {
                                    return $returnProductOnFailure ? $product : false;
                                }
                            } else {
                                if ($product['cart_quantity'] > $availableQuantity) {
                                    return $returnProductOnFailure ? $product : false;
                                }
                            }
                        }
                    } else {
                        if ($product['cart_quantity'] > $availableQuantity) {
                            return $returnProductOnFailure ? $product : false;
                        }
                    }
                }
            }
        }
        return true;
    }
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public function getProducts($refresh = false, $id_product = false, $id_country = null, $fullInfos = true, bool $keepOrderPrices = false)
    {
        $products = parent::getProducts($refresh, $id_product, $id_country, $fullInfos, $keepOrderPrices);
        if (Module::isEnabled('preorder')) {
            include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
            $preorderObj = new PreOrderProduct();
            foreach ($products as &$product) {
                $existingPreorderProductData = $preorderObj->getExistingPreOrderProduct(
                    $product['id_product'],
                    $product['id_product_attribute']
                );
                if ($existingPreorderProductData) {
                    if (Validate::isLoadedObject(Context::getContext()->customer) && Context::getContext()->customer->id) {
                        $objPreorderCustomer = new PreorderProductCustomer();
                        $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                            $product['id_product'],
                            $product['id_product_attribute'],
                            Context::getContext()->customer->id,
                            Context::getContext()->shop->id
                        );
                        if ($checkCookie) {
                            $preorderCustomerObj = new PreorderProductCustomer();
                            $existingCustomerPreorder = $preorderCustomerObj->getCustomerPreOrderByIdPIdCIdO(
                                (int) Context::getContext()->customer->id,
                                $product['id_product'],
                                $product['id_product_attribute'],
                                $checkCookie['old_order_id']
                            );
                            if ($existingCustomerPreorder) {
                                if (!$existingCustomerPreorder['preorder_complete']) {
                                    $product['specific_prices'] = [];
                                    continue;
                                }
                            }
                        }
                        if ($existingPreorderProductData['payment_type'] == 3
                        && $existingPreorderProductData['is_preorder'] == '1') {
                            $product['specific_prices'] = [];
                        }
                    }
                }
            }
        }
        return $products;
    }
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public function getPackageShippingCost(
        $id_carrier = null,
        $use_tax = true,
        Country $default_country = null,
        $product_list = null,
        $id_zone = null,
        bool $keepOrderPrices = false
    ) {
        if ($id_carrier === 8) {//FedEx Standard Overnight
            $total_shipping_cost = $this->getFedExShippingCost();

            return $total_shipping_cost;
        } else {
            if ($this->isVirtualCart()) {
                return 0;
            }
            if (!$default_country) {
                $default_country = Context::getContext()->country;
            }
            if (null === $product_list) {
                $products = $this->getProducts(false, false, null, true, $keepOrderPrices);
            } else {
                foreach ($product_list as $key => $value) {
                    if ($value['is_virtual'] == 1) {
                        unset($product_list[$key]);
                    }
                }
                $products = $product_list;
            }
            if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_invoice') {
                $address_id = (int) $this->id_address_invoice;
            } elseif (is_array($product_list) && count($product_list)) {
                $prod = current($product_list);
                $address_id = (int) $prod['id_address_delivery'];
            } else {
                $address_id = null;
            }
            if (!Address::addressExists($address_id, true)) {
                $address_id = null;
            }
            if (null === $id_carrier && !empty($this->id_carrier)) {
                $id_carrier = (int) $this->id_carrier;
            }
            $cache_id = 'getPackageShippingCost_' . (int) $this->id . '_' . (int) $address_id . '_' . (int) $id_carrier . '_' . (int) $use_tax . '_' . (int) $default_country->id . '_' . (int) $id_zone;
            if ($products) {
                foreach ($products as $product) {
                    $cache_id .= '_' . (int) $product['id_product'] . '_' . (int) $product['id_product_attribute'];
                }
            }
            if (Cache::isStored($cache_id)) {
                return Cache::retrieve($cache_id);
            }
            $order_total = $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, $product_list, $id_carrier, false, $keepOrderPrices);
            if (Module::isEnabled('preorder') && $product_list) {
                include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
                $preorderObj = new PreOrderProduct();
                foreach ($product_list as $product) {
                    $id_product = $product['id_product'];
                    $id_product_attribute = $product['id_product_attribute'];
                    $existingPreorderProductData = $preorderObj->getExistingPreOrderProduct(
                        $id_product,
                        $id_product_attribute
                    );
                    if ($existingPreorderProductData) {
                        if (Validate::isLoadedObject(Context::getContext()->customer) && Context::getContext()->customer->id) {
                            $objPreorderCustomer = new PreorderProductCustomer();
                            $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                                $id_product,
                                $id_product_attribute,
                                Context::getContext()->customer->id,
                                Context::getContext()->shop->id
                            );
                            if ($checkCookie) {
                                $completePreorder = 1;
                            } else {
                                $completePreorder = 0;
                            }
                            if (isset($completePreorder) && $completePreorder == 1) {
                                $preorderCustomerObj = new PreorderProductCustomer();
                                $existingCustomerPreorder = $preorderCustomerObj->getCustomerPreOrderByIdPIdCIdO(
                                    (int) Context::getContext()->customer->id,
                                    $id_product,
                                    $id_product_attribute,
                                    $checkCookie['old_order_id']
                                );
                                if ($existingCustomerPreorder) {
                                    if (!$existingCustomerPreorder['preorder_complete']) {
                                        $order_total += $existingCustomerPreorder['paid_amt'] + $existingCustomerPreorder['tax_amt'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $shipping_cost = 0;
            if (!count($products)) {
                Cache::store($cache_id, $shipping_cost);
                return $shipping_cost;
            }
            if (!isset($id_zone)) {
                if (!$this->isMultiAddressDelivery()
                    && isset($this->id_address_delivery) // Be careful, id_address_delivery is not useful one 1.5
                    && $this->id_address_delivery
                    && Customer::customerHasAddress($this->id_customer, $this->id_address_delivery)
                ) {
                    $id_zone = Address::getZoneById((int) $this->id_address_delivery);
                } else {
                    if (!Validate::isLoadedObject($default_country)) {
                        $default_country = new Country(
                            (int) Configuration::get('PS_COUNTRY_DEFAULT'),
                            (int) Configuration::get('PS_LANG_DEFAULT')
                        );
                    }
                    $id_zone = (int) $default_country->id_zone;
                }
            }
            if ($id_carrier && !$this->isCarrierInRange((int) $id_carrier, (int) $id_zone)) {
                $id_carrier = '';
            }
            if (empty($id_carrier) && $this->isCarrierInRange((int) Configuration::get('PS_CARRIER_DEFAULT'), (int) $id_zone)) {
                $id_carrier = (int) Configuration::get('PS_CARRIER_DEFAULT');
            }
            if (empty($id_carrier)) {
                if ((int) $this->id_customer) {
                    $customer = new Customer((int) $this->id_customer);
                    $result = Carrier::getCarriers((int) Configuration::get('PS_LANG_DEFAULT'), true, false, (int) $id_zone, $customer->getGroups());
                    unset($customer);
                } else {
                    $result = Carrier::getCarriers((int) Configuration::get('PS_LANG_DEFAULT'), true, false, (int) $id_zone);
                }
                foreach ($result as $k => $row) {
                    if ($row['id_carrier'] == Configuration::get('PS_CARRIER_DEFAULT')) {
                        continue;
                    }
                    if (!isset(self::$_carriers[$row['id_carrier']])) {
                        self::$_carriers[$row['id_carrier']] = new Carrier((int) $row['id_carrier']);
                    }
                    
                    $carrier = self::$_carriers[$row['id_carrier']];
                    $shipping_method = $carrier->getShippingMethod();
                    if (($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT && $carrier->getMaxDeliveryPriceByWeight((int) $id_zone) === false)
                        || ($shipping_method == Carrier::SHIPPING_METHOD_PRICE && $carrier->getMaxDeliveryPriceByPrice((int) $id_zone) === false)) {
                        unset($result[$k]);
                        continue;
                    }
                    if ($row['range_behavior']) {
                        $check_delivery_price_by_weight = Carrier::checkDeliveryPriceByWeight($row['id_carrier'], $this->getTotalWeight(), (int) $id_zone);
                        $check_delivery_price_by_price = Carrier::checkDeliveryPriceByPrice($row['id_carrier'], $order_total, (int) $id_zone, (int) $this->id_currency);
                        if (($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT && $check_delivery_price_by_weight === false)
                            || ($shipping_method == Carrier::SHIPPING_METHOD_PRICE && $check_delivery_price_by_price === false)) {
                            unset($result[$k]);
                            continue;
                        }
                    }
                    if ($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                        $shipping = $carrier->getDeliveryPriceByWeight($this->getTotalWeight($product_list), (int) $id_zone);
                    } else {
                        $shipping = $carrier->getDeliveryPriceByPrice($order_total, (int) $id_zone, (int) $this->id_currency);
                    }
                    if (!isset($min_shipping_price)) {
                        $min_shipping_price = $shipping;
                    }
                    if ($shipping <= $min_shipping_price) {
                        $id_carrier = (int) $row['id_carrier'];
                        $min_shipping_price = $shipping;
                    }
                }
            }
            if (empty($id_carrier)) {
                $id_carrier = Configuration::get('PS_CARRIER_DEFAULT');
            }
            if (!isset(self::$_carriers[$id_carrier])) {
                self::$_carriers[$id_carrier] = new Carrier((int) $id_carrier, (int) Configuration::get('PS_LANG_DEFAULT'));
            }
            $carrier = self::$_carriers[$id_carrier];
            if (!Validate::isLoadedObject($carrier)) {
                Cache::store($cache_id, 0);
                return 0;
            }
            $shipping_method = $carrier->getShippingMethod();
            if (!$carrier->active) {
                Cache::store($cache_id, $shipping_cost);
                return $shipping_cost;
            }
            if ($carrier->is_free == 1) {
                Cache::store($cache_id, 0);
                return 0;
            }
            if ($use_tax && !Tax::excludeTaxeOption()) {
                $address = Address::initialize((int) $address_id);
                if (Configuration::get('PS_ATCP_SHIPWRAP')) {
                    $carrier_tax = 0;
                } else {
                    $carrier_tax = $carrier->getTaxesRate($address);
                }
            }
            $configuration = Configuration::getMultiple([
                'PS_SHIPPING_FREE_PRICE',
                'PS_SHIPPING_HANDLING',
                'PS_SHIPPING_METHOD',
                'PS_SHIPPING_FREE_WEIGHT',
            ]);
            $free_fees_price = 0;
            if (isset($configuration['PS_SHIPPING_FREE_PRICE'])) {
                $free_fees_price = Tools::convertPrice((float) $configuration['PS_SHIPPING_FREE_PRICE'], Currency::getCurrencyInstance((int) $this->id_currency));
            }
            $orderTotalwithDiscounts = $this->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING, null, null, false);
            if ($orderTotalwithDiscounts >= (float) $free_fees_price && (float) $free_fees_price > 0) {
                $shipping_cost = $this->getPackageShippingCostFromModule($carrier, $shipping_cost, $products);
                Cache::store($cache_id, $shipping_cost);
                return $shipping_cost;
            }
            if (isset($configuration['PS_SHIPPING_FREE_WEIGHT'])
                && $this->getTotalWeight() >= (float) $configuration['PS_SHIPPING_FREE_WEIGHT']
                && (float) $configuration['PS_SHIPPING_FREE_WEIGHT'] > 0) {
                $shipping_cost = $this->getPackageShippingCostFromModule($carrier, $shipping_cost, $products);
                Cache::store($cache_id, $shipping_cost);
                return $shipping_cost;
            }
            if ($carrier->range_behavior) {
                if (($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT && Carrier::checkDeliveryPriceByWeight($carrier->id, $this->getTotalWeight(), (int) $id_zone) === false)
                    || (
                        $shipping_method == Carrier::SHIPPING_METHOD_PRICE && Carrier::checkDeliveryPriceByPrice($carrier->id, $order_total, $id_zone, (int) $this->id_currency) === false
                    )) {
                    $shipping_cost += 0;
                } else {
                    if ($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                        $shipping_cost += $carrier->getDeliveryPriceByWeight($this->getTotalWeight($product_list), $id_zone);
                    } else { // by price
                        $shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int) $this->id_currency);
                    }
                }
            } else {
                if ($shipping_method == Carrier::SHIPPING_METHOD_WEIGHT) {
                    $shipping_cost += $carrier->getDeliveryPriceByWeight($this->getTotalWeight($product_list), $id_zone);
                } else {
                    $shipping_cost += $carrier->getDeliveryPriceByPrice($order_total, $id_zone, (int) $this->id_currency);
                }
            }
            if (isset($configuration['PS_SHIPPING_HANDLING']) && $carrier->shipping_handling) {
                $shipping_cost += (float) $configuration['PS_SHIPPING_HANDLING'];
            }
            foreach ($products as $product) {
                if (!$product['is_virtual']) {
                    $shipping_cost += $product['additional_shipping_cost'] * $product['cart_quantity'];
                }
            }
            $shipping_cost = Tools::convertPrice($shipping_cost, Currency::getCurrencyInstance((int) $this->id_currency));
            $shipping_cost = $this->getPackageShippingCostFromModule($carrier, $shipping_cost, $products);
            if ($shipping_cost === false) {
                Cache::store($cache_id, false);
                return false;
            }
            if (Configuration::get('PS_ATCP_SHIPWRAP')) {
                if (!$use_tax) {
                    $shipping_cost /= (1 + $this->getAverageProductsTaxRate());
                }
            } else {
                if ($use_tax && isset($carrier_tax)) {
                    $shipping_cost *= 1 + ($carrier_tax / 100);
                }
            }
            $shipping_cost = (float) Tools::ps_round((float) $shipping_cost, Context::getContext()->getComputingPrecision());
            Cache::store($cache_id, $shipping_cost);
            
            return $shipping_cost;
        }
    }
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public function isAllProductsInStock($ignoreVirtual = false, $exclusive = false)
    {
        if (func_num_args() > 1) {
            @trigger_error(
                '$exclusive parameter is deprecated since version 1.7.3.2 and will be removed in the next major version.',
                E_USER_DEPRECATED
            );
        }
        $productOutOfStock = 0;
        $productInStock = 0;
        foreach ($this->getProducts(false, false, null, false) as $product) {
            if ($ignoreVirtual && $product['is_virtual']) {
                continue;
            }
            $idProductAttribute = !empty($product['id_product_attribute']) ? $product['id_product_attribute'] : null;
            $availableOutOfStock = Product::isAvailableWhenOutOfStock($product['out_of_stock']);
            $productQuantity = Product::getQuantity(
                $product['id_product'],
                $idProductAttribute,
                null,
                $this,
                $product['id_customization']
            );
            if (!$exclusive
                && ($productQuantity < 0 && !$availableOutOfStock)
            ) {
                return false;
            } elseif ($exclusive) {
                if ($productQuantity <= 0) {
                    ++$productOutOfStock;
                } else {
                    ++$productInStock;
                }
                if ($productInStock > 0 && $productOutOfStock > 0) {
                    return false;
                }
            }
        }
        if (Module::isEnabled('preorder')) {
            if (PreorderHelper::validateConfigConditions()) {
                return false;
            }
        }
        return true;
    }

    private function getFedExShippingCost()
    {

        // Set up FedEx API credentials
        $api_key = 'PbHxzpmMPlKBYdJs';
        $api_password = 'yGtrfupfDmCipEmODFa44644c';
        $account_number = '707910399';
        $meter_number = '259195573';

        // Create the SOAP client
        $client = new SoapClient('path_to_wsdl_file', array('trace' => 1));

        // Set up the request data
        $request = array(
            'WebAuthenticationDetail' => array(
                'UserCredential' => array(
                    'Key' => $api_key,
                    'Password' => $api_password
                )
            ),
            'ClientDetail' => array(
                'AccountNumber' => $account_number,
                'MeterNumber' => $meter_number
            ),
            'TransactionDetail' => array(
                'CustomerTransactionId' => ' *** Rate Request using PHP ***'
            ),
            'Version' => array(
                'ServiceId' => 'crs',
                'Major' => '26',
                'Intermediate' => '0',
                'Minor' => '0'
            ),
            'RequestedShipment' => array(
                'DropoffType' => 'REGULAR_PICKUP',
                'ServiceType' => 'FEDEX_GROUND', // Example: Change as needed
                'PackagingType' => 'YOUR_PACKAGING',
                'Shipper' => array(
                    'Address' => array(
                        'StreetLines' => array('Shipper Address Line 1'),
                        'City' => 'Shipper City',
                        'StateOrProvinceCode' => 'CA',
                        'PostalCode' => '12345',
                        'CountryCode' => 'US'
                    )
                ),
                'Recipient' => array(
                    'Address' => array(
                        'StreetLines' => array('Recipient Address Line 1'),
                        'City' => 'Recipient City',
                        'StateOrProvinceCode' => 'NY',
                        'PostalCode' => '67890',
                        'CountryCode' => 'US',
                        'Residential' => false
                    )
                ),
                'ShippingChargesPayment' => array(
                    'PaymentType' => 'SENDER',
                    'Payor' => array(
                        'ResponsibleParty' => array(
                            'AccountNumber' => $account_number
                        )
                    )
                ),
                'RateRequestTypes' => 'ACCOUNT',
                'PackageCount' => '1',
                'RequestedPackageLineItems' => array(
                    '0' => array(
                        'GroupPackageCount' => 1,
                        'Weight' => array(
                            'Units' => 'LB',
                            'Value' => 10
                        ),
                        'Dimensions' => array(
                            'Length' => 10,
                            'Width' => 10,
                            'Height' => 10,
                            'Units' => 'IN'
                        )
                    )
                )
            )
        );

        // Send the request
        $response = $client->getRates($request);

        // Extract the shipping cost from the response
        // This example assumes the shipping cost is in 'RateReplyDetails'
        $shipping_cost = $response->RateReplyDetails->RatedShipmentDetails->ShipmentRateDetail->TotalNetCharge->Amount;

        return $shipping_cost;
    }
    
}
