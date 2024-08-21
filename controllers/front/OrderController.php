<?php
/**
 * 2007-2020 PrestaShop
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
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
use PrestaShop\PrestaShop\Core\Foundation\Templating\RenderableProxy;
class OrderController extends OrderControllerCore
{
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public function initContent()
    {
        if (Configuration::isCatalogMode()) {
            Tools::redirect('index.php');
        }
        if ($this->ajax) {
            return parent::initContent();
        }
        $this->restorePersistedData($this->checkoutProcess);
        $this->checkoutProcess->handleRequest(
            Tools::getAllValues()
        );
        $presentedCart = $this->cart_presenter->present($this->context->cart);
        if (count($presentedCart['products']) <= 0 || $presentedCart['minimalPurchaseRequired']) {
            $cartLink = $this->context->link->getPageLink('cart');
            Tools::redirect($cartLink);
        }
        $product = $this->context->cart->checkQuantities(true);
        if (Module::isEnabled('preorder')) {
            $context = Context::getContext();
            include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
            $idCustomer = $context->cart->id_customer;
            $onlypreorders = 0;
            $onlynormal = 0;
            $wkErrors = false;
            if ($cartProducts = $context->cart->getProducts()) {
                foreach ($cartProducts as $productData) {
                    $idProduct = $productData['id_product'];
                    $idAttr = $productData['id_product_attribute'];
                    $preorderObj = new PreorderProduct();
                    $existingPreorderProduct = $preorderObj->getExistingActivePreOrderProduct(
                        $idProduct,
                        $idAttr
                    );
                    if (Configuration::get('WK_RESTRICT_CHECKOUT')) {
                        if ($existingPreorderProduct && $existingPreorderProduct['is_preorder'] == 1) {
                            $onlypreorders = 1;
                            if ($onlynormal) {
                                $cartLink = $this->context->link->getPageLink('cart', null, null, ['action' => 'show']);
                                Tools::redirect($cartLink);
                            }
                        } else {
                            $onlynormal = 1;
                            if ($onlypreorders) {
                                $cartLink = $this->context->link->getPageLink('cart', null, null, ['action' => 'show']);
                                Tools::redirect($cartLink);
                            }
                        }
                    }
                    if ($existingPreorderProduct) {
                        if (Configuration::get('PS_GUEST_CHECKOUT_ENABLED')) {
                            if (!Configuration::get('WK_GUEST_PREORDER_ENABLED')) {
                                if ($existingPreorderProduct['is_preorder'] == 1
                                    && $existingPreorderProduct['payment_type'] != 1) {
                                    if (!$idCustomer) {
                                        $wkErrors = true;
                                        $this->errors[] = $this->trans(
                                            'Please login to buy preorder product!',
                                            [],
                                            'Shop.Notifications.Error'
                                        );
                                    } else {
                                        $defaultGroupId = Customer::getDefaultGroupId($idCustomer);
                                        if ($defaultGroupId == Configuration::get('PS_GUEST_GROUP')) {
                                            $wkErrors = true;
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
                        if (PreorderHelper::validateConfigConditions()) {
                            $wkErrors = true;
                            $this->errors[] = $this->trans(
                                'The Preorder products %product% in your cart is not available at your current location. You cannot proceed with your order until the product is available.',
                                ['%product%' => $productData['name']],
                                'Shop.Notifications.Error'
                            );
                        }
                        $remainingQty = $existingPreorderProduct['maxquantity'] -
                        $existingPreorderProduct['prebooked_quantity'];
                        if ($productData['cart_quantity'] > $remainingQty) {
                            $wkErrors = true;
                            $this->errors[] = $this->trans(
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
                            $objPreorderCustomer = new PreorderProductCustomer();
                            $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                                $productData['id_product'],
                                $productData['id_product_attribute'],
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
                                    $productData['id_product'],
                                    $productData['id_product_attribute'],
                                    $checkCookie['old_order_id']
                                );
                                unset($completePreorder);
                                if ($existingCustomerPreorder) {
                                    $wkAllowedQty = (int) ($existingCustomerPreorder['quantity'] -
                                    $existingCustomerPreorder['complete_qty']);
                                    $wkAllowedQty = $wkAllowedQty + $availableQuantity; // Add normal qty
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
                            if ($checkCookie) {
                                $oldOrderId = (int) $checkCookie['old_order_id'];
                                $objOldOrder = new Order($oldOrderId);
                                if (Validate::isLoadedObject($objOldOrder)) {
                                    $idAddress = $objOldOrder->{Configuration::get('PS_TAX_ADDRESS_TYPE')};
                                    $idCartAddress = $this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')};
                                    if ($idAddress
                                        && ($objAddress = new Address((int) $idAddress))
                                        && Validate::isLoadedObject($objAddress)
                                    ) {
                                        $idLang = (int) $this->context->language->id;
                                        $addressOld = Address::getCountryAndState($idAddress);
                                        $addressNew = Address::getCountryAndState($idCartAddress);
                                        if ($addressOld && $addressNew) {
                                            $hasError = false;
                                            if ($addressOld['id_country'] != $addressNew['id_country']) {
                                                $hasError = true;
                                                $this->errors[] = $this->trans(
                                                    'Please use the same country (%country%) in address used for the making preorder order (#%order%).',
                                                    ['%country%' => Country::getNameById($idLang, $addressOld['id_country']), '%order%' => $oldOrderId],
                                                    'Shop.Notifications.Error'
                                                );
                                            } elseif ($addressOld['id_state'] != $addressNew['id_state']) {
                                                $hasError = true;
                                                $this->errors[] = $this->trans(
                                                    'Please use the same state (%state%) in address used for the making preorder order (#%order%).',
                                                    ['%state%' => State::getNameById($addressOld['id_state']), '%order%' => $oldOrderId],
                                                    'Shop.Notifications.Error'
                                                );
                                            } elseif ($addressOld['postcode'] != $addressNew['postcode']) {
                                                $hasError = true;
                                                $this->errors[] = $this->trans(
                                                    'Please use the same postal code (%postcode%) in address used for the making preorder order (#%order%).',
                                                    ['%postcode%' => $addressOld['postcode'], '%order%' => $oldOrderId],
                                                    'Shop.Notifications.Error'
                                                );
                                            }
                                            if ($hasError) {
                                                foreach ($this->checkoutProcess->getSteps() as $step) {
                                                    if ($step instanceof CheckoutAddressesStep) {
                                                        $step->setCurrent(true);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            if ($productData['cart_quantity'] > $availableQuantity) {
                                $hasError = true;
                                $this->errors[] = $this->trans(
                                    'The item %product% in your cart is no longer available in this quantity. You cannot proceed with your order until the quantity is adjusted.',
                                    ['%product%' => $productData['name']],
                                    'Shop.Notifications.Error'
                                );
                            }
                        }
                    }
                }
            }
            if ($wkErrors) {
                foreach ($this->checkoutProcess->getSteps() as $step) {
                    if ($step instanceof CheckoutAddressesStep) {
                        $step->setCurrent(true);
                    }
                    $step->setComplete(false)->setReachable(false);
                }
            }
        }
        if (is_array($product)) {
            $cartLink = $this->context->link->getPageLink('cart', null, null, ['action' => 'show']);
            Tools::redirect($cartLink);
        }
        $this->checkoutProcess
            ->setNextStepReachable()
            ->markCurrentStep()
            ->invalidateAllStepsAfterCurrent();
        $this->saveDataToPersist($this->checkoutProcess);
        if (!$this->checkoutProcess->hasErrors()) {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !$this->ajax) {
                return $this->redirectWithNotifications(
                    $this->checkoutProcess->getCheckoutSession()->getCheckoutURL()
                );
            }
        }
        $this->context->smarty->assign([
            'checkout_process' => new RenderableProxy($this->checkoutProcess),
            'cart' => $presentedCart,
        ]);
        $this->context->smarty->assign([
            'display_transaction_updated_info' => Tools::getIsset('updatedTransaction'),
        ]);
        parent::initContent();
        $this->setTemplate('checkout/checkout');
    }
}
