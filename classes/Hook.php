<?php
/**
 * 2008-2024 Prestaworld
 *
 * NOTICE OF LICENSE
 *
 * The source code of this module is under a commercial license.
 * Each license is unique and can be installed and used on only one website.
 * Any reproduction or representation total or partial of the module, one or more of its components,
 * by any means whatsoever, without express permission from us is prohibited.
 *
 * DISCLAIMER
 *
 * Do not alter or add/update to this file if you wish to upgrade this module to newer
 * versions in the future.
 *
 * @author    prestaworld
 * @copyright 2008-2024 Prestaworld
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 * International Registered Trademark & Property of prestaworld
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
class Hook extends HookCore
{
    /*
    * module: prestastorecredit
    * date: 2024-06-26 19:54:54
    * version: 7.0.0
    */
    public static function getHookModuleExecList($hook_name = null)
    {
        $list = parent::getHookModuleExecList($hook_name);
        $list = self::preventPaymentMethod($hook_name, $list);
        return $list;
    }
    /*
    * module: prestastorecredit
    * date: 2024-06-26 19:54:54
    * version: 7.0.0
    */
    public static function preventPaymentMethod($hook_name, $list)
    {
        $hook_name = Tools::strtolower($hook_name);
        $allowedHook = array(
            'paymentoptions',
            'displaypayment',
            'displaypaymenteu',
            'advancedpaymentoptions'
        );
        if (Module::isEnabled('prestastorecredit') && in_array($hook_name, $allowedHook)) {
            include_once _PS_MODULE_DIR_.'prestastorecredit/classes/PrestaStoreCreditClasses.php';
            $context = Context::getContext();
            if (!empty($context) && isset($context->cart->id)) {
                if ($id_cart = $context->cart->id) {
                    $showPaymentMethod = Tools::getValue('show_data');
                    if ($showPaymentMethod == 'data') {
                        foreach ($list as $key => $payment) {
                            if ($payment['module'] == 'prestastorecredit') {
                                unset($list[$key]);
                            }
                        }
                    }
                }
            }
        }
        return $list;
    }
}
