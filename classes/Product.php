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
class Product extends ProductCore
{
    /*
    * module: preorder
    * date: 2024-08-14 21:44:17
    * version: 5.3.1
    */
    public static function priceCalculation(
        $id_shop,
        $id_product,
        $id_product_attribute,
        $id_country,
        $id_state,
        $zipcode,
        $id_currency,
        $id_group,
        $quantity,
        $use_tax,
        $decimals,
        $only_reduc,
        $use_reduc,
        $with_ecotax,
        &$specific_price,
        $use_group_reduction,
        $id_customer = 0,
        $use_customer_price = true,
        $id_cart = 0,
        $real_quantity = 0,
        $id_customization = 0,
        $preorderoriginalPrice = 1
    ) {
        $price = parent::priceCalculation(
            $id_shop,
            $id_product,
            $id_product_attribute,
            $id_country,
            $id_state,
            $zipcode,
            $id_currency,
            $id_group,
            $quantity,
            $use_tax,
            $decimals,
            $only_reduc,
            $use_reduc,
            $with_ecotax,
            $specific_price,
            $use_group_reduction,
            $id_customer,
            $use_customer_price,
            $id_cart,
            $real_quantity,
            $id_customization
        );
        if (Module::isEnabled('preorder')) {
            include_once _PS_MODULE_DIR_ . 'preorder/classes/PreorderClasses.php';
            $preorderObj = new PreOrderProduct();
            $existingPreorderProductData = $preorderObj->getExistingPreOrderProduct(
                $id_product,
                $id_product_attribute
            );
            if ($existingPreorderProductData && $existingPreorderProductData['is_preorder'] == '1'
            && $preorderoriginalPrice) {
                if ($existingPreorderProductData['payment_type'] == 3) {
                    if (Validate::isLoadedObject(Context::getContext()->customer)
                    && Context::getContext()->customer->id
                    && PreorderCustomPrice::getCustomPrice(
                        $id_product,
                        $id_product_attribute,
                        Context::getContext()->customer->id,
                        $id_shop
                    )) {
                        $context = Context::getContext();
                        $customPrice = Tools::ps_round(
                            (float) PreorderCustomPrice::getCustomPrice(
                                $id_product,
                                $id_product_attribute,
                                Context::getContext()->customer->id,
                                $id_shop
                            ),
                            $decimals
                        );
                        if ($use_tax && $customPrice) {
                            if (is_object($context->cart) && $context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')} != null) {
                                $id_address = $context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')};
                                $objAddress = new Address($id_address);
                            } else {
                                $objAddress = new Address();
                            }
                            $objProduct = new Product((int) $id_product, false, $context->language->id);
                            $taxRate = $objProduct->getTaxesRate($objAddress);
                            $customPrice += ((float) $customPrice * $taxRate) / 100;
                        }
                        return $customPrice;
                    } else {
                        if ($existingPreorderProductData['payment_method'] == 1) {
                            $price = Tools::ps_round(
                                ($price * $existingPreorderProductData['preorder_price']) / 100,
                                2
                            );
                        } elseif ($existingPreorderProductData['payment_method'] == 2) {
                            $price = Tools::convertPriceFull(
                                $existingPreorderProductData['preorder_price'],
                                new Currency((int) $existingPreorderProductData['id_default_currency']),
                                new Currency((int) Context::getContext()->currency->id)
                            );
                            if ($use_tax && $price) {
                                $context = Context::getContext();
                                if (is_object($context->cart) && $context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')} != null) {
                                    $id_address = $context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')};
                                    $objAddress = new Address($id_address);
                                } else {
                                    $objAddress = new Address();
                                }
                                $objProduct = new Product((int) $id_product, false, $context->language->id);
                                $taxRate = $objProduct->getTaxesRate($objAddress);
                                $price += ((float) $price * $taxRate) / 100;
                            }
                        }
                    }
                    return $price;
                }
            }
            if (Validate::isLoadedObject(Context::getContext()->customer) && Context::getContext()->customer->id
            && (Tools::getValue('controller') != 'product')) {
                if ($existingPreorderProductData) {
                    $objPreorderCustomer = new PreorderProductCustomer();
                    $checkCookie = $objPreorderCustomer->checkEntryExistsWithoutOrder(
                        $id_product,
                        $id_product_attribute,
                        Context::getContext()->customer->id,
                        Context::getContext()->shop->id
                    );
                    if ($checkCookie) {
                        $completePreorder = 1;
                    }
                    if (isset($completePreorder)) {
                        $preorderCustomerObj = new PreorderProductCustomer();
                        $existingCustomerPreorder = $preorderCustomerObj->getCustomerPreOrderByIdPIdCIdO(
                            (int) Context::getContext()->customer->id,
                            $id_product,
                            $id_product_attribute,
                            $checkCookie['old_order_id']
                        );
                        if ($existingCustomerPreorder) {
                            if (!$existingCustomerPreorder['preorder_complete']) {
                                $preorderQuantity = $existingCustomerPreorder['quantity'] -
                                $existingCustomerPreorder['complete_qty'];
                                static $address = null;
                                static $context = null;
                                if ($context == null) {
                                    $context = Context::getContext()->cloneContext();
                                }
                                if ($address === null) {
                                    if (is_object($context->cart) && $context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')} != null) {
                                        $id_address = $context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')};
                                        $address = new Address($id_address);
                                    } else {
                                        $address = new Address();
                                    }
                                }
                                $address->id_country = $id_country;
                                $address->id_state = $id_state;
                                $address->postcode = $zipcode;
                                $tax_manager = TaxManagerFactory::getManager($address, Product::getIdTaxRulesGroupByIdProduct((int) $id_product, $context));
                                $preorderPrice = Tools::convertPriceFull(
                                    $existingCustomerPreorder['remaining_amt'],
                                    new Currency((int) $existingPreorderProductData['id_default_currency']),
                                    new Currency((int) Context::getContext()->currency->id)
                                );
                                $preorderPrice = $preorderPrice / $preorderQuantity;
                                if (!$use_tax) {
                                    $product_tax_calculator = $tax_manager->getTaxCalculator();
                                    $preorderPrice = $product_tax_calculator->removeTaxes($preorderPrice);
                                }
                                return Tools::ps_round(
                                    (float) $preorderPrice,
                                    $decimals
                                );
                            }
                        }
                    }
                }
            }
        }
        return $price;
    }
}
