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
class CartController extends CartControllerCore
{
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    protected function areProductsAvailable()
    {
        $product = $this->context->cart->checkQuantities(true);
        if (Module::isEnabled('preorder')) {
            $context = Context::getContext();
            include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
            $idCustomer = $context->cart->id_customer;
            $onlypreorders = 0;
            $onlynormal = 0;
            $preorderObj = new PreOrderProduct();
            if ($cartProducts = $context->cart->getProducts()) {
                foreach ($cartProducts as $productData) {
                    if (Configuration::get('WK_RESTRICT_CHECKOUT')) {
                        $existingPreorderProduct = $preorderObj->getExistingActivePreOrderProduct(
                            $productData['id_product'],
                            $productData['id_product_attribute']
                        );
                        if ($existingPreorderProduct && $existingPreorderProduct['is_preorder'] == 1) {
                            if (PreorderHelper::validateConfigConditions()) {
                                return $this->trans(
                                    'Preorder products are not available.',
                                    [],
                                    'Shop.Notifications.Error'
                                );
                            }
                            $onlypreorders = 1;
                            if ($onlynormal) {
                                return $this->trans(
                                    'You are not allowed to purchase preorder products with the normal products.',
                                    [],
                                    'Shop.Notifications.Error'
                                );
                            }
                        } else {
                            $onlynormal = 1;
                            if ($onlypreorders) {
                                return $this->trans(
                                    'You are not allowed to purchase preorder products with the normal products.',
                                    [],
                                    'Shop.Notifications.Error'
                                );
                            }
                        }
                    }
                    $idProduct = $productData['id_product'];
                    $idAttr = $productData['id_product_attribute'];
                    $preorderObj = new PreorderProduct();
                    $existingPreorderProduct = $preorderObj->getExistingActivePreOrderProduct(
                        $idProduct,
                        $idAttr
                    );
                    if ($existingPreorderProduct) {
                        if (Configuration::get('PS_GUEST_CHECKOUT_ENABLED')) {
                            if (!Configuration::get('WK_GUEST_PREORDER_ENABLED')) {
                                if ($existingPreorderProduct['is_preorder'] == 1
                                    && $existingPreorderProduct['payment_type'] != 1) {
                                    if (!$idCustomer) {
                                        $this->errors[] = $this->trans(
                                            'Please login to buy preorder product!',
                                            [],
                                            'Shop.Notifications.Error'
                                        );
                                    } else {
                                        $defaultGroupId = Customer::getDefaultGroupId($idCustomer);
                                        if ($defaultGroupId == Configuration::get('PS_GUEST_GROUP')) {
                                            $this->errors[] = $this->trans(
                                                'Please login as a customer to buy preorder product!',
                                                [],
                                                'Shop.Notifications.Error'
                                            );
                                        }
                                    }
                                }
                            }
                        }
                        $allowedGroups = json_decode(Configuration::get('WK_PREORDER_GROUP'));
                        $customerGroups = Customer::getGroupsStatic($context->customer->id);
                        if ($allowedGroups && $customerGroups
                        && is_array($allowedGroups) && is_array($customerGroups)
                        && empty(array_intersect($allowedGroups, $customerGroups))) {
                            return $this->trans(
                                'The item %product% in your cart is no longer available.',
                                ['%product%' => $productData['name']],
                                'Shop.Notifications.Error'
                            );
                        }
                        $remainingQty = $existingPreorderProduct['maxquantity'] -
                        $existingPreorderProduct['prebooked_quantity'];
                        if ($productData['cart_quantity'] > $remainingQty) {
                            return $this->trans(
                                'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                ['%product%' => $productData['name']],
                                'Shop.Notifications.Error'
                            );
                        }
                    }
                    if ($preorderObj->getExistingPreOrderByProductId($productData['id_product'])) {
                        $availableQuantity = StockAvailable::getQuantityAvailableByProduct(
                            $productData['id_product'],
                            $productData['id_product_attribute']
                        );
                        $existingPreorderProductData = $preorderObj->getExistingPreOrderProduct(
                            $productData['id_product'],
                            $productData['id_product_attribute']
                        );
                        if ($existingPreorderProductData) {
                            $oldOrderId = 0;
                            $objPreorderCustomer = new PreorderProductCustomer();
                            $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                                $productData['id_product'],
                                $productData['id_product_attribute'],
                                Context::getContext()->customer->id,
                                Context::getContext()->shop->id
                            );
                            if ($checkCookie) {
                                $oldOrderId = (int) $checkCookie['old_order_id'];
                                $completePreorder = 1;
                            }
                            if ($checkCookie) {
                                $checkCookie = (array) json_decode(Context::getContext()->cookie->preorder_complete);
                                if (isset(
                                    $checkCookie[$productData['id_product'] . '_' . $productData['id_product_attribute']]
                                )) {
                                    $completePreorder = 1;
                                }
                            }
                            if (isset($completePreorder)) {
                                $preorderCustomerObj = new PreorderProductCustomer();
                                $existingCustomerPreorder = $preorderCustomerObj->getCustomerPreOrderByIdPIdCIdO(
                                    (int) $idCustomer,
                                    $productData['id_product'],
                                    $productData['id_product_attribute'],
                                    $oldOrderId
                                );
                                unset($completePreorder);
                                if ($existingCustomerPreorder) {
                                    $wkAllowedQty = (int) ($existingCustomerPreorder['quantity'] -
                                    $existingCustomerPreorder['complete_qty']);
                                    if (!$existingCustomerPreorder['preorder_complete']
                                    && ($wkAllowedQty >= $productData['cart_quantity'])) {
                                    } else {
                                        return $this->trans(
                                            'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                            ['%product%' => $productData['name']],
                                            'Shop.Notifications.Error'
                                        );
                                    }
                                } else {
                                    if ($productData['cart_quantity'] > $availableQuantity) {
                                        return $this->trans(
                                            'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                            ['%product%' => $productData['name']],
                                            'Shop.Notifications.Error'
                                        );
                                    }
                                }
                            }
                        } else {
                            if ($productData['cart_quantity'] > $availableQuantity) {
                                return $this->trans(
                                    'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                    ['%product%' => $productData['name']],
                                    'Shop.Notifications.Error'
                                );
                            }
                        }
                    }
                }
            }
        }
        if (true === $product || !is_array($product)) {
            return true;
        }
        if ($product['active']) {
            return $this->trans(
                'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                ['%product%' => $product['name']],
                'Shop.Notifications.Error'
            );
        }
        return $this->trans(
            'This product (%product%) is no longer available.',
            ['%product%' => $product['name']],
            'Shop.Notifications.Error'
        );
    }
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    protected function shouldAvailabilityErrorBeRaised($product, $qtyToCheck)
    {
        if (Module::isEnabled('preorder')) {
            include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
            $preorderObj = new PreorderProduct();
            if (_PS_VERSION_ >= '8.0.0') {
                $quantityChecked = ProductAttribute::checkAttributeQty($this->id_product_attribute, $qtyToCheck);
            } else {
                $quantityChecked = Attribute::checkAttributeQty($this->id_product_attribute, $qtyToCheck);
            }
            $outOfStock = Product::isAvailableWhenOutOfStock($product->out_of_stock);
            $availableQuantity = StockAvailable::getQuantityAvailableByProduct(
                $this->id_product,
                $this->id_product_attribute
            );
            $existingPreorderProductData = $preorderObj->getExistingPreOrderProduct(
                $this->id_product,
                $this->id_product_attribute
            );
            if ($existingPreorderProductData) {
                $outOfStock = 0;
                $objPreorderCustomer = new PreorderProductCustomer();
                $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                    $this->id_product,
                    $this->id_product_attribute,
                    Context::getContext()->customer->id,
                    Context::getContext()->shop->id
                );
                if ($checkCookie) {
                    $completePreorder = 1;
                }
                if (isset($completePreorder)) {
                    $preorderCustomerObj = new PreorderProductCustomer();
                    $existingCustomerPreorder = $preorderCustomerObj->getCustomerPreOrderByIdPIdCIdO(
                        (int) $this->context->customer->id,
                        $this->id_product,
                        $this->id_product_attribute,
                        $checkCookie['old_order_id']
                    );
                    unset($completePreorder);
                    if ($existingCustomerPreorder) {
                        $wkAllowedQty = (int) ($existingCustomerPreorder['quantity'] -
                        $existingCustomerPreorder['complete_qty']);
                        $quantityChecked = !$existingCustomerPreorder['preorder_complete']
                        && ($wkAllowedQty >= $qtyToCheck);
                    } else {
                        $quantityChecked = $availableQuantity && $qtyToCheck <= $availableQuantity;
                    }
                } else {
                    if ($existingPreorderProductData['is_preorder'] == 1) {
                        $outOfStock = 1;
                        $remainingQty = $existingPreorderProductData['maxquantity'] -
                        $existingPreorderProductData['prebooked_quantity'];
                        $quantityChecked = $remainingQty && $qtyToCheck <= $remainingQty;
                    } else {
                        $quantityChecked = $availableQuantity && $qtyToCheck <= $availableQuantity;
                    }
                }
            } else {
                $quantityChecked = $availableQuantity && $qtyToCheck <= $availableQuantity;
            }
            if ($this->id_product_attribute) {
                return !$outOfStock
                && !$quantityChecked;
            } elseif ($outOfStock) {
                return false;
            }
        } else {
            if (_PS_VERSION_ >= '8.0.0') {
                $checkQty = ProductAttribute::checkAttributeQty($this->id_product_attribute, $qtyToCheck);
            } else {
                $checkQty = Attribute::checkAttributeQty($this->id_product_attribute, $qtyToCheck);
            }
            if ($this->id_product_attribute) {
                return !Product::isAvailableWhenOutOfStock($product->out_of_stock)
                    && !$checkQty;
            } elseif (Product::isAvailableWhenOutOfStock($product->out_of_stock)) {
                return false;
            }
        }
        $productQuantity = Product::getQuantity(
            $this->id_product,
            $this->id_product_attribute,
            null,
            $this->context->cart,
            $this->customization_id
        );
        return $productQuantity < 0;
    }
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    protected function processChangeProductInCart()
    {
        if (Tools::getValue('controller') == 'product') {
            if (Module::isEnabled('preorder')) {
                include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
                $idCustomer = $this->context->customer->id;
                $idProduct = $this->id_product;
                $product = new Product($idProduct);
                $idAttr = $this->id_product_attribute;
                $has_attr = $product->hasAttributes();
                if ($has_attr > 0 && $idAttr == 0) {
                    $idAttr = Product::getDefaultAttribute($idProduct);
                }
                $preorderObj = new PreOrderProduct();
                $existingPreorderProduct = $preorderObj->getExistingActivePreOrderProduct($idProduct, $idAttr);
                $cartProducts = $this->context->cart->getProducts();
                $remainingQty = $existingPreorderProduct['maxquantity'] - $existingPreorderProduct['prebooked_quantity'];
                if (!Configuration::get('WK_GUEST_PREORDER_ENABLED')) {
                    if (empty($idCustomer)
                && $existingPreorderProduct['is_preorder'] == 1
                && $existingPreorderProduct['payment_type'] != 1) {
                        $this->errors[] = $this->trans(
                            'Please login to buy preorder product!',
                            [],
                            'Shop.Notifications.Error'
                        );
                        return;
                    }
                }
                $allowedGroups = json_decode(Configuration::get('WK_PREORDER_GROUP'));
                $customerGroups = Customer::getGroupsStatic($context->customer->id);
                if ($allowedGroups && $customerGroups
                && is_array($allowedGroups) && is_array($customerGroups)
                && empty(array_intersect($allowedGroups, $customerGroups))) {
                    return $this->trans(
                        'Unfortunately, you are not allowed to purchase this product.',
                        [],
                        'Shop.Notifications.Error'
                    );
                }
                if ($preorderObj->getExistingPreOrderByProductId($idProduct)) {
                    $availableQuantity = StockAvailable::getQuantityAvailableByProduct(
                        $idProduct,
                        $idAttr
                    );
                    $existingPreorderProductData = $preorderObj->getExistingPreOrderProduct(
                        $idProduct,
                        $idAttr
                    );
                    if ($existingPreorderProductData) {
                        $objPreorderCustomer = new PreorderProductCustomer();
                        $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                            $idProduct,
                            $idAttr,
                            Context::getContext()->customer->id,
                            Context::getContext()->shop->id
                        );
                        if ($checkCookie) {
                            $preorderCustomerObj = new PreorderProductCustomer();
                            $existingCustomerPreorder = $preorderCustomerObj->getCustomerPreOrderByIdPIdCIdO(
                                (int) $idCustomer,
                                $idProduct,
                                $idAttr,
                                $checkCookie['old_order_id']
                            );
                            if ($existingCustomerPreorder) {
                                if (!$existingCustomerPreorder['preorder_complete']) {
                                    $objPreorderCustomer->deteleTempCompletionEntry(
                                        $idProduct,
                                        $idAttr,
                                        Context::getContext()->customer->id,
                                        Context::getContext()->shop->id
                                    );
                                }
                            }
                        }
                        $objPreorderCustomer = new PreorderProductCustomer();
                        $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                            $idProduct,
                            $idAttr,
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
                                $idProduct,
                                $idAttr,
                                $checkCookie['old_order_id']
                            );
                            unset($completePreorder);
                            if ($existingCustomerPreorder) {
                                $wkAllowedQty = (int) ($existingCustomerPreorder['quantity'] -
                            $existingCustomerPreorder['complete_qty']);
                                if (!$existingCustomerPreorder['preorder_complete'] && ($wkAllowedQty >= $this->qty)) {
                                } else {
                                    $this->errors[] = $this->trans(
                                        'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                        ['%product%' => Product::getProductName($idProduct, $idAttr, $this->context->language->id)],
                                        'Shop.Notifications.Error'
                                    );
                                    return;
                                }
                            } else {
                                if ($this->qty > $availableQuantity) {
                                    $this->errors[] = $this->trans(
                                        'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                        ['%product%' => Product::getProductName($idProduct, $idAttr, $this->context->language->id)],
                                        'Shop.Notifications.Error'
                                    );
                                    return;
                                }
                            }
                        }
                    } else {
                        if ($this->qty > $availableQuantity) {
                            $this->errors[] = $this->trans(
                                'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                ['%product%' => Product::getProductName($idProduct, $idAttr, $this->context->language->id)],
                                'Shop.Notifications.Error'
                            );
                            return;
                        }
                    }
                }
                if (!empty($cartProducts)) {
                    $onlypreorders = 0;
                    $onlynormal = 0;
                    foreach ($cartProducts as $productList) {
                        if (Configuration::get('WK_RESTRICT_CHECKOUT')) {
                            $existingPreorderProduct = $preorderObj->getExistingActivePreOrderProduct(
                                $productList['id_product'],
                                $productList['id_product_attribute']
                            );
                            if ($existingPreorderProduct && $existingPreorderProduct['is_preorder'] == 1) {
                                $onlypreorders = 1;
                                if ($onlynormal) {
                                    $this->errors[] = $this->trans(
                                        'You are not allowed to purchase preorder products with the normal products.',
                                        [],
                                        'Shop.Notifications.Error'
                                    );
                                    return;
                                }
                            } else {
                                $onlynormal = 1;
                                if ($onlypreorders) {
                                    $this->errors[] = $this->trans(
                                        'You are not allowed to purchase preorder products with the normal products.',
                                        [],
                                        'Shop.Notifications.Error'
                                    );
                                    return;
                                }
                            }
                        }
                        if ($productList['id_product'] == $idProduct
                && $productList['id_product_attribute'] == $idAttr
                && $existingPreorderProduct
                && $existingPreorderProduct['is_preorder'] == 1) {
                            if (Tools::getValue('op') == 'down') {
                                $currentQuantity = $productList['quantity'] - $this->qty;
                            } else {
                                $currentQuantity = $productList['quantity'] + $this->qty;
                            }
                            if ($this->qty > $remainingQty || $currentQuantity > $remainingQty) {
                                $this->errors[] = $this->trans(
                                    'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                    ['%product%' => Product::getProductName($productList['id_product'], $productList['id_product_attribute'], $this->context->language->id)],
                                    'Shop.Notifications.Error'
                                );
                                return;
                            }
                        }
                    }
                } else {
                    if ($existingPreorderProduct && $existingPreorderProduct['is_preorder'] == '1') {
                        if ($this->qty > $remainingQty) {
                            $this->errors[] = $this->trans(
                                'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                ['%product%' => Product::getProductName($productList['id_product'], $productList['id_product_attribute'], $this->context->language->id)],
                                'Shop.Notifications.Error'
                            );
                            return;
                        }
                    }
                }
            }
        }
        parent::processChangeProductInCart();
    }
}
