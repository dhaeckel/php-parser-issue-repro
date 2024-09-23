<?
use DynCom\dc\common\classes\Hook;
use DynCom\dc\common\classes\Siteparts;
use DynCom\dc\common\interfaces\IOCInterface;
use DynCom\dc\dcShop\abstracts\DiscountBase;
use DynCom\dc\dcShop\classes\AppliedDiscount;
use DynCom\dc\dcShop\classes\BasketEntity;
use DynCom\dc\dcShop\classes\CategoryRepository;
use DynCom\dc\dcShop\classes\Customer;
use DynCom\dc\dcShop\classes\GenericInvoiceDiscount;
use DynCom\dc\dcShop\classes\GenericLineDiscount;
use DynCom\dc\dcShop\classes\GenericUserBasket;
use DynCom\dc\dcShop\classes\ItemPriceData;
use DynCom\dc\dcShop\ShippingOptions\ShippingClassRepository;
use DynCom\dc\dcShop\classes\UserBasketLoginHandler;
use DynCom\dc\dcShop\classes\WebshopItem;
use DynCom\dc\dcShop\classes\WebshopItemBuilder;
use DynCom\dc\dcShop\interfaces\ItemPriceDataInterface;
use DynCom\dc\dcShop\interfaces\UserBasket;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'shop_functions_extended.inc.php';

function get_shop_setup($company)
{
    $query = "SELECT * FROM shop_setup WHERE company = '" . $company . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        return mysqli_fetch_array($result);
    } else {
        //echo "Fehler in Funktion 'get_shop_setup' \n\n query: " . $query . "\n\n";
    }
}

function get_shop($company, $shop_code)
{
    if (!isset($GLOBALS['mysql_con'])) {
        db_connect();
    }
    $query = "SELECT * FROM shop_shop WHERE company = '" . $company . "' AND code = '" . $shop_code . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        return (mysqli_fetch_assoc($result));
    } else {
        //echo "Fehler in Funktion 'get_shop' \n\n query: " . $query . "\n\n error: " . mysqli_error($GLOBALS['mysql_con']);
    }
}

function get_shop_language($company, $shop_code, $language_code)
{
    $query = "SELECT * FROM shop_language WHERE company = '" . $company . "' AND shop_code = '" . $shop_code . "' AND code = '" . $language_code . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        return (mysqli_fetch_assoc($result));
    } else {
        //echo "Fehler in Funktion 'get_shop_language' \n\nquery: " . $query . "\n\n";
    }
}

function get_shop_currency($company, $shop_code, $language_code, $currency_code)
{
    if ($currency_code != '') {
        $query = "SELECT * FROM shop_currency WHERE company = '" . $company . "' AND shop_code = '" . $shop_code . "' AND language_code = '" . $language_code . "' AND code='" . $currency_code . "'";
    } else {
        if ($GLOBALS['shop_language']['default_currency_code'] != '') {
            $query = "SELECT * FROM shop_currency WHERE company = '" . $company . "' AND shop_code = '" . $shop_code . "' AND language_code = '" . $language_code . "' AND code='" . $GLOBALS['shop_setup']['default_currency_code'] . "'";
        } else {
            return '';
        }
    }
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        return (mysqli_fetch_assoc($result));
    } else {
        //echo "Fehler in Funktion 'get_shop_currency' \n\nquery: " . $query . "\n\n";
    }
}

function format_amount($amount, $gramm = false, $itemprop = false, $htmlEntity = false)
{
    //Währung abfragen, wenn keine gefunden -> Euro als Standard
    if (\DynCom\Compat\Compat::array_key_exists('shop_currency', $GLOBALS) &&
        is_array($GLOBALS['shop_currency']) &&
        \DynCom\Compat\Compat::array_key_exists('code', $GLOBALS['shop_currency']) &&
        $GLOBALS['shop_currency']['code'] != ''
    ) {
        $sign = $GLOBALS['shop_currency']['code'];
        $snippet = $GLOBALS['shop_currency']['code'];
    } else {
        $sign = $GLOBALS['shop_language']['default_currency_code'];
        $snippet = $GLOBALS['shop_language']['default_currency_code'];
    }

    if ($sign == 'EUR' || $sign == '') {
        $sign = "€";
        $snippet = 'EUR';

        if ($htmlEntity){
            $sign = '&euro;';
        }
    }

    switch (true) {
        case $itemprop:
            return "<span itemprop='price'>"
                . number_format($amount, 2, ',', '.')
                . " <span class='itemprop_price'>" . $snippet . "</span>"
                . "</span> " . $sign;
        case $gramm:
        default:
            return number_format($amount, 2, ',', '.') . "&nbsp;" . $sign;
    }
}

// +++Warenkorb/Favoriten-Funktionen+++
function shop_basket_listener()
{
    switch ($_GET["action"]) {
        case "shop_add_item_to_basket":
            if ($_GET["action_id"] <> '') {
                shop_add_item_to_basket($GLOBALS["visitor"]["id"], $_GET["action_id"], 1, $_GET['var_code']);
            }
            break;
        case "shop_add_item_to_basket_card":
            if (($_REQUEST["item_id"] <> '') && ((int)$_POST["item_qty"] > 0)) {
                shop_add_item_to_basket(
                    $GLOBALS["visitor"]["id"],
                    $_REQUEST["item_id"],
                    (float)$_POST["item_qty"],
                    $_REQUEST['item_var_code']
                );
            }
            break;
        case "direct_order":
            if (($_POST["input_item_no"] <> '') && (intval($_POST["input_item_quantity"]) > 0)) {
                //kundenspezifische Referenznummer prüfen
                $query = "SELECT *
						FROM shop_item_cross_reference
						WHERE item_reference_no LIKE '" . $_POST['input_item_no'] . "'
							AND customer_no = '" . $GLOBALS["shop_customer"]["customer_no"] . "'
							AND company = '" . $GLOBALS['shop']['company'] . "'";
                $result = mysqli_query($GLOBALS['mysql_con'], $query);
                if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
                    $row = mysqli_fetch_array($result);
                    $item = get_item(
                        $GLOBALS['shop']['company'],
                        $GLOBALS['shop']['code'],
                        $GLOBALS['shop_language']['code'],
                        $row['item_no']
                    );
                }
                //eigene Artikelnummer prüfen
                if ($item["id"] == '') {
                    $item = get_item(
                        $GLOBALS['shop']['company'],
                        $GLOBALS['shop']['code'],
                        $GLOBALS['shop_language']['code'],
                        $_POST["input_item_no"]
                    );
                }
                //allgemeine Referenznummern prüfen (z.B. EAN)
                if ($item['id'] == '') {
                    $query = "SELECT *
				 			FROM shop_item_cross_reference
				 			WHERE item_reference_no LIKE '" . $_POST['input_item_no'] . "'
				 				AND customer_no = ''
				 				AND company = '" . $GLOBALS['shop']['company'] . "'";
                    $result = mysqli_query($GLOBALS['mysql_con'], $query);
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
                        $row = mysqli_fetch_array($result);
                        $item = get_item(
                            $GLOBALS['shop']['company'],
                            $GLOBALS['shop']['code'],
                            $GLOBALS['shop_language']['code'],
                            $row['item_no']
                        );
                    }
                }
                //wenn gefunden, Artikel zum Warenkorb hinzufügen, ansonsten Fehlermeldung
                if ($item["id"] <> '') {
                    //Berechtigungen prüfen
                    $query = "SELECT shop_view_active_item.* FROM shop_view_active_item 
							LEFT JOIN shop_permissions_group_link ON (shop_permissions_group_link.item_no = shop_view_active_item.item_no
																	  AND shop_permissions_group_link.company = shop_view_active_item.company)
							WHERE
							shop_view_active_item.id=" . $item["id"] . "
							 " . get_permissions_group_customer() . "
							";
                    $result = mysqli_query($GLOBALS['mysql_con'], $query);
                    $item = mysqli_fetch_assoc($result);
                    if ($item["id"] > 0) {
                        $GLOBALS['error_direct_order'] = 0;
                        shop_add_item_to_basket(
                            $GLOBALS["visitor"]["id"],
                            $item["id"],
                            intval($_POST["input_item_quantity"]),
                            $_POST["input_var_code"]
                        );
                    } else {
                        $GLOBALS['error_direct_order'] = 1;
                    }
                } else {
                    $GLOBALS['error_direct_order'] = 1;
                }
            } else {
                $GLOBALS['error_direct_order'] = 1;
            }
            break;
        case "shop_add_item_to_basket_list":
            if ($_GET["action_id"] <> "") {
                for ($i = 1; $i < \DynCom\Compat\Compat::count($_POST); $i++) {
                    $item_id = $_POST["input_item_id_" . $i];
                    $item_quantity = $_POST["input_item_quantity_" . $i];
                    if (($item_id == $_GET["action_id"])) {
                        if ($item_quantity == 0) {
                            $item_quantity = 1;
                        }
                        shop_add_item_to_basket($GLOBALS["visitor"]["id"], $item_id, $item_quantity);
                    }
                }
            }
            break;
        case "shop_add_all_items_to_basket":
            for ($i = 1; $i < \DynCom\Compat\Compat::count($_POST); $i++) {
                $item_id = $_POST["input_item_id_" . $i];
                $item_quantity = $_POST["input_item_quantity_" . $i];
                $variant = $_POST["input_item_variant_" . $i];
                if ($item_quantity <> "") {
                    shop_add_item_to_basket($GLOBALS["visitor"]["id"], $item_id, $item_quantity, $variant);
                }
            }
            break;
        case "shop_refresh_user_basket":
            for ($i = 1; $i < \DynCom\Compat\Compat::count($_POST); $i++) {
                $item_id = $_POST["input_item_id_" . $i];
                $item_quantity = $_POST["input_item_quantity_" . $i];
                $variant_code = $_POST["input_variant_code_" . $i];
                if ($item_id <> "") {
                    shop_change_item_in_basket($GLOBALS["visitor"]["id"], $item_id, $item_quantity, $variant_code);
                }
            }
            break;
        case "shop_remove_item_from_basket":
            shop_remove_item_from_basket($GLOBALS["visitor"]["id"], $_GET["action_id"], $_GET['var_code']);
            break;
        case "shop_remove_item_from_basket_by_id":
            shop_remove_item_from_basket_by_id($_GET["action_id"]);
            break;
        case "shop_empty_user_basket":
            shop_empty_user_basket($GLOBALS["visitor"]["id"]);
            break;
        case "shop_add_item_to_favorites":
            shop_add_item_to_favorites($GLOBALS["visitor"]["id"], $_GET["action_id"]);
            break;
        case "shop_remove_item_from_favorites":
            shop_remove_item_from_favorites($GLOBALS["visitor"]["id"], $_GET["action_id"]);
            break;
        case "shop_empty_user_favorites":
            shop_empty_user_favorites($GLOBALS["visitor"]["id"]);
            break;
        case "shop_add_package":
            shop_add_package($GLOBALS["visitor"]["id"], $_GET['item'], $_GET['package']);
            break;
        case "shop_remove_package_from_basket":
            shop_remove_package($GLOBALS["visitor"]["id"], $_GET['action_id']);
            break;
    }
}

function shop_add_item_to_basket($visitor_id, $item_id, $quantity = 1, $variant = '')
{
    $item_query = "SELECT shop_item.*,shop_vat_posting_setup.vat_percent AS 'vat_percent'
				  FROM shop_item
				  LEFT JOIN shop_vat_posting_setup ON (shop_vat_posting_setup.company=shop_item.company AND shop_vat_posting_setup.vat_bus_posting_group='" . $GLOBALS['shop']['vat_bus_posting_group'] . "' AND shop_vat_posting_setup.vat_prod_posting_group=shop_item.vat_prod_posting_group)
			  	  WHERE shop_item.id = '" . $item_id . "'";
    $item_result = mysqli_query($GLOBALS['mysql_con'], $item_query);
    if ((@\DynCom\Compat\Compat::mysqli_num_rows($item_result) > 0) && ($quantity > 0)) {
        if (@\DynCom\Compat\Compat::mysqli_num_rows($item_result) == 1) {
            $item = mysqli_fetch_array($item_result);
        }

        $query = "SELECT *
				  FROM shop_user_basket
				  WHERE shop_visitor_id = '" . $visitor_id . "'
				  	AND shop_item_id = '" . $item_id . "'
				  	AND variant_code = '" . $variant . "'
				  LIMIT 1";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
            $basket_entry = mysqli_fetch_array($result);
            $quantity = $basket_entry["item_quantity"] + $quantity;
            $changed_to_min = false;
            $changed_to_vpe = false;
            $quantity = get_allowed_basket_quantity($item, $quantity, $changed_to_min, $changed_to_vpe);
            $item_price = get_item_customer_price(
                $item,
                $GLOBALS["shop_customer"],
                $quantity,
                $GLOBALS['shop_currency']['code'],
                $variant,
                true,
                $GLOBALS['shop']['campain_no']
            );
            $item_price_wo_vat = ($GLOBALS["shop"]["prices_including_vat"] && $item['vat_percent'] > 0) ? ($item_price['price'] - ($item_price['price'] / (100 + $item['vat_percent']) * $item['vat_percent'])) : '';
            $query = "UPDATE shop_user_basket
					  SET item_quantity = '" . $quantity . "', customer_price = '" . round($item_price["price"], 4) . "',
						  customer_price_wo_vat = " . round($item_price_wo_vat, 4) . ",
					  	  allow_invoice_disc = " . (int)$item_price["allow_invoice_discount"] . ",
					  	  variant_code = '" . $variant . "', changed_to_minimum = '" . $changed_to_min . "',changed_to_vpe = '" . $changed_to_vpe . "'
					  WHERE id = '" . $basket_entry["id"] . "'
					  LIMIT 1";
            mysqli_query($GLOBALS['mysql_con'], $query);
        } else {
            $changed_to_min = false;
            $changed_to_vpe = false;
            $quantity = get_allowed_basket_quantity($item, $quantity, $changed_to_min, $changed_to_vpe);
            $item_price = get_item_customer_price(
                $item,
                $GLOBALS["shop_customer"],
                $quantity,
                (!empty($GLOBALS['shop_currency']['code']) ? $GLOBALS['shop_currency']['code'] : ''),
                $variant,
                true,
                $GLOBALS['shop']['campain_no']
            );
            $item_price_wo_vat = ($GLOBALS["shop"]["prices_including_vat"] && $item['vat_percent'] > 0) ? ($item_price['price'] - ($item_price['price'] / (100 + $item['vat_percent']) * $item['vat_percent'])) : $item_price['price'];

            $query = "INSERT INTO shop_user_basket (id,shop_visitor_id,shop_item_id,item_quantity,customer_price,customer_price_wo_vat,allow_invoice_disc,insert_datetime,variant_code,changed_to_minimum,changed_to_vpe)
					  VALUES (NULL,'" . $visitor_id . "','" . $item_id . "','" . $quantity . "','" . round(
                    $item_price["price"],
                    4
                ) . "'," . round($item_price_wo_vat, 4) . ",'" . (int)$item_price["allow_invoice_discount"] . "',NOW(),
					  		  '" . $variant . "','" . $changed_to_min . "','" . $changed_to_vpe . "')";
            @mysqli_query($GLOBALS['mysql_con'], $query);
        }
    }
}

function shop_change_item_in_basket($visitor_id, $item_id, $quantity, $variant_code = '')
{
    if ($quantity > 0) {
        $item_query = "SELECT *
					   FROM shop_item
					   WHERE id = '" . $item_id . "'";
        $item_result = mysqli_query($GLOBALS['mysql_con'], $item_query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($item_result) == 1) {
            $item = mysqli_fetch_array($item_result);
        }
        $changed_to_min = false;
        $changed_to_vpe = false;
        $quantity = get_allowed_basket_quantity($item, $quantity, $changed_to_min, $changed_to_vpe);
        $item_price = get_item_customer_price(
            $item,
            $GLOBALS["shop_customer"],
            $quantity,
            $GLOBALS['shop_currency']['code'],
            "",
            true,
            $GLOBALS['shop']['campain_no']
        );
        $query = "UPDATE shop_user_basket
				  SET item_quantity = '" . $quantity . "', customer_price = '" . $item_price["price"] . "',
				  	  allow_invoice_disc = '" . $item_price["allow_invoice_discount"] . "', changed_to_minimum = '" . $changed_to_min . "',changed_to_vpe = '" . $changed_to_vpe . "'
				  WHERE shop_visitor_id = '" . $visitor_id . "'
				  	AND shop_item_id = '" . $item_id . "'
				  	AND variant_code = '" . $variant_code . "'
				  LIMIT 1";
        mysqli_query($GLOBALS['mysql_con'], $query);
    } else {
        shop_remove_item_from_basket($visitor_id, $item_id, $variant_code);
    }
}

function shop_remove_item_from_basket($visitor_id, $item_id, $variant_code = '')
{
    if (($visitor_id <> '') | ($item_id <> '')) {
        $query = "DELETE FROM shop_user_basket
				  WHERE shop_visitor_id = '" . $visitor_id . "'
				  	AND shop_item_id = '" . $item_id . "'
				  	AND variant_code ='" . $variant_code . "'";
        mysqli_query($GLOBALS['mysql_con'], $query);
    }
}

function shop_remove_item_from_basket_by_id($basket_id)
{
    if ($basket_id <> '') {
        $query = "DELETE FROM shop_user_basket
				  WHERE id = '" . $basket_id . "'";
        mysqli_query($GLOBALS['mysql_con'], $query);
    }
}

function shop_empty_user_basket($visitor_id)
{
    if ($visitor_id <> '') {
        $query = "DELETE FROM shop_user_basket
				  WHERE shop_visitor_id = '" . $visitor_id . "'";
        mysqli_query($GLOBALS['mysql_con'], $query);
    }
}

function shop_add_item_to_favorites($visitor_id, $item_id)
{
    $query = "SELECT *
			  FROM shop_item
			  WHERE id = '" . $item_id . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        if($GLOBALS['shop_user']['id']) {
            $query = "SELECT *
				  FROM shop_user_favorites
				  WHERE shop_user_id = '" . $GLOBALS['shop_user']['id'] . "'
				  	AND shop_item_id = '" . $item_id . "'
				  LIMIT 1";
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 0) {
                $query = "INSERT INTO shop_user_favorites (id,shop_user_id,shop_item_id,insert_datetime)
					  VALUES (NULL,'" . $GLOBALS['shop_user']['id'] . "','" . $item_id . "',NOW())";
                mysqli_query($GLOBALS['mysql_con'], $query);
            }
        } else {
            $query = "SELECT *
                      FROM shop_user_favorites
                      WHERE shop_visitor_id = '" . $visitor_id . "'
                        AND shop_item_id = '" . $item_id . "'
                      LIMIT 1";
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 0) {
                $query = "INSERT INTO shop_user_favorites (id,shop_visitor_id,shop_item_id,insert_datetime)
                          VALUES (NULL,'" . $visitor_id . "','" . $item_id . "',NOW())";
                mysqli_query($GLOBALS['mysql_con'], $query);
            }

        }
    }
}

function shop_remove_item_from_favorites($visitor_id, $item_id)
{
    if($GLOBALS['shop_user']['id']) {
        if (($item_id <> '')) {
            $query = "DELETE FROM shop_user_favorites
                      WHERE shop_user_id = '" . $GLOBALS['shop_user']['id'] . "'
                        AND shop_item_id = '" . $item_id . "'";
            mysqli_query($GLOBALS['mysql_con'], $query);
        }
    } else {

        if (($visitor_id <> '') && ($item_id <> '')) {
            $query = "DELETE FROM shop_user_favorites
                      WHERE shop_visitor_id = '" . $visitor_id . "'
                        AND shop_item_id = '" . $item_id . "'";
            mysqli_query($GLOBALS['mysql_con'], $query);
        }
    }
}

function shop_empty_user_favorites($visitor_id)
{
    if($GLOBALS['shop_user']['id']) {
        $query = "DELETE FROM shop_user_favorites
				  WHERE shop_user_id = '" . $GLOBALS['shop_user']['id'] . "'";
        mysqli_query($GLOBALS['mysql_con'], $query);
    } else {
        if ($visitor_id <> '') {
            $query = "DELETE FROM shop_user_favorites
                      WHERE shop_visitor_id = '" . $visitor_id . "'";
            mysqli_query($GLOBALS['mysql_con'], $query);
    }
    }
}

function shop_remove_package($visitor_id, $basket_id)
{
    if (($visitor_id <> '') | ($basket_id <> '')) {
        $query = "DELETE FROM shop_user_basket WHERE id= '" . $basket_id . "'";
        mysqli_query($GLOBALS['mysql_con'], $query);
    }
}

function shop_add_package($visitor_id, $item_id, $package_id)
{
    date_default_timezone_set("Europe/Berlin");
    $query = "SELECT * FROM shop_item WHERE id= '" . $package_id . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $pack = mysqli_fetch_assoc($result);
    $query = "SELECT id,item_quantity FROM shop_user_basket WHERE shop_visitor_id ='" . $visitor_id . "' AND shop_item_id='" . $package_id . "' AND package_for_item= '" . $item_id . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $query = "UPDATE shop_user_basket SET item_quantity=" . ($row['item_quantity'] + 1) . " WHERE id=" . $row['id'];
    } else {
        $query = "INSERT INTO shop_user_basket(shop_visitor_id,shop_item_id,item_quantity,allow_invoice_disc,customer_price,insert_datetime,package_for_item)
				VALUES(" . $visitor_id . "," . $package_id . ",1," . $pack['allow_invoice_discount'] . "," . $pack['base_price'] . ",'" . date(
                "Y-m-d H:i:s"
            ) . "'," . $item_id . ")";
    }
    mysqli_query($GLOBALS['mysql_con'], $query);
    headerFunctionBridge("location:/basket/");
}

function shop_get_basket_amount($visitor_id, $allow_invoice_disc = false)
{
    if ($allow_invoice_disc) {
        $query = "SELECT SUM(item_quantity * customer_price) AS 'basket_amount'
				  FROM shop_user_basket
				  WHERE shop_visitor_id = '" . $visitor_id . "'
				  	AND allow_invoice_disc = TRUE
				  	AND shop_visitor_id != 0";
    } else {
        $query = "SELECT SUM(item_quantity * customer_price) AS 'basket_amount'
				  FROM shop_user_basket
				  WHERE shop_visitor_id = '" . $visitor_id . "'
				  	AND shop_visitor_id != 0";
    }
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $basket_entry = mysqli_fetch_array($result);
    return $basket_entry["basket_amount"];
}

function shop_get_basket_quantity($visitor_id)
{
    $query = "SELECT id
			  FROM shop_user_basket
			  WHERE shop_visitor_id = '" . $visitor_id . "'
			  	AND shop_visitor_id != 0";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    return @\DynCom\Compat\Compat::mysqli_num_rows($result);
}

function shop_get_favorites_quantity($visitor_id)
{
    if($GLOBALS['shop_user']['id']) {
        $query  = "SELECT shop_view_active_item.*
              FROM shop_view_active_item
              LEFT JOIN shop_user_favorites ON shop_view_active_item.id = shop_user_favorites.shop_item_id
              WHERE shop_user_favorites.shop_user_id = '" . $GLOBALS['shop_user']['id'] . "'
                AND shop_view_active_item.company = '". $GLOBALS["shop"]["company"] ."'
                AND shop_view_active_item.shop_code = '". $GLOBALS["shop"]["item_source"] ."'
                AND shop_view_active_item.language_code = '". $GLOBALS["shop_language"]["code"] ."'
              ORDER BY shop_view_active_item.item_no";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        return @\DynCom\Compat\Compat::mysqli_num_rows($result);
    } else {
        $query  = "SELECT shop_view_active_item.*
              FROM shop_view_active_item
              LEFT JOIN shop_user_favorites ON shop_view_active_item.id = shop_user_favorites.shop_item_id
              WHERE shop_user_favorites.shop_visitor_id = '" . $visitor_id . "'
                AND shop_view_active_item.company = '". $GLOBALS["shop"]["company"] ."'
                AND shop_view_active_item.shop_code = '". $GLOBALS["shop"]["item_source"] ."'
                AND shop_view_active_item.language_code = '". $GLOBALS["shop_language"]["code"] ."'
              ORDER BY shop_view_active_item.item_no";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        return @\DynCom\Compat\Compat::mysqli_num_rows($result);
    }
}

//Sucht die erlaubte Menge nach Mindestbestellmenge und Verpackungseinheit (die changed-Variablen werden per Referenz übergeben und im Basket gespeichert!)
function get_allowed_basket_quantity($item, $quantity, &$changed_to_min, &$changed_to_vpe)
{
    if ($quantity < $item['minimum_order_quantity']) {
        $quantity = $item['minimum_order_quantity'];
        $changed_to_min = true;
    }
    if ($item['order_per_packing_unit'] == 1 && $item['quantity_packing_unit'] > 0) {
        if ($quantity % $item['quantity_packing_unit'] != 0) {
            $quantity = (floor($quantity / $item['quantity_packing_unit']) + 1) * $item['quantity_packing_unit'];
            $changed_to_vpe = true;
        }
    }
    return $quantity;
}

// ---Warenkorb/Favoriten-Funktionen---
function shop_login_listener()
{
    $passwordHashingOptions = get_password_options();
    $loginDataUsed = "";
	$isLoginAction = false;

    switch ($_REQUEST["action"]) {
        case "shop_logout":

            $eventName = 'beforeLogout';
            $eventData = ['visitor' => $GLOBALS['visitor'], 'user' => $GLOBALS['shop_user'], 'customer' => $GLOBALS['shop_customer']];
            Hook::update($eventName, $eventData);
            if (isset($_SERVER['HTTP_COOKIE'])) {
                $cookies = explode(';', $_SERVER['HTTP_COOKIE']);
                foreach ($cookies as $cookie) {
                    $parts = explode('=', $cookie);
                    $name = trim($parts[0]);
                    if (strpos($name, $GLOBALS['site']['code']) !== false) {
                        setcookie($name, '', time() - 1000, '/', NULL, NULL, true);
                        setcookie($name, '', time() - 1000, '/', NULL, NULL, true);
                    }
                }
            }
            $visitor = get_visitor_small(session_id());
            if ($visitor["id"] <> '') {
                $query = "UPDATE main_visitor
						  SET frontend_login = FALSE, remember_token=NULL,main_user_id = NULL
						  WHERE id = " . $visitor["id"] . "
						  LIMIT 1";
                mysqli_query($GLOBALS['mysql_con'], $query);
                //echo "<!-- VISITOR LOGGED OUT (ACTION shop_logout) -->";
                //visitor aktualisieren
                $visitor = get_visitor_small(session_id());
                $GLOBALS["visitor"] = $visitor;
            }

            $eventName = 'afterLogout';
            $eventData = ['visitor' => $GLOBALS['visitor'], 'user' => $GLOBALS['shop_user'], 'customer' => $GLOBALS['shop_customer']];
            Hook::update($eventName, $eventData);
            break;
        case "shop_login":
			$isLoginAction = true;
            if(!$_SESSION['is_oci_call']) {
                $input_login = filter_var($_POST['input_login'], FILTER_SANITIZE_STRING);
                $input_email = filter_var($_POST['input_email'], FILTER_SANITIZE_STRING);
                $input_password = filter_input(INPUT_POST, 'input_password');
            } else {
                $_REQUEST['PASSWORD'] = str_replace(" ","+",$_REQUEST['PASSWORD']);
                $input_login = filter_var($_REQUEST['USERNAME'], FILTER_SANITIZE_STRING);
                $input_email = filter_var($_REQUEST['USERNAME'], FILTER_SANITIZE_STRING);
                $input_password = filter_var($_REQUEST['PASSWORD'], FILTER_SANITIZE_STRING);

            }
            $eventName = 'beforeLogin';
            $eventData = ['input_login' => &$input_login, 'input_email' => &$input_email, 'input_password' => &$input_password];
            Hook::update($eventName, $eventData);
            if (($_POST["input_customer_no"] <> '' || $_POST["input_login"] <> '' || $_POST['input_email'] != '') && ($_POST["input_password"] <> '')) {
                $visitor = get_visitor_small(session_id());
                unset($login_snippet);

                $loginDataUsed = $_POST['input_email'];
                $currentLoginData = $_POST['input_email'];

                switch ($GLOBALS['shop']['login_type']) {
                    case 0:
                        //US OCI
                        $escapedInputEmail = mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST["input_email"]);
                        if($GLOBALS['shop']['shop_typ'] <> 2) {
                            $login_snippet =  " AND (shop_user.email = '$escapedInputEmail' or shop_user.login = '$escapedInputEmail') ";
                        } else {
                            $login_snippet =  " AND email = '$escapedInputEmail' ";
                        }
                        $loginDataUsed = $_POST['input_email'];
                        break;
                    case 1:
                        $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND UPPER(shop_user.login) = UPPER('" . mysqli_real_escape_string(
                                $GLOBALS['mysql_con'],
                                $_POST["input_login"]
                            ) . "') " : " AND UPPER(salesperson_code) = UPPER('" . mysqli_real_escape_string(
                                $GLOBALS['mysql_con'],
                                $_POST["input_login"]
                            ) . "') ");
                        $loginDataUsed = $_POST['input_login'];
                        $currentLoginData = $_POST['input_login'];
                        break;
                    case 2:
                        $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                                $GLOBALS['mysql_con'],
                                $_POST['input_customer_no']
                            ) . "'  AND UPPER(shop_user.login) = UPPER('" . mysqli_real_escape_string(
                                $GLOBALS['mysql_con'],
                                $_POST["input_login"]
                            ) . "') " : " AND 1=2 "); //Für Salesperson nicht zulässig
                        $loginDataUsed = $_POST['input_login'] . "_" . $_POST['input_customer_no'];
                        $currentLoginData = $_POST['input_login'];
                        break;
                    case 3:
                        $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                                $GLOBALS['mysql_con'],
                                $_POST['input_customer_no']
                            ) . "'  AND UPPER(shop_user.email) = UPPER('" . mysqli_real_escape_string(
                                $GLOBALS['mysql_con'],
                                $_POST["input_email"]
                            ) . "') " : " AND 1=2 "); //Für Salesperson nicht zulässig
                        $loginDataUsed = $_POST['input_email'];
                        $currentLoginData = $_POST['input_email'];
                        break;
                    case 4:
                        $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                                $GLOBALS['mysql_con'],
                                $_POST['input_customer_no']
                            ) . "' " : " AND 1=2 "); //Für Salesperson nicht zulässig
                        $loginDataUsed = $_POST['input_customer_no'];
                        break;
                }
                $table = 'shop_user';
                switch ($GLOBALS['shop']['shop_typ']) {
                    case 0:
                        $query = "SELECT shop_user.*
								  FROM shop_user
								  INNER JOIN shop_customer ON shop_customer.company = shop_user.company AND shop_customer.customer_no = shop_user.customer_no
								  WHERE 
								  	shop_user.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
								  	AND shop_customer.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_customer.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
									AND shop_customer.language_code = '" . $GLOBALS['shop_language']['code'] . "'
								  	 " . $login_snippet . " 
								  	#AND shop_user.password = '" . md5($_POST["input_password"]) . "'
								  	AND shop_customer.active = 1
								  LIMIT 1";
                        break;
                    case 1:
                        //US OCI
                        $query = "SELECT shop_user.*
								  FROM shop_user
								  INNER JOIN shop_customer ON shop_customer.company = shop_user.company AND shop_customer.customer_no = shop_user.customer_no
								  WHERE shop_user.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
								  	AND shop_customer.language_code = '" . $GLOBALS['shop_language']['code'] . "'
								  	 " . $login_snippet . " 
								  	#AND shop_user.password = '" . md5($_POST["input_password"]) . "'
								  	AND shop_customer.active = 1
								  LIMIT 1";
                        break;
                    case 2:
                        $table = 'shop_salesperson';
                        $query = "SELECT *
								  FROM shop_salesperson
								  WHERE company = '" . $GLOBALS['shop']['company'] . "'
								  	 " . $login_snippet . " 
								  	#AND PASSWORD = '" . md5($_POST["input_password"]) . "'
								  LIMIT 1";
                        break;
                    case 3:
                        $query = "SELECT shop_user.*
								  FROM shop_user
								  INNER JOIN shop_customer ON shop_customer.company = shop_user.company AND shop_customer.customer_no = shop_user.customer_no 
								  WHERE shop_user.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
								  	AND shop_customer.language_code = '" . $GLOBALS['shop_language']['code'] . "'
								  	 " . $login_snippet . " 
                                    #AND shop_user.password = '" . md5($_POST["input_password"]) . "'
                                    AND shop_customer.active = 1
								  LIMIT 1";
                        break;
                }

                $result = mysqli_query($GLOBALS['mysql_con'], $query);
                if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
                    $row = mysqli_fetch_assoc($result);
                    $rowID = $row['id'];
                    $dbPasswordHash = $row['password'];
                    $inputPassword = $input_password;
                    $eventName = 'afterLoginUserFound';
                    $eventData = ['input_login' => &$input_login, 'input_email' => &$input_email];
                    Hook::update($eventName, $eventData);
                    $validOldHash = md5($inputPassword) === $dbPasswordHash;
                    if (password_verify($inputPassword, $dbPasswordHash) || $validOldHash) {
                        if ($validOldHash || password_needs_rehash(
                                $dbPasswordHash,
                                PASSWORD_DEFAULT,
                                $passwordHashingOptions
                            )
                        ) {
                            $rehashedPassword = password_hash(
                                $inputPassword,
                                PASSWORD_DEFAULT,
                                $passwordHashingOptions
                            );
                            mysqli_query(
                                $GLOBALS['mysql_con'],
                                'UPDATE ' . $table . ' SET password = \'' . $rehashedPassword . '\' WHERE id = ' . $rowID
                            );
                            $row = mysqli_fetch_assoc(
                                mysqli_query($GLOBALS['mysql_con'], 'SELECT * FROM ' . $table . ' WHERE id = ' . $rowID)
                            );
                        }
                        if($row['needs_authorization'] == 1) {
                            $_POST['needs_authorization'] = true;
                            $user = $row;
                            unset($user['password']);
                            $_SESSION['USER_OAUTH'] = $user;

                            $newaddress = $_SERVER['HTTP_REFERER'];
                            $newaddress = str_replace(
                                array('?action=shop_login', '&action=shop_login', '?action=login', '&action=login'),
                                '',
                                $newaddress
                            );
                            unset($_GET['action']);
                            unset($_POST['action']);
                            if (substr_count($newaddress, "/") == 3 && (int)$GLOBALS["language"]["logout_site_id"] > 0) {
                                $logoutSite = site_getbyid($GLOBALS["language"]["logout_site_id"]);
                                if($logoutSite['is_unique_site'] == "0") {

                                $newaddress .= $logoutSite["code"] . "/" . $GLOBALS["language"]["code"] . "/";
                                }
                            }
                            if (strpos($newaddress, '?') !== false) {
                                $newaddress .= "&needs_authorization=true";
                            } else {
                                $newaddress .= "?needs_authorization=true";
                            }

                            if(!empty($user['token'])) {
                                $newaddress .= "&already_fullfilled=true";
                            }

                            headerFunctionBridge("Location: " . $newaddress);
                            exit();
                        } else {
                            $eventName = 'afterSuccessfullLoginAuthentication';
                            $eventData = ['input_login' => &$input_login, 'input_email' => &$input_email, 'input_password' => &$input_password];
                            Hook::update($eventName, $eventData);
                            $login_error = true;
                            $shop_user = $row;


                            $eventData = [
                                'session_id' => session_id(),
                                'current_visitor_id' => $GLOBALS['visitor']['id'],
                                'identified_user_id' => $row['id'],
                            ];
                            $eventName = 'afterUserLogin';
                            Hook::update($eventName, $eventData);

                            $GLOBALS['visitor']['pass_auth'] = true;
                            if (isset($_POST['remember_login'])) {
                                $remember_token_unique = md5(
                                    uniqid(
                                        session_id(),
                                        true
                                    ) . $shop_user['password'] . $GLOBALS['visitor']['main_user_id']
                                );
                            } else {
                                $remember_token_unique = '';
                            }
                            //Neue Session wenn anderer User
                            if (($GLOBALS['shop']['shop_typ'] != 2 && ($visitor['main_user_id'] != $shop_user['id'])) || ($GLOBALS['shop']['shop_typ'] == 2 && ($visitor['shop_salesperson_id'] != $shop_user['id']))) {

                                $old_sid = session_id();
                                $old_visitor_id = $visitor['id'];
                                if (session_regenerate_id()) {
                                    $new_sid = session_id();
                                    Hook::update('session_id_changed', ['old_sid' => $old_sid, 'new_sid' => $new_sid]);
                                }

                                //Alten Visitor löschen
                                $delete_query = "DELETE FROM main_visitor WHERE session_id = '" . mysqli_real_escape_string(
                                        $GLOBALS['mysql_con'],
                                        $old_sid
                                    ) . "'";
                                @mysqli_query($GLOBALS['mysql_con'], $delete_query);

                                //Neuen visitor anlegen:
                                if ($GLOBALS['shop']['shop_typ'] != 2) {
                                    $user_snippet = 'main_user_id = ' . (int)$shop_user['id'];
                                } else {
                                    $user_snippet = 'shop_salesperson_id = ' . (int)$shop_user['id'] . ', main_user_id = ' . (int)$shop_user['id'];
                                }

                                $ip = $_SERVER['REMOTE_ADDR'];
                                $ipv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
                                $regexCount = null;
                                $ipv4anon = preg_replace(
                                    '/(\\d{1,3})\\.(\\d{1,3})\\.(\\d{1,3}).*/',
                                    '$1.$2.$3.0',
                                    $ipv4,
                                    -1,
                                    $regexCount
                                );
                                $ipv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $ip : '';
                                $ipv6sections = [];
                                $ipv6anon = implode(':', explode(':', $ipv6, 2));

                                $insert_query = "
							INSERT INTO 
								main_visitor 
							SET 
								session_id = '" . mysqli_real_escape_string($GLOBALS['mysql_con'], $new_sid) . "', 
								last_ipv4_anon = '$ipv4anon',
								last_ipv6_anon = '$ipv6anon',
								session_date = NOW(), 
								valid_until = DATE_ADD(NOW(), INTERVAL 30 DAY), 
								frontend_login = TRUE,
								" . $user_snippet . ",
								remember_token = '" . $remember_token_unique . "'";
                                @mysqli_query($GLOBALS['mysql_con'], $insert_query);
                                $visitor = get_visitor_small($new_sid);
                                $GLOBALS['visitor'] = $visitor;
                                $new_visitor_id = $visitor['id'];

                            } elseif ($GLOBALS['shop']['shop_typ'] != 2) {
                                $query = "UPDATE main_visitor
								  SET 
								    frontend_login = TRUE, 
								    nav_login= FALSE, 
								    main_user_id = " . $shop_user["id"] . ",
								    cookie_only=0,
								    remember_token='" . $remember_token_unique . "'
								  WHERE id = " . $visitor["id"] . "
								  LIMIT 1";
                            } else {
                                $query = "UPDATE main_visitor
								  SET frontend_login = TRUE, nav_login= FALSE, main_user_id = " . $shop_user["id"] . ",cookie_only=0,remember_token='" . $remember_token_unique . "',
								  shop_salesperson_id = " . $shop_user['id'] . "
								  WHERE id = " . $visitor["id"] . "
								  LIMIT 1";
                            }
                            if (mysqli_query($GLOBALS['mysql_con'], $query)) {
                                if (isset($_POST['remember_login'])) {

                                    $cookie_timeout = COOKIE_DAYS_VALID * 24 * 60 * 60;
                                    setcookie(
                                        $GLOBALS['site']['code'] . '_remember_login',
                                        $remember_token_unique,
                                        time() + $cookie_timeout,
                                        '/'
                                        , NULL, NULL, true);
                                    setcookie(
                                        'sid' . $GLOBALS['site']['code'],
                                        session_id(),
                                        time() + $cookie_timeout,
                                        '/'
                                        , NULL, NULL, true);
                                }
                            }
                            //visitor aktualisieren
                            $visitor = get_visitor_small(session_id());
                            $GLOBALS["visitor"] = $visitor;
                            //Warenkorb und Favoriten für angemeldete Benutzer ermitteln
                            if ($shop_user['last_visitor_id'] != '') {

                                if (!isset($IOCContainer)) {
                                    $IOCContainer = $GLOBALS['IOC'];
                                }
                                $basketLoginHandler = $IOCContainer->create(
                                    'DynCom\dc\dcShop\classes\UserBasketLoginHandler'
                                );
                                if ($GLOBALS['shop']['shop_typ'] == 1) {
                                    $basketLoginHandler->handleLogin($visitor['id'], $old_visitor_id, true);
                                } else {
                                    $basketLoginHandler->handleLogin($visitor['id'], $shop_user['last_visitor_id'], false);
                                }

                                $currbasket_query = "SELECT COUNT(id) AS 'counter' FROM shop_user_basket WHERE shop_visitor_id=" . $shop_user['last_visitor_id'];
                                $currbasket_result = mysqli_query($GLOBALS['mysql_con'], $currbasket_query);
                                $currbasket_no = mysqli_result($currbasket_result, 0, 0);
                                if (((int)$currbasket_no > 0)) {
                                    $query = "UPDATE shop_user_basket
									  SET shop_visitor_id = " . $GLOBALS['visitor']['id'] . "
									  WHERE shop_visitor_id = " . $shop_user['last_visitor_id'];
                                    mysqli_query($GLOBALS['mysql_con'], $query);
                                } else { //Kann mit Konditional raus.
                                    $query = "DELETE FROM shop_user_basket
									  WHERE shop_visitor_id = " . $shop_user["last_visitor_id"];
                                    mysqli_query($GLOBALS['mysql_con'], $query);
                                }
                                $query = "UPDATE shop_user_favorites
								  SET shop_visitor_id = " . $GLOBALS['visitor']['id'] . "
								  WHERE shop_visitor_id = " . $shop_user['last_visitor_id'];
                                mysqli_query($GLOBALS['mysql_con'], $query);
                                $query = "UPDATE shop_user
								  SET last_visitor_id = " . $GLOBALS['visitor']['id'] . "
								  WHERE id = " . $shop_user['id'];
                                mysqli_query($GLOBALS['mysql_con'], $query);
                            }
                            $_SESSION['pass_auth'] = true;
                            $GLOBALS['visitor']['pass_auth'] = true;
                            $customer = get_shop_customer($shop_user);
                            $_SESSION['sid' . $GLOBALS['site']['code']] = session_id();
                            $eventName = 'afterSuccessfullLogin';
                            $eventData = ['visitor' => &$GLOBALS['visitor'], 'shop_user' => &$shop_user, 'shop_customer' => &$customer];
                            Hook::update($eventName, $eventData);
                            check_generate_customer_address($customer);
                            $login_error = false;
                            //delete_false_password_login_counter(md5(getUserIP()), md5($loginDataUsed));
                        }


                    } else {
                        $eventName = 'afterUnsuccessfulLogin';
                        $eventData = ['input_login' => &$input_login, 'input_email' => &$input_email, 'input_password' => &$input_password];
                        Hook::update($eventName, $eventData);
                        $login_error = true;
                    }

                } else {
                    $login_error = true;
                }
            } else {
                if ($_SESSION['is_oci_call']) {
                    if (isset($_REQUEST['USERNAME'])) {
                        $username = $_REQUEST['USERNAME'];
                        if (isset($_REQUEST['PASSWORD'])) {
                            $password = $_REQUEST['PASSWORD'];
                            $visitor = get_visitor_small(session_id());
                            unset($login_snippet);
                            $loginDataUsed = $username;
                            $currentLoginData = $username;
                            switch ($GLOBALS['shop']['login_type']) {
                                case 0:
                                    //US OCI
                                    $escapedInputEmail = mysqli_real_escape_string($GLOBALS['mysql_con'],$username);
                                    if($GLOBALS['shop']['shop_typ'] <> 2) {
                                        $login_snippet =  " AND (shop_user.email = '$escapedInputEmail' or shop_user.login = '$escapedInputEmail') ";
                                    } else {
                                        $login_snippet =  " AND email = '$escapedInputEmail' ";
                                    }
                                    $loginDataUsed = $username;
                                    break;
                                case 1:
                                    $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND UPPER(shop_user.login) = UPPER('" . mysqli_real_escape_string(
                                            $GLOBALS['mysql_con'],
                                            $username
                                        ) . "') " : " AND UPPER(salesperson_code) = UPPER('" . mysqli_real_escape_string(
                                            $GLOBALS['mysql_con'],
                                            $username
                                        ) . "') ");
                                    $loginDataUsed = $username;
                                    $currentLoginData = $username;
                                    break;
                                case 2:
                                    $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                                            $GLOBALS['mysql_con'],
                                            $_POST['input_customer_no']
                                        ) . "'  AND UPPER(shop_user.login) = UPPER('" . mysqli_real_escape_string(
                                            $GLOBALS['mysql_con'],
                                            $_POST["input_login"]
                                        ) . "') " : " AND 1=2 "); //Für Salesperson nicht zulässig
                                    $loginDataUsed = $_POST['input_login'] . "_" . $_POST['input_customer_no'];
                                    $currentLoginData = $_POST['input_login'];
                                    break;
                                case 3:
                                    $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                                            $GLOBALS['mysql_con'],
                                            $_POST['input_customer_no']
                                        ) . "'  AND UPPER(shop_user.email) = UPPER('" . mysqli_real_escape_string(
                                            $GLOBALS['mysql_con'],
                                            $_POST["input_email"]
                                        ) . "') " : " AND 1=2 "); //Für Salesperson nicht zulässig
                                    $loginDataUsed = $_POST['input_email'];
                                    $currentLoginData = $_POST['input_email'];
                                    break;
                                case 4:
                                    $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                                            $GLOBALS['mysql_con'],
                                            $_POST['input_customer_no']
                                        ) . "' " : " AND 1=2 "); //Für Salesperson nicht zulässig
                                    $loginDataUsed = $_POST['input_customer_no'];
                                    break;
                            }
                            $table = 'shop_user';
                            switch ($GLOBALS['shop']['shop_typ']) {
                                case 0:
                                    $query = "SELECT shop_user.*
								  FROM shop_user
								  INNER JOIN shop_customer ON shop_customer.company = shop_user.company AND shop_customer.customer_no = shop_user.customer_no
								  WHERE 
								  	shop_user.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
								  	AND shop_customer.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_customer.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
									AND shop_customer.language_code = '" . $GLOBALS['shop_language']['code'] . "'
								  	 " . $login_snippet . " 
								  	#AND shop_user.password = '" . md5($_POST["input_password"]) . "'
								  	AND shop_customer.active = 1
								  LIMIT 1";
                                    break;
                                case 1:
                                    //US OCI
                                    $query = "SELECT shop_user.*
								  FROM shop_user
								  INNER JOIN shop_customer ON shop_customer.company = shop_user.company AND shop_customer.customer_no = shop_user.customer_no
								  WHERE shop_user.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
								  	AND shop_customer.language_code = '" . $GLOBALS['shop_language']['code'] . "'
								  	 " . $login_snippet . " 
								  	#AND shop_user.password = '" . md5($_POST["input_password"]) . "'
								  	AND shop_customer.active = 1
								  LIMIT 1";
                                    break;
                                case 2:
                                    $table = 'shop_salesperson';
                                    $query = "SELECT *
								  FROM shop_salesperson
								  WHERE company = '" . $GLOBALS['shop']['company'] . "'
								  	 " . $login_snippet . " 
								  	#AND PASSWORD = '" . md5($_POST["input_password"]) . "'
								  LIMIT 1";
                                    break;
                                case 3:
                                    $query = "SELECT shop_user.*
								  FROM shop_user
								  INNER JOIN shop_customer ON shop_customer.company = shop_user.company AND shop_customer.customer_no = shop_user.customer_no 
								  WHERE shop_user.company = '" . $GLOBALS['shop']['company'] . "'
								  	AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
								  	AND shop_customer.language_code = '" . $GLOBALS['shop_language']['code'] . "'
								  	 " . $login_snippet . " 
                                    #AND shop_user.password = '" . md5($_POST["input_password"]) . "'
                                    AND shop_customer.active = 1
								  LIMIT 1";
                                    break;
                            }

                            $result = mysqli_query($GLOBALS['mysql_con'], $query);
                            if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
                                $row = mysqli_fetch_assoc($result);
                                $rowID = $row['id'];
                                $dbPasswordHash = $row['password'];
                                $inputPassword = $input_password;
                                $eventName = 'afterLoginUserFound';
                                $eventData = ['input_login' => &$input_login, 'input_email' => &$input_email];
                                Hook::update($eventName, $eventData);
                                $validOldHash = md5($inputPassword) === $dbPasswordHash;
                                if (password_verify($inputPassword, $dbPasswordHash) || $validOldHash) {
                                    if ($validOldHash || password_needs_rehash(
                                            $dbPasswordHash,
                                            PASSWORD_DEFAULT,
                                            $passwordHashingOptions
                                        )
                                    ) {
                                        $rehashedPassword = password_hash(
                                            $inputPassword,
                                            PASSWORD_DEFAULT,
                                            $passwordHashingOptions
                                        );
                                        mysqli_query(
                                            $GLOBALS['mysql_con'],
                                            'UPDATE ' . $table . ' SET password = \'' . $rehashedPassword . '\' WHERE id = ' . $rowID
                                        );
                                        $row = mysqli_fetch_assoc(
                                            mysqli_query($GLOBALS['mysql_con'], 'SELECT * FROM ' . $table . ' WHERE id = ' . $rowID)
                                        );
                                    }
                                    if($row['needs_authorization'] == 1) {
                                        $_POST['needs_authorization'] = true;
                                        $user = $row;
                                        unset($user['password']);
                                        $_SESSION['USER_OAUTH'] = $user;

                                        $newaddress = $_SERVER['HTTP_REFERER'];
                                        $newaddress = str_replace(
                                            array('?action=shop_login', '&action=shop_login', '?action=login', '&action=login'),
                                            '',
                                            $newaddress
                                        );
                                        unset($_GET['action']);
                                        unset($_POST['action']);
                                        if (substr_count($newaddress, "/") == 3 && (int)$GLOBALS["language"]["logout_site_id"] > 0) {
                                            $logoutSite = site_getbyid($GLOBALS["language"]["logout_site_id"]);
                                            if($logoutSite['is_unique_site'] == "0") {

                                                $newaddress .= $logoutSite["code"] . "/" . $GLOBALS["language"]["code"] . "/";
                                            }
                                        }
                                        if (strpos($newaddress, '?') !== false) {
                                            $newaddress .= "&needs_authorization=true";
                                        } else {
                                            $newaddress .= "?needs_authorization=true";
                                        }

                                        if(!empty($user['token'])) {
                                            $newaddress .= "&already_fullfilled=true";
                                        }

                                        headerFunctionBridge("Location: " . $newaddress);
                                        exit();
                                    } else {
                                        $eventName = 'afterSuccessfullLoginAuthentication';
                                        $eventData = ['input_login' => &$input_login, 'input_email' => &$input_email, 'input_password' => &$input_password];
                                        Hook::update($eventName, $eventData);
                                        $login_error = true;
                                        $shop_user = $row;


                                        $eventData = [
                                            'session_id' => session_id(),
                                            'current_visitor_id' => $GLOBALS['visitor']['id'],
                                            'identified_user_id' => $row['id'],
                                        ];
                                        $eventName = 'afterUserLogin';
                                        Hook::update($eventName, $eventData);

                                        $GLOBALS['visitor']['pass_auth'] = true;
                                        if (isset($_POST['remember_login'])) {
                                            $remember_token_unique = md5(
                                                uniqid(
                                                    session_id(),
                                                    true
                                                ) . $shop_user['password'] . $GLOBALS['visitor']['main_user_id']
                                            );
                                        } else {
                                            $remember_token_unique = '';
                                        }
                                        //Neue Session wenn anderer User
                                        if (($GLOBALS['shop']['shop_typ'] != 2 && ($visitor['main_user_id'] != $shop_user['id'])) || ($GLOBALS['shop']['shop_typ'] == 2 && ($visitor['shop_salesperson_id'] != $shop_user['id']))) {

                                            $old_sid = session_id();
                                            $old_visitor_id = $visitor['id'];
                                            if (session_regenerate_id()) {
                                                $new_sid = session_id();
                                                Hook::update('session_id_changed', ['old_sid' => $old_sid, 'new_sid' => $new_sid]);
                                            }

                                            //Alten Visitor löschen
                                            $delete_query = "DELETE FROM main_visitor WHERE session_id = '" . mysqli_real_escape_string(
                                                    $GLOBALS['mysql_con'],
                                                    $old_sid
                                                ) . "'";
                                            @mysqli_query($GLOBALS['mysql_con'], $delete_query);

                                            //Neuen visitor anlegen:
                                            if ($GLOBALS['shop']['shop_typ'] != 2) {
                                                $user_snippet = 'main_user_id = ' . (int)$shop_user['id'];
                                            } else {
                                                $user_snippet = 'shop_salesperson_id = ' . (int)$shop_user['id'] . ', main_user_id = ' . (int)$shop_user['id'];
                                            }

                                            $ip = $_SERVER['REMOTE_ADDR'];
                                            $ipv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $ip : '';
                                            $regexCount = null;
                                            $ipv4anon = preg_replace(
                                                '/(\\d{1,3})\\.(\\d{1,3})\\.(\\d{1,3}).*/',
                                                '$1.$2.$3.0',
                                                $ipv4,
                                                -1,
                                                $regexCount
                                            );
                                            $ipv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $ip : '';
                                            $ipv6sections = [];
                                            $ipv6anon = implode(':', explode(':', $ipv6, 2));

                                            $insert_query = "
							INSERT INTO 
								main_visitor 
							SET 
								session_id = '" . mysqli_real_escape_string($GLOBALS['mysql_con'], $new_sid) . "', 
								last_ipv4_anon = '$ipv4anon',
								last_ipv6_anon = '$ipv6anon',
								session_date = NOW(), 
								valid_until = DATE_ADD(NOW(), INTERVAL 30 DAY), 
								frontend_login = TRUE,
								" . $user_snippet . ",
								remember_token = '" . $remember_token_unique . "'";
                                            @mysqli_query($GLOBALS['mysql_con'], $insert_query);
                                            $visitor = get_visitor_small($new_sid);
                                            $GLOBALS['visitor'] = $visitor;
                                            $new_visitor_id = $visitor['id'];

                                        } elseif ($GLOBALS['shop']['shop_typ'] != 2) {
                                            $query = "UPDATE main_visitor
								  SET 
								    frontend_login = TRUE, 
								    nav_login= FALSE, 
								    main_user_id = " . $shop_user["id"] . ",
								    cookie_only=0,
								    remember_token='" . $remember_token_unique . "'
								  WHERE id = " . $visitor["id"] . "
								  LIMIT 1";
                                        } else {
                                            $query = "UPDATE main_visitor
								  SET frontend_login = TRUE, nav_login= FALSE, main_user_id = " . $shop_user["id"] . ",cookie_only=0,remember_token='" . $remember_token_unique . "',
								  shop_salesperson_id = " . $shop_user['id'] . "
								  WHERE id = " . $visitor["id"] . "
								  LIMIT 1";
                                        }
                                        if (mysqli_query($GLOBALS['mysql_con'], $query)) {
                                            if (isset($_POST['remember_login'])) {

                                                $cookie_timeout = COOKIE_DAYS_VALID * 24 * 60 * 60;
                                                setcookie(
                                                    $GLOBALS['site']['code'] . '_remember_login',
                                                    $remember_token_unique,
                                                    time() + $cookie_timeout,
                                                    '/'
                                                    , NULL, NULL, true);
                                                setcookie(
                                                    'sid' . $GLOBALS['site']['code'],
                                                    session_id(),
                                                    time() + $cookie_timeout,
                                                    '/'
                                                    , NULL, NULL, true);
                                            }
                                        }
                                        //visitor aktualisieren
                                        $visitor = get_visitor_small(session_id());
                                        $GLOBALS["visitor"] = $visitor;
                                        //Warenkorb und Favoriten für angemeldete Benutzer ermitteln
                                        if ($shop_user['last_visitor_id'] != '') {

                                            if (!isset($IOCContainer)) {
                                                $IOCContainer = $GLOBALS['IOC'];
                                            }
                                            $basketLoginHandler = $IOCContainer->create(
                                                'DynCom\dc\dcShop\classes\UserBasketLoginHandler'
                                            );
                                            if ($GLOBALS['shop']['shop_typ'] == 1) {
                                                $basketLoginHandler->handleLogin($visitor['id'], $old_visitor_id, true);
                                            } else {
                                                $basketLoginHandler->handleLogin($visitor['id'], $shop_user['last_visitor_id'], false);
                                            }

                                            $currbasket_query = "SELECT COUNT(id) AS 'counter' FROM shop_user_basket WHERE shop_visitor_id=" . $shop_user['last_visitor_id'];
                                            $currbasket_result = mysqli_query($GLOBALS['mysql_con'], $currbasket_query);
                                            $currbasket_no = mysqli_result($currbasket_result, 0, 0);
                                            if (((int)$currbasket_no > 0)) {
                                                $query = "UPDATE shop_user_basket
									  SET shop_visitor_id = " . $GLOBALS['visitor']['id'] . "
									  WHERE shop_visitor_id = " . $shop_user['last_visitor_id'];
                                                mysqli_query($GLOBALS['mysql_con'], $query);
                                            } else { //Kann mit Konditional raus.
                                                $query = "DELETE FROM shop_user_basket
									  WHERE shop_visitor_id = " . $shop_user["last_visitor_id"];
                                                mysqli_query($GLOBALS['mysql_con'], $query);
                                            }
                                            $query = "UPDATE shop_user_favorites
								  SET shop_visitor_id = " . $GLOBALS['visitor']['id'] . "
								  WHERE shop_visitor_id = " . $shop_user['last_visitor_id'];
                                            mysqli_query($GLOBALS['mysql_con'], $query);
                                            $query = "UPDATE shop_user
								  SET last_visitor_id = " . $GLOBALS['visitor']['id'] . "
								  WHERE id = " . $shop_user['id'];
                                            mysqli_query($GLOBALS['mysql_con'], $query);
                                        }
                                        $_SESSION['pass_auth'] = true;
                                        $GLOBALS['visitor']['pass_auth'] = true;
                                        $customer = get_shop_customer($shop_user);
                                        $_SESSION['sid' . $GLOBALS['site']['code']] = session_id();
                                        $eventName = 'afterSuccessfullLogin';
                                        $eventData = ['visitor' => &$GLOBALS['visitor'], 'shop_user' => &$shop_user, 'shop_customer' => &$customer];
                                        Hook::update($eventName, $eventData);
                                        check_generate_customer_address($customer);
                                        $login_error = false;
                                        //delete_false_password_login_counter(md5(getUserIP()), md5($loginDataUsed));
                                        oci_start();
                                    }
                                } else {
                                    $eventName = 'afterUnsuccessfulLogin';
                                    $eventData = ['input_login' => &$input_login, 'input_email' => &$input_email, 'input_password' => &$input_password];
                                    Hook::update($eventName, $eventData);
                                    $login_error = true;
                                }
                            } else {
                                $login_error = true;
                            }


                        } else {
                            die('OCI CALL PASSWORD FAIL');
                        }
                    } else {
                        die('OCI CALL USERNAME FAIL');
                    }
                } else {
                    $login_error = true;
                }
            }
            if (isset($login_error) && $login_error) {

                //add_false_password_loign_counter(md5(getUserIP()), md5($loginDataUsed), 1, time());

                $_POST['login_error'] = true;

                // to keep the login data in the text box after page reload//
                $cookie_timeout = 2;
                setcookie(
                    'login_used_data',
                    $currentLoginData,
                    time() + $cookie_timeout,
                    '/'
                    , NULL, NULL, true);

                if ($_POST['input_customer_no'] <> '') {
                    setcookie(
                        'customer_used_data',
                        $_POST['input_customer_no'],
                        time() + $cookie_timeout,
                        '/'
                        , NULL, NULL, true);
                }
                //---------------------- END --------//


                $newaddress = $_SERVER['HTTP_REFERER'];
                $newaddress = str_replace(
                    array('?action=shop_login', '&action=shop_login', '?action=login', '&action=login'),
                    '',
                    $newaddress
                );
                unset($_GET['action']);
                unset($_POST['action']);
                if (substr_count($newaddress, "/") == 3 && (int)$GLOBALS["language"]["logout_site_id"] > 0) {
                    $logoutSite = site_getbyid($GLOBALS["language"]["logout_site_id"]);
                    $newaddress .= $logoutSite["code"] . "/" . $GLOBALS["language"]["code"] . "/";
                }
                if (strpos($newaddress, '?') !== false) {
                    $newaddress .= "&login_error=true";
                } else {
                    $newaddress .= "?login_error=true";
                }
                headerFunctionBridge("Location: " . $newaddress);
            }

            if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'shop_login' && isset($_POST['catalog_selected_item']) && $_POST['catalog_selected_item'] != '') {

                $item = get_item_by_id($_POST['catalog_selected_item']);
                $itemlink = create_item_link($item);
                headerFunctionBridge('Location: //' . $_SERVER['SERVER_NAME'] . $itemlink);
            }

            if (!empty($_POST["redirect_url"])) {
                $newaddress = "//" . $_SERVER["SERVER_NAME"] . $_POST["redirect_url"];
                if (isset($login_error) && $login_error) {
                    if (strpos($newaddress, '?') !== false) {
                        $newaddress .= "&login_error=true";
                    } else {
                        $newaddress .= "?login_error=true";
                    }
                }
                universal_redirect($newaddress);
            }

            break;
    }
    if ((bool)$GLOBALS['site']['login_required'] === true && (bool)$GLOBALS['visitor']['frontend_login'] === false) {

        $loginSiteId = $GLOBALS["language"]["logout_site_id"];
        $loginLangaugeId = $GLOBALS["language"]["logout_language_id"];
        $redirectSite = get_site_from_site_id($loginSiteId);
        $redirectLanguage = language_getbyid($loginLangaugeId);
		$parameter = "/";
		if ($isLoginAction) {
			$parameter = "/?login_error=true";
		}
		universal_redirect("//" . $_SERVER["SERVER_NAME"] . ($_SERVER['SERVER_PORT'] !== '' ? ':' . $_SERVER['SERVER_PORT'] : '') . '/' . customizeUrl(true, $redirectSite, $redirectLanguage) . $parameter);

    }

}

function check_generate_customer_address($customer) {
    $company = $customer['company'];
    $customerNo = $customer['customer_no'];
    $pdo = \DynCom\dc\dcShop\classes\DC::getInstance()->getPDO();
    $pdo->setQuery("SELECT * FROM shop_customer_address WHERE company = '$company' AND customer_no = '$customerNo'")->doQuery();
    $res = $pdo->getResultArray(PDO::FETCH_ASSOC);
    if(\DynCom\Compat\Compat::count($res) == 0) {

        $name = $customer['name'];
        $address = $customer['address'];
        $postCode = $customer['post_code'];
        $city = $customer['city'];
        $country = $customer['country'];
        $pdo->setQuery("INSERT INTO shop_customer_address SET 
                                      company = '$company', 
                                      customer_no = '$customerNo', 
                                      name = '$name', 
                                      address = '$address', 
                                      post_code = '$postCode', 
                                      city = '$city', 
                                      salutation = 2,
                                      country = '$country' ")->doQuery();
    }
}

function get_shop_user($visitor)
{
    if ($visitor["frontend_login"]) {
        if ($GLOBALS['shop']['shop_typ'] != 2) {
            $query = "SELECT shop_user.*
					  FROM main_visitor
					  LEFT JOIN shop_user ON shop_user.id = main_visitor.main_user_id
					  WHERE main_visitor.id = '" . $visitor["id"] . "'";
        } else {
            $query = "SELECT shop_salesperson.*
					  FROM main_visitor
					  LEFT JOIN shop_salesperson ON shop_salesperson.id = main_visitor.main_user_id
					  WHERE main_visitor.id = '" . $visitor["id"] . "'";
        }
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
            $shop_user = mysqli_fetch_array($result);
            return $shop_user;
        }
    }
}

function get_shop_customer($shop_user)
{
    if ($shop_user["customer_no"] <> '') {
        $query = "SELECT *
				  FROM shop_customer
				  WHERE customer_no = '" . $shop_user["customer_no"] . "'
				  AND shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
				  AND company = '" . $GLOBALS['shop']['company'] . "'
				  LIMIT 1";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
            $shop_customer = mysqli_fetch_array($result);
            return $shop_customer;
        }
    }
}

function shipment_address_select(
    $customer,
    $name,
    $selectname = "input_shipment_address_id",
    $value = null,
    $disabled = false,
    $autoupdate = false
)
{
    $disabled_text = ($disabled) ? " disabled=\"disabled\"" : "";
    $autoupdate_text = ($autoupdate) ? " onchange=\"this.form.submit();\"" : "";
    echo "<div class=\"form-group\"><label for=\"" . $selectname . "\">" . $name . "</label>";
    echo "<div class=\"select_body\"><select" . $disabled_text . $autoupdate_text . " class=\"select_2\" name=\"" . $selectname . "\" id=\"" . $selectname . "\">";
    /* if (!empty($GLOBALS['shop_customer']["name_2"])) {
         echo "<option value=\"0\">" . $GLOBALS['shop_customer']["name"] . " - " . $GLOBALS['shop_customer']["name_2"] . " - " . $GLOBALS['shop_customer']["address"] . " " . $GLOBALS['shop_customer']["address_2"] . ", " . $GLOBALS['shop_customer']["post_code"] . " " . $GLOBALS['shop_customer']["city"] . "</option>";
     } else{
         echo "<option value=\"0\">" . $GLOBALS['shop_customer']["name"] . " - " . $GLOBALS['shop_customer']["address"] . " " . $GLOBALS['shop_customer']["address_2"] . ", " . $GLOBALS['shop_customer']["post_code"] . " " . $GLOBALS['shop_customer']["city"] . "</option>";
     }*/

    $result = mysqli_query(
        $GLOBALS['mysql_con'],
        "SELECT *
						   FROM shop_shipment_address
						   WHERE customer_no = '" . $customer["customer_no"] . "'
						   	AND company = '" . $GLOBALS['shop']['company'] . "'"
    );
    $defaultSelected = false;
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        while ($shipment_address = mysqli_fetch_array($result)) {
            $defaultAttribute = '';
            if ($shipment_address['code'] === 'DEFAULT') {
                $defaultAttribute = ' data-default=\'1\'';
            }
            if (!empty($shipment_address["name_2"])) {
                $description = $shipment_address["name"] . " - " . $shipment_address["name_2"] . " - " . $shipment_address["address"] . " " . $shipment_address["address_2"] . ", " . $shipment_address['post_code'] . " " . $shipment_address["city"];
            } else {
                $description = $shipment_address["name"] . " - " . $shipment_address["address"] . " " . $shipment_address["address_2"] . ", " . $shipment_address['post_code'] . " " . $shipment_address["city"];
            }
            if ($shipment_address["id"] == $value) {
                echo "<option" . $defaultAttribute . " selected=\"selected\" value=\"" . $shipment_address["id"] . "\">" . $description . "</option>";
                if ($shipment_address['code'] === 'DEFAULT') {
                    $defaultSelected = true;
                }
            } else {
                echo "<option" . $defaultAttribute . " value=\"" . $shipment_address["id"] . "\">" . $description . "</option>";
            }
        }
    }
    echo "</select></div></div>";

    return $defaultSelected;
}

function shipment_address_select_new(
    $customer,
    $name,
    $selectname = "input_shipment_address_id",
    $value = null,
    $disabled = false,
    $autoupdate = false
)
{
    $disabled_text = ($disabled) ? " disabled=\"disabled\"" : "";
    $autoupdate_text = ($autoupdate) ? " onchange=\"this.form.submit();\"" : "";
    echo "<div class=\"form-group\"><label for=\"" . $selectname . "\">" . $name . "</label>";
    echo "<div class=\"select_body\"><select" . $disabled_text . $autoupdate_text . " class=\"select_2\" name=\"" . $selectname . "\" id=\"" . $selectname . "\">";
    /* if (!empty($GLOBALS['shop_customer']["name_2"])) {
         echo "<option value=\"0\">" . $GLOBALS['shop_customer']["name"] . " - " . $GLOBALS['shop_customer']["name_2"] . " - " . $GLOBALS['shop_customer']["address"] . " " . $GLOBALS['shop_customer']["address_2"] . ", " . $GLOBALS['shop_customer']["post_code"] . " " . $GLOBALS['shop_customer']["city"] . "</option>";
     } else{
         echo "<option value=\"0\">" . $GLOBALS['shop_customer']["name"] . " - " . $GLOBALS['shop_customer']["address"] . " " . $GLOBALS['shop_customer']["address_2"] . ", " . $GLOBALS['shop_customer']["post_code"] . " " . $GLOBALS['shop_customer']["city"] . "</option>";
     }*/

    $result = mysqli_query(
        $GLOBALS['mysql_con'],
        "SELECT *
						   FROM shop_customer_address
						   WHERE customer_no = '" . $customer["customer_no"] . "'
						   	AND company = '" . $GLOBALS['shop']['company'] . "'"
    );
    $defaultSelected = false;
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        while ($shipment_address = mysqli_fetch_array($result)) {
            $defaultAttribute = '';
            if ($shipment_address['code'] === 'DEFAULT') {
                $defaultAttribute = ' data-default=\'1\'';
            }
            if (!empty($shipment_address["name_2"])) {
                $description = $shipment_address["name"] . " - " . $shipment_address["name_2"] . " - " . $shipment_address["address"] . " " . $shipment_address["address_2"] . ", " . $shipment_address['post_code'] . " " . $shipment_address["city"];
            } else {
                $description = $shipment_address["name"] . " - " . $shipment_address["address"] . " " . $shipment_address["address_2"] . ", " . $shipment_address['post_code'] . " " . $shipment_address["city"];
            }
            if ($shipment_address["id"] == $value) {
                echo "<option" . $defaultAttribute . " selected=\"selected\" value=\"" . $shipment_address["id"] . "\">" . $description . "</option>";
                if ($shipment_address['code'] === 'DEFAULT') {
                    $defaultSelected = true;
                }
            } else {
                echo "<option" . $defaultAttribute . " value=\"" . $shipment_address["id"] . "\">" . $description . "</option>";
            }
        }
    }
    echo "</select></div></div>";

    return $defaultSelected;
}

function get_invoice_discount($invoice_discount_code, $order_amount)
{
    $query = "SELECT *
			  FROM shop_invoice_discount
			  WHERE company = '" . $GLOBALS["shop"]["company"] . "'
			  	AND invoice_discount_code = '" . $invoice_discount_code . "'
			  	AND minimum_amount <= '" . $order_amount . "'
			  ORDER BY minimum_amount DESC
			  LIMIT 1";
    $result = @mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        $invoice_discout = @mysqli_fetch_array($result);
        $GLOBALS['invoice_discount_id'] = $invoice_discout['id'];
        return $invoice_discout["discount"];
    } else {
        return 0;
    }
}

function format_address(
    $name,
    $name_2,
    $address,
    $address_2,
    $post_code,
    $city,
    $country,
    $contact = '',
    $salutation = '',
    $isB2BAddress = false
)
{
    if($isB2BAddress) {
        echo ($name <> '') ? $name . "<br />\n" : "";
        if($name_2 <> ''){
            echo ($salutation <> '') ? $salutation . "" : "";
            echo (($name_2 <> '') && ($name_2 <> $name)) ? $name_2 . "<br />\n" : "";
        }
    } else {
        echo ($salutation <> '') ? $salutation . "<br />\n" : "";
        echo ($name <> '') ? $name . "<br />\n" : "";
        echo (($name_2 <> '') && ($name_2 <> $name)) ? $name_2 . "<br />\n" : "";
    }

    echo ((($contact <> $name_2) && ($contact <> $name)) && $contact <> "") ? $contact . "<br />\n" : "";
    echo ($address <> '') ? $address . "<br />\n" : "";
    echo ($address_2 <> '') ? $address_2 . "<br />\n" : "";
    echo ($post_code) ? $post_code . " " . $city . "<br />\n" : "";
    echo ($country <> '') ? get_country_by_code($country) . "<br />\n" : "";
}

function format_address_with_phone(
    $name,
    $name_2,
    $address,
    $address_2,
    $post_code,
    $city,
    $country,
    $contact = '',
    $salutation = '',
    $telephone = '',
    $is_company = false,
    $vat_id = ''
)
{
    if ($_SESSION["input_is_company"] == 'on') {
        echo ($name <> '') ? $name . "<br />\n" : "";
        echo ($salutation <> '') ? $salutation . " " : "";
        echo (($name_2 <> '') && ($name_2 <> $name)) ? $name_2 . "<br />\n" : "";
    } else {
        echo ($salutation <> '') ? $salutation . " " : "";
        echo ($name <> '') ? $name . "<br />\n" : "";
        echo (($name_2 <> '') && ($name_2 <> $name)) ? $name_2 . "<br />\n" : "";
    }
    echo ((($contact <> $name_2) && ($contact <> $name)) && $contact != "") ? $contact . "<br />\n" : "";
    echo ($address <> '') ? $address . "<br />\n" : "";
    echo ($address_2 <> '') ? $address_2 . "<br />\n" : "";
    echo ($post_code) ? $post_code . " " . $city . "<br />\n" : "";
    echo ($country <> '') ? get_country_by_code($country) . "<br />\n" : "";
    echo ($telephone <> '') ? $telephone . "<br />\n" : "";
    if ($_SESSION["input_is_company"] == 'on') {
        echo ($vat_id <> '') ? $vat_id : "";
    }
}

function get_text_module($company, $text_module_code, $spacer = array(), $description = false, $addHeaderAndFooter = false, $emailHeaderTextModuleCode = '', $emailFooterTextModuleCode = '')
{
    if ($text_module_code <> '') {
        if ($addHeaderAndFooter && $emailHeaderTextModuleCode !== '' && $emailFooterTextModuleCode !== '') {
            $query = "SELECT content FROM shop_text_module WHERE company = '" . $company . "' AND code = '" . $emailHeaderTextModuleCode . "'";
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            $header = mysqli_fetch_array($result);
            $query = "SELECT content, description FROM shop_text_module WHERE company = '" . $company . "' AND code = '" . $emailFooterTextModuleCode . "'";
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            $footer = mysqli_fetch_array($result);
        }
        $query = "SELECT content, description FROM shop_text_module WHERE company = '" . $company . "' AND code = '" . $text_module_code . "'";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
            $text_module = mysqli_fetch_array($result);
            $text = '';
            if ($description) {
                $text = $text_module["description"];
            } else {
                if (isset($header["content"])) {
                    $text = $header["content"];
                }
                $text .= $text_module["content"];

                if (isset($footer["content"])) {
                    $text .= $footer["content"];
                }
            }
            if (\DynCom\Compat\Compat::count($spacer) > 0) {
                foreach ($spacer as $key => $value) {
                    $text = str_replace($key, $value, $text);
                }
            }
            return $text;
        } else {
            echo "Fehler in Funktion 'get_text_module' \n\nquery: " . $query . " \n\n";
        }
    } else {
        echo "Fehler in Funktion 'get_text_module' \n\nTextmodul Code " . $text_module_code . " ist leer\n\n";
    }
}


function password_reminder()
{
    $_POST = secure_array($_POST);
    if (empty($GLOBALS['shop']['company']) && !empty($GLOBALS['shop_primary']['company'])) {
        $GLOBALS['shop']['company'] = $GLOBALS['shop_primary']['company'];
    }

    if (empty($GLOBALS['shop']['code']) && !empty($GLOBALS['shop_primary']['code'])) {
        $GLOBALS['shop']['code'] = $GLOBALS['shop_primary']['code'];
    }

    if (empty($GLOBALS['shop']['language_code']) && !empty($GLOBALS['shop_primary']['language_code'])) {
        $GLOBALS['shop']['language_code'] = $GLOBALS['shop_primary']['language_code'];
    }
    $mail_send = false;

    $showError = false;
    switch ($GLOBALS['shop']['login_type']) {
        case 0: //E-Mail & Passwort
            if (!isset($_POST['input_email']) || $_POST['input_email'] == "") {
                $showError = true;
            }
            $countquery = " SELECT * FROM shop_user WHERE company= '" . $GLOBALS['shop']['company'] . "' and shop_code= '" . $GLOBALS['shop']['customer_source'] . "' and  email = '" . $_POST['input_email'] . "'";
            break;
        case 1: //Login & Passwort
            if (!isset($_POST['input_login']) || $_POST['input_login'] == "") {
                $showError = true;
            }
            $countquery = " SELECT * FROM shop_user WHERE company= '" . $GLOBALS['shop']['company'] . "' and shop_code= '" . $GLOBALS['shop']['customer_source'] . "' and  login = '" . $_POST['input_login'] . "'";
            break;
        case 2: //Kunden-Nr., Login & Passwort
            if (!isset($_POST['input_customer_no']) || $_POST['input_customer_no'] == "" || !isset($_POST['input_login']) || $_POST['input_login'] == "") {
                $showError = true;
            }
            $countquery = "SELECT * FROM shop_user WHERE   company= '" . $GLOBALS['shop']['company'] . "' and shop_code= '" . $GLOBALS['shop']['customer_source'] . "' and  customer_no = '" . $_POST["input_customer_no"] . "' AND login='" . $_POST['input_login'] . "'";
            break;
        case 3: //Kunden-Nr., E-Mail & Passwort
            if (!isset($_POST['input_customer_no']) || $_POST['input_customer_no'] == "" || !isset($_POST['input_email']) || $_POST['input_email'] == "") {
                $showError = true;
            }
            $countquery = "SELECT * FROM shop_user WHERE   company= '" . $GLOBALS['shop']['company'] . "' and shop_code= '" . $GLOBALS['shop']['customer_source'] . "' and  customer_no = '" . $_POST["input_customer_no"] . "' AND email='" . $_POST['input_email'] . "'";
            break;
        case 4: //Kunden-Nr. & Passwort
            if (!isset($_POST['input_customer_no']) || $_POST['input_customer_no'] == "") {
                $showError = true;
            }
            $countquery = "SELECT * FROM shop_user WHERE   company= '" . $GLOBALS['shop']['company'] . "' and shop_code= '" . $GLOBALS['shop']['customer_source'] . "' and  customer_no = '" . $_POST["input_customer_no"] . "'";
            break;
        default:
            $showError = true;
            break;
    }
    if ($showError !== true) {

        $result = mysqli_query($GLOBALS['mysql_con'], $countquery);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
            $user = mysqli_fetch_assoc($result);

            $hashString = $GLOBALS['shop']['company'] . $GLOBALS['shop']['customer_source'] . $user['email'] . rand() . getenv('SHOP_PASSWORD') . '@!#$%^&*' . time();
            $hashString = hash('sha256', md5(md5($hashString)));

            $pdoHost = getenv('MAIN_MYSQL_DB_HOST');
            $pdoPort = getenv('MAIN_MYSQL_DB_PORT');
            $pdoUser = getenv('MAIN_MYSQL_DB_USER');
            $pdoPass = getenv('MAIN_MYSQL_DB_PASS');
            $pdoSchema = getenv('MAIN_MYSQL_DB_SCHEMA');

            $pdo = new \DynCom\dc\common\classes\PDOQueryWrapper($pdoHost, $pdoPort, $pdoSchema, $pdoUser, $pdoPass);

            $prepStatement = " 
            
            INSERT INTO `user_new_password_request`
                (`company`,`shop_code`,`user_email`, `random_hash`, `create_date`)
                VALUES 
                (:company, :shop_code, :email, :randomHash, :createDate)
                ON DUPLICATE KEY UPDATE random_hash = :randomHash , create_date = :createDate

                        ";
            $params = [
                [':company', $GLOBALS['shop']['company'], PDO::PARAM_STR],
                [':shop_code', $GLOBALS['shop']['customer_source'], PDO::PARAM_STR],
                [':email', $user['email'], PDO::PARAM_STR],
                [':randomHash', $hashString, PDO::PARAM_STR],
                [':createDate', date('Y-m-d H:i:s'), PDO::PARAM_STR],
                [':usedHash', 0, PDO::PARAM_STR],
            ];
            $pdo->setQuery($prepStatement);
            $pdo->prepareQuery();
            $pdo->bindParameters($params);
            if ($pdo->executePreparedStatement()) {
                $prepStatement = " 
            
           SELECT main_navigation.*  
                FROM main_navigation
                    inner join main_page on  main_navigation.forward_page_id = main_page.id 
                        and main_navigation.main_language_id = main_page.main_language_id
                         and main_navigation.main_language_id = :langaugeId
                    inner join main_page_link on main_page.id = main_page_link.main_page_id  and main_page.main_language_id = :langaugeId and main_page_link.main_sitepart_id = ". Siteparts::SITEPART_SHOP ."
                    inner  join main_shop_sitepart on  main_page_link.main_sitepart_header_id  = main_shop_sitepart.id and main_shop_sitepart.type = 7 
                        ";
                $params = [
                    [':langaugeId', $GLOBALS["language"]['id'], PDO::PARAM_STR],
                ];
                $pdo->setQuery($prepStatement);
                $pdo->prepareQuery();
                $pdo->bindParameters($params);
                $pdo->executePreparedStatement();
                $navigation = $pdo->getResultArray();

                $navigationLink = current_site_navigation_path($GLOBALS["site"], $GLOBALS["language"], $navigation[0]);
                $protocol = stripos($_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';
                $navigationLink = $protocol . $_SERVER['SERVER_NAME'] . $navigationLink . '?id=' . $hashString;
                $spacer['%email%'] = $user['email'];
                $spacer['%link%'] = $navigationLink;
                $spacer['%here%'] = "<a href='" . $navigationLink . "'>" . $GLOBALS['tc']['here'] . "</a>";
                if ($GLOBALS["shop_language"]["email_password_text_module"] <> '') {
                    $message = get_text_module(
                        $GLOBALS['shop']['company'], $GLOBALS["shop_language"]["email_password_text_module"], $spacer, false, true, $GLOBALS["shop_language"]['email_header'], $GLOBALS["shop_language"]['email_footer']
                    );
                    $subject = get_text_module(
                        $GLOBALS['shop']['company'], $GLOBALS["shop_language"]["email_password_text_module"], $spacer, true, false
                    );
                    if (mail_create(
                        $subject, $message, $GLOBALS["shop"]["email_sender"], $user["email"], "", "", true, 0, ''
                    )) {
                        mail_send();
                        $mail_send = true;
                    }
                }
            }

        }
        return $mail_send;
    } else {
        get_requestbox($GLOBALS["tc"]["login_error"], "", "error");
    }


}

function show_order_sum(
    $subtotal,
    $online_discount,
    $online_discount_amount,
    $invoice_discount,
    $invoice_discount_amount,
    $small_quantity_charge_amount,
    $total,
    $shipping_cost = 0,
    $payment_cost = 0,
    $show_vat = false,
    $sales_line_result = null,
    $rule_disc_percent = 0.00,
    $rule_disc_amount = 0.00,
    $html_entities = false
)
{

    if ($_SESSION["coupon"]['coupon_discount_amount'] <> 0) {
        //$total -= $_SESSION["coupon"]['coupon_discount_amount'];
    }

    ?>

    <table class="order_sum" style="width:100%">
        <? if ($subtotal <> $total) { ?>
            <tr>
                <td class="order_sum_1"><?= $GLOBALS["tc"]["order_total"] ?></td>

                <td class="order_sum_2" style="text-align:right">
                    <?= format_amount($subtotal, false, false, $html_entities) ?>
                </td>

            </tr>
        <? }
        if ($rule_disc_amount <> 0) { ?>
            <tr>
                <td class="order_sum_1"><?= $GLOBALS["tc"]["discount"] . ' ' . round($rule_disc_percent, 2) . '%' ?></td>
                <td class="order_sum_2" style="text-align:right">- <?= format_amount($rule_disc_amount, false, false, $html_entities) ?></td>
            </tr>
        <? }
        if ($small_quantity_charge_amount <> 0) { ?>
            <tr>
                <td class="order_sum_1"><?= $GLOBALS["tc"]["small_quantity_charge"] ?></td>
                <td class="order_sum_2" style="text-align:right">+ <?= format_amount($small_quantity_charge_amount, false, false, $html_entities) ?></td>
            </tr>
            <?php
        }
        if ($shipping_cost <> 0) {
            if(isUserShowNet()) {
                //Aufschläge
                $markup = $payment_cost + $small_quantity_charge_amount;
                //Rabatte zusammenrechnen für Rechnung
                $discount = $online_discount_amount + $invoice_discount_amount + $_SESSION["coupon"]['coupon_discount_amount'] + $_SESSION['coupon']['amnt_disc_non_items'];
                //Rabatte ohne Coupon
                $discount_wo_coupon = $online_discount_amount + $invoice_discount_amount;


                //MB --- OOP ---
                if (!isset($currUserBasket) || !($currUserBasket instanceof UserBasket)) {
                    if (!isset($IOCContainer)) {
                        $IOCContainer = $GLOBALS['IOC'];
                    }
                    $currUserBasket = $IOCContainer->create('$CurrUserBasket');
                }
                set_vat_lines(
                    $GLOBALS['visitor']['id'],
                    $GLOBALS["shop"]["vat_bus_posting_group"],
                    $_SESSION['coupon'],
                    $discount_wo_coupon,
                    $markup,
                    $shipping_cost,
                    $currUserBasket,
                    $online_discount_amount
                );
                foreach ($GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']] as  $vatPercentageKey => $vat) {
                    $markup_shipping = $shipping_cost * 100 / (100 + $vat['vat_percent']);
                    if($markup_shipping > 0) {
                        $shipping_cost = $markup_shipping;
                    }
                }
            }

            ?>
            <tr>
                <td class="order_sum_1"><?= $GLOBALS["tc"]["shipping_cost"] ?></td>
                <td class="order_sum_2" style="text-align:right">+ <?= format_amount($shipping_cost, false, false, $html_entities) ?></td>
            </tr>
        <? }
        if ($payment_cost <> 0) { ?>
            <tr>
                <td class="order_sum_1"><?= $GLOBALS["tc"]["payment_cost"] ?></td>
                <td class="order_sum_2" style="text-align:right">+ <?= format_amount($payment_cost, false, false, $html_entities) ?></td>
            </tr>
        <? }
        if ($online_discount_amount <> 0) { ?>
            <tr>
                <td class="order_sum_1"><?= round($online_discount) ?>% <?= $GLOBALS["tc"]["online_discount"] ?></td>
                <td class="order_sum_2" style="text-align:right">- <?= format_amount($online_discount_amount, false, false, $html_entities) ?></td>
            </tr>
        <? }
        if ($invoice_discount_amount <> 0) { ?>
            <tr>
                <td class="order_sum_1"><?= round($invoice_discount) ?>% <?= $GLOBALS["tc"]["invoice_discount"] ?></td>
                <td class="order_sum_2" style="text-align:right">- <?= format_amount($invoice_discount_amount, false, false, $html_entities) ?></td>
            </tr>
        <? }
        if ($_SESSION["coupon"]['coupon_discount_amount'] <> 0) { ?>
            <tr>
                <td class="order_sum_1"><?= $GLOBALS["tc"]["coupon_discount"] ?></td>
                <td class="order_sum_2" style="text-align:right">-<?= format_amount($_SESSION["coupon"]['coupon_discount_amount'], false, false, $html_entities) ?></td>
            </tr>
        <? } ?>
        <?
            $showNetSum = false;
            $orderPriceTotalLabel = $GLOBALS["tc"]["total_amount"];
            $taxLbl = $GLOBALS['tc']['excl_tax'];
            if(isUserShowNet()) {
                //US Sales Tax---
                if ($show_vat == 1 && $sales_line_result != null && (!(isset($GLOBALS['us_sales_tax_breakdown']) || isset($GLOBALS['us_sales_tax_estimate'])))) {


                    //MB +++ OOP +++

                    $basket_vat_groups = get_basket_vat_groups($GLOBALS['visitor']['id']);

                    if (isset($GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][0])) {
                        for ($i = 0; $i < \DynCom\Compat\Compat::count($GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']]); $i++) {
                            if (is_array($basket_vat_groups) && in_array(
                                    $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_group'],
                                    $basket_vat_groups
                                )
                            ) {
                                $totalPrice += $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_amount'];
                                echo("<tr>");
                                echo("<td class=\"order_sum_1\">" . $taxLbl . " " . round(
                                        $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_percent']
                                    ) . "%</td>");
                                echo("<td class=\"order_sum_2\" style=\"text-align:right\">+" . format_amount(
                                        $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_amount'],
                                        false, false, $html_entities
                                    ) . "</td>");
                                echo("</tr>");
                            }
                        }
                    }

                } elseif ($show_vat && (isset($GLOBALS['us_sales_tax_breakdown']) || isset($GLOBALS['us_sales_tax_estimate']))) {
                    if (isset($GLOBALS['us_sales_tax_estimate'])) {
                        $estimate = $GLOBALS['us_sales_tax_estimate'];
                        $breakdown = $estimate->getBreakdown();
                    } else {
                        $breakdown = $GLOBALS['us_sales_tax_breakdown'];
                    }


                    /**
                     * @var $breakdown \DynCom\dc\dcShop\USSalesTax\TaxJar\TaxBreakdown
                     */

                    if ($breakdown->getStateTaxCollectable() > 0) { ?>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['state_taxable_amount'] ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getStateTaxableAmount(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['state_tax'] . ' ' . ($breakdown->getStateTaxRate() * 100) . ' %' ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getStateTaxCollectable(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>
                    <?php }
                    if ($breakdown->getCountyTaxableAmount() > 0) { ?>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['county_taxable_amount'] ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getCountyTaxableAmount(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['county_tax'] . ' ' . ($breakdown->getCountyTaxRate() * 100) . ' %' ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getCountyTaxCollectable(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>

                    <?php }
                    if ($breakdown->getCityTaxCollectable() > 0) { ?>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['city_taxable_amount'] ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getCityTaxableAmount(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['city_tax'] . ' ' . ($breakdown->getCityTaxRate() * 100) . ' %' ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getCityTaxCollectable(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>
                    <?php }
                    if ($breakdown->getSpecialDistrictTaxCollectable() > 0) { ?>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['special_district_taxable_amount'] ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getSpecialDistrictTaxableAmount(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>
                        <tr>
                            <td class="order_sum_1"><?= $GLOBALS['tc']['special_district_tax'] . ' ' . ($breakdown->getSpecialTaxRate() * 100) . ' %' ?></td>
                            <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                    $breakdown->getSpecialDistrictTaxCollectable(),
                                    false, false, $html_entities
                                ) ?></td>
                        </tr>
                        <?
                    }
                    //US Sales Tax +++
                }
                $showNetSum = true;
                $orderPriceTotalLabel .= " (" . $GLOBALS['tc']['gross'] . ")";
//                $taxLbl = $GLOBALS['tc']['excl_tax'];
            }
            $totalPrice = $total;

        ?>
        <tr>
            <td class="order_sum_1">
                <div class="order_price_total_label"><?= $orderPriceTotalLabel ?></div>
            </td>
            <td class="order_sum_2" style="text-align:right">
                <div class="order_price_total"><?= format_amount($totalPrice, false, false, $html_entities) ?></div>
            </td>
        </tr>
        <?
        if(!isUserShowNet()) {

            //US Sales Tax---
            if ($show_vat == 1 && $sales_line_result != null && (!(isset($GLOBALS['us_sales_tax_breakdown']) || isset($GLOBALS['us_sales_tax_estimate'])))) {

                //Aufschläge
                $markup = $payment_cost + $small_quantity_charge_amount;
                //Rabatte zusammenrechnen für Rechnung
                $discount = $online_discount_amount + $invoice_discount_amount + $_SESSION["coupon"]['coupon_discount_amount'] + $_SESSION['coupon']['amnt_disc_non_items'];
                //Rabatte ohne Coupon
                $discount_wo_coupon = $online_discount_amount + $invoice_discount_amount;


                //MB --- OOP ---
                if (!isset($currUserBasket) || !($currUserBasket instanceof UserBasket)) {
                    if (!isset($IOCContainer)) {
                        $IOCContainer = $GLOBALS['IOC'];
                    }
                    $currUserBasket = $IOCContainer->create('$CurrUserBasket');
                }
                set_vat_lines(
                    $GLOBALS['visitor']['id'],
                    $GLOBALS["shop"]["vat_bus_posting_group"],
                    $_SESSION['coupon'],
                    $discount_wo_coupon,
                    $markup,
                    $shipping_cost,
                    $currUserBasket,
                    $online_discount_amount
                );
                //MB +++ OOP +++

                $basket_vat_groups = get_basket_vat_groups($GLOBALS['visitor']['id']);
                $taxLbl = $GLOBALS["tc"]["incl_tax"];

                if (isset($GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][0])) {
                    for ($i = 0; $i < \DynCom\Compat\Compat::count($GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']]); $i++) {
                        if (is_array($basket_vat_groups) && in_array(
                                $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_group'],
                                $basket_vat_groups
                            )
                        ) {
                            $totalPrice += $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_amount'];
                            echo("<tr>");
                            echo("<td class=\"order_sum_1\">" . $taxLbl . " " . round(
                                    $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_percent']
                                ) . "%</td>");
                            echo("<td class=\"order_sum_2\" style=\"text-align:right\">" . format_amount(
                                    $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_amount'],
                                    false, false, $html_entities
                                ) . "</td>");
                            echo("</tr>");
                        }
                    }
                }

            } elseif ($show_vat && (isset($GLOBALS['us_sales_tax_breakdown']) || isset($GLOBALS['us_sales_tax_estimate']))) {
                if (isset($GLOBALS['us_sales_tax_estimate'])) {
                    $estimate = $GLOBALS['us_sales_tax_estimate'];
                    $breakdown = $estimate->getBreakdown();
                } else {
                    $breakdown = $GLOBALS['us_sales_tax_breakdown'];
                }


                /**
                 * @var $breakdown \DynCom\dc\dcShop\USSalesTax\TaxJar\TaxBreakdown
                 */

                if ($breakdown->getStateTaxCollectable() > 0) { ?>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['state_taxable_amount'] ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getStateTaxableAmount(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['state_tax'] . ' ' . ($breakdown->getStateTaxRate() * 100) . ' %' ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getStateTaxCollectable(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>
                <?php }
                if ($breakdown->getCountyTaxableAmount() > 0) { ?>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['county_taxable_amount'] ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getCountyTaxableAmount(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['county_tax'] . ' ' . ($breakdown->getCountyTaxRate() * 100) . ' %' ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getCountyTaxCollectable(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>

                <?php }
                if ($breakdown->getCityTaxCollectable() > 0) { ?>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['city_taxable_amount'] ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getCityTaxableAmount(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['city_tax'] . ' ' . ($breakdown->getCityTaxRate() * 100) . ' %' ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getCityTaxCollectable(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>
                <?php }
                if ($breakdown->getSpecialDistrictTaxCollectable() > 0) { ?>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['special_district_taxable_amount'] ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getSpecialDistrictTaxableAmount(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>
                    <tr>
                        <td class="order_sum_1"><?= $GLOBALS['tc']['special_district_tax'] . ' ' . ($breakdown->getSpecialTaxRate() * 100) . ' %' ?></td>
                        <td class="order_sum_2" style="text-align:right"><?= format_amount(
                                $breakdown->getSpecialDistrictTaxCollectable(),
                                false, false, $html_entities
                            ) ?></td>
                    </tr>
                    <?
                }
                //US Sales Tax +++
            }
        }
        ?>
    </table>
    <?


}

//Funktion mit MwSt-Satz auf Versand- und Zahlungskosten aus der Hauptleistung(höchster Wertanteil der Bestellung), anteilige Funktion siehe unten
function show_vat($sales_line_result, $shipping_cost = 0, $payment_cost = 0)
{

    //Array für MwSt-Beträge pro Steuersatz
    $vat_array = array();
    //Array für zu versteuernden Warenwert pro Steuersatz
    $totalarray = array();
    //Array mit Beträgen für einzelne Steuersätze befüllen
    while ($line = mysqli_fetch_assoc($sales_line_result)) {
        $query = "SELECT vat_percent
				  FROM shop_vat_posting_setup
				  WHERE company = '" . $GLOBALS['shop']['company'] . "'
				  	AND vat_bus_posting_group = '" . $GLOBALS['shop']['vat_bus_posting_group'] . "'
				  	AND vat_prod_posting_group = '" . $line['vat_prod_posting_group'] . "'";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            if ($row['vat_percent'] > 0) {
                $vat_array[floor(
                    $row['vat_percent']
                )] += (($line['basket_quantity'] * $line['customer_price']) / (100 + $row['vat_percent'])) * $row['vat_percent'];
                $totalarray[floor($row['vat_percent'])] += $line['basket_quantity'] * $line['customer_price'];
                $total_for_vat += $line['basket_quantity'] * $line['customer_price'];
            }
        }
    }

    //MwSt für Versand- und Zahlungskosten berechnen
    $high_amount = 0;
    $ship_vat = 0;
    foreach ($totalarray AS $key => $value) {
        if ($value > $high_amount) {
            $high_amount = $value;
            $ship_vat = $key;
        }
    }
    $vat_array[$ship_vat] += (($shipping_cost + $payment_cost) / (100 + $ship_vat)) * $ship_vat;
    //MwSt ausgeben
    echo "<table class=\"vat_table\" style=\"width: 100%\">";
    foreach ($vat_array AS $key => $value) {
        echo("<tr>");
        echo("<td class=\"order_sum_1\">" . $GLOBALS["tc"]["incl_tax"] . " " . $key . "%</td>");
        echo("<td class=\"order_sum_2\" style='text-align:right'>" . format_amount($value, false) . "</td>");
        echo("</tr>");
    }
    echo "</table>";
}

//Funktion mit Verteilung der MwSt für Versand- und Zahlunskosten anteilig auf die einzelnen Steuersätze!
/*
function show_vat($result,$shipping_cost=0,$payment_cost=0)
{
	//Array für MwSt-Beträge pro Steuersatz
	$vat_array = array();
	//Array für zu versteuernden Warenwert pro Steuersatz
	$totalarray = array();
	//zu versteuernder Gesamtbetrag
	$total_for_vat = 0;
	//Array mit Beträgen für einzelne Steuersätze befüllen
	while($line = mysqli_fetch_assoc($result))
	{

		$query = "SELECT vat_percent
				  FROM shop_vat_posting_setup
				  WHERE company = '".$GLOBALS['shop']['company']."'
				  	AND vat_bus_posting_group = '".$GLOBALS['shop']['vat_bus_posting_group']."'
				  	AND vat_prod_posting_group = '".$line['vat_prod_posting_group']."'";
		$result = mysqli_query($GLOBALS['mysql_con'],$query);
		if(@mysqli_num_rows($result)>0)
		{
			$row = mysqli_fetch_assoc($result);
			if($row['vat_percent'] > 0)
			{
				$vat_array[floor($row['vat_percent'])] += (($line['basket_quantity']*$line['customer_price']) / (100+$row['vat_percent']))*$row['vat_percent'];
				$totalarray[floor($row['vat_percent'])] += $line['basket_quantity']*$line['customer_price'];
				$total_for_vat += $line['basket_quantity']*$line['customer_price'];
			}
		}
	}
	//MwSt für Versand- und Zahlungskosten berechnen
	foreach($vat_array AS $key=>$value)
	{
		//prozentualer Wertanteil für diesen Steuersatz
		$percentage = $totalarray[$key]/$total_for_vat;
		//Anteil, der mit diesem Steuersatz versteuert wird
		$vat_amount = $percentage * ($shipping_cost+$payment_cost);
		//Betrag ausrechnen und im Array addieren
		$vat_array[$key] += round(($vat_amount/(100+$key))*$key,2);
	}
	//MwSt ausgeben
	foreach($vat_array AS $key=>$value)
	{
		echo("<tr>");
		echo("<td class=\"order_sum_1\">". $GLOBALS["tc"]["incl_tax"] ." ".$key."%</td>");
    	echo("<td class=\"order_sum_2\">". format_amount($value) ."</td>");
		echo("</tr>");
	}
}
*/

function navigation_menu_shop($site, $language, $navigation, $from_level, $to_level)
{
    navigation_menu_rek_shop($site, $language, $navigation, 0, 1, "", $from_level, $to_level);
    echo "\n";
    return true;
}

function navigation_full_menu_shop($site, $language, $navigation, $from_level, $to_level)
{
    navigation_menu_rek_shop($site, $language, $navigation, 0, 1, "", $from_level, $to_level, true);
    echo "\n";
    return true;
}

function navigation_menu_rek_shop(
    $site,
    $language,
    $navigation,
    $parent_line_no,
    $level,
    $navcode,
    $from_level,
    $to_level,
    $full_menu = false
)
{

    switch ($level) {
        case 1:
            $levelcode = \DynCom\Compat\Compat::array_key_exists('slevel_1', $_GET) ? $_GET["slevel_1"] : '';
            break;
        case 2:
            $levelcode = \DynCom\Compat\Compat::array_key_exists('slevel_2', $_GET) ? $_GET["slevel_2"] : '';
            break;
        case 3:
            $levelcode = \DynCom\Compat\Compat::array_key_exists('slevel_3', $_GET) ? $_GET["slevel_3"] : '';
            break;
        case 4:
            $levelcode = \DynCom\Compat\Compat::array_key_exists('slevel_4', $_GET) ? $_GET["slevel_4"] : '';
            break;
        case 5:
            $levelcode = \DynCom\Compat\Compat::array_key_exists('slevel_5', $_GET) ? $_GET["slevel_5"] : '';
            break;
        case 6:
            $levelcode = \DynCom\Compat\Compat::array_key_exists('slevel_6', $_GET) ? $_GET["slevel_6"] : '';
            break;
    }
    $spaces = "";
    $count = 0;
    while ($count <= $level) {
        $spaces .= "";
        $count++;
    }
    $layer_no = get_layer_no();

    $showCategoryIconInNavigationMenu = $GLOBALS['shop_setup']['show_category_icon_in_top_navigation_menu'];
    $showCategoryIconInSideNavigationMenu = $GLOBALS['shop_setup']['show_category_icon_in_side_navigation_menu'];

    //Query angepasst für Berechtigungsmodul 20.12.2012 FK
    //$parent_query = ($parent_line_no == 0) ? " AND parent_line_no = 0 " : " AND parent_line_no = " . $parent_line_no;
    /*$query = "SELECT *
			  FROM shop_category
			  WHERE language_code = '" . $GLOBALS['shop_language']["code"] . "'
			  	AND company = '".$GLOBALS['shop']['company']."'
			  	AND shop_code = '".$GLOBALS['shop']['category_source']."'
			  	AND active=1" .
				$parent_query . "
			  ORDER BY sorting ASC";*/
    $parent_query = ($parent_line_no == 0) ? " AND shop_category.parent_line_no = 0 " : " AND shop_category.parent_line_no = " . $parent_line_no;
    $shop_permissions_group_link_join = '';
    $shop_permissions_group_link_where = '';
    $shop_permissions_group_link_join = 'LEFT JOIN shop_permissions_group_link ON shop_permissions_group_link.line_no = shop_category.line_no
    AND shop_permissions_group_link.shop_code = shop_category.shop_code
    AND shop_permissions_group_link.language_code = shop_category.language_code
    AND shop_permissions_group_link.company = shop_category.company
    AND shop_permissions_group_link.type <> 5
    AND shop_permissions_group_link.type <> 6
    AND shop_permissions_group_link.type <> 7
    AND shop_permissions_group_link.type <> 8';
    $shop_permissions_group_link_where = get_permissions_group_customer();
    $query = "SELECT DISTINCT shop_category.*
			  FROM shop_category
			  " . $shop_permissions_group_link_join . "
			  WHERE shop_category.language_code = '" . $GLOBALS['shop_language']["code"] . "'
			  	AND shop_category.company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_category.shop_code = '" . $GLOBALS['shop']['category_source'] . "'
			  	AND shop_category.active=1" .
        $parent_query . " " . $shop_permissions_group_link_where
        . "
			  ORDER BY shop_category.sorting ASC";


    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $show_level = (($level >= $from_level) && ($level <= $to_level)) ? true : false;
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        if ($show_level) {
            echo "" . $spaces . "<ul class=\"level_$level\">";
        }
        while ($nav = mysqli_fetch_array($result)) {

            $currDateTime = new DateTime();
            $skip = false;
            if ($nav['validity_from'] !== null && $nav['validity_from'] !== '0000-00-00' && $nav['validity_from'] !== '') {
                $validFromDateTime = DateTime::createFromFormat('Y-m-d', $nav['validity_from']);
                if ($currDateTime < $validFromDateTime) {
                    $skip = true;
                }
            }
            if ($nav['validity_to'] !== null && $nav['validity_to'] !== '0000-00-00' && $nav['validity_to'] !== '') {
                $validToDateTime = DateTime::createFromFormat('Y-m-d', $nav['validity_to']);
                if ($currDateTime > $validToDateTime) {
                    $skip = true;
                }
            }
            if ($skip) {
                continue;
            }

            if ($GLOBALS['shop_setup']['show_short_url']) {
                $newnavcode = ($nav["line_no"] == $GLOBALS["shop_language"]["dc_category_line_no"]) ? $nav["code"] . "/dc_order/" : $nav["code"] . "/";
            } else {
                $newnavcode = ($nav["line_no"] == $GLOBALS["shop_language"]["dc_category_line_no"]) ? $navcode . $nav["code"] . "/dc_order/" : $navcode . $nav["code"] . "/";

            }

            $active = "";
            $active_classname = '';
            if ($nav["code"] == $levelcode) {
                $active = "class=\"active_tree\" ";
                $active_classname = 'active_tree';
            }
            if (\DynCom\Compat\Compat::array_key_exists('slevel_' . $layer_no, $_GET) && $_GET["slevel_" . $layer_no] == $nav["code"]) {
                $active = "class=\"active\" ";
                $active_classname = 'active';
            }

            if ((int)$_GET['card'] > 0) {
                if ($_SESSION['current_item_category']['code'] <> '') {
                    $itemCategory = $_SESSION['current_item_category'];
                    if ($_SESSION['current_item_category']['code'] == $nav["code"]) {
                        $active = "class=\"active\" ";
                        $active_classname = 'active';
                    }
                } elseif ($GLOBALS['catgegory']['code'] <> '') {
                    $itemCategory = $GLOBALS['category'];
                    if ($GLOBALS['catgegory']['code'] == $nav["code"]) {
                        $active = "class=\"active\" ";
                        $active_classname = 'active';
                    }
                } else {
                    // this case happens when the card page is accessed without any request call in the website
                    $item = get_item_by_id($_GET['card']);
                    $itemCategory = get_item_default_category($item);
                    if ($itemCategory['code'] == $nav["code"]) {
                        $active = "class=\"active\" ";
                        $active_classname = 'active';
                    }
                }
                if ($itemCategory <> null && $itemCategory['code'] != $nav["code"]) {
                    $categoryTree = $itemCategory;
                    do {
                        $categoryTree = get_category_from_tree_as_array_by_line_no($GLOBALS['curr_category_tree'], $categoryTree['parent_line_no']);
                        if ($categoryTree['code'] == $nav["code"]) {
                            $active = "class=\"active_tree\" ";
                            $active_classname = 'active_tree';
                        }
                    } while ($categoryTree['id'] <> null);

                }

            }

            if ($nav['promotion_active'] && $nav['promotion_label'] == 2) {
                $active_classname.= ' promotion_sale';
            }

            if ($show_level) {

                $canonicalValue = " rel='nofollow' ";
                if (!(bool)$nav['nofollow']) {
                    $canonicalValue = '';
                }
                if ($showCategoryIconInNavigationMenu && $full_menu) // for the top menu
                {
                    $categoryIcon = $GLOBALS['projectRoot'] . "/userdata/dcshop/category_icon/" . $nav['category_icon'];

                    if ($nav['category_icon'] != '' && file_exists("../../" . $categoryIcon)) {
                        $categoryIcon = "<div class='navigation_menu_category_icon'> <img src='" . $categoryIcon . "' alt='" . $nav['name'] . "' title='" . $nav['name'] . "' /> </div>";
                        echo "" . $spaces . "<li class=\"level_$level $active_classname \"><a " . $canonicalValue . $active . "href=\"/" . customizeUrl() . "/" . $newnavcode . "\">" . $nav["name"] . $categoryIcon . "</a>";
                    } else {
                        echo "" . $spaces . "<li class=\"level_$level $active_classname \"><a " . $canonicalValue . $active . "href=\"/" . customizeUrl() . "/" . $newnavcode . "\">" . $nav["name"] . "</a>";
                    }

                } elseif ($showCategoryIconInSideNavigationMenu && !$full_menu)// for side menu
                {
                    $categoryIcon = $GLOBALS['projectRoot'] . "/userdata/dcshop/category_icon/" . $nav['category_icon'];

                    if ($nav['category_icon'] != '' && file_exists("../../" . $categoryIcon)) {
                        $categoryIcon = "<div class='navigation_menu_category_icon'> <img src='" . $categoryIcon . "'  alt='" . $nav['name'] . "' title='" . $nav['name'] . "' /> </div>";
                        echo "" . $spaces . "<li class=\"level_$level $active_classname \"><a " . $canonicalValue . $active . "href=\"/" . customizeUrl() . "/" . $newnavcode . "\">" . $nav["name"] . $categoryIcon . "</a>";
                    } else {
                        echo "" . $spaces . "<li class=\"level_$level $active_classname \"><a " . $canonicalValue . $active . "href=\"/" . customizeUrl() . "/" . $newnavcode . "\">" . $nav["name"] . "</a>";
                    }
                } else {
                    echo "" . $spaces . "<li class=\"level_$level $active_classname \"><a " . $canonicalValue . $active . "href=\"/" . customizeUrl() . "/" . $newnavcode . "\">" . $nav["name"] . "</a>";
                }


            }
            if ((($nav["code"] == $levelcode) | $full_menu) && $nav["line_no"] > 0) {
                navigation_menu_rek_shop(
                    $site,
                    $language,
                    $navigation,
                    $nav["line_no"],
                    ($level + 1),
                    $newnavcode,
                    $from_level,
                    $to_level,
                    $full_menu
                );
            }
            if ($show_level) {
                echo "</li>";
            }
        }
        if ($show_level) {
            echo "" . $spaces . "</ul>";
        }
    }
    return true;
}


function get_country_by_code($country_code)
{
    $query = "SELECT *
			  FROM shop_country
			  WHERE country_code = '" . $country_code . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'
			  	AND to_delete = 0
			  LIMIT 1";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        $val = mysqli_fetch_array($result);
        return $val["description"];
    } else {
        return false;
    }
}

function create_sort_select($name, $selectname, $select_value, $disabled = null)
{
    $category = $GLOBALS['category'];
    $isParentCategory = false;
    if(!empty(emty_category_query($category))) {
        $isParentCategory = true;
    }

    if ($name != '') {
        if (\DynCom\Compat\Compat::count($GLOBALS['category_sort_types']) > 0) {

            ?>
            <div class="form-group">
                <label for="<?=$selectname?>"><?=$GLOBALS['tc']['sort_by']?></label>
                <?
                echo "<div class=\"select_body\"><SELECT name=\"" . $selectname . "\" id=\"" . $selectname . "\" onchange=\"this.form.submit();\">";
                if ($_GET['shop_category'] == 'search' && (!empty($_REQUEST['input_search']) || !empty($_POST['input_search'])) && $_POST['sim_result']) {
                    echo "<option selected=\"selected\" value=\"relevance\">" . $GLOBALS['tc']["relevance"] . "</option>";
                }
                $i = 0;
                while ($i < \DynCom\Compat\Compat::count($GLOBALS['category_sort_types'])) {
                    $code = $GLOBALS['category_sort_types'][$i]['code'];
                    if($code == 'sorting' && $isParentCategory) {
                        $i++;
                        continue;
                    }
                    if ($GLOBALS['category_sort_types'][$i]['code'] != "") {
                        if ($GLOBALS['category_sort_types'][$i]['code'] == $select_value) {
                            echo "<option selected=\"selected\" value=\"" . $GLOBALS['category_sort_types'][$i]['code'] . "\">" . $GLOBALS['category_sort_types'][$i]["name"] . "</option>";
                        } else {
                            echo "<option value=\"" . $GLOBALS['category_sort_types'][$i]['code'] . "\">" . $GLOBALS['category_sort_types'][$i]["name"] . "</option>";
                        }
                    }
                    $i++;
                }
                echo("</SELECT></div>");
                ?>
            </div>
            <?php
        }
    } else {
        if (\DynCom\Compat\Compat::count($GLOBALS['category_sort_types']) > 0) {
            ?>
            <div class="form-group">
                <label for="<?=$selectname?>"><?=$GLOBALS['tc']['sort_by']?></label>
                <div class="select_body">
                    <?
                    echo "<SELECT name=\"" . $selectname . "\" id=\"" . $selectname . "\" onchange=\"this.form.submit();\">";
                    if ($_GET['shop_category'] == 'search' && (!empty($_REQUEST['input_search']) || !empty($_POST['input_search'])) && $_POST['sim_result']) {
                        echo "<option selected=\"selected\" value=\"relevance\">" . $GLOBALS['tc']["relevance"] . "</option>";
                    }
                    $i = 0;
                    while ($i < \DynCom\Compat\Compat::count($GLOBALS['category_sort_types'])) {
                        $code = $GLOBALS['category_sort_types'][$i]['code'];
                        if($code == 'sorting' && $isParentCategory) {
                            $i++;
                            continue;
                        }
                        if ($GLOBALS['category_sort_types'][$i]['code'] != "") {
                            if ($GLOBALS['category_sort_types'][$i]['code'] == $select_value) {
                                echo "<option selected=\"selected\" value=\"" . $GLOBALS['category_sort_types'][$i]['code'] . "\">" . $GLOBALS['category_sort_types'][$i]["name"] . "</option>";
                            } else {
                                echo "<option value=\"" . $GLOBALS['category_sort_types'][$i]['code'] . "\">" . $GLOBALS['category_sort_types'][$i]["name"] . "</option>";
                            }
                        }
                        $i++;
                    }
                    echo("</SELECT>");
                    ?>
                </div>
            </div>
            <?php
        }
    }
}


function create_select(
    $result,
    $name,
    $selectname,
    $select_value = "",
    $disabled = false,
    $autoupdate = true,
    $script = "",
    $sort = ""
)
{
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        $disabled_text = ($disabled) ? " disabled=\"disabled\"" : "";
        $autoupdate_text = ($autoupdate) ? " onchange=\"this.form.submit();\"" : "";
        echo "<div class=\"" . $sort . "label\"><label for=\"" . $selectname . "\">" . $name .
            "</label></div>";
        echo "<div class=\"input\"><select" . $disabled_text . $autoupdate_text .
            " class=\"" . $sort . "select\" class=\"" . $sort . "select\" name=\"" . $selectname . "\" id=\"" .
            $selectname . "\"" . $script . " >";
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
            while ($val = mysqli_fetch_array($result)) {
                if ($val["code"] == $select_value) {
                    echo "<option selected=\"selected\" value=\"" . $val["code"] . "\">" . $val["name"] .
                        "</option>";
                } else {
                    echo "<option value=\"" . $val["code"] . "\">" . $val["name"] . "</option>";
                }
            }
        }
        echo "</select></div>";
    }
}


function get_sort_type($code)
{
    if (!empty($code)) {
        for ($i = 0; $i <= \DynCom\Compat\Compat::count($GLOBALS['category_sort_types']); $i++) {
            if ($GLOBALS['category_sort_types'][$i]['code'] === $code) {
                return $GLOBALS['category_sort_types'][$i]['description'];
            }
        }
    }
    return "
    CASE WHEN shop_view_active_item.item_no LIKE '%" . mysqli_real_escape_string(
            $GLOBALS['mysql_con'],
            $_REQUEST["input_search"]
        ) . "%' THEN 1 ELSE 0 END,
    CASE WHEN shop_view_active_item.description LIKE '%" . mysqli_real_escape_string(
            $GLOBALS['mysql_con'],
            $_REQUEST["input_search"]
        ) . "%' THEN 1 ELSE 0 END,
    CASE WHEN shop_view_active_item.summary LIKE '%" . mysqli_real_escape_string(
            $GLOBALS['mysql_con'],
            $_REQUEST["input_search"]
        ) . "%' THEN 1 ELSE 0 END,
    CASE WHEN shop_view_active_item.search_query LIKE '%" . mysqli_real_escape_string(
            $GLOBALS['mysql_con'],
            $_REQUEST["input_search"]
        ) . "%' THEN 1 ELSE 0 END,
    shop_view_active_item.order_ranking DESC ";
}


function get_layer_no()
{
    if (\DynCom\Compat\Compat::array_key_exists('slevel_6', $_GET) && !empty($_GET["slevel_6"])) {
        $layer_no = "6";
    } elseif (\DynCom\Compat\Compat::array_key_exists('slevel_5', $_GET) && !empty($_GET["slevel_5"])) {
        $layer_no = "5";
    } elseif (\DynCom\Compat\Compat::array_key_exists('slevel_4', $_GET) && !empty($_GET["slevel_4"])) {
        $layer_no = "4";
    } elseif (\DynCom\Compat\Compat::array_key_exists('slevel_3', $_GET) && !empty($_GET["slevel_3"])) {
        $layer_no = "3";
    } elseif (\DynCom\Compat\Compat::array_key_exists('slevel_2', $_GET) && !empty($_GET["slevel_2"])) {
        $layer_no = "2";
    } elseif (\DynCom\Compat\Compat::array_key_exists('slevel_1', $_GET) && !empty($_GET["slevel_1"])) {
        $layer_no = "1";
    } else {
        return 0;
    }
    if(
        (isset($_GET['card']) && $_GET["card"] <> '') ||
        (isset($_GET['shop_category']) && $_GET['shop_category'] === 'queue') ||
        (isset($_GET['shop_category']) && $_GET['shop_category'] === 'dc_order')
    ) {
        if ($GLOBALS['shop_setup']['show_short_url']) {
            return $layer_no;
        }
        return $layer_no - 1;
    } else {
        return $layer_no;
    }
}

function shipping_agent_select(
    $visitor, $name, $selectname = "input_shipping_agent_line_no", $value = null, $disabled = false, $autoupdate = false, $country_code = '', $postCode = '', UserBasket $basket = null
)
{
    $shippingAgents = getAvailableShippingAgents($visitor["id"], $country_code, $postCode, $basket);
    if (is_string($shippingAgents)) {
        $shippingAgents = @mysqli_query($GLOBALS['mysql_con'], $shippingAgents);
        $shippingAgents = @mysqli_fetch_all($shippingAgents, MYSQLI_ASSOC);
    }

    // Prüfen ob für das Gewicht Versanddienstleister verfügbar sind
    if ($shippingAgents) {
        $disabled_text = ($disabled) ? " disabled=\"disabled\"" : "";
        $autoupdate_text = ($autoupdate) ? " onchange=\"this.form.submit();\"" : "";
    }
    if (\DynCom\Compat\Compat::count($shippingAgents) > 0) {

		if (!isset($IOCContainer)) {
			$IOCContainer = $GLOBALS['IOC'];
		}
		/** @var \DynCom\dc\dcShop\classes\CurrShopConfiguration $configuration */
		$configuration = $IOCContainer->create('$CurrShopConfig');

		echo "<div class='form-group'><label for='" . $selectname . "' >" . $name . "</label>";

        echo "<div class=\"select_body\"><select" . $disabled_text . $autoupdate_text . " class=\"select_2\" name=\"" . $selectname . "\" id=\"" . $selectname . "\">";
        $result = mysqli_query($GLOBALS['mysql_con'], $shippingAgents);
        $i = 0;

        if (is_object($shippingAgents)) {
            foreach ($shippingAgents as $shippingAgent) {
                $firstShippingAgent = $shippingAgent->getAllFieldsAsArray();
                break;
            }
        }

        if ($_POST["input_shipping_line_no"] == '' && $_SESSION['shipping_line_no'] == '') {
            $_POST["input_shipping_line_no"] = $firstShippingAgent["line_no"];
        } elseif ($_POST["input_shipping_line_no"] == '' && $_SESSION['shipping_line_no'] != '') {
            $_POST["input_shipping_line_no"] = $_SESSION['shipping_line_no'];
        }

        foreach ($shippingAgents as $shippingAgent) {
            if (is_object($shippingAgent)) {
                $shippingAgent = $shippingAgent->getAllFieldsAsArray();
            }
            if ($i == 0) {
                $_SESSION['shipping_line_no'] = $shippingAgent['line_no'];
                $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'];
                if($GLOBALS['shop_setup']['shipping_cost_net'] == true) {
                    $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'] * getTaxForShippingCost();
                }
                $i = 1;
            }
            $selected = ($_POST["input_shipping_line_no"] == $shippingAgent["line_no"]) ? "selected = \"selected\"" : "";
            if ($selected != '') {
                $_SESSION['shipping_line_no'] = $shippingAgent['line_no'];
                $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'];
                if($GLOBALS['shop_setup']['shipping_cost_net'] == true) {
                    $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'] * getTaxForShippingCost();
                }
            }
            if (basketTotal() > $shippingAgent['exemption'] && $shippingAgent['exemption'] > 0
            ) {
                $shippingAgent["shipping_cost"] = 0;
            }
			//generate cost string
			if ($shippingAgent['shipping_cost'] > 0) {
				$numberFormatter = NumberFormatter::create($GLOBALS['language']['locale_code'], NumberFormatter::CURRENCY);
				$costValue = '+&nbsp;' . $numberFormatter->formatCurrency($shippingAgent['shipping_cost'], $configuration->getCurrencyCode() === '' ? 'EUR' : $configuration->getCurrencyCode());
			} else {
				$costValue = $GLOBALS['tc']['for_free'];
			}
			$costText = "&nbsp;(" . $costValue . ")";

            echo "<option " . $selected . " value=\"" . $shippingAgent["line_no"] . "\">"
                . $shippingAgent["description"]
				. $costText
                . "</option>";
        }
        echo "</select></div></div>";
    }
}

function shipping_agent_list(
    $visitor, $name, $selectname = "input_shipping_agent_line_no", $value = null, $disabled = false, $autoupdate = false, $country_code = '', $postCode = '', UserBasket $basket = null
)
{
    $shippingAgents = getAvailableShippingAgents($visitor["id"], $country_code, $postCode, $basket);
    if (is_string($shippingAgents)) {
        $shippingAgents = @mysqli_query($GLOBALS['mysql_con'], $shippingAgents);
        $shippingAgents = @mysqli_fetch_all($shippingAgents, MYSQLI_ASSOC);
    }

    if ($shippingAgents) {
        $disabled_text = ($disabled) ? " disabled=\"disabled\"" : "";
        $autoupdate_text = ($autoupdate) ? " onchange=\"this.form.submit();\"" : "";
    }
    if (\DynCom\Compat\Compat::count($shippingAgents) > 0) {

		if (!isset($IOCContainer)) {
			$IOCContainer = $GLOBALS['IOC'];
		}
		/** @var \DynCom\dc\dcShop\classes\CurrShopConfiguration $configuration */
		$configuration = $IOCContainer->create('$CurrShopConfig');

        echo "<div class='form-group'><div class='order_devision_headline'>" . $name . "</div></div>";
        echo "<div class=\"shippingAgentWrapper order_option_list_inner\"><div class='row'>";
        $i = 0;
        $j = 0;

        if (is_object($shippingAgents)) {
            foreach ($shippingAgents as $shippingAgent) {
                $firstShippingAgent = $shippingAgent->getAllFieldsAsArray();
                break;
            }
        }

        if ($_POST["input_shipping_line_no"] == '' && $_SESSION['shipping_line_no'] == '') {
            $_POST["input_shipping_line_no"] = $firstShippingAgent["line_no"];
        } elseif ($_POST["input_shipping_line_no"] == '' && $_SESSION['shipping_line_no'] != '') {
            $_POST["input_shipping_line_no"] = $_SESSION['shipping_line_no'];
        }

        foreach ($shippingAgents as $shippingAgent) {
            if (is_object($shippingAgent)) {
                $shippingAgent = $shippingAgent->getAllFieldsAsArray();
            }
            if ($j == 0) {
                $_SESSION['shipping_line_no'] = $shippingAgent['line_no'];
                $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'];
                if($GLOBALS['shop_setup']['shipping_cost_net'] == true) {
                    $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'] * getTaxForShippingCost();
                }
                $j = 1;
            }
            $selected = ($_POST["input_shipping_line_no"] == $shippingAgent["line_no"]) ? " selected" : "";
            $selectedRadio = ($_POST["input_shipping_line_no"] == $shippingAgent["line_no"]) ? " checked=\"checked\"" : "";
            if ($selected != '') {
                $_SESSION['shipping_line_no'] = $shippingAgent['line_no'];
                $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'];
                if($GLOBALS['shop_setup']['shipping_cost_net'] == true) {
                    $_SESSION['shipping_cost'] = $shippingAgent['shipping_cost'] * getTaxForShippingCost();
                }
            }
            if (basketTotal() > $shippingAgent['exemption'] && $shippingAgent['exemption'] > 0
            ) {
                $shippingAgent["shipping_cost"] = 0;
            }
            $img = "";
            if ($shippingAgent["logo"] != "" && file_exists(
                    rtrim(
                        dirname(dirname(dirname(__DIR__))),
                        '/'
                    ) . DIRECTORY_SEPARATOR . $GLOBALS["shop_setup"]["uploaddir_order_icons"] . $shippingAgent["logo"]
                )
            ) {
                $img = "<div class='image'><img src=\"" . $GLOBALS["shop_setup"]["uploaddir_order_icons"] . $shippingAgent["logo"] . "\"></div>";
            }
            $longText = "";
            if ($shippingAgent["content"] != "") {
                $longText = "&nbsp;<span class=\"longText\">" . $shippingAgent["content"] . "</span>";
            }
            //generate cost string
            if ($shippingAgent['shipping_cost'] > 0) {
				$numberFormatter = NumberFormatter::create($GLOBALS['language']['locale_code'], NumberFormatter::CURRENCY);
				$costValue = '+&nbsp;' . $numberFormatter->formatCurrency($shippingAgent['shipping_cost'], $configuration->getCurrencyCode() === '' ? 'EUR' : $configuration->getCurrencyCode());
            } else {
				$costValue = $GLOBALS['tc']['for_free'];
            }
			$costText = "&nbsp;(" . $costValue . ")";
            echo "<div class='col-xs-12 col-sm-6 col-lg-4'>";
            echo "<div class=\"form-check shippingAgent" . $selected . "\">";
            echo "<label  for=\"" . $selectname . "_" . $i . "\">";
            echo "<input" . $disabled_text . $autoupdate_text . " value=\"" . $shippingAgent["line_no"] . "\" type=\"radio\" id=\"" . $selectname . "_" . $i . "\" name=\"" . $selectname . "\" class=\"shippingRadioButton\"" . $selectedRadio . " />";
            echo $img . "<span class=\"span-wrapper\"><span class=\"termDescription\">"
                . $shippingAgent["description"]
                . $costText . "</span>"
                . $longText . "</span>";
            echo "</label>";
            echo "</div>";
            echo "</div>";
            $i++;
        }
        echo "</div></div>";
    }
}

function getAvailableShippingAgents($visitorID, $countrycode, $postCode, UserBasket $basket = null)
{
    $query = '';
    if (null !== $basket) {
        if (!isset($IOCContainer) || !($IOCContainer instanceof IOCInterface)) {
            $IOCContainer = $GLOBALS['IOC'];
        }
        $currShopConfig = $IOCContainer->resolve('$CurrShopConfig');
        /**
         * @var $shipOptRepo \DynCom\dc\dcShop\ShippingOptions\ShippingOptionRepository
         */
        $shipOptRepo = $IOCContainer->resolve('$ShippingOptionRepository');
        $shippingOptions = $shipOptRepo->getAllForOrder($currShopConfig, $basket, $countrycode, $postCode);
        if ((\DynCom\Compat\Compat::count($shippingOptions) > 0) && $shippingOptions->getFirst()->getID() > 0) {
            return $shippingOptions;
        } else {
            return;
        }
    }

    // Berechnet das Gewicht aller Artikel im Warenkorb
    // Berechnet anschließend an Hand des Gesamtgewichts die möglichen Versanddienstleister und schreibt diese in ein Array
    $query = "SELECT *
			  FROM shop_user_basket
			  WHERE shop_visitor_id = '" . $visitorID . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $weight_sum = 0;
    while ($row = mysqli_fetch_array($result)) {
        $item = mysqli_fetch_array(
            mysqli_query(
                $GLOBALS['mysql_con'],
                "SELECT weight
											   FROM shop_item
											   WHERE id = '" . $row["shop_item_id"] . "'"
            )
        );
        $weight_sum = $weight_sum + ($item["weight"] * $row["item_quantity"]);
    }
    //Ermittelt den Preis zum Finden möglicher Versanddienstleister
    $amount = shop_get_basket_amount($visitorID);
    //Anpassung Sonderversandgutschein ---
    $value_type = 0;
    if ($_SESSION['coupon']['value_type']) {
        $value_type = $_SESSION['coupon']['value_type'];
    }
    // +++
    // Prüfen ob Versanddienstleister gefunden werden
    // wenn nicht, Array leeren und zurückgeben
    $orderBy = 'shipping_cost';
    if ($GLOBALS["shop"]["order_options_sorting"] == "1") {
        $orderBy = 'sorting';
    }
    $shippingCostQuery = "SELECT id,
								  company,
								  shop_code,
								  language_code,
								  line_no,
								  country_code,
								  shipping_agent_code,
								  shipping_agent_service_code,
								  weight_from,
								  weight_to,
								  amount_from,
								  amount_to,
								  IF (" . $value_type . " = 3 AND coupon_shipping_cost > 0,
										coupon_shipping_cost,	
										shipping_cost) AS shipping_cost,
								  exemption,
								  description,
								  to_delete
						  FROM shop_shipping_option
						  WHERE (weight_to > '" . $weight_sum . "' OR weight_to=0)
						  	AND weight_from <= '" . $weight_sum . "'
						  	AND amount_from <= '" . $amount . "'
						  	AND (amount_to > '" . $amount . "' OR amount_to = 0)
						  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'
						  	AND language_code = '" . $GLOBALS['shop_language']['code'] . "'
						  	AND (country_code = '' OR country_code = '" . $countrycode . "')
						  ORDER BY " . $orderBy;

    if (@\DynCom\Compat\Compat::mysqli_num_rows(mysqli_query($GLOBALS['mysql_con'], $shippingCostQuery)) > 0) {
        return $shippingCostQuery;
    } else {
        return false;
    }

}

function payment_terms_select($user, $name, $selectname = "input_payment_line_no", $value = NULL, $disabled = FALSE, $autoupdate = FALSE, $countrycode = '', $isSubscriptionOrder = false)
{
    $payment_terms = get_available_payment_terms($countrycode, $_SESSION["dc_id"], $isSubscriptionOrder, null);
    //echo "<!--".$payment_terms."-->";
    // Prüfen ob Zahlunsarten verfügbar sind
    if ($payment_terms) {

        if (!isset($IOCContainer)) {
            $IOCContainer = $GLOBALS['IOC'];
        }
        /** @var \DynCom\dc\dcShop\classes\CurrShopConfiguration $configuration */
        $configuration = $IOCContainer->create('$CurrShopConfig');

        $disabled_text = ($disabled) ? " disabled=\"disabled\"" : "";
        $autoupdate_text = ($autoupdate) ? " onchange=\"this.form.submit();\"" : "";
        echo "<div class='form-group'><label for='" . $selectname . "' >" . $name . "</label>";

        echo "<div class=\"select_body\"><select" . $disabled_text . $autoupdate_text . " class=\"select_2\" name=\"" . $selectname . "\" id=\"" . $selectname . "\">";
        $result = mysqli_query($GLOBALS['mysql_con'], $payment_terms);
        $i = 0;
        $j = 0;
        if ($_POST["input_payment_line_no"] == '' && $_SESSION['payment_line_no'] == '') {
            $_POST["input_payment_line_no"] = $GLOBALS['shop_language']['default_payment_option_line_no'];
        } elseif ($_POST["input_payment_line_no"] == '' && $_SESSION['payment_line_no'] != '') {
            $_POST["input_payment_line_no"] = $_SESSION['payment_line_no'];
        }
        while ($payment_term = mysqli_fetch_array($result)) {
            if ($j == 0) {
                $_SESSION['payment_line_no'] = $payment_term['line_no'];
                $_SESSION['payment_cost'] = $payment_term['payment_cost'];
                $j = 1;
            }

            if ($i == 0 && $_POST["input_payment_line_no"] == 0) {
                $_POST["input_payment_line_no"] = $payment_term["line_no"];
            }

            //generate cost string
            $costText = '';
            if ($payment_term['payment_cost'] > 0) {
                $numberFormatter = NumberFormatter::create($GLOBALS['language']['locale_code'], NumberFormatter::CURRENCY);
				$costText = "&nbsp;(+&nbsp;" . $numberFormatter->formatCurrency($payment_term['payment_cost'], $configuration->getCurrencyCode() === '' ? 'EUR' : $configuration->getCurrencyCode()). ")";
            }

            $selected = ($_POST["input_payment_line_no"] == $payment_term["line_no"]) ? "selected = \"selected\"" : "";
            if ($selected != '') {
                $_SESSION['payment_line_no'] = $payment_term['line_no'];
                $_SESSION['payment_cost'] = $payment_term['payment_cost'];
            }
            echo "<option " . $selected . " value=\"" . $payment_term["line_no"] . "\">"
                . $payment_term["description"]
                . $costText
                . "</option>";
            $i++;
        }
        echo "</select></div></div>";
    }
}

function payment_terms_list($user, $name, $selectname = "input_payment_line_no", $value = NULL, $disabled = FALSE, $autoupdate = FALSE, $countrycode = '', $isSubscriptionOrder = false)
{
    $payment_terms = get_available_payment_terms($countrycode, $_SESSION["dc_id"], $isSubscriptionOrder, null);

    // Prüfen ob Zahlunsarten verfügbar sind
    if ($payment_terms) {

        if (!isset($IOCContainer)) {
            $IOCContainer = $GLOBALS['IOC'];
        }
        /** @var \DynCom\dc\dcShop\classes\CurrShopConfiguration $configuration */
        $configuration = $IOCContainer->create('$CurrShopConfig');

        $disabled_text = ($disabled) ? " disabled=\"disabled\"" : "";
        $autoupdate_text = ($autoupdate) ? " onchange=\"this.form.submit();\"" : "";
        echo "<div class='order_devision_headline'>" . $name . "</div>";
        echo "<div class=\"paymentTermWrapper orderOptionList\"><div class='row'>";
        $result = mysqli_query($GLOBALS['mysql_con'], $payment_terms);
        $i = 0;
        $j = 0;
        if ($_POST["input_payment_line_no"] == '' && $_SESSION['payment_line_no'] == '') {
            $_POST["input_payment_line_no"] = $GLOBALS['shop_language']['default_payment_option_line_no'];
        } elseif ($_POST["input_payment_line_no"] == '' && $_SESSION['payment_line_no'] != '') {

            $_POST["input_payment_line_no"] = $_SESSION['payment_line_no'];


            $paymentOption = get_payment_option_by_line_no($_SESSION['payment_line_no']);
            // if the payment is paypal express then load paypal in the payment select options
            if ($paymentOption['checkout'] == 20 || $paymentOption['checkout'] == 21) {

                if (!isset($IOCContainer) || !($IOCContainer instanceof IOCInterface)) {
                    $IOCContainer = $GLOBALS['IOC'];
                }
                $pdo = $IOCContainer->resolve('DynCom\dc\common\classes\PDOQueryWrapper');

                // Get PayPal types with checkout number 4 or 5 if the current payment lino is paypal express
                $prepStatement = "SELECT 
                        *
                         FROM 
                         shop_payment_option
                          where 
                              company = :company
                                AND shop_code = :shop_code
                                  AND language_code = :language_code
                                  AND ( checkout = 4 or  checkout = 5 ) 
                                   LIMIT 1";

                $params = [
                    [':company', $GLOBALS['shop']['company'], PDO::PARAM_STR],
                    [':shop_code', $GLOBALS['shop']['code'], PDO::PARAM_STR],
                    [':language_code', $GLOBALS['shop_language']['code'], PDO::PARAM_STR],

                ];
                $pdo->setQuery($prepStatement);
                $pdo->prepareQuery();
                $pdo->bindParameters($params);
                $pdo->executePreparedStatement();
                $resultArray = $pdo->getResultArray();

                $paymentOption = $resultArray[0];

                $_POST["input_payment_line_no"] = $paymentOption['line_no'];
                $_SESSION['payment_line_no'] = $paymentOption['line_no'];

            }

        }

        while ($payment_term = mysqli_fetch_array($result)) {
            if ($j == 0) {
                $_SESSION['payment_line_no'] = $payment_term['line_no'];
                $_SESSION['payment_cost'] = $payment_term['payment_cost'];
                $j = 1;
            }

            if ($i == 0 && $_POST["input_payment_line_no"] == 0) {
                $_POST["input_payment_line_no"] = $payment_term["line_no"];
            }

            $selected = ($_POST["input_payment_line_no"] == $payment_term["line_no"]) ? " selected" : "";
            $selectedRadio = ($_POST["input_payment_line_no"] == $payment_term["line_no"]) ? " checked=\"checked\"" : "";

            if ($selected != '') {
                $_SESSION['payment_line_no'] = $payment_term['line_no'];
                $_SESSION['payment_cost'] = $payment_term['payment_cost'];
            }
            $img = "";
            if ($payment_term["logo"] != "" && file_exists(
                    rtrim(
                        dirname(dirname(dirname(__DIR__))),
                        '/'
                    ) . DIRECTORY_SEPARATOR . $GLOBALS["shop_setup"]["uploaddir_order_icons"] . $payment_term["logo"]
                )
            ) {
                $img = "<div class='orderOptionList__image'><div class='image'><img src=\"" . $GLOBALS["shop_setup"]["uploaddir_order_icons"] . $payment_term["logo"] . "\"></div></div>";
            }
            $longText = "";
            if ($payment_term["content"] != "") {
                $longText = "<div class=\"orderOptionList__content\">" . $payment_term["content"] . "</div>";
            }
            $costText = '';
            //generate cost string
			if ($payment_term['payment_cost'] > 0) {
				$numberFormatter = NumberFormatter::create($GLOBALS['language']['locale_code'], NumberFormatter::CURRENCY);
				$costText = '&nbsp;(+&nbsp;' . $numberFormatter->formatCurrency($payment_term['payment_cost'], $configuration->getCurrencyCode() === '' ? 'EUR' : $configuration->getCurrencyCode()). ")";
            }

            echo "<div class='col-xs-12 col-sm-6 col-lg-4'>";
            echo "<label class='orderOptionList__item paymentTerm ".$selected."' for=\"" . $selectname . "_" . $i . "\">";
            echo "<input" . $disabled_text . $autoupdate_text . " value=\"" . $payment_term["line_no"] . "\" type=\"radio\" id=\"" . $selectname . "_" . $i . "\" name=\"" . $selectname . "\" class=\"paymentRadioButton\"" . $selectedRadio . " />";
            echo $img . "<div class=\"orderOptionList__text\"><div class=\"orderOptionList__headline\">" . $payment_term["description"] . $costText . "</div>" . $longText . "</div>";
            echo "</label>";
            echo "</div>";
            $i++;
        }
        echo "</div></div>";
    }
}

/** @param GenericUserBasket $basket */
function get_available_payment_terms($countrycode, $dc_active = false, $recurrent_payment_active = false, $basket = null)
{
    // Prüfen ob Versanddienstleister gefunden werden
    // wenn nicht, Array leeren und zurückgeben

    $IOCContainer = $GLOBALS['IOC'];
    $currShopConfig = $IOCContainer->resolve('$CurrShopConfig');
    /** @var UserBasket $currUserBasket */
    $currUserBasket = $IOCContainer->resolve('$CurrUserBasket');

    /** @var \DynCom\dc\dcShop\classes\PaymentOptionRepository $paymentOptRepo */
    $paymentOptRepo = $IOCContainer->resolve('DynCom\dc\dcShop\classes\PaymentOptionRepository');
    $allowedPaymentOptionsIds = $paymentOptRepo->getrAllowedPaymentOptions($currShopConfig);

    $queryCondition = "";
    if (\DynCom\Compat\Compat::count($allowedPaymentOptionsIds) > 0) {
        $paymentIds = "";
        foreach ($allowedPaymentOptionsIds as $paymentId) {
            $paymentIds .= $paymentId->id . ",";
        }
        $paymentIds = rtrim($paymentIds, ',');
        $queryCondition .= " AND id in (" . $paymentIds . ")";
    }

    if ($basket === null) {
        $basket = $IOCContainer->resolve('$CurrUserBasket');
    }

    $payolution_added_filter = '';

    if (
        $_SESSION['visitor_new_shipping_address'] === 'on'
        || $_SESSION["visitor_radio_shipping_is_packstation"] != ""
        || $_SESSION["input_is_company"] == 'on'
        || $basket->getBasketTotal() < 10
        || $basket->getBasketTotal() > 3000
        || checkInvoiceIsNotShipping()
    ) {
        $payolution_added_filter = " AND checkout NOT IN (17, 18) ";
    }

    $orderBy = 'line_no';
    if ($GLOBALS["shop"]["order_options_sorting"] == "1") {
        $orderBy = 'sorting';
    }

    $digitalProducts = $currUserBasket->hasDigitalProducts();

    // hide paypal express in the payment options list


    if (($_SESSION["dc_id"] == '' && !$dc_active && !$digitalProducts) && !$recurrent_payment_active) {
        $payment_terms_query = "SELECT *
							  FROM shop_payment_option
							  WHERE company = '" . $GLOBALS['shop']['company'] . "'
								AND shop_code = '" . $GLOBALS['shop']['code'] . "'
								AND language_code='" . $GLOBALS['shop_language']['code'] . "'
								AND (country_code = '' OR country_code = '" . $countrycode . "')
								AND active = 1
								AND checkout <> 20 and checkout <> 21
								" . $payolution_added_filter . " " . $queryCondition . "
							  ORDER BY " . $orderBy;
    } elseif ($_SESSION["dc_id"] != '' || $dc_active || $digitalProducts) {
        $payment_terms_query = "SELECT *
							  FROM shop_payment_option
							  WHERE company = '" . $GLOBALS['shop']['company'] . "'
								AND shop_code = '" . $GLOBALS['shop']['code'] . "'
								AND language_code='" . $GLOBALS['shop_language']['code'] . "'
								AND (country_code = '' OR country_code = '" . $countrycode . "')
								AND dc_active=1
								AND active = 1
								AND checkout <> 20 and checkout <> 21
								" . $payolution_added_filter . " " . $queryCondition . "
							  ORDER BY " . $orderBy;
    } elseif ($recurrent_payment_active) {
        $payment_terms_query = "SELECT *
							  FROM shop_payment_option
							  WHERE company = '" . $GLOBALS['shop']['company'] . "'
								AND shop_code = '" . $GLOBALS['shop']['code'] . "'
								AND language_code='" . $GLOBALS['shop_language']['code'] . "'
								AND (country_code = '' OR country_code = '" . $countrycode . "')
								AND active = 1
								AND checkout <> 20 and checkout <> 21
								AND recurrent_payment_active = 1
								" . $payolution_added_filter . " " . $queryCondition . "
							  ORDER BY " . $orderBy;
    }

    //echo "<!--".$payment_terms_query."-->";
    if (@\DynCom\Compat\Compat::mysqli_num_rows(mysqli_query($GLOBALS['mysql_con'], $payment_terms_query)) > 0) {
        return $payment_terms_query;
    } else {
        return false;
    }
}

/*function shipping_cost($userID, $mode = "shipping_cost")
{
    if (!isset($_POST["input_shipping_agent_code"])) {
        $shippingAgentsQuery = getAvailableShippingAgents($userID,);
    } else {
        $shippingAgentsQuery = "SELECT shipping_cost
								FROM shop_shipping_cost
								WHERE id = '" . $_POST["input_shipping_agent_code"] . "'";
    }
    if ($shippingAgentsQuery) {
        $shippingAgentsResult = mysqli_query($GLOBALS['mysql_con'], $shippingAgentsQuery);
        $shippingAgent = mysqli_fetch_array($shippingAgentsResult);
        $shippingCost = $shippingAgent["shipping_cost"];
    } else {
        $shippingCost = 0;
    }
    return $shippingCost;
}*/

function create_countries($name, $selectname, $select_value, $disabled = null, $type = 0)
{
    $result = get_countries($type);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        $disabled_text = ($disabled) ? " disabled=\"disabled\"" : ""; ?>
        <div id="countries_select">
            <div class="form-group">
                <label for="<?= $selectname; ?>"><?= $name; ?></label>
                <div class="select_body">
                    <select <?= $disabled_text; ?> id="<?= $selectname ?>" class="select" name='<?= $selectname ?>'>
                        <?
                        $currentReverseChargeAllowedCountryCode = "";
                        $allReverseChargeAllowedCountryCodes = [];
                        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
                            while ($val = mysqli_fetch_array($result)) {
                                if ($val["country_code"] == $select_value) {
                                    echo "<option selected=\"selected\" value=\"" . $val["country_code"] . "\">" . $val["description"] . "</option>";
                                    if ($val["reverse_charge"] == 1) {
                                        $currentReverseChargeAllowedCountryCode = "'" . $val["country_code"] . "'";
                                    }
                                } else {
                                    echo "<option value=\"" . $val["country_code"] . "\">" . $val["description"] . "</option>";
                                }

                                if ($val["reverse_charge"] == 1) {
                                    $allReverseChargeAllowedCountryCodes[] = $val["country_code"];
                                }
                            }
                        }
                        $allReverseChargeAllowedCountryCodes = json_encode($allReverseChargeAllowedCountryCodes);

                        ?>
                    </select>
                    <script type="text/javascript">
                        jsvat.allowed = [<?= $currentReverseChargeAllowedCountryCode ?>];
                        var currentCountryCode = <? echo $currentReverseChargeAllowedCountryCode == '' ? "''" : $currentReverseChargeAllowedCountryCode ?>;
                        var allReverseChargeAllowedCountryCodes = <?= $allReverseChargeAllowedCountryCodes ?>;
                        $(document).ready(function () {

                            $('#input_country').on('change', function (e) {
                                jsvat.allowed = [$('#input_country').val()];
                                currentCountryCode = $('#input_country').val();
                                dc_checkVat($("#input_vatid"), $("#input_vatid").val(), allReverseChargeAllowedCountryCodes, currentCountryCode);
                            });

                            $("#input_vatid").blur(function () {
                                dc_checkVat($(this), $(this).val(), allReverseChargeAllowedCountryCodes, currentCountryCode);
                            });

                            $('.required').each(function (e) {
                                var input = $(this).find('input');
                                if (input.is(':visible')) {
                                    input.blur(function () {
                                        if (input.val() == '') {
                                            input.parent().addClass('has-danger');
                                        } else {
                                            input.parent().removeClass('has-danger');
                                        }
                                    })
                                }
                            });

                            $('body').on('click', ".validate_vat", function (e) {
                                e.preventDefault();

                                var error = dc_checkVat($("#input_vatid"), $("#input_vatid").val(), allReverseChargeAllowedCountryCodes, currentCountryCode);


                                $('.required').each(function (e) {
                                    var input = $(this).find('input');
                                    if (input.is(':visible')) {
                                        if (input.val() == '') {
                                            error = true;
                                            input.parent().addClass('has-danger');
                                        }
                                    }
                                });

                                if (!error) {
                                    document.form_user_order.action = '/<? echo customizeUrl(); ?>/order/shipment_payment_option_select/';
                                    document.form_user_order.submit();
                                } else {
                                    $('#vat_check_requestbox').modal('show');
                                }
                                return false;
                            })
                        });
                    </script>
                </div>
            </div>
        </div>
        <?
    }
}

function get_countries($type = 0)
{
    switch ($type) {
        case 0:
            $typequery = "";
            break;
        case 1:
            $typequery = " AND invoice_to=1 ";
            break;
        case 2:
            $typequery = " AND ship_to=1 ";
            break;
        default:
            $typequery = "";
            break;
    }
    $query = "SELECT *
			  FROM shop_country
			  WHERE company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'
			  	AND language_code = '" . $GLOBALS['shop_language']['code'] . "'
			  	" . $typequery . "
			  ORDER BY description";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        return $result;
    }
}

function check_mandatory_fields_b2c($post_var, $step = "step1")
{

    $pay_query = "SELECT 
					checkout 
				FROM 
					shop_payment_option 
				WHERE 
					company='" . $GLOBALS['shop']['company'] . "' 
				  AND 
					shop_code='" . $GLOBALS['shop']['code'] . "' 
				  AND 
					language_code='" . $GLOBALS['shop_language']['code'] . "' 
				  AND 
					line_no='" . $post_var["input_payment_line_no"] . "'";
    $pay_res = mysqli_query($GLOBALS['mysql_con'], $pay_query);
    $checkout = mysqli_result($pay_res, 0);
    $ret_value = true;
//    if($GLOBALS['shop_customer']['show_net'] == 1) {
//        return true;
//    }
    if (!$GLOBALS['visitor']['frontend_login']) {
        if ($step == "step1" && $_GET["action"] == "step2") {
            $ret_val = true;
            if ($post_var["input_surname"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_lastname"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_user_street"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_user_street_no"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_city"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_post_code"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_email"] == "") {
                $ret_val = false;
            } elseif ($post_var['input_birthday'] != "") {
                $date_arr = explode(".", $post_var['input_birthday']);
                if (checkdate($date_arr[1], $date_arr[0], $date_arr[2])) {
                    $ret_val = true;
                } else {
                    $ret_val = false;
                }
            } elseif ($post_var['input_is_company'] == 'on' && $post_var['input_company'] == '' ) {
                $ret_val = false;
            }
            if ($post_var['input_shipping_is_not_invoice'] == "on" && $_SESSION["dc_id"] == '') {
                if ($post_var["input_surname_shipping"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_lastname_shipping"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_user_street_shipping"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_user_street_no_shipping"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_city_shipping"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_post_code_shipping"] == "") {
                    $ret_val = false;
                }
            }
            if ($post_var['input_shipping_is_packstation'] == "on" && $_SESSION["dc_id"] == '') {
                if ($post_var["input_surname_shipping_packstation"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_lastname_shipping_packstation"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_company_shipping_packstation"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_user_street_shipping_packstation"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_city_shipping_packstation"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_post_code_shipping_packstation"] == "") {
                    $ret_val = false;
                }
            }
        } elseif ($step == "step2" && $_GET["action"] == "step3") {
            if ($post_var['input_payment_line_no'] == '') {
                if ($_SESSION["payment_line_no"] != '') {
                    $post_var['input_payment_line_no'] = $_SESSION["payment_line_no"];
                }
            }
            $query = "SELECT *
			  FROM shop_payment_option
			  WHERE line_no='" . $post_var['input_payment_line_no'] . "'
			  AND shop_code='" . $GLOBALS['shop']['code'] . "'
			  AND language_code ='" . $GLOBALS['shop_language']['code'] . "'";
            if (!empty($_SESSION["dc_id"])) {
                $query .= " AND dc_active = 1";
            }
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            $ret_val = true;
            if (\DynCom\Compat\Compat::mysqli_num_rows($result) == 0) {
                $ret_val = false;
            }
            if ($step == "step2" && $_GET["action"] == "step3" && $checkout == 7 && $ret_val) {
                $ret_val = true;
                if ($post_var["input_account_no"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_bank_no"] == "") {
                    $ret_val = false;
                } elseif ($post_var["input_birthday"] == "" || $post_var["input_billpay_agb"] <> 'on') {
                    $ret_val = false;
                }
            } elseif ($step == "step2" && $_GET["action"] == "step3" && $checkout == 6 && $ret_val) {
                $ret_val = true;
                if ($post_var["input_birthday"] == "" || $post_var["input_billpay_agb"] <> 'on') {
                    $ret_val = false;
                }
            }// Payolution ---
            elseif ($step == "step2" && $_GET["action"] == "step3" && in_array($checkout, array("17", "18"))) {
                $ret_val = TRUE;
                $paytype = ($checkout == "17") ? "INVOICE" : "INSTALLMENT";
                if (!payolution_check_mandatory_fields($paytype, $post_var)) { // see payolution.inc.php
                    $ret_val = false;
                }
            }
            // Payolution +++

        } else {
            return true;
        }
    } else {
        if ($step == "step1" && $_GET["action"] == "step2") {
            $ret_val = true;
            if ((($post_var["input_surname"] == "" && $_SESSION["customer_invoice_address"]->sur_name == "")
                || ($post_var["input_lastname"] == "" && $_SESSION["customer_invoice_address"]->last_name == ""))
                && ($_SESSION['customer_invoice_address']->name == ''))  {
                $ret_val = false;
            } elseif ($post_var["input_user_street"] == "" && $_SESSION["customer_invoice_address"]->address == "") {
                $ret_val = false;
            } elseif ($post_var["input_city"] == "" && $_SESSION["customer_invoice_address"]->city == "") {
                $ret_val = false;
            } elseif ($post_var["input_post_code"] == "" && $_SESSION["customer_invoice_address"]->post_code == "") {
                $ret_val = false;
            } elseif ($post_var["input_email"] == "" && $GLOBALS["shop_user"]['email'] == "") {
                $ret_val = false;
            } elseif ($post_var['input_birthday'] != "") {
                $date_arr = explode(".", $post_var['input_birthday']);
                if (checkdate($date_arr[1], $date_arr[0], $date_arr[2])) {
                    $ret_val = true;
                } else {
                    $ret_val = false;
                }
            }
            if ((($post_var["input_surname_shipping"] == "" && $_SESSION["customer_invoice_address"]->sur_name == "")
                    || ($post_var["input_lastname_shipping"] == "" && $_SESSION["customer_invoice_address"]->last_name == ""))
                && ($_SESSION['customer_invoice_address']->name == '')) {
                $ret_val = false;
            } elseif ($post_var["input_user_street_shipping"] == "" && $_SESSION["customer_invoice_address"]->address == "") {
                $ret_val = false;
            } elseif ($post_var["input_city_shipping"] == "" && $_SESSION["customer_invoice_address"]->city == "") {
                $ret_val = false;
            } elseif ($post_var["input_post_code_shipping"] == "" && $_SESSION["customer_invoice_address"]->post_code == "") {
                $ret_val = false;
            }
        } elseif ($step == "step2" && $_GET["action"] == "step3" && $checkout == 7) {
            $ret_val = true;
            if ($post_var["input_account_no"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_bank_no"] == "") {
                $ret_val = false;
            } elseif ($post_var["input_birthday"] == "" || $post_var["input_billpay_agb"] <> 'on') {
                $ret_val = false;
            }
        } elseif ($step == "step2" && $_GET["action"] == "step3" && $checkout == 6) {
            $ret_val = true;
            if ($post_var["input_birthday"] == "" || $post_var["input_billpay_agb"] <> 'on') {
                $ret_val = false;
            }
            // Payolution ---
        } elseif ($step == "step2" && $_GET["action"] == "step3" && in_array($checkout, array("17", "18"))) {
            $ret_val = TRUE;
            $paytype = ($checkout == "17") ? "INVOICE" : "INSTALLMENT";
            if (!payolution_check_mandatory_fields($paytype, $post_var)) { // see payolution.inc.php
                $ret_val = false;
            }
            // Payolution +++
        } else {
            return true;
        }
    }
    return $ret_val;
}

function get_shipment_address($id)
{
    $query = "SELECT * FROM shop_shipment_address WHERE id = '" . $id . "' AND customer_no = '" . $GLOBALS["shop_customer"]["customer_no"] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        $shipment_address = mysqli_fetch_array($result);
    }
    return $shipment_address;
}

function session_save_data_b2c($page)
{
    if ($page == "step1") {

        if ($GLOBALS['visitor']['frontend_login']) {

            switch ((int)$_SESSION["customer_invoice_address"]->salutation) {
                case 0:
                    $_SESSION["visitor_title"] = $GLOBALS['tc']['Mr.'];
                    break;
                case 1:
                    $_SESSION["visitor_title"] = $GLOBALS['tc']['Ms.'];
                    break;
                default:
                    $_SESSION["visitor_title"] = $GLOBALS['tc']['Mr.'];
                    break;
            }

            $_SESSION["visitor_name"] = $_SESSION["customer_invoice_address"]->name;
            $_SESSION["visitor_surname"] = $_SESSION["customer_invoice_address"]->sur_name;
            $_SESSION["visitor_lastname"] = $_SESSION["customer_invoice_address"]->last_name;
            $_SESSION["visitor_name_2"] = $_SESSION["customer_invoice_address"]->name_2;
            $_SESSION["visitor_company"] = '';
            $_SESSION["visitor_vatid"] = '';
            if ($_SESSION["customer_invoice_address"]->is_company) {
                $_SESSION["visitor_company"] = $_SESSION["customer_invoice_address"]->name;
                $_SESSION["visitor_name"] = $_SESSION["customer_invoice_address"]->name_2;
                $_SESSION["visitor_vatid"] = $_SESSION["customer_invoice_address"]->vat_id;
                $_SESSION["visitor_name_2"] = '';
            }

            $_SESSION["visitor_address"] = $_SESSION["customer_invoice_address"]->address;
            $_SESSION["visitor_address_2"] = $_SESSION["customer_invoice_address"]->address_2;
            //$_SESSION["visitor_address_no"] = $_SESSION["customer_invoice_address"]->name;
            $_SESSION["visitor_city"] = $_SESSION["customer_invoice_address"]->city;
            $_SESSION["visitor_post_code"] = $_SESSION["customer_invoice_address"]->post_code;
            $_SESSION["visitor_email"] = $GLOBALS['shop_user']['email'];
            // $_SESSION["visitor_telephone"] = $_POST["input_phone_no"];
            $_SESSION["visitor_customer_no"] = $_SESSION["customer_invoice_address"]->customer_no;
            // $_SESSION["visitor_birthday"] = $_POST["input_birthday"];
            // $_SESSION["address_is_shipping_address"] = $_POST["input_shipping_is_invoice"];
            $_SESSION["visitor_country"] = $_SESSION["customer_invoice_address"]->country;
             if($_SESSION["visitor_country"] == '') {
                 $_SESSION["visitor_country"] = "DE";
             }
            //$_SESSION['visitor_salutation_shipping'] = $_POST['input_salutation_shipping'];
            //$_SESSION['visitor_title_shipping'] = $_POST['input_title_shipping'];

            if ((bool)$_SESSION["customer_shipment_address"]->is_packstation) {

                $_SESSION["visitor_surname_shipping_packstation"] = $_SESSION["customer_shipment_address"]->sur_name;
                $_SESSION["visitor_name_shipping_packstation"] = $_SESSION["customer_shipment_address"]->name;
                $_SESSION["visitor_lastname_shipping_packstation"] = $_SESSION["customer_shipment_address"]->last_name;
                $_SESSION["visitor_company_shipping_packstation"] = $_SESSION["customer_shipment_address"]->name_2;
                $_SESSION["visitor_user_street_shipping_packstation"] = $_SESSION["customer_shipment_address"]->address;
                $_SESSION["visitor_post_code_shipping_packstation"] = $_SESSION["customer_shipment_address"]->post_code;
                $_SESSION["visitor_city_shipping_packstation"] = $_SESSION["customer_shipment_address"]->city;
                $_SESSION["visitor_packstation_address"] = 1;
                $_SESSION["visitor_company_shipping"] = '';
                $_SESSION["input_is_company"] = 0;

            } else {
                $_SESSION["visitor_name_shipping"] = $_SESSION["customer_shipment_address"]->sur_name . " " . $_SESSION["customer_shipment_address"]->last_name;
                $_SESSION["visitor_surname_shipping"] = $_SESSION["customer_shipment_address"]->sur_name;
                $_SESSION["visitor_lastname_shipping"] = $_SESSION["customer_shipment_address"]->last_name;

                if ($_SESSION["customer_shipment_address"]->is_company) {
                    $_SESSION["visitor_company_shipping"] = $_SESSION["customer_shipment_address"]->name;
                    $_SESSION["input_is_company"] = 1;
                } else {
                    $_SESSION["visitor_company_shipping"] = '';
                    $_SESSION["input_is_company"] = 0;
                }
                $_SESSION["visitor_user_street_shipping"] = $_SESSION["customer_shipment_address"]->address;
                // $_SESSION["visitor_user_street_no_shipping"] = $_SESSION["customer_shipment_address"]->sur_name;
                $_SESSION["visitor_user_street_2_shipping"] = $_SESSION["customer_shipment_address"]->address_2;
                $_SESSION["visitor_post_code_shipping"] = $_SESSION["customer_shipment_address"]->post_code;
                $_SESSION["visitor_city_shipping"] = $_SESSION["customer_shipment_address"]->city;
                if(!is_null($_SESSION["customer_shipment_address"])) {
                    $_SESSION["visitor_country_shipping"] = $_SESSION["customer_shipment_address"]->getCountry();
                } else {
                    $_SESSION["visitor_country_shipping"] = $_SESSION["customer_shipment_address"]->country;
                    if(empty($_SESSION['visitor_country_shipping'])) {
                        $_SESSION["visitor_country_shipping"] = 'DE';
                    }
                }
            }
        } else {

            $_SESSION["visitor_radio_shipping_is_packstation"] = $_POST["input_shipping_is_packstation"];
            $_SESSION["visitor_radio_shipping_is_not_invoice"] = $_POST["input_shipping_is_not_invoice"];
            if (isset($_POST["input_name"]) || $_POST["input_surname"]) {
                $_SESSION['visitor_salutation'] = $_POST['input_salutation'];
                $_SESSION['visitor_title'] = $_POST['input_title'];
                //SH 13.12.2013 salutation_title
                $_SESSION['visitor_salutation_title'] = ($_SESSION['visitor_title'] != '') ? $_POST['input_salutation'] . ' ' . $_POST['input_title'] : $_POST['input_salutation'];
                $_SESSION["visitor_name"] = $_POST["input_surname"] . " " . $_POST["input_lastname"];
                $_SESSION["visitor_surname"] = $_POST["input_surname"];
                $_SESSION["visitor_lastname"] = $_POST["input_lastname"];
                $_SESSION["visitor_company"] = $_POST["input_company"];
                $_SESSION["visitor_vatid"] = $_POST["input_vatid"];
                $_SESSION["visitor_address"] = stripslashes($_POST["input_user_street"]);
                $_SESSION["visitor_address_2"] = stripslashes($_POST["input_address_2"]);
                $_SESSION["visitor_address_no"] = stripslashes($_POST["input_user_street_no"]);
                $_SESSION["visitor_city"] = $_POST["input_city"];
                $_SESSION["visitor_post_code"] = $_POST["input_post_code"];
                $_SESSION["visitor_email"] = $_POST["input_email"];
                $_SESSION["visitor_telephone"] = $_POST["input_phone_no"];
                $_SESSION["visitor_customer_no"] = $_POST["input_shop_customer_no"];
                $_SESSION["visitor_birthday"] = $_POST["input_birthday"];
                $_SESSION["address_is_shipping_address"] = $_POST["input_shipping_is_invoice"];
                $_SESSION["visitor_country"] = $_POST["input_country"];
                $_SESSION['visitor_salutation_shipping'] = $_POST['input_salutation_shipping'];
                $_SESSION['visitor_title_shipping'] = $_POST['input_title_shipping'];
                $_SESSION["visitor_name_shipping"] = $_POST["input_surname_shipping"] . " " . $_POST["input_lastname_shipping"];
                $_SESSION["visitor_surname_shipping"] = $_POST["input_surname_shipping"];
                $_SESSION["visitor_lastname_shipping"] = $_POST["input_lastname_shipping"];
                $_SESSION["visitor_company_shipping"] = $_POST["input_company_shipping"];
                $_SESSION["visitor_user_street_shipping"] = stripslashes($_POST["input_user_street_shipping"]);
                $_SESSION["visitor_user_street_no_shipping"] = stripslashes($_POST["input_user_street_no_shipping"]);
                $_SESSION["visitor_user_street_2_shipping"] = stripslashes($_POST["input_address_2_shipping"]);
                $_SESSION["visitor_post_code_shipping"] = $_POST["input_post_code_shipping"];
                $_SESSION["visitor_city_shipping"] = $_POST["input_city_shipping"];
                $_SESSION["visitor_country_shipping"] = $_POST["input_shop_country_shipping"];
                $_SESSION["visitor_shipment_address_id"] = $_POST["input_shipment_address_id"];
                $_SESSION["visitor_new_shipping_address"] = $_POST["input_shipping_is_not_invoice"];
                $_SESSION["visitor_packstation_address"] = $_POST["input_shipping_is_packstation"];
                //$_SESSION["visitor_radio_shipping_is_packstation"] = $_POST["input_shipping_is_packstation"];
                //$_SESSION["visitor_radio_shipping_is_not_invoice"] = $_POST["input_shipping_is_not_invoice"];
                $_SESSION["input_is_company"] = $_POST["input_is_company"];
                //TF 22.10.2013 - Packstation
                $_SESSION["visitor_surname_shipping_packstation"] = $_POST["input_surname_shipping_packstation"];
                $_SESSION["visitor_name_shipping_packstation"] = $_POST["input_surname_shipping_packstation"] . " " . $_POST["input_lastname_shipping_packstation"];
                $_SESSION["visitor_lastname_shipping_packstation"] = $_POST["input_lastname_shipping_packstation"];
                $_SESSION["visitor_company_shipping_packstation"] = $_POST["input_company_shipping_packstation"];
                $_SESSION["visitor_user_street_shipping_packstation"] = stripslashes($_POST["input_user_street_shipping_packstation"]);
                $_SESSION["visitor_post_code_shipping_packstation"] = $_POST["input_post_code_shipping_packstation"];
                $_SESSION["visitor_city_shipping_packstation"] = $_POST["input_city_shipping_packstation"];
                // ---
                if ($GLOBALS["shop_customer"]["customer_no"] != "" && $_POST['input_shipping_is_not_invoice'] != "on" && $_POST['input_shipment_address_id'] != 0) {
                    $shipment_address = get_shipment_address($_SESSION["visitor_shipment_address_id"]);
                    $match_found = preg_match('/[0-9]+\s*[a-zA-Z-]*/', $shipment_address["address"], $ship_street_no);
                    $_SESSION['visitor_name_shipping'] = $shipment_address["surname"] . " " . $shipment_address["lastname"];
                    $_SESSION['visitor_surname_shipping'] = $shipment_address["surname"];
                    $_SESSION['visitor_lastname_shipping'] = $shipment_address["lastname"];
                    $_SESSION['visitor_company_shipping'] = $shipment_address["company_name"];
                    $_SESSION['visitor_user_street_shipping'] = trim(
                        str_replace($ship_street_no[0], "", $shipment_address["address"])
                    );
                    $_SESSION["visitor_user_street_no_shipping"] = $ship_street_no[0];
                    $_SESSION['visitor_post_code_shipping'] = $shipment_address["post_code"];
                    $_SESSION['visitor_city_shipping'] = $shipment_address["city"];
                    $_SESSION['visitor_country_shipping'] = $shipment_address["country"];
                }
            }
        }


    } elseif ($page == "step2" && $_GET['payment_error'] != 1) {
        $_SESSION["shipping_line_no"] = (isset($_POST["input_shipping_line_no"])) ? $_POST["input_shipping_line_no"] : $_SESSION["shipping_line_no"];
        $_SESSION["payment_line_no"] = (isset($_POST["input_payment_line_no"])) ? $_POST["input_payment_line_no"] : $_SESSION["payment_line_no"];
        $_SESSION["account_no"] = (isset($_POST["input_account_no"])) ? $_POST["input_account_no"] : $_SESSION["account_no"];
        $_SESSION["bank_no"] = (isset($_POST["input_bank_no"])) ? $_POST["input_bank_no"] : $_SESSION["bank_no"];
        $_SESSION["bank_name"] = (isset($_POST["input_bank_name"])) ? $_POST["input_bank_name"] : $_SESSION["bank_name"];
        $_SESSION['bonus_code'] = (isset($_POST['input_bonus'])) ? mb_strtolower(
            $_POST['input_bonus'],
            'UTF-8'
        ) : $_SESSION['bonus_code'];
        $_SESSION["your_comment"] = (isset($_POST['input_your_comment'])) ? str_replace(
            "\\r\\n",
            " ",
            $_POST["input_your_comment"]
        ) : $_SESSION["your_comment"];
        $_SESSION["visitor_birthday"] = (isset($_POST["input_birthday"])) ? $_POST["input_birthday"] : $_SESSION["visitor_birthday"];
    } elseif ($page == "start") {
        $_SESSION["order_account_type"] = $_POST["input_order_user_account"];
    } elseif ($page == "customer_data_save") {
        $_SESSION["visitor_name"] = $_POST["input_customer_surname"] . " " . $_POST["input_customer_lastname"];
        $_SESSION["visitor_surname"] = $_POST["input_customer_surname"];
        $_SESSION["visitor_lastname"] = $_POST["input_customer_lastname"];
        $_SESSION["visitor_name_2"] = $_POST["input_customer_name_2"];
        $_SESSION["visitor_address"] = stripslashes($_POST["input_shop_customer_address"]);
        $_SESSION["visitor_address_2"] = stripslashes($_POST["input_shop_customer_address_2"]);
        $_SESSION["visitor_address_no"] = $_POST["input_shop_customer_street_no"];
        $_SESSION["visitor_city"] = $_POST["input_shop_customer_city"];
        $_SESSION["visitor_post_code"] = $_POST["input_shop_customer_post_code"];
        $_SESSION["visitor_email"] = $_POST["input_shop_customer_email"];
        $_SESSION["visitor_telephone"] = $_POST["input_shop_customer_phone_no"];
        $_SESSION["visitor_customer_no"] = $_POST["input_shop_customer_no"];
        $_SESSION["visitor_birthday"] = $_POST["input_shop_customer_birthday"];
        $_SESSION["address_is_shipping_address"] = $_POST["input_shipping_is_invoice"];
        $_SESSION["visitor_country"] = $_POST["input_shop_customer_country"];
    }
}

function get_main_visitor_data_b2c($page)
{
    if ($page == "step1") {
        if ($GLOBALS["shop_customer"]["customer_no"] != "" && $_SESSION['visitor_name'] == '') {
            $match_found = preg_match('/[0-9]+\s*[a-zA-Z-]*/', $GLOBALS['shop_customer']['address'], $cust_street_no);
            $ret_val["visitor_name"] = $GLOBALS['shop_user']['name'];
            $ret_val["visitor_surname"] = $GLOBALS['shop_customer']['surname'];
            $ret_val["visitor_lastname"] = $GLOBALS['shop_customer']['lastname'];
            $ret_val["visitor_company"] = (isset($_SESSION["visitor_company"]) ? $_SESSION["visitor_company"] : $GLOBALS['shop_customer']["company_name"]);
            $ret_val["visitor_vatid"] = $_SESSION["visitor_vatid"];
            $ret_val["visitor_shipment_address_id"] = $GLOBALS['shop_user']['shop_shipment_address_id'];
            $ret_val["visitor_shipping_new_address"] = $_SESSION["visitor_new_shipping_address"];
            //$ret_val["visitor_salutation"] = $_SESSION["visitor_salutation"];
            $ret_val["visitor_salutation"] = $GLOBALS['shop_customer']['salutation'];
            $ret_val["visitor_address"] = $GLOBALS["shop_customer"]["address_street"];
            $ret_val["visitor_address_no"] = $GLOBALS["shop_customer"]["address_no"];
            $ret_val["visitor_address_2"] = $GLOBALS["shop_customer"]["address_2"];
            $ret_val["visitor_city"] = $GLOBALS['shop_customer']['city'];
            $ret_val["visitor_post_code"] = $GLOBALS['shop_customer']['post_code'];
            $ret_val["visitor_email"] = $GLOBALS['shop_user']['email'];
            $ret_val["visitor_telephone"] = $GLOBALS['shop_customer']['phone_no'];
            $ret_val["visitor_customer_no"] = $_SESSION["visitor_customer_no"];
            $ret_val["visitor_birthday"] = $_SESSION["visitor_birthday"];
            $ret_val["visitor_country"] = $GLOBALS['shop_customer']['country'];
            $ret_val["visitor_is_company"] = ($GLOBALS['shop_customer']["company_name"] !== '') ? 1 : 0;
        } else {
            $ret_val["visitor_name"] = $_SESSION["visitor_name"];
            $ret_val["visitor_surname"] = $_SESSION['visitor_surname'];
            $ret_val["visitor_lastname"] = $_SESSION['visitor_lastname'];
            $ret_val["visitor_company"] = $_SESSION["visitor_company"];
            $ret_val["visitor_vatid"] = $_SESSION["visitor_vatid"];
            $ret_val["visitor_shipment_address_id"] = $_SESSION["visitor_shipment_address_id"];
            $ret_val["visitor_shipping_new_address"] = $_SESSION["visitor_new_shipping_address"];
            $ret_val["visitor_salutation"] = $_SESSION["visitor_salutation"];
            $ret_val["visitor_address"] = $_SESSION["visitor_address"];
            $ret_val["visitor_address_2"] = $_SESSION["visitor_address_2"];
            $ret_val["visitor_address_no"] = $_SESSION["visitor_address_no"];
            $ret_val["visitor_city"] = $_SESSION["visitor_city"];
            $ret_val["visitor_post_code"] = $_SESSION["visitor_post_code"];
            $ret_val["visitor_email"] = $_SESSION["visitor_email"];
            $ret_val["visitor_telephone"] = $_SESSION["visitor_telephone"];
            $ret_val["visitor_customer_no"] = $_SESSION["visitor_customer_no"];
            $ret_val["visitor_birthday"] = $_SESSION["visitor_birthday"];
            $ret_val["visitor_country"] = $_SESSION["visitor_country"];
            $ret_val["visitor_is_company"] = ($_SESSION["visitor_is_company"] == 'on') ? 1 : 0;
        }
        $ret_val["address_is_shipping_address"] = $_SESSION["address_is_shipping_address"];
        if ($_SESSION["address_is_shipping_address"] != "on" || (($GLOBALS["shop_customer"]["customer_no"] != "") && ($_SESSION["visitor_new_shipping_address"] == "on"))) {
            if ($GLOBALS["shop_customer"]["customer_no"] != "") {
                $ret_val["visitor_name_shipping"] = $_SESSION["visitor_name_shipping"];
                $ret_val["visitor_surname_shipping"] = $_SESSION["visitor_surname_shipping"];
                $ret_val["visitor_lastname_shipping"] = $_SESSION["visitor_lastname_shipping"];
                $ret_val["visitor_company_shipping"] = $_SESSION["visitor_company_shipping"];
            } else {
                $ret_val["visitor_name_shipping"] = $_SESSION["visitor_name_shipping"];
                $ret_val["visitor_surname_shipping"] = $_SESSION["visitor_surname_shipping"];
                $ret_val["visitor_lastname_shipping"] = $_SESSION["visitor_lastname_shipping"];
                $ret_val["visitor_company_shipping"] = $_SESSION["visitor_company_shipping"];
            };
            $ret_val["visitor_address_shipping"] = $_SESSION["visitor_user_street_shipping"];
            $ret_val["visitor_address_2_shipping"] = $_SESSION["visitor_user_street_2_shipping"];
            $ret_val["visitor_address_no_shipping"] = $_SESSION["visitor_user_street_no_shipping"];
            $ret_val["visitor_city_shipping"] = $_SESSION["visitor_city_shipping"];
            $ret_val["visitor_post_code_shipping"] = $_SESSION["visitor_post_code_shipping"];
            $ret_val["visitor_country_shipping"] = $_SESSION["visitor_country_shipping"];
        }
        //Packstation
        if ($_SESSION["address_is_shipping_address"] != "on" || (($GLOBALS["shop_customer"]["customer_no"] != "") && ($_SESSION["input_shipping_is_packstation"] == "on"))) {
            $ret_val["visitor_name_shipping_packstation"] = $_SESSION["visitor_name_shipping_packstation"];
            $ret_val["visitor_surname_shipping_packstation"] = $_SESSION["visitor_surname_shipping_packstation"];
            $ret_val["visitor_lastname_shipping_packstation"] = $_SESSION["visitor_lastname_shipping_packstation"];
            $ret_val["visitor_company_shipping_packstation"] = $_SESSION["visitor_company_shipping_packstation"];
            $ret_val["visitor_address_shipping_packstation"] = $_SESSION["visitor_user_street_shipping_packstation"];
            $ret_val["visitor_city_shipping_packstation"] = $_SESSION["visitor_city_shipping_packstation"];
            $ret_val["visitor_post_code_shipping_packstation"] = $_SESSION["visitor_post_code_shipping_packstation"];
            $ret_val["visitor_country_shipping"] = $_SESSION["visitor_country_shipping"];
        }
        if (($GLOBALS["shop_customer"]["customer_no"] != "") && ($_SESSION["visitor_surname"] == "") && (($_SESSION["order_previous_step"] == "basket" || $_GET['action'] == 'shop_login'))) {
            $match_found = preg_match('/[0-9]+\s*[a-zA-Z-]*/', $GLOBALS['shop_customer']['address'], $cust_street_no);
            $ret_val["visitor_name"] = $GLOBALS["shop_customer"]["name"];
            $ret_val["visitor_name_2"] = $GLOBALS["shop_customer"]["name_2"];
            $ret_val["visitor_address"] = $GLOBALS["shop_customer"]["address_street"];
            $ret_val["visitor_address_2"] = $GLOBALS["shop_customer"]["name_2"];
            $ret_val["visitor_address_no"] = $GLOBALS["shop_customer"]["address_no"];
            $ret_val["visitor_city"] = $GLOBALS["shop_customer"]["city"];
            $ret_val["visitor_post_code"] = $GLOBALS["shop_customer"]["post_code"];
            $ret_val["visitor_email"] = $GLOBALS["shop_customer"]["email"];
            $ret_val["visitor_telephone"] = $GLOBALS["shop_customer"]["phone_no"];
            $ret_val["visitor_customer_no"] = $GLOBALS["shop_customer"]["customer_no"];;
            $ret_val["visitor_birthday"] = datefromsql($GLOBALS["shop_customer"]["birthday"]);
            $ret_val["visitor_country"] = $GLOBALS["shop_customer"]["country"];
            $ret_val["visitor_shipment_address_id"] = $_SESSION["visitor_shipment_address_id"];
            $ret_val["visitor_shipping_new_address"] = $_SESSION["visitor_new_shipping_address"];
        }

    } elseif ($page == "step2") {
        $ret_val["input_dispatch_type"] = $_SESSION["dispatch_type"];
        $ret_val["input_payment_line_no"] = $_SESSION["payment_line_no"];
        $ret_val["input_your_comment"] = $_SESSION["your_comment"];
        $ret_val["input_account_no"] = $_SESSION["account_no"];
        $ret_val["input_bank_no"] = $_SESSION["bank_no"];
        $ret_val["input_bank_name"] = $_SESSION["bank_name"];
    }
    return $ret_val;
}

function check_date($date, $format, $sep, $type = 0)
{

    //$type = 0 => gewöhnliches Datum
    //$type = 1 => Gültigkeitsdatum der Kreditkarte
    date_default_timezone_set('Europe/Berlin');
    if ($type == 1) {
        $pos1 = strpos($format, 'm');
        $pos2 = strpos($format, 'y');

        $check = explode($sep, $date);

        if (\DynCom\Compat\Compat::count($check) <= 1) {
            return false;
        } else {

            switch (strlen($check[$pos2])) {
                case 2:
                    $check_year = date("y");
                    break;
                case 4:
                    $check_year = date("Y");
                    break;
                default:
                    return false;
                    break;
            }

            if ($check[$pos2] > $check_year) {
                return true;
            } else {
                if ($check[$pos2] == $check_year & $check[$pos1] > date("m")) {
                    return true;
                } else {
                    return false;
                }
            }
        }
    } else {
        $pos1 = strpos($format, 'd');
        $pos2 = strpos($format, 'm');
        $pos3 = strpos($format, 'Y');

        $check = explode($sep, $date);

        if (strlen($check[$pos3]) < 4 || $check[$pos3] > date("Y")) {
            return false;
        } else {
            return checkdate($check[$pos2], $check[$pos1], $check[$pos3]);
        }
    }
}

//Holt Meta-Beschreibung für Artikel. Sollte keine hinterlegt sein wird zunächst eine Abfrage für das Parent Item (falls vorhanden) getätigt,
//sollte hier nichts hinterlegt sein wird die Beschreibung des Artikels ausgegeben. Sollte hier nichts hinterlegt sein wird nach einer Meta_Description der Category gesucht. Sollte auch hier keine Meta-Beschreibung hinterlegt sein,
//wird die für den main_navigation gültige Meta-Beschreibung ausgegeben.
function get_meta_description()
{
    $card = (int)$_GET['card'];
    $company = $GLOBALS['shop']['company'];
    $shop = $GLOBALS['shop']['item_source'];
    $language = $GLOBALS['shop_language']['code'];
    $navigation_id = $GLOBALS['navigation']['id'];
    $category = $GLOBALS['category'];
    $GLOBALS['item'] = '';
    IF ($card > 0) {
        $item = get_item_by_card_id(
            $GLOBALS['shop']['company'],
            $GLOBALS['shop']['item_source'],
            $GLOBALS['shop_language']['code'],
            $card
        );
        $GLOBALS['item'] = $item;
        $query_1 = "SELECT meta_description, meta_description_webform, item_no, parent_item_no FROM shop_view_active_item WHERE id = '" . $card . "' AND company = '" . $company . "' AND shop_code = '" . $shop . "' AND language_code = '" . $language . "'";
        $result_1 = mysqli_query($GLOBALS['mysql_con'], $query_1);
        $row = mysqli_fetch_assoc($result_1);
        if ($row['meta_description_webform']) {
            $short_desc = $row['meta_description_webform'];
        } else $short_desc = $row["meta_description"];
        $item_no = $item["item_no"];
        $parent_item_no = $item["parent_item_no"];
        IF ($short_desc <> '') {
            RETURN $short_desc;
        } ELSE {
            IF ($parent_item_no <> '') {
                $query_2 = "SELECT meta_description, meta_description_webform FROM shop_view_active_item WHERE item_no = '" . $parent_item_no . "' AND company = '" . $company . "' AND shop_code = '" . $shop . "' AND language_code = '" . $language . "'";
                $result_2 = mysqli_query($GLOBALS['mysql_con'], $query_2);
                $row = mysqli_fetch_assoc($result_2);
                if ($row['meta_description_webform']) {
                    $short_desc = $row['meta_description_webform'];
                } else $short_desc = $row["meta_description"];
                IF ($short_desc <> '') {
                    RETURN $short_desc;
                } ELSE {
                    $query_3 = "SELECT content FROM shop_item_description WHERE item_no = '" . $parent_item_no . "' AND content != '' AND company = '" . $company . "' AND shop_code = '" . $shop . "' AND language_code = '" . $language . "' AND marketplace_only = 0 ORDER BY line_no ASC LIMIT 1";
                    $result_3 = mysqli_query($GLOBALS['mysql_con'], $query_3);
                    $long_desc = mysqli_fetch_assoc($result_3);
                    $long_desc = $long_desc['content'];
                    IF ($long_desc <> '') {
                        $long_desc = strip_tags($long_desc);
                        $long_desc = str_replace(array('\r\n', '\r', '\n'), ' ', $long_desc);
                        RETURN $long_desc;
                    }
                }
            } ELSE {
                $query_3 = "SELECT content FROM shop_item_description WHERE item_no = '" . $item_no . "' AND company = '" . $company . "' AND shop_code = '" . $shop . "' AND language_code = '" . $language . "' AND marketplace_only = 0 ORDER BY line_no ASC LIMIT 1";
                $result_3 = mysqli_query($GLOBALS['mysql_con'], $query_3);
                $long_desc = mysqli_fetch_assoc($result_3);
                $long_desc = strip_tags($long_desc["content"]);
                $long_desc = str_replace(array('\r\n', '\r', '\n'), ' ', $long_desc);
                IF ($long_desc <> '') {
                    RETURN $long_desc;
                } ELSE {
                    $query_4 = "SELECT sc.meta_description, sc.parent_line_no FROM shop_category sc, shop_item_has_category sihc WHERE sihc.company = '" . $company . "' AND sihc.shop_code = '" . $GLOBALS["shop"]["category_source"] . "' AND sihc.language_code = '" . $language . "' AND sc.line_no = sihc.category_line_no AND sc.company = '" . $company . "' AND sc.shop_code = '" . $shop . "' AND sc.language_code = '" . $language . "'";
                    $result_4 = mysqli_query($GLOBALS['mysql_con'], $query_4);
                    $row = mysqli_fetch_assoc($result_4);
                    $category_meta_desc = $row["meta_description"];
                    IF ($category_meta_desc <> '') {
                        RETURN $category_meta_desc;
                    } ELSE {
                        IF ($row["parent_line_no"] <> 0) {
                            $category_meta_desc = get_category_meta_desc_rek($row["parent_line_no"]);
                            IF ($category_meta_desc !== false) {
                                RETURN $category_meta_desc;
                            } ELSE {
                                $query_5 = "SELECT meta_description FROM main_navigation WHERE id = '" . $navigation_id . "'";
                                $result_5 = mysqli_query($GLOBALS['mysql_con'], $query_5);
                                $nav_meta_desc = mysqli_result($result_5, 0);
                                IF ($nav_meta_desc <> '') {
                                    RETURN $nav_meta_desc;
                                } ELSE {
                                    $query_6 = "SELECT meta_description FROM main_language WHERE id = " . $GLOBALS["language"]["id"];
                                    $result_6 = mysqli_query($GLOBALS['mysql_con'], $query_6);
                                    $lang_meta_desc = mysqli_result($result_6, 0);
                                    IF ($lang_meta_desc <> '') {
                                        RETURN $lang_meta_desc;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    } ELSE {
        IF (!$category['meta_description_webform']) {
            IF ($category["meta_description"] <> '') {
                RETURN $category["meta_description"];
            }
        } ELSEIF ($category['meta_description_webform']) {
            RETURN $category['meta_description_webform'];
        } ELSE {
            IF ($category["parent_line_no"] <> 0) {
                $category_meta_desc = get_category_meta_desc_rek($category["parent_line_no"]);
                IF ($category_meta_desc !== (bool)false) {
                    RETURN $category_meta_desc;
                } ELSE {
                    $query_5 = "SELECT meta_description FROM main_navigation WHERE id = '" . $navigation_id . "'";
                    $result_5 = mysqli_query($GLOBALS['mysql_con'], $query_5);
                    $nav_meta_desc = mysqli_result($result_5, 0);
                    IF ($nav_meta_desc <> '') {
                        RETURN $nav_meta_desc;
                    } ELSE {
                        $query_6 = "SELECT meta_description FROM main_language WHERE id = " . $GLOBALS["language"]["id"];
                        $result_6 = mysqli_query($GLOBALS['mysql_con'], $query_6);
                        $lang_meta_desc = mysqli_result($result_6, 0);
                        IF ($lang_meta_desc <> '') {
                            RETURN $lang_meta_desc;
                        }
                    }
                }
            } ELSE {
                $query_5 = "SELECT meta_description FROM main_navigation WHERE id = '" . $navigation_id . "'";
                $result_5 = mysqli_query($GLOBALS['mysql_con'], $query_5);
                $nav_meta_desc = mysqli_result($result_5, 0);
                IF ($nav_meta_desc <> '') {
                    RETURN $nav_meta_desc;
                } ELSE {
                    $query_6 = "SELECT meta_description FROM main_language WHERE id = " . $GLOBALS["language"]["id"];
                    $result_6 = mysqli_query($GLOBALS['mysql_con'], $query_6);
                    $lang_meta_desc = mysqli_result($result_6, 0);
                    IF ($lang_meta_desc <> '') {
                        RETURN $lang_meta_desc;
                    }
                }

            }
        }
        RETURN $category['name'];
    }
}

//Holt zu Artikel (/Kategorie/Seite) verfügbare Keywords und begrenzt diese auf 250 Zeichen zur Verwendung als Meta-Keywords
function get_meta_keywords()
{
    $card = (int)$_GET['card'];
    $company = $GLOBALS['shop']['company'];
    $shop = $GLOBALS['shop']['code'];
    $language = $GLOBALS['shop_language']['code'];
    $navigation_id = $GLOBALS['navigation']['id'];
    $category = $GLOBALS['category'];
    IF ($card <> '') {
        $query_1 = "SELECT meta_keywords, item_no, parent_item_no FROM shop_view_active_item WHERE id = '" . $card . "' AND company = '" . $company . "' AND shop_code = '" . $shop . "' AND language_code = '" . $language . "'";
        $result_1 = mysqli_query($GLOBALS['mysql_con'], $query_1);
        $row = mysqli_fetch_assoc($result_1);
        $item_meta_keywords = $row["meta_keywords"];
        $item_no = $row["item_no"];
        $parent_item_no = $row["parent_item_no"];
        IF ($item_meta_keywords <> '') {
            RETURN $item_meta_keywords;
        } ELSE {
            $query_2 = "SELECT meta_keywords FROM shop_view_active_item WHERE id = " . $parent_item_no . " AND company = '" . $company . "' AND shop_code = '" . $shop . "' AND language_code = '" . $language . "'";
            $result_2 = mysqli_query($GLOBALS['mysql_con'], $query_2);
            $item_meta_keywords = mysqli_result($result_2, 0);
            IF ($item_meta_keywords <> '') {
                RETURN $item_meta_keywords;
            } ELSE {
                $query_4 = "SELECT sc.meta_keywords, sc.parent_line_no FROM shop_category sc, shop_item_has_category sihc WHERE sihc.company = '" . $company . "' AND sihc.shop_code = '" . $GLOBALS["shop"]["category_source"] . "' AND sihc.language_code = '" . $language . "' AND sc.line_no = sihc.category_line_no AND sc.company = '" . $company . "' AND sc.shop_code = '" . $shop . "' AND sc.language_code = '" . $language . "'";
                $result_4 = mysqli_query($GLOBALS['mysql_con'], $query_4);
                $row = mysqli_fetch_assoc($result_4);
                $category_meta_keywords = $row["meta_keywords"];
                IF ($category_meta_keywords <> '') {
                    RETURN $category_meta_keywords;
                } ELSE {
                    IF ($row["parent_line_no"] <> 0) {
                        $category_meta_keyw = get_category_meta_keyw_rek($row["parent_line_no"]);
                        IF ($category_meta_keyw !== false) {
                            RETURN $category_meta_keyw;
                        } ELSE {
                            $query_5 = "SELECT meta_keywords FROM main_navigation WHERE id = " . $navigation_id;
                            $result_5 = mysqli_query($GLOBALS['mysql_con'], $query_5);
                            $nav_meta_keywords = mysqli_result($result_5, 0);
                            IF ($nav_meta_keywords <> '') {
                                RETURN $nav_meta_keywords;
                            } ELSE {
                                $query_6 = "SELECT meta_keywords FROM main_language WHERE id = " . $GLOBALS["language"]["id"];
                                $result_6 = mysqli_query($GLOBALS['mysql_con'], $query_6);
                                $lang_meta_desc = mysqli_result($result_6, 0);
                                IF ($lang_meta_desc <> '') {
                                    RETURN $lang_meta_desc;
                                }
                            }
                        }
                    }
                }
            }
        }
    } ELSE {
        IF ($category["meta_keywords"] <> '') {
            RETURN $category["meta_keywords"];
        } ELSE {
            IF ($category["parent_line_no"] <> 0) {
                $category_meta_keyw = get_category_meta_keyw_rek($category["parent_line_no"]);
                IF ($category_meta_keyw !== false) {
                    RETURN $category_meta_keyw;
                } ELSE {
                    $query_5 = "SELECT meta_keywords FROM main_navigation WHERE id = " . $navigation_id;
                    $result_5 = mysqli_query($GLOBALS['mysql_con'], $query_5);
                    $nav_meta_keywords = mysqli_result($result_5, 0);
                    IF ($nav_meta_keywords <> '') {
                        RETURN $nav_meta_keywords;
                    } ELSE {
                        $query_6 = "SELECT meta_keywords FROM main_language WHERE id = " . $GLOBALS["language"]["id"];
                        $result_6 = mysqli_query($GLOBALS['mysql_con'], $query_6);
                        $lang_meta_keyw = mysqli_result($result_6, 0);
                        IF ($lang_meta_keyw <> '') {
                            RETURN $lang_meta_keyw;
                        }
                    }
                }
            } ELSE {
                $query_5 = "SELECT meta_keywords FROM main_navigation WHERE id = " . $navigation_id;
                $result_5 = mysqli_query($GLOBALS['mysql_con'], $query_5);
                $nav_meta_keywords = mysqli_result($result_5, 0);
                IF ($nav_meta_keywords <> '') {
                    RETURN $nav_meta_keywords;
                } ELSE {
                    $query_6 = "SELECT meta_keywords FROM main_language WHERE id = " . $GLOBALS["language"]["id"];
                    $result_6 = mysqli_query($GLOBALS['mysql_con'], $query_6);
                    $lang_meta_keyw = mysqli_result($result_6, 0);
                    IF ($lang_meta_keyw <> '') {
                        RETURN $lang_meta_keyw;
                    }
                }
            }
        }
    }
}

function get_category_meta_desc_rek($line_no)
{
    $value = false;
    $query = "SELECT * FROM shop_category WHERE company = '" . $GLOBALS["shop"]['company'] . "' AND shop_code = '" . $GLOBALS["shop"]['category_source'] . "' AND language_code = '" . $GLOBALS["shop_language"]['code'] . "' AND line_no = " . $line_no;
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $row = mysqli_fetch_assoc($result);
    IF ($row["meta_description"] <> '') {
        $value = htmlspecialchars($row["meta_description"], ENT_QUOTES, "UTF-8");
    } ELSE {
        IF ($row["parent_line_no"] <> 0) {
            $value = get_category_meta_desc_rek($row["parent_line_no"]);
        } ELSE {
            $value = false;
        }
    }
    return $value;
}

function get_category_meta_keyw_rek($line_no)
{
    $query = "SELECT * FROM shop_category WHERE company = '" . $GLOBALS["shop"]['company'] . "' AND shop_code = '" . $GLOBALS["shop"]['category_source'] . "' AND language_code = '" . $GLOBALS["shop_language"]['code'] . "' AND line_no = '" . $line_no . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $row = mysqli_fetch_assoc($result);
    IF ($row["meta_keywords"] <> '') {
        RETURN $row["meta_keywords"];
    } ELSE {
        IF ($row["parent_line_no"] <> 0) {
            get_category_meta_keyw_rek($row["parent_line_no"]);
        } ELSE {
            RETURN false;
        }
    }
}

function shop_get_meta_data()
{

}

function calculate_coupon($coupon_code, $basket_amount)
{

    $IOCContainer = $GLOBALS['IOC'];
    /** @var GenericUserBasket $currUserBasket */
    $currUserBasket = $IOCContainer->create('$CurrUserBasket');
    $pdo = get_main_db_pdo_from_env_single_instance();

    $cat_coupon_item_exists = false;
    $errormessage = "";
    if ($_SESSION['coupon']['used'] == 1) {
        $errormessage = $GLOBALS['tc']['coupon_limit'];
        //echo("<div class='errorbox'>" . $GLOBALS['tc']['coupon_limit'] . "</div>");
    } else {

        $query = "SELECT scl.*, scll.category_coupon, scll.category_line_no, scsl.shipping_coupon_type, scsl.shipping_coupon_value
				  FROM shop_coupon_line scl
				  right join shop_coupon_group_link scgl
				  ON scgl.company = scl.company AND scgl.shop_code = '" . $GLOBALS['shop']['code'] . "' AND scgl.coupon_group = scl.coupon_group
				  left join shop_coupon_line_link scll
				  ON scl.company = scll.company AND scl.code = scll.code AND scl.coupon_code = scll.coupon_code
				  left join shop_coupon_shipping_link scsl
				  on scl.company = scsl.company AND scl.code = scsl.code AND scl.coupon_code = scsl.coupon_code
				  WHERE scl.coupon_code = '" . $coupon_code . "'
				  AND (scl.valid_from <= CURDATE() OR scl.valid_from = '0000-00-00')
				  AND (scl.valid_to >= CURDATE() OR scl.valid_to = '0000-00-00')
				  AND (scl.customer_no = '" . $GLOBALS['shop_customer']['customer_no'] . "' OR scl.customer_no = '')
				  AND scl.to_delete = 0
				  AND scl.active = 1
				  AND scl.company = '" . $GLOBALS['shop']['company'] . "'
				  AND (scl.times_used < scl.max_no_of_usage OR scl.max_no_of_usage=0)
				  and (
					(scll.shop_code IS NULL OR scll.shop_code = '" . $GLOBALS['shop']['code'] . "')
                    AND
                    (scll.language_code IS NULL OR scll.language_code = '" . $GLOBALS['shop_language']['code'] . "')
                  )";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 0) {
            $query2 = "SELECT scl.*, scll.category_coupon, scll.category_line_no, scsl.shipping_coupon_type, scsl.shipping_coupon_value
				  FROM shop_coupon_line scl
				  right join shop_coupon_group_link scgl
				  ON scgl.company = scl.company AND scgl.shop_code = '" . $GLOBALS['shop']['code'] . "' AND scgl.coupon_group = scl.coupon_group
				  right join shop_coupon_line_link scll
				  ON scl.company = scll.company AND scl.code = scll.code AND scl.coupon_code = scll.coupon_code
				  left join shop_coupon_shipping_link scsl
				  on scl.company = scsl.company AND scl.code = scsl.code AND scl.coupon_code = scsl.coupon_code
				  WHERE scl.coupon_code = '" . $coupon_code . "'
				  AND (scl.customer_no = '" . $GLOBALS['shop_customer']['customer_no'] . "' OR scl.customer_no = '')
				  AND scl.to_delete = 0
				  AND scl.active = 1
				  AND scl.company = '" . $GLOBALS['shop']['company'] . "'
				  AND (scl.times_used > scl.max_no_of_usage OR scl.max_no_of_usage=0)
				  AND (
							(scl.valid_from > CURDATE() OR scl.valid_from = '0000-00-00')
						OR
							(scl.valid_to < CURDATE() OR scl.valid_to = '0000-00-00')
						OR
							(scl.times_used > scl.max_no_of_usage OR scl.max_no_of_usage=0)
				  )
				  and (
					(scll.shop_code IS NULL OR scll.shop_code = '" . $GLOBALS['shop']['code'] . "')
                    AND
                    (scll.language_code IS NULL OR scll.language_code = '" . $GLOBALS['shop_language']['code'] . "')
                  )
				  ";
            $result2 = mysqli_query($GLOBALS['mysql_con'], $query2);
            if (@\DynCom\Compat\Compat::mysqli_num_rows($result2) > 0) {
                $errormessage = $GLOBALS['tc']['coupon_error_invalid'];
                //echo("<div class='errorbox'>" . $GLOBALS['tc']['coupon_error_invalid'] . "</div>");
            } else {
                $errormessage = $GLOBALS['tc']['coupon_error'];
                //echo("<div class='errorbox'>" . $GLOBALS['tc']['coupon_error'] . "</div>");
            }
        } else {

            $coupon = mysqli_fetch_assoc($result);
            $couponID = $coupon['id'];
            //Kategorie-Gutschein-Erweiterung ---
            $cat_coupon_item_exists = false;
            if ($coupon["category_coupon"] == 1) {
                $couponCatLineNo = (int)$coupon['category_line_no'];
                if (isset($GLOBALS['IOC'])) {
                    $IOCContainer = $GLOBALS['IOC'];
                    if ($IOCContainer instanceof \Dice\Dice) {

                        $currShopConfig = $IOCContainer->create('$CurrShopConfig');
                        $itemBuilder = $IOCContainer->create('DynCom\dc\dcShop\classes\WebshopItemBuilder');
                        /**
                         * @var $basket GenericUserBasket
                         */
                        $basket = $IOCContainer->create('$CurrUserBasket');
                        $categoyRepository = $IOCContainer->resolve('DynCom\dc\dcShop\classes\CategoryRepository');
                        $discountValidItemsArray = [];
                        if ($coupon["value_type"] == 0) {
                            $amountToDiscount = (float)$coupon['amount'];
                        } elseif ($coupon["value_type"] == 1) {
                            $percentageToDiscount = (float)$coupon['percentage'];
                        }

                        $discountValidAtLeastOnce = false;

                        /**
                         * @var $basketItem BasketEntity
                         */
                        $basketAmount = 0;
                        foreach ($basket as $basketItem) {
                            $discountValid = false;
                            if ($basketItem instanceof BasketEntity && $basketItem->getOrderableType() === 2) {
                                $orderableEntity = $basketItem->getOrderableEntity();
                                if ($itemBuilder instanceof WebshopItemBuilder) {
                                    $itemWithCatgeoies = $itemBuilder->decorateWebshopItemCategories($orderableEntity);
                                    if ($itemWithCatgeoies->isInCategory($couponCatLineNo)) {
                                        $discountValid = true;
                                    }
                                    if ($discountValid) {
                                        $basketAmount += $basketItem->getUnitPrice() * $basketItem->getQuantity();
                                    }
                                }
                            }
                        }

                        if ($basketAmount >= $coupon["amount_from"]) {
                            foreach ($basket as $basketItem) {
                                $discountValid = false;
                                if ($basketItem instanceof BasketEntity && $basketItem->getOrderableType() === 2) {
                                    $orderableEntity = $basketItem->getOrderableEntity();
                                    if ($itemBuilder instanceof WebshopItemBuilder) {
                                        $itemWithCatgeoies = $itemBuilder->decorateWebshopItemCategories($orderableEntity);
                                        if ($itemWithCatgeoies->isInCategory($couponCatLineNo)) {
                                            $discountValid = true;
                                            $discountValidAtLeastOnce = true;
                                        }
                                        $categoryArray = $itemWithCatgeoies->getCategoryArr();
                                        if ($discountValid) {
                                            if ($coupon["value_type"] == 1) {
                                                $discObj = new GenericLineDiscount(
                                                    GenericLineDiscount::DISCOUNT_SOURCE_TYPE_COUPON,
                                                    $coupon['id'],
                                                    $percentageToDiscount
                                                );
                                                $itemBasketKey = $currUserBasket->getKey($orderableEntity);
                                                if (!$currUserBasket->isLineDiscountAppliedToItemByKey(
                                                    $discObj,
                                                    $itemBasketKey
                                                )
                                                ) {
                                                    $currUserBasket->applyLineDiscountByKey($discObj, $itemBasketKey);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        if ($discountValidAtLeastOnce) {
                            if ($coupon["value_type"] == 0) {
                                if ($basketAmount >= $amountToDiscount) {
                                    $val_amount = $amountToDiscount;
                                } else {
                                    $val_amount = $basketAmount;
                                }
                                $discObj = new GenericInvoiceDiscount(
                                    GenericLineDiscount::DISCOUNT_SOURCE_TYPE_COUPON,
                                    $coupon['id'],
                                    0,
                                    $val_amount
                                );
                                if (!$currUserBasket->isInvoiceDiscountApplied($discObj)
                                ) {
                                    $currUserBasket->applyInvoiceDiscount($discObj);
                                }
                            }
                            $_SESSION['coupon'] = $coupon;
                            $cat_coupon_item_exists = true;
                        }
                    }
                }
            }
            //+++
            //echo "<!-- COUPON CONDITIONS: Category Coupon? - ".$coupon["category_coupon"]." | ITEM EXISTS? - ".$cat_coupon_item_exists." -->";
            $couponAmountFrom = (float)$coupon['amount_from'];
            $isCategoryCoupon = (bool)$coupon['category_coupon'];
            $isCouponAmountFromZeroOrLessThanBasket = ($couponAmountFrom == 0 || ($couponAmountFrom < $basket_amount));
            $isNotCategoryCouponAndNoCatCouponItemExists = (!$isCategoryCoupon && !$cat_coupon_item_exists);

            if ($isCouponAmountFromZeroOrLessThanBasket && $isNotCategoryCouponAndNoCatCouponItemExists) {                //MB --- OOP ---
                //MB --- OOP ---
                $IOC = $GLOBALS['IOCContainer'];
                $currUserBasket = $IOC->create('$CurrUserBasket');
                //MB +++ OOP +++
                $_SESSION['coupon'] = $coupon;
                $isInvoiceDiscount = false;
                $valueToDiscount = 0.00;
                $percentageToDiscount = 0.00;


                switch ($coupon['value_type']) {
                    case 0:
                        //Betrag
                        $_SESSION['coupon']['used'] = 1;
                        $isInvoiceDiscount = true;
                        $valueToDiscount = (float)$_SESSION['coupon']['amount'];
                        break;
                    case 1:
                        //Prozent
                        $_SESSION['coupon']['used'] = 1;
                        $isInvoiceDiscount = true;
                        $percentageToDiscount = (float)$_SESSION['coupon']['percentage'];
                        break;
                    case 2:
                        //Artikel
                        $item = get_item(
                            $GLOBALS['shop']['company'],
                            $GLOBALS['shop']['item_source'],
                            $GLOBALS['shop_language']['code'],
                            $coupon['item_no']
                        );
                        //MB --- OOP ---
                        $itemBuilder = $IOC->create('DynCom\dc\dcShop\classes\WebshopItemBuilder');
                        if ($itemBuilder instanceof WebshopItemBuilder && $currUserBasket instanceof UserBasket) {
                            $itemObj = $itemBuilder->getWebshopItemBasketEntity(
                                $coupon['item_no'],
                                1,
                                $coupon['variant_code']
                            );
                            $itemObj->removeAllAppliedDiscounts();
                            $qty = $itemObj->getMinQty();
                            $itemObj->setQuantity($qty);
                            $itemObj->setCreationSourceType(BasketEntity::CREATION_SOURCE_TYPE_COUPON);
                            $itemObj->setCreationSourceID($coupon['id']);
                            $itemObj->setCreationNotification(
                                $GLOBALS['tc']['from_coupon'] . ': ' . $coupon['description']
                            );
                            $itemObj->removeAllAppliedDiscounts();
                            $priceData = $itemObj->getPriceData();
                            $priceData->setUnitPrice(0.00);
                            $priceData->setPriceSourceType(ItemPriceData::PRICE_SOURCE_COUPON);
                            $priceData->setPriceSourceID($coupon['id']);
                            $priceData->setQtySourceType(ItemPriceData::QTY_SOURCE_COUPON);
                            $priceData->setQtySourceID($coupon['id']);
                            $itemObj->setUnitPriceAccessibility(false);
                            $itemObj->setQtyAccessibility(false);
                            if (!$currUserBasket->hasItemForKey($currUserBasket->getKey($itemObj))) {
                                $currUserBasket->addItem($itemObj);
                            }

                        }
                        //MB +++ OOP +++
                        $query = "INSERT INTO shop_user_basket (id,shop_visitor_id,shop_item_id,item_quantity,customer_price,allow_invoice_disc,is_coupon_item,insert_datetime) VALUES (NULL,'" . $GLOBALS['visitor']['id'] . "','" . $item['id'] . "','1','0','0','1',NOW())";
                        mysqli_query($GLOBALS['mysql_con'], $query);
                        $_SESSION['coupon']['used'] = 1;
                        break;
                    case 10:
                        //Individualbetrag
                        $_SESSION['coupon']['used'] = 1;
                        IF ($_SESSION['coupon']['value_coupon']) {
                            $_SESSION['coupon']['amount'] = $_SESSION['coupon']['amount_left'];
                        }
                        $isInvoiceDiscount = true;
                        $valueToDiscount = (float)$_SESSION['coupon']['amount'];
                        break;
                    case 3:
                        //Sonderversandoption
                        $_SESSION['coupon']['used'] = 1;
                        break;
                    default:
                        $errormessage = $GLOBALS['tc']['coupon_error'];
                        //echo("<div class='errorbox'>" . $GLOBALS['tc']['coupon_error'] . "</div>");
                        break;
                }

                /*$query = "UPDATE shop_coupon_line
						  SET times_used = times_used+1, last_date_used=CURDATE()
						  WHERE coupon_code='" . $coupon_code . "'
						  	AND code = '" . $coupon['code'] . "'
						  	AND company = '" . $GLOBALS['shop']['company'] . "'
						    AND shop_code = '" . $GLOBALS['shop']['code'] . "'
						    AND language_code = '" . $GLOBALS['shop_language']['code'] . "'";
                mysqli_query($GLOBALS['mysql_con'], $query);*/
                $query = "SELECT shop_coupon_line.*
						FROM shop_coupon_line 
						WHERE 
							shop_coupon_line.coupon_code='" . $coupon_code . "' 
						  AND 
							shop_coupon_line.code='" . $coupon['code'] . "'";
                $result = mysqli_query($GLOBALS['mysql_con'], $query);
                $loc_coupon = mysqli_fetch_assoc($result);
                /*echo "<!-- BEFORE VALUE COUPON CHECK -->";
                echo "<!-- LOC-COUPON: ";
                var_dump($loc_coupon);
                echo " -->";
                echo "<!-- SESSION COUPON: ";
                var_dump($_SESSION["coupon"]);
                echo " -->";*/

                if ($loc_coupon["id"] > 0 && $loc_coupon["value_coupon"] == 1) {

                    //$order_total = shop_get_basket_amount($GLOBALS["visitor"]["id"]) + $_POST["shipping_cost"] + $_POST["payment_cost"] + $_SESSION["small_quantity"];


                    $order_total = $currUserBasket->getBasketTotal() +
                        (float)$_POST["shipping_cost"]//get_shipping_cost($GLOBALS['shop']['company'], $GLOBALS['shop']['code'], $GLOBALS['shop_language']['code'], (int)$_POST['input_shipping_line_no'], $currUserBasket->getBasketItemTotal(), ($coupon['value_type'] == 4 ? true : false))
                        + (float)$_POST["payment_cost"]//get_payment_cost($GLOBALS['shop']['company'], $GLOBALS['shop']['code'], $GLOBALS['shop_language']['code'], (int)$_POST['input_payment_line_no'])
                        + (float)$_SESSION["small_quantity"];

                    $amount_deductible = $_SESSION['coupon']['amount'];
                    if ($amount_deductible > $order_total) {
                        $remainder = $amount_deductible - $order_total;
                        $amount_deducted = $order_total;
                    } else {
                        $remainder = 0;
                        $amount_deducted = $amount_deductible;
                    }
                    $amount_left_text = str_replace(
                        '%value%',
                        format_amount($remainder, false),
                        $GLOBALS["tc"]["coupon_amnt_left"]
                    );
                    $amount_deducted_text = str_replace(
                        '%value%',
                        format_amount($amount_deducted, false),
                        $GLOBALS["tc"]["applied_coupon_disc"]
                    );
                    $coupon_info_output = $amount_deducted_text . "<br/>" . $amount_left_text . "";
                    $amount_deducted_from_non_item_positions = $amount_deducted - $currUserBasket->getBasketItemTotal();
                    $_SESSION['coupon']['amnt_disc_non_items'] = $amount_deducted_from_non_item_positions > 0 ? $amount_deducted_from_non_item_positions : 0;
                    $_SESSION['coupon']['amount_deducted'] = $amount_deducted > 0 ? $amount_deducted : 0;
                    $_SESSION['coupon']['remainder'] = $remainder > 0 ? $remainder : 0;
                }


                //MB --- OOP ---
                if ($isInvoiceDiscount) {

                    $amnt = null;
                    $percentage = null;
                    if ($valueToDiscount > 0) {
                        $amnt = $valueToDiscount;
                    } elseif ($percentageToDiscount > 0) {
                        $percentage = $percentageToDiscount;
                    }


                    $basketAmount = $currUserBasket->getBasketItemTotal();

                    if ($valueToDiscount >= $basketAmount) {
                        $amnt = $basketAmount;
                    }

                    $discountType = $loc_coupon["value_coupon"] ? GenericInvoiceDiscount::EVALUATION_TYPE_PAYMENT : GenericInvoiceDiscount::EVALUATION_TYPE_TAXABLE_DISCOUNT;
                    $invoiceDiscount = new GenericInvoiceDiscount(
                        GenericInvoiceDiscount::DISCOUNT_SOURCE_TYPE_COUPON,
                        $coupon['id'],
                        $percentage,
                        $amnt,
                        $discountType
                    );

                    if (!$currUserBasket->isInvoiceDiscountApplied($invoiceDiscount)) {
                        $currUserBasket->applyInvoiceDiscount($invoiceDiscount);
                    }
                }
                //MB +++ OOP +++
                get_requestbox($coupon_info_output, $GLOBALS["tc"]["coupon_redeemed"], "success");
                //echo $coupon_info_output;
            } elseif ($coupon["category_coupon"] == 1 && $cat_coupon_item_exists === true) {
                get_requestbox('', $GLOBALS["tc"]["coupon_redeemed"], "success");
            } elseif ((($coupon["category_coupon"] == 1) && !$cat_coupon_item_exists)) {
                $errormessage = $GLOBALS['tc']['category_coupon_no_item'];
                //echo("<div class='errorbox'>" . $GLOBALS['tc']['category_coupon_no_item'] . "</div>");
            } else {
                $errormessage = $GLOBALS['tc']['coupon_valid_from_amount_1'] . $coupon['amount_from'] . $GLOBALS['tc']['coupon_valid_from_amount_2'];
                // echo("<div class='errorbox'>" . $GLOBALS['tc']['coupon_valid_from_amount_1'] . $coupon['amount_from'] . $GLOBALS['tc']['coupon_valid_from_amount_2'] . "</div>");
            }
        }
    }
    if ($errormessage != "") {
        get_requestbox($errormessage, "");
    }
}

function calculate_category_coupon_items($category_line_no)
{

    $IOC = $GLOBALS['IOC'];
    $currUserBasket = $IOC->create('$CurrUserBasket');
    if ($currUserBasket instanceof UserBasket) {
        $basketID = $currUserBasket->getID();

        $newBasketQuery = '
        SELECT
          SUM(subl.line_amount) AS sum
        FROM
          ' . $GLOBALS['my_db'] . '.shop_user_basket_line_new subl
        INNER JOIN
          ' . $GLOBALS['my_db'] . '.shop_item si
          ON (
                subl.orderable_item_type = 2
            AND si.id = subl.item_id
          )
        INNER JOIN
          ' . $GLOBALS['my_db'] . '.shop_item_has_category sihc
          ON (
                sihc.company=\'' . $GLOBALS['shop']['company'] . '\'
		    AND sihc.shop_code=\'' . $GLOBALS['shop']['item_source'] . '\'
			AND sihc.language_code=\'' . $GLOBALS['shop_language']['code'] . '\'
			AND sihc.category_shop_code=\'' . $GLOBALS['shop']['category_source'] . '\'
			AND sihc.category_line_no=\'' . $category_line_no . '\'
			AND (
			        sihc.item_no = si.item_no
			    OR  sihc.item_no = si.parent_item_no
			)
		WHERE
		    subl.header_id = \'' . $basketID . '\'
    ';

        //echo "<!-- CAT ITEM SUM QUERY: $query -->";
        $result = mysqli_query($GLOBALS['mysql_con'], $newBasketQuery);
        $array = mysqli_fetch_assoc($result);
        return $array["sum"];
    }

    $query = "
				SELECT
				  SUM(svub.customer_price * svub.item_quantity) AS 'sum'
				FROM
				  shop_item_has_category sihc
				INNER JOIN
				  shop_view_user_basket svub
				  ON (
				            svub.shop_visitor_id=" . $GLOBALS["visitor"]["id"] . "
				        AND svub.shop_code=sihc.shop_code
				        AND svub.language_code=sihc.language_code
				        AND (
				                    svub.item_no=sihc.item_no
				                OR  svub.parent_item_no=sihc.item_no
				        )
				  )
				WHERE
						sihc.company='" . $GLOBALS['shop']['company'] . "'
					AND sihc.shop_code='" . $GLOBALS['shop']['item_source'] . "'
					AND sihc.language_code='" . $GLOBALS['shop_language']['code'] . "'
					AND sihc.category_shop_code='" . $GLOBALS['shop']['category_source'] . "'
					AND sihc.category_line_no='" . $category_line_no . "'
				";
//echo "<!-- CAT ITEM SUM QUERY: $query -->";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $array = mysqli_fetch_assoc($result);
    return $array["sum"];
}

//Dropdown für Kundenauswahl im Vertreterportal
function create_customer_select($salesperson_code)
{
    $customer_query = "SELECT customer_no,name,id,city
					   FROM shop_customer
					   WHERE salesperson_code = '" . $salesperson_code . "'
					   	AND company = '" . $GLOBALS['shop']['company'] . "'
					   ORDER BY NAME ASC";
    $customer_result = mysqli_query($GLOBALS['mysql_con'], $customer_query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($customer_result) > 0) {
        if ($_GET['action'] != 'complete_order') {
            $action = $PHP_SELF;
        } else {
            $action = $GLOBALS['site']['code'] . "/" . $GLOBALS['language']['code'] . "/order/address_select/";
        }
        ?>
        <form name='customer_select_form' action="<?= $action; ?>" method="post">
            <div class=input>
                <select name=customer_select onChange="document.customer_select_form.submit();">
                    <?
                    if ($GLOBALS['shop_user']['customer_no'] == '') {
                        echo("<option value=''>" . $GLOBALS['tc']['choose_customer'] . "</option>");
                    }
                    while ($customer = mysqli_fetch_assoc($customer_result)) {
                        if ($customer['customer_no'] == $GLOBALS['shop_user']['customer_no']) {
                            $selected = "selected = selected";
                        } else {
                            $selected = "";
                        }
                        echo("<option value='" . $customer['customer_no'] . "' " . $selected . ">" . $customer['name'] . ", " . $customer['city'] . "</option>");
                    }
                    ?>
                </select>
            </div>
        </form>
        <?
    }
}

function user_for_temp_cust_exists()
{
    $vis_id = $GLOBALS['visitor']['id'];
    $countquery = "SELECT id FROM shop_user WHERE customer_no = 'TEMP_" . $vis_id . "'";
    return (@\DynCom\Compat\Compat::mysqli_num_rows(mysqli_query($GLOBALS['mysql_con'], $countquery)) > 0);
}

function create_user_for_existing_customer(Customer $customer)
{
    $countquery = "SELECT id FROM shop_user WHERE customer_no = '$customer->customer_no'";
    if (@\DynCom\Compat\Compat::mysqli_num_rows(mysqli_query($GLOBALS['mysql_con'], $countquery)) == 0) {
        $query = "INSERT INTO shop_user
				  SET company='" . $GLOBALS['shop']['company'] . "',
					  shop_code='" . $GLOBALS['shop']['code'] . "',
					  customer_no='$customer->customer_no',
					  name='" . $_POST['input_surname'] . " " . $_POST['input_lastname'] . "',
					  email='" . $_POST['input_email'] . "',
					  login='',
					  password=MD5('" . $_POST['input_password'] . "'),
					  last_visitor_id='" . $GLOBALS['visitor']['id'] . "'";
        if (mysqli_query($GLOBALS['mysql_con'], $query)) {
            $userID = mysqli_insert_id($GLOBALS['mysql_con']);
            $visitorUpdateQuery = "
                UPDATE
                  main_visitor
				SET
				  frontend_login=1,
				  main_user_id = '" . $userID . "'
				WHERE
				id='" . $GLOBALS['visitor']['id'] . "'";
            mysqli_query($visitorUpdateQuery);
        }
    }
}

function user_for_cust_no_exists($customer_no)
{
    $countquery = "SELECT id FROM shop_user WHERE customer_no = '$customer_no'";
    return (@\DynCom\Compat\Compat::mysqli_num_rows(mysqli_query($GLOBALS['mysql_con'], $countquery)) > 0);
}

function user_exists_for_customer($customerNo, $email, &$useID = null)
{
    if (!isset($IOCContainer) || !($IOCContainer instanceof IOCInterface)) {
        $IOCContainer = $GLOBALS['IOC'];
    }
    $pdo = $IOCContainer->resolve('DynCom\dc\common\classes\PDOQueryWrapper');
    //SH: 25.10.21 T21-13928 keine registrierung, wenn mail schon vorhanden
    $prepStatement = 'SELECT id FROM shop_user WHERE email = :email AND company = :company AND shop_code = :shopcode ORDER BY id LIMIT 1';
    $params = [
        [':email', $email, PDO::PARAM_STR],
        [':company', $GLOBALS["shop"]["company"], PDO::PARAM_STR],
        [':shopcode', $GLOBALS["shop"]["code"], PDO::PARAM_STR],
    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $resArr = $pdo->getResultArray();
    $userID = null;
    if (is_array($resArr)) {
        if (is_array($resArr[0])) {
            $resArr = $resArr[0];
        }
        if (\DynCom\Compat\Compat::array_key_exists('id', $resArr)) {
            $userID = (int)$resArr['id'];
        }
    }
    return \DynCom\Compat\Compat::count($resArr);
}

function register_new_user($customerNo, $email, $vis_id)
{
    $countquery = "SELECT * FROM shop_user WHERE customer_no = '" . $customerNo . "' AND email = '" . $_POST['input_email'] . "'";
    $countres = mysqli_query($GLOBALS['mysql_con'], $countquery);
    $password_options = get_password_options();
    $hashedPassword = password_hash(filter_input(INPUT_POST, 'input_password'), PASSWORD_DEFAULT, $password_options);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($countres) == 0) {
        //neuen Kunden und User anlegen und Visitor auf eingeloggt stellen
        $query = "INSERT INTO shop_user
				  SET company='" . $GLOBALS['shop']['company'] . "',
					  shop_code='" . $GLOBALS['shop']['code'] . "',
					  customer_no='" . $customerNo . "',
					  NAME='" . $_POST['input_surname'] . " " . $_POST['input_lastname'] . "',
					  email='" . $_POST['input_email'] . "',
					  login='',
					  PASSWORD='" . $hashedPassword . "',
					  last_visitor_id='" . $vis_id . "'";
        mysqli_query($GLOBALS['mysql_con'], $query);
        $userid = mysqli_insert_id($GLOBALS['mysql_con']);
    } else {
        $user = mysqli_fetch_assoc($countres);
        $userid = $user["id"];
        $query = "UPDATE shop_user SET password='" . $hashedPassword . "' WHERE id = '" . $userid . "'";
        mysqli_query($GLOBALS['mysql_con'], $query);
    }
    $query = "UPDATE main_visitor
				  SET frontend_login=1,
				  	  main_user_id = '" . $userid . "'
				  WHERE id='" . $vis_id . "'";
    mysqli_query($GLOBALS['mysql_con'], $query);
    return $userid;
}

function register_new_customer($customerNo, $name, $email, $isB2B = false)
{
    $salutation = ($_POST['input_salutation'] == 1) ? $GLOBALS['tc']['Mrs.'] : $GLOBALS['tc']['Mr.'];
    if($isB2B) {
        $query = "INSERT INTO shop_customer
				  SET company='" . $GLOBALS['shop']['company'] . "',
                  shop_code='" . $GLOBALS['shop']['code'] . "',
                  language_code='" . $GLOBALS['shop_language']['code'] . "',
                  customer_no='" . $customerNo . "',
                  NAME='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST["input_company"]) . "',
                  NAME_2='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                  surname='" . $_POST['input_surname'] . "',
                  lastname='" . $_POST['input_lastname'] . "',
                  company_name='" . $_POST["input_company"] . "',
                  address = '" . $_POST['input_user_street'] . " " . $_POST["input_user_street_no"] . "',
                  address_street = '" . $_POST['input_user_street'] . "',
                  address_no = '" . $_POST["input_user_street_no"] . "',
                  post_code = '" . $_POST['input_post_code'] . "',
                  city = '" . $_POST['input_city'] . "',
                  country = '" . $_POST['input_country'] . "',
                  bill_to_customer_no='" . $customerNo . "',
                  bill_to_name='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST["input_company"]) . "',
                  bill_to_name_2='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                  bill_to_address = '" . $_POST['input_user_street'] . "',
                  bill_to_post_code = '" . $_POST['input_post_code'] . "',
                  bill_to_city = '" . $_POST['input_city'] . "',
                  bill_to_country = '" . $_POST['input_country'] . "',
                  phone_no = '" . $_POST['input_phone_no'] . "',
                  email = '" . $_POST['input_email'] . "',
                  salutation = '" . $salutation . "',
                  active = 1,
                  currency_code = '" . $GLOBALS['currency']['code'] . "'";
    } else {
        $query = "INSERT INTO shop_customer
				  SET company='" . $GLOBALS['shop']['company'] . "',
                  shop_code='" . $GLOBALS['shop']['code'] . "',
                  language_code='" . $GLOBALS['shop_language']['code'] . "',
                  customer_no='" . $customerNo . "',
                  NAME='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                  surname='" . $_POST['input_surname'] . "',
                  lastname='" . $_POST['input_lastname'] . "',
                  company_name='" . $_POST["input_company"] . "',
                  address = '" . $_POST['input_user_street'] . " " . $_POST["input_user_street_no"] . "',
                  address_street = '" . $_POST['input_user_street'] . "',
                  address_no = '" . $_POST["input_user_street_no"] . "',
                  post_code = '" . $_POST['input_post_code'] . "',
                  city = '" . $_POST['input_city'] . "',
                  country = '" . $_POST['input_country'] . "',
                  bill_to_customer_no='" . $customerNo . "',
                  bill_to_name='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                  bill_to_address = '" . $_POST['input_user_street'] . "',
                  bill_to_post_code = '" . $_POST['input_post_code'] . "',
                  bill_to_city = '" . $_POST['input_city'] . "',
                  bill_to_country = '" . $_POST['input_country'] . "',
                  phone_no = '" . $_POST['input_phone_no'] . "',
                  email = '" . $_POST['input_email'] . "',
                  salutation = '" . $salutation . "',
                  active = 1,
                  currency_code = '" . $GLOBALS['currency']['code'] . "'";
    }

    mysqli_query($GLOBALS['mysql_con'], $query);
}

function update_sales_header_with_new_user($customerNo, $userid)
{
    if (!isset($IOCContainer) || !($IOCContainer instanceof IOCInterface)) {
        $IOCContainer = $GLOBALS['IOC'];
    }
    $pdo = $IOCContainer->resolve('\DynCom\dc\common\classes\PDOQueryWrapper');
    $prepStatement = 'UPDATE shop_sales_header
        SET
            shop_user_id = :userid,
            user_name = :username
        WHERE
            customer_no = :customerno
        AND
            company = :company
        AND 
            shop_code = :shopcode';
    $params = [
        [':customerno', $customerNo, PDO::PARAM_STR],
        [':userid', $userid, PDO::PARAM_STR],
        [':username', $_POST['input_surname'] . " " . $_POST['input_lastname'], PDO::PARAM_STR],
        [':company', $GLOBALS["shop"]["company"], PDO::PARAM_STR],
        [':shopcode', $GLOBALS["shop"]["code"], PDO::PARAM_STR],
    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
}

function update_customer($customerNo, $isB2B = false)
{
    if (!isset($IOCContainer) || !($IOCContainer instanceof IOCInterface)) {
        $IOCContainer = $GLOBALS['IOC'];
    }
    $pdo = $IOCContainer->resolve('DynCom\dc\common\classes\PDOQueryWrapper');
    if($isB2B) {
        $prepStatement = "UPDATE shop_customer
				  SET company='" . $GLOBALS['shop']['company'] . "',
                      shop_code='" . $GLOBALS['shop']['code'] . "',
                      language_code='" . $GLOBALS['shop_language']['code'] . "',
                      customer_no='" . $customerNo . "',
                      NAME='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST["input_company"]) . "',
                      NAME_2='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                      surname='" . $_POST['input_surname'] . "',
                      lastname='" . $_POST['input_lastname'] . "',
                      company_name='" . $_POST["input_company"] . "',
                      address = '" . $_POST['input_user_street'] . " " . $_POST["input_user_street_no"] . "',
                      address_street = '" . $_POST['input_user_street'] . "',
                      address_no = '" . $_POST["input_user_street_no"] . "',
                      post_code = '" . $_POST['input_post_code'] . "',
                      city = '" . $_POST['input_city'] . "',
                      country = '" . $_POST['input_country'] . "',
                      bill_to_customer_no='" . $customerNo . "',
                      bill_to_name='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST["input_company"]) . "',
                      bill_to_name_2='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                      bill_to_address = '" . $_POST['input_user_street'] . "',
                      bill_to_post_code = '" . $_POST['input_post_code'] . "',
                      bill_to_city = '" . $_POST['input_city'] . "',
                      bill_to_country = '" . $_POST['input_country'] . "',
                      phone_no = '" . $_POST['input_phone_no'] . "',
                      email = '" . $_POST['input_email'] . "',
                      salutation = '" . $_POST['input_salutation'] . "',
                      active = 1,
                      currency_code = '" . $GLOBALS['currency']['code'] . "'
                  WHERE
                      customer_no = :customerno
                  AND
                      company = :company
                  AND
                      shop_code = :shopcode
                  ";
    } else {
        $prepStatement = "UPDATE shop_customer
				  SET company='" . $GLOBALS['shop']['company'] . "',
                      shop_code='" . $GLOBALS['shop']['code'] . "',
                      language_code='" . $GLOBALS['shop_language']['code'] . "',
                      customer_no='" . $customerNo . "',
                      NAME='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                        surname='" . $_POST['input_surname'] . "',
                      lastname='" . $_POST['input_lastname'] . "',
                      company_name='" . $_POST["input_company"] . "',
                      address = '" . $_POST['input_user_street'] . " " . $_POST["input_user_street_no"] . "',
                      address_street = '" . $_POST['input_user_street'] . "',
                      address_no = '" . $_POST["input_user_street_no"] . "',
                      post_code = '" . $_POST['input_post_code'] . "',
                      city = '" . $_POST['input_city'] . "',
                      country = '" . $_POST['input_country'] . "',
                      bill_to_customer_no='" . $customerNo . "',
                      bill_to_name='" . mysqli_real_escape_string($GLOBALS['mysql_con'],$_POST['input_surname'] . " " . $_POST['input_lastname']) . "',
                      bill_to_address = '" . $_POST['input_user_street'] . "',
                      bill_to_post_code = '" . $_POST['input_post_code'] . "',
                      bill_to_city = '" . $_POST['input_city'] . "',
                      bill_to_country = '" . $_POST['input_country'] . "',
                      phone_no = '" . $_POST['input_phone_no'] . "',
                      email = '" . $_POST['input_email'] . "',
                      salutation = '" . $_POST['input_salutation'] . "',
                      active = 1,
                      currency_code = '" . $GLOBALS['currency']['code'] . "'
                  WHERE
                      customer_no = :customerno
                  AND
                      company = :company
                  AND
                      shop_code = :shopcode
                  ";
    }

    $params = [
        [':customerno', $customerNo, PDO::PARAM_STR],
        [':company', $GLOBALS["shop"]["company"], PDO::PARAM_STR],
        [':shopcode', $GLOBALS["shop"]["code"], PDO::PARAM_STR],
    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
}

//Prüft vor Registrierung auf bereits vorhandenes Konto (nach NAV-Dublettenprüfungslogik)
function check_duplicate_user()
{
    $query = "SELECT id 
			  FROM shop_customer
			  WHERE email='" . $_POST['input_email'] . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //E-Mail bereits für diesen Shop vorhanden
        return false;
    }
    $query = "SELECT id 
			  FROM shop_customer
			  WHERE email='" . $_POST['input_email'] . "'
			  	AND post_code = '" . $_POST['input_post_code'] . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //E-Mail/PLZ bereits für diesen Shop vorhanden
        return false;
    }
    $query = "SELECT id 
			  FROM shop_customer
			  WHERE email='" . $_POST['input_email'] . "'
			  	AND NAME = '" . $_POST['input_name'] . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //E-Mail/Name bereits für diesen Shop vorhanden
        return false;
    }
    $query = "SELECT id 
			  FROM shop_customer
			  WHERE name='" . $_POST['input_name'] . "'
			  	AND address = '" . $_POST['input_user_street'] . "'
				AND post_code = '" . $_POST['input_post_code'] . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //Kombination aus Name, Adresse und PLZ schon vorhanden
        return false;
    }
    return true;
}

function existing_customer_id()
{
    $query = "SELECT id
			  FROM shop_customer
			  WHERE email='" . $_POST['input_email'] . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //E-Mail bereits für diesen Shop vorhanden
        $first = @mysqli_fetch_assoc($result);
        return (int)$first['id'];
    }

    $query = "SELECT id
			  FROM shop_customer
			  WHERE name='" . $_POST['input_name'] . "'
			  	AND address = '" . $_POST['input_user_street'] . "'
				AND post_code = '" . $_POST['input_post_code'] . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //Kombination aus Name, Adresse und PLZ schon vorhanden
        $first = @mysqli_fetch_assoc($result);
        return (int)$first['id'];
    }
    return 0;
}

function existing_customer_id_from_sales_header(array $salesHeader)
{
    $query = "SELECT id
			  FROM shop_customer
			  WHERE email='" . $salesHeader['user_email'] . "'
			  	AND company = '" . $salesHeader['company'] . "'
			  	AND shop_code = '" . $salesHeader['shop_code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //E-Mail bereits für diesen Shop vorhanden
        $id = @mysqli_fetch_array($result)[0];
        return (int)$id;
    }
    $query = "SELECT id
			  FROM shop_customer
			  WHERE email='" . $salesHeader['user_email'] . "'
			  	AND NAME = '" . $salesHeader['bill_to_name'] . "'
			  	AND company = '" . $salesHeader['company'] . "'
			  	AND shop_code = '" . $salesHeader['shop_code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //E-Mail/Name bereits für diesen Shop vorhanden
        $id = @mysqli_fetch_array($result)[0];
        return (int)$id;
    }
    $query = "SELECT id
			  FROM shop_customer
			  WHERE name = '" . $salesHeader['bill_to_name'] . "'
			  	AND address = '" . $salesHeader['bill_to_address'] . "'
				AND post_code = '" . $salesHeader['bill_to_post_code'] . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND shop_code = '" . $GLOBALS['shop']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        //Kombination aus Name, Adresse und PLZ schon vorhanden
        $id = @mysqli_fetch_array($result)[0];
        return (int)$id;
    }
    return 0;
}

//Funktionen für Artikelmerkmalfilter +++

function get_attribute_option_description(PDO $pdo, $company, $attribute_code, $option_code, $language_code)
{
    static $query = '
    SELECT 
        CASE WHEN shop_attribute_translation.id IS NULL THEN shop_attribute_option.description ELSE shop_attribute_translation.description END AS \'description\'
    FROM shop_attribute_option
    LEFT JOIN shop_attribute_translation ON 
          shop_attribute_translation.company = shop_attribute_option.company 
      AND shop_attribute_translation.attribute_code=shop_attribute_option.attribute_code
      AND shop_attribute_translation.type=2
      AND shop_attribute_translation.language_code = :language_code
    WHERE
          shop_attribute_option.company = :company
      AND shop_attribute_option.attribute_code = :attribute_code
      AND shop_attribute_option.code = :option_code
    LIMIT 1 
    ';
    static $memo;

    $paramHash = md5($company . '|' . $attribute_code . '|' . $option_code . '|' . $language_code);
    if (\DynCom\Compat\Compat::array_key_exists($paramHash, $memo)) {
        return $memo[$paramHash];
    }
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':company', $company, PDO::PARAM_STR);
    $stmt->bindValue(':attribute_code', $attribute_code, PDO::PARAM_STR);
    $stmt->bindValue(':option_code', $option_code, PDO::PARAM_STR);
    $stmt->bindValue(':language_code', $language_code, PDO::PARAM_STR);
    $stmt->execute();
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $row = $stmt->fetch();
    $memo[$paramHash] = $row['description'];
    return $row['description'];

}

function get_category_filters(PDO $pdo, $company, $main_shop_code, $category_shop_code, $language_code, $category_line_no)
{
    static $query = '
    SELECT 
      shop_attribute_link.attribute_code AS \'code\',
      CASE WHEN shop_attribute_translation.id IS NULL THEN shop_attribute.description ELSE shop_attribute_translation.description END AS \'description\',
      shop_attribute.display_type AS \'display_type\',
      shop_attribute.data_type AS \'data_type\' 
    FROM shop_attribute_link
	INNER JOIN shop_attribute ON shop_attribute.code = shop_attribute_link.attribute_code AND shop_attribute.company = shop_attribute_link.company
	LEFT JOIN shop_attribute_translation ON shop_attribute_translation.type = 0 AND shop_attribute_translation.attribute_code=shop_attribute.code AND ((:language_code = \'\' AND FALSE) OR (shop_attribute_translation.language_code=:language_code))
	WHERE 
	      shop_attribute_link.company = :company
	  AND ( 
        ( 
              shop_attribute_link.type = 1
          AND shop_attribute_link.line_no = :category_line_no
          AND shop_attribute_link.shop_code = :category_shop_code
          AND shop_attribute_link.language_code = :language_code
        ) OR  (
              shop_attribute_link.type = 2
          AND shop_attribute_link.shop_code = :main_shop_code
          AND shop_attribute_link.language_code = :language_code
          AND shop_attribute.auto_link = 1
        )
	  )
	ORDER BY shop_attribute_link.sorting
    ';
    static $memo;
    if (null === $memo) {
        $memo = [];
    }
    $paramHash = md5($company . '|' . $main_shop_code . '|' . $category_shop_code . '|' . $language_code . '|' . $category_line_no);
    if (\DynCom\Compat\Compat::array_key_exists($paramHash, $memo)) {
        return $memo[$paramHash];
    }
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':company', $company, PDO::PARAM_STR);
    $stmt->bindValue(':main_shop_code', $main_shop_code, PDO::PARAM_STR);
    $stmt->bindValue(':category_shop_code', $category_shop_code, PDO::PARAM_STR);
    $stmt->bindValue(':language_code', $language_code, PDO::PARAM_STR);
    $stmt->bindValue(':category_line_no', $category_line_no, PDO::PARAM_INT);
    $stmt->execute();
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $resArr = $stmt->fetchAll();
    $memo[$paramHash] = $resArr;
    return $resArr;
}

function show_category_filters($category, $category_sort_order)
{

    if ($GLOBALS['category']['show_all_items'] && ($category["line_no"] != $GLOBALS["shop_language"]["productfinder_cat_line_no"])) {

        if (isset($_SESSION['filters_category_id']) && $_SESSION['filters_category_id'] != $category['id']) {
            unset($_SESSION['filters']);
        }
        if (!isset($_GET['card'])) {
            $_SESSION['filters_category_id'] = $category['id'];
            //Filterattribute für Kategorie suchen und get_parameter auswerten
            if (!isset($_GET['card'])) {
                $query = "SELECT DISTINCT attribute_code AS 'code',shop_attribute.display_type AS 'display_type',shop_attribute.data_type AS 'data_type' FROM shop_attribute_link
						  INNER JOIN shop_attribute ON shop_attribute.code = shop_attribute_link.attribute_code AND shop_attribute.company = '" . $GLOBALS['shop']['company'] . "'
						  WHERE (shop_attribute_link.type = 1
						  	AND shop_attribute_link.company = '" . $GLOBALS['shop']['company'] . "'
						  	AND shop_attribute_link.line_no = '" . $category['line_no'] . "'
						  	AND shop_attribute_link.shop_code = '" . $GLOBALS['shop']['category_source'] . "'
						  	AND shop_attribute_link.language_code = '" . $GLOBALS['shop_language']['code'] . "'
						  	)
						  	OR (shop_attribute_link.type = 2
						  	AND shop_attribute_link.company = '" . $GLOBALS['shop']['company'] . "'
						  	AND shop_attribute_link.shop_code = '" . $GLOBALS['shop']['code'] . "'
							) ORDER BY shop_attribute_link.sorting";
            }
            //echo "<!-- Attribute-code query: $query -->";
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0 && $GLOBALS['category']['id'] != '') {
                $counter = 0;

                //Alle Artikelnummern der Kategorie suchen
                $itemquery = "SELECT shop_view_active_item.item_no FROM shop_view_active_item
								  INNER JOIN shop_item_has_category ON								   
								        shop_item_has_category.company = '" . $GLOBALS['shop']['company'] . "'
								    AND shop_item_has_category.item_no = shop_view_active_item.item_no
								    AND (shop_item_has_category.category_line_no = " . $GLOBALS['category']['line_no'] . emty_category_query($GLOBALS['category']) . ")
								 	AND shop_item_has_category.category_shop_code = '" . $GLOBALS['shop']['category_source'] . "'
								 	AND shop_item_has_category.category_language_code = '" . $GLOBALS['shop_language']['code'] . "'
                                    AND shop_view_active_item.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                    AND shop_view_active_item.language_code = '" . $GLOBALS['shop_language']['code'] . "' 
                                    
								  UNION
								  SELECT shop_view_active_item.item_no FROM shop_view_active_item
								  INNER JOIN shop_item_has_category ON 
								        shop_item_has_category.company = '" . $GLOBALS['shop']['company'] . "'
								    AND shop_item_has_category.item_no = shop_view_active_item.parent_item_no

								    AND shop_view_active_item.parent_item_no != ''
								    AND (shop_item_has_category.category_line_no = " . $GLOBALS['category']['line_no'] . emty_category_query($GLOBALS['category']) . ")
								 	AND shop_item_has_category.category_shop_code = '" . $GLOBALS['shop']['category_source'] . "'
								 	AND shop_item_has_category.category_language_code = '" . $GLOBALS['shop_language']['code'] . "'
                                    AND shop_view_active_item.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                    AND shop_view_active_item.language_code = '" . $GLOBALS['shop_language']['code'] . "' 
                                    AND (SELECT 
            COUNT(id)
        FROM
            shop_item
        WHERE
            shop_item.item_no = shop_view_active_item.parent_item_no
                AND shop_item.active = 1
                AND shop_item.shop_code = shop_view_active_item.shop_code
                AND shop_item.language_code = shop_view_active_item.language_code)
                                    ";


                $itemresult = mysqli_query($GLOBALS['mysql_con'], $itemquery);
                //echo "<!-- ATTRIBUTELINK ITEMQUERY: ".$itemquery."-->";
                if (@\DynCom\Compat\Compat::mysqli_num_rows($itemresult) > 0) {
                    //Artikelnummern für IN-Clause zusammensetzen (Alle Artikel der Kategorie)
                    $items = " (";
                    while ($item = mysqli_fetch_assoc($itemresult)) {
                        $items .= "'" . $item['item_no'] . "',";
                    }
                    $items = substr($items, 0, -1);
                    $items .= ") ";
                } else {
                    $items = "(NULL)";
                }

                while ($row = mysqli_fetch_assoc($result)) {
                    //echo ("<div class='filterbox_inner'>");


                    $query = "SELECT DISTINCT shop_attribute.* 
							  FROM shop_attribute 
							  LEFT JOIN shop_attribute_link ON shop_attribute_link.attribute_code = shop_attribute.code
							  WHERE shop_attribute.code = '" . $row['code'] . "'
							  AND shop_attribute.company = '" . $GLOBALS["shop"]["company"] . "'
							  	AND (shop_attribute.auto_link=1
							  	OR(
							  		shop_attribute_link.no IN " . $items . "))";

                    $att_result = mysqli_query($GLOBALS['mysql_con'], $query);
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($att_result) == 1) {
                        //Filter in Session setzen, wenn get-parameter vorhanden
                        $att = mysqli_fetch_assoc($att_result);
                        if ($att['display_type'] != 3) {
                            if ($_GET[mb_strtolower(str_replace(' ', '~|~', $row['code']), 'UTF-8')] != '') {
                                if ($att["data_type"] != 3 && $_GET[mb_strtolower(
                                        str_replace(' ', '~|~', $row['code']),
                                        'UTF-8'
                                    )] == '0'
                                ) {
                                    unset($_SESSION['filters'][$row['code']]);
                                } elseif ($_GET[mb_strtolower(
                                        str_replace(' ', '~|~', $row['code']),
                                        'UTF-8'
                                    )] == 'default'
                                ) {
                                    unset($_SESSION['filters'][$row['code']]);
                                } else {
                                    $_SESSION['filters'][$row['code']] = $_GET[mb_strtolower(
                                        str_replace(' ', '~|~', $row['code']),
                                        'UTF-8'
                                    )];
                                }
                            }
                        } else {
                            if ($_GET[mb_strtolower(str_replace(' ', '~|~', $row['code']), 'UTF-8')] == 'default') {
                                unset($_SESSION['filters'][$row['code']]);
                            }
                            if ($_GET[mb_strtolower(str_replace(' ', '~|~', $row['code']), 'UTF-8') . "_from"] != '') {
                                if ($_GET[mb_strtolower(
                                        str_replace(' ', '~|~', $row['code']),
                                        'UTF-8'
                                    ) . "_from"] == '0'
                                ) {
                                    unset($_SESSION['filters'][$row['code']]['from']);
                                } else {
                                    $_SESSION['filters'][$row['code']]['from'] = $_GET[mb_strtolower(
                                        str_replace(' ', '~|~', $row['code']),
                                        'UTF-8'
                                    ) . "_from"];
                                }
                                if ($_GET[mb_strtolower(
                                        str_replace(' ', '~|~', $row['code']),
                                        'UTF-8'
                                    ) . "_to"] == '0'
                                ) {
                                    unset($_SESSION['filters'][$row['code']]['to']);
                                } else {
                                    $_SESSION['filters'][$row['code']]['to'] = $_GET[mb_strtolower(
                                        str_replace(' ', '~|~', $row['code']),
                                        'UTF-8'
                                    ) . "_to"];
                                }
                                if (!isset($_SESSION['filters'][$row['code']]['to']) && !isset($_SESSION['filters'][$row['code']]['from'])) {
                                    unset($_SESSION['filters'][$row['code']]);
                                }
                            }
                        }
                    }
                }

                /*$num_of_nav_values = 0;
                $filterquery = get_filterquery_old($num_of_nav_values);
                $havingquery = "";
                if (count($_SESSION['filters']) > 0) {
                    $count = count($_SESSION['filters']) - $num_of_nav_values;
                    if ($count > 0) {
                        $havingquery .= "HAVING COUNT(DISTINCT shop_attribute_link.id) = " . $count;
                    }
                }*/

                $num_of_nav_values = 0;
                $filterquery = get_filterquery($num_of_nav_values);
                $havingquery = "";
                if (\DynCom\Compat\Compat::count($_SESSION['filters']) > 0) {
                    $count = \DynCom\Compat\Compat::count($_SESSION['filters']) - $num_of_nav_values;
                    if ($count > 0) {
                        $havingquery .= "GROUP BY item_no HAVING COUNT(item_no) = " . $count;
                    }
                }

                if ($filterquery != '') {
                    if ($num_of_nav_values == 0) {
                        $attribute_link_query_where = "
                                    LEFT JOIN shop_item_attribute_links_ext sial ON (
                                            sial.company = '" . $GLOBALS['shop']['company'] . "'
                                        AND	sial.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                        AND sial.no = v_i.item_no
                                    )
                                    WHERE
                                            " . $filterquery . "
                                        AND sial.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                        AND (
                                                (
                                                    sial.type = 0
                                                AND sial.company = '" . $GLOBALS['shop']['company'] . "'
                                                )
                                            OR (
                                                    sial.company IS NULL
                                                AND sial.type IS NULL
                                                )
                                        )" . $havingquery;
                    } else {
                        $attribute_link_query_where = $filterquery;
                    }
                }

                \DynCom\Compat\Compat::mysqli_data_seek($result, 0);

                /*if ($counter == 4) {
                    echo "</div><div class='row'>";
                    $counter = 0;
                }*/

                /*$itemquery = "SELECT shop_view_active_item.item_no
								  FROM shop_view_active_item
								  INNER JOIN shop_item_has_category ON shop_item_has_category.item_no = shop_view_active_item.item_no
								  LEFT JOIN shop_attribute_link ON (shop_attribute_link.no = shop_view_active_item.item_no )
								  WHERE
								        shop_item_has_category.company = '" . $GLOBALS['shop']['company'] . "'
								    AND (shop_item_has_category.category_line_no = " . $GLOBALS['category']['line_no'] . emty_category_query(
                        $GLOBALS['category']
                    ) . ")
								 	AND shop_item_has_category.category_shop_code = '" . $GLOBALS['shop']['category_source'] . "'
								 	AND shop_item_has_category.category_language_code = '" . $GLOBALS['shop_language']['code'] . "'
                                    AND shop_view_active_item.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                    AND shop_view_active_item.language_code = '" . $GLOBALS['shop_language']['code'] . "'

								 	" . $filterquery . "
								  GROUP BY shop_view_active_item.id
								  " . $havingquery . "
								  UNION
								  SELECT shop_view_active_item.item_no
								  FROM shop_view_active_item
								  INNER JOIN shop_item_has_category ON shop_item_has_category.item_no = shop_view_active_item.parent_item_no
								  LEFT JOIN shop_attribute_link ON (shop_attribute_link.no = shop_view_active_item.item_no )
								  WHERE
								        shop_item_has_category.company = '" . $GLOBALS['shop']['company'] . "'
								    AND (shop_item_has_category.category_line_no = " . $GLOBALS['category']['line_no'] . emty_category_query(
                        $GLOBALS['category']
                    ) . ")
								 	AND shop_item_has_category.category_shop_code = '" . $GLOBALS['shop']['category_source'] . "'
								 	AND shop_item_has_category.category_language_code = '" . $GLOBALS['shop_language']['code'] . "'
                                    AND shop_view_active_item.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                    AND shop_view_active_item.language_code = '" . $GLOBALS['shop_language']['code'] . "'

								 	" . $filterquery . "
                                    AND (SELECT
            COUNT(id)
        FROM
            shop_item
        WHERE
            shop_item.item_no = shop_view_active_item.parent_item_no
                AND shop_item.active = 1
                AND shop_item.shop_code = shop_view_active_item.shop_code
                AND shop_item.language_code = shop_view_active_item.language_code)
								  GROUP BY shop_view_active_item.id
								  " . $havingquery; */

                $itemquery = "
                            
                            SELECT 
                                item_no
                            FROM shop_view_active_item 
                            WHERE 
                                    company = '" . $GLOBALS["shop"]["company"] . "'
                                AND shop_code ='" . $GLOBALS['shop']['item_source'] . "'
                                AND language_code = '" . $GLOBALS["shop_language"]["code"] . "'
                                AND	item_no IN (
                                    SELECT DISTINCT item_no    
                                    FROM (
                                        SELECT item_no
                                        FROM shop_item_has_category
                                        WHERE
                                                shop_item_has_category.category_line_no = " . $category["line_no"] .
                    emty_category_query($category) . "
                                            AND	shop_item_has_category.company = '" . $GLOBALS["shop"]["company"] . "'
                                            AND shop_item_has_category.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                            AND shop_item_has_category.language_code = '" . $GLOBALS["shop_language"]["code"] . "'
                                            AND shop_item_has_category.category_shop_code = '" . $GLOBALS['shop']['category_source'] . "'
                                        UNION SELECT item_no
                                        FROM shop_view_active_item
                                        WHERE 
                                                shop_view_active_item.company = '" . $GLOBALS["shop"]["company"] . "'
                                            AND	shop_view_active_item.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                            AND shop_view_active_item.language_code = '" . $GLOBALS["shop_language"]["code"] . "'
                                            AND shop_view_active_item.parent_item_no IN (
                                                SELECT item_no
                                                FROM shop_item_has_category
                                                WHERE
                                                        shop_item_has_category.category_line_no = " . $category["line_no"] .
                    emty_category_query($category) . "
                                                    AND	shop_item_has_category.company = '" . $GLOBALS["shop"]["company"] . "'
                                                    AND shop_item_has_category.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
                                                    AND shop_item_has_category.language_code = '" . $GLOBALS["shop_language"]["code"] . "'
                                                    AND shop_item_has_category.category_shop_code = '" . $GLOBALS['shop']['category_source'] . "'
                                            ) 
                                    ) AS v_i
                                 " . $attribute_link_query_where . ")";

                //echo "<!-- ATTRIBUTELINK ITEMQUERY: ".$itemquery."-->";

                $itemresult = mysqli_query($GLOBALS['mysql_con'], $itemquery);
                if (@\DynCom\Compat\Compat::mysqli_num_rows($itemresult) > 0) {

                    //Artikelnummern für IN-Clause zusammensetzen
                    $items = " (";
                    while ($item = mysqli_fetch_assoc($itemresult)) {
                        $items .= "'" . $item['item_no'] . "',";
                    }
                    $items = substr($items, 0, -1);
                    $items .= ") ";
                    ob_start();
                    while ($row = mysqli_fetch_assoc($result)) {
                        //Prüfen ob für aktuelles Attribut Werte vorhanden sind
                        $query = "SELECT DISTINCT shop_attribute.* 
								  FROM shop_attribute 
								  LEFT JOIN shop_attribute_link ON shop_attribute_link.attribute_code = shop_attribute.code
								  WHERE shop_attribute.code = '" . $row['code'] . "'
								    AND shop_attribute.company = '" . $GLOBALS["shop"]["company"] . "'
								  	AND (shop_attribute.auto_link=1
								  	OR(
								  		shop_attribute_link.no IN " . $items . "))";
                        $att_result = mysqli_query($GLOBALS['mysql_con'], $query);
                        if (\DynCom\Compat\Compat::mysqli_num_rows($att_result) == 1) {
                            //Filter anzeigen wenn Werte vorhanden
                            show_filter($row['code'], $items, false, $category_sort_order);
                        }
                        //Zurücksetzen-Button einblenden, wenn Filter gesetzt (nicht bei Slider)
                        //showUnsetFilterButton($row);
                        /*if (@mysqli_num_rows($att_result) == 1) {
                            echo "</div></div>";
                        }*/
                        $counter++;
                    }
                    $content = ob_get_contents();
                    ob_end_clean();
                    if(!empty($content)) {
                        echo "<div class='filterbox-wrapper'>";
                        echo "<div class='h3-decorated hidden-xs hidden-sm'><span>".$GLOBALS['tc']['filter_by']."</span></div>";
                        echo "<div class='filterbox-mobilebutton visible-xs visible-sm'>" . $GLOBALS['tc']['filter_by'] . "</div>";
                        echo "<div class='filterbox'><div class='row'>";
                        echo $content;
                        echo "</div></div></div>";
                    }
                }
            }
        }
    }
}

function show_all_filters($category_sort_order = '')
{

    $item_query = "SELECT shop_view_active_item.item_no 
	FROM shop_view_active_item
    LEFT JOIN 
	shop_permissions_group_link 
  ON 
  (
		shop_permissions_group_link.item_no=shop_view_active_item.item_no
	AND 
		shop_permissions_group_link.company=shop_view_active_item.company
  )
	WHERE 
		shop_view_active_item.company='" . $GLOBALS["shop"]["company"] . "' 
	AND shop_view_active_item.shop_code='" . $GLOBALS["shop"]["item_source"] . "'
	AND shop_view_active_item.language_code='" . $GLOBALS["shop_language"]["code"] . "'
	" . get_permissions_group_customer();
    //echo "<!-- ITEM-QUERY: ".$item_query." -->";
    $item_result = mysqli_query($GLOBALS['mysql_con'], $item_query);
    $items = "(";
    $counter = 1;
    while ($item = mysqli_fetch_assoc($item_result)) {
        if ($counter > 1) {
            $items .= ',';
        }
        $items .= "'" . $item["item_no"] . "'";
        $counter++;
    }
    $items .= ")";
    $allitems = $items;

    $catquery = "SELECT * FROM shop_category WHERE company='" . $GLOBALS["shop"]["company"] . "' AND shop_code='" . $GLOBALS["shop"]["category_source"] . "' AND language_code='" . $GLOBALS["shop_language"]["code"] . "' AND line_no='" . $GLOBALS["shop_language"]["productfinder_cat_line_no"] . "' LIMIT 1";
    $catresult = mysqli_query($GLOBALS['mysql_con'], $catquery);
    $category = mysqli_fetch_assoc($catresult);

    $always_open = $GLOBALS["open_filter"];
    if (\DynCom\Compat\Compat::count($always_open) > 0) {
        $order_clause = "ORDER BY CASE WHEN code IN (";
        for ($i = 0; $i < \DynCom\Compat\Compat::count($always_open); $i++) {
            if ($i > 0) {
                $order_clause .= ",";
            }
            $order_clause .= "'" . $always_open[$i] . "'";
        }
        $order_clause .= ") THEN 0 ELSE 1 END";
    }

    $attributes_query = "SELECT * FROM shop_attribute WHERE company='" . $GLOBALS["shop"]["company"] . "'" . $order_clause;
    $attributes_result = mysqli_query($GLOBALS['mysql_con'], $attributes_query);
    while ($row = mysqli_fetch_assoc($attributes_result)) {
        if (isset($_SESSION['filters_category_id']) && $_SESSION['filters_category_id'] != $category['id']) {
            unset($_SESSION['filters']);
        }
        if (!isset($_SESSION['filters_category_id']) || $_SESSION['filters_category_id'] != $category['id']) {
            $_SESSION['filters_category_id'] = $category["id"];
        }

        //Filter in Session setzen, wenn get-parameter vorhanden
        $att = $row;
        if ($att['display_type'] != 3) {
            if ($_GET[mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8')] != '') {
                if ($att["data_type"] != 3 && $_GET[mb_strtolower(
                        str_replace(' ', '_', $att['code']),
                        'UTF-8'
                    )] == '0'
                ) {
                    unset($_SESSION['filters'][$att['code']]);
                } elseif ($_GET[mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8')] == 'default') {
                    unset($_SESSION['filters'][$att['code']]);
                } else {
                    $_SESSION['filters'][$att['code']] = $_GET[mb_strtolower(
                        str_replace(' ', '_', $att['code']),
                        'UTF-8'
                    )];
                }
            }
        } else {
            if ($_GET[mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8')] == 'default') {
                unset($_SESSION['filters'][$att['code']]);
            }
            if ($_GET[mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8') . "_from"] != '') {
                if ($_GET[mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8') . "_from"] == '0') {
                    unset($_SESSION['filters'][$att['code']]['from']);
                } else {
                    $_SESSION['filters'][$att['code']]['from'] = $_GET[mb_strtolower(
                        str_replace(' ', '_', $att['code']),
                        'UTF-8'
                    ) . "_from"];
                }
                if ($_GET[mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8') . "_to"] == '0') {
                    unset($_SESSION['filters'][$att['code']]['to']);
                } else {
                    $_SESSION['filters'][$att['code']]['to'] = $_GET[mb_strtolower(
                        str_replace(' ', '_', $att['code']),
                        'UTF-8'
                    ) . "_to"];
                }
                if (!isset($_SESSION['filters'][$att['code']]['to']) && !isset($_SESSION['filters'][$att['code']]['from'])) {
                    unset($_SESSION['filters'][$att['code']]);
                }
            }
        }

        echo "<div class='filter-" . $att['code'] . "-wrapper filter-wrapper'>";
        show_filter($att['code'], $allitems, true, $category_sort_order);
        if ((isset($_SESSION['filters'][$att['code']]) || isset(
                    $_GET[mb_strtolower(
                        str_replace(' ', '_', $att['code']),
                        'UTF-8'
                    )]
                )) && $att['display_type'] != 3
        ) {
            if ($att["data_type"] != 3 && $_GET[mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8')] != '0') {
                echo("<div class='filter_unset td_filter_button' ><a class='button_delete' href='?" . urldecode(
                        mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8')
                    ) . "=0'>" . $GLOBALS['tc']['unset_filter'] . "</a></div>");
            } elseif ($att["data_type"] == 3 && $_GET[mb_strtolower(
                    str_replace(' ', '_', $att['code']),
                    'UTF-8'
                )] != 'default'
            ) {
                echo("<div class='filter_unset td_filter_button'><a class='button_delete' href='?" . urldecode(
                        mb_strtolower(str_replace(' ', '_', $att['code']), 'UTF-8')
                    ) . "=default'>" . $GLOBALS['tc']['unset_filter'] . "</a></div>");
            }
        }
        echo "</div>";
    }
}

function show_category_filters_start($category, $category_sort_order = '')
{
    if ($category['show_all_items']) {
        if (isset($_SESSION['filters_category_id']) && $_SESSION['filters_category_id'] != $category['id']) {
            unset($_SESSION['filters']);
        }
        if (!isset($_GET['card'])) {
            $_SESSION['filters_category_id'] = $category['id'];
            //Filterattribute für Kategorie suchen und get_parameter auswerten
            if (!isset($_GET['card'])) {
                $query = "SELECT DISTINCT attribute_code AS 'code',shop_attribute.display_type AS 'display_type',shop_attribute.data_type AS 'data_type' FROM shop_attribute_link
						  INNER JOIN shop_attribute ON shop_attribute.code = shop_attribute_link.attribute_code
						  WHERE (shop_attribute_link.type = 1
						  	AND shop_attribute_link.company = '" . $GLOBALS['shop']['company'] . "'						 	
						  	AND shop_attribute_link.shop_code = '" . $GLOBALS['shop']['category_source'] . "'
						  	
						  	AND (shop_attribute.code ='" . $GLOBALS["shop_language"]["productfinder_home_attribut_1"] . "'
						  	OR   shop_attribute.code ='" . $GLOBALS["shop_language"]["productfinder_home_attribut_2"] . "'
						  	OR   shop_attribute.code ='" . $GLOBALS["shop_language"]["productfinder_home_attribut_3"] . "'))
						  	OR (shop_attribute_link.type = 2
						  	AND shop_attribute_link.company = '" . $GLOBALS['shop']['company'] . "'
						  	AND shop_attribute_link.shop_code = '" . $GLOBALS['shop']['code'] . "'
							
						  	AND shop_attribute.auto_link = 1
						  	AND (shop_attribute.code ='" . $GLOBALS["shop_language"]["productfinder_home_attribut_1"] . "'
						  	OR   shop_attribute.code ='" . $GLOBALS["shop_language"]["productfinder_home_attribut_2"] . "'
						  	OR   shop_attribute.code ='" . $GLOBALS["shop_language"]["productfinder_home_attribut_3"] . "'))";
            }
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            echo("<!-- XATTRIBUTE-LIST: " . $query . " -->");
            if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0 && $category['id'] != '') {
                echo("<div class='menu_headline'>" . $GLOBALS['tc']['properties'] . "</div>");
                echo("<div class='filterbox'>");


                while ($row = mysqli_fetch_assoc($result)) {
                    //echo ("<div class='filterbox_inner'>");

                    //Alle Artikelnummern der Kategorie suchen
                    $itemquery = "SELECT shop_view_active_item.item_no FROM shop_view_active_item								  
								  WHERE  shop_view_active_item.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
								 	AND shop_view_active_item.language_code = '" . $GLOBALS['shop_language']['code'] . "'";
                    $itemresult = mysqli_query($GLOBALS['mysql_con'], $itemquery);
                    //echo "<!-- ATTRIBUTELINK: ".$itemquery."-->";
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($itemresult) > 0) {
                        //Artikelnummern für IN-Clause zusammensetzen (Alle Artikel der Kategorie)
                        $items = " (";
                        while ($item = mysqli_fetch_assoc($itemresult)) {
                            $items .= "'" . $item['item_no'] . "',";
                        }
                        $items = substr($items, 0, -1);
                        $items .= ") ";
                    } else {
                        $items = "(NULL)";
                    }
                    $query = "SELECT DISTINCT shop_attribute.* 
							  FROM shop_attribute 
							  LEFT JOIN shop_attribute_link ON shop_attribute_link.attribute_code = shop_attribute.code
							  WHERE shop_attribute.code = '" . $row['code'] . "'
							  	AND (shop_attribute.auto_link=1
							  	OR(
							  		shop_attribute_link.no IN " . $items . "))";
                    $att_result = mysqli_query($GLOBALS['mysql_con'], $query);
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($att_result) == 1) {
                        //Filter in Session setzen, wenn get-parameter vorhanden
                        $att = mysqli_fetch_assoc($att_result);
                        if ($att['display_type'] != 3) {
                            if ($_GET[mb_strtolower($row['code'], 'UTF-8')] != '') {
                                if ($att["data_type"] != 3 && $_GET[mb_strtolower($row['code'], 'UTF-8')] == '0') {
                                    unset($_SESSION['filters'][$row['code']]);
                                } elseif ($_GET[mb_strtolower($row['code'], 'UTF-8')] == 'default') {
                                    unset($_SESSION['filters'][$row['code']]);
                                } else {
                                    $_SESSION['filters'][$row['code']] = $_GET[mb_strtolower($row['code'], 'UTF-8')];
                                }
                            }
                        } else {
                            if ($_GET[mb_strtolower($row["code"], 'UTF-8')] == 'default') {
                                unset($_SESSION['filters'][$row['code']]);
                            }
                            if ($_GET[mb_strtolower($row['code'], 'UTF-8') . "_from"] != '') {
                                if ($_GET[mb_strtolower($row['code'], 'UTF-8') . "_from"] == '0') {
                                    unset($_SESSION['filters'][$row['code']]['from']);
                                } else {
                                    $_SESSION['filters'][$row['code']]['from'] = $_GET[mb_strtolower(
                                        $row['code'],
                                        'UTF-8'
                                    ) . "_from"];
                                }
                                if ($_GET[mb_strtolower($row['code'], 'UTF-8') . "_to"] == '0') {
                                    unset($_SESSION['filters'][$row['code']]['to']);
                                } else {
                                    $_SESSION['filters'][$row['code']]['to'] = $_GET[mb_strtolower(
                                        $row['code'],
                                        'UTF-8'
                                    ) . "_to"];
                                }
                                if (!isset($_SESSION['filters'][$row['code']]['to']) && !isset($_SESSION['filters'][$row['code']]['from'])) {
                                    unset($_SESSION['filters'][$row['code']]);
                                }
                            }
                        }
                    }
                }

                $num_of_nav_values = 0;
                $filterquery = get_filterquery($num_of_nav_values);
                $havingquery = "";
                if (\DynCom\Compat\Compat::count($_SESSION['filters']) > 0) {
                    $count = \DynCom\Compat\Compat::count($_SESSION['filters']) - $num_of_nav_values;
                    if ($count > 0) {
                        $havingquery .= "HAVING COUNT(DISTINCT shop_attribute_link.id) = " . $count;
                    }
                }

                \DynCom\Compat\Compat::mysqli_data_seek($result, 0);
                while ($row = mysqli_fetch_assoc($result)) {
                    $itemquery = "SELECT shop_view_active_item.item_no 
								  FROM shop_view_active_item								  
								  LEFT JOIN shop_attribute_link ON (shop_attribute_link.no = shop_view_active_item.item_no )
								  WHERE shop_view_active_item.shop_code = '" . $GLOBALS['shop']['item_source'] . "'
								 	AND shop_view_active_item.language_code = '" . $GLOBALS['shop_language']['code'] . "'								 	
								 
								 	" . $filterquery . "
								  GROUP BY shop_view_active_item.id
								  " . $havingquery;
                    $itemresult = mysqli_query($GLOBALS['mysql_con'], $itemquery);
                    //echo "<!-- ITEMQUERY: ".$itemquery." -->";
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($itemresult) > 0) {
                        //Artikelnummern für IN-Clause zusammensetzen
                        $items = " (";
                        while ($item = mysqli_fetch_assoc($itemresult)) {
                            $items .= "'" . $item['item_no'] . "',";
                        }
                        $items = substr($items, 0, -1);
                        $items .= ") ";
                        //Prüfen ob für aktuelles Attribut Werte vorhanden sind
                        $query = "SELECT DISTINCT shop_attribute.* 
								  FROM shop_attribute 
								  LEFT JOIN shop_attribute_link ON shop_attribute_link.attribute_code = shop_attribute.code
								  WHERE shop_attribute.code = '" . $row['code'] . "'
								  	AND (shop_attribute.auto_link=1
								  	OR(
								  		shop_attribute_link.no IN " . $items . "))";
                        $att_result = mysqli_query($GLOBALS['mysql_con'], $query);
                        if (@\DynCom\Compat\Compat::mysqli_num_rows($att_result) == 1) {
                            //Filter anzeigen wenn Werte vorhanden
                            echo "<div class='filter-" . $row['code'] . "-wrapper filter-wrapper'>";
                            show_filter($row['code'], $items, true, $category_sort_order);
                        }
                        //Zuücksetzen-Button einblenden, wenn Filter gesetzt (nicht bei Slider)
                        /*if((isset($_SESSION['filters'][$row['code']]) || isset($_GET[mb_strtolower($row['code'], 'UTF-8')])) && $row['display_type'] != 3)
						{
							if($row["data_type"]!=3 && $_GET[mb_strtolower($row['code'], 'UTF-8')] != '0') {
								echo ("<div class='filter_unset'><a class='button_delete' href='?".urldecode(mb_strtolower($row['code'], 'UTF-8'))."=0'>".$GLOBALS['tc']['unset_filter']."</a></div>");
							} elseif($row["data_type"]==3 && $_GET[mb_strtolower($row['code'], 'UTF-8')] != 'default') {
								echo ("<div class='filter_unset'><a class='button_delete' href='?".urldecode(mb_strtolower($row['code'], 'UTF-8'))."=default'>".$GLOBALS['tc']['unset_filter']."</a></div>");
							}
						}*/
                        if (@\DynCom\Compat\Compat::mysqli_num_rows($att_result) == 1) {
                            echo "</div>";
                        }
                    }
                    //echo("</div>");
                }
                echo("</div>");
                echo("<div class='clearfloat'></div>");
            }
            // einen globalen apply-Button für alle Attribute
            echo("<table><tr><td width=\"50%\" class=\"td_filter_button\"><a class='button_apply' onclick=\"window.location.href='?" . urlencode(
                    mb_strtolower($attribute['code'], 'UTF-8')
                ) . "_from='+ $('#slider-range_" . urlencode(
                    mb_strtolower($attribute['code'], 'UTF-8')
                ) . "' ).slider('values', 0 ) +'&" . urlencode(
                    mb_strtolower($attribute['code'], 'UTF-8')
                ) . "_to='+ $('#slider-range_" . urlencode(
                    mb_strtolower($attribute['code'], 'UTF-8')
                ) . "' ).slider('values', 1 ) +''\">anwenden</a></td>");
        }
    }
}


function show_filter($attribute_code, $items, $for_finder = false, $category_sort_order = '')
{
    $query = "SELECT * FROM shop_attribute WHERE company = '" . $GLOBALS['shop']['company'] . "' AND CODE = '" . $attribute_code . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $row = mysqli_fetch_assoc($result);
    //Filteroptionen je nach Anzeigeart anzeigen (0=Liste,1=Scroll-Liste,2=Dropdown,3=Schieberegler,4=Checkbox)
    if ($category_sort_order != '') {
        $category_sort_order = "&sort_by=" . $category_sort_order;
    }elseif ($_GET['sort_by'] <> '')
    {
        $category_sort_order = "&sort_by=" . $_GET['sort_by'];
    }
    switch ($row['display_type']) {
        case 0:
            show_filter_list($row, $items, $for_finder, $category_sort_order);
            break;
        case 1:
            show_filter_scroll_list($row, $items, $for_finder, $category_sort_order);
            break;
        case 2:
            show_filter_dropdown($row, $items, $for_finder, $category_sort_order);
            break;
        case 3:
            show_filter_slider($row, $items, $for_finder, $category_sort_order);
            break;
        case 4:
            show_filter_checkbox($row, $items, $for_finder, $category_sort_order);
            break;
        case 5:
            show_filter_icons($row, $items, $for_finder, $category_sort_order);
            break;
        default:
            echo "undefined filtertype";
            break;
    }
}

function show_filter_list($attribute, $items, $for_finder = false, $category_sort_order = '')
{
    if (isset($_SESSION['filters'][$attribute['code']])) {
        $filter_set = true;
        $open_class = " open";
        $headline_class = " body_open";
        $select_class = " active";
    } else {
        $filter_set = false;
        $open_class = " default-hide";
        $select_class = "";
    }
    $always_open = $GLOBALS["open_filter"];
    for ($i = 0; $i < \DynCom\Compat\Compat::count($always_open); $i++) {
        if ($always_open[$i] == $attribute["code"]) {
            $open_class = " open";
            $headline_class = " body_open";
        }
    }
    $valuearray = get_option_values($attribute, $items);
    if (\DynCom\Compat\Compat::count($valuearray) > 0) {
        $translation = get_attribute_translation($attribute['code'], 0);
        if (!$translation) {
            $translation = $attribute['description'];
        }

        if (isset($_SESSION['filters'][$attribute['code']])) {
            $selected = $valuearray[strtoupper($_SESSION['filters'][$attribute['code']])];
        } else {
            $selected = "";
        }
        showFilterWrapper($attribute);
        ?>
        <div class="filter list">
            <div
                    class="filter_headline <?= $headline_class ?> <?= $select_class ?>"><span><?= $translation ?></span><?= $selected; ?></div>
            <div class="filter_toggle">
                <div class="filter_body <?= $open_class; ?> <?= $select_class; ?>">
                    <ul class="filterlist">
                        <?
                        if ($filter_set) {
                            if ($attribute["data_type"] == 3) {
                                $output = ($_SESSION['filters'][$attribute['code']] == 1) ? $GLOBALS["tc"]["yes"] : $GLOBALS["tc"]["no"];
                            } else {
                                $localcode = $_SESSION['filters'][$attribute['code']];
                                $output = $localcode;
                                if (strtoupper($output) == strtoupper(
                                        $_SESSION['filters'][$attribute['code']]
                                    ) && strlen($valuearray[strtoupper($_SESSION['filters'][$attribute['code']])]) > 0
                                ) {
                                    $output = $valuearray[strtoupper($_SESSION['filters'][$attribute['code']])];
                                }
                            }
                            echo("<li data-isset='true' data-key='" . $_SESSION['filters'][$attribute['code']] . "' data-value='" . $valuearray[strtoupper(
                                    $_SESSION['filters'][$attribute['code']]
                                )] . "'><span> " . strtoupper($output) . "</span></li>");

                        } else {
                            foreach ($valuearray AS $key => $value) {
                                if (!strlen($key) > 0) {
                                    $valuedesc = $GLOBALS["tc"]["no"];
                                } else {
                                    $valuedesc = $value;
                                    if (!strlen($valuedesc) > 0) {
                                        $valuedesc = $key;
                                    }
                                }

                                echo("<li data-isset='false'  data-key='" . $key . "' data-value='" . $valuearray[$key] . "'><a href='?" . urlencode(
                                        mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                                    ) . "=" . urlencode(
                                        mb_strtolower($key, 'UTF-8')
                                    ) . $category_sort_order . "'>" . strtoupper($valuedesc) . "</a></li>");
                            }
                        }
                        ?>
                    </ul>
                </div>
            </div>
        </div>
        <?
        showUnsetFilterButton($attribute, $category_sort_order);
        echo "</div></div>";
    }
}

function show_filter_scroll_list($attribute, $items, $for_finder = false, $category_sort_order = '')
{
    if (isset($_SESSION['filters'][$attribute['code']])) {
        $filter_set = true;
        $open_class = " open";
        $headline_class = " body_open";
        $select_class = " active";
    } else {
        $filter_set = false;
        $open_class = " default-hide";
        $select_class = "";
    }
    $always_open = $GLOBALS["open_filter"];
    for ($i = 0; $i < \DynCom\Compat\Compat::count($always_open); $i++) {
        if ($always_open[$i] == $attribute["code"]) {
            $open_class = " open";
            $headline_class = " body_open";
        }
    }
    $valuearray = get_option_values($attribute, $items);
    if (\DynCom\Compat\Compat::count($valuearray) > 0) {
        $translation = get_attribute_translation($attribute['code'], 0);
        if (!$translation) {
            $translation = $attribute['description'];
        }
        if (isset($_SESSION['filters'][$attribute['code']])) {
            $selected = $_SESSION['filters'][$attribute['code']];
        } else {
            $selected = "";
        }
        showFilterWrapper($attribute);
        ?>
        <div class="filter list">
            <div
                    class="filter_headline <?= $headline_class ?> <?= $select_class ?>"><?= $translation ?> <?= $selected; ?></div>
            <div class="filter_toggle">
                <div class="filter_body scroll-list_body <?= $open_class; ?> <?= $select_class; ?>">
                    <? echo("<select size='5' class='filterlist' onchange=\"window.location.href='?" . urlencode(
                            mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                        ) . "='+this.options[this.selectedIndex].value+'" . $category_sort_order . "'\">");

                    foreach ($valuearray AS $key => $value) {
                        if ($_SESSION['filters'][$attribute['code']] == urlencode(mb_strtolower($key, 'UTF-8'))) {
                            $selected = "selected='selected'";
                        } else {
                            $selected = "";
                        }
                        echo("<option value='" . urlencode(
                                mb_strtolower($key, 'UTF-8')
                            ) . "' " . $selected . ">" . $value . "</option>");
                    }
                    echo("</select>") ?>
                </div>
            </div>
        </div>
        <?
        showUnsetFilterButton($attribute, $category_sort_order);
        echo "</div></div>";
    }
}

function show_filter_dropdown($attribute, $items, $for_finder = false, $category_sort_order = '')
{
    if (isset($_SESSION['filters'][$attribute['code']])) {
        $filter_set = true;
        $open_class = " open";
        $headline_class = " body_open";
        $select_class = "active";
    } else {
        $filter_set = false;
        $open_class = " default-hide";
        $select_class = "";
    }
    $always_open = $GLOBALS["open_filter"];
    for ($i = 0; $i < \DynCom\Compat\Compat::count($always_open); $i++) {
        if ($always_open[$i] == $attribute["code"]) {
            $open_class = " open";
            $headline_class = " body_open";
        }
    }
    $valuearray = get_option_values($attribute, $items);
    if (\DynCom\Compat\Compat::count($valuearray) > 0) {
        $translation = get_attribute_translation($attribute['code'], 0);
        if (!$translation) {
            $translation = $attribute['description'];
        }
        if (isset($_SESSION['filters'][$attribute['code']])) {
            $select_class = "active";
        } else {
            $select_class = "";
        }
        showFilterWrapper($attribute);
        echo("<div class='filter dropdown form-group'><label for='".$attribute["id"]."'>".$translation."</label><div class=\"select_body" . $open_class . " " . $select_class . "\">");
        if ($for_finder) {
            echo("<select class='filterlist' name='".$attribute["id"]."' data-attribute-id='" . $attribute["id"] . "'>");
        } else {
            echo("<select  class='filterlist' name='".$attribute["id"]."'  data-attribute-id='" . $attribute["id"] . "' onchange=\"window.location.href='?" . urlencode(mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')) . "='+this.options[this.selectedIndex].value+'" . $category_sort_order . "#filter_anchor'\">");
        }
        if (isset($_SESSION['filters'][$attribute['code']])) {
            echo("<option value='0'  disabled selected>Alle</option>");
        } else {
            echo("<option value='0'  disabled selected></option>");
        }

        foreach ($valuearray AS $key => $value) {
            if ($_SESSION['filters'][$attribute['code']] == mb_strtolower($key, 'UTF-8')) {
                $selected = "selected='selected'";
            } else {
                $selected = "";
            }
            if (isset($_SESSION['filters'][$attribute['code']])) {
                if ($_SESSION['filters'][$attribute['code']] == mb_strtolower($key, 'UTF-8')) {
                    echo("<option value='" . urlencode(
                            mb_strtolower($key, 'UTF-8')
                        ) . "' " . $selected . ">" . $value . "</option>");
                }
            } else {
                echo("<option value='" . urlencode(
                        mb_strtolower($key, 'UTF-8')
                    ) . "' " . $selected . ">" . $value . "</option>");
            }
        }
        echo("</select></div></div>");
        showUnsetFilterButton($attribute, $category_sort_order);
        echo "</div></div>";
    }
}

function show_filter_slider($attribute, $items, $for_filter = false, $category_sort_order = '')
{
    if (isset($_SESSION['filters'][$attribute['code']])) {
        $filter_set = true;
        $open_class = " open";
        $headline_class = " body_open";
        $from_value = $_SESSION['filters'][$attribute['code']]["from"];
        $to_value = $_SESSION['filters'][$attribute['code']]["to"];
    } else {
        $filter_set = false;
        $open_class = " default-hide";
    }
    $always_open = $GLOBALS["open_filter"];
    for ($i = 0; $i < \DynCom\Compat\Compat::count($always_open); $i++) {
        if ($always_open[$i] == $attribute["code"]) {
            $open_class = " open";
            $headline_class = " body_open";
        }
    }


    if ($attribute['navision_value'] != 0) {
        switch ($attribute['navision_value']) {
            case 1:
                $field = 'width';
                break;
            case 2:
                $field = 'lenght';
                break;
            case 3:
                $field = 'height';
                break;
            case 4:
                $field = 'volume';
                break;
            case 5:
                $field = 'weight';
                break;
            case 6:
                $field = 'retail_price';
                break;
            case 7:
                $field = 'base_price';
                break;
            case 8:
                $field = 'vendor_no';
                break;
            case 9:
                $field = 'inventory';
                break;
        }
        $rangequery = "SELECT MIN(svai." . $field . ") as min,MAX(svai." . $field . ") as max
					   FROM shop_view_active_item svai
					   INNER JOIN shop_item_has_category sihc ON (svai.item_no = sihc.item_no OR svai.parent_item_no=sihc.item_no)
					   WHERE svai.company = '" . $GLOBALS['shop']['company'] . "'					   	
					   	AND sihc.category_shop_code = '" . $GLOBALS["shop"]['category_source'] . "'
					   	AND svai.shop_code = '" . $GLOBALS["shop"]['item_source'] . "'
					   	AND svai.language_code='" . $GLOBALS['shop_language']['code'] . "'";
        if (!$for_filter) {
            $rangequery .= "
						AND sihc.category_line_no = '" . $GLOBALS['category']['line_no'] . "'
					   	AND sihc.language_code = '" . $GLOBALS['shop_language']['code'] . "'
					   	AND sihc.category_language_code = '" . $GLOBALS['shop_language']['code'] . "'
					   	AND sihc.shop_code = '" . $GLOBALS["shop"]['item_source'] . "'
						AND svai.item_no IN " . $items;
        }

        $result = mysqli_query($GLOBALS['mysql_con'], $rangequery);
        $range = mysqli_fetch_assoc($result);
    } else {
        switch ($attribute['data_type']) {
            case 0:
                $field = "value_option";
                break;
            case 1:
                $field = "value_integer";
                break;
            case 2:
                $field = "value_decimal";
                break;
            case 3:
                $field = "value_bool";
                break;
            case 4:
                $field = "value_text";
                break;
        }
        $rangequery = "SELECT MIN(" . $field . ") as min,MAX(" . $field . ") as max
					   FROM shop_attribute_link
					   WHERE company = '" . $GLOBALS['shop']['company'] . "'
					   	AND attribute_code = '" . $attribute['code'] . "'
						
					   	AND type=0
					   	AND no IN " . $items;
        //echo "<!-- RANGEQUERY: $rangequery -->";
        $result = mysqli_query($GLOBALS['mysql_con'], $rangequery);
        $range = mysqli_fetch_assoc($result);
    }
    if ($range["min"] <> $range["max"]) {
        if (!(strlen($from_value) > 0)) {
            $from_value = floor($range['min']);
        }
        if (!(strlen($to_value) > 0)) {
            $to_value = ceil($range['max']);
        }

        $translation = get_attribute_translation($attribute['code'], 0);
        if (!$translation) {
            $translation = $attribute['description'];
        }

        showFilterWrapper($attribute);
        ?>
        <div class="filter slider-filter form-group">
            <label class="filter_headline <?= $headline_class; ?>"><?= $translation; ?></label>

            <div class="filter_toggle">
                <div class="filter_body <?= $open_class; ?>">
                    <input id="att_value_<?= mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8'); ?>"
                           type="text" class="span2"
                           value="" data-slider-min="<?= floor($range['min']) ?>"
                           data-slider-max="<?= ceil($range['max']) ?>" data-slider-step="5"
                           data-slider-value="[<?= $from_value ?>, <?= $to_value ?>]"/>
                    <table width="100%">
                        <tr>
                            <td width="50%" class="text-left">
                                <a class='button_apply'
                                   onclick="window.location.href='?<?= urlencode(
                                       mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                                   ); ?>_from='+ $('#att_value_<?= urlencode(
                                       mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                                   ); ?>' ).slider('getValue')[0] +'&<?= urlencode(
                                       mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                                   ); ?>_to='+ $('#att_value_<?= urlencode(
                                       mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                                   ); ?>' ).slider('getValue')[1] +'<?= $category_sort_order ?>'"><?= $GLOBALS["tc"]["apply"]; ?></a>
                            </td>
                            <td width="50%" class="text-right">
                                <a class='button_delete'
                                   onclick="window.location.href='?<?= urlencode(
                                       mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                                   ); ?>_from=0&<?= urlencode(
                                       mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                                   ); ?>_to=0<?= $category_sort_order ?>'"><?= $GLOBALS["tc"]["reset"]; ?></a>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            $("#att_value_<?=mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8');?>").slider({});
        </script>

        <?
        showUnsetFilterButton($attribute, $category_sort_order);
        echo "</div></div>";
    }
}

function show_filter_checkbox($attribute, $items, $for_finder = false, $category_sort_order = '')
{
    if (isset($_SESSION['filters'][$attribute['code']])) {
        $filter_set = true;
        $open_class = " open";
        $headline_class = " body_open";
    } else {
        $filter_set = false;
        $open_class = " default-hide";
    }
    $always_open = $GLOBALS["open_filter"];
    for ($i = 0; $i < \DynCom\Compat\Compat::count($always_open); $i++) {
        if ($always_open[$i] == $attribute["code"]) {
            $open_class = " open";
            $headline_class = " body_open";
        }
    }
    $valuearray = get_option_values($attribute, $items);
    if (\DynCom\Compat\Compat::count($valuearray) > 0) {
        $translation = get_attribute_translation($attribute['code'], 0);
        if (!$translation) {
            $translation = $attribute['description'];
        }
        if (isset($_SESSION['filters'][$attribute['code']])) {
            $filter_set = true;
            $open_class = " open";
            $headline_class = " body_open";
            $select_class = "active";
        } else {
            $filter_set = false;
            $open_class = " default-hide";
            $select_class = "";
        }
        for ($i = 0; $i < \DynCom\Compat\Compat::count($always_open); $i++) {
            if ($always_open[$i] == $attribute["code"]) {
                $open_class = " open";
                $headline_class = " body_open";
            }
        }
        showFilterWrapper($attribute);
        echo "<div class='filter checkboxes'>";
        if (isset($_SESSION['filters'][$attribute['code']])) {
            $checked = "checked='checked'";
            $value = $_SESSION['filters'][$attribute['code']];
            if ($attribute['data_type'] == 3) {
                if ($value == 1) {
                    $output = $attribute["description"] . ": " . $GLOBALS["tc"]["yes"];
                };
            } else {
                $output = $valuearray[strtoupper($_SESSION['filters'][$attribute['code']])];
            }
            ?>
            <div class="filter_headline <?= $select_class ?>">
                <span><?= $output ?></span>
            </div>
            <?
        } else {
            if ($attribute['data_type'] != 3) {
                echo "<div class='filter_headline'><span>" . $translation . "</span></div>";
                echo "<div class='filter_toggle'>";
            }
            foreach ($valuearray AS $key => $value) {
                $val = $value;
                $checked = "";
                if ($val == $GLOBALS["tc"]["yes"]) {
                    $val = 1;
                    $desc = $GLOBALS["tc"]["yes"];
                }
                if ($val == $GLOBALS["tc"]["no"]) {
                    $val = 0;
                    $desc = $GLOBALS["tc"]["no"];
                }
                if ($attribute["data_type"] == 3) {
                    echo "<div class='filter_headline single_checkbox'>";
                    $val == 1 ? $desc = $translation : $desc = $GLOBALS["tc"]["no"];
                } else {
                    $desc = $val;
                }
                ?>
                <div>
                    <label class="form-check-label"
                           onclick="window.location.href='?<?= urlencode(
                               mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                           ); ?>=<?= mb_strtolower($key, 'UTF-8') ?><?= $category_sort_order ?>'">
                        <input id="<?= mb_strtolower(
                            str_replace(' ', '~|~', $attribute['code']),
                            'UTF-8'
                        ) ?>" <?= $checked ?>
                               name="<?= mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8') ?>"
                               value="<?= $val ?>"
                               type="checkbox">
                        <?= $desc ?>
                    </label>
                </div>
                <?
                if ($attribute["data_type"] == 3) {
                    echo "</div>";
                    break;
                }
            }
            if ($attribute['data_type'] != 3) {
                echo "</div>";
            }
        }
        echo("</div>");
        showUnsetFilterButton($attribute, $category_sort_order);
        echo "</div></div>";
    }
}

function show_filter_icons($attribute, $items, $for_finder = false, $category_sort_order = '')
{
    $valuearray = get_option_values($attribute, $items);

    if (isset($_SESSION['filters'][$attribute['code']])) {
        $select_class = "active";
    } else {
        $select_class = "";
    }

    if (\DynCom\Compat\Compat::count($valuearray) > 0) {
        $translation = get_attribute_translation($attribute['code'], 0);
        if (!$translation) {
            $translation = $attribute['description'];
        }
        if (isset($_SESSION['filters'][$attribute['code']])) {
            $selectedSessionValue = $_SESSION['filters'][$attribute['code']];
            $selected = \DynCom\Compat\Compat::array_key_exists(strtoupper($selectedSessionValue), $valuearray) ? $valuearray[strtoupper($selectedSessionValue)] : $selectedSessionValue;
        } else {
            $selected = "";
        }
        showFilterWrapper($attribute);
        ?>
        <div class="filter list">
            <div
                    class="filter_headline <?= $headline_class ?> <?= $select_class ?>"><span><?= $translation ?></span><?= $selected; ?></div>
            <div class="filter_toggle">
                <div class="filter_body icons_body <?= $select_class ?>">
                    <? foreach ($valuearray AS $key => $value) {
                        $option = get_option($attribute['code'], $key);
                        echo("<a href='?" . urlencode(
                                mb_strtolower(str_replace(' ', '~|~', $attribute['code']), 'UTF-8')
                            ) . "=" . urlencode(
                                mb_strtolower($key, 'UTF-8')
                            ) . "" . $category_sort_order . "'><div class='filter_icon' >");
                        if ($option['icon'] !== '' && file_exists(rtrim($_SERVER["DOCUMENT_ROOT"],'/').$GLOBALS["shop_setup"]["uploaddir_filter_icon"] . $option['icon'])) {
                            echo("<img src = '" . $GLOBALS["shop_setup"]["uploaddir_filter_icon"] . $option['icon'] . "' alt='" . $option['description'] . "'>");
                        } else {
                            echo $option['description'];
                        }
                        echo("</div></a>");
                    } ?>
                    <div class="clearfloat"></div>
                </div>
            </div>
        </div>
        <?
        showUnsetFilterButton($attribute, $category_sort_order);
        echo "</div></div>";
    }
}

/**
 * @param $attribute
 */
function showFilterWrapper($attribute): void
{
    echo "<div class='col-xs-12 filter-" . $attribute['code'] . "-wrapper filter-wrapper'><div class='filter-wrapper-inner'>";
}

/**
 * @param $attributeRow
 */
function showUnsetFilterButton(array $attributeRow, $category_sort_order = ''): void
{
    $rawCode = $attributeRow['code'];
    $normalizedCode = url_normalize_attribute_code($rawCode);
    if ((isset($_SESSION['filters'][$rawCode]) || isset(
                $_GET[$normalizedCode]
            )) && $attributeRow['display_type'] != 3
    ) {
        if ($attributeRow["data_type"] != 3 && $_GET[$normalizedCode] != '0'
        ) {
            ?>
            <div class="filter_unset">
                <a href="?<?= urldecode($normalizedCode) ?>=0<?= $category_sort_order ?>#filter_anchor"
                   title="<?= $GLOBALS['tc']['unset_filter'] ?>"><i class="icon icon-close"
                                                                    aria-hidden="true"></i></a>
            </div>
            <?
        } elseif ($attributeRow["data_type"] == 3 && $_GET[$normalizedCode] != 'default'
        ) {
            ?>
            <div class="filter_unset">
                <a href="?<?= urldecode($normalizedCode) ?>=default<?= $category_sort_order ?>#filter_anchor"
                   title="<?= $GLOBALS['tc']['unset_filter'] ?>"><i class="icon icon-close"
                                                                    aria-hidden="true"></i></a>
            </div>
            <?
        }
    }
}

function getUnsetFilterButton($attr_code, $attr_display_type, $attr_data_type)
{
    $normalizedCode = url_normalize_attribute_code($attr_code);

    $buttonStr = '';
    if ((isset($_SESSION['filters'][$attr_code]) || isset(
                $_GET[$normalizedCode]
            )) && $attr_display_type != 3
    ) {
        if ($attr_data_type != 3 && $_GET[$normalizedCode] != '0'
        ) {
            $buttonStr .= '
            <div class="filter_unset">
                <a href="?' . urldecode($normalizedCode) . '=0" title="' . $GLOBALS['tc']['unset_filter'] . '"><i class="icon icon-close" aria-hidden="true"></i></a>
            </div>
            ';


        } elseif ($attr_data_type == 3 && $_GET[$normalizedCode] != 'default') {
            $buttonStr .= '
            <div class="filter_unset">
                <a href="?' . urldecode($normalizedCode) . '=default" title="' . $GLOBALS['tc']['unset_filter'] . '"><i class="icon icon-close" aria-hidden="true"></i></a>
            </div>
            ';
        }
    }
    return $buttonStr;
}

function get_attribute_description(PDO $pdo, $company, $attribute_code, $language_code)
{

    static $query = '
    SELECT 
    CASE WHEN shop_attribute_translation.id IS NULL THEN shop_attribute.description ELSE shop_attribute_translation.description END AS \'description\'
    FROM shop_attribute
    LEFT JOIN shop_attribute_translation ON 
      shop_attribute_translation.company = shop_attribute.company 
    AND shop_attribute_translation.attribute_code=shop_attribute.code
    AND shop_attribute_translation.type=0
    AND shop_attribute_translation.language_code = :language_code
    WHERE
      shop_attribute.company = :company
    AND shop_attribute.code = :attribute_code    
    LIMIT 1 
    ';
    static $memo;

    $paramHash = md5($company . '|' . $attribute_code . '|' . $option_code . '|' . $language_code);
    if (\DynCom\Compat\Compat::array_key_exists($paramHash, $memo)) {
        return $memo[$paramHash];
    }
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':company', $company, PDO::PARAM_STR);
    $stmt->bindValue(':attribute_code', $attribute_code, PDO::PARAM_STR);
    $stmt->bindValue(':language_code', $language_code, PDO::PARAM_STR);
    $stmt->execute();
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $row = $stmt->fetch();
    $memo[$paramHash] = $row['description'];
    return $row['description'];


}

function get_attribute_translation($code, $type)
{
    $query = "SELECT description 
			  FROM shop_attribute_translation 
			  WHERE type='" . $type . "'
			  AND language_code ='" . $GLOBALS['shop_language']['code'] . "'
			  AND attribute_code = '" . $code . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['description'];
    }
    return false;
}

//Gibt ein Array mit den vorhandenen Wahlmöglichkeiten zurück
function get_option_values($attribute, $items)
{

    if ($attribute['navision_value'] != 0) {
        switch ($attribute['navision_value']) {
            case 1:
                $field = 'width';
                break;
            case 2:
                $field = 'lenght';
                break;
            case 3:
                $field = 'height';
                break;
            case 4:
                $field = 'volume';
                break;
            case 5:
                $field = 'weight';
                break;
            case 6:
                $field = 'retail_price';
                break;
            case 7:
                $field = 'base_price';
                break;
            case 8:
                $field = 'vendor_no';
                break;
            case 9:
                $field = 'inventory';
                break;
        }
        $query = "SELECT " . $field . " 
					   FROM shop_view_active_item svai
					   WHERE svai.company = '" . $GLOBALS['shop']['company'] . "'					   	
					   	AND svai.shop_code = '" . $GLOBALS["shop"]['item_source'] . "'
					   	AND svai.language_code='" . $GLOBALS['shop_language']['code'] . "'
					   	AND svai.item_no IN " . $items . "
					   	GROUP BY " . $field . "
					   	ORDER BY " . $field . " ASC
					   	";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);

        while ($row = mysqli_fetch_assoc($result)) {
            if ($attribute['data_type'] == 2) {
                $description = str_replace(".", ",", $row[$field]);
                $description = str_replace(",00", "", $row[$field]);
                $description = str_replace(".00", "", $row[$field]);
            } elseif ($attribute['data_type'] == 3) {
                $description = ($row[$field] == 1) ? $GLOBALS["tc"]["yes"] : $GLOBALS["tc"]["no"];
            } else {
                $description = format_amount($row[$field]);
            }
            $optionsarray[$row[$field]] = $description;
        }
    } else {

        //Prüfen ob Optionen(mit Filter) oder nur Links vorhanden sind
        $options = false;
        $optionsquery = "SELECT * 
					 FROM shop_attribute_option 
					 WHERE attribute_code = '" . $attribute['code'] . "'
					 	AND company = '" . $GLOBALS['shop']['company'] . "'
					 ORDER BY shop_attribute_option.sorting ASC";
        $optionsresult = mysqli_query($GLOBALS['mysql_con'], $optionsquery);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($optionsresult) > 0) {
            $options = true;
        }
        $optionsarray = array();
        switch ($attribute['data_type']) {
            case 0:
                $field = "value_option";
                break;
            case 1:
                $field = "value_integer";
                break;
            case 2:
                $field = "value_decimal";
                break;
            case 3:
                $field = "value_bool";
                break;
            case 4:
                $field = "value_text";
                break;
        }
        //Wenn keine Optionen vorhanden, die einzelnen Werte aus Links anzeigen
        if (!$options) {
            $query = "SELECT DISTINCT " . $field . "
				  FROM shop_attribute_link 
				  WHERE shop_attribute_link.attribute_code = '" . $attribute['code'] . "'
				  	AND shop_attribute_link.no IN " . $items . "
				
				  	AND shop_attribute_link.type=0 
				  	AND shop_attribute_link.company = '" . $GLOBALS['shop']['company'] . "'
                    AND shop_attribute_link.shop_code = '" . $GLOBALS['shop']['item_source'] . "' 
                    ";
            if ($field == 'value_bool') {
                //echo "<!-- BOOL VALUE QUERY: ".$query." -->";
            }
            $result = mysqli_query($GLOBALS['mysql_con'], $query);
            while ($row = mysqli_fetch_assoc($result)) {
                if ($attribute['data_type'] == 2) {
                    $description = str_replace(".", ",", $row[$field]);
                    $description = str_replace(",00", "", $row[$field]);
                    $description = str_replace(".00", "", $row[$field]);
                }
                if ($attribute['data_type'] == 3) {
                    $description = ($row[$field] == 1) ? $GLOBALS["tc"]["yes"] : $GLOBALS["tc"]["no"];
                }
                $optionsarray[$row[$field]] = $description;
            }
        } //Wenn Optionen vorhanden, die Optionsbeschreibungen anzeigen, für die Werte vorhanden sind
        else {
            $optionvaluequery = "SELECT shop_attribute_option.id,shop_attribute_option.company,shop_attribute_option.code,shop_attribute_option.attribute_code,shop_attribute_option.filter,shop_attribute_option.icon,shop_attribute_option.sorting,
                             CASE WHEN
                                (SELECT count(id) FROM shop_attribute_translation where company = '" . $GLOBALS['shop']['company'] . "' AND type = 2 AND attribute_code = shop_attribute_link.attribute_code AND attribute_link_no = shop_attribute_option.code AND language_code = '" . $GLOBALS['shop_language']['code'] . "') > 0
                            THEN
                                (SELECT description FROM shop_attribute_translation where company = '" . $GLOBALS['shop']['company'] . "' AND type = 2 AND attribute_code = shop_attribute_link.attribute_code AND attribute_link_no = shop_attribute_option.code AND language_code = '" . $GLOBALS['shop_language']['code'] . "')
                            ELSE
                                shop_attribute_option.description END AS 'description'
							 FROM shop_attribute_option
							 INNER JOIN shop_attribute_link ON (shop_attribute_link.attribute_code=shop_attribute_option.attribute_code AND shop_attribute_option.code = shop_attribute_link.value_option )
							 WHERE shop_attribute_option.attribute_code = '" . $attribute['code'] . "'
					 			AND shop_attribute_option.company = '" . $GLOBALS['shop']['company'] . "'
					 			AND shop_attribute_link.no IN " . $items . " 
					 			AND shop_attribute_link.type = 0
                                AND shop_attribute_link.shop_code = '" . $GLOBALS['shop']['item_source'] . "' 
							  ORDER BY shop_attribute_option.description ASC";


            $optionsvalueresult = mysqli_query($GLOBALS['mysql_con'], $optionvaluequery);
            $optionvaluearray = array();
            if (@\DynCom\Compat\Compat::mysqli_num_rows($optionsvalueresult) > 0) {
                while ($optionvalue = mysqli_fetch_assoc($optionsvalueresult)) {
                    $optionvaluearray[$optionvalue['code']]['description'] = $optionvalue['description'];
                }
            }
            while ($option = mysqli_fetch_assoc($optionsresult)) {
                //Ohne Filter prüfen auf value_option in Links
                //if($option['filter'] == '')
                if (1 == 1) {
                    if (\DynCom\Compat\Compat::array_key_exists($option['code'], $optionvaluearray)) {
                        /*$linkquery = "SELECT *
                                      FROM shop_attribute_link
                                      WHERE attribute_code = '".$attribute['code']."'
                                          AND value_option='".$option['code']."'
                                          AND company='".$GLOBALS['shop']['company']."'
                                          AND no IN ".$items ."
                                          AND type = 0";
                        $linkresult = mysqli_query($GLOBALS['mysql_con'],$linkquery);
                        if(@mysqli_num_rows($linkresult) > 0)
                        {*/
                        $optionsarray[$option['code']] = $optionvaluearray[$option['code']]['description'];
                        //}
                    }
                } //Mit Filter prüfen auf Wertebereich in Links
                else {
                    $rangeparts = explode("..", trim($option['filter']));
                    $min = $rangeparts[0];
                    $max = $rangeparts[1];
                    if ($attribute['data_type'] == 2) {
                        $min = str_replace(",", ".", $min);
                        $max = str_replace(",", ".", $max);
                    }
                    if (preg_match('/[\d\.]+/', trim($option['filter'])) !== 1) {
                        if (strlen($min) > 0) {
                            $minpart = "AND `" . $field . "` >= '" . $min . "'";
                        }
                        if (strlen($max) > 0) {
                            $maxpart = "AND `" . $field . "` <= '" . $max . "'";
                        }
                    }
                    $linkquery = "SELECT * 
							  FROM shop_attribute_link
							  WHERE attribute_code = '" . $attribute['code'] . "' 
							  " . $minpart . " 
							  " . $maxpart . " 
							  	AND company='" . $GLOBALS['shop']['company'] . "'
								AND shop_code='" . $GLOBALS['shop']['code'] . "'
				
							  	AND NO IN " . $items . " 
							  	AND type = 0";


                    $linkresult = mysqli_query($GLOBALS['mysql_con'], $linkquery);
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($linkresult) > 0) {
                        $optionsarray[$option['code']] = $option['description'];
                    }
                }
            }
        }
    }
    asort($optionsarray);
    return $optionsarray;
}

function get_filterquery(&$num_of_nav_values)
{
    $firstlink = true;
    $filterquery = "";
    $itemquery = "";
    $linkquery = "";
    if (isset($_SESSION['filters']) && \DynCom\Compat\Compat::count($_SESSION['filters']) > 0) {

        foreach ($_SESSION['filters'] as $key => $value) {


            $attributequery = "SELECT *
							   FROM shop_attribute
							   WHERE code = '" . $key . "'
							   AND company='" . $GLOBALS['shop']['company'] . "'";
            $attributeresult = mysqli_query($GLOBALS['mysql_con'], $attributequery);
            $attribute = mysqli_fetch_assoc($attributeresult);
            if ($attribute['navision_value'] != 0) {

                $num_of_nav_values++;
                switch ($attribute['navision_value']) {
                    case 1:
                        $field = 'width';
                        break;
                    case 2:
                        $field = 'lenght';
                        break;
                    case 3:
                        $field = 'height';
                        break;
                    case 4:
                        $field = 'volume';
                        break;
                    case 5:
                        $field = 'weight';
                        break;
                    case 6:
                        $field = 'retail_price';
                        break;
                    case 7:
                        $field = 'base_price';
                        break;
                    case 8:
                        $field = 'vendor_no';
                        break;
                    case 9:
                        $field = 'inventory';
                        break;
                }
                //Slider auswerten
                if ($attribute['display_type'] == 3) {

                    $from = $_SESSION['filters'][$key]['from'];
                    $to = $_SESSION['filters'][$key]['to'];
                    $itemquery .= " ) AND ((shop_view_active_item." . $field . ">='" . $from . "'";
                    $itemquery .= " ) AND (shop_view_active_item." . $field . "<='" . $to . "'";
                } //Andere Filter auswerten
                else {

                    //Prüfen ob Option mit dem Wert vorhanden ist (wegen Bereichsfilter)
                    $optionquery = "SELECT *                    FROM shop_attribute_option
                    WHERE company = '" . $GLOBALS['shop']['company'] . "'
                        AND attribute_code = '" . $attribute['code'] . "'
                        AND CODE = '" . $value . "'";
                    $optionresult = mysqli_query($GLOBALS['mysql_con'], $optionquery);
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($optionresult) == 0) {
                        $linkquery .= " sial." . $field . "='" . $value . "' AND sial.attribute_code='" . $attribute["code"] . "'";
                    }
                    else {
                        $linkquery .= " sial.attribute_code='" . $attribute["code"] . "' AND";
                        $linkquery .= " sial.value_option = '" . $value . "'";
                    }
                }
            } else {

                if ($firstlink) {
                    $linkquery = "(";
                    $firstlink = false;
                } else {
                    $linkquery .= ") OR (";
                }
                switch ($attribute['data_type']) {
                    case 0:
                        $field = "value_option";
                        break;
                    case 1:
                        $field = "value_integer";
                        break;
                    case 2:
                        $field = "value_decimal";
                        break;
                    case 3:
                        $field = "value_bool";
                        break;
                    case 4:
                        $field = "value_text";
                        break;
                }
                if ($attribute['display_type'] == 3) {
                    $from = $_SESSION['filters'][$key]['from'];
                    $to = $_SESSION['filters'][$key]['to'];
                    $linkquery .= " (sial." . $field . ">='" . $from . "'";
                    $linkquery .= " AND sial." . $field . "<='" . $to . "'
									AND sial.attribute_code = '" . $attribute['code'] . "')";
                } else {
                    //Prüfen ob Option mit dem Wert vorhanden ist (wegen Bereichsfilter)
                    $optionquery = "SELECT *                    FROM shop_attribute_option
                    WHERE company = '" . $GLOBALS['shop']['company'] . "'
                        AND attribute_code = '" . $attribute['code'] . "'
                        AND CODE = '" . $value . "'";
                    $optionresult = mysqli_query($GLOBALS['mysql_con'], $optionquery);
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($optionresult) == 0) {
                        $linkquery .= " sial." . $field . "='" . $value . "' AND sial.attribute_code='" . $attribute["code"] . "'";
                    }
                    else {
                        $linkquery .= " sial.attribute_code='" . $attribute["code"] . "' AND";
                        $linkquery .= " sial.value_option = '" . $value . "'";
                    }
                }
            }
        }

        $filterquery = (strlen($itemquery.$linkquery)> 0) ? $itemquery . " " . $linkquery . ")" : "";
    }
    return $filterquery;
}


function get_filterquery_old(&$num_of_nav_values)
{
    $firstlink = true;
    $filterquery = "";
    $itemquery = "";
    $linkquery = "";
    if (isset($_SESSION['filters']) && \DynCom\Compat\Compat::count($_SESSION['filters']) > 0) {

        foreach ($_SESSION['filters'] as $key => $value) {


            $attributequery = "SELECT *
							   FROM shop_attribute
							   WHERE code = '" . $key . "'
							   AND company='" . $GLOBALS['shop']['company'] . "'";
            $attributeresult = mysqli_query($GLOBALS['mysql_con'], $attributequery);
            $attribute = mysqli_fetch_assoc($attributeresult);
            if ($attribute['navision_value'] != 0) {

                $num_of_nav_values++;
                switch ($attribute['navision_value']) {
                    case 1:
                        $field = 'width';
                        break;
                    case 2:
                        $field = 'lenght';
                        break;
                    case 3:
                        $field = 'height';
                        break;
                    case 4:
                        $field = 'volume';
                        break;
                    case 5:
                        $field = 'weight';
                        break;
                    case 6:
                        $field = 'retail_price';
                        break;
                    case 7:
                        $field = 'base_price';
                        break;
                    case 8:
                        $field = 'vendor_no';
                        break;
                    case 9:
                        $field = 'inventory';
                        break;
                }
                //Slider auswerten
                if ($attribute['display_type'] == 3) {

                    $from = $_SESSION['filters'][$key]['from'];
                    $to = $_SESSION['filters'][$key]['to'];
                    $itemquery .= " AND shop_view_active_item." . $field . ">='" . $from . "'";
                    $itemquery .= " AND shop_view_active_item." . $field . "<='" . $to . "'";
                } //Andere Filter auswerten
                else {

                    //Prüfen ob Option mit dem Wert vorhanden ist (wegen Bereichsfilter)
                    $optionquery = "SELECT * 
									FROM shop_attribute_option
									WHERE company = '" . $GLOBALS['shop']['company'] . "'
										AND attribute_code = '" . $attribute['code'] . "'
										AND CODE = '" . $value . "'";
                    $optionresult = mysqli_query($GLOBALS['mysql_con'], $optionquery);
                    //Keine Option -> Werte direkt aus attribute_link
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($optionresult) == 0) {

                        $itemquery .= " AND shop_view_active_item." . $field . "='" . $value . "'";
                    } //Werte aus attribute_option entweder mit Filter oder value_option
                    else {

                        $option = mysqli_fetch_assoc($optionresult);
                        //if($option['filter'] == '')
                        if (1 == 1) {

                            $itemquery .= " AND shop_view_active_item." . $field . "='" . $value . "'";
                        } else {

                            $rangeparts = explode("..", $option['filter']);
                            $min = $rangeparts[0];
                            $max = $rangeparts[1];
                            if ($attribute['data_type'] == 2) {
                                $min = str_replace(",", ".", $min);
                                $max = str_replace(",", ".", $max);
                            }
                            $itemquery .= " AND shop_view_active_item." . $field . ">='" . $min . "'";
                            $itemquery .= " AND shop_view_active_item." . $field . "<='" . $max . "'";
                        }
                    }
                }
            } else {

                if ($firstlink) {
                    $linkquery .= " AND(";
                    $firstlink = false;
                } else {
                    $linkquery .= " OR ";
                }
                switch ($attribute['data_type']) {
                    case 0:
                        $field = "value_option";
                        break;
                    case 1:
                        $field = "value_integer";
                        break;
                    case 2:
                        $field = "value_decimal";
                        break;
                    case 3:
                        $field = "value_bool";
                        break;
                    case 4:
                        $field = "value_text";
                        break;
                }
                if ($attribute['display_type'] == 3) {
                    $from = $_SESSION['filters'][$key]['from'];
                    $to = $_SESSION['filters'][$key]['to'];
                    $linkquery .= " (shop_attribute_link." . $field . ">='" . $from . "'";
                    $linkquery .= " AND shop_attribute_link." . $field . "<='" . $to . "'
									AND shop_attribute_link.attribute_code = '" . $attribute['code'] . "')";
                } else {
                    //Prüfen ob Option mit dem Wert vorhanden ist (wegen Bereichsfilter)
                    $optionquery = "SELECT * 
									FROM shop_attribute_option
									WHERE company = '" . $GLOBALS['shop']['company'] . "'
										AND attribute_code = '" . $attribute['code'] . "'
										AND CODE = '" . $value . "'";
                    //echo "<!-- OPTIONQUERY: ".$optionquery." -->";

                    $optionresult = mysqli_query($GLOBALS['mysql_con'], $optionquery);
                    //Keine Option -> Werte direkt aus attribute_link
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($optionresult) == 0) {
                        $linkquery .= " shop_attribute_link." . $field . "='" . $value . "' AND shop_attribute_link.attribute_code='" . $attribute["code"] . "'";
                    } //Werte aus attribute_option entweder mit Filter oder value_option
                    else {
                        $linkquery .= " shop_attribute_link.attribute_code='" . $attribute["code"] . "' AND";
                        $option = mysqli_fetch_assoc($optionresult);
                        //if($option['filter'] == '')
                        if (1 == 1) {
                            $linkquery .= " shop_attribute_link.value_option = '" . $value . "'";
                        } else {
                            $rangeparts = explode("..", $option['filter']);
                            $min = $rangeparts[0];
                            $max = $rangeparts[1];
                            if ($attribute['data_type'] == 2) {
                                $min = str_replace(",", ".", $min);
                                $max = str_replace(",", ".", $max);
                            }
                            $linkquery .= " (shop_attribute_link." . $field . ">='" . $min . "'";
                            $linkquery .= " AND shop_attribute_link." . $field . "<='" . $max . "')";
                        }
                    }
                }
            }
        }
        if ($linkquery != "") {
            $linkquery .= ") AND shop_attribute_link.language_code = '" . $GLOBALS["shop_language"]["code"] . "'
                        AND shop_attribute_link.shop_code = '" . $GLOBALS["shop"]["item_source"] . "'";
        }
        $filterquery = $itemquery . " " . $linkquery;
        //echo "<!-- FILTERQUERY: ".$filterquery." -->";
    }
    return $filterquery;
}

function get_option($attribute_code, $code)
{
    $query = "SELECT * FROM shop_attribute_option
			  WHERE attribute_code = '" . $attribute_code . "'
			  	AND company = '" . $GLOBALS['shop']['company'] . "'
			  	AND CODE = '" . $code . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $row = mysqli_fetch_assoc($result);
    return $row;
}

//Funktionen für Artikelmerkmalfilter ---


function get_permissions_group_customer()
{
    if ($GLOBALS["shop_customer"]["customer_no"] <> '') {
        $customer_no = $GLOBALS["shop_customer"]["customer_no"];
    } else {
        $customer_no = $_SESSION['input_customer_no'];
    }

    //SH: 31.08.21 T21-13879 cust_no nicht leer, da sonst ohne customer auch Artikel mit Berechtigungsgruppen gefunden werden
    if ($customer_no == ""){
        $customer_no = "no_permission_items";
    }

    if ($GLOBALS['shop']['company'] <> '') {
        $company = $GLOBALS['shop']['company'];
    } else {
        $company = $GLOBALS['language']['company'];
    }

    $query_permission_customer = "SELECT * 
								  FROM shop_permissions_group_link
								  WHERE company = '" . $company . "'
									 AND type = 0
									 AND customer_no = '" . $customer_no . "'";

    $result_permission_customer = mysqli_query($GLOBALS['mysql_con'], $query_permission_customer);
    $numrows = @\DynCom\Compat\Compat::mysqli_num_rows($result_permission_customer);
    $permission_customer = "";
    for ($i = 1; $i <= $numrows; $i++) {
        $row = mysqli_fetch_assoc($result_permission_customer);
        if ($i == 1) {
            $permission_customer .= " AND (";
        } else {
            $permission_customer .= " OR ";
        }
        $permission_customer .= "shop_permissions_group_link.permission_group_code = '" . $row["permission_group_code"] . "' ";
        if ($i == $numrows) {
            $permission_customer .= " OR (shop_permissions_group_link.permission_group_code IS NULL 
										AND shop_permissions_group_link.customer_no IS NULL)
									  OR shop_permissions_group_link.customer_no = '" . $customer_no . "')";
        }
    }
    if ($permission_customer == "") {
        $permission_customer .= "AND (shop_permissions_group_link.customer_no = '" . $customer_no . "'
									  OR shop_permissions_group_link.customer_no IS NULL)";
    }
    return $permission_customer;
}

function get_newsletter_setup()
{
    $query = "SELECT * FROM newsletter_setup";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $setup = mysqli_fetch_assoc($result);
    return $setup;
}

//gewicht pro 100 gramm
function get_gram_price($item)
{
    $query = "SELECT * FROM shop_item WHERE id = '" . $item['id'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    while ($row = mysqli_fetch_object($result)) {
        $weight = $row->net_weight;
        $price = $row->base_price;
    }
    //$weight = $GLOBALS['item']['net_weight'];
    if ($weight > 0) {
        //$price = $GLOBALS["item"]["base_price"]; //netto gewicht!
        $gram_price = $price / $weight * 100;
        $ausgabe = $GLOBALS["tc"]["price_per_gram"] . format_amount($gram_price, true, false);
        return $ausgabe;
    } else {
        return "";
    }
}

//Hersteller Namen für die Itemliste & Karte
function get_brand_name($item, $withoutContainer = false)
{


    $query = "SELECT * FROM shop_attribute_link WHERE company = '" . $GLOBALS['shop']['company'] . "' AND attribute_code = '" . $GLOBALS['shop']['attribute_brand'] . "' AND (`no` != '') AND (`no`= '" . $item['item_no'] . "' OR `no` = '" . $item['parent_item_no'] . "')";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    while ($row = mysqli_fetch_object($result)) {
        $brand_code = $row->value_option;
        $query_2 = "SELECT * FROM shop_attribute_option WHERE company = '" . $GLOBALS['shop']['company'] . "' AND attribute_code = '" . $row->attribute_code . "' AND code = '" . $brand_code . "'";
        $result_2 = mysqli_query($GLOBALS['mysql_con'], $query_2);
        while ($row = mysqli_fetch_object($result_2)) {
            $brand_name = $row->description;
            $brand_url = $row->link;
        }
    }
    /*if ($brand_url != NULL && isset($_GET['card']) && $check != 1) {
        return "<a class='description' href='" . $brand_url . "'>" . $brand_name . "</a>";
    } else {*/
    if ($withoutContainer) {
        return $brand_name;
    }

    return "<span class='item_brand_name'>" . $brand_name . "</span>";

    //}
}

//Hersteller Logo für die Itemkarte
function get_brand_logo( $item ) {
    $query  = "SELECT * FROM shop_attribute_link WHERE company = '" . $item['company'] . "' AND attribute_code = '" . $GLOBALS['shop']['attribute_brand'] . "' AND (NO != '') AND (NO = '" . $item['item_no'] . "' OR NO = '" . $item['parent_item_no'] . "')";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $returnString = "";

    while ($row = mysqli_fetch_object($result)) {

        $brand_code = $row->value_option;
        $query_2    = "SELECT * FROM shop_attribute_option WHERE company = '" . $item['company'] . "' AND attribute_code = '" . $GLOBALS['shop']['attribute_brand'] . "' AND code = '" . $brand_code . "'";
        $result_2   = mysqli_query($GLOBALS['mysql_con'], $query_2);

        if (\DynCom\Compat\Compat::mysqli_num_rows($result_2)) {
            $row = mysqli_fetch_array($result_2,MYSQLI_ASSOC);
            $brand_name = $row["description"];
            $brand_logo = $row["icon"];
            $brand_url  = $row["link"];

            if ($brand_logo) {
                $link1 = "";
                $link2 = "";
                if ($brand_url) {
                    $link1 = "<a href='" . $brand_url . "'>";
                    $link2 = "</a>";
                }
                $returnString .= "<div class='itemcardBrandlogo'>" . $link1 . "<img src='/userdata/dcshop/filter_icon/" . $brand_logo . "' border='0' alt='" . $brand_name . "'>" . $link2 . "</div>";
            }
        }
    }
    return $returnString;

}

function show_special_shipment($item)
{
    //datenbank auf merkmal durchsuchen


    $query = "SELECT attribute_code, value_bool FROM shop_attribute_link WHERE (no='" . $item['item_no'] . "' OR NO='" . $item['parent_item_no'] . "')";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    while ($row = mysqli_fetch_object($result)) {
        //gefundene merkmale auf versand prüfen
        if ($row->attribute_code == $GLOBALS['shop']['attribute_frige_shipping'] && $row->value_bool == 0) {
            $special_shipment = '<div class="special_shipment">' . $GLOBALS["tc"]["shipping_" . $row->attribute_code . ""] . '</div>';
            break;
        } else {
            $special_shipment = "";
        }
    }
    return $special_shipment;
}

function show_food_type($item)
{
    //datenbank auf merkmal durchsuchen
    $query = "SELECT attribute_code, value_bool FROM shop_attribute_link WHERE no='" . $item['item_no'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    while ($row = mysqli_fetch_object($result)) {
        //gefundene merkmale auf versand prüfen
        if ($row->attribute_code == $GLOBALS['shop']['attribute_bio_food'] && $row->value_bool == 1) {
            $food_type = '<div class="food_type"></div>';
            break;
        } else {
            $food_type = "";
        }
    }
    return $food_type;

}

function show_item_rating($item, $average = true, $review = false, $hideSnippt = false)
{
    if ($average == true) {
        $itemnos = get_all_item_nos_for_item($item);
        $itemnos_snippet = " IN (";
        for ($i = 0; $i <= \DynCom\Compat\Compat::count($itemnos); $i++) {
            if ($i > 0) {
                $itemnos_snippet .= ',';
            }
            $itemnos_snippet .= "'" . $itemnos[$i] . "'";
        }
        $itemnos_snippet .= ")";

        $rating_query = "SELECT AVG(rating) AS 'average' 
		FROM shop_item_comments
		WHERE 
			company = '" . $GLOBALS["shop"]["company"] . "'
		  AND
			shop_code = '" . $GLOBALS["shop"]["code"] . "'
		  AND
			language_code = '" . $GLOBALS["shop_language"]["code"] . "'
		  AND
			item_no " . $itemnos_snippet . "
		  AND
			released = 1";
        //echo "<!-- RATING-QUERY: $rating_query -->";
        $rating_result = mysqli_query($GLOBALS['mysql_con'], $rating_query);
        $average = round(mysqli_result($rating_result, 0));
    }

    if ($review) {
        $average = (int)$item["rating"];
    }

    if ($average > 0) {
        if (!$review) {
            if (!$hideSnippt) {
                echo "<meta itemprop=\"ratingValue\" content=\"" . $average . "\" />";
            }

        } else {
            ?>
            <div itemprop="reviewRating" itemscope itemtype="http://schema.org/Rating">
                <meta itemprop="worstRating" content="1">
                <meta itemprop="bestRating" content="5">
                <meta itemprop="ratingValue" content="<?= $average ?>">
            </div>

            <span itemprop="author" itemscope itemtype="http://schema.org/Person">
                 <span itemprop="name" content="<?= $item['name'] ?>"></span>
             </span>
            <div itemprop="itemReviewed" itemscope itemtype="http://schema.org/Thing">
                <span itemprop="name" content="<?= $item['description'] ?>"></span>
            </div>

            <?
        }

        $rating = '<div class="rating_stars">';
        for ($i = 0; $i < $average; $i++) {
            $rating .= "<i class='fa fa-star' aria-hidden='true'></i>";
        }
        for ($i = 0; $i < (5 - $average); $i++) {
            $rating .= "<i class='fa fa-star-o' aria-hidden='true'></i>";
        }
        $rating .= '</div>';
        echo $rating;
    }
}

function get_item_rating($item)
{
    $itemnos = get_all_item_nos_for_item($item);
    $itemnos_snippet = " IN (";
    for ($i = 0; $i < \DynCom\Compat\Compat::count($itemnos); $i++) {
        if ($i > 0) {
            $itemnos_snippet .= ',';
        }
        $itemnos_snippet .= "'" . $itemnos[$i] . "'";
    }
    $itemnos_snippet .= ")";

    $rating_query = "SELECT AVG(rating) AS 'average',COUNT(id) AS 'counter'
	FROM shop_item_comments
	WHERE 
		company = '" . $GLOBALS["shop"]["company"] . "'
	  AND
		shop_code = '" . $GLOBALS["shop"]["code"] . "'
	  AND
		language_code = '" . $GLOBALS["shop_language"]["code"] . "'
	  AND
		item_no " . $itemnos_snippet . "
	  AND
		released = 1";
    $rating_result = mysqli_query($GLOBALS['mysql_con'], $rating_query);
    $rating = mysqli_fetch_assoc($rating_result);
    if ($rating["average"] > 0) {
        $GLOBALS['rating_sum'] = $rating['counter'];
        return $rating;
    } else {
        return false;
    }
}

function get_item_comments($item)
{
    $itemnos = get_all_item_nos_for_item($item);
    $itemnos_snippet = " IN (";
    for ($i = 0; $i < \DynCom\Compat\Compat::count($itemnos); $i++) {
        if ($i > 0) {
            $itemnos_snippet .= ',';
        }
        $itemnos_snippet .= "'" . $itemnos[$i] . "'";
    }
    $itemnos_snippet .= ")";

    $ratings_query = "
	SELECT * 
	FROM shop_item 
	RIGHT JOIN shop_item_comments 
		ON (
			shop_item_comments.company=shop_item.company 
		  AND 
			shop_item_comments.shop_code=shop_item.shop_code 
		  AND 
			shop_item_comments.language_code=shop_item.language_code 
		  AND 
		    shop_item_comments.item_no=shop_item.item_no
		)
	WHERE 
		shop_item_comments.company='" . $GLOBALS['shop']['company'] . "' 
	  AND 
		shop_item_comments.shop_code='" . $GLOBALS['shop']['code'] . "' 
	  AND 
		shop_item_comments.language_code='" . $GLOBALS['shop_language']['code'] . "' 
	  AND 
	    shop_item_comments.item_no " . $itemnos_snippet . "
      AND 
		shop_item_comments.released = 1  ";

    $result = mysqli_query($GLOBALS["mysql_con"], $ratings_query);

    show_item_list($result, 8);
}

function get_inventory_info($item)
{
    $inventory = $item["inventory"];
    switch ($inventory) {
        case ($inventory <= -1):
            echo "<div class=\"cross_red\">" . $GLOBALS["tc"]["check_red"] . "</div>";
            break;
        case ($inventory == 0):
            echo "<div class=\"check_orange\">" . $GLOBALS["tc"]["check_orange"] . "</div>";
            break;
        case ($inventory >= 1):
            echo "<div class=\"check_green\">" . $GLOBALS["tc"]["check_green"] . "</div>";
            break;
        default:
            echo "<div class=\"check_green\">" . $GLOBALS["tc"]["check_green"] . "</div>";
            break;
    }

}

//SH: 20.08.13 digitaler Gutscheinversand +++
function get_dc($dc_id)
{
    $query = "SELECT * FROM shop_digital_coupon WHERE id = '" . $dc_id . "' LIMIT 1";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        $dc = mysqli_fetch_assoc($result);
        if ($dc["id"] <> '') {
            return $dc;
        } else {
            return false;
        }
    } else {
        return false;
    }
}


function finish_dc_order($dc_id)
{
    generate_pdf($dc_id);

}

function create_card_preview($dc, $showImage = true, $showInfos = true)
{
    $dc = get_dc($_SESSION["dc_id"]);
    ?>
    <? if ($showImage) { ?>
    <div id="coupon_background_image"
         style="background-image:url('<?= $GLOBALS["shop_setup"]["dc_image_config"][2]["path"] . '/' . $GLOBALS["shop_language"]["digital_coupon_background_" . $dc["background_image"] . ""] ?>')">
        <div id="coupon_textfields">
        </div>
    </div>
<? } ?>
    <? if ($showInfos) { ?>
    <div id="dc_sender_recipient">
        <h2><?= $GLOBALS["tc"]["sender_recipient"] ?></h2>
        <div id="dc_from_name">
            <? input_shop($GLOBALS["tc"]["from_name"], "from_name", "text", $dc['from_name'], 256, true) ?>
        </div>
        <div id="dc_to_name">
            <? input_shop($GLOBALS["tc"]["to_name"], "to_name", "text", $dc['to_name'], 256, true) ?>
        </div>
    </div>
    <div id="dc_message">
        <h2><?= $GLOBALS["tc"]["greeting_message"] ?></h2>
        <? input_shop("", "message", "textarea", $dc['message'], null, true) ?>
    </div>
<? } ?>
    <?
}

function generate_pdf($dc_id)
{
    $dc = get_dc($_SESSION["dc_id"]);


    $dc_code = "DC" . random_str(7);

    $counter_line = 1;
    $new_code = true;

    while ($dc_code <> '' && $counter_line < 10 && $new_code == true) {
        $query_line = "SELECT id FROM shop_coupon_line
				 WHERE company = '" . $GLOBALS["shop"]["company"] . "'
				 AND coupon_code = '" . $dc_code . "'";
        $result_line = mysqli_query($GLOBALS['mysql_con'], $query_line);

        if (\DynCom\Compat\Compat::mysqli_num_rows($result_line) > 0) {
            $dc_code = "DC" . random_str(7);
            $new_code = true;
            $counter_line++;
        } else {
            $new_code = false;
        }
    }

    insert_dc_code($dc_id, $dc_code);

    require dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'plugins/fpdf/pdf_create.php';
    if ($dc["to_mail"] == '') {
        $dc["to_email"] = $dc["from_email"];

    }
    $dc["from_email"] = $GLOBALS["shop"]["email_sender"];
    $message = get_text_module($GLOBALS['shop']['company'], $GLOBALS["shop_language"]["text_email_digital_coupon"], false, false, true, $GLOBALS["shop_language"]['email_header'], $GLOBALS["shop_language"]['email_footer']);
    $subject = get_text_module(
        $GLOBALS['shop']['company'], $GLOBALS["shop_language"]["text_email_digital_coupon"], '', true, false
    );
    if (mail_create($subject, $message, $dc["from_email"], $dc["to_email"], "", "", true, $filepath, 0, '', 1, $_SESSION['trans_id'])) {
        mail_send();
    }
}

function create_shipping_inputs($dc)
{
    $dc = get_dc($_SESSION["dc_id"]);
    // Ausdrucken: Nur eigene Mailadresse
    echo "<div id=\"dc_shipping_option_inputs0\" style=\"display:none;\">";
    input_shop(
        $GLOBALS["tc"]["your_mail_mandatory"],
        'input_from_email1',
        'text',
        $dc["from_email"],
        200,
        false,
        false,
        ""
    );
    echo "</div>";

    // Per Mail versenden: Empfänger-Adresse, eigener Name, eigene Adresse
    echo "<div id=\"dc_shipping_option_inputs1\">";
    input_shop(
        $GLOBALS["tc"]["mail_recipient_mandatory"],
        'input_from_email2',
        'text',
        $dc["from_email"],
        200,
        false,
        false,
        ""
    );
    //input_shop('E-Mail-Adresse Empfänger*','input_to_email0','text',$dc["to_email"],200,FALSE,FALSE,"");
    echo "</div>";
}

function show_dc_shipping_option($dc)
{
    // Per Mail versenden: Empfänger-Adresse, eigener Name, eigene Adresse
    if ($dc["shipping_option"] == 1) {
        echo "<div id=\"dc_shipping_option\">";
        echo "<div id =\"your_email\">" . str_replace(
                "%to_name%",
                $dc["to_name"],
                $GLOBALS["tc"]["dc_ship_choice_default"]
            ) . "</div> ";
        echo "<div id =\"your_email\">" . $GLOBALS["tc"]["mail_recipient"] . ": " . $dc["from_email"] . "</div> ";
        //echo "<div id =\"to_email\">Email-Adresse Empfänger: ".$dc["to_email"]."</div> ";
        echo "</div>";
    } else {
        echo "<div id=\"dc_shipping_option\">";
        echo str_replace("%from_name%", $dc["from_name"], $GLOBALS["tc"]["dc_ship_choice_own"]);
        echo "<div id =\"your_email\">" . $GLOBALS["tc"]["your_mail"] . ": " . $dc["from_email"] . "</div>";
        echo "</div>";
    }
}

function insert_dc_code($dc_id, $coupon_code)
{

    $pdo = get_main_db_pdo_from_env_single_instance();

    static $getDigitalCouponQuery = '
        SELECT 
            *
        FROM
          shop_digital_coupon
        WHERE
          id = :id
        LIMIT 1
    ';
    $stmt = $pdo->prepare($getDigitalCouponQuery);
    $stmt->bindValue(':id', $dc_id, PDO::PARAM_INT);

    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $digitalCoupon = $stmt->fetch();

    static $couponHeaderQuery = '
        SELECT 
          *
        FROM
          shop_coupon_header
        WHERE
          code = :couponHeaderCode
        AND
          company = :company
    ';

    $stmt = $pdo->prepare($couponHeaderQuery);
    $stmt->bindValue(':company', $GLOBALS["shop"]["company"], PDO::PARAM_STR);
    $stmt->bindValue(':couponHeaderCode', $digitalCoupon["coupon_header"], PDO::PARAM_STR);

    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $couponHeader = $stmt->fetch();

    static $setCouponLineQuery = '
        INSERT INTO shop_coupon_line 
			  SET company = :company,
				  coupon_group = :CouponHeaderCouponGroup,
				  code = :couponHeaderCode,
				  coupon_code = :couponCode,
				  times_used = 0,
				  max_no_of_usage = 0,
				  amount = :couponAmount,
				  amount_left = :couponAmount,
				  value_type = 0,
				  value_coupon = 1,
				  active = 1,
				  last_date_used=CURDATE()
    ';

    $stmt = $pdo->prepare($setCouponLineQuery);
    $stmt->bindValue(':company', $GLOBALS["shop"]["company"], PDO::PARAM_STR);
    $stmt->bindValue(':CouponHeaderCouponGroup', $couponHeader["coupon_group"], PDO::PARAM_STR);
    $stmt->bindValue(':couponHeaderCode', $couponHeader["code"], PDO::PARAM_STR);
    $stmt->bindValue(':couponCode', $coupon_code, PDO::PARAM_STR);
    $stmt->bindValue(':couponAmount', $digitalCoupon["amount"], PDO::PARAM_STR);

    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

    $newCouponLineId = $pdo->lastInsertId();

    static $updateDigitalCouponLineQuery = '
        UPDATE 
          shop_digital_coupon
		SET 
		  shop_coupon_line_id = :insertId
		WHERE 
		  id = :dcId
    ';

    $stmt = $pdo->prepare($updateDigitalCouponLineQuery);
    $stmt->bindValue(':insertId', $newCouponLineId, PDO::PARAM_INT);
    $stmt->bindValue(':dcId', $dc_id, PDO::PARAM_INT);

    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

    static $updateSalesHeaderQuery = '
        UPDATE 
          shop_sales_header
        SET 
          coupon_code = :couponCode
         WHERE 
            payment_transaction_id = :transId
    ';

    $stmt = $pdo->prepare($updateSalesHeaderQuery);
    $stmt->bindValue(':couponCode', $coupon_code, PDO::PARAM_STR);
    $stmt->bindValue(':transId', $_SESSION['trans_id'], PDO::PARAM_STR);

    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

}

function random_prefix($length)
{
    $random = "";
    mt_srand((double)microtime() * 1000000);

    $data = "AbcDE123IJKLMN67QRSTUVWXYZ";
    $data .= "BC123456789";
    $data .= "0FGH45OP89";

    for ($i = 0; $i < $length; $i++) {
        $random .= substr($data, (rand() % (strlen($data))), 1);
    }

    return $random;
}

//digitaler Gutscheinversand ---

function set_vat_lines(
    $visitor_id,
    $vat_group,
    $coupon = false,
    $discount_wo_coupon = 0,
    $markup = 0,
    $shipping_cost  = 0,
    UserBasket $basket = null,
    $online_discount_amount = 0
)
{

    //Berechne gesamt-discount (ohne coupon-discount wenn wertgutschein)
    $discount = (float)$discount_wo_coupon;
    $coupon_discount_amount = 0;
    $coupon_amnt_to_subtract = 0;
    if ($coupon !== false && $coupon["value_coupon"] != 1 && in_array(
            $coupon["value_typ"],
            array(0, 1, 3)
        ) && $coupon['coupon_discount_amount'] > 0
    ) {
        $coupon_discount_amount = $coupon['coupon_discount_amount'];
    } elseif (is_array($coupon) && $coupon["value_coupon"] == 1 && $coupon['coupon_discount_amount'] > 0) {
        $coupon_amnt_to_subtract = $coupon['coupon_discount_amount'];
    }
    $discount += (float)$coupon_discount_amount;
    $markup_discount = (float)$_SESSION['coupon']['amnt_disc_non_items'];
    $markup -= $markup_discount;
    $markup = $markup > 0 ? $markup : 0;

    if (null !== $basket) {
        $discount = $basket->getInvoiceDiscountAmount();
        if ($discount >= $coupon_discount_amount && $coupon_discount_amount > 0) {
            $discount -= $coupon_amnt_to_subtract;
        }
    }


    $vat_excluded_snippet = 'ROUND(((SUM(ub.item_quantity * ub.customer_price))*(svps.vat_percent/100)),2)';
    $vat_included_snippet = 'ROUND(((SUM(ub.item_quantity * ub.customer_price))/(100+svps.vat_percent)*vat_percent),2)';
    $vat_snippet = ($GLOBALS['shop']['prices_including_vat']) ? $vat_included_snippet : $vat_excluded_snippet;

    $vat_query =
        "
	SELECT 
		SUM(ub.item_quantity * ub.customer_price) as 'total_amount',
		svps.vat_prod_posting_group AS 'vat_group',
		svps.vat_percent as 'vat_percent',			
		" . $vat_snippet . " AS 'vat_amount'
	FROM 
		shop_user_basket ub
	LEFT JOIN 
		shop_item 
	  ON 
		shop_item.id=ub.shop_item_id 
	LEFT JOIN 
		shop_vat_posting_setup svps 
	  ON 
		(svps. vat_bus_posting_group = '" . $vat_group . "' AND svps.vat_prod_posting_group=shop_item.vat_prod_posting_group)
	WHERE 
		ub.shop_visitor_id=" . $visitor_id . "
      AND
        svps.company = '" . $GLOBALS['shop']['company'] . "'
	GROUP BY 
		shop_item.vat_prod_posting_group
	ORDER BY 
		vat_percent DESC
	";
    if (!empty($GLOBALS['subscription_item_vat_query'])) {
        $vat_query = $GLOBALS['subscription_item_vat_query'];
    }
    if (!empty($GLOBALS['digital_item_vat_query'])) {
        $vat_query = $GLOBALS['digital_item_vat_query'];
    }
    $i = 0;
    $basket_total_w_vat = 0;
    $basket_total_wo_vat = 0;
    if (null === $basket || $basket->getTotalNoOfPos() == 0) {
        $vat_result = mysqli_query($GLOBALS['mysql_con'], $vat_query);
        while ($vat_line = mysqli_fetch_assoc($vat_result)) {

            $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['total_amount'] = (float)$vat_line['total_amount'];
            $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['vat_group'] = $vat_line['vat_group'];
            $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['vat_percent'] = (float)$vat_line['vat_percent'];
            $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['vat_amount'] = (float)$vat_line['vat_amount'];


            //Basket Total with and without vat
            if ($GLOBALS['shop']['prices_including_vat']) {
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_w_vat'] += (float)$vat_line['total_amount'];
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_wo_vat'] += ((float)$vat_line['total_amount'] - (float)$vat_line['vat_amount']);
            } else {
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_w_vat'] += (float)$vat_line['total_amount'] + (float)$vat_line['vat_amount'];
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_wo_vat'] += (float)$vat_line['total_amount'];
            }
            $i++;
        }
    } else {
        $GLOBALS['vat_order_visitor_' . $visitor_id] = \DynCom\Compat\Compat::array_values($basket->getItemVATData());
        if(isUserShowNet()) {
            foreach($GLOBALS['vat_order_visitor_' . $visitor_id] as &$elem) {
                $elem['total_amount'] += $elem['vat_amount'];
            }
        }
        foreach ($GLOBALS['vat_order_visitor_' . $visitor_id] as $vat_line) {
            //Basket Total with and without vat
            if ($GLOBALS['shop']['prices_including_vat']) {
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_w_vat'] += (float)$vat_line['total_amount'];
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_wo_vat'] += ((float)$vat_line['total_amount'] - (float)$vat_line['vat_amount']);
            } else {
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_w_vat'] += (float)$vat_line['total_amount'] + (float)$vat_line['vat_amount'];
                $GLOBALS['vat_order_visitor_' . $visitor_id][$i]['basket_total_wo_vat'] += (float)$vat_line['total_amount'];
            }
            $i++;
        }
    }

    $discount = $discount + (float)$online_discount_amount;

    if ($discount > 0) {

        $discount_amount[0] = 0;
        $discount_amount_remaining = $discount;
        //Abschläge kleiner gleich Summe Zeilen mit höchstem MwSt-Satz
        if (($discount <= ($GLOBALS['vat_order_visitor_' . $visitor_id][0]['total_amount']))) {

            $new_amount_vat0 = (
                (
                    ($GLOBALS['vat_order_visitor_' . $visitor_id][0]['total_amount'] - $discount)
                    /
                    (100 + $GLOBALS['vat_order_visitor_' . $visitor_id][0]['vat_percent'])
                )
                * $GLOBALS['vat_order_visitor_' . $visitor_id][0]['vat_percent']
            );
            $discount_amount[0] = $discount;
            $discount_amount_remaining = 0;
            $GLOBALS['vat_order_visitor_' . $visitor_id][0]['total_amount'] -= $discount_amount[0];
            $GLOBALS['vat_order_visitor_' . $visitor_id][0]['vat_amount'] = $new_amount_vat0;
            $GLOBALS['vat_order_visitor_' . $visitor_id][0]['discount_amount'] = $discount_amount[0];

            //Abschläge größer Summe Zeilen mit höchstem MwSt-Satz
        } else {
            $discount_amount[0] = $GLOBALS['vat_order_visitor_' . $visitor_id][0]['total_amount'];
            $discount_amount_remaining -= $discount_amount[0];
            $GLOBALS['vat_order_visitor_' . $visitor_id][0]['total_amount'] = 0;
            $GLOBALS['vat_order_visitor_' . $visitor_id][0]['vat_amount'] = 0;
            $GLOBALS['vat_order_visitor_' . $visitor_id][0]['discount_amount'] = $discount_amount[0];

            //Wenn es einen zweiten (nächstniedrigeren) Steuersatz in der Bestellung gibt
            if (isset($GLOBALS['vat_order_visitor_' . $visitor_id][1]['total_amount']) && ((float)$GLOBALS['vat_order_visitor_' . $visitor_id][1]['total_amount'] > 0)) {

                //Wenn Abschläge minus Abschläge MwSt-Gruppe 1 kleiner gleich Summe Zeilen mit zweit-höchstem MwSt-Satz
                if ($discount_amount_remaining <= ($GLOBALS['vat_order_visitor_' . $visitor_id][1]['total_amount'])) {
                    $new_amount_vat1 = (
                        (
                            ($GLOBALS['vat_order_visitor_' . $visitor_id][1]['total_amount'] - $discount)
                            /
                            (100 + $GLOBALS['vat_order_visitor_' . $visitor_id][1]['vat_percent'])
                        )
                        * $GLOBALS['vat_order_visitor_' . $visitor_id][1]['vat_percent']
                    );

                    $discount_amount[1] = $discount_amount_remaining;
                    $discount_amount_remaining = 0;
                    $GLOBALS['vat_order_visitor_' . $visitor_id][1]['total_amount'] -= $discount_amount[1];
                    $GLOBALS['vat_order_visitor_' . $visitor_id][1]['vat_amount'] = $new_amount_vat1;
                    $GLOBALS['vat_order_visitor_' . $visitor_id][1]['discount_amount'] = $discount_amount[1];

                    //Wenn Abschläge größer Summe Zeilen mit höchtem plus Summe Zeilen mit zweit-höchstem MwSt-Satz
                } else {

                    $discount_amount[1] = $GLOBALS['vat_order_visitor_' . $visitor_id][1]['total_amount'];
                    $discount_amount_remaining -= $discount_amount[1];
                    $GLOBALS['vat_order_visitor_' . $visitor_id][1]['total_amount'] = 0;
                    $GLOBALS['vat_order_visitor_' . $visitor_id][1]['vat_amount'] = 0;
                    $GLOBALS['vat_order_visitor_' . $visitor_id][1]['discount_amount'] = $discount_amount[1];

                    //Wenn es einen dritten Steuersatz (0-Prozent) in der Bestellung gibt,
                    //wird das Total des 0-Prozent satzes um den verbleibenden Abschlag (maximal um das Total selbst) reduziert
                    if (isset($GLOBALS['vat_order_visitor_' . $visitor_id][2]['total_amount']) && ((float)$GLOBALS['vat_order_visitor_' . $visitor_id][2]['total_amount'] > 0)) {
                        $discount_amount[2] = (($discount_amount_remaining <= (float)$GLOBALS['vat_order_visitor_' . $visitor_id][2]['total']) ? $discount_amount_remaining : (float)$GLOBALS['vat_order_visitor_' . $visitor_id][2]['total_amount']);
                        $discount_amount_remaining -= $discount_amount[2];
                        $GLOBALS['vat_order_visitor_' . $visitor_id][2]['total_amount'] -= $discount_amount[2];
                        //Immer null, da dritte Gruppe nur 0-Prozent sein kann
                        $GLOBALS['vat_order_visitor_' . $visitor_id][2]['vat_amount'] = 0;
                        $GLOBALS['vat_order_visitor_' . $visitor_id][2]['discount_amount'] = $discount_amount[2];
                    }

                }
            }
        }
    }

    //Aufschläge gänzlich auf niedrigsten MwSt-Satz, wenn Aufschläge > 0
    if ($markup > 0) {
        if (isset($GLOBALS['vat_order_visitor_' . $visitor_id][2]['vat_percent'])) {
            //0%
            vat_markup($visitor_id, $markup, 2);
        } elseif (isset($GLOBALS['vat_order_visitor_' . $visitor_id][1]['vat_percent'])) {
            //7%
            vat_markup($visitor_id, $markup, 1);
        } else {
            //19%
            vat_markup($visitor_id, $markup, 0);
        }
    }

    if ($shipping_cost > 0) {
        $shippingCostsCalculationType = (int)$GLOBALS['shop_setup']['shipping_costs_calculation_type'];
        switch ($shippingCostsCalculationType) {
            case 1: //VAT on highest turnover
                $highestAmountVatPercentageKey = null;
                $highestAmount = 0;
                foreach ($GLOBALS['vat_order_visitor_' . $visitor_id] as $vatPercentageKey => $vat) {
                    if ($vat['total_amount'] > $highestAmount) {
                        $highestAmount = $vat['total_amount'];
                        $highestAmountVatPercentageKey = $vatPercentageKey;
                    }
                }

                if($highestAmountVatPercentageKey !== null) {
                    vat_markup($visitor_id, (float)$shipping_cost, $highestAmountVatPercentageKey);
                }
                break;
            case 0: //by percentage
            default:
                if(isUserShowNet()) {
                    $total = $basket->getBasketTotal() + $basket->getSumVATAmounts();
                } else {
                    $total = $basket->getBasketTotal();
                }
                foreach ($GLOBALS['vat_order_visitor_' . $visitor_id] as  $vatPercentageKey => $vat) {
                    $markup_shipping = $vat['total_amount'] / $total * $shipping_cost;
                    vat_markup($visitor_id, $markup_shipping, $vatPercentageKey);
                }
                break;
        }
    }

    //Übergreifendes total berechnen und speichern
    $order_total_w_vat_amount = 0;
    $order_total_vat_amount = 0;
    $order_total_wo_vat_amount = 0;
    $totalMarkupWithVat = 0;
    $totalMarkupWithoutVat = 0;
    if (is_array($GLOBALS['vat_order_visitor_' . $visitor_id])) {
        foreach ($GLOBALS['vat_order_visitor_' . $visitor_id] as $vat_group_line) {
            $order_total_w_vat_amount += $vat_group_line['total_amount'];
            $order_total_vat_amount += $vat_group_line['vat_amount'];
            $totalMarkupWithVat = $vat_group_line['markup_amount'];
            $totalMarkupWithoutVat = $vat_group_line['markup_amount_wo_vat'];
        }
    }

    $order_total_wo_vat_amount += ($order_total_w_vat_amount - $order_total_vat_amount);
    $GLOBALS['vat_order_visitor_' . $visitor_id][3]['order_total_w_vat'] = $order_total_w_vat_amount;
    $GLOBALS['vat_order_visitor_' . $visitor_id][3]['order_total_wo_vat'] = $order_total_wo_vat_amount;
    $GLOBALS['vat_order_visitor_' . $visitor_id][3]['order_total_markup_w_vat'] = $totalMarkupWithVat;
    $GLOBALS['vat_order_visitor_' . $visitor_id][3]['order_total_markup_wo_vat'] = $totalMarkupWithoutVat;
}

/**
 * @param $visitor_id
 * @param $markup
 * @param int $percentage
 */
function vat_markup($visitor_id, $markup, $percentage): void
{
    /*
     * $percentage 0 => 19%, 1 => 7%, 2 => 0%
     */
    $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['total_amount'] += $markup;
    if ($percentage != 2) {
        $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['vat_amount'] =
            (
                $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['total_amount'] /
                (100 + (float)$GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['vat_percent'])
            ) *
            $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['vat_percent'];
    } else {
        $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['vat_amount'] = 0;
    }
    $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['markup_amount'] = $markup;
    $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['markup_amount_wo_vat'] = (($markup / (100 + $GLOBALS['vat_order_visitor_' . $visitor_id][$percentage]['vat_percent'])) * 100);
}

function get_basket_vat_groups($visitor_id)
{

    $IOC = $GLOBALS['IOC'];
    $currUserBasket = $IOC->create('$currUserBasket');
    if ($currUserBasket instanceof UserBasket) {
        $vat_groups = array_keys($currUserBasket->getVATAmountsPerVATGroup());
        if (!empty($vat_groups)) {
            return $vat_groups;
        }
    }

    $query = "SELECT DISTINCT shop_item.vat_prod_posting_group
			  FROM
				shop_user_basket
			  LEFT JOIN shop_item ON shop_item.id=shop_user_basket.shop_item_id
			  WHERE shop_user_basket.shop_visitor_id = " . $visitor_id;
    //echo "<!-- BASKET-VAT-QUERY: ".$query." -->";
    if (!empty($GLOBALS['subscription_item_vat_group_query'])) {
        $query = $GLOBALS['subscription_item_vat_group_query'];
    }
    if (!empty($GLOBALS['digital_item_vat_group_query'])) {
        $query = $GLOBALS['digital_item_vat_group_query'];
    }
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $vat_group_array = array();
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        \DynCom\Compat\Compat::mysqli_data_seek($result, 0);
        while ($row = mysqli_fetch_assoc($result)) {
            $vat_group_array[] = $row["vat_prod_posting_group"];
        }
    }
    if (\DynCom\Compat\Compat::count($vat_group_array) > 0) {
        return $vat_group_array;
    } else {
        return false;
    }
}

function show_shopping_cart()
{
    ?>
    <div class="shoppingcart">
        <a href="/<? echo customizeUrl(); ?>/basket/">
            <span
                    class="shoppingcard_quantity"><?= $GLOBALS["tc"]["shopping_basket"] . "&nbsp;(" . shop_get_basket_quantity(
                    $GLOBALS["visitor"]["id"]
                ) . ")" ?></span>
            <span
                    class="shoppingcart_amount"><?= format_amount(
                    shop_get_basket_amount($GLOBALS["visitor"]["id"]),
                    false
                ) ?></span>
        </a>
        <!--<div class="shoppingcart_quantity"><?= $GLOBALS["tc"]["items"] ?>: <?= shop_get_basket_quantity(
            $GLOBALS["visitor"]["id"]
        ) ?></div>-->
    </div>
    <?
}

function show_customer_select_list($result, $formname, $inputname = "input_id")
{
    $num_rows = @\DynCom\Compat\Compat::mysqli_num_rows($result);
    if ($num_rows > 0) {
        $firstrow = true;
        $input_arr_counter = 0;
        echo "  <table cellpadding=0 cellspacing=0 border=0 class=\"linklist customer_select_list\">\n";
        while ($row = mysqli_fetch_array($result, 1)) {
            if ($firstrow) {
                echo "    <tr>\n";
                foreach ($row as $key => $value) {
                    $curr_format = ($key <> "id") ? @\DynCom\Compat\Compat::current($format) : "option";
                    echo "      " . format_key($key, $inputname, $curr_format) . "\n";
                    @\DynCom\Compat\Compat::next($format);
                }
                echo "    </tr>\n";
                $firstrow = false;
            }
            if ($row[$GLOBALS['tc']['customer_no']] == $GLOBALS['shop_user']['customer_no']) {
                $active_tr = ' active_tr';
                $checked = true;
            } else {
                $active_tr = '';
                $checked = false;
            }
            $input_arr_text = ($num_rows > 1) ? "[" . $input_arr_counter . "]" : "";
            echo "    <tr class=\"" . $active_tr . "\" onClick=\"document.forms['" . $formname . "']." . $inputname . $input_arr_text . ".checked = true; document.forms['" . $formname . "'].submit();\" " . $dblclick . ">\n";
            $input_arr_counter++;
            @\DynCom\Compat\Compat::reset($format);
            foreach ($row as $key => $value) {
                $curr_format = ($key <> "id") ? @\DynCom\Compat\Compat::current($format) : "option";
                echo "      " . format_value($value, $inputname, $curr_format, $checked) . "\n";
                @\DynCom\Compat\Compat::next($format);
            }
            echo "    </tr>\n";
        }
        echo "  </table>\n";
    }
}

function salesperson_user_select()
{
    $salesperson_code = $GLOBALS['shop_user']['salesperson_code'];
    $customer_no = $GLOBALS['shop_customer']['customer_no'];
    $query = "SELECT * FROM shop_user WHERE customer_no='" . $customer_no . "' ORDER BY main_user=1 DESC,id DESC";
    echo $query;
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        ?>
        <select name="salesperson_user_select">
            <?
            while ($user = mysqli_fetch_assoc($result)) {
                if ($user['id'] = $GLOBAlS['shop_user']['salesperson_user_id']) {
                    $preselect = ' selcted="selected"';
                } else {
                    $preselect = '';
                }
                ?>
                <option value="<?= $user['id'] ?>"><?= $user['name'] ?></option>
                <?
            }
            ?>
        </select>
        <?
    }
}

function side_login()
{

    if (($_POST["input_login"] <> '' || $_POST['input_email'] != '') && ($_POST["input_password"] <> '')) {
        $visitor = get_visitor_small(session_id());
        unset($login_snippet);
        switch ($GLOBALS['shop']['login_type']) {
            case 0:
                $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND UPPER(shop_user.email) = UPPER('" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST["input_email"]
                    ) . "') " : " AND UPPER(email) = UPPER('" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST["input_email"]
                    ) . "') ");
                break;
            case 1:
                $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND UPPER(shop_user.login) = UPPER('" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST["input_login"]
                    ) . "') " : " AND UPPER(salesperson_code) = UPPER('" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST["input_login"]
                    ) . "') ");
                break;
            case 2:
                $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST['input_customer_no']
                    ) . "'  AND UPPER(shop_user.login) = UPPER('" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST["input_login"]
                    ) . "') " : " AND 1=2 "); //Für Salesperson nicht zulässig
                break;
            case 3:
                $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST['input_customer_no']
                    ) . "'  AND UPPER(shop_user.email) = UPPER('" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST["input_email"]
                    ) . "') " : " AND 1=2 "); //Für Salesperson nicht zulässig
                break;
            case 4:
                $login_snippet = ($GLOBALS['shop']['shop_typ'] <> 2 ? " AND shop_user.customer_no = '" . mysqli_real_escape_string(
                        $GLOBALS['mysql_con'],
                        $_POST['input_customer_no']
                    ) . "' " : " AND 1=2 "); //Für Salesperson nicht zulässig
                break;
        }

        $table = 'shop_user';
        switch ($GLOBALS['shop']['shop_typ']) {
            case 0:
                $query = "SELECT shop_user.*
						  FROM shop_user
						  INNER JOIN shop_customer ON shop_customer.customer_no = shop_user.customer_no
						  WHERE 
							shop_user.company = '" . $GLOBALS['shop']['company'] . "'
							AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
							AND shop_customer.company = '" . $GLOBALS['shop']['company'] . "'
							AND shop_customer.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
							AND shop_customer.language_code = '" . $GLOBALS['shop_language']['code'] . "'
							 " . $login_snippet . " 
							AND shop_customer.active = 1
						  LIMIT 1";
                break;
            case 1:
                $query = "SELECT shop_user.*
						  FROM shop_user
						  WHERE shop_user.company = '" . $GLOBALS['shop']['company'] . "'
							AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
							 " . $login_snippet . " 
						  LIMIT 1";
                break;
            case 2:
                $table = 'shop_salesperson';
                $query = "SELECT *
						  FROM shop_salesperson
						  WHERE company = '" . $GLOBALS['shop']['company'] . "'
							 " . $login_snippet . " 
						  LIMIT 1";
                break;
            case 3:
                $query = "SELECT shop_user.*
						  FROM shop_user
						  WHERE shop_user.company = '" . $GLOBALS['shop']['company'] . "'
							AND shop_user.shop_code = '" . $GLOBALS['shop']['customer_source'] . "'
							 " . $login_snippet . " 
						  LIMIT 1";
                break;
        }
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
            $passwordOptions = get_password_options();
            $row = mysqli_fetch_assoc($GLOBALS['mysql_con'], $result);
            $rowID = $row['id'];
            $dbPasswordHash = $row['password'];
            $inputPassword = filter_input(INPUT_POST, 'input_password', FILTER_SANITIZE_STRING);
            $validOldHash = md5($inputPassword) === $dbPasswordHash;
            if ($validOldHash || password_verify($inputPassword, $dbPasswordHash)) {
                if ($validOldHash || password_needs_rehash($dbPasswordHash, PASSWORD_DEFAULT, $passwordOptions)) {
                    $newPasswordHash = password_hash($inputPassword, PASSWORD_DEFAULT, $passwordOptions);
                    $updateQuery = 'UPDATE ' . $table . ' SET password = \'' . $newPasswordHash . '\' WHERE id = ' . $rowID;
                    mysqli_query($GLOBALS['mysql_con'], $updateQuery);
                }
                $shop_user = $row;
                $GLOBALS['visitor']['pass_auth'] = true;
                if ($_POST['remember_login'] == 'on') {
                    $remember_token_unique = md5(
                        uniqid(session_id(), true) . $shop_user['password'] . $GLOBALS['visitor']['main_user_id']
                    );
                } else {
                    $remember_token_unique = '';
                }
                //Neue Session wenn anderer User
                if (($GLOBALS['shop']['shop_typ'] != 2 && ($visitor['main_user_id'] != $shop_user['id'])) || ($GLOBALS['shop']['shop_typ'] == 2 && ($visitor['shop_salesperson_id'] != $shop_user['id']))) {
                    $old_sid = session_id();
                    $old_visitor_id = $visitor['id'];
                    if (session_regenerate_id()) {
                        $new_sid = session_id();
                    }

                    //Alten Visitor löschen
                    $delete_query = "DELETE FROM main_visitor WHERE session_id = '" . $old_sid . "'";
                    @mysqli_query($GLOBALS['mysql_con'], $delete_query);

                    //Neuen visitor anlegen:
                    $user_id_fieldname = (($GLOBALS['shop']['shop_typ'] != 2) ? 'main_user_id' : 'shop_salesperson_id');
                    $insert_query = "
					INSERT INTO 
						main_visitor 
					SET 
						session_id = '" . $new_sid . "', 
						session_date = NOW(), 
						valid_until = DATE_ADD(NOW(), INTERVAL 30 DAY), 
						frontend_login = TRUE,
						" . $user_id_fieldname . " = " . $shop_user['id'] . ",
						remember_token = '" . $remember_token_unique . "'";
                    @mysqli_query($GLOBALS['mysql_con'], $insert_query);
                    $visitor = get_visitor_small($new_sid);
                    $GLOBALS['visitor'] = $visitor;
                    $new_visitor_id = $visitor['id'];

                } elseif ($GLOBALS['shop']['shop_typ'] != 2) {
                    $query = "UPDATE main_visitor
						  SET frontend_login = TRUE, main_user_id = " . $shop_user["id"] . ",cookie_only=0,remember_token='" . $remember_token_unique . "'
						  WHERE id = " . $visitor["id"] . "
						  LIMIT 1";
                } else {
                    $query = "UPDATE main_visitor
						  SET frontend_login = TRUE, main_user_id = " . $shop_user["id"] . ",cookie_only=0,remember_token='" . $remember_token_unique . "',
						  shop_salesperson_id = " . $shop_user['id'] . "
						  WHERE id = " . $visitor["id"] . "
						  LIMIT 1";
                }
                if (mysqli_query($GLOBALS['mysql_con'], $query)) {
                    if ($_POST['remember_login'] == 'on') {
                        //echo "<!-- REMEMBER LOGIN - SET COOKIE -->";
                        $cookie_timeout = COOKIE_DAYS_VALID * 24 * 60 * 60;
                        setcookie(
                            $GLOBALS['site']['code'] . '_remember_login',
                            $remember_token_unique,
                            time() + $cookie_timeout,
                            '/'
                            , NULL, NULL, true);
                        setcookie('sid' . $GLOBALS['site']['code'], session_id(), time() + $cookie_timeout, '/', NULL, NULL, true);
                    }
                }
                //visitor aktualisieren
                $visitor = get_visitor_small(session_id());
                $GLOBALS["visitor"] = $visitor;
                //Warenkorb und Favoriten für angemeldete Benutzer ermitteln
                if ($shop_user['last_visitor_id'] != '') {
                    $currbasket_query = "SELECT COUNT(id) AS 'counter' FROM shop_user_basket WHERE shop_visitor_id=" . $shop_user['last_visitor_id'];
                    $currbasket_result = mysqli_query($GLOBALS['mysql_con'], $currbasket_query);
                    $currbasket_no = mysqli_result($currbasket_result, 0, 0);
                    if (((int)$currbasket_no > 0)) {
                        $query = "UPDATE shop_user_basket
							  SET shop_visitor_id = " . $GLOBALS['visitor']['id'] . "
							  WHERE shop_visitor_id = " . $shop_user['last_visitor_id'];
                        mysqli_query($GLOBALS['mysql_con'], $query);
                    } else { //Kann mit Konditional raus.
                        $query = "DELETE FROM shop_user_basket
							  WHERE shop_visitor_id = " . $shop_user["last_visitor_id"];
                        mysqli_query($GLOBALS['mysql_con'], $query);
                    }
                    $query = "UPDATE shop_user_favorites
						  SET shop_visitor_id = " . $GLOBALS['visitor']['id'] . "
						  WHERE shop_visitor_id = " . $shop_user['last_visitor_id'];
                    mysqli_query($GLOBALS['mysql_con'], $query);
                    $query = "UPDATE shop_user
						  SET last_visitor_id = " . $GLOBALS['visitor']['id'] . "
						  WHERE id = " . $shop_user['id'];
                    mysqli_query($GLOBALS['mysql_con'], $query);
                }
                $_SESSION['pass_auth'] = true;
                $GLOBALS['visitor']['pass_auth'] = true;
                $_SESSION['sid' . $GLOBALS['site']['code']] = session_id();

            } else {
                $_POST['login_error'] = true;
            }

        } else {
            $_POST['login_error'] = true;
        }
        if (isset($_POST['login_error']) && true === $_POST['login_error']) {
            $newaddress = $_SERVER['HTTP_REFERER'];
            $newaddress = str_replace(
                array('?action=shop_login', '&action=shop_login', '?action=login', '&action=login'),
                '',
                $newaddress
            );
            unset($_GET['action']);
            unset($_POST['action']);
            if (strpos($newaddress, '?') !== false) {
                $newaddress .= "&login_error=true";
            } else {
                $newaddress .= "?login_error=true";
            }
            headerFunctionBridge("Location: " . $newaddress);
        }
    }
}

function get_attribute_value_translation($attribute_code, $number, $option_code)
{
    $query = "
	SELECT 
	(CASE
	  WHEN
		(
			'" . $GLOBALS["shop_language"]["code"] . "' != '" . $GLOBALS["shop"]["default_language_code"] . "' 
		  AND
			CHAR_LENGTH(TRIM(sat.description))>0
		)
		THEN 
			sat.description
	  WHEN
		(
			'" . $GLOBALS["shop_language"]["code"] . "' = '" . $GLOBALS["shop"]["default_language_code"] . "' 
		)
		THEN
		  sao.description
	END) AS description
	FROM shop_attribute_option sao
	LEFT JOIN shop_attribute_translation sat ON (sat.type='" . $number . "' AND sat.company=sao.company AND sat.attribute_code=sao.attribute_code AND sat.attribute_link_no=sao.code)
	WHERE 
	    sao.company = '" . $GLOBALS['shop']['company'] . "'
	  AND
	  	sao.attribute_code = '" . $attribute_code . "'
	  AND
		sao.code = '" . $option_code . "'
		AND
        (
            CASE WHEN (
                '" . $GLOBALS["shop_language"]["code"] . "' != '" . $GLOBALS["shop"]["default_language_code"] . "'
              AND
                CHAR_LENGTH(TRIM(sat.description))>0
            )
            THEN
			    sat.language_code = '" . $GLOBALS['shop_language']['code'] . "'
            ELSE 1 END
        )
	";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    $description = mysqli_result($result, 0, 0);
    if (strlen($description) > 0) {
        return $description;
    } else {
        return false;
    }
}

function unset_session_safe()
{
    $sid = $_SESSION['sid' . $GLOBALS['site']['code']];
    session_unset();
    $_SESSION['sid' . $GLOBALS['site']['code']] = $sid;
}

function tokenizeSearchTable()
{
    $source_table = 'shop_item_search';
    $target_table = 'tokentable';

    $stopwords_ger = array(
        'aber',
        'als',
        'am',
        'an',
        'auch',
        'auf',
        'aus',
        'bei',
        'bin',
        'bis',
        'bist',
        'da',
        'dadurch',
        'daher',
        'darum',
        'das',
        'daß',
        'dass',
        'dein',
        'deine',
        'dem',
        'den',
        'der',
        'des',
        'dessen',
        'deshalb',
        'die',
        'dies',
        'dieser',
        'dieses',
        'doch',
        'dort',
        'du',
        'durch',
        'ein',
        'eine',
        'einem',
        'einen',
        'einer',
        'eines',
        'er',
        'es',
        'euer',
        'eure',
        'für',
        'hatte',
        'hatten',
        'hattest',
        'hattet',
        'hier',
        'hinter',
        'ich',
        'ihr',
        'ihre',
        'im',
        'in',
        'ist',
        'ja',
        'jede',
        'jedem',
        'jeden',
        'jeder',
        'jedes',
        'jener',
        'jenes',
        'jetzt',
        'kann',
        'kannst',
        'können',
        'könnt',
        'machen',
        'mein',
        'meine',
        'mit',
        'muß',
        'mußt',
        'musst',
        'müssen',
        'müßt',
        'nach',
        'nachdem',
        'nein',
        'nicht',
        'nun',
        'oder',
        'seid',
        'sein',
        'seine',
        'sich',
        'sie',
        'sind',
        'soll',
        'sollen',
        'sollst',
        'sollt',
        'sonst',
        'soweit',
        'sowie',
        'und',
        'unser	',
        'unsere',
        'unter',
        'vom',
        'von',
        'vor',
        'wann',
        'warum',
        'was',
        'weiter',
        'weitere',
        'wenn',
        'wer',
        'werde',
        'werden',
        'werdet',
        'weshalb',
        'wie',
        'wieder',
        'wieso',
        'wir',
        'wird',
        'wirst',
        'wo',
        'woher',
        'wohin',
        'zu',
        'zum',
        'zur',
        'über'
    );

    $stopwords_enu = array(
        "a",
        "about",
        "above",
        "after",
        "again",
        "against",
        "all",
        "am",
        "an",
        "and",
        "any",
        "are",
        "aren't",
        "as",
        "at",
        "be",
        "because",
        "been",
        "before",
        "being",
        "below",
        "between",
        "both",
        "but",
        "by",
        "can't",
        "cannot",
        "could",
        "couldn't",
        "did",
        "didn't",
        "do",
        "does",
        "doesn't",
        "doing",
        "don't",
        "down",
        "during",
        "each",
        "few",
        "for",
        "from",
        "further",
        "had",
        "hadn't",
        "has",
        "hasn't",
        "have",
        "haven't",
        "having",
        "he",
        "he'd",
        "he'll",
        "he's",
        "her",
        "here",
        "here's",
        "hers",
        "herself",
        "him",
        "himself",
        "his",
        "how",
        "how's",
        "i",
        "i'd",
        "i'll",
        "i'm",
        "i've",
        "if",
        "in",
        "into",
        "is",
        "isn't",
        "it",
        "it's",
        "its",
        "itself",
        "let's",
        "me",
        "more",
        "most",
        "mustn't",
        "my",
        "myself",
        "no",
        "nor",
        "not",
        "of",
        "off",
        "on",
        "once",
        "only",
        "or",
        "other",
        "ought",
        "our",
        "ours	",
        "ourselves",
        "out",
        "over",
        "own",
        "same",
        "shan't",
        "she",
        "she'd",
        "she'll",
        "she's",
        "should",
        "shouldn't",
        "so",
        "some",
        "such",
        "than",
        "that",
        "that's",
        "the",
        "their",
        "theirs",
        "them",
        "themselves",
        "then",
        "there",
        "there's",
        "these",
        "they",
        "they'd",
        "they'll",
        "they're",
        "they've",
        "this",
        "those",
        "through",
        "to",
        "too",
        "under",
        "until",
        "up",
        "very",
        "was",
        "wasn't",
        "we",
        "we'd",
        "we'll",
        "we're",
        "we've",
        "were",
        "weren't",
        "what",
        "what's",
        "when",
        "when's",
        "where",
        "where's",
        "which",
        "while",
        "who",
        "who's",
        "whom",
        "why",
        "why's",
        "with",
        "won't",
        "would",
        "wouldn't",
        "you",
        "you'd",
        "you'll",
        "you're",
        "you've",
        "your",
        "yours",
        "yourself",
        "yourselves"
    );

    $importance_mapping = array(
        'description' => 1,
        'variant_description' => 1,
        'item_search_terms' => 1,
        'description_2' => 0.8,
        'variant_description_2' => 0.8,
        'category_search_terms' => 0.8,
        'long_description' => 0.4
    );

    $query = 'SELECT `item_id`';
    foreach ($importance_mapping AS $key => $value) {
        $query .= ', `' . $key . '`';
    }
    $query .= ' FROM `' . $source_table . '`';
    $result = @mysqli_query($GLOBALS['mysql_con'], $query);
    while ($row = @mysqli_fetch_assoc($result)) {
        foreach ($importance_mapping AS $key => $importance) {
            if (!empty($row[$key])) {
                $orig_str = $row[$key];
                $prepared_str_4 = strip_tags($orig_str);
                $prepared_str_3 = preg_replace('/[^\s-\d\p{L}]/', ' ', $prepared_str_4);
                $prepared_str_2 = preg_replace('/\s+/', ' ', $prepared_str_3);
                $prepared_str = \DynCom\Compat\Compat::strtolower(preg_replace('/\s\d+\s/', ' ', $prepared_str_2));
                $arr = explode(' ', $prepared_str);
                foreach ($arr as $token) {
                    if (($token != ' ' && $token != '' && !ctype_digit($token) && strlen($token) > 1) && !(in_array(
                            $token,
                            $stopwords_ger
                        )) && !(in_array($token, $stopwords_enu))
                    ) {
                        $insert_query = 'INSERT INTO `' . $target_table . '` SET token=\'' . $token . '\', document_id=' . $row['item_id'] . ', importance=' . $importance;
                        @mysqli_query($GLOBALS['mysql_con'], $insert_query);
                    }
                }
            }
        }
    }
}

function URLnormalize($string)
{

    //Trim
    $string = trim($string);

    //Alle Umlaute umschreiben
    $string = str_replace(
        array('ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'),
        array('ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss'),
        $string
    );

    //Alle Sonderzeichen außer Plus zu '-' machen
    $string = preg_replace('/([^a-zA-Z0-9+-_\/]|[.])/', '-', $string);

    //Doppelte Vorkommen von '+' und '-' mit einzelnen ersetzen
    $string = \DynCom\Compat\Compat::strtolower(preg_replace('/([+-]){2,}/', '$1', $string));

    return $string;
}

function set_global_metadata_shop()
{
//Sonderkategorien
    if ($_GET['shop_category'] == 'search') {
        $site_title = $GLOBALS["tc"]["search"];
        $meta_description = get_meta_description();
        $meta_keywords = get_meta_keywords();
    } elseif ($_GET['shop_category'] == 'order') {
        $site_title = $GLOBALS["tc"]["order"];
        $meta_description = get_meta_description();
        $meta_keywords = get_meta_keywords();
    } elseif ($_GET['shop_category'] == 'basket' && !isset($_GET['action'])) {
        $site_title = $GLOBALS["tc"]["shopping_basket"];
        $meta_description = get_meta_description();
        $meta_keywords = get_meta_keywords();
    } elseif ($_GET['shop_category'] == 'account') {
        $site_title = $GLOBALS["tc"]["my_account"];
        $meta_description = get_meta_description();
        $meta_keywords = get_meta_keywords();
    } elseif ($_GET['shop_category'] == 'favorites') {
        if ($GLOBALS["shop"]["shop_typ"] == 1) {
            $site_title = $GLOBALS["tc"]["favorites"];
        } else {
            $site_title = $GLOBALS["tc"]["favorites_b2b"];
        }
        $meta_description = get_meta_description();
        $meta_keywords = get_meta_keywords();
    } elseif ($_GET['shop_category'] == 'basket' && $_GET['action'] == 'greeting_card') {
        $site_title = $GLOBALS["tc"]["greeting_card"];
        $meta_description = get_meta_description();
        $meta_keywords = get_meta_keywords();
    } elseif ($_GET['shop_category'] == 'basket' && $_GET['action'] == 'gift_package') {
        $site_title = $GLOBALS["tc"]["info_gift_wrapping"];
        $meta_description = get_meta_description();
        $meta_keywords = get_meta_keywords();
    } else {
        //Artikelkategorie oder Artikelkarte
        if (is_array($GLOBALS['category']) || (int)$_GET['card'] > 0) {
            if (!(int)$_GET['card'] > 0) {
                $site_title = $GLOBALS['category']['name'];
                if ($GLOBALS['category']['site_titel'] != "") {
                    $site_title = $GLOBALS['category']['site_titel'];
                }
            } else {
                $tmpItem = &$GLOBALS['item'];
                if (is_object($tmpItem) && $tmpItem instanceof WebshopItem) {
                    $GLOBALS['item'] = $tmpItem->getAllFieldsAsArray();
                }
                $site_title = $GLOBALS['item']['description'];
                if ($GLOBALS['item']['site_title'] != "") {
                    $site_title = $GLOBALS['item']['site_title'];
                }
            }
            $meta_description = get_meta_description();
            $GLOBALS['meta_description'] = $meta_description;
            $meta_keywords = get_meta_keywords();
        }
    }
    if (!empty($site_title)) {
        $GLOBALS['site_title'] = $site_title . ' | ' . $GLOBALS["language"]["site_title_name"];
    }
    if (!empty($meta_keywords)) {
        $GLOBALS['meta_keywords'] = $meta_keywords;
    }
    if (!empty($meta_description)) {
        $query_0 = "SELECT content from shop_text_module where company = '".$GLOBALS['shop']['company']."' and code = (
	        SELECT meta_description_template from shop_language where company = '".$GLOBALS['shop']['company']."' and shop_code = '".$GLOBALS['shop']['item_source']."' and code = '".$GLOBALS['shop_language']['code']."')";
        $result_0 = mysqli_query($GLOBALS['mysql_con'], $query_0);
        $meta_desc_end = mysqli_fetch_array($result_0)[0];
        $GLOBALS['meta_description'] = $meta_description.$meta_desc_end;
    }
}

function get_item_user_notifications($item)
{
    $notifications = [];
    try {
        $IOCContainer = &$GLOBALS['IOC'];
        $builder = $IOCContainer->create('DynCom\dc\dcShop\classes\WebshopItemBuilder');
        if ($item instanceof BasketEntity) {
            $item = $item->getOrderableEntity();
        }
        $itemWithCategories = $builder->decorateWebshopItemCategories($item);
        foreach ($GLOBALS['active_campaigns'] as $campaign) {
            if ($campaign instanceof \DynCom\dc\dcShop\campaigns\GenericCampaign) {
                $actionElements = $campaign->getActionElements();
                $hasItemCampaignAction = \DynCom\Compat\Compat::count($actionElements) > 0 &&
                    $actionElements->itemMeetsCriteria($itemWithCategories);

                if ($hasItemCampaignAction) {
                    $notifications['actions'][] = [
                        'text' => $campaign->getTextActionItems()
                            ? get_text_module($GLOBALS['shop']['company'], $campaign->getTextActionItems(), false, false, false)
                            : '',
                        'icon' => $campaign->getIconPathActionItems(),
                        'banner' => $campaign->getBannerPathActionItems()
                    ];
                }

                $conditionElements = $campaign->getConditionElements();
                $itemMeetsCampaignConditions = \DynCom\Compat\Compat::count($conditionElements) === 0 ||
                    $conditionElements->itemMeetsCriteria($itemWithCategories);

                if ($itemMeetsCampaignConditions) {
                    $notifications['conditions'][] = [
                        'text' => $campaign->getTextConditionItems()
                            ? get_text_module($GLOBALS['shop']['company'], $campaign->getTextConditionItems(), false, false, false)
                            : '',
                        'icon' => $campaign->getIconPathConditionItems(),
                        'banner' => $campaign->getBannerPathConditionItems()
                    ];
                }

                if ($_GET['shop_category'] === 'basket') {
                    $notifications['basket'][] = [
                        'text' => $campaign->getTextBasket()
                            ? get_text_module($GLOBALS['shop']['company'], $campaign->getTextBasket())
                            : '',
                        'icon' => $campaign->getBannerBasket()
                    ];
                }

            }
        }

        $priceData = $item->getPriceData();
        if ($priceData instanceof ItemPriceDataInterface && $priceData->getUnitPrice() > 0) {
            $appliedDiscounts = $priceData->getAppliedLineDiscounts();
            foreach ($appliedDiscounts as $discount) {
                if ($discount instanceof AppliedDiscount) {
                    if ($discount->getSourceType() === DiscountBase::DISCOUNT_SOURCE_TYPE_RULE) {
                        $notification = $discount->getNotification();
                        $valueSubstr = $discount->getDiscountValue();
                        $valueType = $discount->getDiscountValueType();
                        if ($valueType === DiscountBase::DISCOUNT_VALUE_TYPE_AMOUNT) {
                            $valueStr = number_format($valueSubstr, 2, ',', '.') . ' €';
                        } else {
                            $valueStr = $valueSubstr . '%';
                        }
                        if (!empty($notification)) {
                            $notifications['discounts'][] = ['text' => $notification, 'value' => $valueStr];
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        //Do noting
    }
    if ($item instanceof BasketEntity) {
        $creationSource = $item->getCreationSourceType();
        if ($creationSource === BasketEntity::CREATION_SOURCE_TYPE_RULE || $creationSource === BasketEntity::CREATION_SOURCE_TYPE_COUPON) {
            $notifications['creation'] = $item->getCreationNotification();
        }
    }
    return $notifications;
}

function logout_customer_invalid_language(&$visitor, $shopLanguage, $dbConn)
{

    //Check preconditions
    if (is_array($visitor) && $dbConn instanceof mysqli && isset($visitor['id']) && !empty($shopLanguage)) {
        //Check language match
        if ($visitor['frontend_login']) {
            $query = "SELECT sc.language_code AS 'language_code' FROM shop_user su LEFT JOIN shop_customer sc ON sc.customer_no = su.customer_no WHERE su.last_visitor_id = " . (int)$visitor['id'];
            $result = mysqli_query($dbConn, $query);
            $arr = mysqli_fetch_assoc($result);
            if (is_array($arr) && \DynCom\Compat\Compat::count($arr) > 0 && isset($arr['language_code'])) {
                $language_code = $arr['language_code'];
                if ($language_code !== $shopLanguage) {
                    //Logout visitor
                    $visitor['frontend_login'] = 0;
                    $query2 = "UPDATE main_visitor SET frontend_login = 0 WHERE id = " . (int)$visitor['id'];
                    mysqli_query($dbConn, $query2);
                }
            }
        }
    }
}

function get_shop_breadcrumb()
{
    ?>
    <?php
    if (isset($_GET['shop_category']) && $_GET['shop_category'] != "dc_order" && $_GET['shop_category'] != "queue") {
        $breadcrumb = '<span><a href="/' . customizeUrl() . '/"><span>' . $GLOBALS["tc"]["homepage"] . '</span></a></span>&nbsp;<span class=\'selector\'>/</span>&nbsp;';

        if (($_GET["shop_category"] == 'search') && ($_REQUEST["input_search"] <> '' || $_SESSION['search'] != "") && ($_REQUEST["input_search"] != $GLOBALS["tc"]["search_term"]) && !isset($_GET["card"])) {
            if (!empty($_SESSION['search']) && empty($_REQUEST["input_search"])) {
                $_REQUEST["input_search"] = $_SESSION['search'];
            }
            //Ausgabe Suchbegriff in Navigationsleiste
            //$breadcrumb_shopcategory = $GLOBALS["tc"]["search"] . ' : ' . htmlspecialchars($_REQUEST['input_search'], ENT_QUOTES, "UTF-8");
            $breadcrumb_shopcategory = $GLOBALS["tc"]["search"];
        }
        if ($_GET["shop_category"] == 'basket') {
            $breadcrumb_shopcategory = $GLOBALS["tc"]["shopping_basket"];
            if ($_GET['action'] == 'gift_package') {
                $breadcrumb_shopcategory = $GLOBALS["tc"]["info_gift_wrapping"];
            }
            if ($_GET['action'] == 'greeting_card_text') {
                $breadcrumb_shopcategory = $GLOBALS["tc"]["greeting_card"];
            }
        }
        if ($_GET["shop_category"] == 'favorites') {
            if ($GLOBALS["shop"]["shop_typ"] == 1) {
                $breadcrumb_shopcategory = $GLOBALS["tc"]["favorites"];
            } else {
                $breadcrumb_shopcategory = $GLOBALS["tc"]["favorites_b2b"];
            }
        }

        if ($_GET["shop_category"] == 'order') {
            $breadcrumb_shopcategory = $GLOBALS["tc"]["user_order"];
        }

        if ($_GET["shop_category"] == 'account') {
            $breadcrumb_shopcategory = $GLOBALS["tc"]["shop_account"];
            if(isset($GLOBALS['tc'][$_GET['action']])){
                $breadcrumb_shopcategory = $GLOBALS['tc'][$_GET['action']];
            }
        }
        if ($_GET['shop_category'] == 'rma') {
            $breadcrumb_shopcategory = $GLOBALS["tc"]["rma"];
        }

        if ($_GET['shop_category'] == 'registration') {
            $breadcrumb_shopcategory = $GLOBALS["tc"]["registration_form"];
        }

        if ($_GET['shop_category'] == 'my_items') {
            $breadcrumb_shopcategory = $GLOBALS["tc"]["my_items"];
        }

        $breadcrumb .= "<span class='current'>" . $breadcrumb_shopcategory . "</span>";
    } else {


        if (isset($_GET['card']) && $_GET['card'] <> '') {
            // 1. get the item category
            // 2 check if item category = last item session
            // is equal session category with item category then set category with session
            // if the session category is not equal to the item category set the category to the item main category
            $itemData = get_item_by_id($_GET['card']);
            $itemCategories = get_item_has_categories($itemData);
            $isItemFound = false;
            if (isset($_SESSION['current_item_category']) && $_SESSION['current_item_category']['id'] <> '') {
                for ($i = 0; $i < \DynCom\Compat\Compat::count($itemCategories); $i++) {
                    if ($itemCategories[$i]['id'] == $_SESSION['current_item_category']['id']) {
                        $isItemFound = true;
                        $category = $_SESSION['current_item_category'];
                    }

                }
                if (!$isItemFound) {
                    $category = get_item_default_category($itemData);
                }

            } else {
                $category = get_item_default_category($itemData);
            }

        } else {
            $category = $GLOBALS['category'];
        }
        $_SESSION['current_item_category'] = $category;

        if ($category["id"] <> '') {
            $breadcrumb = curr_category_path($category, ($_GET["card"] <> ''));
        } else {
            if ($_GET["slevel_2"]) {
                //$breadcrumb = '&nbsp;<i class="fa fa-angle-right" aria-hidden="true"></i>&nbsp;<a href="">' . $_GET["slevel_2"] . '</a>' . curr_category_path($category, ($_GET["card"] <> ''));
                $breadcrumb = curr_category_path($category, ($_GET["card"] <> ''));
            } else {
                $query = "SELECT * FROM  `shop_item` WHERE  `id` ='" . (int)$_GET["card"] . "'";
                $result = @mysqli_query($GLOBALS['mysql_con'], $query);
                if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
                    $item = @mysqli_fetch_assoc($result);
                    $line_no = $item['main_category_line_no'];
                }
                if (isset($line_no)) {
                    $query = "SELECT * FROM  `shop_category` WHERE  `line_no` =" . $line_no;
                    $result = @mysqli_query($GLOBALS['mysql_con'], $query);
                    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
                        $category = @mysqli_fetch_assoc($result);
                    }
                }
                $breadcrumb = curr_category_path($category, ($_GET["card"] <> ''));
            }
        }
    }
    if (show_my_items_only()) {
        $breadcrumb = '<span><a href="/' . customizeUrl() . '/"><span>' . $GLOBALS['tc']['homepage'] . '</span></a></span>&nbsp;<i class="fa fa-angle-right" aria-hidden="true"></i>&nbsp;';
        $breadcrumb .= '<span class="current"><a href="/' . customizeUrl() . '/my_items/">' . $GLOBALS["tc"]["my_items"] . '</a></span>';
    }
    ?>
    <div class="breadcrumbWrapper">
        <? if (isset($_GET['card'])) { ?>
            <div class="breadcrumb"><?= $breadcrumb; ?></div>
            <? if (isset($_GET['card'])) {

                if (!isset($IOCContainer)) {
                    $IOCContainer = $GLOBALS['IOC'];
                }
                /** @var WebshopItemBuilder $itemBuilder */
                $itemBuilder = $IOCContainer->create('DynCom\dc\dcShop\classes\WebshopItemBuilder');
                /** @var \DynCom\dc\dcShop\classes\CurrShopConfiguration $currShopConfig */
                $currShopConfig = $IOCContainer->create('$CurrShopConfig');

                $id = (int)$_GET['card'];
                $varCode = filter_var($_GET['variant'], FILTER_SANITIZE_STRING);
                /** @var  $itemObj WebshopItem */
                if ((int)$currShopConfig->getShopType() === 0) {
                    $itemObj = $itemBuilder->getWebshopItemByID($id);
                } else {
                    $itemObj = $itemBuilder->getFirstVariant($id, $varCode);
                }

                $breadcrumb .= '&nbsp;<span class=\'selector\'>/</span>&nbsp;<span>' . htmlentities(
                        $itemObj->getDescription()
                    ) . '</span>';
                $itemNo = $itemObj->getItemNo();
                if ($itemObj->getParentItemNo() != '') {
                    $itemNo = $itemObj->getParentItemNo();
                }
                $pageParameter = '';
                if (isset($_GET['page']) && $_GET['page'] != '') {
                    $pageParameter = '?page=' . $_GET['page'];
                }
                if ($_GET['search'] == 1) {

                    ?>
                    <a class="breadcrumbBackbutton"
                       href="/<? echo customizeUrl(); ?>/search/<?= $pageParameter ?>&term=<?= $_SESSION['search'] ?>#item_<?= $itemNo ?>"><i class="icon icon-angle-left"></i><?= $GLOBALS["tc"]["back_to_search"] ?></a>
                <? } else if (show_my_items_only()) {
                        ?>
                        <a class="breadcrumbBackbutton"
                           href="/<? echo customizeUrl(); ?>/my_items/#item_<?= $itemNo ?>"><i class="fa fa-angle-left"></i><?= $GLOBALS["tc"]["back_to_my_items"] ?></a>
                        <?
                    } else {
                    if ($pageParameter != '') {
                        $pageParameter = '?page=' . $_GET['page'];
                    }
                    $backurl = $_SERVER["REDIRECT_URL"];
                    if ($_GET['card']) {
                        $backurl = category_get_path($category) . $itemObj->getItemSlug() . "-p" . $id . "/";
                    }

                    $backurl = explode('/', $backurl, -1);
                    $arrcount = \DynCom\Compat\Compat::count($backurl);
                    $arrcount--;
                    $arrcount--;
                    $i = 0;
                    $back_url = "<a class='breadcrumbBackbutton' href='";
                    while ($i < $arrcount) {
                        $i++;
                        $back_url .= "/" . $backurl[$i];
                    }
                    $back_url .= "/" . $pageParameter . "#item_" . $itemNo;
                    $back_url .= "'><i class=\"icon icon-angle-left\"></i>" . $GLOBALS['tc']['back_itemcard'] . "</a>";


                    // $back_url .= "/'>" . $GLOBALS['tc']['back_itemcard'] . "</a>";
                    if ($GLOBALS['category'] == NULL && $_SESSION['search'] == NULL && !isset($_GET['card'])) {
                        echo "<a class='breadcrumbBackbutton' href='/" . customizeUrl() . "/'><i class=\"icon icon-angle-left\"></i>" . $GLOBALS['tc']['back_startpage'] . "</a>";
                    } else {
                        echo $back_url;
                    }
                }
            } ?>
        <? } else { ?>
            <div class="breadcrumb"><?= $breadcrumb; ?></div>
        <? } ?>
    </div>
    <?
}

function get_shop_shipping_option(UserBasket $basket)
{
    $IOCContainer = $GLOBALS['IOC'];
    $currShopConfig = $IOCContainer->resolve('$CurrShopConfig');
    $countryCode = '';
    if (isset($_SESSION["visitor_country_shipping"]) && $_SESSION["visitor_country_shipping"] != "") {
        $countryCode = $_SESSION["visitor_country_shipping"];
    } elseif (isset($GLOBALS['shop_customer']['country']) && $GLOBALS['shop_customer']['country'] != '') {
        $countryCode = $GLOBALS['shop_customer']['country'];
    } else {
        // $countryCode = $GLOBALS['shop_language']['default_country_code'];
        $countryCode = $currShopConfig->getShopLanguageCode();
    }

    $postCode = '';
    if (isset($_SESSION["visitor_post_code_shipping"]) && $_SESSION["visitor_post_code_shipping"] != "") {
        $postCode = $_SESSION["visitor_post_code_shipping"];
    } elseif (isset($GLOBALS['shop_customer']['post_code']) && $GLOBALS['shop_customer']['post_code'] != '') {
        $postCode = $GLOBALS['shop_customer']['post_code'];
    }

    /**
     * @var $shipOptRepo ShippingClassRepository
     */
    $shipOptRepo = $IOCContainer->resolve('$ShippingOptionRepository');
    $shippingOptions = $shipOptRepo->getAllForOrder($currShopConfig, $basket, $countryCode, $postCode);
    if ((\DynCom\Compat\Compat::count($shippingOptions) > 0) && $shippingOptions->getFirst()->getID() > 0) {

        $firstShippingAgent = array();
        if (is_object($shippingOptions)) {
            foreach ($shippingOptions as $shippingAgent) {
                $firstShippingAgent = $shippingAgent->getAllFieldsAsArray();
                break;
            }
        }
        return $firstShippingAgent;
    } else {
        return;


    }

}

function calculate_vat($basePrice, $totalPrice)
{
    $taxAmount = $totalPrice - $basePrice;
    $taxRate = ($taxAmount / $basePrice) * 100;
    $taxRate = round($taxRate);
    return $taxRate;
}


function add_dealers_geographic_coordinates($company, $shopCode, $languageCode, $customerSource, $GoogleApikey)
{
    $pdoHost = getenv('MAIN_MYSQL_DB_HOST');
    $pdoPort = getenv('MAIN_MYSQL_DB_PORT');
    $pdoUser = getenv('MAIN_MYSQL_DB_USER');
    $pdoPass = getenv('MAIN_MYSQL_DB_PASS');
    $pdoSchema = getenv('MAIN_MYSQL_DB_SCHEMA');

    $pdo = new \DynCom\dc\common\classes\PDOQueryWrapper($pdoHost, $pdoPort, $pdoSchema, $pdoUser, $pdoPass);

    $prepStatement = " 
            
            SELECT 
    shop_customer.*
FROM
    shop_customer
        RIGHT JOIN
    shop_customer_link ON shop_customer_link.customer_no = shop_customer.customer_no
        AND shop_customer.company = shop_customer_link.company
        AND shop_customer_link.shop_code = :shop_code
        AND shop_customer_link.language_code = :language_code
        AND shop_customer_link.active_for_dealer_search = 1
WHERE
    (shop_customer.latitude = ''
        OR shop_customer.latitude = '0'
        OR shop_customer.longitude = ''
        OR shop_customer.longitude = '0')
        AND shop_customer.company = :company
        AND shop_customer.shop_code = :customer_source
        ";
    $params = [
        [':company', $company, PDO::PARAM_STR],
        [':shop_code', $shopCode, PDO::PARAM_STR],
        [':language_code', $languageCode, PDO::PARAM_STR],
        [':customer_source', $customerSource, PDO::PARAM_STR],
    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $customers = $pdo->getResultArray();

    for ($i = 0; $i < \DynCom\Compat\Compat::count($customers); $i++) {
        //$address = trim($customers[$i]['address_no']) .','.trim($customers[$i]['address_street']).','.trim($customers[$i]['city']).','.trim($customers[$i]['post_code']).','.trim($customers[$i]['country']);
        $address = trim($customers[$i]['address']) . ',' . trim($customers[$i]['city']) . ',' . trim($customers[$i]['post_code']) . ',' . trim($customers[$i]['country']);
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $GoogleApikey;
        //$apiResponse =file_get_contents($url);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_PROXYPORT, 3128);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $apiResponse = curl_exec($ch);
        curl_close($ch);

        $responseArray = json_decode($apiResponse, true);
        //11,Von-Lindestr,Kulmbach,95326,DE
        //11,Gabelsbergerstr,Bayreuth,95444,DE

        if ($responseArray['status'] == "OK") {
            $latitude = $responseArray['results'][0]['geometry']['location']['lat'];
            $longitude = $responseArray['results'][0]['geometry']['location']['lng'];

            $prepStatement = " UPDATE shop_customer SET latitude = :latitude , longitude = :longitude WHERE id = :id ";
            $params = [
                [':latitude', $latitude, PDO::PARAM_STR],
                [':longitude', $longitude, PDO::PARAM_STR],
                [':id', $customers[$i]['id'], PDO::PARAM_STR],
            ];
            $pdo->setQuery($prepStatement);
            $pdo->prepareQuery();
            $pdo->bindParameters($params);
            $pdo->executePreparedStatement();
        }

    }
}

function get_close_dealers_coordinates($company, $shopCode, $languageCode, $latitude, $longitude, $radius, $customerSource)
{

    $pdoHost = getenv('MAIN_MYSQL_DB_HOST');
    $pdoPort = getenv('MAIN_MYSQL_DB_PORT');
    $pdoUser = getenv('MAIN_MYSQL_DB_USER');
    $pdoPass = getenv('MAIN_MYSQL_DB_PASS');
    $pdoSchema = getenv('MAIN_MYSQL_DB_SCHEMA');

    $pdo = new \DynCom\dc\common\classes\PDOQueryWrapper($pdoHost, $pdoPort, $pdoSchema, $pdoUser, $pdoPass);

    $prepStatement = "  SELECT z.*,
        p.distance_unit
                 * DEGREES(ACOS(COS(RADIANS(p.latpoint))
                 * COS(RADIANS(z.latitude))
                 * COS(RADIANS(p.longpoint) - RADIANS(z.longitude))
                 + SIN(RADIANS(p.latpoint))
                 * SIN(RADIANS(z.latitude)))) AS distance_in_km
  FROM shop_customer AS z
  JOIN (  
        SELECT  " . $latitude . "  AS latpoint, " . $longitude . " AS longpoint,
                " . $radius . " AS radius,      111.045 AS distance_unit
    ) AS p ON 1=1 
    Join shop_customer_link on z.customer_no = shop_customer_link.customer_no 
        AND z.company = shop_customer_link.company 
        AND shop_customer_link.shop_code = :shop_code 
        AND z.language_code = shop_customer_link.language_code
        AND  shop_customer_link.active_for_dealer_search = 1
  WHERE 
	z.company = :company
  and
	z.latitude
     BETWEEN p.latpoint  - (p.radius / p.distance_unit)
         AND p.latpoint  + (p.radius / p.distance_unit)
    AND z.longitude
     BETWEEN p.longpoint - (p.radius / (p.distance_unit * COS(RADIANS(p.latpoint))))
         AND p.longpoint + (p.radius / (p.distance_unit * COS(RADIANS(p.latpoint))))
  ORDER BY distance_in_km ";
    $params = [
        [':company', $company, PDO::PARAM_STR],
        [':shop_code', $shopCode, PDO::PARAM_STR],
    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $dealers = $pdo->getResultArray();
    return $dealers;
}

function calculate_distance($lat1, $lon1, $lat2, $lon2, $unit)
{

    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    $unit = strtoupper($unit);

    if ($unit == "K") {
        return ($miles * 1.609344);
    } else if ($unit == "N") {
        return ($miles * 0.8684);
    } else {
        return $miles;
    }
}

function validate_address($street, $number, $postalCode, $city, $country)
{

    $url = 'http://validator2.addressdoctor.com/addInteractive/Interactive.asmx?WSDL';

    $useinfo = array(
        "CustomerID" => $GLOBALS['shop_language']['address_doctor_customer_id'],
        "DepartmentID" => 0,
        "Password" => $GLOBALS['shop_language']['address_doctor_password']
    );

    $addressinfo = array(
        "Street" => $street,
        "HouseNumber" => $number,
        "Locality" => $city,
        "PostalCode" => $postalCode,
        "Country" => $country);


    $parameters = array(
        "CountryOfOrigin" => "COO_ALWAYS_USE_DESTINATION_COUNTRY",
        "StreetWithHNo" => "True",
        "CountryType" => "ISO_2",
        "LineSeparator" => "LST_SEMICOLON",
        "PreferredLanguage" => "PFL_DATABASE",
        "Capitalization" => "NO_CHANGE",
        "FormattedAddressWithOrganization" => "False",
        "RemoveDiacritics" => "False",
        "ParsedInput" => "NEVER"

    );


    $client = new SoapClient($url);
    $function = $client->Validate(array("addInteractiveRequest" => array("Authentication" => $useinfo, "Parameters" => $parameters, "AddressCount" => 1, "Address" => $addressinfo)));

    $object = $function->ValidateResult;
    $result = get_object_vars($object);
    $returnedAdress = $result['Results']->Result;
    $returnedResults = array();
    if (\DynCom\Compat\Compat::count($returnedAdress) == 1) {
        $returnedResults[0] = $returnedAdress;
    } else {
        $returnedResults = $returnedAdress;
    }

    $recomendedAddress = array();

    for ($i = 0; $i < \DynCom\Compat\Compat::count($returnedResults); $i++) {

        if ($returnedResults[$i]->ResultPercentage > 0 && $returnedResults[$i]->ResultPercentage < 100) {
            if (strpos($returnedResults[$i]->Address->HouseNumber, '-') !== false) // getting a list of house numbers
            {
                $houseNumbers = explode("-", $returnedResults[$i]->Address->HouseNumber);

                $streetWithoutHouseNumber = str_replace($returnedResults[$i]->Address->HouseNumber, "", $returnedResults[$i]->Address->Street);

                for ($j = $houseNumbers[0]; $j <= $houseNumbers[1]; $j++) {
                    $recomendedAddress["$j"] = $streetWithoutHouseNumber . ' ' . $j . ', ' . $returnedResults[$i]->Address->PostalCode . ', ' . $returnedResults[$i]->Address->Locality . ', ' . $returnedResults[$i]->Address->Country;
                }
            } else {
                $index = $returnedResults[$i]->Address->HouseNumber;
                $streetWithoutHouseNumber = str_replace($returnedResults[$i]->Address->HouseNumber, "", $returnedResults[$i]->Address->Street);
                $recomendedAddress["$index"] = $streetWithoutHouseNumber . ' ' . $returnedResults[$i]->Address->HouseNumber . ', ' . $returnedResults[$i]->Address->PostalCode . ', ' . $returnedResults[$i]->Address->Locality . ', ' . $returnedResults[$i]->Address->Country;
            }
        } else if ($returnedResults[$i]->ResultPercentage == 0) { // Completley Wrong Address
            return false;

        } else { // Correct Address
            return true;

        }
    }
    natsort($recomendedAddress);
    return $recomendedAddress;

}

// Payolution ---
/**
 * Returns one record from shop_payment_option with the given line_no
 *
 * @param $line_no
 * @return array|boolean
 */
function get_payment_option_by_line_no($line_no)
{
    $query = "
        SELECT * 
        FROM shop_payment_option
        WHERE
            line_no = '" . $line_no . "'
            AND company = '" . $GLOBALS['shop']['company'] . "'
            AND shop_code = '" . $GLOBALS['shop']['code'] . "'
            AND language_code='" . $GLOBALS['shop_language']['code'] . "' 
        LIMIT 1
        ";
    $result = @mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
        $row = @mysqli_fetch_array($result, 1);
        return $row;
    }
    return false;
}


/**
 * Returns the trans_id from the SESSION or generates a new one
 * @return array|bool|int|mixed|string
 */
function get_payment_transaction_id()
{
    if (isset($_SESSION['trans_id']) && $_SESSION['trans_id'] <> '') {
        if (!check_payment_transaction_id_exists($_SESSION['trans_id'])) {
            return $_SESSION['trans_id'];
        }
    }
    $t_id = generate_trans_id();
    $_SESSION['trans_id'] = $t_id;
    return $t_id;
}

/**
 * Check if the payment_transaction_id already exists in the sales header
 *
 * @param $transaction_id
 * @return bool
 */
function check_payment_transaction_id_exists($transaction_id)
{
    $query = "
        SELECT *
        FROM shop_sales_header
        WHERE payment_transaction_id = '" . $transaction_id . "'
        ";
    $result = @mysqli_query($GLOBALS['mysql_con'], $query);
    if (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        return true;
    }
    return false;
}


/**
 * Generates a new trans_id or return the one in the Session
 *
 * @return array|int|mixed|string
 */
function generate_trans_id()
{

    do {
        $t_id = (string)mt_rand();
        $t_id .= date("yzGis");
        $query = "
            SELECT *
            FROM shop_sales_header
            WHERE payment_transaction_id='" . $t_id . "'
            ";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
    } while (@\DynCom\Compat\Compat::mysqli_num_rows($result) > 0);

    return $t_id;

}

/**
 *
 * @param $date_string
 * @param int $age
 * @return bool
 */
function check_age($date_string, $age = 18)
{

    $date = new DateTime($date_string);
    $now = new DateTime();
    $interval = $now->diff($date);

    return (bool)((int)$interval->y >= (int)$age);
}

/**
 *
 * @param $order_total
 * @param int $value_type
 * @return array|int|null
 */
function get_shipping_cost($order_total, $value_type = 0)
{

    $shipping_cost = 0;
    $query = "
        SELECT 
            IF (
                " . $value_type . " = 3 AND coupon_shipping_cost >0,
                coupon_shipping_cost,
                shipping_cost) 
            AS shipping_cost,
            exemption
        FROM shop_shipping_option
        WHERE 
            line_no='" . $_SESSION['shipping_line_no'] . "'
            AND shop_code='" . $GLOBALS['shop']['code'] . "'
            AND language_code = '" . $GLOBALS['shop_language']['code'] . "'";
    $result = mysqli_query($GLOBALS['mysql_con'], $query);
    if (\DynCom\Compat\Compat::mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        if ($_SESSION['shipping_line_no'] <> 0
            && ($row['exemption'] > $order_total || $row['exemption'] == '0')
        ) {
            $shipping_cost = $row['shipping_cost'];
        }
    }
    return $shipping_cost;
}

/**
 *
 * Returns an array of messages who are collected during the order steps. See check_mandatory_fields_b2c().
 *
 * @return array
 */
function get_all_order_error_msg()
{
    return $GLOBALS["order_error_msgs"];
}

/**
 *
 * Adds a message to the order_error_msg array
 *
 * @param $errormsg
 * @return bool
 */
function add_order_error_msg($errormsg)
{
    if (!isset($GLOBALS["order_error_msgs"])) {
        $GLOBALS["order_error_msgs"] = array();
    }

    if (!in_array($errormsg, $GLOBALS["order_error_msgs"])) {
        $GLOBALS["order_error_msgs"][] = $errormsg;
        return true;
    }
    return false;
}

// Payolution +++

/**
 * @param array $shippingAddress
 * @return bool
 */
function google_validate_shipping_address(array $shippingAddress)
{
    /**
     * @var $validator \DynCom\dc\common\classes\GoogleAddressValidator
     */
    static $validator;
    if (null === $validator) {
        $validator = new \DynCom\dc\common\classes\GoogleAddressValidator();
    }
    $addrString = $shippingAddress['address_no'] . ' ' . $shippingAddress['address_street'] . ', ' . $shippingAddress['post_code'] . ' ' . $shippingAddress['city'] . ', ' . $shippingAddress['country'];
    $isValid = $validator->isAddressValid($addrString);
    return $isValid;

}

function create_password_reminder_html()
{

    $pdoHost = getenv('MAIN_MYSQL_DB_HOST');
    $pdoPort = getenv('MAIN_MYSQL_DB_PORT');
    $pdoUser = getenv('MAIN_MYSQL_DB_USER');
    $pdoPass = getenv('MAIN_MYSQL_DB_PASS');
    $pdoSchema = getenv('MAIN_MYSQL_DB_SCHEMA');

    $pdo = new \DynCom\dc\common\classes\PDOQueryWrapper($pdoHost, $pdoPort, $pdoSchema, $pdoUser, $pdoPass);

    $prepStatement = "SELECT * FROM main_shop_login WHERE main_language_id = :langaugeId LIMIT 1";

    $params = [
        [':langaugeId', $GLOBALS["language"]['id'], PDO::PARAM_STR],
    ];

    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $result = $pdo->getResultArray();

    if (\DynCom\Compat\Compat::count($result) == 1) {

        $login_sitepart = $result[0];
        $show_labels_in_fields = (bool)$login_sitepart['show_labels_in_fields'];
        $email_field = (
        $show_labels_in_fields ?
            "
					<div class=\"form-group\">
                        <input type=\"text\" id=\"input_email_reminder\" name=\"input_email\" placeholder=\"" . $GLOBALS['tc']['email'] . "\">
                    </div>
					"
            :
            "
                    <div class=\"form-group\">
                        <label for=\"input_email\">" . $GLOBALS['tc']['email'] . "</label>
                        <input type=\"text\" name=\"input_email\" id=\"input_email_reminder\">
                    </div>
				"
        );

        $login_field = (
        $show_labels_in_fields ?
            "
                        <div class=\"form-group\">
                            <input type=\"text\" name=\"input_login\" id=\"input_login__reminder\" placeholder=\"" . $GLOBALS['tc']['login_b2b'] . "\">
                        </div>
                        "
            :
            "
                        <div class=\"form-group\">
                            <label for=\"input_login\">" . $GLOBALS['tc']['login_b2b'] . "</label>
                            <input type=\"text\" name=\"input_login\" id=\"input_login__reminder\">
                        </div>
                    "
        );

        $customer_no_field = (
        $show_labels_in_fields ?
            "
                        <div class=\"form-group\">
                            <input type=\"text\" name=\"input_customer_no\" id=\"input_customer_no__reminder\" placeholder=\"" . $GLOBALS['tc']['customer_no'] . "\">
                        </div>
                        "
            :
            "
                        <div class=\"form-group\">
                            <label for=\"input_customer_no\">" . $GLOBALS['tc']['customer_no'] . "</label>
                            <input type=\"text\" name=\"input_customer_no\" id=\"input_customer_no__reminder\">
                        </div>
                    "
        );
    }

    $field_1_html = '';
    $field_2_html = '';

    $target_site_code = $login_sitepart['target_site_code'];
    $target_language_code = $login_sitepart['target_language_code'];

    $prepStatement = "
				SELECT 
					shop_shop.*
				FROM 
					main_language,
					main_site,
					shop_shop
				WHERE
					main_site.code = :siteCode
				  AND
					main_language.main_site_id = main_site.id
				  AND
					main_language.code = :languageCode
				  AND
					shop_shop.company = main_language.company
				  AND
					shop_shop.code = main_language.shop_code
				";

    $params = [
        [':siteCode', $target_site_code, PDO::PARAM_STR],
        [':languageCode', $target_language_code, PDO::PARAM_STR],
    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $shop_result = $pdo->getResultArray();

    $shop = $shop_result[0];

    switch ($shop['login_type']) {
        case 0: //E-Mail & Passwort
            $field_1_html = $email_field;
            break;
        case 1: //Login & Passwort
            $field_1_html = $login_field;
            break;
        case 2: //Kunden-Nr., Login & Passwort
            $field_1_html = $customer_no_field;
            $field_2_html = $login_field;
            break;
        case 3: //Kunden-Nr., E-Mail & Passwort
            $field_1_html = $customer_no_field;
            $field_2_html = $email_field;
            break;
        case 4: //Kunden-Nr. & Passwort
            $field_1_html = $customer_no_field;
            break;
        default:
            break;
    }
    echo $field_1_html;
    echo $field_2_html;
}

function get_active_filters()
{
    $attributes = [];
    $resultSet = [];
    if (isset($_SESSION['filters']) && is_array($_SESSION['filters'])) {

        $attributesQuery = '
          SELECT * FROM shop_attribute;
          ';
        $attrRes = mysqli_query($GLOBALS['mysql_con'], $attributesQuery);
        $attrs = mysqli_fetch_all($attrRes, MYSQLI_ASSOC);
        foreach ($attrs as $row) {
            $attributes[$row['code']] = $row;
        }

        foreach ($_SESSION['filters'] as $key => $value) {
            if (\DynCom\Compat\Compat::array_key_exists($key, $attributes)) {
                $rawAttributeCode = $attributes[$key]['code'];
                $attr = $attributes[$key];

                $field = $rawAttributeCode;
                $val = $value;
                $val_from = null;
                $val_to = null;

                $isRange = false;
                if ((int)$attr['display_type'] === 3) {
                    $isRange = true;
                    $val_from = $_SESSION[$key]['from'];
                    $val_to = $_SESSION[$key]['to'];
                }


                switch ($attr['data_type']) {
                    case 0: //Option
                        if ($isRange) {
                            $val_from = (string)$val_from;
                            $val_to = (string)$val_to;
                        } else {
                            $val = (string)$val;
                        }
                        break;
                    case 1: //Int
                        if ($isRange) {
                            $val_from = (int)$val_from;
                            $val_to = (int)$val_to;
                        } else {
                            $val = (int)$val;
                        }
                        break;
                    case 2: //Float
                        if ($isRange) {
                            $val_from = (float)$val_from;
                            $val_to = (float)$val_to;
                        } else {
                            $val = (float)$val;
                        }
                        break;
                    case 3: //Bool
                        if ($isRange) {
                            $val_from = (bool)$val_from;
                            $val_to = (bool)$val_to;
                        } else {
                            $val = (bool)$val;
                        }
                        break;
                    case 4:
                        if ($isRange) {
                            $val_from = (string)$val_from;
                            $val_to = (string)$val_to;
                        } else {
                            $val = (string)$val;
                        }
                        break;
                }

                if ($attr['navision_value']) {
                    switch ($attr['navision_value']) {
                        case 1:
                            $field = 'width';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                        case 2:
                            $field = 'length';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                        case 3:
                            $field = 'height';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                        case 4:
                            $field = 'volume';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                        case 5:
                            $field = 'weight';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                        case 6:
                            $field = 'retail_price';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                        case 7:
                            $field = 'base_price';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                        case 8:
                            $field = 'vendor_no';
                            if ($isRange) {
                                $val_from = (string)$val_from;
                                $val_to = (string)$val_to;
                            } else {
                                $val = (string)$val;
                            }
                            break;
                        case 9:
                            $field = 'inventory';
                            if ($isRange) {
                                $val_from = (float)$val_from;
                                $val_to = (float)$val_to;
                            } else {
                                $val = (float)$val;
                            }
                            break;
                    }
                }
                if ($isRange) {
                    $resultSet[$field] = ['from' => $val_from, 'to' => $val_to];
                } else {
                    $resultSet[$key] = $val;
                }

            }
        }
    }
    return $resultSet;
}


function create_elastic_query($company, $item_shop_code, $category_shop_code, $language_code, array $cat_line_nos, array $active_attribute_filters, array $category_filters, $orderByCode, $from, $limit)
{
    $catLineNo = $cat_line_nos[0];
    $catKey = $category_shop_code . '|' . $catLineNo;

    $sorting = [];
    switch ($orderByCode) {
        case 'ranking':
            $sorting = ['order_ranking' => ['order' => 'desc']];
            break;
        case 'item_no':
            $sorting = ['item_no' => ['order' => 'asc']];
            break;
        case 'description':
            $sorting = ['description' => ['order' => 'asc']];
            break;
        case 'creation_date':
            $sorting = ['_uid' => ['order' => 'asc']];
            break;
        case 'sorting':
            $sorting = [['category_sortings.' . $catKey => ['order' => 'asc', 'nested_path' => 'category_sortings']]];
            break;
        case 'base_price_asc':
            $sorting = ['base_price' => ['order' => 'asc']];
            break;
        case 'base_price_desc':
            $sorting = ['base_price' => ['order' => 'desc']];
            break;
        default:
            $sorting = ['_uid' => ['order' => 'asc']];
    }


    array_walk($cat_line_nos, function (&$el) use ($category_shop_code) {
        $el = $category_shop_code . '|' . $el;
    });

    $aggsPart = [];
    foreach ($category_filters as $filter) {
        if ($filter['code'] !== '') {
            $aggsPart[$filter['code']] = ['terms' => ['field' => $filter['code']]];
        }
    }

    $body = [
        '_source' => ['item_no', 'parent_item_no'],
        'from' => (int)$from,
        'size' => (int)$limit,
        'query' => [
            'bool' => [
                'filter' => [
                    'bool' => [
                        'must' => [
                            ['term' => ['parent_item_no' => '']],
                            ['term' => ['active' => 1]],
                            ['term' => ['company' => $company]],
                            ['term' => ['shop_code' => $item_shop_code]],
                            ['term' => ['language_code' => $language_code]],
                            ['terms' => ['categories' => $cat_line_nos]],
                            ['range' => ['starting_date' => ['lte' => 'now']]],
                            ['range' => ['ending_date' => ['gte' => 'now']]],
                        ]
                    ]
                ],
            ],
        ],
        'sort' => $sorting
    ];
    if (\DynCom\Compat\Compat::count($aggsPart) > 0) {
        $body['aggs'] = $aggsPart;
    }
    foreach ($active_attribute_filters as $fieldName => $filterVal) {
        if (is_array($filterVal)) {
            $gte = $filterVal['from'];
            $lte = $filterVal['to'];
            $body['query']['bool']['filter']['bool']['must'][] = ['range' => [$fieldName => ['gte' => $gte, 'lte' => $lte]]];
        } else {
            $body['query']['bool']['filter']['bool']['must'][] = ['term' => [$fieldName => $filterVal]];
        }

    }

    return $body;

}

function create_facet_html($attr_code, $attr_desc, $attr_display_type, $attr_data_type, array $options, $sort_code)
{
    if (\DynCom\Compat\Compat::count($options) === 0) {
        return '';
    }

    $optionsHTML = '';
    $normalizedAttrCode = url_normalize_attribute_code($attr_code);
    $normalizedAttrCodeFrom = $normalizedAttrCode . '_from';
    $normalizedAttrCodeTo = $normalizedAttrCode . '_to';
    $optionSelected = false;
    foreach ($options as $optArr) {
        $opt_code = $optArr['code'];
        $opt_desc = $optArr['desc'];
        $opt_doc_num = $optArr['doc_num'];
        $selectedStr = (\DynCom\Compat\Compat::array_key_exists($normalizedAttrCode, $_GET) || \DynCom\Compat\Compat::array_key_exists($normalizedAttrCodeFrom, $_GET) || \DynCom\Compat\Compat::array_key_exists($normalizedAttrCodeTo, $_GET)) && $_GET[$normalizedAttrCode] === $opt_code ? ' selected=\'selected\'' : '';
        if ($selectedStr) {
            $optionSelected = true;
        }
        $desc_str = $selectedStr ? $opt_desc : $opt_desc . ' (' . $opt_doc_num . ')';
        $optionsHTML .= '
            <option value="' . $opt_code . '"' . $selectedStr . '>' . $desc_str . '</option>
        ';
    }
    $activeStr = $optionSelected ? ' active' : '';
    $openStr = $optionSelected ? ' open' : ' default-hide';
    $titleSelectedStr = $optionSelected ? '' : ' selected=\'selected\'';

    $unsetButton = getUnsetFilterButton($attr_code, $attr_display_type, $attr_data_type);

    $structure = '
    <div class="col-xs-6 col-sm-4 col-md-4 col-lg-3 filter-' . $attr_code . '-wrapper filter-wrapper">
        <div class="filter-wrapper-inner">
            <div class="filter dropdown form-group">
                <div class="select_body ' . $openStr . $activeStr . '">
                    <select size="0" class="filterlist" data-attribute-id="2" onchange="window.location.href=\'?' . url_normalize_attribute_code($attr_code) . '=\'+this.options[this.selectedIndex].value + \'&amp;sort_by=' . $sort_code . '\'">
                        <option value="0" disabled="" ' . $titleSelectedStr . '>' . strtoupper($attr_desc) . '</option>  
                        ' . $optionsHTML . '                 
                    </select>
                </div>
            </div>
            ' . $unsetButton . '
        </div>
    </div>
    ';
    return $structure;
}

function url_normalize_attribute_code($attribute_code)
{
    return mb_strtolower(str_replace(' ', '~|~', $attribute_code), 'UTF-8');
}

function evaluate_url_filters(array $attributesInFilteredDocs)
{
//Filter in Session setzen, wenn get-parameter vorhanden

    foreach ($attributesInFilteredDocs as $att) {
        $rawAttrCode = $att['code'];
        $normalizedAttrCode = url_normalize_attribute_code($att['code']);
        if ($att['display_type'] != 3) {
            if (\DynCom\Compat\Compat::array_key_exists($normalizedAttrCode, $_GET) && $_GET[$normalizedAttrCode] !== '') {
                //Unset filter in session if a default-value ('0' or 'default') is passed via GET
                if (($att["data_type"] != 3 && $_GET[$normalizedAttrCode] == '0') || $_GET[$normalizedAttrCode] === 'default') {
                    unset($_SESSION['filters'][$rawAttrCode]);
                } else {
                    //Else set the corresponding session-entry to the new value from GET

                    $_SESSION['filters'][$rawAttrCode] = $_GET[$normalizedAttrCode];
                }
            }
        } else {
            if ($_GET[$normalizedAttrCode] === 'default') {
                unset($_SESSION['filters'][$rawAttrCode]);
            }
            if ($_GET[$normalizedAttrCode . "_from"] !== '') {
                if ($_GET[$normalizedAttrCode . "_from"] == '0') {
                    unset($_SESSION['filters'][$rawAttrCode]['from']);
                } else {
                    $_SESSION['filters'][$rawAttrCode]['from'] = $_GET[$normalizedAttrCode . "_from"];
                }
                if ($_GET[$normalizedAttrCode . "_to"] == '0') {
                    unset($_SESSION['filters'][$rawAttrCode]['to']);
                } else {
                    $_SESSION['filters'][$rawAttrCode]['to'] = $_GET[$normalizedAttrCode . "_to"];
                }
                if (!isset($_SESSION['filters'][$rawAttrCode]['to']) && !isset($_SESSION['filters'][$rawAttrCode]['from'])) {
                    unset($_SESSION['filters'][$rawAttrCode]);
                }
            }
        }
    }
}

function get_attribute(PDO $pdo, $company, $code)
{
    static $query = 'SELECT * FROM shop_attribute WHERE company = :company AND code = :code';
    static $memo;
    if (null === $memo) {
        $memo = [];
    }
    $paramHash = md5($company . '|' . $code);
    if (\DynCom\Compat\Compat::array_key_exists($paramHash, $memo)) {
        return $memo[$paramHash];
    }
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':company', $company, PDO::PARAM_STR);
    $stmt->bindValue(':code', $code, PDO::PARAM_STR);
    $stmt->execute();
    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $row = $stmt->fetch();
    $memo[$paramHash] = $row;
    return $row;

}

function create_paypal_express()
{
    $_SESSION['site'] = $GLOBALS['site']['code'];
    $_SESSION['is_unique_site'] = $GLOBALS['site']['is_unique_site'];
    $_SESSION['language'] = $GLOBALS['language']['code'];
    $_SESSION['shop'] = $GLOBALS['shop']['code'];
    $_SESSION['shop_language'] = $GLOBALS['shop_language']['code'];

    $paypalExpress = get_paypal_express_data($GLOBALS['shop']['company'], $GLOBALS['shop']['code'], $GLOBALS['shop_language']['code']);

    if (\DynCom\Compat\Compat::count($paypalExpress) > 0) {

        require_once rtrim(dirname(dirname(dirname(__DIR__))), '/\\') . DIRECTORY_SEPARATOR . "/plugins/paygate/payolution.inc.php";

        $merchant_id = $GLOBALS['shop']['computop_merchant_id'];
        $trans_id = "&TransID=" . get_payment_transaction_id();
        $new_amount = basketItemTotal();
        $amount = "&Amount=" . round($new_amount * 100, 0);
        $currency = "&Currency=EUR";
        if ($GLOBALS["shop_language"]["default_currency_code"] != "") {
            $currency = "&Currency=" . $GLOBALS["shop_language"]["default_currency_code"];
        }

        $url_failure = "&URLFailure=https://" . $_SERVER['SERVER_NAME'] . "/module/dcshop/b2c/order_error.php";
        $url_notify = "&URLNotify=https://" . $_SERVER['SERVER_NAME'] . "/module/dcshop/b2c/order_notify.php?shopdata=" . $GLOBALS["shop"]["company"] . "|" . $GLOBALS["shop"]["code"] . "|" . $GLOBALS["shop_language"]["code"];
        $url_success = "&URLSuccess=https://" . $_SERVER['SERVER_NAME'] . "/module/dcshop/b2c/order_success.php?shopdata=" . $GLOBALS["shop"]["company"] . "|" . $GLOBALS["shop"]["code"] . "|" . $GLOBALS["shop_language"]["code"];

        $computopLanguageCodesPayPal = ["au" => "au", "de" => "de", "fr" => "fr", "it" => "it", "gb" => "gb", "es" => "es", "en" => "us"];
        $languagePayPal = "&Language=de";
        if (\DynCom\Compat\Compat::array_key_exists($GLOBALS["language"]["code"], $computopLanguageCodesPayPal)) {
            $languagePayPal = "&Language=" . $computopLanguageCodesPayPal[$GLOBALS["language"]["code"]];
        }

        $response = "&Response=Encrypt";
        $userdata = "&UserData=" . session_id();
        // I should check here for the checkout number if its capture or buchen

        if ($paypalExpress['checkout'] == '20') {
            $capture = "&Capture=Manual";
            $txtype = "&Txtype=Auth";
        } else {
            $capture = "&Capture=Auto";
            $txtype = "";
        }

        $paypalMethod = "&PayPalMethod=shortcut";

        $order_desc = "&OrderDesc=" . $GLOBALS['site']['name'];
        $plaintext_paypal = "MerchantID=" . $merchant_id . $trans_id . $paypalMethod . $amount . $currency . $url_success . $url_failure . $url_notify . $userdata . $order_desc . $response . $userdata . $capture . $txtype;

        //$len_paypal = strlen($plaintext_paypal);
        $len_paypal = mb_strlen($plaintext_paypal, mb_internal_encoding());

        $BlowFish = new ctBlowfish;
        $Data_paypal = $BlowFish->ctEncrypt($plaintext_paypal, $len_paypal, $GLOBALS['shop']['computop_password']);

        $_SESSION['site'] = $GLOBALS['site']['code'];
        $_SESSION['language'] = $GLOBALS['language']['code'];
        $_SESSION['payment_line_no'] = $paypalExpress['line_no']


        ?>

        <a class="button_paypalexpress"
           href=https://www.computop-paygate.com/paypal.aspx?MerchantID=<?= $merchant_id ?>&Len=<?= $len_paypal ?>&Data=<?= $Data_paypal ?><?= $languagePayPal ?>">
            <img src="<?= $GLOBALS['projectRoot'] ?>/userdata/images/paypal_express.png" alt="PayPal Express"/>
        </a>

        <?
    }
}

function get_paypal_express_data($company, $shopCode, $shopLanguageCode)
{
    $pdoHost = getenv('MAIN_MYSQL_DB_HOST');
    $pdoPort = getenv('MAIN_MYSQL_DB_PORT');
    $pdoUser = getenv('MAIN_MYSQL_DB_USER');
    $pdoPass = getenv('MAIN_MYSQL_DB_PASS');
    $pdoSchema = getenv('MAIN_MYSQL_DB_SCHEMA');

    $pdo = new \DynCom\dc\common\classes\PDOQueryWrapper($pdoHost, $pdoPort, $pdoSchema, $pdoUser, $pdoPass);
    // Get PayPal Express types with checkout number 21 or 22
    $prepStatement = "SELECT 
                        *
                         FROM 
                         shop_payment_option
                          where 
                              company = :company
                                AND shop_code = :shop_code
                                  AND language_code = :language_code
                                  AND ( checkout = 20 or  checkout = 21 )  AND active = 1 
                                   LIMIT 1";

    $params = [
        [':company', $company, PDO::PARAM_STR],
        [':shop_code', $shopCode, PDO::PARAM_STR],
        [':language_code', $shopLanguageCode, PDO::PARAM_STR],

    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $resultArray = $pdo->getResultArray();

    $paymentOption = $resultArray[0];
    return $paymentOption;
}

function checkInvoiceIsNotShipping()
{
    if (!empty($_SESSION["visitor_name_shipping"]) && ($_SESSION["visitor_name"] != $_SESSION["visitor_name_shipping"])) {
        return true;
    }

    if (!empty($_SESSION["visitor_user_street_shipping"]) && ($_SESSION["visitor_address"] != $_SESSION["visitor_user_street_shipping"])) {
        return true;
    }

    if (!empty($_SESSION["visitor_user_street_no_shipping"]) && ($_SESSION["visitor_address_no"] != $_SESSION["visitor_user_street_no_shipping"])) {
        return true;
    }

    if (!empty($_SESSION["visitor_city_shipping"]) && ($_SESSION["visitor_city"] != $_SESSION["visitor_city_shipping"])) {
        return true;
    }

    if (!empty($_SESSION["visitor_user_street_no_shipping"]) && ($_SESSION["visitor_post_code"] != $_SESSION["visitor_post_code_shipping"])) {
        return true;
    }

    if (!empty($_SESSION["visitor_country_shipping"]) && ($_SESSION["visitor_country"] != $_SESSION["visitor_country_shipping"])) {
        return true;
    }

    return false;
}

function check_coupon_before_payment($pdo, &$goToNextStep)
{

    static $getCouponLineQuery = '
        SELECT 
          *
        FROM
          shop_coupon_line 
        WHERE
          company = :company
        AND
          coupon_code = :coupon_code
        AND 
          code = :code
        LIMIT 1
    ';

    $stmt = $pdo->prepare($getCouponLineQuery);
    $stmt->bindValue(':company', $GLOBALS['shop']['company'], PDO::PARAM_STR);
    $stmt->bindValue(':coupon_code', $_SESSION['coupon']['coupon_code'], PDO::PARAM_STR);
    $stmt->bindValue(':code', $_SESSION['coupon']['code'], PDO::PARAM_STR);

    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $coupon = $stmt->fetch();

    if ((bool)$coupon['active'] === 0 || ((float)$coupon['amount'] - (float)$_SESSION['coupon']['amount_deducted'] < 0)) {
        $goToNextStep = false;
        $_SESSION['coupon_error'] = true;
    }

    return $goToNextStep;
}

function get_order_sum_as_string(
    $subtotal,
    $online_discount,
    $online_discount_amount,
    $invoice_discount,
    $invoice_discount_amount,
    $small_quantity_charge_amount,
    $total,
    $shipping_cost = 0,
    $payment_cost = 0,
    $show_vat = false,
    $sales_line_result = null,
    $rule_disc_percent = 0.00,
    $rule_disc_amount = 0.00
)
{

    if ($_SESSION["coupon"]['coupon_discount_amount'] <> 0) {
        $total -= $_SESSION["coupon"]['coupon_discount_amount'];
    }


    $output = ' <table class="order_sum" style="width:100%"> ';
    if ($subtotal <> $total) {
        $output .= '<tr>
                <td class="order_sum_1">' . $GLOBALS["tc"]["order_total"] . '</td>

                <td class="order_sum_2" style="text-align:right">
                    <div class="order_price_total">' . format_amount($subtotal, false) . '</div>
                </td>

            </tr>';
    }
    if ($rule_disc_amount <> 0) {
        $output .= ' <tr>
            <td class="order_sum_1"> ' . $GLOBALS["tc"]["discount"] . ' ' . round(
                $rule_disc_percent,
                2
            ) . '% </td>
            <td class="order_sum_2" style="text-align:right">- ' . format_amount(
                $rule_disc_amount,
                false
            ) . '</td>
        </tr>';
    }
    if ($small_quantity_charge_amount <> 0) {
        $output .= '   <tr>
                <td class="order_sum_1">' . $GLOBALS["tc"]["small_quantity_charge"] . '</td>
                <td class="order_sum_2" style="text-align:right"> ' . format_amount(
                $small_quantity_charge_amount,
                false
            ) . ' </td>
            </tr> ';
    }
    if ($shipping_cost <> 0) {
        $output .= '
            <tr>
                <td class="order_sum_1">' . $GLOBALS["tc"]["shipping_cost"] . '</td>
                <td class="order_sum_2" style="text-align:right">+ ' . format_amount($shipping_cost, false) . '</td>
            </tr> ';
    }
    if ($payment_cost <> 0) {
        $output .= '            <tr>
                <td class="order_sum_1">' . $GLOBALS["tc"]["payment_cost"] . '</td>
                <td class="order_sum_2" style="text-align:right">+ ' . format_amount($payment_cost, false) . '</td>
            </tr> ';
    }
    if ($online_discount_amount <> 0) {
        $output .= '  <tr>
                <td class="order_sum_1">' . round($online_discount) . '% ' . $GLOBALS["tc"]["online_discount"] . '</td>
                <td class="order_sum_2" style="text-align:right">- ' . format_amount(
                $online_discount_amount,
                false
            ) . '</td>
            </tr> ';
    }
    if ($invoice_discount_amount <> 0) {
        $output .= '  <tr>
                <td class="order_sum_1">' . round($invoice_discount) . '% ' . $GLOBALS["tc"]["invoice_discount"] . '</td>
                <td class="order_sum_2" style="text-align:right">- ' . format_amount(
                $invoice_discount_amount,
                false
            ) . '</td>
            </tr>';
    }
    if ($_SESSION["coupon"]['coupon_discount_amount'] <> 0) {
        $output .= '  <tr>
                <td class="order_sum_1">' . $GLOBALS["tc"]["coupon_discount"] . '</td>

                <td class="order_sum_2" style="text-align:right">
                    -' . format_amount($_SESSION["coupon"]['coupon_discount_amount'], false) . '</td>
            </tr>';
    }
    $output .= ' <tr>

            <td class="order_sum_1">
                <div class="order_price_total_label">' . $GLOBALS["tc"]["total_amount"] . '</div>
            </td>


            <td class="order_sum_2" style="text-align:right">
                <div class="order_price_total">' . format_amount($total, false) . '</div>
            </td>

        </tr> ';

    //US Sales Tax---
    if ($show_vat == 1 && $sales_line_result != null && (!(isset($GLOBALS['us_sales_tax_breakdown']) || isset($GLOBALS['us_sales_tax_estimate'])))) {

        //Aufschläge
        $markup =  $payment_cost + $small_quantity_charge_amount;
        //Rabatte zusammenrechnen für Rechnung
        $discount = $online_discount_amount + $invoice_discount_amount + $_SESSION["coupon"]['coupon_discount_amount'] + $_SESSION['coupon']['amnt_disc_non_items'];
        //Rabatte ohne Coupon
        $discount_wo_coupon = $online_discount_amount + $invoice_discount_amount;


        //MB --- OOP ---
        if (!isset($currUserBasket) || !($currUserBasket instanceof UserBasket)) {
            if (!isset($IOCContainer)) {
                $IOCContainer = $GLOBALS['IOC'];
            }
            $currUserBasket = $IOCContainer->create('$CurrUserBasket');
        }
        set_vat_lines(
            $GLOBALS['visitor']['id'],
            $GLOBALS["shop"]["vat_bus_posting_group"],
            $_SESSION['coupon'],
            $discount_wo_coupon,
            $markup,
            $shipping_cost,
            $currUserBasket,
            $online_discount_amount
        );
        //MB +++ OOP +++

        $basket_vat_groups = get_basket_vat_groups($GLOBALS['visitor']['id']);

        if (isset($GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][0])) {
            for ($i = 0; $i < \DynCom\Compat\Compat::count($GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']]); $i++) {
                if (is_array($basket_vat_groups) && in_array(
                        $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_group'],
                        $basket_vat_groups
                    )
                ) {
                    $output .= ' <tr>';
                    $output .= ' <td class=\"order_sum_1\">' . $GLOBALS["tc"]["incl_tax"] . " " . round(
                            $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_percent']
                        ) . '%</td>';
                    $output .= ' <td class="order_sum_2" style="text-align:right"> ' . format_amount(
                            $GLOBALS['vat_order_visitor_' . $GLOBALS['visitor']['id']][$i]['vat_amount'],
                            false
                        ) . '</td>';
                    $output .= ' </tr>';
                }
            }
        }

    } elseif ($show_vat && (isset($GLOBALS['us_sales_tax_breakdown']) || isset($GLOBALS['us_sales_tax_estimate']))) {
        if (isset($GLOBALS['us_sales_tax_estimate'])) {
            $estimate = $GLOBALS['us_sales_tax_estimate'];
            $breakdown = $estimate->getBreakdown();
        } else {
            $breakdown = $GLOBALS['us_sales_tax_breakdown'];
        }


        /**
         * @var $breakdown \DynCom\dc\dcShop\USSalesTax\TaxJar\TaxBreakdown
         */

        if ($breakdown->getStateTaxCollectable() > 0) {
            $output .= '  <tr>
                    <td class="order_sum_1">' . $GLOBALS['tc']['state_taxable_amount'] . '</td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getStateTaxableAmount()
                ) . '
                    </td>
                </tr>
                <tr>
                    <td class="order_sum_1">' . $GLOBALS['tc']['state_tax'] . ' ' . ($breakdown->getStateTaxRate() * 100)
                . ' % 
                    </td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getStateTaxCollectable()
                ) . '
                    </td>
                </tr> ';
        }
        if ($breakdown->getCountyTaxableAmount() > 0) {
            $output .= ' <tr>
                    <td class="order_sum_1" > ' . $GLOBALS['tc']['county_taxable_amount'] . '</td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getCountyTaxableAmount()
                ) . ' 
                    
                </td>
                </tr>
                <tr>
                    <td class="order_sum_1">' . $GLOBALS['tc']['county_tax'] . ' ' . ($breakdown->getCountyTaxRate() *
                    100) . ' %
                    </td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getCountyTaxCollectable()
                ) . '
                    </td>
                </tr>';

        }
        if ($breakdown->getCityTaxCollectable() > 0) {
            $output .= '  <tr>
                    <td class="order_sum_1">' . $GLOBALS['tc']['city_taxable_amount'] . '</td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getCityTaxableAmount()
                ) . '
                    </td>
                </tr>
                <tr>
                    <td class="order_sum_1">' . $GLOBALS['tc']['city_tax'] . ' ' . ($breakdown->getCityTaxRate() * 100) .
                ' %
                    </td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getCityTaxCollectable()
                ) . '
                    </td>
                </tr> ';
        }
        if ($breakdown->getSpecialDistrictTaxCollectable() > 0) {
            $output .= ' <tr>
                    <td class="order_sum_1">' . $GLOBALS['tc']['special_district_taxable_amount'] . '</td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getSpecialDistrictTaxableAmount()
                ) . '
                    </td>
                </tr>
                <tr>
                    <td class="order_sum_1">' . $GLOBALS['tc']['special_district_tax'] . ' ' .
                ($breakdown->getSpecialTaxRate() * 100) . ' %
                    </td>
                    <td class="order_sum_2" style="text-align:right">' . format_amount(
                    $breakdown->getSpecialDistrictTaxCollectable()
                ) . '
                    </td>
                </tr>
                ';
        }
        //US Sales Tax +++
    }
    $output .= ' </table> ';

    return $output;

}

function get_shop_by_company_shop($company, $shopCode)
{

    $pdoHost = getenv('MAIN_MYSQL_DB_HOST');
    $pdoPort = getenv('MAIN_MYSQL_DB_PORT');
    $pdoUser = getenv('MAIN_MYSQL_DB_USER');
    $pdoPass = getenv('MAIN_MYSQL_DB_PASS');
    $pdoSchema = getenv('MAIN_MYSQL_DB_SCHEMA');

    $pdo = new \DynCom\dc\common\classes\PDOQueryWrapper($pdoHost, $pdoPort, $pdoSchema, $pdoUser, $pdoPass);

    $prepStatement = "SELECT * FROM shop_shop where company = :company AND code = :shop_code LIMIT 1";

    $params = [
        [':company', $company, PDO::PARAM_STR],
        [':shop_code', $shopCode, PDO::PARAM_STR],
    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $resultArray = $pdo->getResultArray();

    $shop = $resultArray[0];

    return $shop;
}

function get_main_language($company, $shopCode, $languageCode)
{
    $pdoHost = getenv('MAIN_MYSQL_DB_HOST');
    $pdoPort = getenv('MAIN_MYSQL_DB_PORT');
    $pdoUser = getenv('MAIN_MYSQL_DB_USER');
    $pdoPass = getenv('MAIN_MYSQL_DB_PASS');
    $pdoSchema = getenv('MAIN_MYSQL_DB_SCHEMA');

    $pdo = new \DynCom\dc\common\classes\PDOQueryWrapper($pdoHost, $pdoPort, $pdoSchema, $pdoUser, $pdoPass);

    // Get Main Language
    $prepStatement = " SELECT 
                                  * FROM
                                   main_language
                                    WHERE
                                  company = :company
                                    AND shop_code = :shop_code
                                     AND code = :language_code
                                   LIMIT 1";

    $params = [
        [':company', $company, PDO::PARAM_STR],
        [':shop_code', $shopCode, PDO::PARAM_STR],
        [':language_code', $languageCode, PDO::PARAM_STR],

    ];
    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();
    $resultArray = $pdo->getResultArray();

    $mainLanguage = $resultArray[0];

    return $mainLanguage;
}

function create_amazon_payment_button()
{

    // build the callback url for amazon pay
    //TODO: is_unique_site
    $callback_url = sprintf("https://%s/%s/%s/order/amazon_checkout/?UserData=" . session_id() . "&shopdata=" . $GLOBALS["shop"]["company"] . "|" . $GLOBALS["shop"]["code"], $_SERVER['SERVER_NAME'], $GLOBALS['site']['code'], $GLOBALS['language']['code']);
    $amazonPay = get_amazon_pay_data($GLOBALS['shop']['company'], $GLOBALS['shop']['code'], $GLOBALS['shop_language']['code']);

    if (\DynCom\Compat\Compat::count($amazonPay) > 0) {
        if (\DynCom\Compat\Compat::array_key_exists('shop_currency', $GLOBALS) && is_array($GLOBALS['shop_currency'])
            && \DynCom\Compat\Compat::array_key_exists('code', $GLOBALS['shop_currency']) && $GLOBALS['shop_currency']['code'] != ''
        ) {
            $_SESSION['amazon_payment_currency'] = $GLOBALS['shop_currency']['code'];
        } else {
            $_SESSION['amazon_payment_currency'] = $GLOBALS['shop_language']['default_currency_code'];
        }
        if ($_SESSION['amazon_payment_currency'] == '') {
            $_SESSION['amazon_payment_currency'] = 'EUR';
        }

        if (!empty($_SESSION["dc_id"])) {
            // digital coupon order
            $dc  = get_dc($_SESSION["dc_id"]);
            $amount = $dc["amount"];
        } else {
            // normal basket order
            $IOCContainer = $GLOBALS['IOC'];
            $currUserBasket = $IOCContainer->create('$CurrUserBasket');
            $amount = $currUserBasket->getBasketTotal();
        }
        $_SESSION['amazon_payment_amount'] = $amount;

        $_SESSION['site'] = $GLOBALS['site']['code'];
        $_SESSION['language'] = $GLOBALS['language']['code'];
        $widget_url = get_amazon_javascript_widget_url($GLOBALS['shop']['company'], $GLOBALS['shop']['code'], $GLOBALS['shop_language']['code']);

        ?>

        <div id="AmazonPayButton" style="display: inline-block; height: 38px !important"></div>

        <style>
            #AmazonPayButton img {
                max-height: 36px !important;
            }
        </style>
        <div id="amazon-root"></div>
        <script>
            window.onAmazonLoginReady = function () {
                amazon.Login.setClientId('<?= $GLOBALS["amazon_pay"]["client_id"] ?>');
                amazon.Login.setUseCookie(true);
            };

            window.onAmazonPaymentsReady = function () {
                // render the button here
                var authRequest;

                OffAmazonPayments.Button('AmazonPayButton', '<?= $GLOBALS["amazon_pay"]["seller_id"] ?>', {
                    type: 'PwA',
                    color: 'LightGray',
                    size: 'medium',
                    language: '<?= $GLOBALS['language']['locale_code'] ?>',

                    authorization: function () {
                        loginOptions = {
                            scope: 'profile postal_code payments:widget payments:shipping_address payments:billing_address',
                            popup: true
                        };
                        authRequest = amazon.Login.authorize(loginOptions, '<?= $callback_url ?>');
                    },
                    onError: function (error) {
                        alert(error.getErrorCode());
                        alert(error.getErrorMessage());
                    }
                });
            }
        </script>

        <script async="async"
                src='<?= $widget_url ?>'>
        </script>

        <?
    }
}

function get_amazon_pay_data($company, $shopCode, $shopLanguageCode)
{
    /** @var PDO $pdo */
    $pdo = get_main_db_pdo_from_env_single_instance();
    $prepStatement = "SELECT 
                        *
                         FROM 
                         shop_payment_option
                          where 
                              company = :company
                                AND shop_code = :shop_code
                                  AND language_code = :language_code
                                  AND checkout = 11 AND active = 1 
                                   LIMIT 1";

    $stmt = $pdo->prepare($prepStatement);
    $stmt->bindValue(':company', $company, PDO::PARAM_STR);
    $stmt->bindValue(':shop_code', $shopCode, PDO::PARAM_STR);
    $stmt->bindValue(':language_code', $shopLanguageCode, PDO::PARAM_STR);

    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

    $stmt->setFetchMode(PDO::FETCH_ASSOC);
    $resultArray = $stmt->fetchAll(0);

    $paymentOption = $resultArray[0];
    return $paymentOption;
}

/**
 * @param $company
 * @param $shopCode
 * @param $shopLanguageCode
 * @return string
 */
function get_amazon_javascript_widget_url($company, $shopCode, $shopLanguageCode) {
    $payment_option = get_amazon_pay_data($company, $shopCode, $shopLanguageCode);
    if ($payment_option['checkout_state'] == "1") { // teststate, use test widget
        return 'https://static-eu.payments-amazon.com/OffAmazonPayments/eur/sandbox/lpa/js/Widgets.js';
    }
    return 'https://static-eu.payments-amazon.com/OffAmazonPayments/eur/lpa/js/Widgets.js';
}

function get_next_customer_no($vis_id, $prefix = "") : string
{

    if (!isset($IOCContainer) || !($IOCContainer instanceof IOCInterface)) {
        $IOCContainer = $GLOBALS['IOC'];
    }

    $pdo = $IOCContainer->resolve('DynCom\dc\common\classes\PDOQueryWrapper');
    $prepStatement = 'SELECT * FROM shop_customer WHERE customer_no = :customer_no AND company = :company AND shop_code = :shop_code LIMIT 1';

    $customer_no = ($prefix <> "") ? $prefix . (string)$vis_id : $vis_id;

    $params = [
        [':customer_no', $customer_no, PDO::PARAM_STR],
        [':company', $GLOBALS["shop"]["company"], PDO::PARAM_STR],
        [':shop_code', $GLOBALS["shop"]["code"], PDO::PARAM_STR],
    ];

    $pdo->setQuery($prepStatement);
    $pdo->prepareQuery();
    $pdo->bindParameters($params);
    $pdo->executePreparedStatement();

    $resArr = $pdo->getResultArray();

    if (is_array($resArr)) {
        if (is_array($resArr[0])) {
            return get_next_customer_no((int)$vis_id + 1, $prefix);
        }
    }
    return $customer_no;
}

/**
 * @return string
 */
function getAdditionalPaymentDataForSalesHeaderId($salesHeaderID)
{

    $pdo = get_main_db_pdo_from_env_single_instance();

    $salesHeaderQuery = '
        SELECT 
            *
        FROM
            shop_sales_header
        WHERE
            id = :salesHeaderId
        LIMIT 1
    ';

    $stmt = $pdo->prepare($salesHeaderQuery);
    $stmt->bindValue(':salesHeaderId', $salesHeaderID, PDO::PARAM_INT);
    if (!$stmt->execute()) {
        $errorInfo = $pdo->errorInfo();
        $errorString = implode($errorInfo, PHP_EOL);
        throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
    }

    $stmt->setFetchMode(PDO::FETCH_BOTH);
    $salesHeaderResultArray = $stmt->fetchAll(0);
    $salesHeader = $salesHeaderResultArray[0];


    //3DSecure 2.0 documentation https://computop.atlassian.net/wiki/spaces/DEV/pages/35717127/3DS+2.0

    //billToCustomer JSON
    $billToCustomerArray = [
        'customerNumber' => $salesHeader['customer_no'], //string 30
        'email' => $salesHeader['user_email'], //string 50
    ];

    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
    try {
        $numberProto = $phoneUtil->parse($salesHeader['user_phone_no'], $salesHeader['bill_to_country']);
        $billToCustomerArray['phone'] = [
            'countryCode' => $numberProto->getCountryCode(), //string 1-3, required
            'subscriberNumber' => str_repeat('0', $numberProto->getNumberOfLeadingZeros()) . $numberProto->getNationalNumber(), //string 12, required
        ];
    } catch (\libphonenumber\NumberParseException $e) {
    }

    if ($_SESSION["input_is_company"] === 'on') {
        $billToCustomerArray['business'] = [
            'legalName' => $salesHeader['bill_to_name'], //string 50, required
            'dbaName' => $salesHeader['bill_to_name'], //string 50
            'registrationNumber' => $salesHeader['vat_id'], //string 20
        ];
    } else {
        $salutation = ($salesHeader['salutation_title'] === 'Herr' || $salesHeader['salutation_title'] == 0 || $salesHeader['salutation_title'] == 'Mr.') ? 'Mr' : 'Mrs';
        $billToCustomerArray['consumer'] = [
            'salutation' => $salutation, //string ["Mr", "Mrs", "Miss"]
            'firstName' => $salesHeader['sur_name'], //string 30, required
            'lastName' => $salesHeader['last_name'], //string 30, required
            'birthDate' => date("Y-m-d", strtotime($salesHeader['birthday'])), //YYYY-MM-DD
        ];
    }
    $billToCustomerJson = json_encode($billToCustomerArray);
    $billToCustomerBase64 = base64_encode($billToCustomerJson);

    //shipToCustomer JSON
    $shipToCustomerArray = [
        'customerNumber' => $salesHeader['customer_no'], //string 30
        'email' => $salesHeader['user_email'], //string 50
    ];

    $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
    try {
        $numberProto = $phoneUtil->parse($salesHeader['user_phone_no'], $salesHeader['bill_to_country']);
        $shipToCustomerArray['phone'] = [
            'countryCode' => $numberProto->getCountryCode(), //string 1-3, required
            'subscriberNumber' => str_repeat('0', $numberProto->getNumberOfLeadingZeros()) . $numberProto->getNationalNumber(), //string 12, required
        ];
    } catch (\libphonenumber\NumberParseException $e) {

    }

    if ($_SESSION["input_is_company"] === 'on') {
        $shipToCustomerArray['business'] = [
            'legalName' => $salesHeader['bill_to_name'], //string 50, required
            'dbaName' => $salesHeader['bill_to_name'], //string 50
            'registrationNumber' => $salesHeader['vat_id'], //string 20
        ];
    } else {
        $salutation = ($salesHeader['salutation_title'] === 'Herr' || $salesHeader['salutation_title'] == 0 || $salesHeader['salutation_title'] == 'Mr.') ? 'Mr' : 'Mrs';
        $shipToCustomerArray['consumer'] = [
            'salutation' => $salutation, //string ["Mr", "Mrs", "Miss"]
            'firstName' => $salesHeader['sur_name'], //string 30, required
            'lastName' => $salesHeader['last_name'], //string 30, required
            'birthDate' => date("Y-m-d", strtotime($salesHeader['birthday'])), //YYYY-MM-DD
        ];
    }
    $shipToCustomerJson = json_encode($shipToCustomerArray);
    $shipToCustomerBase64 = base64_encode($shipToCustomerJson);

    //billingAddress JSON
    $state = (new \League\ISO3166\ISO3166)->alpha2($salesHeader['bill_to_country']);
    $billingAddressArray = [
        'city' => $salesHeader['bill_to_city'], //string
        'country' => [ //required
            'countryName' => $state['name'], //string
            'countryA2' => $state['alpha2'], //string, ISO-3166 alpha-2 code.
            'countryA3' => $state['alpha3'], //string, ISO 3166-1:2013 alpha-3, required
            'countryNumber' => $state['numeric'] //string, ISO-3166 numeric code
        ],
        'addressLine1' => [ //required
            'street' => $salesHeader['bill_to_address'], //string, required
            //'streetNumber' => '', //string
        ],
        'addressLine2' => $salesHeader['bill_to_address_2'], //string
        //'addressLine3' => '', //string
        'postalCode' => $salesHeader['bill_to_post_code'], //string, required
        //'State' => '', //string, ISO 3166-2:2013
    ];
    $billingAddressJson = json_encode($billingAddressArray);
    $billingAddressBase64 = base64_encode($billingAddressJson);

    //shippingAddress JSON
    $state = (new \League\ISO3166\ISO3166)->alpha2($salesHeader['ship_to_country']);
    $shippingAddressArray = [
        'city' => $salesHeader['ship_to_city'], //string
        'country' => [ //required
            'countryName' => $state['name'], //string
            'countryA2' => $state['alpha2'], //string, ISO-3166 alpha-2 code.
            'countryA3' => $state['alpha3'], //string, ISO 3166-1:2013 alpha-3, required
            'countryNumber' => $state['numeric'] //string, ISO-3166 numeric code
        ],
        'addressLine1' => [ //required
            'street' => $salesHeader['ship_to_address'], //string, required
            //'streetNumber' => '', //string
        ],
        'addressLine2' => $salesHeader['ship_to_address_2'], //string
        //'addressLine3' => '', //string
        'postalCode' => $salesHeader['ship_to_post_code'], //string, required
        //'State' => '', //string, ISO 3166-2:2013
    ];
    $shippingAddressJson = json_encode($shippingAddressArray);
    $shippingAddressBase64 = base64_encode($shippingAddressJson);

    //accountInfo JSON
    $accountInfoArray = []; //complete optional

    if ('TEMP_' === substr($salesHeader['customer_no'], 0, 5)) {
        $accountInfoArray['accountAgeIndicator'] = 'guestCheckout';
        $accountInfoArray['shipAddressUsageDate'] = $salesHeader['order_date']; //string, YYYY-MM-DD
        $accountInfoArray['shipAddressUsageIndicator'] = 'thisTransaction'; //string, ["thisTransaction", "lessThan30Days", "from30To60Days", "moreThan60Days"]
    } else {
        $oldestSalesHeaderQuery = '
            SELECT 
                *
            FROM
                shop_sales_header
            WHERE
                company = :company
            AND 
                shop_code = :shopCode
            AND 
                customer_no = :customerNo
            AND 
                order_error = 0
            AND 
                successful = 1
            ORDER BY
                order_date ASC
        ';

        $stmt = $pdo->prepare($oldestSalesHeaderQuery);
        $stmt->bindValue(':company', $salesHeader['company'], PDO::PARAM_STR);
        $stmt->bindValue(':shopCode', $salesHeader['shop_code'], PDO::PARAM_STR);
        $stmt->bindValue(':customerNo', $salesHeader['customer_no'], PDO::PARAM_STR);
        if (!$stmt->execute()) {
            $errorInfo = $pdo->errorInfo();
            $errorString = implode($errorInfo, PHP_EOL);
            throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
        }

        $stmt->setFetchMode(PDO::FETCH_BOTH);
        $oldestSalesHeaderResultArray = $stmt->fetchAll(0);
        $oldestSalesHeader = $oldestSalesHeaderResultArray[0];

        $currentDateTime = new DateTime();

        $nbrTransactionsYearArray = \DynCom\Compat\Compat::array_filter($oldestSalesHeaderResultArray, function ($var) {
            $currentDateTime = new DateTime();
            return (new DateTime($var['order_date']) >= $currentDateTime->sub(new DateInterval('P1Y')));
        });

        $nbrTransactionsToDayArray = \DynCom\Compat\Compat::array_filter($oldestSalesHeaderResultArray, function ($var) {
            $currentDateTime = new DateTime();
            return (new DateTime($var['order_date']) === $currentDateTime);
        });

        $oldestSalesHeaderDateTime = new DateTime($oldestSalesHeader['order_date']);

        switch (true) { //string, ["thisTransaction", "lessThan30Days", "from30To60Days", "moreThan60Days"]
            case $oldestSalesHeaderDateTime === $currentDateTime:
                //Today
                $accountInfoArray['accountAgeIndicator'] = 'thisTransaction';
                $accountInfoArray['paymentAccountAgeIndicator'] = 'thisTransaction';
                break;
            case $oldestSalesHeaderDateTime > $currentDateTime->sub(new DateInterval('P30D')):
                //lessThan30Days
                $accountInfoArray['accountAgeIndicator'] = 'lessThan30Days';
                $accountInfoArray['paymentAccountAgeIndicator'] = 'lessThan30Days';
                break;
            case ($oldestSalesHeaderDateTime <= $currentDateTime->sub(new DateInterval('P30D'))) && ($oldestSalesHeaderDateTime >= $currentDateTime->sub(new DateInterval('P60D'))):
                //from30To60Days
                $accountInfoArray['accountAgeIndicator'] = 'from30To60Days';
                $accountInfoArray['paymentAccountAgeIndicator'] = 'from30To60Days';
                break;
            case $oldestSalesHeaderDateTime > $currentDateTime->sub(new DateInterval('P60D')):
                //moreThan60Days
                $accountInfoArray['accountAgeIndicator'] = 'moreThan60Days';
                $accountInfoArray['paymentAccountAgeIndicator'] = 'moreThan60Days';
                break;
        }

        //$accountInfoArray['accountChangeDate'] = ''; //string, YYYY-MM-DD
        //$accountInfoArray['accountChangeIndicator'] = ''; //string, ["thisTransaction", "lessThan30Days", "from30To60Days", "moreThan60Days"]
        $accountInfoArray['accountCreationDate'] = $oldestSalesHeader['order_date']; //string, YYYY-MM-DD
        //$accountInfoArray['passwordChangeDate'] = ''; //string, YYYY-MM-DD
        //$accountInfoArray['passwordChangeDateIndicator'] = ''; //string, ["thisTransaction", "lessThan30Days", "from30To60Days", "moreThan60Days"]
        $accountInfoArray['nbrOfPurchases'] = \DynCom\Compat\Compat::count($oldestSalesHeaderResultArray); //integer, 1-9999
        //$accountInfoArray['addCardAttemptsDay'] = ''; //integer, 1-999
        $accountInfoArray['nbrTransactionsDay'] = \DynCom\Compat\Compat::count($nbrTransactionsToDayArray); //integer, 1-999
        $accountInfoArray['nbrTransactionsYear'] = \DynCom\Compat\Compat::count($nbrTransactionsYearArray); //integer, 1-999
        $accountInfoArray['paymentAccountAge'] = $oldestSalesHeader['order_date']; //string, YYYY-MM-DD

        $shippingAddressLastUsedSalesHeaderQuery = '
            SELECT 
                *
            FROM
                shop_sales_header
            WHERE
                company = :company
            AND 
                shop_code = :shopCode
            AND 
                customer_no = :customerNo
            AND 
                order_error = 0
            AND 
                successful = 1
            AND 
                ship_to_name = :shipToName
            AND 
                ship_to_name_2 = :shipToName2
            AND 
                ship_to_address = :shipToAddress
            AND 
                ship_to_address_2 = :shipToAddress2
            AND 
                ship_to_city = :shipToCity
            AND 
                ship_to_post_code = :shipToPostCode
            AND 
                ship_to_country = :shipToCountry
            AND 
                id <> :salesHeaderId
            ORDER BY
                order_date DESC
            LIMIT 1
        ';

        $stmt = $pdo->prepare($shippingAddressLastUsedSalesHeaderQuery);
        $stmt->bindValue(':company', $salesHeader['company'], PDO::PARAM_STR);
        $stmt->bindValue(':shopCode', $salesHeader['shop_code'], PDO::PARAM_STR);
        $stmt->bindValue(':customerNo', $salesHeader['customer_no'], PDO::PARAM_STR);
        $stmt->bindValue(':shipToName', $salesHeader['ship_to_name'], PDO::PARAM_STR);
        $stmt->bindValue(':shipToName2', $salesHeader['ship_to_name_2'], PDO::PARAM_STR);
        $stmt->bindValue(':shipToAddress', $salesHeader['ship_to_address'], PDO::PARAM_STR);
        $stmt->bindValue(':shipToAddress2', $salesHeader['ship_to_address_2'], PDO::PARAM_STR);
        $stmt->bindValue(':shipToCity', $salesHeader['ship_to_city'], PDO::PARAM_STR);
        $stmt->bindValue(':shipToPostCode', $salesHeader['ship_to_post_code'], PDO::PARAM_STR);
        $stmt->bindValue(':shipToCountry', $salesHeader['ship_to_country'], PDO::PARAM_STR);
        $stmt->bindValue(':salesHeaderId', $salesHeaderID, PDO::PARAM_INT);
        if (!$stmt->execute()) {
            $errorInfo = $pdo->errorInfo();
            $errorString = implode($errorInfo, PHP_EOL);
            throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
        }

        $stmt->setFetchMode(PDO::FETCH_BOTH);
        $shippingAddressLastUsedSalesHeaderResultArray = $stmt->fetchAll(0);
        $shippingAddressLastUsedSalesHeaderArray = $shippingAddressLastUsedSalesHeaderResultArray[0];

        $accountInfoArray['shipAddressUsageDate'] = $shippingAddressLastUsedSalesHeaderArray['order_date']; //string, YYYY-MM-DD

        $shippingAddressLastUsedSalesHeaderDateTime = new DateTime($shippingAddressLastUsedSalesHeaderArray['order_date']);

        switch (true) { //string, ["thisTransaction", "lessThan30Days", "from30To60Days", "moreThan60Days"]
            case $shippingAddressLastUsedSalesHeaderDateTime === $currentDateTime:
                //Today
                $accountInfoArray['shipAddressUsageIndicator'] = 'thisTransaction';
                break;
            case $shippingAddressLastUsedSalesHeaderDateTime > $currentDateTime->sub(new DateInterval('P30D')):
                //lessThan30Days
                $accountInfoArray['shipAddressUsageIndicator'] = 'lessThan30Days';
                break;
            case ($shippingAddressLastUsedSalesHeaderDateTime <= $currentDateTime->sub(new DateInterval('P30D'))) && ($shippingAddressLastUsedSalesHeaderDateTime >= $currentDateTime->sub(new DateInterval('P60D'))):
                //from30To60Days
                $accountInfoArray['shipAddressUsageIndicator'] = 'from30To60Days';
                break;
            case $shippingAddressLastUsedSalesHeaderDateTime > $currentDateTime->sub(new DateInterval('P60D')):
                //moreThan60Days
                $accountInfoArray['shipAddressUsageIndicator'] = 'moreThan60Days';
                break;
        }
    }

    //$accountInfoArray['suspiciousAccActivity'] = false;   //boolean

    $accountInfoArray = \DynCom\Compat\Compat::array_filter($accountInfoArray, function ($value) {
        return !is_null($value) && $value !== '';
    });
    if (!empty($accountInfoArray)) {
        $accountInfoJson = json_encode($accountInfoArray);
        $accountInfoBase64 = base64_encode($accountInfoJson);
    }

    //merchantRiskIndicator JSON
    $merchantRiskIndicatorArray = []; //complete optional
    if ((bool)$salesHeader['dc_order']) {
        $currency = "EUR";
        if ($GLOBALS["shop_language"]["default_currency_code"] != "") {
            $currency = $GLOBALS["shop_language"]["default_currency_code"];
        }

        $couponQuery = '
            SELECT 
                shop_digital_coupon.*
            FROM
                shop_digital_coupon
            RIGHT JOIN
                shop_coupon_line on shop_coupon_line.id = shop_digital_coupon.shop_coupon_line_id
            WHERE
                shop_coupon_line.company = :company
            AND 
                shop_coupon_line.coupon_code = :couponCode
            LIMIT 1
        ';

        $stmt = $pdo->prepare($couponQuery);
        $stmt->bindValue(':company', $salesHeader['company'], PDO::PARAM_STR);
        $stmt->bindValue(':couponCode', $salesHeader['coupon_code'], PDO::PARAM_STR);
        if (!$stmt->execute()) {
            $errorInfo = $pdo->errorInfo();
            $errorString = implode($errorInfo, PHP_EOL);
            throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
        }

        $stmt->setFetchMode(PDO::FETCH_BOTH);
        $couponLineResultArray = $stmt->fetchAll(0);
        $couponLineArray = $couponLineResultArray[0];

        if ($couponLineArray['to_email'] !== '') {
            $merchantRiskIndicatorArray['deliveryEmail'] = $couponLineArray['to_email']; //string, 50
        } else {
            $merchantRiskIndicatorArray['deliveryEmail'] = $couponLineArray['from_email']; //string, 50
        }

        $merchantRiskIndicatorArray['deliveryTimeframe'] = 'electronicDelivery'; //string, ["electronicDelivery", "sameDayDelivery", "nextDayDelivery", "twoOrMoreDaysDelivery"]
        $merchantRiskIndicatorArray['giftCardAmount'] = $salesHeader['coupon_amount'] * 100; //integer, 1-999999999999, Cent
        $merchantRiskIndicatorArray['giftCardCount'] = 1; //integer, 1-99
        $merchantRiskIndicatorArray['giftCardCurrency'] = $currency; //string, 3, ISO 4217 three-letter currency code
        $merchantRiskIndicatorArray['shippingAddressIndicator'] = 'digitalGoods'; //string, ["shipToBillingAddress", "shipToVerifiedAddress", "shipToNewAddress", "shipToStore", "digitalGoods", "noShippment", "other"]
    } elseif (isset($GLOBALS['digital_item']) && $GLOBALS['digital_item'] instanceof \DynCom\dc\dcShop\interfaces\OrderableEntityInterface) {
        $merchantRiskIndicatorArray['deliveryEmail'] = $salesHeader['user_email']; //string, 50
        $merchantRiskIndicatorArray['deliveryTimeframe'] = 'electronicDelivery'; //string, ["electronicDelivery", "sameDayDelivery", "nextDayDelivery", "twoOrMoreDaysDelivery"]
        $merchantRiskIndicatorArray['shippingAddressIndicator'] = 'digitalGoods'; //string, ["shipToBillingAddress", "shipToVerifiedAddress", "shipToNewAddress", "shipToStore", "digitalGoods", "noShippment", "other"]
    } else {
        $salesLinesItemNosForSalesHeaderIdQuery = '
            SELECT
                item_no
            FROM
                shop_sales_line
            WHERE
                shop_sales_header_id = :shopSalesHeaderId
        ';

        $stmt = $pdo->prepare($salesLinesItemNosForSalesHeaderIdQuery);
        $stmt->bindValue(':shopSalesHeaderId', $salesHeaderID, PDO::PARAM_INT);
        if (!$stmt->execute()) {
            $errorInfo = $pdo->errorInfo();
            $errorString = implode($errorInfo, PHP_EOL);
            throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
        }

        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        $salesLinesItemNosForSalesHeaderIdResultArray = $stmt->fetchAll(0);

        $itemNoInValue  = implode(', ', array_map(function ($entry) {
            return '\'' . $entry['item_no'] . '\'';
        }, $salesLinesItemNosForSalesHeaderIdResultArray));

        $salesHeaderInValue  = implode(', ', array_map(function ($entry) {
            return $entry['id'];
        }, $oldestSalesHeaderResultArray));

        $reOrderItemsQuery = '
            SELECT 
                COUNT(*)
            FROM
                shop_sales_line
            WHERE
                shop_sales_line.item_no IN (' . $itemNoInValue . ')
                    AND shop_sales_line.shop_sales_header_id IN (' . $salesHeaderInValue . ')
        ';

        $stmt = $pdo->prepare($reOrderItemsQuery);
        if (!$stmt->execute()) {
            $errorInfo = $pdo->errorInfo();
            $errorString = implode($errorInfo, PHP_EOL);
            throw new ErrorException('Could not execute prepared statement. Error: ' . $errorString);
        }

        $stmt->setFetchMode(PDO::FETCH_BOTH);
        $reOrderItemsResultArray = $stmt->fetchAll(0);
        $reOrderItemsArray = $reOrderItemsResultArray[0];

        $merchantRiskIndicatorArray['reorderItemsIndicator'] = false; //boolean
        if ((int)$reOrderItemsArray[0] > 0) {
            $merchantRiskIndicatorArray['reorderItemsIndicator'] = true; //boolean
        }

        if (
            $salesHeader['ship_to_name'] === $salesHeader['bill_to_name']
            &&
            $salesHeader['ship_to_name_2'] === $salesHeader['bill_to_name_2']
            &&
            $salesHeader['ship_to_address'] === $salesHeader['bill_to_address']
            &&
            $salesHeader['ship_to_address_2'] === $salesHeader['bill_to_address_2']
            &&
            $salesHeader['ship_to_city'] === $salesHeader['bill_to_city']
            &&
            $salesHeader['ship_to_post_code'] === $salesHeader['bill_to_post_code']
            &&
            $salesHeader['ship_to_country'] === $salesHeader['bill_to_country']
        ) {
            $merchantRiskIndicatorArray['shippingAddressIndicator'] = 'shipToBillingAddress'; //string, ["shipToBillingAddress", "shipToVerifiedAddress", "shipToNewAddress", "shipToStore", "digitalGoods", "noShippment", "other"]
        } else {
            $merchantRiskIndicatorArray['shippingAddressIndicator'] = 'other'; //string, ["shipToBillingAddress", "shipToVerifiedAddress", "shipToNewAddress", "shipToStore", "digitalGoods", "noShippment", "other"]
        }
    }

    //$merchantRiskIndicatorArray['preOrderDate'] = ''; //string, YYYY-MM-DD
    //$merchantRiskIndicatorArray['preOrderPurchaseIndicator'] = false; //boolean

    $merchantRiskIndicatorArray = \DynCom\Compat\Compat::array_filter($merchantRiskIndicatorArray, function ($value) {
        return !is_null($value) && $value !== '';
    });
    if (!empty($accountInfoArray)) {
        $merchantRiskIndicatorJson = json_encode($merchantRiskIndicatorArray);
        $merchantRiskIndicatorBase64 = base64_encode($merchantRiskIndicatorJson);
    }

    $additionalData = '&billToCustomer=' . $billToCustomerBase64;
    $additionalData .= '&shipToCustomer=' . $shipToCustomerBase64;
    $additionalData .= '&billingAddress=' . $billingAddressBase64;
    $additionalData .= '&shippingAddress=' . $shippingAddressBase64;

    if (!empty($accountInfoArray)) {
        $additionalData .= '&accountInfo=' . $accountInfoBase64;
    }

    if (!empty($merchantRiskIndicatorArray)) {
        $additionalData .= '&merchantRiskIndicator=' . $merchantRiskIndicatorBase64;
    }
    return $additionalData;
}
function get_page_switch_pagination($rows,$page,$is_Search,$categoryPath,$order_by) {
    if ($rows > $GLOBALS['shop_setup']['num_items_per_page']) {
        $path = ($is_Search) ? "/" . customizeUrl(). "/" : '';
        $rest = $rows % $GLOBALS['shop_setup']['num_items_per_page'];
        if ($rest != 0) {
            $number_of_subrows = intval($rows / $GLOBALS['shop_setup']['num_items_per_page']) + 1;
        } else {
            $number_of_subrows = intval($rows / $GLOBALS['shop_setup']['num_items_per_page']);
        }
        $off = ($number_of_subrows > 5) ? 2 : 5;
        if (($number_of_subrows > 1) || (($number_of_subrows <= 1) && ($rest != 0))) {
            //Generieren der "weiter" bzw. "zurück" Links
            if ($page != 1) {
                $output           = $page - 1;
                $step_back_link   = $path . $categoryPath . "page=" . $output . "&sort_by=" . $order_by;
                $step_back_output = '<a class="pageSwitch__item pageSwitch__item--prev" href="' . $step_back_link . '" rel="prev" title="'.$GLOBALS["tc"]["back"].'"><i class="icon icon-angle-left"></i></a>';
            } else {
                $step_back_output = "<div class='pageSwitch__item pageSwitch__item--prev'></div>";
            }
            if ($page>$off) {
                $step_first_link   = $path . $categoryPath . "page=" . 1 . "&sort_by=" . $order_by;
                $separator = ($page>$off+1) ? "<span class='pageSwitch__item pageSwitch__item--separator'> ... </span>" : "";
                $step_first_output = "<a class='pageSwitch__item' href=\"" . $step_first_link . "\">" . 1 . "</a>" . $separator;
            } else {
                $step_first_output = "";
            }
            if ($page != $number_of_subrows) {
                $output              = $page + 1;
                $step_forward_link   = $path . $categoryPath . "page=" . $output . "&sort_by=" . $order_by;
                $step_forward_output = '<a class="pageSwitch__item pageSwitch__item--next" href="' . $step_forward_link . '" rel="next" title="'.$GLOBALS["tc"]["next_step"].'"><i class="icon icon-angle-right"></i></a>';
            } else {
                $step_forward_output = "<div class='pageSwitch__item pageSwitch__item--next'></div>";
            }
            if ($page <= $number_of_subrows - $off) {
                $step_last_link      = $path . $categoryPath . "page=" . $number_of_subrows . "&sort_by=" . $order_by;
                $separator = ($page <= $number_of_subrows - $off - 1) ? "<span class='pageSwitch__item pageSwitch__item--separator'>...</span>" : "";
                $step_last_output = $separator . "<a class='pageSwitch__item' href=\"" . $step_last_link . "\">" . $number_of_subrows . "</a>";
            } else {
                $step_last_output = "";
            }
            //Ende

            //Erstellen der Seitenauswahl
            echo "<div class='pageSwitchWrapper'>";
            echo "<div class=\"pageSwitch\">";
            echo "<div class='pageSwitch__headline'><span>".$GLOBALS['tc']['select_page']."</span></div>";
            echo "<div class=\"pageSwitch__inner\">";
            echo $step_back_output;
            echo "<div class='pageSwitch__pages'>";
            echo $step_first_output;
            for ($i = 1; $i <= $number_of_subrows; $i++) {
                if ($i >= 2) {
                    $pipe = "";
                } else {
                    $pipe = "";
                }
                if ($page == $i) {
                    $output           = "<span class='pageSwitch__item active'>" . $i . "</span>";
                    $item_link_output = $output;
                } elseif(abs($page-$i)<$off) {
                    $output           = $i;
                    $itemlink         = $path . $categoryPath . "page=" . $output . "&sort_by=" . $order_by;
                    $item_link_output = "<a class='pageSwitch__item' href=\"" . $itemlink . "\">" . $output . "</a>";
                } else {
                    $item_link_output = '';
                    $pipe = '';
                }
                echo $pipe . $item_link_output;
            }
            echo $step_last_output;
            echo "</div>";
            echo $step_forward_output;
            echo "</div>";
            echo "</div>";
            echo "</div>";
            //Ende
        }
    }
}

function is_allowed_to_view_item() {
    if (show_my_items_only() && !empty($_GET['card'])) {
        $query = "SELECT shop_view_active_item.id, shop_view_active_item.item_no 
                    FROM shop_view_active_item 
                        LEFT JOIN shop_nav_sales_line ON shop_view_active_item.item_no = shop_nav_sales_line.no
                    WHERE shop_nav_sales_line.document_no = '".mysqli_real_escape_string($GLOBALS['mysql_con'], $GLOBALS['shop_user']['my_items_contract'])."'
                    AND shop_view_active_item.id = " . mysqli_real_escape_string($GLOBALS['mysql_con'], $_GET['card']);
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (\DynCom\Compat\Compat::mysqli_num_rows($result) == 0) {
            return false;
        }
    }
    return true;
}

function show_my_items_only() {
    if ($GLOBALS['shop_user']['my_items_contract'] <> '' && $GLOBALS['shop_user']['right_my_items_only']) {
        return true;
    }
    // Check if my_items_contract is used by shop_user. otherwise get my_items_contract from shop_main_user
    if ($GLOBALS['shop_user']['my_items_contract'] == '' && $GLOBALS['shop_user']['right_my_items_only']) {
        $query = "SELECT *
                    FROM shop_user
                   WHERE customer_no = '". $GLOBALS['shop_user']['customer_no'] ."'
                     AND main_user = 1
                   LIMIT 1";
        $result = mysqli_query($GLOBALS['mysql_con'], $query);
        if (@\DynCom\Compat\Compat::mysqli_num_rows($result) == 1) {
            $row = mysqli_fetch_array($result);
            if ($row['my_items_contract'] <> '') {
                $GLOBALS['shop_user']['my_items_contract'] = $row['my_items_contract'];
                return true;
            }
        }
    }
    return false;
}

function my_items_select() {
    $query = '';
    if (show_my_items_only()) {
        $query = ' LEFT JOIN shop_nav_sales_line ON shop_view_active_item.item_no = shop_nav_sales_line.no';
    }
    return $query;
}
function my_items_where() {
    $query = '';
    if (show_my_items_only()) {
        $query = ' AND shop_nav_sales_line.document_no = "'.mysqli_real_escape_string($GLOBALS['mysql_con'], $GLOBALS['shop_user']['my_items_contract']).'"';
    }
    return $query;
}

function calculate_totals_b2b($item_total = false)
{
    /** @var GenericUserBasket $userBasket */
    $userBasket = $GLOBALS['IOC']->resolve('$CurrUserBasket');

    if ($item_total === false) {
        $item_total = $userBasket->getBasketItemTotal() + $userBasket->getSumVATAmounts();
    }

    /** @var \DynCom\dc\dcShop\classes\CurrShopConfiguration $shopConfig */
    $shopConfig = $GLOBALS['IOC']->resolve(\DynCom\dc\dcShop\classes\CurrShopConfiguration::class);

    $subtotal_invoice_discount_allowed = $item_total;
    $subtotal_discount_subtracted = $subtotal_invoice_discount_allowed;
    $order_total = $subtotal_discount_subtracted;

    /*
     * Calculate Totals – Step 1: Remove Invoice Discount
     */

    $invoice_discount = (bool)$GLOBALS['shop']['show_invoice_discount'] ? get_invoice_discount($subtotal_invoice_discount_allowed) : 0;
    $invoice_discount_amount = $subtotal_invoice_discount_allowed / 100 * $invoice_discount;

    /**
     * @var $appliedInvoiceDiscount \DynCom\dc\dcShop\classes\AppliedDiscount
     */
    foreach ($userBasket->getAppliedInvoiceDiscounts() as $invoiceDiscount) {
        switch ($invoiceDiscount->getSourceType()) {
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_INVOICE_DISCOUNT:
                $invoice_discount_amount += $invoiceDiscount->getDiscountedAmount();
                break;
        }
    }

    if ($invoice_discount_amount) {
        $invoice_discount = $invoice_discount_amount * 100 / $subtotal_invoice_discount_allowed;
    }

    $invoice_discount_amount = round($invoice_discount_amount, 2);

    $subtotal_discount_subtracted -= $invoice_discount_amount;
    $order_total -= $invoice_discount_amount;

    /*
     * Calculate Totals – Step 2: Remove General Discounts
     */

    $online_discount = $GLOBALS["shop"]["online_discount"];
    $online_discount_amount = $subtotal_invoice_discount_allowed / 100 * $online_discount;
    $coupon_discount_amount = 0.00;
    $rule_disc_percent = 0;
    $rule_disc_amount = 0;

    /**
     * @var $appliedInvoiceDiscount \DynCom\dc\dcShop\classes\AppliedDiscount
     */
    foreach ($userBasket->getAppliedInvoiceDiscounts() as $invoiceDiscount) {
        switch ($invoiceDiscount->getSourceType()) {
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_COUPON:
                $coupon_discount_amount += $invoiceDiscount->getDiscountedAmount();
                break;
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_ONLINE_DISCOUNT:
                $online_discount_amount += $invoiceDiscount->getDiscountedAmount();
                break;
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_RULE:
                if ($invoiceDiscount->getDiscountValueType() === \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_VALUE_TYPE_PERCENT) {
                    $rule_disc_amount = $subtotal_invoice_discount_allowed * ($invoiceDiscount->getDiscountValue() / 100);
                } else {
                    $rule_disc_amount += $invoiceDiscount->getDiscountedAmount();
                }

                break;
        }
    }
    if (!isset($_SESSION['coupon']['coupon_code']) || empty($_SESSION['coupon']['coupon_code'])) {
        $coupon_discount_amount = 0;

        if (isset($_SESSION['coupon'])) {
            unset($_SESSION['coupon']);
        }
    }

    if ($rule_disc_amount) {
        $rule_disc_percent = $rule_disc_amount * 100 / $subtotal_invoice_discount_allowed;
    }


    $subtotal_discount_subtracted -= ($online_discount_amount + $coupon_discount_amount + $rule_disc_amount);
    $order_total -= ($online_discount_amount + $coupon_discount_amount + $rule_disc_amount);

    $_SESSION['coupon']['coupon_discount_amount'] = $coupon_discount_amount;

    /*
     * Calculate Totals – Step 2: Add Shipping + Payment Costs + Small Qty Charge
     */


    if ($item_total < $_SESSION['small_quantity']) {
        $_SESSION['small_quantity'] = $_SESSION['small_quantity'];
    } else {
        $_SESSION['small_quantity'] = 0;
    }

    $order_total += $_SESSION['shipping_cost'] + $_SESSION['payment_cost'] + $_SESSION['small_quantity'];

    /*
     * Prepare Results
     */

    $_SESSION['total_basket'] = $order_total;

    $totals = new stdClass();
    $totals->calculated = new stdClass();
    $totals->calculated->showNet = true;
    $totals->calculated->itemTotal = $userBasket->getBasketItemTotal();
    $totals->calculated->subTotalInvoiceDiscountAllowed = $subtotal_invoice_discount_allowed;
    $totals->calculated->subTotalDiscountSubtracted = $subtotal_discount_subtracted;
    $totals->calculated->orderTotal = $order_total;

    $totals->shippingCost = $_SESSION['shipping_cost'];
    $totals->paymentCost = $_SESSION['payment_cost'];
    $totals->onlineDiscount = $online_discount;
    $totals->onlineDiscountAmount = $online_discount_amount;
    $totals->couponDiscountAmount = $coupon_discount_amount;
    $totals->ruleDiscount = $rule_disc_percent;
    $totals->ruleDiscountAmount = $rule_disc_amount;
    $totals->invoiceDiscount = $invoice_discount;
    $totals->invoiceDiscountAmount = $invoice_discount_amount;
    $totals->smallQuantityCharge = $_SESSION['small_quantity'];

    return $totals;
}
function calculate_totals($item_total = false)
{
    /** @var GenericUserBasket $userBasket */
    $userBasket = $GLOBALS['IOC']->resolve('$CurrUserBasket');

    if ($item_total === false) {
        $item_total = $userBasket->getBasketItemTotal();
    }

    /** @var \DynCom\dc\dcShop\classes\CurrShopConfiguration $shopConfig */
    $shopConfig = $GLOBALS['IOC']->resolve(\DynCom\dc\dcShop\classes\CurrShopConfiguration::class);

    $subtotal_invoice_discount_allowed = $item_total;
    $subtotal_discount_subtracted = $subtotal_invoice_discount_allowed;
    $order_total = $subtotal_discount_subtracted;

    /*
     * Calculate Totals – Step 1: Remove Invoice Discount
     */

    $invoice_discount = (bool)$GLOBALS['shop']['show_invoice_discount'] ? get_invoice_discount($subtotal_invoice_discount_allowed) : 0;
    $invoice_discount_amount = $subtotal_invoice_discount_allowed / 100 * $invoice_discount;

    /**
     * @var $appliedInvoiceDiscount \DynCom\dc\dcShop\classes\AppliedDiscount
     */
    foreach ($userBasket->getAppliedInvoiceDiscounts() as $invoiceDiscount) {
        switch ($invoiceDiscount->getSourceType()) {
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_INVOICE_DISCOUNT:
                $invoice_discount_amount += $invoiceDiscount->getDiscountedAmount();
                break;
        }
    }

    if ($invoice_discount_amount) {
        $invoice_discount = $invoice_discount_amount * 100 / $subtotal_invoice_discount_allowed;
    }

    $invoice_discount_amount = round($invoice_discount_amount, 2);

    $subtotal_discount_subtracted -= $invoice_discount_amount;
    $order_total -= $invoice_discount_amount;

    /*
     * Calculate Totals – Step 2: Remove General Discounts
     */

    $online_discount = $GLOBALS["shop"]["online_discount"];
    $online_discount_amount = $subtotal_invoice_discount_allowed / 100 * $online_discount;
    $coupon_discount_amount = 0.00;
    $rule_disc_percent = 0;
    $rule_disc_amount = 0;

    /**
     * @var $appliedInvoiceDiscount \DynCom\dc\dcShop\classes\AppliedDiscount
     */
    foreach ($userBasket->getAppliedInvoiceDiscounts() as $invoiceDiscount) {
        switch ($invoiceDiscount->getSourceType()) {
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_COUPON:
                $coupon_discount_amount += $invoiceDiscount->getDiscountedAmount();
                break;
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_ONLINE_DISCOUNT:
                $online_discount_amount += $invoiceDiscount->getDiscountedAmount();
                break;
            case \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_SOURCE_TYPE_RULE:
                if ($invoiceDiscount->getDiscountValueType() === \DynCom\dc\dcShop\abstracts\DiscountBase::DISCOUNT_VALUE_TYPE_PERCENT) {
                    $rule_disc_amount = $subtotal_invoice_discount_allowed * ($invoiceDiscount->getDiscountValue() / 100);
                } else {
                    $rule_disc_amount += $invoiceDiscount->getDiscountedAmount();
                }

                break;
        }
    }
    if (!isset($_SESSION['coupon']['coupon_code']) || empty($_SESSION['coupon']['coupon_code'])) {
        $coupon_discount_amount = 0;

        if (isset($_SESSION['coupon'])) {
            unset($_SESSION['coupon']);
        }
    }

    if ($rule_disc_amount) {
        $rule_disc_percent = $rule_disc_amount * 100 / $subtotal_invoice_discount_allowed;
    }


    $subtotal_discount_subtracted -= ($online_discount_amount + $coupon_discount_amount + $rule_disc_amount);
    $order_total -= ($online_discount_amount + $coupon_discount_amount + $rule_disc_amount);

    $_SESSION['coupon']['coupon_discount_amount'] = $coupon_discount_amount;

    /*
     * Calculate Totals – Step 2: Add Shipping + Payment Costs + Small Qty Charge
     */


    if ($item_total < $_SESSION['small_quantity']) {
        $_SESSION['small_quantity'] = $_SESSION['small_quantity'];
    } else {
        $_SESSION['small_quantity'] = 0;
    }

    $order_total += $_SESSION['shipping_cost'] + $_SESSION['payment_cost'] + $_SESSION['small_quantity'];

    /*
     * Prepare Results
     */

    $_SESSION['total_basket'] = $order_total;

    $totals = new stdClass();
    $totals->calculated = new stdClass();
    $totals->calculated->itemTotal = $item_total;
    $totals->calculated->subTotalInvoiceDiscountAllowed = $subtotal_invoice_discount_allowed;
    $totals->calculated->subTotalDiscountSubtracted = $subtotal_discount_subtracted;
    $totals->calculated->orderTotal = $order_total;

    $totals->shippingCost = $_SESSION['shipping_cost'];
    $totals->paymentCost = $_SESSION['payment_cost'];
    $totals->onlineDiscount = $online_discount;
    $totals->onlineDiscountAmount = $online_discount_amount;
    $totals->couponDiscountAmount = $coupon_discount_amount;
    $totals->ruleDiscount = $rule_disc_percent;
    $totals->ruleDiscountAmount = $rule_disc_amount;
    $totals->invoiceDiscount = $invoice_discount;
    $totals->invoiceDiscountAmount = $invoice_discount_amount;
    $totals->smallQuantityCharge = $_SESSION['small_quantity'];

    return $totals;
}
